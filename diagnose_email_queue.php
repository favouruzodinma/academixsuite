<?php
/**
 * Email Queue System - Database Diagnostic Tool
 *
 * SECURITY: CLI only. The .htaccess at project root also blocks web access,
 * but this in-script guard is defence in depth.
 *
 * Usage: php diagnose_email_queue.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/includes/autoload.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   EMAIL QUEUE SYSTEM - DATABASE DIAGNOSTIC TOOL            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

try {
    // Check if Database class exists
    if (!class_exists('Database')) {
        die("❌ ERROR: Database class not found!\n");
    }

    echo "✓ Database class found\n\n";

    // Get database connection
    $db = Database::getPlatformConnection();

    if (!$db) {
        die("❌ ERROR: Could not connect to database!\n");
    }

    echo "✓ Database connection successful\n\n";

    // ============================================
    // 1. Check if email queue tables exist
    // ============================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "1. CHECKING EMAIL QUEUE TABLES\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $tables = ['email_queue', 'bulk_email_campaigns', 'email_bounces', 'email_suppression_list'];

    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;

        if ($exists) {
            $countStmt = $db->query("SELECT COUNT(*) as count FROM $table");
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "   ✓ Table '$table' exists ($count records)\n";
        }
        else {
            echo "   ❌ Table '$table' DOES NOT EXIST\n";
            echo "      → Run: mysql -u root -p academixsuite < database/migrations/create_email_queue_table.sql\n";
        }
    }

    echo "\n";

    // ============================================
    // 2. Check schools table
    // ============================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "2. CHECKING SCHOOLS TABLE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    // Total schools
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools");
    $totalSchools = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "   Total schools in database: $totalSchools\n\n";

    if ($totalSchools == 0) {
        echo "   ❌ NO SCHOOLS FOUND IN DATABASE!\n";
        echo "      This is why you're getting the error.\n";
        echo "      → You need to add schools to your database first.\n";
        echo "      → Or run the test data script: php create_test_schools.php\n\n";
    }
    else {
        // Schools by status
        echo "   Schools by status:\n";
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM schools GROUP BY status");
        $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($statusCounts as $row) {
            echo "      • {$row['status']}: {$row['count']} schools\n";
        }

        echo "\n";

        // Schools with valid emails
        $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE email IS NOT NULL AND email != ''");
        $schoolsWithEmail = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        echo "   Schools with email addresses: $schoolsWithEmail\n\n";

        if ($schoolsWithEmail == 0) {
            echo "   ❌ NO SCHOOLS HAVE EMAIL ADDRESSES!\n";
            echo "      → You need to add email addresses to your schools.\n\n";
        }

        // Sample schools
        echo "   Sample schools (first 5):\n";
        $stmt = $db->query("SELECT id, name, email, status FROM schools LIMIT 5");
        $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($schools as $school) {
            $emailStatus = !empty($school['email']) ? "✓ {$school['email']}" : "❌ NO EMAIL";
            echo "      • ID {$school['id']}: {$school['name']} ({$school['status']}) - $emailStatus\n";
        }

        echo "\n";
    }

    // ============================================
    // 3. Check school_admins table
    // ============================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "3. CHECKING SCHOOL_ADMINS TABLE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'school_admins'");
    $tableExists = $stmt->rowCount() > 0;

    if (!$tableExists) {
        echo "   ⚠ Table 'school_admins' does not exist\n";
        echo "      → This is OK - the system will use school emails as fallback\n\n";
    }
    else {
        // Total admins
        $stmt = $db->query("SELECT COUNT(*) as count FROM school_admins");
        $totalAdmins = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        echo "   Total school admins: $totalAdmins\n\n";

        if ($totalAdmins > 0) {
            // Active admins
            $stmt = $db->query("SELECT COUNT(*) as count FROM school_admins WHERE is_active = 1");
            $activeAdmins = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            echo "   Active admins: $activeAdmins\n";

            // Admins with email
            $stmt = $db->query("SELECT COUNT(*) as count FROM school_admins WHERE email IS NOT NULL AND email != ''");
            $adminsWithEmail = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            echo "   Admins with email: $adminsWithEmail\n\n";

            // Sample admins
            echo "   Sample admins (first 5):\n";
            $stmt = $db->query("SELECT school_id, email, role, is_active FROM school_admins LIMIT 5");
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($admins as $admin) {
                $status = $admin['is_active'] ? "✓ Active" : "❌ Inactive";
                $emailStatus = !empty($admin['email']) ? $admin['email'] : "NO EMAIL";
                echo "      • School {$admin['school_id']}: {$admin['role']} - $emailStatus ($status)\n";
            }

            echo "\n";
        }
    }

    // ============================================
    // 4. Test EmailService
    // ============================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "4. TESTING EMAILSERVICE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    if (!class_exists('EmailService')) {
        echo "   ❌ EmailService class not found!\n\n";
    }
    else {
        echo "   ✓ EmailService class found\n\n";

        $emailService = new EmailService();

        // Test different status filters
        $testCases = [
            ['status' => 'all', 'includeTrial' => true],
            ['status' => 'active', 'includeTrial' => true],
            ['status' => 'active', 'includeTrial' => false],
        ];

        foreach ($testCases as $test) {
            echo "   Testing: status='{$test['status']}', includeTrial=" . ($test['includeTrial'] ? 'true' : 'false') . "\n";

            $admins = $emailService->getAllSchoolAdmins($test['status'], $test['includeTrial']);

            echo "      → Found " . count($admins) . " admin records\n";

            if (count($admins) > 0) {
                $sample = array_slice($admins, 0, 2);
                foreach ($sample as $i => $admin) {
                    echo "         [" . ($i + 1) . "] {$admin['school_name']}: {$admin['admin_email']}\n";
                }
            }

            echo "\n";
        }
    }

    // ============================================
    // 5. Check EmailQueueManager
    // ============================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "5. CHECKING EMAILQUEUEMANAGER\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    if (!class_exists('EmailQueueManager')) {
        echo "   ❌ EmailQueueManager class not found!\n";
        echo "      → File should be at: includes/Services/EmailQueueManager.php\n\n";
    }
    else {
        echo "   ✓ EmailQueueManager class found\n\n";

        $queueManager = new EmailQueueManager();

        // Get queue stats
        $stats = $queueManager->getStats();

        if (empty($stats)) {
            echo "   Queue is empty (no emails)\n\n";
        }
        else {
            echo "   Queue status:\n";
            foreach ($stats as $stat) {
                echo "      • {$stat['status']}: {$stat['count']} emails\n";
            }
            echo "\n";
        }
    }

    // ============================================
    // SUMMARY & RECOMMENDATIONS
    // ============================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "SUMMARY & RECOMMENDATIONS\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    if ($totalSchools == 0) {
        echo "❌ ISSUE FOUND: No schools in database\n\n";
        echo "SOLUTION:\n";
        echo "   1. Add schools to your database manually, OR\n";
        echo "   2. Run the test data script:\n";
        echo "      php create_test_schools.php\n\n";
    }
    elseif ($schoolsWithEmail == 0) {
        echo "❌ ISSUE FOUND: Schools exist but have no email addresses\n\n";
        echo "SOLUTION:\n";
        echo "   1. Update your schools to add email addresses:\n";
        echo "      UPDATE schools SET email = 'admin@schoolname.com' WHERE id = 1;\n";
        echo "   2. Or run the test data script to create schools with emails:\n";
        echo "      php create_test_schools.php\n\n";
    }
    else {
        echo "✓ Database looks good!\n\n";
        echo "NEXT STEPS:\n";
        echo "   1. Install email queue tables (if not done):\n";
        echo "      mysql -u root -p academixsuite < database/migrations/create_email_queue_table.sql\n\n";
        echo "   2. Try sending a test email from the admin panel\n\n";
        echo "   3. Set up the cron job for background processing:\n";
        echo "      See: .gemini/CRON_JOB_SETUP_GUIDE.md\n\n";
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Diagnostic complete!\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";


}
catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}
