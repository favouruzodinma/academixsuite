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
    if (empty($invoice['school_email']) || !filter_var($invoice['school_email'], FILTER_VALIDATE_EMAIL)) {
        billingJsonResponse(['success' => false, 'message' => 'School email is missing or invalid'], 422);
    }

    $template = new EmailTemplate();
    $html = $template->getTemplate('invoice', [
        'school_name' => $invoice['school_name'] ?? 'School',
        'invoice_number' => $invoice['invoice_number'] ?? ('#' . $invoiceId),
        'amount' => number_format((float)($invoice['total_amount'] ?? $invoice['amount'] ?? 0), 2),
        'currency' => $invoice['currency'] ?? 'NGN',
        'due_date' => !empty($invoice['due_date']) ? date('F j, Y', strtotime($invoice['due_date'])) : '',
        'description' => $invoice['description'] ?? 'Subscription invoice',
        'status' => $invoice['status'] ?? 'sent'
    ]);

    $email = new EmailService();
    $send = $email->sendEmail($invoice['school_email'], 'Invoice ' . ($invoice['invoice_number'] ?? $invoiceId), $html);
    if (empty($send['success'])) {
        billingJsonResponse(['success' => false, 'message' => $send['error'] ?? 'Email sending failed'], 500);
    }

    if (($invoice['status'] ?? '') === 'draft') {
        updateInvoiceFields($db, $invoiceId, ['status' => 'sent', 'updated_at' => '__NOW__']);
    } else {
        updateInvoiceFields($db, $invoiceId, ['updated_at' => '__NOW__']);
    }
    platformAudit($db, (int)$invoice['school_id'], 'invoice_sent', 'Invoice ' . ($invoice['invoice_number'] ?? $invoiceId) . ' sent to school.');
    billingJsonResponse(['success' => true, 'message' => 'Invoice sent successfully']);
} catch (Exception $e) {
    error_log("send-invoice failed: " . $e->getMessage());
    billingJsonResponse(['success' => false, 'message' => 'Unable to send invoice'], 500);
}
