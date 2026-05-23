<?php
/**
 * Timetable Manager Class
 * Handles all timetable-related operations including creation, updates, and retrieval
 * 
 * @package AcademixSuite
 * @version 2.0
 */

class TimetableManager {
    private $db;
    private $schoolId;
    private $userId;
    private $userType;
    private $schoolData;

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $schoolId School ID
     * @param int $userId Current user ID
     * @param string $userType Current user type
     * @param array $schoolData School information
     */
    public function __construct($db, $schoolId, $userId, $userType, $schoolData) {
        $this->db = $db;
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->userType = $userType;
        $this->schoolData = $schoolData;
        
        error_log("TimetableManager initialized for school ID: " . $schoolId);
    }

    /**
     * Days of the week
     */
    private $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * Get all days
     * @return array
     */
    public function getDays() {
        return $this->days;
    }

    /**
     * Add new timetable entry
     * @param array $data Timetable data
     * @return array [success, message, timetable_id]
     */
    public function addTimetable($data) {
        try {
            $this->db->beginTransaction();
            error_log("=== ADD TIMETABLE TRANSACTION STARTED ===");

            // Validate required fields
            $requiredFields = ['class_id', 'academic_year_id', 'academic_term_id', 'day', 'period_number', 'start_time', 'end_time', 'subject_id', 'teacher_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Required field '{$field}' is missing");
                }
            }

            // Check for conflicts (teacher already assigned at same time)
            $conflictStmt = $this->db->prepare("
                SELECT t.*, 
                       c.name as class_name,
                       s.name as subject_name,
                       u.name as teacher_name
                FROM timetables t
                LEFT JOIN classes c ON t.class_id = c.id
                LEFT JOIN subjects s ON t.subject_id = s.id
                LEFT JOIN users u ON t.teacher_id = u.id
                WHERE t.school_id = ? 
                AND t.day = ? 
                AND t.period_number = ?
                AND t.teacher_id = ?
                AND t.academic_year_id = ?
                AND t.academic_term_id = ?
            ");
            $conflictStmt->execute([
                $this->schoolId,
                $data['day'],
                $data['period_number'],
                $data['teacher_id'],
                $data['academic_year_id'],
                $data['academic_term_id']
            ]);
            
            $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);
            if ($conflict) {
                throw new Exception("Teacher already assigned to {$conflict['subject_name']} for {$conflict['class_name']} at this time");
            }

            // Check for classroom conflict
            if (!empty($data['room_number'])) {
                $roomConflictStmt = $this->db->prepare("
                    SELECT t.*, c.name as class_name
                    FROM timetables t
                    LEFT JOIN classes c ON t.class_id = c.id
                    WHERE t.school_id = ? 
                    AND t.day = ? 
                    AND t.period_number = ?
                    AND t.room_number = ?
                    AND t.academic_year_id = ?
                    AND t.academic_term_id = ?
                ");
                $roomConflictStmt->execute([
                    $this->schoolId,
                    $data['day'],
                    $data['period_number'],
                    $data['room_number'],
                    $data['academic_year_id'],
                    $data['academic_term_id']
                ]);
                
                $roomConflict = $roomConflictStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomConflict) {
                    throw new Exception("Room {$data['room_number']} is already booked for {$roomConflict['class_name']} at this time");
                }
            }

            // Insert timetable
            $stmt = $this->db->prepare("
                INSERT INTO timetables (
                    school_id, class_id, section_id, academic_year_id, academic_term_id,
                    day, period_number, start_time, end_time, subject_id, teacher_id,
                    room_number, is_break, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $this->schoolId,
                $data['class_id'],
                $data['section_id'] ?? null,
                $data['academic_year_id'],
                $data['academic_term_id'],
                $data['day'],
                $data['period_number'],
                $data['start_time'],
                $data['end_time'],
                $data['subject_id'],
                $data['teacher_id'],
                $data['room_number'] ?? null,
                $data['is_break'] ?? 0
            ]);

            $timetableId = $this->db->lastInsertId();
            error_log("Timetable entry created with ID: " . $timetableId);

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type, 
                    entity_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'create', 'timetable', ?, ?, ?, ?, NOW())
            ");

            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $timetableId,
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            error_log("=== TIMETABLE ADDED SUCCESSFULLY === ID: " . $timetableId);

