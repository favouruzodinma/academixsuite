<?php
/**
 * Migration script to add enhanced tables to existing school databases
 */

require_once __DIR__ . '/includes/autoload.php';

class SchoolDatabaseMigrator {
    
    public static function migrateAllSchools() {
        try {
            $platformDb = Database::getPlatformConnection();
            
            // Get all active schools
            $schools = $platformDb->query("
                SELECT id, database_name 
                FROM schools 
                WHERE status IN ('active', 'trial') 
                AND database_name IS NOT NULL
            ")->fetchAll();
            
            $migratedCount = 0;
            $failedCount = 0;
            
            foreach ($schools as $school) {
                try {
                    self::migrateSchoolDatabase($school['id'], $school['database_name']);
                    $migratedCount++;
                    echo "Migrated school ID: {$school['id']} ({$school['database_name']})\n";
                } catch (Exception $e) {
                    $failedCount++;
                    error_log("Failed to migrate school {$school['id']}: " . $e->getMessage());
                    echo "Failed school ID: {$school['id']} - " . $e->getMessage() . "\n";
                }
            }
            
            return [
                'total' => count($schools),
                'migrated' => $migratedCount,
                'failed' => $failedCount
            ];
            
        } catch (Exception $e) {
            error_log("Migration failed: " . $e->getMessage());
            return ['total' => 0, 'migrated' => 0, 'failed' => 0];
        }
    }
    
    public static function migrateSchoolDatabase($schoolId, $databaseName) {
        try {
            // Get connection to school database
            $schoolDb = Database::getSchoolConnection($databaseName);
            
            // Disable foreign key checks temporarily
            $schoolDb->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // Add enhanced feature tables
            self::createEnhancedTables($schoolDb, $schoolId);
            
            // Insert default data for new tables
            self::insertEnhancedDefaults($schoolDb, $schoolId);
            
            // Re-enable foreign key checks
            $schoolDb->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            // Update platform record
            self::updateMigrationStatus($schoolId);
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception("Database migration failed: " . $e->getMessage());
        }
    }
    
    private static function createEnhancedTables($db, $schoolId) {
        // Get table creation SQL from Tenant class methods
        $enhancedTables = [
            // Subscription & Billing
            self::getSubscriptionsTableSql(),
            self::getBillingHistoryTableSql(),
            self::getPaymentMethodsTableSql(),
            self::getInvoicesV2TableSql(),
            
            // Storage & Usage
            self::getStorageUsageTableSql(),
            self::getFileStorageTableSql(),
            
            // Performance & Monitoring
            self::getPerformanceMetricsTableSql(),
            self::getApiLogsTableSql(),
            self::getAuditLogsTableSql(),
            
            // Security & Rate Limiting
            self::getSecurityLogsTableSql(),
            self::getRateLimitsTableSql(),
            self::getLoginAttemptsTableSql(),
            
            // Backup & Recovery
            self::getBackupHistoryTableSql(),
            self::getRecoveryPointsTableSql(),
            
            // Communication & Notifications
            self::getNotificationsTableSql(),
            self::getEmailTemplatesTableSql(),
            self::getSmsLogsTableSql(),
            
            // API Management
            self::getApiKeysTableSql(),
            self::getApiUsageTableSql(),
            
            // System Maintenance
            self::getMaintenanceLogsTableSql(),
            self::getSystemAlertsTableSql()
        ];
        
        foreach ($enhancedTables as $sql) {
            try {
                $db->exec($sql);
            } catch (Exception $e) {
                // Log but continue if table already exists
                error_log("Table creation warning: " . $e->getMessage());
            }
        }
    }
    
    private static function insertEnhancedDefaults($db, $schoolId) {
        try {
            // Insert default subscription if not exists
            $db->exec("
                INSERT IGNORE INTO `subscriptions` 
                (school_id, plan_id, plan_name, status, billing_cycle, amount, 
                 storage_limit, user_limit, student_limit, 
                 current_period_start, current_period_end, created_at) 
                SELECT 
                    $schoolId, 
                    p.id, 
                    p.name, 
                    'active', 
                    'monthly', 
                    p.price_monthly,
                    1073741824, 
                    100, 
                    500, 
                    CURDATE(), 
                    DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 
                    NOW()
                FROM platform.plans p 
                WHERE p.slug = 'starter'
            ");
            
            // Insert default storage usage
            $db->exec("INSERT IGNORE INTO `storage_usage` (school_id, storage_type, used_bytes, limit_bytes) VALUES
                ($schoolId, 'database', 0, 1073741824),
                ($schoolId, 'files', 0, 1073741824),
                ($schoolId, 'backups', 0, 536870912),
                ($schoolId, 'attachments', 0, 536870912)");
                
        } catch (Exception $e) {
            error_log("Default data insertion failed: " . $e->getMessage());
        }
    }
    
    private static function updateMigrationStatus($schoolId) {
        try {
            $platformDb = Database::getPlatformConnection();
            $stmt = $platformDb->prepare("
                UPDATE schools 
                SET settings = JSON_SET(
                    COALESCE(settings, '{}'), 
                    '$.migration_version', '2.0',
                    '$.migration_date', NOW()
                )
                WHERE id = ?
            ");
            $stmt->execute([$schoolId]);
        } catch (Exception $e) {
            error_log("Failed to update migration status: " . $e->getMessage());
        }
    }
    
    // Copy all the table creation methods from Tenant.php here
    // (getSubscriptionsTableSql(), getBillingHistoryTableSql(), etc.)
    // ... Include all the enhanced table creation methods ...
}

// Run migration
if (php_sapi_name() === 'cli') {
    echo "Starting migration of all school databases...\n";
    $result = SchoolDatabaseMigrator::migrateAllSchools();
    echo "\nMigration completed:\n";
    echo "Total schools: {$result['total']}\n";
    echo "Successfully migrated: {$result['migrated']}\n";
    echo "Failed: {$result['failed']}\n";
}