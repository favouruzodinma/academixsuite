<?php
// platform/admin/keep-alive.php
session_start();
require_once __DIR__ . '/../../includes/autoload.php';

// Update last activity time
if (isset($_SESSION['super_admin'])) {
    $_SESSION['super_admin']['last_activity'] = time();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
}
?>