            return [true, "Timetable entry added successfully", $timetableId];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                error_log("Transaction rolled back");
            }
            error_log("Error adding timetable: " . $e->getMessage());
            return [false, "Error adding timetable: " . $e->getMessage(), null];
        }
    }

    /**
     * Update timetable entry
     * @param int $timetableId
     * @param array $data
     * @return array [success, message]
     */
    public function updateTimetable($timetableId, $data) {
        try {
            $this->db->beginTransaction();

            // Get old values for audit log
            $oldStmt = $this->db->prepare("SELECT * FROM timetables WHERE id = ? AND school_id = ?");
            $oldStmt->execute([$timetableId, $this->schoolId]);
            $oldValues = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldValues) {
                throw new Exception("Timetable entry not found");
            }

            // Update timetable
            $stmt = $this->db->prepare("
                UPDATE timetables SET
                    class_id = ?,
                    section_id = ?,
                    academic_year_id = ?,
                    academic_term_id = ?,
                    day = ?,
                    period_number = ?,
                    start_time = ?,
                    end_time = ?,
                    subject_id = ?,
                    teacher_id = ?,
                    room_number = ?,
                    is_break = ?
                WHERE id = ? AND school_id = ?
            ");

            $stmt->execute([
                $data['class_id'],
                $data['section_id'] ?? null,
                $data['academic_year_id'],
                $data['academic_term_id'],
                $data['day'],
                $data['period_number'],
                $data['start_time'],
                $data['end_time'],
                $data['subject_id'],
                $data['teacher_id'],
                $data['room_number'] ?? null,
                $data['is_break'] ?? 0,
                $timetableId,
                $this->schoolId
            ]);

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type, 
                    entity_id, old_values, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'update', 'timetable', ?, ?, ?, ?, ?, NOW())
            ");

            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $timetableId,
                json_encode($oldValues),
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            return [true, "Timetable updated successfully"];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error updating timetable: " . $e->getMessage());
            return [false, "Error updating timetable: " . $e->getMessage()];
        }
    }

    /**
     * Delete timetable entry
     * @param int $timetableId
     * @return array [success, message]
     */
    public function deleteTimetable($timetableId) {
        try {
            $this->db->beginTransaction();

            // Get values for audit log
            $oldStmt = $this->db->prepare("SELECT * FROM timetables WHERE id = ? AND school_id = ?");
            $oldStmt->execute([$timetableId, $this->schoolId]);
            $oldValues = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldValues) {
                throw new Exception("Timetable entry not found");
            }

            // Delete timetable
            $stmt = $this->db->prepare("DELETE FROM timetables WHERE id = ? AND school_id = ?");
            $stmt->execute([$timetableId, $this->schoolId]);

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type, 
                    entity_id, old_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'delete', 'timetable', ?, ?, ?, ?, NOW())
            ");

            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $timetableId,
                json_encode($oldValues),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            return [true, "Timetable entry deleted successfully"];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error deleting timetable: " . $e->getMessage());
            return [false, "Error deleting timetable: " . $e->getMessage()];
        }
    }

    /**
     * Get timetable by ID
     * @param int $timetableId
     * @return array|false
     */
    public function getTimetable($timetableId) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*,
                       c.name as class_name,
                       c.code as class_code,
                       s.name as section_name,
                       ay.name as academic_year_name,
                       at.name as academic_term_name,
                       sub.name as subject_name,
                       sub.code as subject_code,
                       u.name as teacher_name,
                       u.email as teacher_email
                FROM timetables t
                LEFT JOIN classes c ON t.class_id = c.id
                LEFT JOIN sections s ON t.section_id = s.id
                LEFT JOIN academic_years ay ON t.academic_year_id = ay.id
                LEFT JOIN academic_terms at ON t.academic_term_id = at.id
                LEFT JOIN subjects sub ON t.subject_id = sub.id
                LEFT JOIN users u ON t.teacher_id = u.id
                WHERE t.id = ? AND t.school_id = ?
            ");
            $stmt->execute([$timetableId, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting timetable: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get timetables with filters
     * @param array $filters
     * @return array
     */
    public function getTimetables($filters = []) {
        try {
            $sql = "
                SELECT t.*,
                       c.name as class_name,
                       c.code as class_code,
                       s.name as section_name,
                       ay.name as academic_year_name,
                       at.name as academic_term_name,
                       sub.name as subject_name,
                       sub.code as subject_code,
                       u.name as teacher_name,
                       u.email as teacher_email
                FROM timetables t
                LEFT JOIN classes c ON t.class_id = c.id
                LEFT JOIN sections s ON t.section_id = s.id
                LEFT JOIN academic_years ay ON t.academic_year_id = ay.id
                LEFT JOIN academic_terms at ON t.academic_term_id = at.id
                LEFT JOIN subjects sub ON t.subject_id = sub.id
                LEFT JOIN users u ON t.teacher_id = u.id
                WHERE t.school_id = ?
            ";

            $params = [$this->schoolId];

            if (!empty($filters['class_id'])) {
                $sql .= " AND t.class_id = ?";
                $params[] = $filters['class_id'];
            }

            if (!empty($filters['section_id'])) {
                $sql .= " AND t.section_id = ?";
                $params[] = $filters['section_id'];
            }

            if (!empty($filters['teacher_id'])) {
                $sql .= " AND t.teacher_id = ?";
                $params[] = $filters['teacher_id'];
            }

            if (!empty($filters['day'])) {
                $sql .= " AND t.day = ?";
                $params[] = $filters['day'];
            }

            if (!empty($filters['academic_year_id'])) {
                $sql .= " AND t.academic_year_id = ?";
                $params[] = $filters['academic_year_id'];
            }

            if (!empty($filters['academic_term_id'])) {
                $sql .= " AND t.academic_term_id = ?";
                $params[] = $filters['academic_term_id'];
            }

            $sql .= " ORDER BY t.day, t.period_number";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Error getting timetables: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get timetable by day and class
     * @param string $day
     * @param int $classId
     * @param int $academicYearId
     * @param int $academicTermId
     * @return array
     */
    public function getTimetableByDayAndClass($day, $classId, $academicYearId, $academicTermId) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*,
                       sub.name as subject_name,
                       sub.code as subject_code,
                       u.name as teacher_name,
                       s.name as section_name
                FROM timetables t
                LEFT JOIN subjects sub ON t.subject_id = sub.id
                LEFT JOIN users u ON t.teacher_id = u.id
                LEFT JOIN sections s ON t.section_id = s.id
                WHERE t.school_id = ? 
                AND t.day = ?
                AND t.class_id = ?
                AND t.academic_year_id = ?
                AND t.academic_term_id = ?
                ORDER BY t.period_number
            ");
            $stmt->execute([
                $this->schoolId,
                $day,
                $classId,
                $academicYearId,
                $academicTermId
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting timetable by day and class: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get teacher's timetable
     * @param int $teacherId
     * @param int $academicYearId
     * @param int $academicTermId
     * @return array
     */
    public function getTeacherTimetable($teacherId, $academicYearId, $academicTermId) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*,
                       c.name as class_name,
                       c.code as class_code,
                       s.name as section_name,
                       sub.name as subject_name,
                       sub.code as subject_code
                FROM timetables t
                LEFT JOIN classes c ON t.class_id = c.id
                LEFT JOIN sections s ON t.section_id = s.id
                LEFT JOIN subjects sub ON t.subject_id = sub.id
                WHERE t.school_id = ? 
                AND t.teacher_id = ?
                AND t.academic_year_id = ?
                AND t.academic_term_id = ?
                ORDER BY t.day, t.period_number
            ");
            $stmt->execute([
                $this->schoolId,
                $teacherId,
                $academicYearId,
                $academicTermId
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting teacher timetable: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get timetable grid for display
     * @param array $filters
     * @return array
     */
    public function getTimetableGrid($filters = []) {
        $timetables = $this->getTimetables($filters);
        
        // Organize by day and period
        $grid = [];
        $periods = [];
        
        foreach ($timetables as $entry) {
            $day = $entry['day'];
            $period = $entry['period_number'];
            
            if (!isset($grid[$day])) {
                $grid[$day] = [];
            }
            
            $grid[$day][$period] = $entry;
            
            // Track unique periods
            if (!in_array($period, $periods)) {
                $periods[] = $period;
            }
        }
        
        sort($periods);
        
        return [
            'grid' => $grid,
            'periods' => $periods,
            'days' => $this->days
        ];
    }

    /**
     * Check if teacher is available at given time
     * @param int $teacherId
     * @param string $day
     * @param int $periodNumber
     * @param int $academicYearId
     * @param int $academicTermId
     * @param int|null $excludeId
     * @return bool
     */
    public function isTeacherAvailable($teacherId, $day, $periodNumber, $academicYearId, $academicTermId, $excludeId = null) {
        try {
            $sql = "
                SELECT id FROM timetables 
                WHERE school_id = ? 
                AND teacher_id = ?
                AND day = ?
                AND period_number = ?
                AND academic_year_id = ?
                AND academic_term_id = ?
            ";
            
            $params = [
                $this->schoolId,
                $teacherId,
                $day,
                $periodNumber,
                $academicYearId,
                $academicTermId
            ];
            
            if ($excludeId) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->rowCount() === 0;
        } catch (Exception $e) {
            error_log("Error checking teacher availability: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if room is available at given time
     * @param string $roomNumber
     * @param string $day
     * @param int $periodNumber
     * @param int $academicYearId
     * @param int $academicTermId
     * @param int|null $excludeId
     * @return bool
     */
    public function isRoomAvailable($roomNumber, $day, $periodNumber, $academicYearId, $academicTermId, $excludeId = null) {
        try {
            $sql = "
                SELECT id FROM timetables 
                WHERE school_id = ? 
                AND room_number = ?
                AND day = ?
                AND period_number = ?
                AND academic_year_id = ?
                AND academic_term_id = ?
            ";
            
            $params = [
                $this->schoolId,
                $roomNumber,
                $day,
                $periodNumber,
                $academicYearId,
                $academicTermId
            ];
            
            if ($excludeId) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->rowCount() === 0;
        } catch (Exception $e) {
            error_log("Error checking room availability: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get timetable statistics
     * @return array
     */
    public function getTimetableStats() {
        try {
            $stats = [];

            // Total entries
            $totalStmt = $this->db->prepare("SELECT COUNT(*) as total FROM timetables WHERE school_id = ?");
            $totalStmt->execute([$this->schoolId]);
            $stats['total'] = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Entries by day
            $dayStmt = $this->db->prepare("
                SELECT day, COUNT(*) as count 
                FROM timetables 
                WHERE school_id = ? 
                GROUP BY day
            ");
            $dayStmt->execute([$this->schoolId]);
            $stats['by_day'] = $dayStmt->fetchAll(PDO::FETCH_ASSOC);

            // Entries by teacher
            $teacherStmt = $this->db->prepare("
                SELECT u.name, COUNT(t.id) as count
                FROM timetables t
                JOIN users u ON t.teacher_id = u.id
                WHERE t.school_id = ?
                GROUP BY t.teacher_id
                ORDER BY count DESC
                LIMIT 5
            ");
            $teacherStmt->execute([$this->schoolId]);
            $stats['top_teachers'] = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

            return $stats;

        } catch (Exception $e) {
            error_log("Error getting timetable stats: " . $e->getMessage());
            return [];
        }
    }
}