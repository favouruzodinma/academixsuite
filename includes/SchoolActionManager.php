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

    /**
     * Update the school record in the platform database with general settings.
     *
     * @param array $data  Associative array of fields to update (text + optional
     *                     logo_path / favicon_path already resolved by the caller).
     * @return array       ['success' => bool, 'message' => string]
     */
    public function updateSchoolDetails(array $data): array {
        try {
            // Get existing columns so we only touch what's actually there.
            $cols = [];
            try {
                $stmt = $this->platformDb->query("SHOW COLUMNS FROM `schools`");
                $cols = $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
            } catch (Exception $e) {
                error_log("updateSchoolDetails SHOW COLUMNS: " . $e->getMessage());
            }

            if (empty($cols)) {
                return ['success' => false, 'message' => 'Unable to read school table schema.'];
            }

            // Scalar text / numeric columns that may be sent directly.
            $allowed = [
                'name', 'email', 'phone', 'website', 'address', 'city', 'state',
                'country', 'postal_code', 'timezone', 'currency', 'language',
                'school_type', 'curriculum', 'establishment_year', 'principal_name',
                'description', 'mission_statement', 'vision_statement',
                'principal_message', 'logo_path', 'favicon_path',
                'primary_color', 'secondary_color',
                'admission_status', 'admission_deadline',
                'transportation_available', 'boarding_available', 'meal_provided',
            ];

            $sets = [];
            $vals = [];

            foreach ($allowed as $col) {
                if (!in_array($col, $cols, true) || !array_key_exists($col, $data)) {
                    continue;
                }
                $v = $data[$col];
                // Color validation
                if (in_array($col, ['primary_color', 'secondary_color'], true)) {
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v)) continue;
                }
                $sets[] = "`{$col}` = ?";
                $vals[] = ($v === '' || $v === null) ? null : $v;
            }

            // social_links – merge facebook/twitter/instagram/linkedin/youtube into JSON.
            if (in_array('social_links', $cols, true)) {
                $social = [];
                foreach (['facebook', 'twitter', 'instagram', 'linkedin', 'youtube'] as $net) {
                    if (array_key_exists($net, $data)) {
                        $url = trim((string) $data[$net]);
                        if ($url !== '') $social[$net] = $url;
                    }
                }
                // Only persist if at least one key was passed (even if empty, to allow clearing).
                if (!empty(array_intersect(['facebook','twitter','instagram','linkedin','youtube'], array_keys($data)))) {
                    $sets[] = '`social_links` = ?';
                    $vals[] = json_encode($social, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (empty($sets)) {
                return ['success' => false, 'message' => 'No valid fields to update.'];
            }

            $vals[] = $this->schoolId;
            $sql = 'UPDATE `schools` SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $this->platformDb->prepare($sql)->execute($vals);

            return ['success' => true, 'message' => 'School settings saved successfully.'];
        } catch (Exception $e) {
            error_log("updateSchoolDetails error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()];
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
            $this->ensureSubjectTables();
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
            $this->ensureSubjectTables();
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
            $this->ensureSubjectTables();
            $stmt = $this->schoolDb->prepare("
                SELECT cs.*, c.name as class_name, s.name as subject_name,
                       u.name as teacher_name
                FROM class_subjects cs
                LEFT JOIN classes c ON cs.class_id = c.id
                LEFT JOIN subjects s ON cs.subject_id = s.id
                LEFT JOIN users u ON cs.teacher_id = u.id
                WHERE c.school_id = ?
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
                INSERT INTO academic_years
                    (school_id, name, start_date, end_date, is_default, status" . ($this->hasColumn('academic_years', 'campus_id') ? ", campus_id" : "") . ", created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?" . ($this->hasColumn('academic_years', 'campus_id') ? ", ?" : "") . ", NOW())
            ");
            $params = [
                $this->schoolId,
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['is_default'] ?? 0,
                $data['status'] ?? 'upcoming'
            ];
            if ($this->hasColumn('academic_years', 'campus_id')) {
                $params[] = $this->defaultCampusId();
            }
            $stmt->execute($params);
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
            $className = trim((string)($data['name'] ?? ''));
            if ($className === '') {
                throw new Exception("Class name is required");
            }

            $duplicateName = $this->schoolDb->prepare("
                SELECT id FROM classes
                WHERE school_id = ?
                  AND academic_year_id = ?
                  AND LOWER(TRIM(name)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $duplicateName->execute([$this->schoolId, $data['academic_year_id'], $className]);
            $existingClassId = $duplicateName->fetchColumn();
            if ($existingClassId) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'This class already exists, so no duplicate was created.',
                    'id' => (int)$existingClassId,
                ];
            }

            // Check duplicate code
            $check = $this->schoolDb->prepare("SELECT id FROM classes WHERE school_id = ? AND code = ? AND academic_year_id = ?");
            $check->execute([$this->schoolId, $data['code'], $data['academic_year_id']]);
            $existingCodeId = $check->fetchColumn();
            if ($existingCodeId) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'This class code already exists for the academic year, so no duplicate was created.',
                    'id' => (int)$existingCodeId,
                ];
            }

            $hasCampus = $this->hasColumn('classes', 'campus_id');
            $stmt = $this->schoolDb->prepare("
                INSERT INTO classes (
                    school_id" . ($hasCampus ? ", campus_id" : "") . ",
                    name, code, description, grade_level, class_teacher_id, capacity, room_number, academic_year_id, is_active, created_at
                )
                VALUES (?" . ($hasCampus ? ", ?" : "") . ", ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $params = [
                $this->schoolId,
            ];
            if ($hasCampus) {
                $params[] = $this->defaultCampusId();
            }
            $params = array_merge($params, [
                $data['name'],
                $data['code'],
                $data['description'] ?? null,
                $data['grade_level'] ?? null,
                $data['class_teacher_id'] ?? null,
                $data['capacity'] ?? 40,
                $data['room_number'] ?? null,
                $data['academic_year_id']
            ]);
            $stmt->execute($params);
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
                INSERT INTO academic_terms
                    (school_id, academic_year_id, name, start_date, end_date, is_default" . ($this->hasColumn('academic_terms', 'campus_id') ? ", campus_id" : "") . ", created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?" . ($this->hasColumn('academic_terms', 'campus_id') ? ", ?" : "") . ", NOW())
            ");
            $params = [
                $this->schoolId,
                $data['academic_year_id'],
                $data['name'],
                $data['start_date'],
                $data['end_date'],
                $data['is_default'] ?? 0
            ];
            if ($this->hasColumn('academic_terms', 'campus_id')) {
                $params[] = $this->defaultCampusId();
            }
            $stmt->execute($params);
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

    public function getAcademicYearById($id) {
        return $this->getById('academic_years', $id);
    }

    public function getAcademicTermById($id) {
        return $this->getById('academic_terms', $id);
    }

    public function getClassById($id) {
        return $this->getById('classes', $id);
    }

    public function getSectionById($id) {
        return $this->getById('sections', $id);
    }

    public function getSubjectById($id) {
        return $this->getById('subjects', $id);
    }

    public function getPaymentMethodById($id) {
        $row = $this->getById('payment_methods', $id);
        if (!empty($row['metadata']) && is_string($row['metadata'])) {
            $decoded = json_decode($row['metadata'], true);
            if (is_array($decoded)) {
                $row['metadata'] = $decoded;
            }
        }
        return $row;
    }

    public function getFeeCategoryById($id) {
        return $this->getById('fee_categories', $id);
    }

    public function getFeeStructureById($id) {
        return $this->getById('fee_structures', $id);
    }

    public function createSection($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $id = $this->insertRow('sections', [
                'school_id' => $this->schoolId,
                'class_id' => (int)($data['class_id'] ?? 0),
                'name' => trim((string)($data['name'] ?? '')),
                'code' => trim((string)($data['code'] ?? '')),
                'room_number' => $data['room_number'] ?? null,
                'capacity' => (int)($data['capacity'] ?? 40),
                'class_teacher_id' => $data['class_teacher_id'] ?? null,
                'is_active' => 1,
            ]);
            $this->createAuditLog('section_created', 'sections', $id, $data);
            return ['success' => true, 'message' => 'Section created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSection($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->updateRow('sections', $id, [
                'class_id' => (int)($data['class_id'] ?? 0),
                'name' => trim((string)($data['name'] ?? '')),
                'code' => trim((string)($data['code'] ?? '')),
                'room_number' => $data['room_number'] ?? null,
                'capacity' => (int)($data['capacity'] ?? 40),
                'class_teacher_id' => $data['class_teacher_id'] ?? null,
            ]);
            $this->createAuditLog('section_updated', 'sections', $id, $data);
            return ['success' => true, 'message' => 'Section updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteSection($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            if ($this->tableExists('students') && $this->hasColumn('students', 'section_id')) {
                $check = $this->schoolDb->prepare("SELECT COUNT(*) FROM students WHERE section_id = ? AND school_id = ?");
                $check->execute([$id, $this->schoolId]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete: section has students assigned.");
                }
            }
            $this->deleteRow('sections', $id);
            $this->createAuditLog('section_deleted', 'sections', $id, []);
            return ['success' => true, 'message' => 'Section deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createSubject($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->ensureSubjectTables();
            $subjectName = trim((string)($data['name'] ?? ''));
            if ($subjectName === '') {
                throw new Exception("Subject name is required");
            }

            $nameCheck = $this->schoolDb->prepare("
                SELECT id FROM subjects
                WHERE school_id = ?
                  AND LOWER(TRIM(name)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $nameCheck->execute([$this->schoolId, $subjectName]);
            $existingSubjectId = $nameCheck->fetchColumn();
            if ($existingSubjectId) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'This subject already exists, so no duplicate was created.',
                    'id' => (int)$existingSubjectId,
                ];
            }

            $code = trim((string)($data['code'] ?? ''));
            if ($code !== '') {
                $check = $this->schoolDb->prepare("SELECT id FROM subjects WHERE school_id = ? AND code = ? LIMIT 1");
                $check->execute([$this->schoolId, $code]);
                $existingCodeId = $check->fetchColumn();
                if ($existingCodeId) {
                    return [
                        'success' => true,
                        'duplicate' => true,
                        'message' => 'This subject code already exists, so no duplicate was created.',
                        'id' => (int)$existingCodeId,
                    ];
                }
            }

            $id = $this->insertRow('subjects', [
                'school_id' => $this->schoolId,
                'campus_id' => $this->defaultCampusId(),
                'name' => $subjectName,
                'code' => $code,
                'type' => $data['type'] ?? 'core',
                'credit_hours' => (float)($data['credit_hours'] ?? 1.0),
                'description' => $data['description'] ?? null,
                'is_active' => 1,
            ]);
            $this->createAuditLog('subject_created', 'subjects', $id, $data);
            return ['success' => true, 'message' => 'Subject created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSubject($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->ensureSubjectTables();
            $this->updateRow('subjects', $id, [
                'name' => trim((string)($data['name'] ?? '')),
                'code' => trim((string)($data['code'] ?? '')),
                'type' => $data['type'] ?? 'core',
                'credit_hours' => (float)($data['credit_hours'] ?? 1.0),
                'description' => $data['description'] ?? null,
            ]);
            $this->createAuditLog('subject_updated', 'subjects', $id, $data);
            return ['success' => true, 'message' => 'Subject updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteSubject($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->ensureSubjectTables();
            if ($this->tableExists('class_subjects')) {
                $check = $this->schoolDb->prepare("SELECT COUNT(*) FROM class_subjects WHERE subject_id = ?");
                $check->execute([$id]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete: subject is assigned to classes.");
                }
            }
            $this->deleteRow('subjects', $id);
            $this->createAuditLog('subject_deleted', 'subjects', $id, []);
            return ['success' => true, 'message' => 'Subject deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function assignSubjectToClass($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->ensureSubjectTables();
            $classId = (int)($data['class_id'] ?? 0);
            $subjectId = (int)($data['subject_id'] ?? 0);
            if (!$classId || !$subjectId) {
                throw new Exception("Class and subject are required.");
            }

            $class = $this->getById('classes', $classId);
            $subject = $this->getById('subjects', $subjectId);
            if (!$class || !$subject) {
                throw new Exception("Class or subject not found.");
            }

            $check = $this->schoolDb->prepare("SELECT id FROM class_subjects WHERE class_id = ? AND subject_id = ? LIMIT 1");
            $check->execute([$classId, $subjectId]);
            if ($existingId = $check->fetchColumn()) {
                $this->updateSubjectAssignment((int)$existingId, ['teacher_id' => $data['teacher_id'] ?? null]);
                return ['success' => true, 'message' => 'Subject assignment updated', 'id' => (int)$existingId];
            }

            $id = $this->insertRow('class_subjects', [
                'school_id' => $this->schoolId,
                'campus_id' => $this->defaultCampusId(),
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'teacher_id' => $data['teacher_id'] ?? null,
            ], false);
            $this->createAuditLog('subject_assigned', 'class_subjects', $id, $data);
            return ['success' => true, 'message' => 'Subject assigned', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteSubjectAssignment($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $assignment = $this->getClassSubjectById($id);
            if (!$assignment) {
                throw new Exception("Assignment not found.");
            }
            $stmt = $this->schoolDb->prepare("DELETE FROM class_subjects WHERE id = ?");
            $stmt->execute([$id]);
            $this->createAuditLog('subject_assignment_deleted', 'class_subjects', $id, []);
            return ['success' => true, 'message' => 'Subject assignment deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createPaymentMethod($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            if (!empty($data['is_default'])) {
                $this->schoolDb->prepare("UPDATE payment_methods SET is_default = 0 WHERE school_id = ?")->execute([$this->schoolId]);
            }
            $id = $this->insertRow('payment_methods', [
                'school_id' => $this->schoolId,
                'type' => $data['type'] ?? '',
                'provider' => $data['provider'] ?? null,
                'last_four' => $data['last_four'] ?? null,
                'exp_month' => $data['exp_month'] ?? null,
                'exp_year' => $data['exp_year'] ?? null,
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'is_verified' => !empty($data['is_verified']) ? 1 : 0,
                'metadata' => json_encode($data['metadata'] ?? []),
            ]);
            $this->createAuditLog('payment_method_created', 'payment_methods', $id, $data);
            return ['success' => true, 'message' => 'Payment method created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updatePaymentMethod($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            if (!empty($data['is_default'])) {
                $this->schoolDb->prepare("UPDATE payment_methods SET is_default = 0 WHERE school_id = ? AND id <> ?")->execute([$this->schoolId, $id]);
            }
            $this->updateRow('payment_methods', $id, [
                'type' => $data['type'] ?? '',
                'provider' => $data['provider'] ?? null,
                'last_four' => $data['last_four'] ?? null,
                'exp_month' => $data['exp_month'] ?? null,
                'exp_year' => $data['exp_year'] ?? null,
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'is_verified' => !empty($data['is_verified']) ? 1 : 0,
                'metadata' => json_encode($data['metadata'] ?? []),
            ]);
            $this->createAuditLog('payment_method_updated', 'payment_methods', $id, $data);
            return ['success' => true, 'message' => 'Payment method updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deletePaymentMethod($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->deleteRow('payment_methods', $id);
            $this->createAuditLog('payment_method_deleted', 'payment_methods', $id, []);
            return ['success' => true, 'message' => 'Payment method deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createFeeCategory($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $id = $this->insertRow('fee_categories', [
                'school_id' => $this->schoolId,
                'name' => trim((string)($data['name'] ?? '')),
                'description' => $data['description'] ?? null,
                'is_active' => 1,
            ]);
            $this->createAuditLog('fee_category_created', 'fee_categories', $id, $data);
            return ['success' => true, 'message' => 'Fee category created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateFeeCategory($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->updateRow('fee_categories', $id, [
                'name' => trim((string)($data['name'] ?? '')),
                'description' => $data['description'] ?? null,
            ]);
            $this->createAuditLog('fee_category_updated', 'fee_categories', $id, $data);
            return ['success' => true, 'message' => 'Fee category updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteFeeCategory($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            if ($this->tableExists('fee_structures')) {
                $check = $this->schoolDb->prepare("SELECT COUNT(*) FROM fee_structures WHERE fee_category_id = ? AND school_id = ?");
                $check->execute([$id, $this->schoolId]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete: fee category is used by fee structures.");
                }
            }
            $this->deleteRow('fee_categories', $id);
            $this->createAuditLog('fee_category_deleted', 'fee_categories', $id, []);
            return ['success' => true, 'message' => 'Fee category deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createFeeStructure($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $termId = $this->resolveAcademicTermId($data['academic_term_id'] ?? null, $data['academic_year_id'] ?? null);
            $id = $this->insertRow('fee_structures', [
                'school_id' => $this->schoolId,
                'academic_year_id' => (int)($data['academic_year_id'] ?? 0),
                'academic_term_id' => $termId,
                'class_id' => (int)($data['class_id'] ?? 0),
                'fee_category_id' => (int)($data['fee_category_id'] ?? 0),
                'amount' => (float)($data['amount'] ?? 0),
                'due_date' => $data['due_date'] ?? null,
                'late_fee' => (float)($data['late_fee'] ?? 0),
                'is_active' => 1,
            ]);
            $this->createAuditLog('fee_structure_created', 'fee_structures', $id, $data);
            return ['success' => true, 'message' => 'Fee structure created', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateFeeStructure($id, $data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $termId = $this->resolveAcademicTermId($data['academic_term_id'] ?? null, $data['academic_year_id'] ?? null);
            $this->updateRow('fee_structures', $id, [
                'academic_year_id' => (int)($data['academic_year_id'] ?? 0),
                'academic_term_id' => $termId,
                'class_id' => (int)($data['class_id'] ?? 0),
                'fee_category_id' => (int)($data['fee_category_id'] ?? 0),
                'amount' => (float)($data['amount'] ?? 0),
                'due_date' => $data['due_date'] ?? null,
                'late_fee' => (float)($data['late_fee'] ?? 0),
            ]);
            $this->createAuditLog('fee_structure_updated', 'fee_structures', $id, $data);
            return ['success' => true, 'message' => 'Fee structure updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteFeeStructure($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->deleteRow('fee_structures', $id);
            $this->createAuditLog('fee_structure_deleted', 'fee_structures', $id, []);
            return ['success' => true, 'message' => 'Fee structure deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createAnnouncement($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $id = $this->insertRow('announcements', [
                'school_id' => $this->schoolId,
                'campus_id' => (int)($data['campus_id'] ?? 1),
                'title' => trim((string)($data['title'] ?? '')),
                'description' => trim((string)($data['description'] ?? '')),
                'target' => $data['target'] ?? 'all',
                'class_id' => $data['class_id'] ?? null,
                'section_id' => $data['section_id'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_published' => 1,
                'created_by' => $this->userId,
            ]);
            $this->createAuditLog('announcement_created', 'announcements', $id, $data);

            $whatsAppMessage = '';
            if (!empty($data['send_whatsapp'])) {
                try {
                    if (!class_exists('WhatsAppService')) {
                        $servicePath = __DIR__ . '/Services/WhatsAppService.php';
                        if (is_readable($servicePath)) {
                            require_once $servicePath;
                        }
                    }

                    if (class_exists('WhatsAppService')) {
                        $school = $this->getSchoolDetails();
                        $school = is_array($school) ? $school : [];
                        $school['id'] = $school['id'] ?? $this->schoolId;
                        $school['slug'] = $school['slug'] ?? $this->schoolSlug;
                        $school['name'] = $school['name'] ?? 'School';

                        $audiences = $data['whatsapp_audiences'] ?? ['parents', 'teachers'];
                        $audiences = is_array($audiences) ? $audiences : [$audiences];

                        $whatsAppResult = (new WhatsAppService($this->schoolDb, $school))->sendAnnouncement(
                            $id,
                            trim((string)($data['title'] ?? '')),
                            trim((string)($data['description'] ?? '')),
                            (string)($data['target'] ?? 'all'),
                            !empty($data['class_id']) ? (int)$data['class_id'] : null,
                            !empty($data['section_id']) ? (int)$data['section_id'] : null,
                            $audiences
                        );
                        $whatsAppMessage = ' ' . ($whatsAppResult['message'] ?? 'WhatsApp processing completed.');
                    }
                } catch (Throwable $e) {
                    error_log('SchoolActionManager::createAnnouncement WhatsApp: ' . $e->getMessage());
                    $whatsAppMessage = ' WhatsApp notification was not sent.';
                }
            }

            return ['success' => true, 'message' => 'Announcement created' . $whatsAppMessage, 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createApiKey($data) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $apiKey = 'ak_' . bin2hex(random_bytes(24));
            $apiSecret = 'sk_' . bin2hex(random_bytes(32));
            $id = $this->insertRow('api_keys', [
                'school_id' => $this->schoolId,
                'name' => trim((string)($data['name'] ?? 'API Key')),
                'api_key' => $apiKey,
                'api_secret' => password_hash($apiSecret, PASSWORD_DEFAULT),
                'permissions' => json_encode($data['permissions'] ?? []),
                'rate_limit_per_minute' => (int)($data['rate_limit_per_minute'] ?? 60),
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => 1,
            ]);
            $this->createAuditLog('api_key_created', 'api_keys', $id, ['name' => $data['name'] ?? 'API Key']);
            return ['success' => true, 'message' => 'API key created', 'id' => $id, 'api_key' => $apiKey, 'api_secret' => $apiSecret];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteApiKey($id) {
        try {
            if (!$this->schoolDb) throw new Exception("School database not connected");
            $this->deleteRow('api_keys', $id);
            $this->createAuditLog('api_key_deleted', 'api_keys', $id, []);
            return ['success' => true, 'message' => 'API key deleted'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getClassSubjectById($id) {
        try {
            if (!$this->schoolDb) return [];
            $stmt = $this->schoolDb->prepare("
                SELECT cs.*
                FROM class_subjects cs
                JOIN classes c ON c.id = cs.class_id
                WHERE cs.id = ? AND c.school_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("getClassSubjectById error: " . $e->getMessage());
            return [];
        }
    }

    private function updateSubjectAssignment($id, $data) {
        $this->updateRow('class_subjects', $id, [
            'teacher_id' => $data['teacher_id'] ?? null,
        ], false);
    }

    private function ensureSubjectTables(): void {
        if (!$this->schoolDb) {
            throw new Exception("School database not connected");
        }

        $this->schoolDb->exec("
            CREATE TABLE IF NOT EXISTS `subjects` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `school_id` int(10) unsigned NOT NULL,
                `campus_id` int(10) unsigned DEFAULT NULL,
                `name` varchar(150) NOT NULL,
                `code` varchar(50) DEFAULT NULL,
                `type` varchar(50) NOT NULL DEFAULT 'core',
                `credit_hours` decimal(5,2) NOT NULL DEFAULT 1.00,
                `description` text DEFAULT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_subjects_school_active` (`school_id`, `is_active`),
                KEY `idx_subjects_school_code` (`school_id`, `code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->schoolDb->exec("
            CREATE TABLE IF NOT EXISTS `class_subjects` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `school_id` int(10) unsigned NOT NULL,
                `campus_id` int(10) unsigned DEFAULT NULL,
                `class_id` int(10) unsigned NOT NULL,
                `subject_id` int(10) unsigned NOT NULL,
                `teacher_id` int(10) unsigned DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_class_subject` (`class_id`, `subject_id`),
                KEY `idx_class_subjects_school` (`school_id`),
                KEY `idx_class_subjects_subject` (`subject_id`),
                KEY `idx_class_subjects_teacher` (`teacher_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function getById($table, $id) {
        try {
            if (!$this->schoolDb || !$this->tableExists($table)) return [];
            $safeTable = str_replace('`', '', $table);
            $where = $this->hasColumn($table, 'school_id') ? 'id = ? AND school_id = ?' : 'id = ?';
            $params = $this->hasColumn($table, 'school_id') ? [$id, $this->schoolId] : [$id];
            $stmt = $this->schoolDb->prepare("SELECT * FROM `{$safeTable}` WHERE {$where} LIMIT 1");
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("getById {$table} error: " . $e->getMessage());
            return [];
        }
    }

    private function insertRow($table, array $data, $requireSchoolColumn = true) {
        if (!$this->tableExists($table)) {
            throw new Exception("Required table '{$table}' does not exist");
        }
        if ($requireSchoolColumn && $this->hasColumn($table, 'school_id')) {
            $data['school_id'] = $this->schoolId;
        }
        $data = $this->filterColumns($table, $data);
        if (!$data) {
            throw new Exception("No valid fields supplied for {$table}");
        }
        $safeTable = str_replace('`', '', $table);
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = "INSERT INTO `{$safeTable}` (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $this->schoolDb->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->schoolDb->lastInsertId();
    }

    private function updateRow($table, $id, array $data, $requireSchoolColumn = true) {
        if (!$this->tableExists($table)) {
            throw new Exception("Required table '{$table}' does not exist");
        }
        $data = $this->filterColumns($table, $data);
        unset($data['id'], $data['school_id']);
        if (!$data) {
            throw new Exception("No valid fields supplied for {$table}");
        }
        $safeTable = str_replace('`', '', $table);
        $sets = array_map(static fn($field) => "`{$field}` = ?", array_keys($data));
        $params = array_values($data);
        $where = 'id = ?';
        $params[] = $id;
        if ($requireSchoolColumn && $this->hasColumn($table, 'school_id')) {
            $where .= ' AND school_id = ?';
            $params[] = $this->schoolId;
        }
        $stmt = $this->schoolDb->prepare("UPDATE `{$safeTable}` SET " . implode(', ', $sets) . " WHERE {$where}");
        $stmt->execute($params);
    }

    private function deleteRow($table, $id) {
        if (!$this->tableExists($table)) {
            throw new Exception("Required table '{$table}' does not exist");
        }
        $safeTable = str_replace('`', '', $table);
        if ($this->hasColumn($table, 'school_id')) {
            $stmt = $this->schoolDb->prepare("DELETE FROM `{$safeTable}` WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $this->schoolId]);
            return;
        }
        $stmt = $this->schoolDb->prepare("DELETE FROM `{$safeTable}` WHERE id = ?");
        $stmt->execute([$id]);
    }

    private function filterColumns($table, array $data) {
        $columns = array_flip($this->columns($table));
        return array_filter(
            $data,
            static fn($value, $key) => isset($columns[$key]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function tableExists($table) {
        if (!$this->schoolDb) return false;
        try {
            $stmt = $this->schoolDb->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    private function hasColumn($table, $column) {
        return in_array($column, $this->columns($table), true);
    }

    private function columns($table) {
        static $cache = [];
        $key = $this->schoolId . ':' . $table;
        if (isset($cache[$key]) && $cache[$key] !== []) {
            return $cache[$key];
        }
        try {
            $safeTable = str_replace('`', '', $table);
            $rows = $this->schoolDb->query("SHOW COLUMNS FROM `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC);
            $cache[$key] = array_column($rows, 'Field');
        } catch (Exception $e) {
            $cache[$key] = [];
        }
        return $cache[$key];
    }

    private function defaultCampusId() {
        if (!$this->tableExists('campuses')) {
            return 1;
        }
        try {
            $orderBy = $this->hasColumn('campuses', 'is_main') ? 'is_main DESC, id ASC' : 'id ASC';
            $stmt = $this->schoolDb->prepare("SELECT id FROM campuses WHERE school_id = ? ORDER BY {$orderBy} LIMIT 1");
            $stmt->execute([$this->schoolId]);
            $campusId = (int)$stmt->fetchColumn();
            if ($campusId > 0) {
                return $campusId;
            }

            if ($this->hasColumn('campuses', 'school_id') && $this->hasColumn('campuses', 'name') && $this->hasColumn('campuses', 'code')) {
                return $this->insertRow('campuses', [
                    'school_id' => $this->schoolId,
                    'name' => 'Main Campus',
                    'code' => 'MAIN',
                    'is_active' => 1,
                ]);
            }

            return 1;
        } catch (Exception $e) {
            return 1;
        }
    }

    private function resolveAcademicTermId($termId, $academicYearId) {
        $termId = (int)$termId;
        if ($termId > 0) {
            return $termId;
        }
        if (!$this->tableExists('academic_terms')) {
            return null;
        }
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT id
                FROM academic_terms
                WHERE school_id = ? AND academic_year_id = ?
                ORDER BY is_default DESC, start_date ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute([$this->schoolId, (int)$academicYearId]);
            $resolved = (int)$stmt->fetchColumn();
            return $resolved ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

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
