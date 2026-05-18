<?php
/**
 * ============================================================
 * CRON TASK: Process Student Suspensions
 * ============================================================
 * 
 * Automatically suspends student accounts based on suspension queue.
 * Sends email notifications to affected students.
 * 
 * SCHEDULE: Every hour
 * CRON: 0 * * * *
 * 
 * OPTIONS:
 *   --dry-run     : Simulate without actually suspending
 *   --limit=N     : Process N suspensions (default: 100)
 * 
 * SUSPENSION REASONS:
 *   - payment_expired: Student's payment/subscription expired
 *   - violation: Student violated school rules
 *   - admin_action: Manual suspension by admin
 *   - attendance: Poor attendance record
 * 
 * ============================================================
 */

function executeTask($options, $logger) {
    $dryRun = isset($options['dry-run']);
    $limit = isset($options['limit']) ? (int)$options['limit'] : 100;
    
    $logger->info("Starting student suspension processing", [
        'dry_run' => $dryRun,
        'limit' => $limit
    ]);
    
    $platformDb = Database::getPlatformConnection();
    
    // Get pending suspensions that are due
    $stmt = $platformDb->prepare("
        SELECT * FROM student_suspension_queue 
        WHERE status = 'pending' 
        AND scheduled_for <= NOW()
        ORDER BY scheduled_for ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $suspensions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $logger->info("Found {count} suspensions to process", ['count' => count($suspensions)]);
    
    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    
    foreach ($suspensions as $suspension) {
        $processed++;
        
        try {
            $schoolId = $suspension['school_id'];
            $studentId = $suspension['student_id'];
            $reason = $suspension['reason'];
            
            $logger->info("Processing suspension", [
                'suspension_id' => $suspension['id'],
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'reason' => $reason
            ]);
            
            // Get school database name
            $stmt = $platformDb->prepare("SELECT subdomain, name, email FROM schools WHERE id = ?");
            $stmt->execute([$schoolId]);
            $school = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$school) {
                throw new Exception("School not found: ID $schoolId");
            }
            
            $schoolDbName = 'school_' . $school['subdomain'];
            
            // Connect to school database
            $schoolDb = Database::getSchoolConnection($schoolDbName);
            
            // Get student information
            $stmt = $schoolDb->prepare("
                SELECT id, first_name, last_name, email, status 
                FROM students 
                WHERE id = ?
            ");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                throw new Exception("Student not found: ID $studentId in school $schoolDbName");
            }
            
            // Check if already suspended
            if ($student['status'] === 'suspended') {
                $logger->warning("Student already suspended (idempotent)", [
                    'student_id' => $studentId,
                    'school_id' => $schoolId
                ]);
                
                // Mark as processed
                $stmt = $platformDb->prepare("
                    UPDATE student_suspension_queue 
                    SET status = 'processed',
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$suspension['id']]);
                
                $succeeded++;
                continue;
            }
            
            if (!$dryRun) {
                // Suspend the student
                $stmt = $schoolDb->prepare("
                    UPDATE students 
                    SET status = 'suspended',
                        suspension_reason = ?,
                        suspended_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$reason, $studentId]);
                
                $logger->info("Student suspended successfully", [
                    'student_id' => $studentId,
                    'school_id' => $schoolId
                ]);
                
                // Send email notification
                if ($student['email']) {
                    $emailSent = sendSuspensionEmail(
                        $student,
                        $school,
                        $suspension,
                        $platformDb,
                        $logger
                    );
                    
                    // Update email_sent flag
                    $stmt = $platformDb->prepare("
                        UPDATE student_suspension_queue 
                        SET email_sent = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$emailSent ? 1 : 0, $suspension['id']]);
                }
                
                // Mark suspension as processed
                $stmt = $platformDb->prepare("
                    UPDATE student_suspension_queue 
                    SET status = 'processed',
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$suspension['id']]);
                
            } else {
                $logger->info("DRY RUN: Would suspend student", [
                    'student_id' => $studentId,
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'student_email' => $student['email'],
                    'reason' => $reason
                ]);
            }
            
            $succeeded++;
            
        } catch (Exception $e) {
            $failed++;
            $logger->error("Failed to process suspension", [
                'suspension_id' => $suspension['id'],
                'error' => $e->getMessage()
            ]);
            
            // Update metadata with error
            $metadata = json_decode($suspension['metadata'] ?? '{}', true);
            $metadata['last_error'] = $e->getMessage();
            $metadata['last_error_at'] = date('Y-m-d H:i:s');
            
            $stmt = $platformDb->prepare("
                UPDATE student_suspension_queue 
                SET metadata = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($metadata), $suspension['id']]);
        }
    }
    
    $logger->success("Student suspension processing completed", [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed
    ]);
    
    return [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed
    ];
}

/**
 * Send suspension notification email
 */
function sendSuspensionEmail($student, $school, $suspension, $db, $logger) {
    try {
        $studentName = $student['first_name'] . ' ' . $student['last_name'];
        $schoolName = $school['name'];
        $reason = $suspension['reason'];
        
        // Format reason for display
        $reasonText = str_replace('_', ' ', $reason);
        $reasonText = ucwords($reasonText);
        
        // Build email content
        $subject = "Account Suspension Notice - $schoolName";
        
        $htmlContent = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
                .content { background: #f8f9fa; padding: 30px; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                .button { display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Account Suspension Notice</h1>
                </div>
                <div class='content'>
                    <p>Dear $studentName,</p>
                    
                    <p>This is to inform you that your student account at <strong>$schoolName</strong> has been suspended.</p>
                    
                    <p><strong>Reason:</strong> $reasonText</p>
                    
                    " . ($suspension['expires_at'] ? "
                    <p><strong>Suspension Period:</strong> Until " . date('F j, Y', strtotime($suspension['expires_at'])) . "</p>
                    " : "") . "
                    
                    <p>If you believe this is an error or would like to appeal this decision, please contact the school administration immediately.</p>
                    
                    <p style='margin-top: 30px;'>
                        <a href='mailto:{$school['email']}' class='button'>Contact School</a>
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from $schoolName</p>
                    <p>Please do not reply to this email</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $textContent = "
Dear $studentName,

This is to inform you that your student account at $schoolName has been suspended.

Reason: $reasonText

" . ($suspension['expires_at'] ? "Suspension Period: Until " . date('F j, Y', strtotime($suspension['expires_at'])) . "\n\n" : "") . "

If you believe this is an error or would like to appeal this decision, please contact the school administration at {$school['email']}.

---
This is an automated message from $schoolName
Please do not reply to this email
        ";
        
        // Add to email queue
        $stmt = $db->prepare("
            INSERT INTO email_queue 
            (to_email, to_name, subject, html_content, text_content, priority, type, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, 'suspension_notice', 'pending', NOW(), NOW())
        ");
        
        $stmt->execute([
            $student['email'],
            $studentName,
            $subject,
            $htmlContent,
            $textContent
        ]);
        
        $logger->info("Suspension email queued", [
            'student_email' => $student['email'],
            'queue_id' => $db->lastInsertId()
        ]);
        
        return true;
        
    } catch (Exception $e) {
        $logger->error("Failed to queue suspension email", [
            'student_email' => $student['email'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
        return false;
    }
}
