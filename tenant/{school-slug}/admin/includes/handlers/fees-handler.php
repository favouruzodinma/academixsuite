<?php
/**
 * Fees module CRUD handler
 * Include at the top of fees-type.php, fees-group.php, fees-discount.php
 */
require_once __DIR__ . '/../admin-bootstrap.php';
require_once ROOT_PATH . '/includes/Services/WhatsAppService.php';

$message = '';
$error = '';

if (!function_exists('academix_send_fee_whatsapp_notice')) {
    function academix_send_fee_whatsapp_notice(PDO $schoolDb, array $school, int $studentId, float $amount, string $reference, int $paymentId = 0): void {
        try {
            if (!class_exists('WhatsAppService') || !WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'fees', true)) {
                return;
            }

            $stmt = $schoolDb->prepare("
                SELECT
                    s.first_name,
                    s.last_name,
                    u.id AS guardian_user_id,
                    u.name AS guardian_name,
                    u.phone
                FROM students s
                INNER JOIN guardians g ON g.student_id = s.id AND g.school_id = s.school_id
                INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id
                WHERE s.id = ? AND s.school_id = ? AND u.is_active = 1
                  AND u.phone IS NOT NULL AND u.phone != ''
            ");
            $stmt->execute([$studentId, (int)$school['id']]);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($recipients)) {
                return;
            }

            $service = new WhatsAppService($schoolDb, $school);
            $studentName = trim((string)($recipients[0]['first_name'] ?? '') . ' ' . (string)($recipients[0]['last_name'] ?? ''));
            $currency = $school['currency_symbol'] ?? '₦';
            $formattedAmount = $currency . number_format($amount, 2);
            $title = 'Fee Payment Recorded';
            $description = "A fee payment of {$formattedAmount} for {$studentName} has been recorded. Reference: {$reference}.";

            foreach ($recipients as $recipient) {
                $service->sendDirectNotification(
                    'fee',
                    $paymentId,
                    [
                        'user_id' => (int)$recipient['guardian_user_id'],
                        'name' => $recipient['guardian_name'] ?? 'Parent',
                        'phone' => $recipient['phone'] ?? '',
                        'recipient_type' => 'parent',
                    ],
                    $title,
                    $description,
                    'parent/dashboard.php'
                );
            }
        } catch (Throwable $e) {
            error_log('Fee WhatsApp notice failed: ' . $e->getMessage());
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schoolDb) {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create_fees_type':
                if (empty($_POST['name'])) throw new Exception('Fees type name is required');
                $stmt = $schoolDb->prepare("INSERT INTO fee_types (school_id, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                $message = 'Fees type created successfully';
                break;

            case 'update_fees_type':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE fee_types SET name=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                $message = 'Fees type updated';
                break;

            case 'delete_fees_type':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_types WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                $message = 'Fees type deleted';
                break;

            case 'create_fees_group':
                if (empty($_POST['name'])) throw new Exception('Fees group name is required');
                $stmt = $schoolDb->prepare("INSERT INTO fee_groups (school_id, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                $message = 'Fees group created';
                break;

            case 'update_fees_group':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE fee_groups SET name=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                $message = 'Fees group updated';
                break;

            case 'delete_fees_group':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_groups WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                $message = 'Fees group deleted';
                break;

            case 'create_fees_discount':
                if (empty($_POST['name']) || empty($_POST['amount'])) throw new Exception('Name and amount required');
                $stmt = $schoolDb->prepare("INSERT INTO fee_discounts (school_id, name, amount, type, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['amount'], $_POST['type'] ?? 'fixed', $_POST['status'] ?? 'Active']);
                $message = 'Discount created';
                break;

            case 'update_fees_discount':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE fee_discounts SET name=?, amount=?, type=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['amount'], $_POST['type'] ?? 'fixed', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                $message = 'Discount updated';
                break;

            case 'delete_fees_discount':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_discounts WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                $message = 'Discount deleted';
                break;

            case 'collect_fee':
                if (empty($_POST['student_id']) || empty($_POST['amount'])) throw new Exception('Student and amount required');
                $reference = $_POST['reference'] ?? 'MANUAL-' . time();
                $stmt = $schoolDb->prepare("INSERT INTO fee_payments (school_id, student_id, fee_type_id, amount, payment_method, reference, paid_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$school['id'], $_POST['student_id'], $_POST['fee_type_id'] ?? null, $_POST['amount'], $_POST['payment_method'] ?? 'cash', $reference]);
                $paymentId = (int)$schoolDb->lastInsertId();
                academix_send_fee_whatsapp_notice($schoolDb, $school, (int)$_POST['student_id'], (float)$_POST['amount'], $reference, $paymentId);
                $message = 'Fee collected successfully';
                break;
        }
        if ($message) setToast('success', $message);
    } catch (Exception $e) {
        error_log("Fees handler error: " . $e->getMessage());
        setToast('error', $e->getMessage());
    }
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? ''));
    exit;
}
