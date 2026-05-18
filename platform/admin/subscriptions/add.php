<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

header('Location: ../schools/add.php');
exit;
