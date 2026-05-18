<?php
require_once __DIR__ . '/../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

header("Location: settings/general.php");
exit;
