<?php
/**
 * Student Detail Manager
 * Handles all database operations for student details page
 */
class StudentDetailManager {
    private $db;
    private $schoolId;

    public function __construct($db, $schoolId) {
        $this->db = $db;
        $this->schoolId = $schoolId;
    }

    /**
     * Get main student record with related data
     */
    public function getStudent($studentId) {
        $stmt = $this->db->prepare("
            SELECT 
                s.*,
                u.name as student_name,
                u.email as student_email,
                u.phone as student_phone,
                u.profile_photo,
                u.gender,
                u.date_of_birth,
                u.blood_group as user_blood_group,
                u.address as user_address,
                u.is_active as user_active,
                c.id as class_id,
                c.name as class_name,
                c.code as class_code,
                c.academic_year_id,
                ay.name as academic_year_name,
                sec.id as section_id,
                sec.name as section_name
            FROM students s
            LEFT JOIN users u ON s.user_id = u.id AND u.school_id = s.school_id
            LEFT JOIN classes c ON s.class_id = c.id AND c.school_id = s.school_id
            LEFT JOIN sections sec ON s.section_id = sec.id AND sec.school_id = s.school_id
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id AND ay.school_id = s.school_id
            WHERE s.id = ? AND s.school_id = ?
        ");
        $stmt->execute([$studentId, $this->schoolId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get guardians for a student
     */
    public function getGuardians($studentId) {
        $stmt = $this->db->prepare("
            SELECT 
                u.id,
                u.name,
                u.email,
                u.phone,
                u.profile_photo,
                g.relationship,
                g.is_primary,
                g.can_pickup,
                g.emergency_contact
            FROM guardians g
            LEFT JOIN users u ON g.user_id = u.id AND u.school_id = g.school_id
            WHERE g.student_id = ? AND g.school_id = ?
            ORDER BY g.is_primary DESC, g.relationship
        ");
        $stmt->execute([$studentId, $this->schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get attendance statistics for a student in a given year
     */
    public function getAttendanceStats($studentId, $year = null) {
        if (!$year) $year = date('Y');
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day_count,
                COUNT(*) as total_days
            FROM attendance 
            WHERE school_id = ? AND student_id = ? 
            AND YEAR(date) = ?
        ");
        $stmt->execute([$this->schoolId, $studentId, $year]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return array_merge([
            'present_count' => 0,
            'absent_count' => 0,
            'late_count' => 0,
            'half_day_count' => 0,
            'total_days' => 0
        ], $result ?: []);
    }

    /**
     * Get recent attendance records for a student
     */
    public function getAttendanceRecords($studentId, $year = null, $limit = 30) {
        if (!$year) $year = date('Y');
        $stmt = $this->db->prepare("
            SELECT 
                date,
                status,
                remark,
                session,
                created_at
            FROM attendance 
            WHERE school_id = ? AND student_id = ? 
            AND YEAR(date) = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$this->schoolId, $studentId, $year, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get fee statistics for a student
     */
    public function getFeeStats($studentId) {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(balance_amount), 0) as total_due,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_invoices,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_invoices,
                COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_invoices
            FROM invoices 
            WHERE school_id = ? AND student_id = ?
        ");
        $stmt->execute([$this->schoolId, $studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return array_merge([
            'total_amount' => 0,
            'total_paid' => 0,
            'total_due' => 0,
            'paid_invoices' => 0,
            'pending_invoices' => 0,
            'overdue_invoices' => 0
        ], $result ?: []);
    }

    /**
     * Get recent fee records for a student
     */
    public function getFeeRecords($studentId, $limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                i.*,
                (SELECT fc.name 
                 FROM invoice_items ii 
                 JOIN fee_categories fc ON ii.fee_category_id = fc.id AND fc.school_id = i.school_id 
                 WHERE ii.invoice_id = i.id 
                 LIMIT 1) as fee_category_name
            FROM invoices i
            WHERE i.school_id = ? AND i.student_id = ?
            ORDER BY i.due_date DESC
            LIMIT ?
        ");
        $stmt->execute([$this->schoolId, $studentId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get exam results for a student
     */
    public function getExamResults($studentId, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT 
                eg.*,
                e.name as exam_name,
                e.start_date as exam_start_date,
                e.end_date as exam_end_date,
                sub.name as subject_name,
                sub.code as subject_code
            FROM exam_grades eg
            LEFT JOIN exams e ON eg.exam_id = e.id AND e.school_id = eg.school_id
            LEFT JOIN subjects sub ON eg.subject_id = sub.id AND sub.school_id = eg.school_id
            WHERE eg.school_id = ? AND eg.student_id = ?
            ORDER BY e.start_date DESC, sub.name
            LIMIT ?
        ");
        $stmt->execute([$this->schoolId, $studentId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Suspend a student (set status inactive)
     */
    public function suspendStudent($studentId, $userId) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("UPDATE students SET status = 'inactive' WHERE id = ? AND school_id = ?");
            $stmt->execute([$studentId, $this->schoolId]);

            // Also update user status
            $stmt2 = $this->db->prepare("SELECT user_id FROM students WHERE id = ? AND school_id = ?");
            $stmt2->execute([$studentId, $this->schoolId]);
            $student = $stmt2->fetch(PDO::FETCH_ASSOC);
            if (!empty($student['user_id'])) {
                $stmt3 = $this->db->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND school_id = ?");
                $stmt3->execute([$student['user_id'], $this->schoolId]);
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'Student suspended successfully.'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to suspend student: ' . $e->getMessage()];
        }
    }

    /**
     * Activate a student (set status active)
     */
    public function activateStudent($studentId, $userId) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("UPDATE students SET status = 'active' WHERE id = ? AND school_id = ?");
            $stmt->execute([$studentId, $this->schoolId]);

            $stmt2 = $this->db->prepare("SELECT user_id FROM students WHERE id = ? AND school_id = ?");
            $stmt2->execute([$studentId, $this->schoolId]);
            $student = $stmt2->fetch(PDO::FETCH_ASSOC);
            if (!empty($student['user_id'])) {
                $stmt3 = $this->db->prepare("UPDATE users SET is_active = 1 WHERE id = ? AND school_id = ?");
                $stmt3->execute([$student['user_id'], $this->schoolId]);
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'Student activated successfully.'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to activate student: ' . $e->getMessage()];
        }
    }

    /**
     * Promote/Transfer a student (records history in student_promotions and updates current class/section/campus)
     */
    public function promoteStudent($studentId, $data, $userId) {
        // $data: to_academic_year_id, to_class_id, to_section_id (optional), to_campus_id (optional), remarks
        try {
            $this->db->beginTransaction();

            // Get current student info along with the academic year from their current class
            $stmt = $this->db->prepare("
                SELECT s.class_id, s.section_id, s.campus_id, c.academic_year_id as from_academic_year_id
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id AND c.school_id = s.school_id
                WHERE s.id = ? AND s.school_id = ?
            ");
            $stmt->execute([$studentId, $this->schoolId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                throw new Exception("Student not found.");
            }

            // Insert into student_promotions
            $insert = $this->db->prepare("
                INSERT INTO student_promotions 
                (school_id, student_id, from_academic_year_id, to_academic_year_id, from_class_id, to_class_id, from_section_id, to_section_id, from_campus_id, to_campus_id, promotion_date, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $this->schoolId,
                $studentId,
                $current['from_academic_year_id'],
                $data['to_academic_year_id'],
                $current['class_id'],
                $data['to_class_id'],
                $current['section_id'],
                $data['to_section_id'] ?? null,
                $current['campus_id'] ?? null,
                $data['to_campus_id'] ?? null,
                date('Y-m-d'),
                $data['remarks'] ?? null,
                $userId
            ]);

            // Update student's current class/section/campus (academic year is derived from new class)
            $update = $this->db->prepare("
                UPDATE students 
                SET class_id = ?, section_id = ?, campus_id = ?
                WHERE id = ? AND school_id = ?
            ");
            $update->execute([
                $data['to_class_id'],
                $data['to_section_id'] ?? null,
                $data['to_campus_id'] ?? null,
                $studentId,
                $this->schoolId
            ]);

            $this->db->commit();
            return ['success' => true, 'message' => 'Student promoted/transferred successfully.'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to promote/transfer student: ' . $e->getMessage()];
        }
    }

    /**
     * Transfer student (alias for promote)
     */
    public function transferStudent($studentId, $data, $userId) {
        return $this->promoteStudent($studentId, $data, $userId);
    }

    // --- Helper methods for dropdowns ---
    public function getAcademicYears() {
        $stmt = $this->db->prepare("SELECT id, name FROM academic_years WHERE school_id = ? AND status = 'active' ORDER BY start_date DESC");
        $stmt->execute([$this->schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClasses($academicYearId = null) {
        if ($academicYearId) {
            $stmt = $this->db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND academic_year_id = ? AND is_active = 1 ORDER BY name");
            $stmt->execute([$this->schoolId, $academicYearId]);
        } else {
            $stmt = $this->db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY name");
            $stmt->execute([$this->schoolId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSections($classId = null) {
        if ($classId) {
            $stmt = $this->db->prepare("SELECT id, name FROM sections WHERE school_id = ? AND class_id = ? AND is_active = 1 ORDER BY name");
            $stmt->execute([$this->schoolId, $classId]);
        } else {
            $stmt = $this->db->prepare("SELECT id, name FROM sections WHERE school_id = ? AND is_active = 1 ORDER BY name");
            $stmt->execute([$this->schoolId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCampuses() {
        $stmt = $this->db->prepare("SELECT id, name FROM campuses WHERE school_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$this->schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}