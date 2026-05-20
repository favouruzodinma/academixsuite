<?php
/**
 * HRM module CRUD handler
 */
require_once __DIR__ . '/../admin-bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schoolDb) {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create_department':
                if (empty($_POST['name'])) throw new Exception('Department name required');
                $stmt = $schoolDb->prepare("INSERT INTO departments (school_id, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Department created');
                break;
            case 'update_department':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE departments SET name=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Department updated');
                break;
            case 'delete_department':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM departments WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Department deleted');
                break;
            case 'create_designation':
                if (empty($_POST['name'])) throw new Exception('Designation name required');
                $stmt = $schoolDb->prepare("INSERT INTO designations (school_id, name, department_id, description, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['department_id'] ?? null, $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Designation created');
                break;
            case 'update_designation':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE designations SET name=?, department_id=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['department_id'] ?? null, $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Designation updated');
                break;
            case 'delete_designation':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM designations WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Designation deleted');
                break;
            case 'create_employee':
                if (empty($_POST['name']) || empty($_POST['email'])) throw new Exception('Name and email required');

                $empName   = trim($_POST['name']);
                $empEmail  = trim($_POST['email']);
                $empPhone  = $_POST['phone'] ?? '';

                // ── 1. Create / reuse portal login account ────────────────────
                $portalUserId = null;
                $plainPassword = null;

                // Check whether a users account already exists for this email
                $existsStmt = $schoolDb->prepare(
                    "SELECT id FROM users WHERE school_id = ? AND email = ? LIMIT 1"
                );
                $existsStmt->execute([$school['id'], $empEmail]);
                $existingUser = $existsStmt->fetchColumn();

                if (!$existingUser) {
                    // Generate credentials
                    $plainPassword  = substr(str_shuffle('abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);
                    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
                    $username       = strtolower(preg_replace('/\s+/', '.', $empName))
                                    . rand(10, 99);

                    // Build insert defensively (only columns that exist)
                    $userCols = $schoolDb->query("SHOW COLUMNS FROM users")
                                         ->fetchAll(PDO::FETCH_COLUMN);
                    $uData = array_intersect_key([
                        'school_id'   => $school['id'],
                        'name'        => $empName,
                        'email'       => $empEmail,
                        'phone'       => $empPhone,
                        'username'    => $username,
                        'password'    => $hashedPassword,
                        'user_type'   => 'staff',
                        'is_active'   => 1,
                        'created_at'  => date('Y-m-d H:i:s'),
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ], array_flip($userCols));

                    $uCols = '`' . implode('`, `', array_keys($uData)) . '`';
                    $uPh   = implode(', ', array_fill(0, count($uData), '?'));
                    $uStmt = $schoolDb->prepare("INSERT INTO users ($uCols) VALUES ($uPh)");
                    $uStmt->execute(array_values($uData));
                    $portalUserId = (int) $schoolDb->lastInsertId();
                } else {
                    $portalUserId = (int) $existingUser;
                }

                // ── 2. Insert HR record ────────────────────────────────────────
                $empCols = $schoolDb->query("SHOW COLUMNS FROM employees")
                                     ->fetchAll(PDO::FETCH_COLUMN);
                $eData = array_intersect_key([
                    'school_id'       => $school['id'],
                    'user_id'         => $portalUserId,
                    'name'            => $empName,
                    'email'           => $empEmail,
                    'phone'           => $empPhone,
                    'department_id'   => $_POST['department_id']  ?? null,
                    'designation_id'  => $_POST['designation_id'] ?? null,
                    'salary'          => $_POST['salary']         ?? 0,
                    'employment_type' => $_POST['employment_type'] ?? 'full_time',
                    'joining_date'    => $_POST['joining_date']   ?? date('Y-m-d'),
                    'address'         => $_POST['address']        ?? '',
                    'status'          => $_POST['status']         ?? 'Active',
                    'created_at'      => date('Y-m-d H:i:s'),
                ], array_flip($empCols));

                $eCols = '`' . implode('`, `', array_keys($eData)) . '`';
                $ePh   = implode(', ', array_fill(0, count($eData), '?'));
                $eStmt = $schoolDb->prepare("INSERT INTO employees ($eCols) VALUES ($ePh)");
                $eStmt->execute(array_values($eData));

                // ── 3. Send branded welcome email ─────────────────────────────
                if ($plainPassword && $portalUserId && filter_var($empEmail, FILTER_VALIDATE_EMAIL)) {
                    $svcPath = ROOT_PATH . '/includes/Services/WelcomeEmailService.php';
                    if (file_exists($svcPath)) {
                        require_once $svcPath;
                        $svc = new WelcomeEmailService($school);
                        $svc->send('staff', [
                            'name'     => $empName,
                            'email'    => $empEmail,
                            'username' => $username,   // the one we inserted into users
                            'password' => $plainPassword,
                        ]);
                    }
                }

                setToast('success', 'Employee added and welcome email sent');
                break;
            case 'update_employee':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE employees SET name=?, email=?, phone=?, department_id=?, designation_id=?, salary=?, employment_type=?, joining_date=?, address=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['email'], $_POST['phone'] ?? '', $_POST['department_id'] ?? null, $_POST['designation_id'] ?? null, $_POST['salary'] ?? 0, $_POST['employment_type'] ?? 'full_time', $_POST['joining_date'] ?? date('Y-m-d'), $_POST['address'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Employee updated');
                break;
            case 'delete_employee':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM employees WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Employee deleted');
                break;
            case 'mark_employee_attendance':
                if (empty($_POST['employee_id']) || empty($_POST['date']) || empty($_POST['status'])) throw new Exception('Employee, date, and status required');
                $stmt = $schoolDb->prepare("INSERT INTO employee_attendance (school_id, employee_id, date, status, remark, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status), remark=VALUES(remark)");
                $stmt->execute([$school['id'], $_POST['employee_id'], $_POST['date'], $_POST['status'], $_POST['remark'] ?? '']);
                setToast('success', 'Attendance marked');
                break;
            case 'create_salary_grade':
                if (empty($_POST['name']) || empty($_POST['basic_salary'])) throw new Exception('Name and basic salary required');
                $stmt = $schoolDb->prepare("INSERT INTO salary_grades (school_id, name, basic_salary, allowances, deductions, overtime_rate, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$school['id'], $_POST['name'], $_POST['basic_salary'], $_POST['allowances'] ?? 0, $_POST['deductions'] ?? 0, $_POST['overtime_rate'] ?? 0, $_POST['description'] ?? '', $_POST['status'] ?? 'Active']);
                setToast('success', 'Salary grade created');
                break;
            case 'update_salary_grade':
                if (empty($_POST['id']) || empty($_POST['name'])) throw new Exception('ID and name required');
                $stmt = $schoolDb->prepare("UPDATE salary_grades SET name=?, basic_salary=?, allowances=?, deductions=?, overtime_rate=?, description=?, status=? WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['name'], $_POST['basic_salary'], $_POST['allowances'] ?? 0, $_POST['deductions'] ?? 0, $_POST['overtime_rate'] ?? 0, $_POST['description'] ?? '', $_POST['status'] ?? 'Active', $_POST['id'], $school['id']]);
                setToast('success', 'Salary grade updated');
                break;
            case 'delete_salary_grade':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM salary_grades WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Salary grade deleted');
                break;
            case 'create_payroll':
                if (empty($_POST['employee_id']) || empty($_POST['amount'])) throw new Exception('Employee and amount required');
                $stmt = $schoolDb->prepare("INSERT INTO payroll (school_id, employee_id, salary_grade_id, amount, allowances, deductions, net_amount, period_start, period_end, payment_date, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $netAmount = ($_POST['amount'] + ($_POST['allowances'] ?? 0)) - ($_POST['deductions'] ?? 0);
                $stmt->execute([$school['id'], $_POST['employee_id'], $_POST['salary_grade_id'] ?? null, $_POST['amount'], $_POST['allowances'] ?? 0, $_POST['deductions'] ?? 0, $netAmount, $_POST['period_start'] ?? date('Y-m-01'), $_POST['period_end'] ?? date('Y-m-t'), $_POST['payment_date'] ?? date('Y-m-d'), $_POST['status'] ?? 'pending', $_POST['notes'] ?? '']);
                setToast('success', 'Payroll record created');
                break;
            case 'delete_payroll':
                if (empty($_POST['id'])) throw new Exception('ID required');
                $stmt = $schoolDb->prepare("DELETE FROM payroll WHERE id=? AND school_id=?");
                $stmt->execute([$_POST['id'], $school['id']]);
                setToast('success', 'Payroll record deleted');
                break;
        }
    } catch (Exception $e) {
        error_log("HRM handler error: " . $e->getMessage());
        setToast('error', $e->getMessage());
    }
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? ''));
    exit;
}
