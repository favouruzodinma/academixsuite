<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Use the Reports menu for full platform reports.']);
