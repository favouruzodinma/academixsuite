<?php
/**
 * ============================================================
 * CRON TASK: Cleanup Old Logs
 * ============================================================
 * 
 * Cleans up old cron logs and execution history to prevent database bloat.
 * Also cleans up old sent/failed emails from the queue.
 * 
 * SCHEDULE: Daily at 2 AM
 * CRON: 0 2 * * *
 * 
 * OPTIONS:
 *   --logs-days=N      : Keep logs for N days (default: 30)
 *   --emails-days=N    : Keep emails for N days (default: 7)
 *   --history-days=N   : Keep execution history for N days (default: 90)
 *   --dry-run          : Simulate without actually deleting
 * 
 * ============================================================
 */

function executeTask($options, $logger) {
    $logsDays = isset($options['logs-days']) ? (int)$options['logs-days'] : 30;
    $emailsDays = isset($options['emails-days']) ? (int)$options['emails-days'] : 7;
    $historyDays = isset($options['history-days']) ? (int)$options['history-days'] : 90;
    $dryRun = isset($options['dry-run']);
    
    $logger->info("Starting cleanup of old logs and data", [
        'logs_retention_days' => $logsDays,
        'emails_retention_days' => $emailsDays,
        'history_retention_days' => $historyDays,
        'dry_run' => $dryRun
    ]);
    
    $db = Database::getPlatformConnection();
    
    $totalDeleted = 0;
    
    // ============================================================
    // 1. Clean up old cron logs
    // ============================================================
    try {
        if ($dryRun) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM cron_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $logsDays]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'];
            
            $logger->info("DRY RUN: Would delete {count} cron log entries", ['count' => $count]);
            
        } else {
            $stmt = $db->prepare("
                DELETE FROM cron_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $logsDays]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;
            
            $logger->info("Deleted {count} old cron log entries", ['count' => $deleted]);
        }
        
    } catch (Exception $e) {
        $logger->error("Failed to clean up cron logs", ['error' => $e->getMessage()]);
    }
    
    // ============================================================
    // 2. Clean up old execution history
    // ============================================================
    try {
        if ($dryRun) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM cron_execution_history 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $historyDays]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'];
            
            $logger->info("DRY RUN: Would delete {count} execution history entries", ['count' => $count]);
            
        } else {
            $stmt = $db->prepare("
                DELETE FROM cron_execution_history 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $historyDays]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;
            
            $logger->info("Deleted {count} old execution history entries", ['count' => $deleted]);
        }
        
    } catch (Exception $e) {
        $logger->error("Failed to clean up execution history", ['error' => $e->getMessage()]);
    }
    
    // ============================================================
    // 3. Clean up old sent/failed emails
    // ============================================================
    try {
        if ($dryRun) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM email_queue 
                WHERE status IN ('sent', 'failed', 'cancelled') 
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $emailsDays]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'];
            
            $logger->info("DRY RUN: Would delete {count} old emails from queue", ['count' => $count]);
            
        } else {
            $stmt = $db->prepare("
                DELETE FROM email_queue 
                WHERE status IN ('sent', 'failed', 'cancelled') 
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $emailsDays]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;
            
            $logger->info("Deleted {count} old emails from queue", ['count' => $deleted]);
        }
        
    } catch (Exception $e) {
        $logger->error("Failed to clean up email queue", ['error' => $e->getMessage()]);
    }
    
    // ============================================================
    // 4. Clean up old processed suspensions
    // ============================================================
    try {
        if ($dryRun) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM student_suspension_queue 
                WHERE status IN ('processed', 'cancelled') 
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $historyDays]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'];
            
            $logger->info("DRY RUN: Would delete {count} old suspension records", ['count' => $count]);
            
        } else {
            $stmt = $db->prepare("
                DELETE FROM student_suspension_queue 
                WHERE status IN ('processed', 'cancelled') 
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $historyDays]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;
            
            $logger->info("Deleted {count} old suspension records", ['count' => $deleted]);
        }
        
    } catch (Exception $e) {
        $logger->error("Failed to clean up suspension queue", ['error' => $e->getMessage()]);
    }
    
    // ============================================================
    // 5. Clean up old published announcements
    // ============================================================
    try {
        if ($dryRun) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM scheduled_announcements 
                WHERE status = 'published' 
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $historyDays]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'];
            
            $logger->info("DRY RUN: Would delete {count} old announcement records", ['count' => $count]);
            
        } else {
            $stmt = $db->prepare("
                DELETE FROM scheduled_announcements 
                WHERE status = 'published' 
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $historyDays]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;
            
            $logger->info("Deleted {count} old announcement records", ['count' => $deleted]);
        }
        
    } catch (Exception $e) {
        $logger->error("Failed to clean up announcements", ['error' => $e->getMessage()]);
    }
    
    // ============================================================
    // 6. Optimize tables after cleanup
    // ============================================================
    if (!$dryRun && $totalDeleted > 1000) {
        try {
            $logger->info("Optimizing tables after large cleanup...");
            
            $tables = [
                'cron_logs',
                'cron_execution_history',
                'email_queue',
                'student_suspension_queue',
                'scheduled_announcements'
            ];
            
            foreach ($tables as $table) {
                try {
                    $db->exec("OPTIMIZE TABLE $table");
                    $logger->info("Optimized table: $table");
                } catch (Exception $e) {
                    $logger->warning("Failed to optimize table $table: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $logger->error("Failed to optimize tables", ['error' => $e->getMessage()]);
        }
    }
    
    $logger->success("Cleanup completed", [
        'total_deleted' => $totalDeleted,
        'dry_run' => $dryRun
    ]);
    
    return [
        'processed' => $totalDeleted,
        'succeeded' => $totalDeleted,
        'failed' => 0
    ];
}
