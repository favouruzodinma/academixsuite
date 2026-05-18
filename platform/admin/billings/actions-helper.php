<?php

require_once __DIR__ . '/../../../includes/autoload.php';

function billingJsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function requireBillingPost(): array
{
    $auth = new Auth();
    if (!$auth->isLoggedIn('super_admin')) {
        billingJsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        billingJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $source = $origin ?: $referer;
    if ($source) {
        $sourceHost = parse_url($source, PHP_URL_HOST);
        if (!$sourceHost || strcasecmp($sourceHost, preg_replace('/:\d+$/', '', $host)) !== 0) {
            billingJsonResponse(['success' => false, 'message' => 'Invalid request origin'], 403);
        }
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    return $data;
}

function getInvoiceWithSchool(PDO $db, int $invoiceId): ?array
{
    $stmt = $db->prepare("
        SELECT i.*, s.name AS school_name, s.email AS school_email
        FROM invoices i
        LEFT JOIN schools s ON s.id = i.school_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    return $invoice ?: null;
}

function platformAudit(PDO $db, int $schoolId, string $event, string $description): void
{
    try {
        $stmt = $db->prepare("
            INSERT INTO platform_audit_logs (school_id, event, description, user_type, created_at)
            VALUES (?, ?, ?, 'super_admin', NOW())
        ");
        $stmt->execute([$schoolId, $event, $description]);
    } catch (Exception $e) {
        error_log("Billing audit log failed: " . $e->getMessage());
    }
}

function tableColumns(PDO $db, string $table): array
{
    $stmt = $db->query("SHOW COLUMNS FROM `$table`");
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

function updateInvoiceFields(PDO $db, int $invoiceId, array $fields): void
{
    $columns = tableColumns($db, 'invoices');
    $fields = array_intersect_key($fields, array_flip($columns));
    if (!$fields) {
        return;
    }

    $sets = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if ($value === '__NOW__') {
            $sets[] = "`$column` = NOW()";
            continue;
        }
        $sets[] = "`$column` = ?";
        $params[] = $value;
    }
    $params[] = $invoiceId;
    $stmt = $db->prepare("UPDATE invoices SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
}
