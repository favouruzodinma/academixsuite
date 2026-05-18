<?php
/**
 * School Action Manager
 * Handles all school-related data operations and actions
 */
class SchoolActionManager {
    private $platformDb;
    private $schoolDb;
    private $schoolId;
    private $schoolSlug;
    private $userId;

    public function __construct($platformDb, $schoolDb, $schoolId, $schoolSlug, $userId) {
        $this->platformDb = $platformDb;
        $this->schoolDb = $schoolDb;
        $this->schoolId = $schoolId;
        $this->schoolSlug = $schoolSlug;
        $this->userId = $userId;
    }

    // ==================== GETTERS ====================

    public function getSchoolDetails() {
        try {
            $stmt = $this->platformDb->prepare("
                SELECT s.*, p.name as plan_name, p.price_monthly, p.features as plan_features
                FROM schools s
                LEFT JOIN plans p ON s.plan_id = p.id
                WHERE s.id = ?
            ");
            $stmt->execute([$this->schoolId]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['facilities'] = json_decode($details['facilities'] ?? '[]', true);
                $details['social_links'] = json_decode($details['social_links'] ?? '[]', true);
                $details['plan_features'] = json_decode($details['plan_features'] ?? '[]', true);
            }
            return $details ?: [];
        } catch (Exception $e) {
            error_log("getSchoolDetails error: " . $e->getMessage());
            return [];
        }
    }

    public function getStudentCount() {
        if (!$this->schoolDb) return 0;
        try {
            $stmt = $this->schoolDb->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'");
            $stmt->execute([$this->schoolId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("getStudentCount error: " . $e->getMessage());
            return 0;
        }
    }

    public function getTeacherCount() {
        if (!$this->schoolDb) return 0;
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT COUNT(*) FROM users 
                WHERE school_id = ? AND user_type = 'teacher' AND is_active = 1
            ");
            $stmt->execute([$this->schoolId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("getTeacherCount error: " . $e->getMessage());
            return 0;
        }
    }

    public function getAcademicYears() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM academic_years 
                WHERE school_id = ? 
                ORDER BY is_default DESC, start_date DESC
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getAcademicYears error: " . $e->getMessage());
            return [];
        }
    }

    public function getAcademicTerms($academicYearId = null) {
        if (!$this->schoolDb) return [];
        try {
            $sql = "SELECT t.*, ay.name as academic_year_name 
                    FROM academic_terms t
                    JOIN academic_years ay ON t.academic_year_id = ay.id
                    WHERE t.school_id = ?";
            $params = [$this->schoolId];
            if ($academicYearId) {
                $sql .= " AND t.academic_year_id = ?";
                $params[] = $academicYearId;
            }
            $sql .= " ORDER BY t.start_date";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getAcademicTerms error: " . $e->getMessage());
            return [];
        }
    }

    public function getClasses() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT c.*, ay.name as academic_year_name,
                       COUNT(DISTINCT s.id) as section_count,
                       COUNT(DISTINCT cs.subject_id) as subject_count
                FROM classes c
                LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
                LEFT JOIN sections s ON c.id = s.class_id
                LEFT JOIN class_subjects cs ON c.id = cs.class_id
                WHERE c.school_id = ? AND c.is_active = 1
                GROUP BY c.id
                ORDER BY c.name
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getClasses error: " . $e->getMessage());
            return [];
        }
    }

