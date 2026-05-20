<?php
/**
 * Accounts module CRUD handler (income/expense)
 */
require_once __DIR__ . '/../admin-bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schoolDb) {
    $action = $_POST['action'] ?? '';
    if (!academix_admin_validate_csrf($_POST['csrf_token'] ?? null)) {
        throw new Exception('Security validation failed. Please refresh and try again.');
    }
    try {
        switch ($action) {
            case 'create_income_head':
                if (empty($_POST['name'])) throw new Exception('Income head name required');
                $stmt = $schoolDb->prepare("INSERT INTO income_heads (school_id, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Income head created');
                break;
            case 'update_income_head':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE income_heads SET name=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Income head updated');
                break;
            case 'delete_income_head':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM income_heads WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Income head deleted');
                break;
            case 'create_income':
                if (empty($_POST['head_id']) || empty($_POST['amount'])) throw new Exception('Head and amount required');
                $stmt = $schoolDb->prepare("INSERT INTO incomes (school_id, income_head_id, amount, description, date, payment_method, reference, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['head_id'], $_POST['amount'], $_POST['description'] ?? '', $_POST['date'] ?? date('Y-m-d'), $_POST['payment_method'] ?? 'cash', $_POST['reference'] ?? 'INC-' . time()]);
                setToast('success', 'Income recorded');
                break;
            case 'delete_income':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM incomes WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Income deleted');
                break;
            case 'create_expense_head':
                if (empty($_POST['name'])) throw new Exception('Expense head name required');
                $stmt = $schoolDb->prepare("INSERT INTO expense_heads (school_id, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Expense head created');
                break;
            case 'update_expense_head':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE expense_heads SET name=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Expense head updated');
                break;
            case 'delete_expense_head':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM expense_heads WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Expense head deleted');
                break;
            case 'create_expense':
                if (empty($_POST['head_id']) || empty($_POST['amount'])) throw new Exception('Head and amount required');
                $stmt = $schoolDb->prepare("INSERT INTO expenses (school_id, expense_head_id, amount, description, date, payment_method, reference, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['head_id'], $_POST['amount'], $_POST['description'] ?? '', $_POST['date'] ?? date('Y-m-d'), $_POST['payment_method'] ?? 'cash', $_POST['reference'] ?? 'EXP-' . time()]);
                setToast('success', 'Expense recorded');
                break;
            case 'delete_expense':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM expenses WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Expense deleted');
                break;
        }
    } catch (Exception $e) {
        error_log("Accounts handler error: " . $e->getMessage());
        setToast('error', $e->getMessage());
    }
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? ''));
    exit;
}
