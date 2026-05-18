<?php
/**
 * Payroll Manager
 * Handles all payroll-related database operations
 * 
 * @package AcademixSuite
 * @version 1.2
 */

class PayrollManager {
    private $db;
    private $schoolId;

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $schoolId School ID
     */
    public function __construct($db, $schoolId) {
        $this->db = $db;
        $this->schoolId = $schoolId;
    }

    /**
     * Get all employees with their payroll details
     * @return array List of employees
     */
   public function getEmployees() {
    $sql = "SELECT 
                pe.id,
                pe.employee_number,
                u.name,
                u.email,
                u.phone,
                u.profile_photo,
                u.user_type,
                pe.department,
                pe.designation,
                pe.bank_name,
                pe.bank_account,
                pe.salary_grade_id,
                psg.grade_name,
                psg.basic_salary,
                psg.house_allowance,
                psg.transport_allowance,
                psg.medical_allowance,
                psg.other_allowances,
                pe.is_active,
                (SELECT GROUP_CONCAT(s.name SEPARATOR ', ')
                 FROM class_subjects cs
                 JOIN subjects s ON cs.subject_id = s.id
                 WHERE cs.teacher_id = u.id) as teacher_subjects,
                (SELECT ps.net_salary 
                 FROM payroll_slips ps 
                 WHERE ps.employee_id = pe.id 
                 ORDER BY ps.created_at DESC 
                 LIMIT 1) as last_net_salary,
                (SELECT ps.payment_status 
                 FROM payroll_slips ps 
                 WHERE ps.employee_id = pe.id 
                 ORDER BY ps.created_at DESC 
                 LIMIT 1) as last_payment_status,
                (SELECT ps.payment_method 
                 FROM payroll_slips ps 
                 WHERE ps.employee_id = pe.id 
                 ORDER BY ps.created_at DESC 
                 LIMIT 1) as last_payment_method
            FROM payroll_employees pe
            INNER JOIN users u ON pe.user_id = u.id
            LEFT JOIN payroll_salary_grades psg ON pe.salary_grade_id = psg.id
            WHERE pe.school_id = :school_id
            ORDER BY pe.id DESC";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([':school_id' => $this->schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

    /**
     * Get a single employee by ID
     * @param int $employeeId
     * @return array|false
     */
    public function getEmployee($employeeId) {
        $sql = "SELECT 
                    pe.*,
                    u.name,
                    u.email,
                    u.phone,
                    u.profile_photo,
                    psg.grade_name,
                    psg.basic_salary as grade_basic_salary,
                    psg.house_allowance as grade_house_allowance,
                    psg.transport_allowance as grade_transport_allowance,
                    psg.medical_allowance as grade_medical_allowance,
                    psg.other_allowances as grade_other_allowances
                FROM payroll_employees pe
                INNER JOIN users u ON pe.user_id = u.id
                LEFT JOIN payroll_salary_grades psg ON pe.salary_grade_id = psg.id
                WHERE pe.id = :id AND pe.school_id = :school_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $employeeId, ':school_id' => $this->schoolId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get payroll periods
     * @param string|null $status Filter by status
     * @return array
     */
    public function getPayrollPeriods($status = null) {
        try {
            $sql = "SELECT * FROM payroll_periods 
                    WHERE school_id = ?";
            $params = [$this->schoolId];
            if ($status) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY start_date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting payroll periods: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get payslip for an employee in a specific period
     * @param int $employeeId
     * @param int $periodId
     * @return array|false
     */
    public function getPayslip($employeeId, $periodId) {
        $sql = "SELECT 
                    ps.*,
                    pp.name as period_name,
                    pp.start_date,
                    pp.end_date,
                    pe.employee_number,
                    u.name as employee_name,
                    u.email,
                    u.phone,
                    pe.department,
                    pe.designation,
                    pe.bank_name,
                    pe.bank_account,
                    psg.grade_name,
                    pe.basic_salary as employee_basic_salary,
                    (SELECT SUM(amount) FROM payroll_allowances 
                     WHERE employee_id = pe.id 
                       AND (effective_from <= pp.end_date) 
                       AND (effective_to IS NULL OR effective_to >= pp.start_date)) as total_allowances,
                    (SELECT SUM(amount) FROM payroll_deductions 
                     WHERE employee_id = pe.id 
                       AND (effective_from <= pp.end_date) 
                       AND (effective_to IS NULL OR effective_to >= pp.start_date)) as total_deductions
                FROM payroll_slips ps
                INNER JOIN payroll_employees pe ON ps.employee_id = pe.id
                INNER JOIN users u ON pe.user_id = u.id
                INNER JOIN payroll_periods pp ON ps.payroll_run_id IN 
                    (SELECT id FROM payroll_runs WHERE period_id = pp.id)
                LEFT JOIN payroll_salary_grades psg ON pe.salary_grade_id = psg.id
                WHERE ps.employee_id = :employee_id 
                  AND pp.id = :period_id
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':period_id' => $periodId
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get latest payslip for an employee
     * @param int $employeeId
     * @return array|false
     */
    public function getLatestPayslip($employeeId) {
        $sql = "SELECT 
                    ps.*,
                    pp.name as period_name,
                    pp.start_date,
                    pp.end_date
                FROM payroll_slips ps
                INNER JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
                INNER JOIN payroll_periods pp ON pr.period_id = pp.id
                WHERE ps.employee_id = :employee_id
                ORDER BY ps.created_at DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update payment status for a payslip (admin action)
     * @param int $payslipId
     * @param string $status
     * @param int $userId
     * @return array [success, message]
     */
    public function updatePaymentStatus($payslipId, $status, $userId) {
        try {
            $allowed = ['pending', 'paid', 'failed'];
            if (!in_array($status, $allowed)) {
                return ['success' => false, 'message' => 'Invalid status'];
            }

            $sql = "UPDATE payroll_slips 
                    SET payment_status = :status,
                        payment_date = CASE WHEN :status = 'paid' THEN CURDATE() ELSE NULL END
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':status' => $status, ':id' => $payslipId]);

            $this->logAudit($userId, 'update', 'payroll_slips', $payslipId, null, ['payment_status' => $status]);

            return ['success' => true, 'message' => 'Payment status updated'];
        } catch (Exception $e) {
            error_log("PayrollManager::updatePaymentStatus error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error'];
        }
    }

    /**
     * Create a new payroll run (process payroll for a period)
     * @param int $periodId
     * @param int $userId
     * @return array
     */
    public function processPayroll($periodId, $userId) {
        try {
            $period = $this->getPeriod($periodId);
            if (!$period) {
                return ['success' => false, 'message' => 'Period not found'];
            }
            if ($period['status'] !== 'open') {
                return ['success' => false, 'message' => 'Period is not open'];
            }

            $existing = $this->db->prepare("SELECT id FROM payroll_runs WHERE period_id = ?");
            $existing->execute([$periodId]);
            if ($existing->fetch()) {
                return ['success' => false, 'message' => 'Payroll already processed for this period'];
            }

            $this->db->beginTransaction();

            $runSql = "INSERT INTO payroll_runs (school_id, period_id, processed_by, status, created_at) 
                       VALUES (?, ?, ?, 'draft', NOW())";
            $runStmt = $this->db->prepare($runSql);
            $runStmt->execute([$this->schoolId, $periodId, $userId]);
            $runId = $this->db->lastInsertId();

            $employees = $this->db->prepare("
                SELECT pe.*, psg.basic_salary as grade_basic_salary,
                       psg.house_allowance, psg.transport_allowance,
                       psg.medical_allowance, psg.other_allowances
                FROM payroll_employees pe
                LEFT JOIN payroll_salary_grades psg ON pe.salary_grade_id = psg.id
                WHERE pe.school_id = ? AND pe.is_active = 1
            ");
            $employees->execute([$this->schoolId]);
            $empList = $employees->fetchAll(PDO::FETCH_ASSOC);

            $slipSql = "INSERT INTO payroll_slips 
                        (school_id, payroll_run_id, employee_id, gross_salary, 
                         total_allowances, total_deductions, net_salary, payment_status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            $slipStmt = $this->db->prepare($slipSql);

            foreach ($empList as $emp) {
                $basic = $emp['basic_salary'] ?? $emp['grade_basic_salary'] ?? 0;
                $allowances = ($emp['house_allowance'] ?? 0) + ($emp['transport_allowance'] ?? 0) +
                              ($emp['medical_allowance'] ?? 0) + ($emp['other_allowances'] ?? 0);
                // In a real system, you would sum individual allowances/deductions here
                $deductions = 0; 
                $net = $basic + $allowances - $deductions;

                $slipStmt->execute([
                    $this->schoolId,
                    $runId,
                    $emp['id'],
                    $basic,
                    $allowances,
                    $deductions,
                    $net
                ]);
            }

            $this->db->prepare("UPDATE payroll_periods SET status = 'processing' WHERE id = ?")
                     ->execute([$periodId]);

            $this->db->commit();
            return ['success' => true, 'message' => 'Payroll processed successfully', 'run_id' => $runId];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("PayrollManager::processPayroll error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Processing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get users eligible to be added to payroll (teachers, accountants, etc.)
     * Excludes users already in payroll_employees, and users of type admin, student, parent.
     * @return array
     */
    public function getEligiblePayrollUsers() {
        try {
            $sql = "SELECT u.id, u.name, u.email, u.user_type, u.profile_photo
                    FROM users u
                    LEFT JOIN payroll_employees pe ON u.id = pe.user_id
                    WHERE u.school_id = :school_id
                      AND u.user_type NOT IN ('admin', 'student', 'parent')
                      AND pe.id IS NULL
                    ORDER BY u.name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':school_id' => $this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting eligible payroll users: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add selected users to payroll_employees
     * @param array $userIds Array of user IDs
     * @return array [success, message, added_count]
     */
    public function addUsersToPayroll($userIds) {
        try {
            $this->db->beginTransaction();

            $added = 0;
            $insertStmt = $this->db->prepare("
                INSERT INTO payroll_employees (
                    school_id, user_id, employee_number, department, designation,
                    joining_date, is_active, created_at, updated_at
                ) VALUES (
                    :school_id, :user_id, :employee_number, NULL, NULL,
                    CURDATE(), 1, NOW(), NOW()
                )
            ");

            foreach ($userIds as $uid) {
                $empNum = $this->generateEmployeeNumber();
                try {
                    $insertStmt->execute([
                        ':school_id' => $this->schoolId,
                        ':user_id' => $uid,
                        ':employee_number' => $empNum
                    ]);
                    $added++;
                } catch (Exception $e) {
                    error_log("Failed to add user ID $uid to payroll: " . $e->getMessage());
                }
            }

            $this->db->commit();
            return [true, "Added $added user(s) to payroll.", $added];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error adding users to payroll: " . $e->getMessage());
            return [false, "Error adding users to payroll: " . $e->getMessage(), 0];
        }
    }

    /**
     * Generate a unique employee number (e.g., EMP-YYYY-XXXX)
     * @return string
     */
    private function generateEmployeeNumber() {
        $prefix = 'EMP-' . date('Y') . '-';
        $maxAttempts = 100;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $num = rand(1, 9999);
            $empNum = $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
            $check = $this->db->prepare("SELECT id FROM payroll_employees WHERE employee_number = ? AND school_id = ?");
            $check->execute([$empNum, $this->schoolId]);
            if (!$check->fetch()) {
                return $empNum;
            }
            $attempt++;
        }
        return $prefix . date('His') . rand(10, 99);
    }

    /**
     * Get a single period by ID
     * @param int $periodId
     * @return array|false
     */
    private function getPeriod($periodId) {
        $stmt = $this->db->prepare("SELECT * FROM payroll_periods WHERE id = ? AND school_id = ?");
        $stmt->execute([$periodId, $this->schoolId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Remove an employee from payroll (soft delete or permanent)
     * @param int $employeeId
     * @param bool $permanent If true, delete record; if false, just deactivate
     * @return array [success, message]
     */
    public function removeFromPayroll($employeeId, $permanent = false) {
        try {
            $this->db->beginTransaction();

            if ($permanent) {
                $stmt = $this->db->prepare("DELETE FROM payroll_employees WHERE id = ? AND school_id = ?");
                $stmt->execute([$employeeId, $this->schoolId]);
                $message = "Employee permanently removed from payroll.";
            } else {
                $stmt = $this->db->prepare("UPDATE payroll_employees SET is_active = 0 WHERE id = ? AND school_id = ?");
                $stmt->execute([$employeeId, $this->schoolId]);
                $message = "Employee deactivated (removed from active payroll).";
            }

            $this->db->commit();
            return [true, $message];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error removing employee from payroll: " . $e->getMessage());
            return [false, "Error removing employee: " . $e->getMessage()];
        }
    }
    /**
 * Get comma‑separated list of subjects taught by a teacher.
 * @param int $userId
 * @return string
 */
public function getTeacherSubjects($userId) {
    try {
        $sql = "SELECT GROUP_CONCAT(s.name SEPARATOR ', ') as subjects
                FROM class_subjects cs
                JOIN subjects s ON cs.subject_id = s.id
                WHERE cs.teacher_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['subjects'] ?? '';
    } catch (Exception $e) {
        error_log("Error getting teacher subjects: " . $e->getMessage());
        return '';
    }
}

    /**
     * Get full details of an employee for editing
     * @param int $employeeId
     * @return array|false
     */
    public function getEmployeeDetails($employeeId) {
    try {
        $sql = "SELECT pe.*, u.name, u.email, u.phone, u.profile_photo, u.user_type,
                       t.bank_name as teacher_bank_name,
                       t.bank_account as teacher_bank_account,
                       t.ifsc_code as teacher_ifsc_code
                FROM payroll_employees pe
                JOIN users u ON pe.user_id = u.id
                LEFT JOIN teachers t ON u.id = t.user_id
                WHERE pe.id = ? AND pe.school_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$employeeId, $this->schoolId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // If employee is a teacher and payroll bank fields are empty, fill from teacher table
        if ($data && $data['user_type'] == 'teacher') {
            if (empty($data['bank_name']) && !empty($data['teacher_bank_name'])) {
                $data['bank_name'] = $data['teacher_bank_name'];
            }
            if (empty($data['bank_account']) && !empty($data['teacher_bank_account'])) {
                $data['bank_account'] = $data['teacher_bank_account'];
            }
            if (empty($data['ifsc_code']) && !empty($data['teacher_ifsc_code'])) {
                $data['ifsc_code'] = $data['teacher_ifsc_code'];
            }
        }
        return $data;
    } catch (Exception $e) {
        error_log("Error fetching employee details: " . $e->getMessage());
        return false;
    }
}

    /**
     * Update employee payroll details
     * @param int $employeeId
     * @param array $data
     * @return array [success, message]
     */
    public function updateEmployeeDetails($employeeId, $data) {
        try {
            $sql = "UPDATE payroll_employees SET
                        employee_number = ?,
                        department = ?,
                        designation = ?,
                        bank_name = ?,
                        bank_account = ?,
                        ifsc_code = ?,
                        salary_grade_id = ?,
                        basic_salary = ?,
                        updated_at = NOW()
                    WHERE id = ? AND school_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['employee_number'] ?? '',
                $data['department'] ?? null,
                $data['designation'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['ifsc_code'] ?? null,
                !empty($data['salary_grade_id']) ? (int)$data['salary_grade_id'] : null,
                !empty($data['basic_salary']) ? (float)$data['basic_salary'] : null,
                $employeeId,
                $this->schoolId
            ]);

            return [true, "Employee details updated successfully."];
        } catch (Exception $e) {
            error_log("Error updating employee details: " . $e->getMessage());
            return [false, "Error updating details: " . $e->getMessage()];
        }
    }

    // ========== SALARY GRADES ==========
    /**
     * Get all salary grades
     * @return array
     */
    public function getSalaryGrades() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM payroll_salary_grades WHERE school_id = ? ORDER BY grade_name");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting salary grades: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single salary grade by ID
     * @param int $id
     * @return array|false
     */
    public function getSalaryGrade($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM payroll_salary_grades WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting salary grade: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a new salary grade
     * @param array $data
     * @return array [success, message]
     */
    public function addSalaryGrade($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO payroll_salary_grades 
                (school_id, grade_name, basic_salary, house_allowance, transport_allowance, medical_allowance, other_allowances, description, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['grade_name'],
                $data['basic_salary'] ?: 0,
                $data['house_allowance'] ?: 0,
                $data['transport_allowance'] ?: 0,
                $data['medical_allowance'] ?: 0,
                $data['other_allowances'] ?: 0,
                $data['description'] ?? null
            ]);
            return ['success' => true, 'message' => 'Salary grade added.'];
        } catch (Exception $e) {
            error_log("Error adding salary grade: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Update an existing salary grade
     * @param int $id
     * @param array $data
     * @return array [success, message]
     */
    public function updateSalaryGrade($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE payroll_salary_grades SET
                    grade_name = ?,
                    basic_salary = ?,
                    house_allowance = ?,
                    transport_allowance = ?,
                    medical_allowance = ?,
                    other_allowances = ?,
                    description = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ");
            $stmt->execute([
                $data['grade_name'],
                $data['basic_salary'] ?: 0,
                $data['house_allowance'] ?: 0,
                $data['transport_allowance'] ?: 0,
                $data['medical_allowance'] ?: 0,
                $data['other_allowances'] ?: 0,
                $data['description'] ?? null,
                isset($data['is_active']) ? 1 : 0,
                $id,
                $this->schoolId
            ]);
            return ['success' => true, 'message' => 'Salary grade updated.'];
        } catch (Exception $e) {
            error_log("Error updating salary grade: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Delete a salary grade (only if not used by any employee)
     * @param int $id
     * @return array [success, message]
     */
    public function deleteSalaryGrade($id) {
        try {
            $check = $this->db->prepare("SELECT id FROM payroll_employees WHERE salary_grade_id = ? AND school_id = ? LIMIT 1");
            $check->execute([$id, $this->schoolId]);
            if ($check->fetch()) {
                return ['success' => false, 'message' => 'Cannot delete – grade is assigned to employees.'];
            }
            $stmt = $this->db->prepare("DELETE FROM payroll_salary_grades WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            return ['success' => true, 'message' => 'Salary grade deleted.'];
        } catch (Exception $e) {
            error_log("Error deleting salary grade: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    // ========== PAYROLL PERIODS ==========
    /**
     * Get a single payroll period by ID
     * @param int $id
     * @return array|false
     */
    public function getPayrollPeriod($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM payroll_periods WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting payroll period: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a new payroll period
     * @param array $data
     * @return array [success, message]
     */
    public function addPayrollPeriod($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO payroll_periods (school_id, name, start_date, end_date, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'open', NOW(), NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['name'],
                $data['start_date'],
                $data['end_date']
            ]);
            return ['success' => true, 'message' => 'Payroll period added.'];
        } catch (Exception $e) {
            error_log("Error adding payroll period: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Update an existing payroll period
     * @param int $id
     * @param array $data
     * @return array [success, message]
     */
    public function updatePayrollPeriod($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE payroll_periods SET
                    name = ?,
                    start_date = ?,
                    end_date = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['status'],
                $id,
                $this->schoolId
            ]);
            return ['success' => true, 'message' => 'Payroll period updated.'];
        } catch (Exception $e) {
            error_log("Error updating payroll period: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Delete a payroll period (only if no payroll runs exist)
     * @param int $id
     * @return array [success, message]
     */
    public function deletePayrollPeriod($id) {
        try {
            $check = $this->db->prepare("SELECT id FROM payroll_runs WHERE period_id = ? LIMIT 1");
            $check->execute([$id]);
            if ($check->fetch()) {
                return ['success' => false, 'message' => 'Cannot delete – payroll runs already exist for this period.'];
            }
            $stmt = $this->db->prepare("DELETE FROM payroll_periods WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            return ['success' => true, 'message' => 'Payroll period deleted.'];
        } catch (Exception $e) {
            error_log("Error deleting payroll period: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    // ========== PAYROLL RUNS ==========
    /**
     * Get all payroll runs with summary info
     * @return array
     */
    public function getPayrollRuns() {
        try {
            $sql = "SELECT pr.*, pp.name as period_name,
                           (SELECT COUNT(*) FROM payroll_slips WHERE payroll_run_id = pr.id) as employee_count,
                           (SELECT SUM(net_salary) FROM payroll_slips WHERE payroll_run_id = pr.id) as total_net
                    FROM payroll_runs pr
                    JOIN payroll_periods pp ON pr.period_id = pp.id
                    WHERE pr.school_id = ?
                    ORDER BY pr.created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting payroll runs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get detailed information for a single payroll run (including slips)
     * @param int $runId
     * @return array|false
     */
    public function getPayrollRunDetails($runId) {
        try {
            $run = $this->db->prepare("
                SELECT pr.*, pp.name as period_name, pp.start_date, pp.end_date, u.name as processed_by_name
                FROM payroll_runs pr
                JOIN payroll_periods pp ON pr.period_id = pp.id
                LEFT JOIN users u ON pr.processed_by = u.id
                WHERE pr.id = ? AND pr.school_id = ?
            ");
            $run->execute([$runId, $this->schoolId]);
            $runData = $run->fetch(PDO::FETCH_ASSOC);
            if (!$runData) return false;

            $slips = $this->db->prepare("
                SELECT ps.*, pe.employee_number, u.name, u.email, u.profile_photo,
                       pe.department, pe.designation
                FROM payroll_slips ps
                JOIN payroll_employees pe ON ps.employee_id = pe.id
                JOIN users u ON pe.user_id = u.id
                WHERE ps.payroll_run_id = ?
                ORDER BY u.name
            ");
            $slips->execute([$runId]);
            $runData['slips'] = $slips->fetchAll(PDO::FETCH_ASSOC);
            return $runData;
        } catch (Exception $e) {
            error_log("Error getting payroll run details: " . $e->getMessage());
            return false;
        }
    }

    // ========== EMPLOYEE ALLOWANCES ==========
    /**
     * Get all allowances for an employee
     * @param int $employeeId
     * @return array
     */
    public function getEmployeeAllowances($employeeId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM payroll_allowances 
                WHERE employee_id = ? 
                ORDER BY effective_from DESC
            ");
            $stmt->execute([$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting allowances: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a new allowance for an employee
     * @param array $data
     * @return array [success, message]
     */
    public function addAllowance($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO payroll_allowances 
                (employee_id, allowance_type, amount, effective_from, effective_to, is_recurring, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['employee_id'],
                $data['allowance_type'],
                $data['amount'],
                $data['effective_from'],
                $data['effective_to'] ?: null,
                isset($data['is_recurring']) ? 1 : 0
            ]);
            return ['success' => true, 'message' => 'Allowance added.'];
        } catch (Exception $e) {
            error_log("Error adding allowance: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Update an existing allowance
     * @param int $id
     * @param array $data
     * @return array [success, message]
     */
    public function updateAllowance($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE payroll_allowances SET
                    allowance_type = ?,
                    amount = ?,
                    effective_from = ?,
                    effective_to = ?,
                    is_recurring = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['allowance_type'],
                $data['amount'],
                $data['effective_from'],
                $data['effective_to'] ?: null,
                isset($data['is_recurring']) ? 1 : 0,
                $id
            ]);
            return ['success' => true, 'message' => 'Allowance updated.'];
        } catch (Exception $e) {
            error_log("Error updating allowance: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Delete an allowance
     * @param int $id
     * @return array [success, message]
     */
    public function deleteAllowance($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM payroll_allowances WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true, 'message' => 'Allowance deleted.'];
        } catch (Exception $e) {
            error_log("Error deleting allowance: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    // ========== EMPLOYEE DEDUCTIONS ==========
    /**
     * Get all deductions for an employee
     * @param int $employeeId
     * @return array
     */
    public function getEmployeeDeductions($employeeId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM payroll_deductions 
                WHERE employee_id = ? 
                ORDER BY effective_from DESC
            ");
            $stmt->execute([$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting deductions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a new deduction for an employee
     * @param array $data
     * @return array [success, message]
     */
    public function addDeduction($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO payroll_deductions 
                (employee_id, deduction_type, amount, effective_from, effective_to, is_recurring, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['employee_id'],
                $data['deduction_type'],
                $data['amount'],
                $data['effective_from'],
                $data['effective_to'] ?: null,
                isset($data['is_recurring']) ? 1 : 0
            ]);
            return ['success' => true, 'message' => 'Deduction added.'];
        } catch (Exception $e) {
            error_log("Error adding deduction: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Update an existing deduction
     * @param int $id
     * @param array $data
     * @return array [success, message]
     */
    public function updateDeduction($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE payroll_deductions SET
                    deduction_type = ?,
                    amount = ?,
                    effective_from = ?,
                    effective_to = ?,
                    is_recurring = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['deduction_type'],
                $data['amount'],
                $data['effective_from'],
                $data['effective_to'] ?: null,
                isset($data['is_recurring']) ? 1 : 0,
                $id
            ]);
            return ['success' => true, 'message' => 'Deduction updated.'];
        } catch (Exception $e) {
            error_log("Error updating deduction: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Delete a deduction
     * @param int $id
     * @return array [success, message]
     */
    public function deleteDeduction($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM payroll_deductions WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true, 'message' => 'Deduction deleted.'];
        } catch (Exception $e) {
            error_log("Error deleting deduction: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Log audit action (stub – implement as needed)
     */
    private function logAudit($userId, $action, $entity, $entityId, $old, $new) {
        // Optional implementation
        
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (school_id, user_id, action, entity_type, entity_id, old_values, new_values, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $this->schoolId,
            $userId,
            $action,
            $entity,
            $entityId,
            $old ? json_encode($old) : null,
            $new ? json_encode($new) : null
        ]);
        
    }
}