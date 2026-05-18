<?php
/**
 * Flutterwave webhook receiver.
 *
 * Flutterwave signs webhooks by sending a 'verif-hash' header. The merchant
 * sets the expected value in the dashboard ("Webhook secret hash"). Compare
 * the header to the configured secret in constant time.
 */

require_once __DIR__ . '/../../includes/autoload.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$received = $_SERVER['HTTP_VERIF_HASH'] ?? '';

try {
    $config = require __DIR__ . '/../../config/payment.php';
    $expected = $config['gateways']['flutterwave']['webhook_hash']
        ?? \AcademixSuite\Helpers\EnvHelper::get('FLUTTERWAVE_WEBHOOK_HASH', '');

    if ($expected === '' || $received === '' || !hash_equals((string) $expected, (string) $received)) {
        error_log('flutterwave webhook: signature mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid signature']);
        exit;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new Exception('Malformed JSON');
    }

    // If a FlutterwaveWebhook handler class exists, dispatch to it. Otherwise
    // log the payload for later reconciliation.
    $handlerClass = '\\AcademixSuite\\Gateway\\Flutterwave\\FlutterwaveWebhook';
    if (class_exists($handlerClass)) {
        $isLive = !IS_LOCAL;
        $modeKey = $isLive ? 'live' : 'test';
        $secretKey = $config['gateways']['flutterwave'][$modeKey]['secret_key'] ?? '';
        $apiClass = '\\AcademixSuite\\Gateway\\Flutterwave\\FlutterwaveApi';
        $api = class_exists($apiClass) ? new $apiClass($secretKey, '', !$isLive) : null;
        $handler = new $handlerClass($api, $expected);
        $result = $handler->handle($payload, $received);
    } else {
        error_log('flutterwave webhook (no handler class) payload: ' . substr($raw, 0, 4096));
        $result = ['success' => true, 'processed' => false, 'note' => 'Logged for manual reconciliation'];
    }

    http_response_code(200);
    echo json_encode($result);

} catch (Throwable $e) {
    error_log('flutterwave webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
