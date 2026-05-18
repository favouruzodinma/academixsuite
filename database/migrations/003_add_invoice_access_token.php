<?php
/**
 * Add public payment access tokens to platform invoices.
 *
 * Run once from CLI:
 * php database/migrations/003_add_invoice_access_token.php
 */

require_once __DIR__ . '/../../includes/autoload.php';

$db = Database::getPlatformConnection();

$columns = $db->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN, 0);

if (!in_array('access_token', $columns, true)) {
    $db->exec("ALTER TABLE invoices ADD COLUMN access_token VARCHAR(100) NULL AFTER invoice_number");
    echo "Added invoices.access_token\n";
} else {
    echo "invoices.access_token already exists\n";
}

$stmt = $db->query("SELECT id FROM invoices WHERE access_token IS NULL OR access_token = ''");
$invoiceIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$update = $db->prepare("UPDATE invoices SET access_token = ? WHERE id = ?");
foreach ($invoiceIds as $invoiceId) {
    $update->execute([generate_invoice_access_token(), $invoiceId]);
}

echo "Backfilled " . count($invoiceIds) . " invoice access token(s)\n";

$indexes = $db->query("SHOW INDEX FROM invoices WHERE Key_name = 'idx_invoices_access_token'")->fetchAll();
if (!$indexes) {
    $db->exec("CREATE UNIQUE INDEX idx_invoices_access_token ON invoices (access_token)");
    echo "Added idx_invoices_access_token\n";
} else {
    echo "idx_invoices_access_token already exists\n";
}
