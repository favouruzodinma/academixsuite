<?php
require_once __DIR__ . '/actions-helper.php';

$data = requireBillingPost();
$message = substr((string)($data['message'] ?? 'Unknown browser error'), 0, 1000);
$source = substr((string)($data['source'] ?? ''), 0, 500);
$line = (int)($data['line'] ?? 0);

error_log("Billing browser error: {$message} {$source}:{$line}");
billingJsonResponse(['success' => true]);
