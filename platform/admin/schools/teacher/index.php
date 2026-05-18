<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
header('Location: ../add_user.php' . ($schoolId > 0 ? '?school_id=' . $schoolId : ''));
exit;
