<?php
// platform/admin/schools/test_email.php
require_once __DIR__ . '/../../../includes/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$redirect = 'send-email.php';

function redirectWithBulkEmailFlash(string $type, string $message, string $redirect): void
{
    $_SESSION['bulk_email_flash'] = [
        'type' => $type,
        'message' => $message
    ];
    header('Location: ' . $redirect);
    exit;
}

try {
    $auth = new Auth();
    if (!$auth->isLoggedIn('super_admin')) {
        redirectWithBulkEmailFlash('error', 'Authentication required. Please log in again.', $redirect);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirectWithBulkEmailFlash('error', 'Invalid request method.', $redirect);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';
    if (!$csrfToken || !$sessionCsrf || !hash_equals($sessionCsrf, $csrfToken)) {
        redirectWithBulkEmailFlash('error', 'Security validation failed. Please refresh the page and try again.', $redirect);
    }

    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($subject === '' || $message === '') {
        redirectWithBulkEmailFlash('error', 'Subject and message are required before sending a test email.', $redirect);
    }

    $superAdmin = $_SESSION['super_admin'] ?? [];
    $testEmail = $superAdmin['email'] ?? null;

    if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        redirectWithBulkEmailFlash('error', 'Your super-admin account does not have a valid email address for test delivery.', $redirect);
    }

    $template = new EmailTemplate();
    $html = $template->getTemplate('notification', [
        'title' => '[Test] ' . $subject,
        'eyebrow' => 'Bulk email test',
        'intro' => 'This is a test copy of the bulk email campaign. It was sent only to your super-admin email address.',
        'message' => trim(strip_tags($message)),
        'details' => [
            'Recipient' => $testEmail,
            'Sent by' => $superAdmin['name'] ?? 'Super Admin',
            'Date' => date('F j, Y g:i A')
        ]
    ]);

    $emailService = new EmailService();
    $result = $emailService->sendEmail($testEmail, '[Test] ' . $subject, $html, trim(strip_tags($message)));

    if (!empty($result['success'])) {
        redirectWithBulkEmailFlash('success', 'Test email sent successfully to ' . $testEmail . '.', $redirect);
    }

    redirectWithBulkEmailFlash('error', 'Test email failed: ' . ($result['error'] ?? 'Unknown email service error'), $redirect);
} catch (Throwable $e) {
    error_log('Bulk test email failed: ' . $e->getMessage());
    redirectWithBulkEmailFlash('error', 'Test email failed: ' . $e->getMessage(), $redirect);
}
