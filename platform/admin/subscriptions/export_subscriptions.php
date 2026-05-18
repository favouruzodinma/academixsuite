<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

$db = Database::getPlatformConnection();
$stmt = $db->query("
    SELECT s.name, s.email, s.status, p.name AS plan, sub.status AS subscription_status,
           sub.billing_cycle, sub.amount, sub.current_period_start, sub.current_period_end
    FROM schools s
    LEFT JOIN plans p ON p.id = s.plan_id
    LEFT JOIN subscriptions sub ON sub.school_id = s.id
    ORDER BY s.name
");

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=\"subscriptions-' . date('Ymd-His') . '.csv\"');
$out = fopen('php://output', 'w');
fputcsv($out, ['School', 'Email', 'School Status', 'Plan', 'Subscription Status', 'Billing Cycle', 'Amount', 'Period Start', 'Period End']);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, $row);
}
fclose($out);
