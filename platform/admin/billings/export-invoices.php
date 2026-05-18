<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

$db = Database::getPlatformConnection();
$stmt = $db->query("
    SELECT i.invoice_number, s.name AS school, s.email AS school_email, i.status, i.payment_status,
           i.amount, i.total_amount, i.currency, i.due_date, i.created_at
    FROM invoices i
    LEFT JOIN schools s ON s.id = i.school_id
    ORDER BY i.created_at DESC
");

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=\"invoices-' . date('Ymd-His') . '.csv\"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Invoice Number', 'School', 'School Email', 'Status', 'Payment Status', 'Amount', 'Total Amount', 'Currency', 'Due Date', 'Created At']);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, $row);
}
fclose($out);
