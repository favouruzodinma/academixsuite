<?php
/**
 * Create Test Schools for Email Queue Testing — CLI only.
 *
 * Usage: php create_test_schools.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/includes/autoload.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   CREATE TEST SCHOOLS FOR EMAIL QUEUE TESTING              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Configuration
$numberOfSchools = 10; // Change this to create more/fewer schools
$useRealEmail = false; // Set to true to use your real email for testing

if ($useRealEmail) {
    echo "Enter your email address for testing: ";
    $testEmail = trim(fgets(STDIN));

    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        die("❌ Invalid email address!\n");
    }
}
else {
    $testEmail = null;
}

try {
    // Get database connection
    if (!class_exists('Database')) {
        die("❌ ERROR: Database class not found!\n");
    }

    $db = Database::getPlatformConnection();

    if (!$db) {
        die("❌ ERROR: Could not connect to database!\n");
    }

    echo "✓ Database connection successful\n\n";

    // Check if schools table exists
    $stmt = $db->query("SHOW TABLES LIKE 'schools'");
    if ($stmt->rowCount() == 0) {
        die("❌ ERROR: 'schools' table does not exist!\n");
    }

    echo "Creating $numberOfSchools test schools...\n\n";

    $created = 0;
    $skipped = 0;

    $statuses = ['active', 'trial', 'active', 'active']; // More active schools

    for ($i = 1; $i <= $numberOfSchools; $i++) {
        $schoolName = "Test School " . str_pad($i, 3, '0', STR_PAD_LEFT);
        $subdomain = "testschool" . str_pad($i, 3, '0', STR_PAD_LEFT);
        $status = $statuses[($i - 1) % count($statuses)];

        // Use real email if provided, otherwise use test email
        if ($testEmail) {
            $email = $testEmail;
        }
        else {
            $email = "admin" . $i . "@testschool" . $i . ".com";
        }

        // Check if school already exists
        $checkStmt = $db->prepare("SELECT id FROM schools WHERE subdomain = ?");
        $checkStmt->execute([$subdomain]);

        if ($checkStmt->rowCount() > 0) {
            echo "   ⚠ Skipped: $schoolName (already exists)\n";
            $skipped++;
            continue;
        }

        // Insert school
        $insertStmt = $db->prepare("
            INSERT INTO schools (
                name, 
                subdomain, 
                email, 
                status, 
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, NOW(), NOW())
        ");

        $result = $insertStmt->execute([
            $schoolName,
            $subdomain,
            $email,
            $status
        ]);

        if ($result) {
            $schoolId = $db->lastInsertId();
            echo "   ✓ Created: $schoolName (ID: $schoolId, Status: $status, Email: $email)\n";
            $created++;
        }
        else {
            echo "   ❌ Failed: $schoolName\n";
        }
    }

    echo "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "SUMMARY\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    echo "   Created: $created schools\n";
    echo "   Skipped: $skipped schools (already existed)\n\n";

    if ($created > 0) {
        // Show summary by status
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM schools GROUP BY status");
        $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "   Schools by status:\n";
        foreach ($statusCounts as $row) {
            echo "      • {$row['status']}: {$row['count']} schools\n";
        }

        echo "\n";
        echo "✓ Test schools created successfully!\n\n";
        echo "NEXT STEPS:\n";
        echo "   1. Run diagnostic: php diagnose_email_queue.php\n";
        echo "   2. Try sending bulk email from admin panel\n";
        echo "   3. Check queue: SELECT * FROM email_queue;\n\n";
    }
    else {
        echo "⚠ No schools were created (they may already exist)\n\n";
    }


}
catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}
