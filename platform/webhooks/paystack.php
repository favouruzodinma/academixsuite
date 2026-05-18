<?php
/**
 * Paystack webhook receiver.
 *
 * Wired to the existing PaystackWebhook handler. Critical requirements:
 *   - Read the raw body (NOT $_POST) because signature verification is
 *     computed against the exact bytes Paystack sent.
 *   - Verify the x-paystack-signature header before doing any work.
 *   - Always respond 200 quickly so Paystack doesn't retry storms.
 */

require_once __DIR__ . '/../../includes/autoload.php';

header('Content-Type: application/json');

// Restrict to POST — Paystack only sends webhooks via POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature === '' || $raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing signature or body']);
    exit;
}

try {
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new Exception('Malformed JSON');
    }

    $config       = require __DIR__ . '/../../config/payment.php';
    $isLive       = !IS_LOCAL;
    $modeKey      = $isLive ? 'live' : 'test';
    $secretKey    = $config['gateways']['paystack'][$modeKey]['secret_key'] ?? '';
    $webhookSecret = $config['gateways']['paystack']['webhook_secret'] ?? $secretKey;

    if ($secretKey === '') {
        throw new Exception('Paystack secret key not configured');
    }

    $api = new \AcademixSuite\Gateway\Paystack\PaystackApi($secretKey, '', !$isLive);

    // Paystack signs with HMAC-SHA512 of the *body* using the SECRET key (not
    // the webhook secret — read their docs carefully). We delegate to the
    // existing validator on PaystackApi.
    if (!$api->validateWebhookSignature($raw, $signature)) {
        error_log("paystack webhook: signature mismatch from " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid signature']);
        exit;
    }

    $handler = new \AcademixSuite\Gateway\Paystack\PaystackWebhook($api, $webhookSecret);
    $result = $handler->handle($payload, $signature);

    http_response_code(200);
    echo json_encode($result);

} catch (Throwable $e) {
    error_log('paystack webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
