<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

$db = Database::getPlatformConnection();
$schoolId = (int)($_GET['school_id'] ?? $_POST['school_id'] ?? 0);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="school-logs-' . date('Ymd-His') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'School ID', 'Event', 'Description', 'User Type']);

try {
    $sql = "SELECT created_at, school_id, event, description, user_type FROM platform_audit_logs";
    $params = [];
    if ($schoolId > 0) {
        $sql .= " WHERE school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 1000";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, $row);
    }
} catch (Exception $e) {
    fputcsv($out, ['Export failed', '', '', '', '']);
}
fclose($out);
