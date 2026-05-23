<?php
/**
 * Handler for remaining admin pages
 */
require_once __DIR__ . '/../admin-bootstrap.php';
require_once __DIR__ . '/../campus-context.php';

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
            case 'create_exam_schedule':
                if (empty($_POST['exam_id']) || empty($_POST['subject_id']) || empty($_POST['exam_date'])) throw new Exception('Exam, subject, and date required');
                $stmt = $schoolDb->prepare("INSERT INTO exam_schedules (school_id, exam_id, class_id, section_id, subject_id, exam_date, start_time, end_time, room, duration, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['exam_id'], $_POST['class_id'] ?? null, $_POST['section_id'] ?? null, $_POST['subject_id'], $_POST['exam_date'], $_POST['start_time'] ?? null, $_POST['end_time'] ?? null, $_POST['room'] ?? '', $_POST['duration'] ?? null]);
                setToast('success', 'Exam schedule created');
                break;
            case 'update_exam_schedule':
                if (empty($_POST['id']) || empty($_POST['exam_date'])) throw new Exception('ID and date required');
                $stmt = $schoolDb->prepare("UPDATE exam_schedules SET exam_id=?, class_id=?, section_id=?, subject_id=?, exam_date=?, start_time=?, end_time=?, room=?, duration=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['exam_id'] ?? null, $_POST['class_id'] ?? null, $_POST['section_id'] ?? null, $_POST['subject_id'] ?? null, $_POST['exam_date'], $_POST['start_time'] ?? null, $_POST['end_time'] ?? null, $_POST['room'] ?? '', $_POST['duration'] ?? null, $_POST['id'], $school['id']]);
                setToast('success', 'Exam schedule updated');
                break;
            case 'delete_exam_schedule':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM exam_schedules WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Exam schedule deleted');
                break;
            case 'create_student_category':
                if (empty($_POST['name'])) throw new Exception('Category name required');
                $stmt = $schoolDb->prepare("INSERT INTO student_categories (school_id, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Category created');
                break;
            case 'update_student_category':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE student_categories SET name=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Category updated');
                break;
            case 'delete_student_category':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM student_categories WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Category deleted');
                break;
            case 'create_class_room':
                if (empty($_POST['name'])) throw new Exception('Room name required');
                $stmt = $schoolDb->prepare("INSERT INTO class_rooms (school_id, name, capacity, room_number, building, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['capacity'] ?? 30, $_POST['room_number'] ?? '', $_POST['building'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Class room created');
                break;
            case 'update_class_room':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE class_rooms SET name=?, capacity=?, room_number=?, building=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['capacity'] ?? 30, $_POST['room_number'] ?? '', $_POST['building'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Class room updated');
                break;
            case 'delete_class_room':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM class_rooms WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Class room deleted');
                break;
            case 'create_certificate':
                if (empty($_POST['name'])) throw new Exception('Certificate name required');
                $stmt = $schoolDb->prepare("INSERT INTO certificates (school_id, name, template, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['template'] ?? 'default', $_POST['status'] ?? 'Active']);
                setToast('success', 'Certificate created');
                break;
            case 'update_certificate':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE certificates SET name=?, template=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['template'] ?? 'default', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Certificate updated');
                break;
            case 'delete_certificate':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM certificates WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Certificate deleted');
                break;
            case 'create_language':
                if (empty($_POST['name'])) throw new Exception('Language name required');
                $stmt = $schoolDb->prepare("INSERT INTO languages (school_id, name, code, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['code'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Language added');
                break;
            case 'delete_language':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM languages WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Language deleted');
                break;
            case 'create_currency':
                if (empty($_POST['name']) || empty($_POST['code'])) throw new Exception('Name and code required');
                $stmt = $schoolDb->prepare("INSERT INTO currencies (school_id, name, code, symbol, exchange_rate, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['code'], $_POST['symbol'] ?? '', $_POST['exchange_rate'] ?? 1, $_POST['status'] ?? 'Active']);
                setToast('success', 'Currency added');
                break;
            case 'delete_currency':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM currencies WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Currency deleted');
                break;
            case 'create_transaction':
                if (empty($_POST['type']) || empty($_POST['amount'])) throw new Exception('Type and amount required');
                academix_admin_ensure_transactions_table($schoolDb);
                $campusId = academix_admin_resolve_campus_id($schoolDb, $school, false);
                $type = strtolower((string) $_POST['type']);
                $type = in_array($type, ['income', 'expense'], true) ? $type : 'income';
                $reference = trim((string) ($_POST['reference'] ?? ''));
                $reference = $reference !== '' ? $reference : 'TXN-' . date('YmdHis');
                $columns = academix_admin_fresh_columns($schoolDb, 'transactions');

                if (in_array('campus_id', $columns, true)) {
                    $stmt = $schoolDb->prepare("INSERT INTO transactions (school_id, campus_id, type, amount, description, category, payment_method, reference, date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$school['id'], $campusId, $type, $_POST['amount'], $_POST['description'] ?? '', $_POST['category'] ?? '', $_POST['payment_method'] ?? 'cash', $reference, $_POST['date'] ?? date('Y-m-d')]);
                } else {
                    $stmt = $schoolDb->prepare("INSERT INTO transactions (school_id, type, amount, description, category, payment_method, reference, date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$school['id'], $type, $_POST['amount'], $_POST['description'] ?? '', $_POST['category'] ?? '', $_POST['payment_method'] ?? 'cash', $reference, $_POST['date'] ?? date('Y-m-d')]);
                }
                setToast('success', 'Transaction recorded');
                break;
            case 'delete_transaction':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM transactions WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Transaction deleted');
                break;
            case 'suspend_student':
                if (empty($_POST['student_id'])) throw new Exception('Student ID required');
                $stmt = $schoolDb->prepare("UPDATE students SET status='suspended', suspension_reason=?, suspended_at=NOW() WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['reason'] ?? '', $_POST['student_id'], $school['id']]);
                setToast('success', 'Student suspended');
                break;
            case 'unsuspend_student':
                if (empty($_POST['student_id'])) throw new Exception('Student ID required');
                $stmt = $schoolDb->prepare("UPDATE students SET status='active', suspension_reason=NULL, suspended_at=NULL WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['student_id'], $school['id']]);
                setToast('success', 'Student reinstated');
                break;
            case 'assign_role':
                if (empty($_POST['user_id']) || empty($_POST['role'])) throw new Exception('User and role required');
                $stmt = $schoolDb->prepare("UPDATE users SET user_type=?, role_name=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['role'], $_POST['role_name'] ?? $_POST['role'], $_POST['user_id'], $school['id']]);
                setToast('success', 'Role assigned');
                break;
        }
    } catch (Exception $e) {
        error_log("Other handler error: " . $e->getMessage());
        setToast('error', $e->getMessage());
    }
    $redirectTo = academix_admin_safe_redirect_target($_POST['return_to'] ?? null);
    header('Location: ' . $redirectTo);
    exit;
}
