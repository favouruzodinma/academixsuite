<?php
require_once __DIR__ . '/actions-helper.php';
requireSubscriptionAdmin();

try {
    $db = Database::getPlatformConnection();
    $stmt = $db->query("
        SELECT s.id, s.name, s.email, sub.current_period_end
        FROM schools s
        JOIN subscriptions sub ON sub.school_id = s.id
        WHERE s.email IS NOT NULL
          AND s.email <> ''
          AND sub.current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
    ");
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $template = new EmailTemplate();
    $email = new EmailService();
    $sent = 0;
    foreach ($schools as $school) {
        if (!filter_var($school['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $html = $template->getTemplate('notification', [
            'title' => 'Subscription Renewal Reminder',
            'intro' => 'Hello ' . $school['name'] . ', your subscription is approaching renewal.',
            'message' => 'Please review your billing details to avoid service interruption.',
            'details' => ['Renewal date' => date('F j, Y', strtotime($school['current_period_end']))],
            'button_text' => 'Open School Portal',
            'button_url' => (defined('APP_URL') ? APP_URL : '') . '/tenant/login.php'
        ]);
        $result = $email->sendEmail($school['email'], 'Subscription Renewal Reminder', $html);
        if (!empty($result['success'])) {
            $sent++;
        }
    }
    subscriptionJson(['success' => true, 'count' => $sent]);
} catch (Exception $e) {
    error_log("send_reminders failed: " . $e->getMessage());
    subscriptionJson(['success' => false, 'message' => 'Unable to send reminders'], 500);
}
