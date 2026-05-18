<?php
/**
 * Public payment initializer.
 *
 * SECURITY NOTE: the previous version of this file accepted school_id, amount,
 * and email straight from the request body with no authentication, so anyone
 * could create a "payment intent" against any school for any amount and any
 * recipient — effectively a tool for free transactions or invoice fraud.
 *
 * This rewrite enforces three invariants:
 *   1. The caller must reference an existing, payable invoice by id+token.
 *   2. The amount, currency, school, and payer email are read from the DB,
 *      never from the request body.
 *   3. The endpoint is rate-limited per IP to mitigate enumeration.
 */

require_once __DIR__ . '/../../../../includes/autoload.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// --------------------------------------------------------------------------
// Basic per-IP rate limit (token bucket in DB) — keeps the endpoint cheap to
// run while preventing trivial enumeration of invoice IDs.
// --------------------------------------------------------------------------
function pp_rate_limit_or_die(\PDO $db, string $ip, int $maxPerMinute = 30): void
{
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS payment_init_rate_limit (
            ip VARCHAR(45) NOT NULL,
            window_started_at DATETIME NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $db->prepare("SELECT hits, UNIX_TIMESTAMP(window_started_at) AS started
                              FROM payment_init_rate_limit WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        if (!$row || ($now - (int) $row['started']) >= 60) {
            $reset = $db->prepare("REPLACE INTO payment_init_rate_limit (ip, window_started_at, hits)
                                   VALUES (?, NOW(), 1)");
            $reset->execute([$ip]);
            return;
        }

        if ((int) $row['hits'] >= $maxPerMinute) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Try again shortly.']);
            exit;
        }

        $bump = $db->prepare("UPDATE payment_init_rate_limit SET hits = hits + 1 WHERE ip = ?");
        $bump->execute([$ip]);
    } catch (Exception $e) {
        // Fail-open on infra errors — better than locking out legitimate payers.
        error_log("payment init rate-limit error: " . $e->getMessage());
    }
}

function pp_table_columns(\PDO $db, string $table): array
{
    $stmt = $db->query("SHOW COLUMNS FROM `$table`");
    return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
}

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        throw new \Exception('Invalid JSON body');
    }

    // The ONLY fields we trust from the caller:
    //   - invoice_id          : numeric, looked up server-side
    //   - invoice_access_token: opaque token issued when the invoice was created
    //   - gateway             : optional gateway preference (must be a known key)
    //
    // Anything else (amount, email, school_id, currency) is read from the DB.
    $invoiceId    = isset($input['invoice_id']) ? (int) $input['invoice_id'] : 0;
    $accessToken  = isset($input['invoice_access_token']) ? trim((string) $input['invoice_access_token']) : '';
    $gateway      = isset($input['gateway']) ? trim((string) $input['gateway']) : '';

    if ($invoiceId <= 0 || $accessToken === '') {
        throw new \Exception('Missing invoice_id or invoice_access_token');
    }
    if ($gateway !== '' && !preg_match('/^[a-z_]{2,32}$/', $gateway)) {
        throw new \Exception('Unsupported gateway');
    }

    $db = Database::getPlatformConnection();
    pp_rate_limit_or_die($db, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    $invoiceColumns = pp_table_columns($db, 'invoices');
    if (!in_array('access_token', $invoiceColumns, true)) {
        throw new \Exception('Invoice payment access is not configured');
    }

    $amountDueExpr = in_array('amount_due', $invoiceColumns, true)
        ? 'i.amount_due'
        : (in_array('total_amount', $invoiceColumns, true) ? 'i.total_amount' : 'i.amount');
    $balanceExpr = in_array('balance_amount', $invoiceColumns, true)
        ? 'i.balance_amount'
        : $amountDueExpr;
    $currencyExpr = in_array('currency', $invoiceColumns, true) ? 'i.currency' : "'NGN'";
    $payerEmailExpr = in_array('payer_email', $invoiceColumns, true) ? 'i.payer_email' : 's.email';
    $statusExpr = in_array('status', $invoiceColumns, true) ? 'i.status' : "'pending'";

    // Resolve the invoice and its owning school. The access_token column must
    // exist on invoices (or be added in a migration) and be a one-way random
    // value handed to the payer when the invoice was issued. Using a constant-
    // time comparison prevents timing-based token discovery.
    $stmt = $db->prepare("
        SELECT i.id, i.school_id,
               {$amountDueExpr} AS amount_due,
               {$balanceExpr} AS balance_amount,
               {$currencyExpr} AS currency,
               {$payerEmailExpr} AS payer_email,
               i.access_token,
               {$statusExpr} AS status,
               s.slug AS school_slug,
               s.name AS school_name, s.database_name
        FROM invoices i
        INNER JOIN schools s ON s.id = i.school_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice
        || empty($invoice['access_token'])
        || !hash_equals((string) $invoice['access_token'], $accessToken)) {
        // Same generic error in all token-mismatch / missing-row cases so
        // attackers cannot probe valid invoice IDs.
        throw new \Exception('Invoice not found');
    }

    if (in_array($invoice['status'], ['paid', 'void', 'cancelled', 'canceled', 'refunded'], true)) {
        throw new \Exception('Invoice is not payable in its current state');
    }

    // Pull the amount and recipient from the DB row — NEVER from the caller.
    $amount = isset($invoice['balance_amount']) && $invoice['balance_amount'] > 0
        ? (float) $invoice['balance_amount']
        : (float) $invoice['amount_due'];

    if ($amount <= 0) {
        throw new \Exception('Invoice has no outstanding balance');
    }
    if (empty($invoice['payer_email']) || !filter_var($invoice['payer_email'], FILTER_VALIDATE_EMAIL)) {
        throw new \Exception('Invoice payer email is missing');
    }

    $paymentService = new \AcademixSuite\Services\PaymentService($invoice['school_id']);
    $result = $paymentService->initializePayment([
        'type'       => 'invoice',
        'invoice_id' => (int) $invoice['id'],
        'amount'     => $amount,
        'currency'   => $invoice['currency'] ?? 'NGN',
        'email'      => $invoice['payer_email'],
        'gateway'    => $gateway !== '' ? $gateway : null,
        'metadata'   => [
            'school_id'   => (int) $invoice['school_id'],
            'school_slug' => $invoice['school_slug'],
            'origin_ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ],
    ]);

    echo json_encode(['success' => true, 'data' => $result]);

} catch (\Throwable $e) {
    error_log('payment/initialize.php error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
