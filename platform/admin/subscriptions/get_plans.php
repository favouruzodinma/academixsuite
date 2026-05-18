<?php
require_once __DIR__ . '/actions-helper.php';
requireSubscriptionAdmin();

$db = Database::getPlatformConnection();
$plans = $db->query("SELECT id, name, description, price_monthly, price_yearly, is_active FROM plans ORDER BY price_monthly ASC")->fetchAll(PDO::FETCH_ASSOC);
subscriptionJson($plans);
