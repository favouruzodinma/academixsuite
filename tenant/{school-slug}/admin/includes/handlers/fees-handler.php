<?php
/**
 * Fees module CRUD handler
 * Include at the top of fees-type.php, fees-group.php, fees-discount.php
 */
require_once __DIR__ . '/../admin-bootstrap.php';
require_once __DIR__ . '/../campus-context.php';
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

$campusId = $schoolDb ? academix_admin_resolve_campus_id($schoolDb, $school, false) : 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schoolDb) {
    $action = $_POST['action'] ?? '';
    if (!academix_admin_validate_csrf($_POST['csrf_token'] ?? null)) {
        setToast('error', 'Security validation failed. Please refresh and try again.');
        $redirectTo = academix_admin_safe_redirect_target($_POST['return_to'] ?? null);
        header('Location: ' . $redirectTo);
        exit;
    }
    try {
        switch ($action) {
            case 'create_fees_type':
                if (empty($_POST['name'])) throw new Exception('Fees type name is required');
                $stmt = $schoolDb->prepare("INSERT INTO fee_types (school_id, campus_id, name, description, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $campusId, $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                $message = 'Fees type created successfully';
                break;

            case 'update_fees_type':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE fee_types SET name=?, description=?, status=? WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id'], $campusId]);
                $message = 'Fees type updated';
                break;

            case 'delete_fees_type':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_types WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['id'], $school['id'], $campusId]);
                $message = 'Fees type deleted';
                break;

            case 'create_fees_group':
                if (empty($_POST['name'])) throw new Exception('Fees group name is required');
                $stmt = $schoolDb->prepare("INSERT INTO fee_groups (school_id, campus_id, name, description, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $campusId, $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                $message = 'Fees group created';
                break;

            case 'update_fees_group':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE fee_groups SET name=?, description=?, status=? WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id'], $campusId]);
                $message = 'Fees group updated';
                break;

            case 'delete_fees_group':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_groups WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['id'], $school['id'], $campusId]);
                $message = 'Fees group deleted';
                break;

            case 'create_fees_discount':
                if (empty($_POST['name']) || empty($_POST['amount'])) throw new Exception('Name and amount required');
                $stmt = $schoolDb->prepare("INSERT INTO fee_discounts (school_id, campus_id, name, amount, type, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $campusId, $_POST['name'], $_POST['amount'], $_POST['type'] ?? 'fixed', $_POST['status'] ?? 'Active']);
                $message = 'Discount created';
                break;

            case 'update_fees_discount':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE fee_discounts SET name=?, amount=?, type=?, status=? WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['name'], $_POST['amount'], $_POST['type'] ?? 'fixed', $_POST['status'] ?? 'Active', $_POST['id'], $school['id'], $campusId]);
                $message = 'Discount updated';
                break;

            case 'delete_fees_discount':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_discounts WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['id'], $school['id'], $campusId]);
                $message = 'Discount deleted';
                break;

            case 'create_class_fee':
                $feeName = trim((string)($_POST['fee_name'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $amount = (float)($_POST['amount'] ?? 0);
                $yearId = (int)($_POST['academic_year_id'] ?? 0);
                $termId = (int)($_POST['academic_term_id'] ?? 0);
                $classId = (int)($_POST['class_id'] ?? 0);
                $dueDate = $_POST['due_date'] ?? null;
                $lateFee = (float)($_POST['late_fee'] ?? 0);

                if ($feeName === '') {
                    throw new Exception('Fee name is required');
                }
                if ($amount <= 0) {
                    throw new Exception('Fee amount must be greater than zero');
                }
                if ($yearId <= 0 || $termId <= 0 || $classId <= 0) {
                    throw new Exception('Select an academic year, term, and class before adding a fee');
                }

                $classWhere = 'id = ? AND school_id = ?';
                $classParams = [$classId, (int)$school['id']];
                if (academix_admin_has_column($schoolDb, 'classes', 'campus_id')) {
                    $classWhere .= ' AND campus_id = ?';
                    $classParams[] = $campusId;
                }
                $classCheck = $schoolDb->prepare("SELECT COUNT(*) FROM classes WHERE {$classWhere}");
                $classCheck->execute($classParams);
                if ((int)$classCheck->fetchColumn() === 0) {
                    throw new Exception('Selected class does not belong to this campus');
                }

                $schoolDb->beginTransaction();

                $typeLookup = $schoolDb->prepare("
                    SELECT id
                    FROM fee_types
                    WHERE school_id = ? AND campus_id = ? AND LOWER(name) = LOWER(?)
                    LIMIT 1
                ");
                $typeLookup->execute([(int)$school['id'], $campusId, $feeName]);
                $feeTypeId = (int)$typeLookup->fetchColumn();

                if ($feeTypeId <= 0) {
                    $stmt = $schoolDb->prepare("
                        INSERT INTO fee_types (school_id, campus_id, name, description, status, created_at)
                        VALUES (?, ?, ?, ?, 'Active', NOW())
                    ");
                    $stmt->execute([(int)$school['id'], $campusId, $feeName, $description]);
                    $feeTypeId = (int)$schoolDb->lastInsertId();
                } elseif ($description !== '') {
                    $stmt = $schoolDb->prepare("UPDATE fee_types SET description = ?, status = 'Active' WHERE id = ? AND school_id = ? AND campus_id = ?");
                    $stmt->execute([$description, $feeTypeId, (int)$school['id'], $campusId]);
                }

                $existing = $schoolDb->prepare("
                    SELECT id
                    FROM fee_structures
                    WHERE campus_id = ? AND class_id = ? AND fee_category_id = ?
                      AND academic_year_id = ? AND academic_term_id = ? AND school_id = ?
                    LIMIT 1
                ");
                $existing->execute([$campusId, $classId, $feeTypeId, $yearId, $termId, (int)$school['id']]);
                $structureId = (int)$existing->fetchColumn();

                if ($structureId > 0) {
                    $stmt = $schoolDb->prepare("
                        UPDATE fee_structures
                        SET amount = ?, due_date = ?, late_fee = ?, is_active = 1
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$amount, $dueDate ?: null, $lateFee, $structureId, (int)$school['id']]);
                    $message = 'Class fee updated successfully';
                } else {
                    $stmt = $schoolDb->prepare("
                        INSERT INTO fee_structures (
                            school_id, campus_id, academic_year_id, academic_term_id,
                            class_id, fee_category_id, amount, due_date, late_fee, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([(int)$school['id'], $campusId, $yearId, $termId, $classId, $feeTypeId, $amount, $dueDate ?: null, $lateFee]);
                    $message = 'Class fee created successfully';
                }

                $schoolDb->commit();
                break;

            case 'save_fee_structure':
                if (empty($_POST['class_id']) || empty($_POST['fee_type_id']) || !isset($_POST['amount'])) {
                    throw new Exception('Class, fee type, and amount are required');
                }
                $yearId = (int)($_POST['academic_year_id'] ?? 0);
                $termId = (int)($_POST['academic_term_id'] ?? 0);
                if ($yearId <= 0 || $termId <= 0) {
                    throw new Exception('Academic year and term are required before saving class fees');
                }
                $dueDate = $_POST['due_date'] ?? null;
                $lateFee = (float)($_POST['late_fee'] ?? 0);
                $existing = $schoolDb->prepare("SELECT id FROM fee_structures WHERE campus_id=? AND class_id=? AND fee_category_id=? AND academic_year_id=? AND academic_term_id=? AND school_id=?");
                $existing->execute([$campusId, (int)$_POST['class_id'], (int)$_POST['fee_type_id'], $yearId, $termId, $school['id']]);
                $row = $existing->fetchColumn();
                if ($row) {
                    $stmt = $schoolDb->prepare("UPDATE fee_structures SET amount=?, due_date=?, late_fee=?, academic_term_id=? WHERE id=? AND school_id=?");
                    $stmt->execute([$_POST['amount'], $dueDate ?: null, $lateFee, $termId, $row, $school['id']]);
                    $message = 'Fee structure updated';
                } else {
                    $stmt = $schoolDb->prepare("INSERT INTO fee_structures (school_id, campus_id, academic_year_id, academic_term_id, class_id, fee_category_id, amount, due_date, late_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$school['id'], $campusId, $yearId, $termId, (int)$_POST['class_id'], (int)$_POST['fee_type_id'], $_POST['amount'], $dueDate ?: null, $lateFee]);
                    $message = 'Fee structure created';
                }
                break;

            case 'delete_fee_structure':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM fee_structures WHERE id=? AND school_id=? AND campus_id=?");
                $stmt->execute([$_POST['id'], $school['id'], $campusId]);
                $message = 'Fee structure deleted';
                break;

            case 'collect_fee':
                if (empty($_POST['student_id']) || empty($_POST['amount'])) throw new Exception('Student and amount required');
                $studentWhere = "id=? AND school_id=?";
                $studentParams = [(int)$_POST['student_id'], (int)$school['id']];
                if (academix_admin_has_column($schoolDb, 'students', 'campus_id')) {
                    $studentWhere .= " AND campus_id=?";
                    $studentParams[] = $campusId;
                }
                $studentCheck = $schoolDb->prepare("SELECT COUNT(*) FROM students WHERE {$studentWhere}");
                $studentCheck->execute($studentParams);
                if ((int)$studentCheck->fetchColumn() === 0) {
                    throw new Exception('Selected student does not belong to this campus');
                }
                $reference = $_POST['reference'] ?? 'MANUAL-' . time();
                $discountId = !empty($_POST['discount_id']) ? (int)$_POST['discount_id'] : null;
                $discountAmount = (float)($_POST['discount_amount'] ?? 0);
                $stmt = $schoolDb->prepare("INSERT INTO fee_payments (school_id, campus_id, student_id, fee_type_id, amount, discount_id, discount_amount, payment_method, reference, notes, paid_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$school['id'], $campusId, (int)$_POST['student_id'], $_POST['fee_type_id'] ?? null, $_POST['amount'], $discountId, $discountAmount, $_POST['payment_method'] ?? 'cash', $reference, $_POST['notes'] ?? null]);
                $paymentId = (int)$schoolDb->lastInsertId();
                academix_send_fee_whatsapp_notice($schoolDb, $school, (int)$_POST['student_id'], (float)$_POST['amount'], $reference, $paymentId);
                $message = 'Fee collected successfully';
                break;
        }
        if ($message) setToast('success', $message);
    } catch (Exception $e) {
        if ($schoolDb->inTransaction()) {
            $schoolDb->rollBack();
        }
        error_log("Fees handler error: " . $e->getMessage());
        setToast('error', $e->getMessage());
    }
    $redirectTo = academix_admin_safe_redirect_target($_POST['return_to'] ?? null);
    header('Location: ' . $redirectTo);
    exit;
}
