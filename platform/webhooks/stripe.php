<?php
/**
 * Stripe webhook receiver.
 *
 * Stripe verifies via the 'Stripe-Signature' header using a webhook secret
 * (whsec_…). The signed string is "{timestamp}.{raw_body}" hashed with
 * HMAC-SHA256. We implement the verification inline so this file doesn't
 * require the Stripe PHP SDK to be installed.
 */

require_once __DIR__ . '/../../includes/autoload.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw       = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

/**
 * Verify a Stripe signature header. Returns true if any of the provided v1
 * signatures match. 5-minute tolerance window on the timestamp.
 */
function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    if ($header === '' || $secret === '') {
        return false;
    }
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't')  $timestamp = (int) $kv[1];
        if ($kv[0] === 'v1') $signatures[] = $kv[1];
    }
    if ($timestamp === null || empty($signatures)) return false;
    if (abs(time() - $timestamp) > $tolerance) return false;

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

try {
    $config = require __DIR__ . '/../../config/payment.php';
    $secret = $config['gateways']['stripe']['webhook_secret']
        ?? \AcademixSuite\Helpers\EnvHelper::get('STRIPE_WEBHOOK_SECRET', '');

    if (!stripe_verify_signature($raw, $sigHeader, (string) $secret)) {
        error_log('stripe webhook: signature mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid signature']);
        exit;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new Exception('Malformed JSON');
    }

    $handlerClass = '\\AcademixSuite\\Gateway\\Stripe\\StripeWebhook';
    if (class_exists($handlerClass)) {
        $isLive = !IS_LOCAL;
        $modeKey = $isLive ? 'live' : 'test';
        $secretKey = $config['gateways']['stripe'][$modeKey]['secret_key'] ?? '';
        $apiClass = '\\AcademixSuite\\Gateway\\Stripe\\StripeApi';
        $api = class_exists($apiClass) ? new $apiClass($secretKey, '', !$isLive) : null;
        $handler = new $handlerClass($api, (string) $secret);
        $result = $handler->handle($payload, $sigHeader);
    } else {
        error_log('stripe webhook (no handler class) payload: ' . substr($raw, 0, 4096));
        $result = ['success' => true, 'processed' => false, 'note' => 'Logged for manual reconciliation'];
    }

    http_response_code(200);
    echo json_encode($result);

} catch (Throwable $e) {
    error_log('stripe webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
