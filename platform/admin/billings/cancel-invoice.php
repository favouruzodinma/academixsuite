<?php
require_once __DIR__ . '/actions-helper.php';

$data = requireBillingPost();
$invoiceId = (int)($data['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    billingJsonResponse(['success' => false, 'message' => 'Invalid invoice ID'], 400);
}

try {
    $db = Database::getPlatformConnection();
    $invoice = getInvoiceWithSchool($db, $invoiceId);
    if (!$invoice) {
        billingJsonResponse(['success' => false, 'message' => 'Invoice not found'], 404);
    }

    if (($invoice['status'] ?? '') === 'paid') {
        billingJsonResponse(['success' => false, 'message' => 'Paid invoices cannot be canceled'], 422);
    }

    updateInvoiceFields($db, $invoiceId, [
        'status' => 'canceled',
        'payment_status' => 'failed',
        'updated_at' => '__NOW__'
    ]);
    platformAudit($db, (int)$invoice['school_id'], 'invoice_canceled', 'Invoice ' . ($invoice['invoice_number'] ?? $invoiceId) . ' canceled.');
    billingJsonResponse(['success' => true, 'message' => 'Invoice canceled successfully']);
} catch (Exception $e) {
    error_log("cancel-invoice failed: " . $e->getMessage());
    billingJsonResponse(['success' => false, 'message' => 'Unable to cancel invoice'], 500);
}
