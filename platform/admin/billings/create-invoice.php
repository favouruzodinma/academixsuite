<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
if ($schoolId > 0) {
    header('Location: ../schools/manage.php?id=' . $schoolId);
    exit;
}

header('Location: index.php');
exit;
