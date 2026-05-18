<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

header('Location: index.php?message=' . urlencode('Bulk invoice generation is not enabled yet. Use school-level invoice generation from the school manager.'));
exit;
