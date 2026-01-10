<?php
// platform/admin/logout.php
session_start();

// Include auth
require_once __DIR__ . '/../../includes/autoload.php';

$auth = new Auth();
$auth->logout(true);
?>