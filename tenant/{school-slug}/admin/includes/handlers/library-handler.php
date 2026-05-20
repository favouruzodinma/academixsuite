<?php
/**
 * Library module CRUD handler
 */
require_once __DIR__ . '/../admin-bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schoolDb) {
    $action = $_POST['action'] ?? '';
    if (!academix_admin_validate_csrf($_POST['csrf_token'] ?? null)) {
        throw new Exception('Security validation failed. Please refresh and try again.');
    }
    try {
        switch ($action) {
            case 'create_book':
                if (empty($_POST['name'])) throw new Exception('Book name required');
                $stmt = $schoolDb->prepare("INSERT INTO library_books (school_id, name, author, publisher, isbn, quantity, available, rack_no, subject, price, post_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['author'] ?? '', $_POST['publisher'] ?? '', $_POST['isbn'] ?? '', $_POST['quantity'] ?? 1, $_POST['available'] ?? 1, $_POST['rack_no'] ?? '', $_POST['subject'] ?? '', $_POST['price'] ?? 0, $_POST['post_date'] ?? date('Y-m-d'), $_POST['status'] ?? 'Active']);
                setToast('success', 'Book added');
                break;
            case 'update_book':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE library_books SET name=?, author=?, publisher=?, isbn=?, quantity=?, available=?, rack_no=?, subject=?, price=?, post_date=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['author'] ?? '', $_POST['publisher'] ?? '', $_POST['isbn'] ?? '', $_POST['quantity'] ?? 1, $_POST['available'] ?? 1, $_POST['rack_no'] ?? '', $_POST['subject'] ?? '', $_POST['price'] ?? 0, $_POST['post_date'] ?? date('Y-m-d'), $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Book updated');
                break;
            case 'delete_book':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM library_books WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Book deleted');
                break;
            case 'create_member':
                if (empty($_POST['name'])) throw new Exception('Member name required');
                $stmt = $schoolDb->prepare("INSERT INTO library_members (school_id, name, member_type, email, phone, address, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['member_type'] ?? 'student', $_POST['email'] ?? '', $_POST['phone'] ?? '', $_POST['address'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Member added');
                break;
            case 'update_member':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE library_members SET name=?, member_type=?, email=?, phone=?, address=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['member_type'] ?? 'student', $_POST['email'] ?? '', $_POST['phone'] ?? '', $_POST['address'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Member updated');
                break;
            case 'delete_member':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM library_members WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Member deleted');
                break;
            case 'issue_book':
                if (empty($_POST['book_id']) || empty($_POST['member_id'])) throw new Exception('Book and member required');
                $stmt = $schoolDb->prepare("INSERT INTO library_issues (school_id, book_id, member_id, issue_date, due_date, status, created_at) VALUES (?, ?, ?, ?, ?, 'issued', NOW())");
                $stmt->execute([$school['id'], $_POST['book_id'], $_POST['member_id'], $_POST['issue_date'] ?? date('Y-m-d'), $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'))]);
                $stmt2 = $schoolDb->prepare("UPDATE library_books SET available = available - 1 WHERE id=? AND school_id=? AND available > 0");
                $stmt2->execute([$_POST['book_id'], $school['id']]);
                setToast('success', 'Book issued');
                break;
            case 'return_book':
                if (empty($_POST['id'])) throw new Exception('Issue ID required');
                $stmt = $schoolDb->prepare("UPDATE library_issues SET return_date=NOW(), status='returned' WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                $issueStmt = $schoolDb->prepare("SELECT book_id FROM library_issues WHERE id=? AND school_id=?");
                $issueStmt->execute([$_POST['id'], $school['id']]);
                $bookId = $issueStmt->fetchColumn();
                if ($bookId) {
                    $schoolDb->prepare("UPDATE library_books SET available = available + 1 WHERE id=? AND school_id=?")->execute([$bookId, $school['id']]);
                }
                setToast('success', 'Book returned');
                break;
        }
    } catch (Exception $e) {
        error_log("Library handler error: " . $e->getMessage());
        setToast('error', $e->getMessage());
    }
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? ''));
    exit;
}
