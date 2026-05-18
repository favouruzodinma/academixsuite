<?php
/**
 * Attendance Manager Class
 * Handles all attendance-related operations and notifications
 * Location: /includes/AttendanceManager.php
 */

// Include PHPMailer if not already autoloaded
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class AttendanceManager {
    private $db;
    private $schoolId;
    private $userId;
    private $userType;
    private $schoolData;
    private $mailConfig;

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $schoolId School ID
     * @param int $userId Current user ID
     * @param string $userType Current user type
     * @param array $schoolData School information
     */
    public function __construct($db, $schoolId, $userId, $userType, $schoolData = []) {
        $this->db = $db;
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->userType = $userType;
        $this->schoolData = $schoolData;
        $this->loadMailConfig();
        error_log("AttendanceManager initialized for school ID: " . $schoolId);
    }

    /**
     * Load mail configuration from settings
     */
    private function loadMailConfig() {
        try {
            $stmt = $this->db->prepare("
                SELECT `key`, `value` FROM settings 
                WHERE school_id = ? AND `key` IN ('mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name')
            ");
            $stmt->execute([$this->schoolId]);
            $config = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['key']] = $row['value'];
            }
            $this->mailConfig = $config;
            error_log("Mail configuration loaded for school ID: " . $this->schoolId);
        } catch (Exception $e) {
            error_log("Failed to load mail config: " . $e->getMessage());
            $this->mailConfig = [];
        }
    }

    /**
     * Get students with their attendance for a specific date
     * @param int $classId Class ID
     * @param int|null $sectionId Section ID (optional)
     * @param int|null $academicYearId Academic Year ID (optional)
     * @param string $date Date (Y-m-d)
     * @return array Students with attendance data
     */
    public function getStudentsWithAttendance($classId, $sectionId = null, $academicYearId = null, $date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        try {
            $sql = "
                SELECT DISTINCT
                    s.id as student_id,
                    s.user_id,
                    s.admission_number,
                    s.roll_number,
                    s.first_name,
                    s.middle_name,
                    s.last_name,
                    CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name,
                    u.profile_photo,
                    u.email as student_email,
                    u.phone as student_phone,
                    c.id as class_id,
                    c.name as class_name,
                    sec.id as section_id,
                    sec.name as section_name,
                    a.id as attendance_id,
                    a.status as attendance_status,
                    a.remark as attendance_note,
                    a.session as attendance_session,
                    GROUP_CONCAT(DISTINCT CONCAT(g.user_id, ':', g.relationship, ':', u2.name, ':', u2.email)) as parent_info
                FROM students s
                INNER JOIN users u ON s.user_id = u.id
                INNER JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                LEFT JOIN attendance a ON a.student_id = s.id 
                    AND a.date = ? 
                    AND a.class_id = s.class_id
                LEFT JOIN guardians g ON s.id = g.student_id
                LEFT JOIN users u2 ON g.user_id = u2.id
                WHERE s.school_id = ? 
                    AND s.class_id = ?
                    AND s.status = 'active'
            ";
            
            $params = [$date, $this->schoolId, $classId];
            
            if ($sectionId) {
                $sql .= " AND s.section_id = ?";
                $params[] = $sectionId;
            }
            
            if ($academicYearId) {
                $sql .= " AND c.academic_year_id = ?";
                $params[] = $academicYearId;
            }
            
            $sql .= " GROUP BY s.id, s.user_id, s.admission_number, s.roll_number, s.first_name, s.middle_name, s.last_name, 
                              u.profile_photo, u.email, u.phone, c.id, c.name, sec.id, sec.name, 
                              a.id, a.status, a.remark, a.session
                      ORDER BY s.roll_number, s.first_name";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process parent information
            foreach ($results as &$student) {
                $student['parents'] = [];
                if (!empty($student['parent_info'])) {
                    $parentPairs = explode(',', $student['parent_info']);
                    foreach ($parentPairs as $pair) {
                        $parts = explode(':', $pair);
                        if (count($parts) >= 4) {
                            $student['parents'][] = [
                                'id' => $parts[0],
                                'relationship' => $parts[1],
                                'name' => $parts[2],
                                'email' => $parts[3]
                            ];
                        }
                    }
                }
                unset($student['parent_info']);
            }
            
            error_log("Fetched " . count($results) . " students for class ID: " . $classId . " on date: " . $date);
            return $results;
            
        } catch (Exception $e) {
            error_log("Error in getStudentsWithAttendance: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Save attendance records with detailed logging
     * @param array $attendanceData Attendance data [user_id => status]
     * @param array $remarks Remarks [user_id => remark]
     * @param string $date Date
     * @param int $classId Class ID
     * @param int|null $sectionId Section ID
     * @param int|null $academicYearId Academic Year ID
     * @return array Result with success/message
     */
    public function saveAttendance($attendanceData, $remarks = [], $date = null, $classId = null, $sectionId = null, $academicYearId = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        if (empty($attendanceData)) {
            error_log("Attendance save attempted with no data for date: " . $date);
            return ['success' => false, 'message' => 'No attendance data provided'];
        }

        error_log("=== SAVING ATTENDANCE ===");
        error_log("Date: " . $date . ", Class ID: " . $classId . ", Section ID: " . ($sectionId ?: 'All'));
        error_log("Total students submitted: " . count($attendanceData));

        try {
            $this->db->beginTransaction();
            
            $inserted = 0;
            $updated = 0;
            $notifications = [];
            $changedStudents = [];
            
            foreach ($attendanceData as $userId => $status) {
                // Get student details
                $studentStmt = $this->db->prepare("
                    SELECT s.id, s.user_id, s.first_name, s.last_name, 
                           c.name as class_name, u.email as student_email
                    FROM students s
                    INNER JOIN classes c ON s.class_id = c.id
                    INNER JOIN users u ON s.user_id = u.id
                    WHERE s.user_id = ? AND s.school_id = ?
                ");
                $studentStmt->execute([$userId, $this->schoolId]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    error_log("WARNING: Student not found for user_id: " . $userId);
                    continue;
                }
                
                $studentId = $student['id'];
                $remark = $remarks[$userId] ?? '';
                
                // Check if attendance already exists
                $checkStmt = $this->db->prepare("
                    SELECT id, status FROM attendance 
                    WHERE student_id = ? AND date = ? AND class_id = ?
                ");
                $checkStmt->execute([$studentId, $date, $classId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                $oldStatus = $existing['status'] ?? null;
                $studentName = $student['first_name'] . ' ' . $student['last_name'];
                
                if ($existing) {
                    // Update existing
                    $updateStmt = $this->db->prepare("
                        UPDATE attendance 
                        SET status = ?, remark = ?, marked_by = ?, session = 'full_day'
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$status, $remark, $this->userId, $existing['id']]);
                    $updated++;
                    error_log("UPDATED attendance for student: " . $studentName . " (ID: " . $studentId . ") from " . ($oldStatus ?: 'none') . " to " . $status);
                } else {
                    // Insert new
                    $insertStmt = $this->db->prepare("
                        INSERT INTO attendance 
                        (school_id, student_id, class_id, date, status, remark, marked_by, session) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'full_day')
                    ");
                    $insertStmt->execute([
                        $this->schoolId,
                        $studentId,
                        $classId,
                        $date,
                        $status,
                        $remark,
                        $this->userId
                    ]);
                    $inserted++;
                    error_log("INSERTED attendance for student: " . $studentName . " (ID: " . $studentId . ") as " . $status);
                }
                
                // Track for notifications if status changed or new
                if (!$existing || $existing['status'] != $status) {
                    $notifications[] = [
                        'student_id' => $studentId,
                        'user_id' => $userId,
                        'student_name' => $studentName,
                        'class_name' => $student['class_name'],
                        'student_email' => $student['student_email'],
                        'status' => $status,
                        'date' => $date,
                        'old_status' => $oldStatus
                    ];
                    $changedStudents[] = $studentName . " (" . $status . ")";
                }
            }
            
            $this->db->commit();
            
            $logMessage = "Attendance saved: $inserted new, $updated updated. Total processed: " . count($attendanceData);
            error_log($logMessage);
            if (!empty($changedStudents)) {
                error_log("Students with status change: " . implode(", ", $changedStudents));
            }
            
            // Send notifications (in-app and email) to parents and student
            if (!empty($notifications)) {
                error_log("Sending notifications for " . count($notifications) . " changed attendances");
                $notifResult = $this->sendAttendanceNotifications($notifications);
                error_log("Notification result: " . ($notifResult['success'] ? 'success' : 'failed') . " - sent " . ($notifResult['sent'] ?? 0) . " in-app, " . ($notifResult['email_sent'] ?? 0) . " emails");
            }
            
            return [
                'success' => true,
                'message' => "Attendance saved successfully! $inserted new records, $updated updated.",
                'inserted' => $inserted,
                'updated' => $updated,
                'total' => count($attendanceData)
            ];
            
        } catch (Exception $e) {
            if ($this->db && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("ERROR saving attendance: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'message' => 'Error saving attendance: ' . $e->getMessage()];
        }
    }

    /**
     * Send attendance notifications to parents and student
     * @param array $attendanceRecords Array of attendance records that changed
     * @return array Result with success/sent count
     */
    public function sendAttendanceNotifications($attendanceRecords) {
        if (empty($attendanceRecords)) {
            return ['success' => true, 'sent' => 0];
        }

        $sent = 0;
        $emailSent = 0;
        $statusLabels = [
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'half_day' => 'Half Day',
            'holiday' => 'Holiday'
        ];

        try {
            foreach ($attendanceRecords as $record) {
                // 1. Send in-app notification to the student
                $studentTitle = "Attendance Update";
                $studentMessage = "Your attendance for {$record['date']} in {$record['class_name']} has been marked as **{$statusLabels[$record['status']]}**.";
                if ($record['old_status']) {
                    $studentMessage .= " (Previously: {$statusLabels[$record['old_status']]})";
                }
                $this->createNotification($record['user_id'], $studentTitle, $studentMessage, 'attendance');
                error_log("Created notification for student user_id: " . $record['user_id']);

                // 2. Get all guardians for this student
                $guardianStmt = $this->db->prepare("
                    SELECT g.user_id, g.relationship, u.name as guardian_name, u.email, u.phone
                    FROM guardians g
                    INNER JOIN users u ON g.user_id = u.id
                    WHERE g.student_id = ? AND u.is_active = 1
                ");
                $guardianStmt->execute([$record['student_id']]);
                $guardians = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Found " . count($guardians) . " guardians for student ID: " . $record['student_id']);

                if (!empty($guardians)) {
                    $formattedDate = date('F j, Y', strtotime($record['date']));
                    $statusText = $statusLabels[$record['status']];
                    
                    // Create parent notification message
                    $parentTitle = "Attendance Update: {$record['student_name']}";
                    $parentMessage = "Your child {$record['student_name']} from {$record['class_name']} was marked as **{$statusText}** on {$formattedDate}.";
                    
                    if (!empty($record['old_status'])) {
                        $oldStatusText = $statusLabels[$record['old_status']];
                        $parentMessage .= " (Previously: {$oldStatusText})";
                    }

                    foreach ($guardians as $guardian) {
                        // In-app notification for parent
                        $this->createNotification($guardian['user_id'], $parentTitle, $parentMessage, 'attendance');
                        error_log("Created notification for parent user_id: " . $guardian['user_id']);
                        
                        // Email notification (if email exists)
                        if (!empty($guardian['email']) && filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) {
                            $emailBody = $this->buildEmailBody($guardian['guardian_name'], $parentMessage, $record);
                            if ($this->sendEmail($guardian['email'], $parentTitle, $emailBody)) {
                                $emailSent++;
                                error_log("Email sent to parent: " . $guardian['email']);
                            } else {
                                error_log("Failed to send email to parent: " . $guardian['email']);
                            }
                        }
                        $sent++;
                    }
                }
            }

            error_log("Total notifications sent: $sent in-app, $emailSent emails");
            return [
                'success' => true,
                'sent' => $sent,
                'email_sent' => $emailSent,
                'message' => "$sent notifications sent ($emailSent emails)"
            ];

        } catch (Exception $e) {
            error_log("Error sending attendance notifications: " . $e->getMessage());
            return ['success' => false, 'sent' => 0, 'email_sent' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create an in-app notification
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param string $type
     * @return bool
     */
    private function createNotification($userId, $title, $message, $type = 'attendance') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications 
                (school_id, user_id, type, title, message, priority, created_at) 
                VALUES (?, ?, ?, ?, ?, 'normal', NOW())
            ");
            $result = $stmt->execute([$this->schoolId, $userId, $type, $title, $message]);
            error_log("Notification created for user $userId: $title");
            return $result;
        } catch (Exception $e) {
            error_log("Failed to create notification for user $userId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build a nice HTML email body
     */
    private function buildEmailBody($recipientName, $mainMessage, $record) {
        $statusColors = [
            'present' => '#28a745',
            'absent' => '#dc3545',
            'late' => '#ffc107',
            'half_day' => '#fd7e14',
            'holiday' => '#6c757d'
        ];
        $color = $statusColors[$record['status']] ?? '#6c757d';
        $statusLabel = ucfirst($record['status']);

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px; }
                .header { background-color: #f8f9fa; padding: 15px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { padding: 20px; }
                .status-badge { display: inline-block; padding: 5px 15px; border-radius: 30px; color: #fff; background-color: $color; font-weight: bold; }
                .footer { margin-top: 20px; font-size: 12px; color: #6c757d; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Attendance Update</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>$recipientName</strong>,</p>
                    <p>$mainMessage</p>
                    <p>
                        <span class='status-badge'>$statusLabel</span>
                    </p>
                    <p>Date: " . date('F j, Y', strtotime($record['date'])) . "<br>
                       Class: {$record['class_name']}</p>
                </div>
                <div class='footer'>
                    &copy; " . date('Y') . " " . ($this->schoolData['name'] ?? 'School') . ". All rights reserved.
                </div>
            </div>
        </body>
        </html>
        ";
        return $html;
    }

    /**
     * Send email using PHPMailer
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body HTML body
     * @return bool True if sent
     */
    private function sendEmail($to, $subject, $body) {
        // Check if mail configuration exists
        if (empty($this->mailConfig['mail_host']) || empty($this->mailConfig['mail_username'])) {
            error_log("Mail not configured. Cannot send email to $to");
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->mailConfig['mail_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->mailConfig['mail_username'];
            $mail->Password   = $this->mailConfig['mail_password'];
            $mail->SMTPSecure = $this->mailConfig['mail_encryption'] ?? 'tls';
            $mail->Port       = $this->mailConfig['mail_port'] ?? 587;

            // Recipients
            $mail->setFrom(
                $this->mailConfig['mail_from_address'] ?? $this->mailConfig['mail_username'],
                $this->mailConfig['mail_from_name'] ?? ($this->schoolData['name'] ?? 'School')
            );
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            error_log("Email sent to $to");
            return true;
        } catch (Exception $e) {
            error_log("Email could not be sent to $to. Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    /**
     * Get attendance summary for a class/student
     * @param int|null $classId Class ID (optional)
     * @param int|null $studentId Student ID (optional)
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Attendance summary
     */
    public function getAttendanceSummary($classId = null, $studentId = null, $startDate = null, $endDate = null) {
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        try {
            $sql = "
                SELECT 
                    DATE(date) as attendance_date,
                    DAYNAME(date) as day_name,
                    COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                    COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                    COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day_count,
                    COUNT(CASE WHEN status = 'holiday' THEN 1 END) as holiday_count,
                    COUNT(*) as total_count
                FROM attendance a
                WHERE a.school_id = ? 
                    AND a.date BETWEEN ? AND ?
            ";
            
            $params = [$this->schoolId, $startDate, $endDate];

            if ($classId) {
                $sql .= " AND a.class_id = ?";
                $params[] = $classId;
            }

            if ($studentId) {
                $sql .= " AND a.student_id = ?";
                $params[] = $studentId;
            }

            $sql .= " GROUP BY DATE(date), DAYNAME(date) ORDER BY attendance_date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals
            $totals = [
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'half_day' => 0,
                'holiday' => 0,
                'total' => 0,
                'attendance_percentage' => 0
            ];

            foreach ($results as $day) {
                $totals['present'] += $day['present_count'];
                $totals['absent'] += $day['absent_count'];
                $totals['late'] += $day['late_count'];
                $totals['half_day'] += $day['half_day_count'];
                $totals['holiday'] += $day['holiday_count'];
                $totals['total'] += $day['total_count'];
            }

            if ($totals['total'] > 0) {
                $totals['attendance_percentage'] = round(($totals['present'] + $totals['late']) / $totals['total'] * 100, 2);
            }

            error_log("Attendance summary fetched for class $classId, student $studentId: " . json_encode($totals));
            return [
                'daily' => $results,
                'totals' => $totals,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ];

        } catch (Exception $e) {
            error_log("Error in getAttendanceSummary: " . $e->getMessage());
            return [
                'daily' => [],
                'totals' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get student attendance report
     * @param int $studentId Student ID
     * @param int|null $academicYearId Academic Year ID
     * @param int|null $termId Term ID
     * @return array Student attendance report
     */
    public function getStudentAttendanceReport($studentId, $academicYearId = null, $termId = null) {
        try {
            $sql = "
                SELECT 
                    a.date,
                    a.status,
                    a.remark,
                    a.session,
                    c.name as class_name,
                    sec.name as section_name
                FROM attendance a
                INNER JOIN classes c ON a.class_id = c.id
                LEFT JOIN sections sec ON c.id = sec.class_id
                WHERE a.student_id = ? AND a.school_id = ?
            ";
            
            $params = [$studentId, $this->schoolId];

            if ($academicYearId) {
                $sql .= " AND c.academic_year_id = ?";
                $params[] = $academicYearId;
            }

            $sql .= " ORDER BY a.date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate statistics
            $stats = [
                'total_days' => count($attendance),
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'half_day' => 0,
                'holiday' => 0,
                'attendance_percentage' => 0
            ];

            foreach ($attendance as $record) {
                $stats[$record['status']] = ($stats[$record['status']] ?? 0) + 1;
            }

            if ($stats['total_days'] > 0) {
                $presentDays = $stats['present'] + $stats['late'];
                $stats['attendance_percentage'] = round(($presentDays / $stats['total_days']) * 100, 2);
            }

            error_log("Student attendance report fetched for student ID: " . $studentId . " - total days: " . $stats['total_days']);
            return [
                'attendance' => $attendance,
                'statistics' => $stats
            ];

        } catch (Exception $e) {
            error_log("Error in getStudentAttendanceReport: " . $e->getMessage());
            return [
                'attendance' => [],
                'statistics' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get class attendance report
     * @param int $classId Class ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Class attendance report
     */
    public function getClassAttendanceReport($classId, $startDate = null, $endDate = null) {
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        try {
            // Get total students in class
            $studentStmt = $this->db->prepare("
                SELECT COUNT(*) as total 
                FROM students 
                WHERE school_id = ? AND class_id = ? AND status = 'active'
            ");
            $studentStmt->execute([$this->schoolId, $classId]);
            $totalStudents = $studentStmt->fetchColumn();

            // Get attendance summary
            $sql = "
                SELECT 
                    a.date,
                    DAYNAME(a.date) as day_name,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                    COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_count,
                    COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_count,
                    COUNT(CASE WHEN a.status = 'half_day' THEN 1 END) as half_day_count,
                    COUNT(CASE WHEN a.status = 'holiday' THEN 1 END) as holiday_count,
                    COUNT(*) as marked_count
                FROM attendance a
                WHERE a.school_id = ? 
                    AND a.class_id = ?
                    AND a.date BETWEEN ? AND ?
                GROUP BY a.date, DAYNAME(a.date)
                ORDER BY a.date DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->schoolId, $classId, $startDate, $endDate]);
            $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate overall statistics
            $totals = [
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'half_day' => 0,
                'holiday' => 0,
                'total_marked' => 0,
                'days_with_data' => count($daily),
                'average_attendance' => 0
            ];

            foreach ($daily as $day) {
                $totals['present'] += $day['present_count'];
                $totals['absent'] += $day['absent_count'];
                $totals['late'] += $day['late_count'];
                $totals['half_day'] += $day['half_day_count'];
                $totals['holiday'] += $day['holiday_count'];
                $totals['total_marked'] += $day['marked_count'];
            }

            if ($totals['days_with_data'] > 0 && $totalStudents > 0) {
                $totalPossible = $totals['days_with_data'] * $totalStudents;
                $presentEquivalent = $totals['present'] + $totals['late'];
                $totals['average_attendance'] = round(($presentEquivalent / $totalPossible) * 100, 2);
            }

            error_log("Class attendance report fetched for class ID: " . $classId . " - days with data: " . $totals['days_with_data']);
            return [
                'daily' => $daily,
                'totals' => $totals,
                'total_students' => $totalStudents,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ];

        } catch (Exception $e) {
            error_log("Error in getClassAttendanceReport: " . $e->getMessage());
            return [
                'daily' => [],
                'totals' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get today's attendance for a class/section
     * @param int $classId Class ID
     * @param int|null $sectionId Section ID
     * @return array Today's attendance data
     */
    public function getTodaysAttendance($classId, $sectionId = null) {
        $today = date('Y-m-d');
        error_log("Fetching today's attendance for class: $classId, section: " . ($sectionId ?: 'All'));
        return $this->getStudentsWithAttendance($classId, $sectionId, null, $today);
    }

    /**
     * Check if attendance already marked for today
     * @param int $classId Class ID
     * @param int|null $sectionId Section ID
     * @return bool True if attendance marked
     */
    public function isAttendanceMarked($classId, $sectionId = null) {
        $today = date('Y-m-d');
        
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM attendance a
                INNER JOIN students s ON a.student_id = s.id
                WHERE a.class_id = ? AND a.date = ?
            ";
            
            $params = [$classId, $today];

            if ($sectionId) {
                $sql .= " AND s.section_id = ?";
                $params[] = $sectionId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->fetchColumn();

            error_log("Attendance check for class $classId, section $sectionId: " . ($count > 0 ? "already marked" : "not marked"));
            return $count > 0;

        } catch (Exception $e) {
            error_log("Error in isAttendanceMarked: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get attendance status counts for a date range
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param int|null $classId Class ID (optional)
     * @return array Status counts
     */
    public function getAttendanceStatusCounts($startDate, $endDate, $classId = null) {
        try {
            $sql = "
                SELECT 
                    status,
                    COUNT(*) as count
                FROM attendance
                WHERE school_id = ? 
                    AND date BETWEEN ? AND ?
            ";
            
            $params = [$this->schoolId, $startDate, $endDate];

            if ($classId) {
                $sql .= " AND class_id = ?";
                $params[] = $classId;
            }

            $sql .= " GROUP BY status";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Ensure all statuses are present
            $statuses = ['present', 'absent', 'late', 'half_day', 'holiday'];
            $counts = [];

            foreach ($statuses as $status) {
                $counts[$status] = $results[$status] ?? 0;
            }

            error_log("Attendance status counts fetched for range $startDate to $endDate: " . json_encode($counts));
            return $counts;

        } catch (Exception $e) {
            error_log("Error in getAttendanceStatusCounts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get attendance configuration
     * @return array Attendance configuration
     */
    public function getAttendanceConfig() {
        return [
            'present' => ['label' => 'Present', 'color' => '#28a745', 'icon' => 'ri-check-line'],
            'absent' => ['label' => 'Absent', 'color' => '#dc3545', 'icon' => 'ri-close-line'],
            'late' => ['label' => 'Late', 'color' => '#ffc107', 'icon' => 'ri-time-line'],
            'half_day' => ['label' => 'Half Day', 'color' => '#fd7e14', 'icon' => 'ri-sun-line'],
            'holiday' => ['label' => 'Holiday', 'color' => '#6c757d', 'icon' => 'ri-gift-line']
        ];
    }

    /**
     * Format time ago
     * @param string $datetime
     * @return string
     */
    public function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }
}