    public function getSections() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT s.*, c.name as class_name,
                       COUNT(DISTINCT st.id) as student_count
                FROM sections s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN students st ON s.id = st.section_id AND st.status = 'active'
                WHERE s.school_id = ? AND s.is_active = 1
                GROUP BY s.id
                ORDER BY c.name, s.name
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getSections error: " . $e->getMessage());
            return [];
        }
    }

    public function getSubjects() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM subjects 
                WHERE school_id = ? AND is_active = 1
                ORDER BY name
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getSubjects error: " . $e->getMessage());
            return [];
        }
    }

    public function getClassSubjects() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT cs.*, c.name as class_name, s.name as subject_name,
                       u.name as teacher_name
                FROM class_subjects cs
                LEFT JOIN classes c ON cs.class_id = c.id
                LEFT JOIN subjects s ON cs.subject_id = s.id
                LEFT JOIN users u ON cs.teacher_id = u.id
                WHERE cs.school_id = ?
                ORDER BY c.name, s.name
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getClassSubjects error: " . $e->getMessage());
            return [];
        }
    }

    public function getPaymentMethods() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM payment_methods 
                WHERE school_id = ?
                ORDER BY is_default DESC, type
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getPaymentMethods error: " . $e->getMessage());
            return [];
        }
    }

    public function getFeeCategories() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM fee_categories 
                WHERE school_id = ? AND is_active = 1
                ORDER BY name
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getFeeCategories error: " . $e->getMessage());
            return [];
        }
    }

    public function getFeeStructures() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT fs.*, c.name as class_name, ay.name as academic_year_name,
                       fc.name as category_name, t.name as term_name
                FROM fee_structures fs
                LEFT JOIN classes c ON fs.class_id = c.id
                LEFT JOIN academic_years ay ON fs.academic_year_id = ay.id
                LEFT JOIN fee_categories fc ON fs.fee_category_id = fc.id
                LEFT JOIN academic_terms t ON fs.academic_term_id = t.id
                WHERE fs.school_id = ? AND fs.is_active = 1
                ORDER BY c.name, fc.name
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getFeeStructures error: " . $e->getMessage());
            return [];
        }
    }

    public function getAnnouncements($limit = 10) {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT a.*, u.name as created_by_name 
                FROM announcements a
                LEFT JOIN users u ON a.created_by = u.id
                WHERE a.school_id = ? AND a.is_published = 1
                ORDER BY a.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$this->schoolId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getAnnouncements error: " . $e->getMessage());
            return [];
        }
    }

    public function getRecentActivities($limit = 20) {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM audit_logs 
                WHERE school_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$this->schoolId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getRecentActivities error: " . $e->getMessage());
            return [];
        }
    }

    public function getStorageUsage() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM storage_usage 
                WHERE school_id = ?
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getStorageUsage error: " . $e->getMessage());
            return [];
        }
    }

    public function getSubscriptionInfo() {
        try {
            $stmt = $this->platformDb->prepare("
                SELECT * FROM subscriptions 
                WHERE school_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("getSubscriptionInfo error: " . $e->getMessage());
            return [];
        }
    }

    public function getApiKeys() {
        if (!$this->schoolDb) return [];
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM api_keys 
                WHERE school_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getApiKeys error: " . $e->getMessage());
            return [];
        }
    }

    // ==================== CREATE / UPDATE / DELETE ====================

    public function createAcademicYear($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->schoolDb->beginTransaction();

            if (!empty($data['is_default']) && $data['is_default'] == 1) {
                $reset = $this->schoolDb->prepare("UPDATE academic_years SET is_default = 0 WHERE school_id = ?");
                $reset->execute([$this->schoolId]);
            }

            $stmt = $this->schoolDb->prepare("
                INSERT INTO academic_years (school_id, name, start_date, end_date, is_default, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['is_default'] ?? 0,
                $data['status'] ?? 'upcoming'
            ]);
            $id = $this->schoolDb->lastInsertId();

            $this->createAuditLog('academic_year_created', 'academic_years', $id, $data);
            $this->schoolDb->commit();
            return ['success' => true, 'message' => 'Academic year created', 'id' => $id];
        } catch (Exception $e) {
            $this->rollbackIfNeeded();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateAcademicYear($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->schoolDb->beginTransaction();

            if (!empty($data['is_default']) && $data['is_default'] == 1) {
                $reset = $this->schoolDb->prepare("UPDATE academic_years SET is_default = 0 WHERE school_id = ?");
                $reset->execute([$this->schoolId]);
            }

            $stmt = $this->schoolDb->prepare("
                UPDATE academic_years SET name=?, start_date=?, end_date=?, is_default=?, status=?
                WHERE id=? AND school_id=?
            ");
            $stmt->execute([
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['is_default'] ?? 0,
                $data['status'] ?? 'upcoming',
                $id,
                $this->schoolId
            ]);

            $this->createAuditLog('academic_year_updated', 'academic_years', $id, $data);
            $this->schoolDb->commit();
            return ['success' => true, 'message' => 'Academic year updated'];
        } catch (Exception $e) {
            $this->rollbackIfNeeded();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteAcademicYear($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            // Check if used in terms, classes, etc.
            $check = $this->schoolDb->prepare("SELECT COUNT(*) FROM academic_terms WHERE academic_year_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("Cannot delete: academic year has associated terms.");
            }
            $stmt = $this->schoolDb->prepare("DELETE FROM academic_years WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            $this->createAuditLog('academic_year_deleted', 'academic_years', $id, []);
            return ['success' => true, 'message' => 'Academic year deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Similar CRUD methods for other entities (academic terms, classes, sections, subjects, etc.)
    // For brevity, I'll include a few representative ones. You can extend similarly.

    public function createClass($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            // Check duplicate code
            $check = $this->schoolDb->prepare("SELECT id FROM classes WHERE school_id = ? AND code = ? AND academic_year_id = ?");
            $check->execute([$this->schoolId, $data['code'], $data['academic_year_id']]);
            if ($check->fetch()) throw new Exception("Class code already exists for this academic year");

            $stmt = $this->schoolDb->prepare("
                INSERT INTO classes (school_id, name, code, description, grade_level, class_teacher_id, capacity, room_number, academic_year_id, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['name'],
                $data['code'],
                $data['description'] ?? null,
                $data['grade_level'] ?? null,
                $data['class_teacher_id'] ?? null,
                $data['capacity'] ?? 40,
                $data['room_number'] ?? null,
                $data['academic_year_id']
            ]);
            $id = $this->schoolDb->lastInsertId();
            $this->createAuditLog('class_created', 'classes', $id, $data);
            return ['success' => true, 'message' => 'Class created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateClass($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $stmt = $this->schoolDb->prepare("
                UPDATE classes SET name=?, code=?, description=?, grade_level=?, class_teacher_id=?, capacity=?, room_number=?, academic_year_id=?
                WHERE id=? AND school_id=?
            ");
            $stmt->execute([
                $data['name'],
                $data['code'],
                $data['description'] ?? null,
                $data['grade_level'] ?? null,
                $data['class_teacher_id'] ?? null,
                $data['capacity'] ?? 40,
                $data['room_number'] ?? null,
                $data['academic_year_id'],
                $id,
                $this->schoolId
            ]);
            $this->createAuditLog('class_updated', 'classes', $id, $data);
            return ['success' => true, 'message' => 'Class updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteClass($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            // Check dependencies (sections, students, etc.)
            $check = $this->schoolDb->prepare("SELECT COUNT(*) FROM sections WHERE class_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("Cannot delete: class has associated sections.");
            }
            $stmt = $this->schoolDb->prepare("DELETE FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            $this->createAuditLog('class_deleted', 'classes', $id, []);
            return ['success' => true, 'message' => 'Class deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Create academic term
    public function createAcademicTerm($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->schoolDb->beginTransaction();
            // Validate dates
            if (strtotime($data['end_date']) <= strtotime($data['start_date'])) {
                throw new Exception("End date must be after start date");
            }
            // Check duplicate name for same year
            $check = $this->schoolDb->prepare("SELECT id FROM academic_terms WHERE school_id = ? AND academic_year_id = ? AND name = ?");
            $check->execute([$this->schoolId, $data['academic_year_id'], $data['name']]);
            if ($check->fetch()) throw new Exception("Term name already exists for this academic year");

            if (!empty($data['is_default'])) {
                $reset = $this->schoolDb->prepare("UPDATE academic_terms SET is_default = 0 WHERE school_id = ? AND academic_year_id = ?");
                $reset->execute([$this->schoolId, $data['academic_year_id']]);
            }

            $stmt = $this->schoolDb->prepare("
                INSERT INTO academic_terms (school_id, academic_year_id, name, start_date, end_date, is_default, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['academic_year_id'],
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['is_default'] ?? 0
            ]);
            $id = $this->schoolDb->lastInsertId();
            $this->createAuditLog('academic_term_created', 'academic_terms', $id, $data);
            $this->schoolDb->commit();
            return ['success' => true, 'message' => 'Academic term created', 'id' => $id];
        } catch (Exception $e) {
            $this->rollbackIfNeeded();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateAcademicTerm($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->schoolDb->beginTransaction();
            if (!empty($data['is_default'])) {
                $reset = $this->schoolDb->prepare("UPDATE academic_terms SET is_default = 0 WHERE school_id = ? AND academic_year_id = ?");
                $reset->execute([$this->schoolId, $data['academic_year_id']]);
            }
            $stmt = $this->schoolDb->prepare("
                UPDATE academic_terms SET name=?, start_date=?, end_date=?, is_default=?
                WHERE id=? AND school_id=?
            ");
            $stmt->execute([
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['is_default'] ?? 0,
                $id,
                $this->schoolId
            ]);
            $this->createAuditLog('academic_term_updated', 'academic_terms', $id, $data);
            $this->schoolDb->commit();
            return ['success' => true, 'message' => 'Academic term updated'];
        } catch (Exception $e) {
            $this->rollbackIfNeeded();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteAcademicTerm($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $stmt = $this->schoolDb->prepare("DELETE FROM academic_terms WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            $this->createAuditLog('academic_term_deleted', 'academic_terms', $id, []);
            return ['success' => true, 'message' => 'Academic term deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ... (similar methods for sections, subjects, fee categories, payment methods, fee structures, announcements, api keys)

    // Helper audit log methods
    private function createAuditLog($action, $entityType, $entityId, $newValues) {
        if (!$this->schoolDb) return;
        try {
            $stmt = $this->schoolDb->prepare("
                INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                VALUES (?, ?, 'admin', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $this->userId,
                $action,
                $entityType,
                $entityId,
                json_encode($newValues),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
        }
    }

    private function rollbackIfNeeded() {
        if ($this->schoolDb && $this->schoolDb->inTransaction()) {
            $this->schoolDb->rollBack();
        }
        if ($this->platformDb && $this->platformDb->inTransaction()) {
            $this->platformDb->rollBack();
        }
    }

    // Update general settings (platform and school)
    public function updateGeneralSettings($post, $files) {
        // Implementation similar to handleGeneralSettingsUpdate but inside class
        // ... (you can move that function here)
        // For brevity, I'll keep it as is but you'd integrate it.
    }
}