<?php
require_once __DIR__ . '/actions-helper.php';

$data = requireBillingPost();
$invoiceId = (int)($data['invoice_id'] ?? 0);
$action = $data['action'] ?? '';
if ($invoiceId <= 0 || $action !== 'mark_paid') {
    billingJsonResponse(['success' => false, 'message' => 'Invalid invoice action'], 400);
}

try {
    $db = Database::getPlatformConnection();
    $invoice = getInvoiceWithSchool($db, $invoiceId);
    if (!$invoice) {
        billingJsonResponse(['success' => false, 'message' => 'Invoice not found'], 404);
    }

    updateInvoiceFields($db, $invoiceId, [
        'status' => 'paid',
        'payment_status' => 'success',
        'paid_at' => '__NOW__',
        'updated_at' => '__NOW__'
    ]);
    platformAudit($db, (int)$invoice['school_id'], 'invoice_marked_paid', 'Invoice ' . ($invoice['invoice_number'] ?? $invoiceId) . ' marked as paid.');
    billingJsonResponse(['success' => true, 'message' => 'Invoice marked as paid']);
} catch (Exception $e) {
    error_log("update-invoice-status failed: " . $e->getMessage());
    billingJsonResponse(['success' => false, 'message' => 'Unable to update invoice status'], 500);
}
