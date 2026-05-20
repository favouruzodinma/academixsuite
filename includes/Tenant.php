<?php

/**
 * Tenant Management
 * Handles multi-tenancy, school detection, and isolation
 */

class Tenant
{
    private static $currentSchool = null;
    private static $schoolDb = null;
    private static $schoolCache = [];

    // Performance metrics tracking
    private static $performanceMetrics = [];

    // Rate limiting storage
    private static $rateLimits = [];

    // Storage limits
    private static $storageLimits = [
        'free' => 1073741824, // 1GB
        'basic' => 5368709120, // 5GB
        'premium' => 21474836480, // 20GB
        'enterprise' => 107374182400 // 100GB
    ];

    /**
     * Detect current school from request
     * @return array|null
     */
    public static function detect()
    {
        if (self::$currentSchool !== null) {
            return self::$currentSchool;
        }

        // Method 1: Check subdomain
        $school = self::detectFromSubdomain();
        if ($school) {
            self::$currentSchool = $school;
            return $school;
        }

        // Method 2: Check URL path
        $school = self::detectFromPath();
        if ($school) {
            self::$currentSchool = $school;
            return $school;
        }

        // Method 3: Check session
        $school = self::detectFromSession();
        if ($school) {
            self::$currentSchool = $school;
            return $school;
        }

        return null;
    }

    /**
     * Detect school from subdomain
     * @return array|null
     */
    private static function detectFromSubdomain()
    {
        if (function_exists('school_subdomain_slug')) {
            $subdomain = school_subdomain_slug();
            if (!$subdomain) {
                return null;
            }
            return self::getSchoolBySlug($subdomain);
        }

        return null;
    }

    /**
     * Detect school from URL path
     * @return array|null
     */
    private static function detectFromPath()
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Pattern: /tenant/{slug}/... or legacy /school/{slug}/...
        if (preg_match('/^\/(?:tenant|school)\/([a-z0-9-]+)(\/|$)/i', $requestUri, $matches)) {
            return self::getSchoolBySlug($matches[1]);
        }

        return null;
    }

    /**
     * Detect school from session
     * @return array|null
     */
    private static function detectFromSession()
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['school_user']['school_id'])) {
            return self::getSchoolById($_SESSION['school_user']['school_id']);
        }

        return null;
    }

    /**
     * Get school by slug
     * @param string $slug
     * @return array|null
     */
    public static function getSchoolBySlug($slug)
    {
        // Check cache first
        if (isset(self::$schoolCache[$slug])) {
            return self::$schoolCache[$slug];
        }

        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
                SELECT * FROM schools 
                WHERE slug = ? AND status IN ('active', 'trial')
            ");
            $stmt->execute([$slug]);
            $school = $stmt->fetch();

            if ($school) {
                self::$schoolCache[$slug] = $school;
            }

            return $school;
        } catch (Exception $e) {
            self::logError("Failed to get school by slug", $e);
            return null;
        }
    }

    /**
     * Get school by ID
     * @param int $id
     * @return array|null
     */
    public static function getSchoolById($id)
    {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
            SELECT 
                s.*,
                p.name as plan_name,
                p.storage_limit as plan_storage_limit
            FROM schools s
            LEFT JOIN plans p ON s.plan_id = p.id
            WHERE s.id = ? AND s.status IN ('active', 'trial')
        ");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            self::logError("Failed to get school by ID", $e);
            return null;
        }
    }

    /**
     * Get current school
     * @return array|null
     */
    public static function getCurrentSchool()
    {
        return self::detect();
    }

    /**
     * Get current school ID
     * @return int|null
     */
    public static function getCurrentSchoolId()
    {
        $school = self::getCurrentSchool();
        return $school ? $school['id'] : null;
    }

    /**
     * Get current school database connection
     * @return PDO|null
     */
    public static function getSchoolDb()
    {
        if (self::$schoolDb !== null) {
            return self::$schoolDb;
        }

        $school = self::getCurrentSchool();
        if (!$school || empty($school['database_name'])) {
            return null;
        }

        try {
            self::$schoolDb = Database::getSchoolConnection($school['database_name']);
            return self::$schoolDb;
        } catch (Exception $e) {
            self::logError("Failed to get school DB connection", $e);
            return null;
        }
    }

    /**
     * Create new school database with ALL tables including new features
     * @param array $schoolData Must contain: id, admin_name, admin_email, admin_phone, admin_password
     *                          and optional address fields for campus
     * @return array [success, message, database_name, admin_user_id]
     */
    public static function createSchoolDatabase($schoolData)
    {
        try {
            // Validate required data
            $requiredFields = ['id', 'admin_name', 'admin_email', 'admin_phone', 'admin_password'];
            foreach ($requiredFields as $field) {
                if (!isset($schoolData[$field]) || empty($schoolData[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            // Generate database name based on school ID
            $dbName = DB_SCHOOL_PREFIX . $schoolData['id'];
            self::logInfo("Creating school database: " . $dbName);

            // Check subscription limits before creating
            if (!self::checkSubscriptionLimits($schoolData['id'])) {
                return [
                    'success' => false,
                    'message' => 'Subscription limit reached. Please upgrade your plan.'
                ];
            }

            // Create database
            $result = Database::createSchoolDatabase($dbName);
            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Failed to create database'
                ];
            }

            // Get school database connection
            $schoolDb = Database::getSchoolConnection($dbName);
            self::logInfo("School database connection established");

            // Disable foreign key checks temporarily
            $schoolDb->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Create ALL tables (no data yet)
            self::createTablesOnly($schoolDb, $schoolData['id']);

            // Re-enable foreign key checks
            $schoolDb->exec("SET FOREIGN_KEY_CHECKS = 1");

            // Insert default campus record (needed for roles and admin)
            $campusId = self::insertDefaultCampus($schoolDb, $schoolData['id'], $schoolData);
            if (!$campusId) {
                throw new Exception("Failed to create default campus");
            }

            // Insert default data (roles, settings, etc.) – now with campus_id available
            self::insertDefaultData($schoolDb, $schoolData['id'], $campusId);

            // Create initial admin user (with campus_id)
            $adminUserId = self::createInitialAdmin($schoolDb, $schoolData, $campusId);
            if (!$adminUserId) {
                throw new Exception("Failed to create admin user");
            }

            // Initialize subscription and billing data
            self::initializeSubscriptionData($schoolDb, $schoolData['id']);

            // Create initial backup
            self::createInitialBackup($schoolData['id']);

            // Log the created tables
            $tables = $schoolDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            self::logInfo("Total tables created in " . $dbName . ": " . count($tables));

            // Log performance metrics
            self::logPerformanceMetric('database_creation', $schoolData['id'], [
                'tables_created' => count($tables),
                'database_name' => $dbName
            ]);

            return [
                'success' => true,
                'message' => 'School database created successfully',
                'database_name' => $dbName,
                'admin_user_id' => $adminUserId
            ];

        } catch (Exception $e) {
            self::logError("Failed to create school database", $e);
            return [
                'success' => false,
                'message' => 'Failed to create school database: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Insert default campus record for the new school
     * @param PDO $db
     * @param int $schoolId
     * @param array $schoolData
     * @return int|false Campus ID
     */
    private static function insertDefaultCampus($db, $schoolId, $schoolData)
    {
        try {
            // Generate campus code if not provided
            $campusCode = isset($schoolData['campus_code']) ? $schoolData['campus_code'] : 'MAIN001';
            $campusName = "Main Campus";

            $stmt = $db->prepare("
                INSERT INTO campuses 
                (school_id, name, code, address, city, state, country, phone, email, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");

            $stmt->execute([
                $schoolId,
                $campusName,
                $campusCode,
                $schoolData['address'] ?? '',
                $schoolData['city'] ?? '',
                $schoolData['state'] ?? '',
                $schoolData['country'] ?? 'Nigeria',
                $schoolData['phone'] ?? '',
                $schoolData['email'] ?? ''
            ]);

            $campusId = $db->lastInsertId();
            self::logInfo("Default campus inserted with ID: " . $campusId);
            return $campusId;
        } catch (Exception $e) {
            self::logError("Failed to insert default campus", $e);
            return false;
        }
    }

    /**
     * Create ALL tables without inserting default data
     * @param PDO $db
     * @param int $schoolId
     */
    private static function createTablesOnly($db, $schoolId)
    {
        self::logInfo("Creating tables for school ID: " . $schoolId);

        // Array of ALL table creation SQL (120 tables)
        $tables = [
            // Campuses must exist before tables with campus foreign keys.
            self::getCampusesTableSql(),

            // Core Educational Tables
            self::getAcademicTermsTableSql(),
            self::getAcademicYearsTableSql(),
            self::getAnnouncementsTableSql(),
            self::getAttendanceTableSql(),
            self::getClassesTableSql(),
            self::getClassSubjectsTableSql(),
            self::getEventsTableSql(),
            self::getExamsTableSql(),
            self::getExamGradesTableSql(),
            self::getFeeCategoriesTableSql(),
            self::getFeeStructuresTableSql(),
            self::getGuardiansTableSql(),
            self::getHomeworkTableSql(),
            self::getInvoicesTableSql(),
            self::getInvoiceItemsTableSql(),
            self::getPaymentsTableSql(),
            self::getRolesTableSql(),
            self::getSectionsTableSql(),
            self::getSettingsTableSql(),
            self::getStudentsTableSql(),
            self::getSubjectsTableSql(),
            self::getTeachersTableSql(),
            self::getTimetablesTableSql(),
            self::getUsersTableSql(),
            self::getUserRolesTableSql(),

            // Hostel Module
            self::getHostelsTableSql(),
            self::getHostelRoomsTableSql(),
            self::getHostelBedsTableSql(),
            self::getHostelAssignmentsTableSql(),
            self::getHostelStaffTableSql(),

            // Transport Module
            self::getTransportRoutesTableSql(),
            self::getTransportStopsTableSql(),
            self::getTransportVehiclesTableSql(),
            self::getTransportAssignmentsTableSql(),
            self::getStudentTransportTableSql(),

            // Health Module
            self::getStudentHealthRecordsTableSql(),
            self::getSickVisitsTableSql(),
            self::getVaccinationsTableSql(),

            // Inventory Module
            self::getInventoryCategoriesTableSql(),
            self::getInventoryItemsTableSql(),
            self::getInventoryMovementsTableSql(),

            // Alumni Module
            self::getAlumniTableSql(),
            self::getAlumniDonationsTableSql(),
            self::getAlumniEventsTableSql(),

            // Admission Module
            self::getAdmissionApplicationsTableSql(),
            self::getAdmissionDocumentsTableSql(),

            // Curriculum & Lesson Planning
            self::getCurriculumOutlinesTableSql(),
            self::getLessonPlansTableSql(),

            // Assessments
            self::getAssessmentTypesTableSql(),
            self::getAssessmentsTableSql(),
            self::getAssessmentScoresTableSql(),

            // Discipline
            self::getIncidentsTableSql(),
            self::getIncidentStudentsTableSql(),
            self::getDisciplineActionsTableSql(),

            // Parent-Teacher Meetings
            self::getMeetingSlotsTableSql(),
            self::getMeetingBookingsTableSql(),

            // CBT / Exam
            self::getCbtTestsTableSql(),
            self::getCbtQuestionsTableSql(),
            self::getCbtOptionsTableSql(),
            self::getCbtAttemptsTableSql(),
            self::getCbtResponsesTableSql(),
            self::getCbtResultsTableSql(),

            // Voting
            self::getVotingElectionsTableSql(),
            self::getVotingPositionsTableSql(),
            self::getVotingCandidatesTableSql(),
            self::getVotingVotesTableSql(),
            self::getVotingResultsTableSql(),

            // Enhanced Feature Tables (System)
            self::getSubscriptionsTableSql(),
            self::getBillingHistoryTableSql(),
            self::getPaymentMethodsTableSql(),
            self::getInvoicesV2TableSql(),
            self::getStorageUsageTableSql(),
            self::getFileStorageTableSql(),
            self::getPerformanceMetricsTableSql(),
            self::getApiLogsTableSql(),
            self::getAuditLogsTableSql(),
            self::getSecurityLogsTableSql(),
            self::getRateLimitsTableSql(),
            self::getLoginAttemptsTableSql(),
            self::getBackupHistoryTableSql(),
            self::getRecoveryPointsTableSql(),
            self::getNotificationsTableSql(),
            self::getEmailTemplatesTableSql(),
            self::getSmsLogsTableSql(),
            self::getWhatsAppNotificationsTableSql(),
            self::getApiKeysTableSql(),
            self::getApiUsageTableSql(),
            self::getMaintenanceLogsTableSql(),
            self::getSystemAlertsTableSql(),

            // NEW MISSING TABLES (from original dump)
            // Certificates
            self::getCertificateTemplatesTableSql(),
            self::getCertificatesIssuedTableSql(),

            // Messaging
            self::getConversationsTableSql(),
            self::getConversationParticipantsTableSql(),
            self::getMessagesTableSql(),
            self::getMessageAttachmentsTableSql(),
            self::getMessageBlocksTableSql(),
            self::getMessageDraftsTableSql(),
            self::getMessageReactionsTableSql(),
            self::getMessageStatusTableSql(),

            // Exams (additional)
            self::getExamPapersTableSql(),
            self::getExamQuestionsTableSql(),
            self::getExamOptionsTableSql(),

            // Geofence
            self::getGeofenceLogsTableSql(),

            // Leave
            self::getLeaveTypesTableSql(),
            self::getLeaveRequestsTableSql(),

            // Library
            self::getLibraryCategoriesTableSql(),
            self::getLibraryBooksTableSql(),
            self::getLibraryMembersTableSql(),
            self::getLibraryIssuesTableSql(),
            self::getLibraryReservationsTableSql(),
            self::getLibraryFineSettingsTableSql(),

            // Payroll
            self::getPayrollSalaryGradesTableSql(),
            self::getPayrollEmployeesTableSql(),
            self::getPayrollAllowancesTableSql(),
            self::getPayrollDeductionsTableSql(),
            self::getPayrollPeriodsTableSql(),
            self::getPayrollRunsTableSql(),
            self::getPayrollSlipsTableSql(),

            // Academics
            self::getReportCardsTableSql(),
            self::getStudentPromotionsTableSql(),

            // Staff
            self::getStaffAttendanceTableSql(),
        ];

        // Create each table
        $createdCount = 0;
        foreach ($tables as $sql) {
            try {
                $db->exec($sql);
                $createdCount++;

                // Extract table name for logging
                preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/', $sql, $matches);
                if (isset($matches[1])) {
                    self::logInfo("Created table: " . $matches[1]);
                }
            } catch (Exception $e) {
                self::logWarning("Error creating table (continuing): " . $e->getMessage());
                // Continue with other tables
            }
        }

        self::logInfo("Created " . $createdCount . " tables successfully");
    }

    /**
     * Insert default data into new school database
     * @param PDO $db
     * @param int $schoolId
     * @param int $campusId
     */
    private static function insertDefaultData($db, $schoolId, $campusId)
    {
        try {
            // Insert default roles (with campus_id)
            $db->exec("INSERT IGNORE INTO `roles` (`school_id`, `campus_id`, `name`, `slug`, `description`, `permissions`, `is_system`, `created_at`) VALUES
                ($schoolId, $campusId, 'Super Administrator', 'super_admin', 'Has full access to all features', '[\"*\"]', 1, NOW()),
                ($schoolId, $campusId, 'School Administrator', 'school_admin', 'Manages school operations', '[\"dashboard.view\", \"students.*\", \"teachers.*\", \"classes.*\", \"attendance.*\", \"exams.*\", \"fees.*\", \"reports.*\", \"settings.*\"]', 1, NOW()),
                ($schoolId, $campusId, 'Teacher', 'teacher', 'Can manage classes and students', '[\"dashboard.view\", \"attendance.mark\", \"grades.enter\", \"homework.*\", \"students.view\"]', 1, NOW()),
                ($schoolId, $campusId, 'Student', 'student', 'Can view their own information', '[\"dashboard.view\", \"timetable.view\", \"grades.view\", \"homework.view\"]', 1, NOW()),
                ($schoolId, $campusId, 'Parent', 'parent', 'Can view child information', '[\"dashboard.view\", \"children.view\", \"attendance.view\", \"fees.view\"]', 1, NOW()),
                ($schoolId, $campusId, 'Accountant', 'accountant', 'Manages financial operations', '[\"dashboard.view\", \"fees.*\", \"payments.*\", \"invoices.*\", \"reports.financial\"]', 1, NOW()),
                ($schoolId, $campusId, 'Librarian', 'librarian', 'Manages library operations', '[\"dashboard.view\", \"library.*\"]', 1, NOW())");

            // Insert default settings (campus_id not needed)
            $db->exec("INSERT IGNORE INTO `settings` (`school_id`, `key`, `value`, `type`, `category`, `created_at`, `updated_at`) VALUES
                ($schoolId, 'school_name', 'New School', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'school_email', '', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'school_phone', '', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'school_address', '', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'currency', 'NGN', 'string', 'financial', NOW(), NOW()),
                ($schoolId, 'currency_symbol', '₦', 'string', 'financial', NOW(), NOW()),
                ($schoolId, 'attendance_method', 'daily', 'string', 'academic', NOW(), NOW()),
                ($schoolId, 'grading_system', 'percentage', 'string', 'academic', NOW(), NOW()),
                ($schoolId, 'result_publish', 'immediate', 'string', 'academic', NOW(), NOW()),
                ($schoolId, 'fee_due_days', '30', 'number', 'financial', NOW(), NOW()),
                ($schoolId, 'late_fee_percentage', '5', 'number', 'financial', NOW(), NOW())");

            // Insert default subscription plan (Free tier)
            $db->exec("INSERT IGNORE INTO `subscriptions` (`school_id`, `plan_id`, `plan_name`, `status`, `billing_cycle`, `amount`, `storage_limit`, `user_limit`, `student_limit`, `current_period_start`, `current_period_end`, `created_at`) VALUES
                ($schoolId, 'free_tier', 'Free Plan', 'active', 'monthly', 0.00, 1073741824, 100, 500, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), NOW())");

            // Insert default storage usage
            $db->exec("INSERT IGNORE INTO `storage_usage` (`school_id`, `storage_type`, `used_bytes`, `limit_bytes`, `created_at`) VALUES
                ($schoolId, 'database', 0, 1073741824, NOW()),
                ($schoolId, 'files', 0, 1073741824, NOW()),
                ($schoolId, 'backups', 0, 536870912, NOW()),
                ($schoolId, 'attachments', 0, 536870912, NOW())");

            // Insert default leave types
            $db->exec("INSERT IGNORE INTO `leave_types` (`school_id`, `campus_id`, `name`, `description`, `max_days_per_year`, `applicable_to`, `is_paid`, `is_active`, `created_at`) VALUES
                ($schoolId, $campusId, 'Annual Leave', 'Paid annual leave', 21, 'all', 1, 1, NOW()),
                ($schoolId, $campusId, 'Sick Leave', 'Paid sick leave', 14, 'all', 1, 1, NOW()),
                ($schoolId, $campusId, 'Maternity Leave', 'Maternity leave', 90, 'teacher', 1, 1, NOW()),
                ($schoolId, $campusId, 'Unpaid Leave', 'Leave without pay', NULL, 'all', 0, 1, NOW())");

            // Insert default library categories
            $db->exec("INSERT IGNORE INTO `library_categories` (`school_id`, `campus_id`, `name`, `description`, `created_at`) VALUES
                ($schoolId, $campusId, 'Fiction', 'Fictional books', NOW()),
                ($schoolId, $campusId, 'Non-Fiction', 'Non-fictional books', NOW()),
                ($schoolId, $campusId, 'Science', 'Science books', NOW()),
                ($schoolId, $campusId, 'History', 'History books', NOW()),
                ($schoolId, $campusId, 'Biography', 'Biographies', NOW())");

            // Insert default library fine settings
            $db->exec("INSERT IGNORE INTO `library_fine_settings` (`school_id`, `fine_per_day`, `max_fine`, `grace_days`, `created_at`, `updated_at`) VALUES
                ($schoolId, 50.00, 2000.00, 3, NOW(), NOW())");

            // Insert default payroll salary grades
            $db->exec("INSERT IGNORE INTO `payroll_salary_grades` (`school_id`, `grade_name`, `basic_salary`, `house_allowance`, `transport_allowance`, `medical_allowance`, `other_allowances`, `description`, `is_active`, `created_at`) VALUES
                ($schoolId, 'Entry Level', 50000.00, 0.00, 0.00, 0.00, 0.00, 'Entry level staff', 1, NOW()),
                ($schoolId, 'Junior Staff', 80000.00, 0.00, 0.00, 0.00, 0.00, 'Junior staff', 1, NOW()),
                ($schoolId, 'Senior Staff', 120000.00, 0.00, 0.00, 0.00, 0.00, 'Senior staff', 1, NOW()),
                ($schoolId, 'Management', 200000.00, 0.00, 0.00, 0.00, 0.00, 'Management', 1, NOW())");

            self::logInfo("Inserted default data for school ID: " . $schoolId);
            return true;
        } catch (Exception $e) {
            self::logError("Error inserting default data", $e);
            return false;
        }
    }

    // =================================================================
    // TABLE DEFINITION METHODS (120 tables)
    // =================================================================

    // ----- Core Educational Tables -----
    private static function getAcademicTermsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `academic_terms` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_term_school` (`school_id`,`academic_year_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_year` (`academic_year_id`),
            CONSTRAINT `fk_academic_terms_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAcademicYearsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `academic_years` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `status` enum('upcoming','active','completed') DEFAULT 'upcoming',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_year_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_academic_years_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAnnouncementsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `announcements` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text NOT NULL,
            `target` enum('all','students','teachers','parents','class','section') DEFAULT 'all',
            `class_id` int(10) UNSIGNED DEFAULT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 1,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `class_id` (`class_id`),
            KEY `section_id` (`section_id`),
            KEY `created_by` (`created_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_published` (`is_published`),
            KEY `idx_dates` (`start_date`,`end_date`),
            CONSTRAINT `fk_announcements_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAttendanceTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `attendance` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `date` date NOT NULL,
            `status` enum('present','absent','late','half_day','holiday','sunday') NOT NULL,
            `remark` varchar(255) DEFAULT NULL,
            `marked_by` int(10) UNSIGNED DEFAULT NULL,
            `session` enum('morning','afternoon','full_day') DEFAULT 'full_day',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_attendance` (`student_id`,`date`,`session`),
            KEY `marked_by` (`marked_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_date` (`date`),
            KEY `idx_class` (`class_id`),
            KEY `idx_attendance_student_date` (`student_id`,`date`),
            CONSTRAINT `fk_attendance_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getClassesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `classes` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `description` text DEFAULT NULL,
            `grade_level` varchar(50) DEFAULT NULL,
            `class_teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `capacity` int(10) UNSIGNED DEFAULT 40,
            `room_number` varchar(50) DEFAULT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_class_school` (`school_id`,`academic_year_id`,`code`),
            KEY `class_teacher_id` (`class_teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_year` (`academic_year_id`),
            CONSTRAINT `fk_classes_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getClassSubjectsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `class_subjects` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_class_subject` (`class_id`,`subject_id`),
            KEY `subject_id` (`subject_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_teacher` (`teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_class_subjects_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getEventsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `events` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `type` enum('holiday','exam','meeting','celebration','sports','other') DEFAULT 'other',
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `start_time` time DEFAULT NULL,
            `end_time` time DEFAULT NULL,
            `venue` varchar(255) DEFAULT NULL,
            `is_public` tinyint(1) DEFAULT 1,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_dates` (`start_date`,`end_date`),
            KEY `idx_type` (`type`),
            CONSTRAINT `fk_events_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exams` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_exam_school` (`school_id`,`academic_year_id`,`academic_term_id`,`name`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_year` (`academic_year_id`),
            CONSTRAINT `fk_exams_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamGradesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exam_grades` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `exam_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `marks_obtained` decimal(5,2) DEFAULT NULL,
            `total_marks` decimal(5,2) NOT NULL,
            `grade` varchar(5) DEFAULT NULL,
            `remarks` varchar(255) DEFAULT NULL,
            `entered_by` int(10) UNSIGNED DEFAULT NULL,
            `entered_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `is_published` tinyint(1) DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_exam_grade` (`exam_id`,`student_id`,`subject_id`),
            KEY `class_id` (`class_id`),
            KEY `entered_by` (`entered_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_exam` (`exam_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_subject` (`subject_id`),
            KEY `idx_exam_grades_exam_student` (`exam_id`,`student_id`),
            CONSTRAINT `fk_exam_grades_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFeeCategoriesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `fee_categories` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_category_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_fee_categories_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFeeStructuresTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `fee_structures` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `fee_category_id` int(10) UNSIGNED NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `due_date` date DEFAULT NULL,
            `late_fee` decimal(10,2) DEFAULT 0.00,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_fee_structure` (`academic_year_id`,`academic_term_id`,`class_id`,`fee_category_id`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `class_id` (`class_id`),
            KEY `fee_category_id` (`fee_category_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_year` (`academic_year_id`),
            CONSTRAINT `fk_fee_structures_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getGuardiansTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `guardians` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `relationship` enum('father','mother','brother','sister','uncle','aunt','grandfather','grandmother','guardian','other') NOT NULL,
            `is_primary` tinyint(1) DEFAULT 0,
            `can_pickup` tinyint(1) DEFAULT 1,
            `emergency_contact` tinyint(1) DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_guardian_student` (`student_id`,`user_id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_primary` (`is_primary`),
            CONSTRAINT `fk_guardians_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHomeworkTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `homework` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `attachment` varchar(500) DEFAULT NULL,
            `due_date` date NOT NULL,
            `submission_type` enum('online','offline') DEFAULT 'offline',
            `max_marks` decimal(5,2) DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `section_id` (`section_id`),
            KEY `subject_id` (`subject_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_teacher` (`teacher_id`),
            CONSTRAINT `fk_homework_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoicesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoices` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `invoice_number` varchar(100) NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `issue_date` date NOT NULL,
            `due_date` date NOT NULL,
            `total_amount` decimal(10,2) NOT NULL,
            `discount` decimal(10,2) DEFAULT 0.00,
            `late_fee` decimal(10,2) DEFAULT 0.00,
            `paid_amount` decimal(10,2) DEFAULT 0.00,
            `balance_amount` decimal(10,2) NOT NULL,
            `status` enum('draft','pending','partial','paid','overdue','cancelled') DEFAULT 'pending',
            `payment_method` varchar(50) DEFAULT NULL,
            `paid_at` timestamp NULL DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `academic_year_id` (`academic_year_id`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `class_id` (`class_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_status` (`status`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_invoices_student_status` (`student_id`,`status`),
            CONSTRAINT `fk_invoices_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoiceItemsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoice_items` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `invoice_id` int(10) UNSIGNED NOT NULL,
            `fee_category_id` int(10) UNSIGNED NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `fee_category_id` (`fee_category_id`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_invoice_items_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPaymentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `invoice_id` int(10) UNSIGNED NOT NULL,
            `payment_number` varchar(100) NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `payment_method` enum('cash','cheque','bank_transfer','card','mobile_money','online') NOT NULL,
            `payment_date` date NOT NULL,
            `collected_by` int(10) UNSIGNED DEFAULT NULL,
            `bank_name` varchar(255) DEFAULT NULL,
            `cheque_number` varchar(100) DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `reference` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `payment_number` (`payment_number`),
            KEY `collected_by` (`collected_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_payment_date` (`payment_date`),
            KEY `idx_payments_invoice_date` (`invoice_id`,`payment_date`),
            CONSTRAINT `fk_payments_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRolesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `roles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `slug` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `permissions` text DEFAULT NULL,
            `is_system` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_role_school` (`school_id`,`slug`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_roles_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSectionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `sections` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `room_number` varchar(50) DEFAULT NULL,
            `capacity` int(10) UNSIGNED DEFAULT 40,
            `class_teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_section_class` (`class_id`,`code`),
            KEY `class_teacher_id` (`class_teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_class` (`class_id`),
            CONSTRAINT `fk_sections_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSettingsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `settings` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `key` varchar(100) NOT NULL,
            `value` text DEFAULT NULL,
            `type` varchar(50) DEFAULT 'string',
            `category` varchar(50) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_setting` (`school_id`,`key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_key` (`key`),
            CONSTRAINT `fk_settings_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStudentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `students` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `admission_number` varchar(50) NOT NULL,
            `roll_number` varchar(50) DEFAULT NULL,
            `class_id` int(10) UNSIGNED DEFAULT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `admission_date` date NOT NULL,
            `first_name` varchar(100) NOT NULL,
            `middle_name` varchar(100) DEFAULT NULL,
            `last_name` varchar(100) NOT NULL,
            `date_of_birth` date NOT NULL,
            `birth_place` varchar(255) DEFAULT NULL,
            `nationality` varchar(100) DEFAULT NULL,
            `mother_tongue` varchar(100) DEFAULT NULL,
            `current_address` text DEFAULT NULL,
            `permanent_address` text DEFAULT NULL,
            `previous_school` varchar(255) DEFAULT NULL,
            `previous_class` varchar(100) DEFAULT NULL,
            `transfer_certificate_no` varchar(100) DEFAULT NULL,
            `blood_group` varchar(5) DEFAULT NULL,
            `allergies` text DEFAULT NULL,
            `medical_conditions` text DEFAULT NULL,
            `doctor_name` varchar(255) DEFAULT NULL,
            `doctor_phone` varchar(20) DEFAULT NULL,
            `status` enum('active','inactive','graduated','transferred','withdrawn') DEFAULT 'active',
            `graduation_date` date DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `admission_number` (`admission_number`),
            KEY `user_id` (`user_id`),
            KEY `section_id` (`section_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_admission` (`admission_number`),
            KEY `idx_status` (`status`),
            KEY `idx_students_class_status` (`class_id`,`status`),
            CONSTRAINT `fk_students_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSubjectsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `subjects` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `type` enum('core','elective','extra_curricular') DEFAULT 'core',
            `description` text DEFAULT NULL,
            `credit_hours` decimal(4,1) DEFAULT 1.0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_subject_school` (`school_id`,`code`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_subjects_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTeachersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `teachers` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `employee_id` varchar(50) NOT NULL,
            `qualification` varchar(255) DEFAULT NULL,
            `specialization` varchar(255) DEFAULT NULL,
            `experience_years` int(10) UNSIGNED DEFAULT NULL,
            `joining_date` date DEFAULT NULL,
            `leaving_date` date DEFAULT NULL,
            `salary_grade` varchar(50) DEFAULT NULL,
            `bank_name` varchar(255) DEFAULT NULL,
            `bank_account` varchar(50) DEFAULT NULL,
            `ifsc_code` varchar(20) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `employee_id` (`employee_id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_employee` (`employee_id`),
            CONSTRAINT `fk_teachers_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTimetablesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `timetables` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `day` enum('monday','tuesday','wednesday','thursday','friday','saturday') NOT NULL,
            `period_number` int(10) UNSIGNED NOT NULL,
            `start_time` time NOT NULL,
            `end_time` time NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `room_number` varchar(50) DEFAULT NULL,
            `is_break` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_timetable` (`class_id`,`section_id`,`day`,`period_number`,`academic_year_id`),
            KEY `section_id` (`section_id`),
            KEY `academic_year_id` (`academic_year_id`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `subject_id` (`subject_id`),
            KEY `teacher_id` (`teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_day` (`day`),
            CONSTRAINT `fk_timetables_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getUsersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `users` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(255) NOT NULL,
            `email` varchar(255) DEFAULT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `username` varchar(100) DEFAULT NULL,
            `password` varchar(255) NOT NULL,
            `user_type` enum('admin','teacher','student','parent','accountant','librarian','receptionist') NOT NULL,
            `profile_photo` varchar(500) DEFAULT NULL,
            `gender` enum('male','female','other') DEFAULT NULL,
            `date_of_birth` date DEFAULT NULL,
            `blood_group` varchar(5) DEFAULT NULL,
            `religion` varchar(50) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `email_verified_at` timestamp NULL DEFAULT NULL,
            `phone_verified_at` timestamp NULL DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `last_login_at` timestamp NULL DEFAULT NULL,
            `last_login_ip` varchar(45) DEFAULT NULL,
            `remember_token` varchar(100) DEFAULT NULL,
            `reset_token` varchar(100) DEFAULT NULL,
            `reset_token_expires` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_email_school` (`school_id`,`email`),
            UNIQUE KEY `unique_phone_school` (`school_id`,`phone`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_user_type` (`user_type`),
            KEY `idx_email` (`email`),
            KEY `idx_phone` (`phone`),
            KEY `idx_users_school_type` (`school_id`,`user_type`),
            CONSTRAINT `fk_users_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getUserRolesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `user_roles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `role_id` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
            KEY `role_id` (`role_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_user_roles_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Campuses -----
    private static function getCampusesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `campuses` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `address` text DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `state` varchar(100) DEFAULT NULL,
            `country` varchar(100) DEFAULT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `email` varchar(255) DEFAULT NULL,
            `latitude` decimal(10,8) DEFAULT NULL,
            `longitude` decimal(11,8) DEFAULT NULL,
            `radius` int(10) UNSIGNED DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_campus_code` (`school_id`,`code`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Hostel Module -----
    private static function getHostelsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `hostels` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `description` text DEFAULT NULL,
            `capacity` int(10) UNSIGNED DEFAULT NULL,
            `gender` enum('male','female','co-ed') DEFAULT 'co-ed',
            `address` text DEFAULT NULL,
            `facilities` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_hostel_code` (`school_id`,`campus_id`,`code`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_hostels_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHostelRoomsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `hostel_rooms` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `hostel_id` int(10) UNSIGNED NOT NULL,
            `room_number` varchar(20) NOT NULL,
            `floor` varchar(10) DEFAULT NULL,
            `capacity` int(10) UNSIGNED NOT NULL,
            `gender` enum('male','female','co-ed') DEFAULT 'co-ed',
            `class_id` int(10) UNSIGNED DEFAULT NULL,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_room` (`hostel_id`,`room_number`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_hostel` (`hostel_id`),
            KEY `idx_class` (`class_id`),
            CONSTRAINT `fk_hostel_rooms_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_rooms_hostel` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_rooms_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHostelBedsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `hostel_beds` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `hostel_id` int(10) UNSIGNED NOT NULL,
            `room_id` int(10) UNSIGNED NOT NULL,
            `bed_number` varchar(20) NOT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_bed_in_room` (`room_id`,`bed_number`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_hostel` (`hostel_id`),
            KEY `idx_room` (`room_id`),
            CONSTRAINT `fk_hostel_beds_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_beds_hostel` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_beds_room` FOREIGN KEY (`room_id`) REFERENCES `hostel_rooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHostelAssignmentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `hostel_assignments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `hostel_id` int(10) UNSIGNED NOT NULL,
            `bed_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `status` enum('active','inactive','transferred') DEFAULT 'active',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_active_bed` (`bed_id`,`start_date`),
            UNIQUE KEY `unique_active_student` (`student_id`,`hostel_id`,`start_date`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_hostel` (`hostel_id`),
            KEY `idx_bed` (`bed_id`),
            KEY `idx_student` (`student_id`),
            CONSTRAINT `fk_hostel_assignments_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_assignments_hostel` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_assignments_bed` FOREIGN KEY (`bed_id`) REFERENCES `hostel_beds` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_assignments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHostelStaffTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `hostel_staff` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `hostel_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `role` enum('master','mistress','assistant','other') NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_active_staff` (`hostel_id`,`user_id`,`start_date`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_hostel` (`hostel_id`),
            KEY `idx_user` (`user_id`),
            CONSTRAINT `fk_hostel_staff_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_staff_hostel` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_hostel_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Transport Module -----
    private static function getTransportRoutesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `transport_routes` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `route_name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `start_point` varchar(255) NOT NULL,
            `end_point` varchar(255) NOT NULL,
            `distance_km` decimal(5,2) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_route` (`school_id`,`campus_id`,`route_name`),
            KEY `idx_school_campus` (`school_id`,`campus_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTransportStopsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `transport_stops` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `route_id` int(10) UNSIGNED NOT NULL,
            `stop_name` varchar(100) NOT NULL,
            `address` text DEFAULT NULL,
            `latitude` decimal(10,8) DEFAULT NULL,
            `longitude` decimal(11,8) DEFAULT NULL,
            `stop_order` int(10) UNSIGNED DEFAULT 0,
            `pickup_time` time DEFAULT NULL,
            `dropoff_time` time DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_route` (`route_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_transport_stops_route` FOREIGN KEY (`route_id`) REFERENCES `transport_routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTransportVehiclesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `transport_vehicles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `vehicle_number` varchar(50) NOT NULL,
            `registration_number` varchar(50) DEFAULT NULL,
            `type` enum('bus','van','car') DEFAULT 'bus',
            `capacity` int(10) UNSIGNED NOT NULL,
            `driver_name` varchar(255) DEFAULT NULL,
            `driver_phone` varchar(20) DEFAULT NULL,
            `driver_license` varchar(100) DEFAULT NULL,
            `assistant_name` varchar(255) DEFAULT NULL,
            `assistant_phone` varchar(20) DEFAULT NULL,
            `insurance_expiry` date DEFAULT NULL,
            `fitness_expiry` date DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_vehicle` (`school_id`,`campus_id`,`vehicle_number`),
            KEY `idx_school_campus` (`school_id`,`campus_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTransportAssignmentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `transport_assignments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `vehicle_id` int(10) UNSIGNED NOT NULL,
            `route_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `morning_driver` varchar(255) DEFAULT NULL,
            `evening_driver` varchar(255) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_assignment` (`vehicle_id`,`route_id`,`academic_term_id`,`start_date`),
            KEY `idx_route` (`route_id`),
            KEY `idx_term` (`academic_term_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_transport_assignments_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `transport_vehicles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_transport_assignments_route` FOREIGN KEY (`route_id`) REFERENCES `transport_routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_transport_assignments_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStudentTransportTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `student_transport` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `assignment_id` int(10) UNSIGNED NOT NULL,
            `stop_id` int(10) UNSIGNED NOT NULL,
            `fee` decimal(10,2) DEFAULT NULL,
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_student_transport` (`student_id`,`assignment_id`,`start_date`),
            KEY `idx_assignment` (`assignment_id`),
            KEY `idx_stop` (`stop_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_student_transport_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_transport_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `transport_assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_transport_stop` FOREIGN KEY (`stop_id`) REFERENCES `transport_stops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Health Module -----
    private static function getStudentHealthRecordsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `student_health_records` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `blood_group` varchar(5) DEFAULT NULL,
            `allergies` text DEFAULT NULL,
            `chronic_conditions` text DEFAULT NULL,
            `disabilities` text DEFAULT NULL,
            `emergency_contact_name` varchar(255) DEFAULT NULL,
            `emergency_contact_phone` varchar(20) DEFAULT NULL,
            `emergency_contact_relation` varchar(50) DEFAULT NULL,
            `doctor_name` varchar(255) DEFAULT NULL,
            `doctor_phone` varchar(20) DEFAULT NULL,
            `doctor_address` text DEFAULT NULL,
            `insurance_provider` varchar(255) DEFAULT NULL,
            `insurance_policy` varchar(100) DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_student` (`student_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_student_health_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_health_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSickVisitsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `sick_visits` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `visit_date` date NOT NULL,
            `visit_time` time DEFAULT NULL,
            `symptoms` text NOT NULL,
            `temperature` decimal(4,2) DEFAULT NULL,
            `weight` decimal(5,2) DEFAULT NULL,
            `diagnosis` text DEFAULT NULL,
            `treatment` text DEFAULT NULL,
            `medication_given` text DEFAULT NULL,
            `referred_to` varchar(255) DEFAULT NULL,
            `follow_up_date` date DEFAULT NULL,
            `attended_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_date` (`visit_date`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_sick_visits_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_sick_visits_attended_by` FOREIGN KEY (`attended_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_sick_visits_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getVaccinationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `vaccinations` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `vaccine_name` varchar(100) NOT NULL,
            `date_administered` date NOT NULL,
            `next_due_date` date DEFAULT NULL,
            `administered_by` varchar(255) DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_vaccinations_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_vaccinations_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Inventory Module -----
    private static function getInventoryCategoriesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `inventory_categories` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_category` (`school_id`,`campus_id`,`name`),
            KEY `idx_school_campus` (`school_id`,`campus_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInventoryItemsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `inventory_items` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `category_id` int(10) UNSIGNED DEFAULT NULL,
            `item_code` varchar(50) NOT NULL,
            `item_name` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `unit` varchar(50) DEFAULT NULL,
            `unit_price` decimal(10,2) DEFAULT NULL,
            `quantity_in_stock` decimal(10,2) DEFAULT 0.00,
            `minimum_quantity` decimal(10,2) DEFAULT 0.00,
            `maximum_quantity` decimal(10,2) DEFAULT NULL,
            `reorder_level` decimal(10,2) DEFAULT NULL,
            `location` varchar(100) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_item` (`school_id`,`campus_id`,`item_code`),
            KEY `idx_category` (`category_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_inventory_items_category` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_inventory_items_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInventoryMovementsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `inventory_movements` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `item_id` int(10) UNSIGNED NOT NULL,
            `movement_type` enum('receipt','issue','adjustment','return') NOT NULL,
            `quantity` decimal(10,2) NOT NULL,
            `unit_price` decimal(10,2) DEFAULT NULL,
            `reference` varchar(255) DEFAULT NULL,
            `description` text DEFAULT NULL,
            `issued_to` varchar(255) DEFAULT NULL,
            `issued_to_user_id` int(10) UNSIGNED DEFAULT NULL,
            `movement_date` date NOT NULL,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_item` (`item_id`),
            KEY `idx_movement_type` (`movement_type`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_inventory_movements_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_inventory_movements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_inventory_movements_issued_to` FOREIGN KEY (`issued_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_inventory_movements_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Alumni Module -----
    private static function getAlumniTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `alumni` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED DEFAULT NULL,
            `first_name` varchar(100) NOT NULL,
            `middle_name` varchar(100) DEFAULT NULL,
            `last_name` varchar(100) NOT NULL,
            `graduation_year` year(4) NOT NULL,
            `email` varchar(255) DEFAULT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `occupation` varchar(255) DEFAULT NULL,
            `company` varchar(255) DEFAULT NULL,
            `profile_photo` varchar(500) DEFAULT NULL,
            `is_visible` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_student` (`student_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_alumni_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_alumni_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAlumniDonationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `alumni_donations` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `alumni_id` int(10) UNSIGNED NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `donation_date` date NOT NULL,
            `purpose` varchar(255) DEFAULT NULL,
            `payment_method` varchar(50) DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_alumni` (`alumni_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_alumni_donations_alumni` FOREIGN KEY (`alumni_id`) REFERENCES `alumni` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_alumni_donations_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAlumniEventsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `alumni_events` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `event_name` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `event_date` date NOT NULL,
            `venue` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_alumni_events_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Admission Module -----
    private static function getAdmissionApplicationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `admission_applications` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `application_number` varchar(50) NOT NULL,
            `first_name` varchar(100) NOT NULL,
            `middle_name` varchar(100) DEFAULT NULL,
            `last_name` varchar(100) NOT NULL,
            `date_of_birth` date NOT NULL,
            `gender` enum('male','female','other') NOT NULL,
            `nationality` varchar(100) DEFAULT NULL,
            `religion` varchar(50) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `email` varchar(255) DEFAULT NULL,
            `applying_for_class_id` int(10) UNSIGNED NOT NULL,
            `previous_school` varchar(255) DEFAULT NULL,
            `previous_class` varchar(100) DEFAULT NULL,
            `father_name` varchar(255) DEFAULT NULL,
            `father_phone` varchar(20) DEFAULT NULL,
            `mother_name` varchar(255) DEFAULT NULL,
            `mother_phone` varchar(20) DEFAULT NULL,
            `guardian_name` varchar(255) DEFAULT NULL,
            `guardian_phone` varchar(20) DEFAULT NULL,
            `status` enum('pending','reviewed','accepted','rejected','waitlisted') DEFAULT 'pending',
            `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
            `reviewed_at` timestamp NULL DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_application` (`school_id`,`application_number`),
            KEY `idx_status` (`status`),
            KEY `idx_class` (`applying_for_class_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_admission_applications_class` FOREIGN KEY (`applying_for_class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_admission_applications_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAdmissionDocumentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `admission_documents` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `application_id` int(10) UNSIGNED NOT NULL,
            `document_type` varchar(100) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_application` (`application_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_admission_documents_application` FOREIGN KEY (`application_id`) REFERENCES `admission_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_admission_documents_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Curriculum & Lesson Planning -----
    private static function getCurriculumOutlinesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `curriculum_outlines` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `term_id` int(10) UNSIGNED NOT NULL,
            `week` int(10) UNSIGNED DEFAULT NULL,
            `topic` varchar(255) NOT NULL,
            `objectives` text DEFAULT NULL,
            `resources` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_class_subject` (`class_id`,`subject_id`),
            KEY `idx_term` (`term_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_curriculum_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_curriculum_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_curriculum_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_curriculum_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLessonPlansTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `lesson_plans` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `term_id` int(10) UNSIGNED NOT NULL,
            `week` int(10) UNSIGNED DEFAULT NULL,
            `topic` varchar(255) NOT NULL,
            `objectives` text DEFAULT NULL,
            `materials` text DEFAULT NULL,
            `procedure` text DEFAULT NULL,
            `assessment` text DEFAULT NULL,
            `homework` text DEFAULT NULL,
            `attachment` varchar(500) DEFAULT NULL,
            `is_approved` tinyint(1) DEFAULT 0,
            `approved_by` int(10) UNSIGNED DEFAULT NULL,
            `approved_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_class_subject` (`class_id`,`subject_id`),
            KEY `idx_teacher` (`teacher_id`),
            KEY `idx_term` (`term_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_lesson_plans_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_lesson_plans_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_lesson_plans_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_lesson_plans_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_lesson_plans_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_lesson_plans_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_lesson_plans_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Assessments -----
    private static function getAssessmentTypesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `assessment_types` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `weight` decimal(5,2) DEFAULT NULL,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_name` (`school_id`,`name`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_assessment_types_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAssessmentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `assessments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `term_id` int(10) UNSIGNED NOT NULL,
            `assessment_type_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `max_score` decimal(5,2) NOT NULL,
            `date` date NOT NULL,
            `is_published` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_class_subject` (`class_id`,`subject_id`),
            KEY `idx_teacher` (`teacher_id`),
            KEY `idx_term` (`term_id`),
            KEY `idx_type` (`assessment_type_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_assessments_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessments_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_assessments_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessments_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessments_type` FOREIGN KEY (`assessment_type_id`) REFERENCES `assessment_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessments_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAssessmentScoresTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `assessment_scores` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `assessment_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `score` decimal(5,2) NOT NULL,
            `remarks` varchar(255) DEFAULT NULL,
            `entered_by` int(10) UNSIGNED NOT NULL,
            `entered_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_score` (`assessment_id`,`student_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_assessment_scores_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessment_scores_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessment_scores_entered_by` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessment_scores_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Discipline -----
    private static function getIncidentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `incidents` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `incident_date` date NOT NULL,
            `incident_time` time DEFAULT NULL,
            `location` varchar(255) DEFAULT NULL,
            `description` text NOT NULL,
            `reported_by` int(10) UNSIGNED NOT NULL,
            `status` enum('open','investigating','resolved','closed') DEFAULT 'open',
            `action_taken` text DEFAULT NULL,
            `resolved_at` timestamp NULL DEFAULT NULL,
            `resolved_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_reported_by` (`reported_by`),
            KEY `idx_status` (`status`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_incidents_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_incidents_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_incidents_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getIncidentStudentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `incident_students` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `incident_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `role` enum('perpetrator','victim','witness') DEFAULT 'perpetrator',
            `statement` text DEFAULT NULL,
            `action_taken` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_incident_student` (`incident_id`,`student_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_incident_students_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_incident_students_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_incident_students_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getDisciplineActionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `discipline_actions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `incident_id` int(10) UNSIGNED DEFAULT NULL,
            `action_type` enum('detention','suspension','expulsion','community_service','warning') NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `description` text DEFAULT NULL,
            `issued_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_incident` (`incident_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_discipline_actions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_discipline_actions_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_discipline_actions_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_discipline_actions_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Parent-Teacher Meetings -----
    private static function getMeetingSlotsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `meeting_slots` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `date` date NOT NULL,
            `start_time` time NOT NULL,
            `end_time` time NOT NULL,
            `max_bookings` int(10) UNSIGNED DEFAULT 1,
            `current_bookings` int(10) UNSIGNED DEFAULT 0,
            `status` enum('available','full','cancelled') DEFAULT 'available',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_slot` (`teacher_id`,`date`,`start_time`),
            KEY `idx_teacher` (`teacher_id`),
            KEY `idx_date` (`date`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_meeting_slots_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_meeting_slots_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMeetingBookingsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `meeting_bookings` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `slot_id` int(10) UNSIGNED NOT NULL,
            `parent_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `purpose` text DEFAULT NULL,
            `status` enum('booked','attended','cancelled','no_show') DEFAULT 'booked',
            `booked_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `cancelled_at` timestamp NULL DEFAULT NULL,
            `attended_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_booking` (`slot_id`,`parent_id`,`student_id`),
            KEY `idx_parent` (`parent_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_school_campus` (`school_id`,`campus_id`),
            CONSTRAINT `fk_meeting_bookings_slot` FOREIGN KEY (`slot_id`) REFERENCES `meeting_slots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_meeting_bookings_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_meeting_bookings_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_meeting_bookings_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- CBT -----
    private static function getCbtTestsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `cbt_tests` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `paper_id` int(10) UNSIGNED NOT NULL,
            `is_random_questions` tinyint(1) DEFAULT 0,
            `is_random_options` tinyint(1) DEFAULT 0,
            `allow_review` tinyint(1) DEFAULT 1,
            `show_result_immediately` tinyint(1) DEFAULT 0,
            `scheduled_start` datetime DEFAULT NULL,
            `scheduled_end` datetime DEFAULT NULL,
            `pass_marks` decimal(5,2) DEFAULT NULL,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `paper_id` (`paper_id`),
            KEY `idx_created_by` (`created_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_cbt_tests_paper` FOREIGN KEY (`paper_id`) REFERENCES `exam_papers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_tests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_tests_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getCbtQuestionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `cbt_questions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `test_id` int(10) UNSIGNED NOT NULL,
            `question_text` text NOT NULL,
            `question_type` enum('multiple_choice','true_false','essay') NOT NULL,
            `marks` decimal(5,2) NOT NULL,
            `difficulty_level` enum('easy','medium','hard') DEFAULT 'medium',
            `attachment` varchar(500) DEFAULT NULL,
            `order` int(10) UNSIGNED DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_test` (`test_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_cbt_questions_test` FOREIGN KEY (`test_id`) REFERENCES `cbt_tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_questions_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getCbtOptionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `cbt_options` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `question_id` int(10) UNSIGNED NOT NULL,
            `option_text` text NOT NULL,
            `is_correct` tinyint(1) DEFAULT 0,
            `order` int(10) UNSIGNED DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_question` (`question_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_cbt_options_question` FOREIGN KEY (`question_id`) REFERENCES `cbt_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_options_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getCbtAttemptsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `cbt_attempts` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `test_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `start_time` timestamp NOT NULL DEFAULT current_timestamp(),
            `end_time` timestamp NULL DEFAULT NULL,
            `status` enum('in_progress','completed','scored','expired') DEFAULT 'in_progress',
            `total_score` decimal(5,2) DEFAULT NULL,
            `percentage` decimal(5,2) DEFAULT NULL,
            `graded_by` int(10) UNSIGNED DEFAULT NULL,
            `graded_at` timestamp NULL DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_test` (`test_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_graded_by` (`graded_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_cbt_attempts_test` FOREIGN KEY (`test_id`) REFERENCES `cbt_tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_attempts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_attempts_graded_by` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_attempts_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getCbtResponsesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `cbt_responses` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `attempt_id` int(10) UNSIGNED NOT NULL,
            `question_id` int(10) UNSIGNED NOT NULL,
            `selected_option_id` int(10) UNSIGNED DEFAULT NULL,
            `answer_text` text DEFAULT NULL,
            `is_correct` tinyint(1) DEFAULT NULL,
            `marks_obtained` decimal(5,2) DEFAULT NULL,
            `graded_by` int(10) UNSIGNED DEFAULT NULL,
            `graded_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_attempt` (`attempt_id`),
            KEY `idx_question` (`question_id`),
            KEY `idx_option` (`selected_option_id`),
            KEY `idx_graded_by` (`graded_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_cbt_responses_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `cbt_attempts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_responses_question` FOREIGN KEY (`question_id`) REFERENCES `cbt_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_responses_option` FOREIGN KEY (`selected_option_id`) REFERENCES `cbt_options` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_responses_graded_by` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_responses_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getCbtResultsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `cbt_results` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `attempt_id` int(10) UNSIGNED NOT NULL,
            `rank` int(10) UNSIGNED DEFAULT NULL,
            `grade` varchar(5) DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `published` tinyint(1) DEFAULT 0,
            `published_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `attempt_id` (`attempt_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_cbt_results_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `cbt_attempts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cbt_results_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Voting -----
    private static function getVotingElectionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `voting_elections` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `start_date` datetime NOT NULL,
            `end_date` datetime NOT NULL,
            `status` enum('upcoming','active','closed','archived') DEFAULT 'upcoming',
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_status` (`status`),
            KEY `idx_dates` (`start_date`,`end_date`),
            KEY `idx_created_by` (`created_by`),
            CONSTRAINT `fk_voting_elections_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_elections_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getVotingPositionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `voting_positions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `election_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `eligibility_criteria` text DEFAULT NULL,
            `max_candidates` int(10) UNSIGNED DEFAULT 1,
            `max_votes_per_voter` int(10) UNSIGNED DEFAULT 1,
            `is_active` tinyint(1) DEFAULT 1,
            `order` int(10) UNSIGNED DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_election` (`election_id`),
            CONSTRAINT `fk_voting_positions_election` FOREIGN KEY (`election_id`) REFERENCES `voting_elections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_positions_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getVotingCandidatesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `voting_candidates` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `election_id` int(10) UNSIGNED NOT NULL,
            `position_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `manifesto` text DEFAULT NULL,
            `photo` varchar(500) DEFAULT NULL,
            `approved` tinyint(1) DEFAULT 0,
            `approved_by` int(10) UNSIGNED DEFAULT NULL,
            `approved_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_candidate` (`election_id`,`position_id`,`student_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_position` (`position_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_approved_by` (`approved_by`),
            CONSTRAINT `fk_voting_candidates_election` FOREIGN KEY (`election_id`) REFERENCES `voting_elections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_candidates_position` FOREIGN KEY (`position_id`) REFERENCES `voting_positions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_candidates_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_candidates_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_candidates_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getVotingVotesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `voting_votes` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `election_id` int(10) UNSIGNED NOT NULL,
            `position_id` int(10) UNSIGNED NOT NULL,
            `candidate_id` int(10) UNSIGNED NOT NULL,
            `voter_id` int(10) UNSIGNED NOT NULL,
            `vote_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_vote` (`election_id`,`position_id`,`voter_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_election` (`election_id`),
            KEY `idx_position` (`position_id`),
            KEY `idx_candidate` (`candidate_id`),
            KEY `idx_voter` (`voter_id`),
            CONSTRAINT `fk_voting_votes_election` FOREIGN KEY (`election_id`) REFERENCES `voting_elections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_votes_position` FOREIGN KEY (`position_id`) REFERENCES `voting_positions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_votes_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `voting_candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_votes_voter` FOREIGN KEY (`voter_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_votes_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getVotingResultsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `voting_results` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `election_id` int(10) UNSIGNED NOT NULL,
            `position_id` int(10) UNSIGNED NOT NULL,
            `candidate_id` int(10) UNSIGNED NOT NULL,
            `vote_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
            `percentage` decimal(5,2) DEFAULT NULL,
            `is_winner` tinyint(1) DEFAULT 0,
            `calculated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_result` (`election_id`,`position_id`,`candidate_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_election` (`election_id`),
            KEY `idx_position` (`position_id`),
            KEY `idx_candidate` (`candidate_id`),
            CONSTRAINT `fk_voting_results_election` FOREIGN KEY (`election_id`) REFERENCES `voting_elections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_results_position` FOREIGN KEY (`position_id`) REFERENCES `voting_positions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_results_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `voting_candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_voting_results_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Enhanced Feature Tables (System) -----
    private static function getSubscriptionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `subscriptions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `plan_id` varchar(50) NOT NULL,
            `plan_name` varchar(100) NOT NULL,
            `status` enum('active','pending','cancelled','expired','past_due') DEFAULT 'pending',
            `billing_cycle` enum('monthly','quarterly','yearly') DEFAULT 'monthly',
            `amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `storage_limit` bigint(20) DEFAULT 1073741824,
            `user_limit` int(10) DEFAULT 100,
            `student_limit` int(10) DEFAULT 500,
            `features` text COMMENT 'JSON encoded features',
            `current_period_start` date NOT NULL,
            `current_period_end` date NOT NULL,
            `cancel_at_period_end` tinyint(1) DEFAULT 0,
            `cancelled_at` timestamp NULL DEFAULT NULL,
            `trial_ends_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_school_subscription` (`school_id`),
            KEY `idx_status` (`status`),
            KEY `idx_period` (`current_period_end`),
            KEY `idx_school_plan` (`school_id`,`plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getBillingHistoryTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `billing_history` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `subscription_id` int(10) UNSIGNED DEFAULT NULL,
            `invoice_number` varchar(100) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `tax_amount` decimal(10,2) DEFAULT 0.00,
            `total_amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `payment_method` varchar(50) DEFAULT NULL,
            `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
            `payment_date` timestamp NULL DEFAULT NULL,
            `due_date` date NOT NULL,
            `paid_at` timestamp NULL DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `payment_gateway` varchar(50) DEFAULT NULL,
            `gateway_response` text,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `subscription_id` (`subscription_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_payment_status` (`payment_status`),
            KEY `idx_payment_date` (`payment_date`),
            KEY `idx_school_status` (`school_id`,`payment_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPaymentMethodsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payment_methods` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `type` enum('card','bank_transfer','mobile_money','wallet') NOT NULL,
            `provider` varchar(50) DEFAULT NULL,
            `last_four` varchar(4) DEFAULT NULL,
            `exp_month` int(2) DEFAULT NULL,
            `exp_year` int(4) DEFAULT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `is_verified` tinyint(1) DEFAULT 0,
            `metadata` text COMMENT 'JSON encoded metadata',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_type` (`type`),
            KEY `idx_default` (`is_default`),
            KEY `idx_school_default` (`school_id`,`is_default`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoicesV2TableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoices_v2` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `invoice_number` varchar(100) NOT NULL,
            `billing_history_id` int(10) UNSIGNED DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `tax` decimal(10,2) DEFAULT 0.00,
            `discount` decimal(10,2) DEFAULT 0.00,
            `total_amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `status` enum('draft','sent','viewed','paid','overdue','cancelled') DEFAULT 'draft',
            `due_date` date NOT NULL,
            `paid_date` timestamp NULL DEFAULT NULL,
            `notes` text,
            `terms` text,
            `pdf_path` varchar(500) DEFAULT NULL,
            `sent_at` timestamp NULL DEFAULT NULL,
            `viewed_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `billing_history_id` (`billing_history_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_status` (`status`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_school_status` (`school_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStorageUsageTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `storage_usage` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `storage_type` enum('database','files','backups','attachments') NOT NULL,
            `used_bytes` bigint(20) DEFAULT 0,
            `limit_bytes` bigint(20) DEFAULT 1073741824,
            `file_count` int(10) DEFAULT 0,
            `last_calculated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_school_storage` (`school_id`,`storage_type`),
            KEY `idx_school` (`school_id`),
            KEY `idx_type` (`storage_type`),
            KEY `idx_usage` (`used_bytes`),
            KEY `idx_school_type` (`school_id`,`storage_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFileStorageTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `file_storage` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `file_type` varchar(100) NOT NULL,
            `file_size` bigint(20) NOT NULL,
            `mime_type` varchar(100) DEFAULT NULL,
            `storage_type` enum('local','s3','cloudinary','wasabi') DEFAULT 'local',
            `bucket_name` varchar(255) DEFAULT NULL,
            `object_key` varchar(500) DEFAULT NULL,
            `is_public` tinyint(1) DEFAULT 0,
            `access_hash` varchar(100) DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL,
            `download_count` int(10) DEFAULT 0,
            `last_downloaded` timestamp NULL DEFAULT NULL,
            `metadata` text COMMENT 'JSON encoded metadata',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_file_type` (`file_type`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_type` (`school_id`,`file_type`),
            KEY `idx_access_hash` (`access_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPerformanceMetricsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `performance_metrics` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `metric_type` enum('api_response','page_load','query_time','memory_usage','cpu_usage') NOT NULL,
            `endpoint` varchar(500) DEFAULT NULL,
            `value` decimal(10,4) NOT NULL,
            `unit` varchar(20) DEFAULT NULL,
            `sample_count` int(10) DEFAULT 1,
            `min_value` decimal(10,4) DEFAULT NULL,
            `max_value` decimal(10,4) DEFAULT NULL,
            `avg_value` decimal(10,4) DEFAULT NULL,
            `p95_value` decimal(10,4) DEFAULT NULL,
            `metadata` text COMMENT 'JSON encoded metadata',
            `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_metric_type` (`metric_type`),
            KEY `idx_recorded_at` (`recorded_at`),
            KEY `idx_school_metric` (`school_id`,`metric_type`,`recorded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getApiLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `api_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `api_key_id` int(10) UNSIGNED DEFAULT NULL,
            `endpoint` varchar(500) NOT NULL,
            `method` varchar(10) NOT NULL,
            `request_body` text,
            `response_body` text,
            `status_code` int(3) DEFAULT NULL,
            `response_time` decimal(10,4) DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `is_success` tinyint(1) DEFAULT 0,
            `error_message` text,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `api_key_id` (`api_key_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_endpoint` (`endpoint`),
            KEY `idx_status_code` (`status_code`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_endpoint` (`school_id`,`endpoint`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAuditLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `user_type` varchar(50) DEFAULT NULL,
            `action` varchar(100) NOT NULL,
            `entity_type` varchar(100) DEFAULT NULL,
            `entity_id` int(10) UNSIGNED DEFAULT NULL,
            `old_values` text COMMENT 'JSON encoded old values',
            `new_values` text COMMENT 'JSON encoded new values',
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `url` varchar(500) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_action` (`action`),
            KEY `idx_entity` (`entity_type`,`entity_id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_action` (`school_id`,`action`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSecurityLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `security_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `event_type` enum('login_attempt','failed_login','password_change','session_start','session_end','suspicious_activity','blocked_ip') NOT NULL,
            `severity` enum('low','medium','high','critical') DEFAULT 'low',
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `location` varchar(255) DEFAULT NULL,
            `details` text,
            `resolved` tinyint(1) DEFAULT 0,
            `resolved_at` timestamp NULL DEFAULT NULL,
            `resolved_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_severity` (`severity`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_event` (`school_id`,`event_type`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRateLimitsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `rate_limits` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `endpoint` varchar(500) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `request_count` int(10) DEFAULT 1,
            `limit_reached` tinyint(1) DEFAULT 0,
            `first_request` timestamp NOT NULL DEFAULT current_timestamp(),
            `last_request` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `window_reset` timestamp NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_rate_limit` (`school_id`,`endpoint`,`ip_address`,`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_endpoint` (`endpoint`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_window_reset` (`window_reset`),
            KEY `idx_school_endpoint_ip` (`school_id`,`endpoint`,`ip_address`,`last_request`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLoginAttemptsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `username` varchar(255) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `user_agent` text,
            `success` tinyint(1) DEFAULT 0,
            `failed_reason` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_username` (`username`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_success` (`success`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_ip` (`school_id`,`ip_address`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getBackupHistoryTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `backup_history` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `backup_type` enum('full','incremental','differential','schema_only') DEFAULT 'full',
            `storage_type` enum('local','s3','ftp','google_drive') DEFAULT 'local',
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) DEFAULT NULL,
            `file_size` bigint(20) DEFAULT NULL,
            `database_size` bigint(20) DEFAULT NULL,
            `table_count` int(10) DEFAULT NULL,
            `status` enum('pending','in_progress','completed','failed','cancelled') DEFAULT 'pending',
            `error_message` text,
            `started_at` timestamp NULL DEFAULT NULL,
            `completed_at` timestamp NULL DEFAULT NULL,
            `retention_days` int(10) DEFAULT 30,
            `expires_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_backup_type` (`backup_type`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_expires_at` (`expires_at`),
            KEY `idx_school_status` (`school_id`,`status`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRecoveryPointsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `recovery_points` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `backup_id` int(10) UNSIGNED DEFAULT NULL,
            `point_name` varchar(255) NOT NULL,
            `description` text,
            `recovery_type` enum('full','partial','data_only','schema_only') DEFAULT 'full',
            `tables_included` text COMMENT 'JSON array of tables',
            `status` enum('available','restoring','restored','failed') DEFAULT 'available',
            `file_path` varchar(500) DEFAULT NULL,
            `file_size` bigint(20) DEFAULT NULL,
            `checksum` varchar(64) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `restored_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `backup_id` (`backup_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_status` (`school_id`,`status`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getNotificationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `notifications` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `type` enum('email','sms','push','in_app','system') DEFAULT 'in_app',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `data` text COMMENT 'JSON encoded data',
            `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
            `is_read` tinyint(1) DEFAULT 0,
            `read_at` timestamp NULL DEFAULT NULL,
            `is_sent` tinyint(1) DEFAULT 0,
            `sent_at` timestamp NULL DEFAULT NULL,
            `delivery_status` enum('pending','sent','delivered','failed','bounced') DEFAULT 'pending',
            `failure_reason` text,
            `scheduled_for` timestamp NULL DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_type` (`type`),
            KEY `idx_is_read` (`is_read`),
            KEY `idx_priority` (`priority`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_user` (`school_id`,`user_id`,`is_read`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getEmailTemplatesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `email_templates` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `template_key` varchar(100) NOT NULL,
            `name` varchar(255) NOT NULL,
            `subject` varchar(255) NOT NULL,
            `body_html` text NOT NULL,
            `body_text` text,
            `variables` text COMMENT 'JSON array of available variables',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_template` (`school_id`,`template_key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_template_key` (`template_key`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_school_active` (`school_id`,`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSmsLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `sms_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `recipient` varchar(20) NOT NULL,
            `message` text NOT NULL,
            `sender_id` varchar(20) DEFAULT NULL,
            `message_id` varchar(100) DEFAULT NULL,
            `status` enum('pending','sent','delivered','failed','undelivered') DEFAULT 'pending',
            `status_code` varchar(50) DEFAULT NULL,
            `status_message` text,
            `cost` decimal(8,4) DEFAULT NULL,
            `units` int(10) DEFAULT NULL,
            `provider` varchar(50) DEFAULT NULL,
            `sent_at` timestamp NULL DEFAULT NULL,
            `delivered_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_recipient` (`recipient`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_status` (`school_id`,`status`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getWhatsAppNotificationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `whatsapp_notifications` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `announcement_id` int(10) UNSIGNED DEFAULT NULL,
            `feature` varchar(50) NOT NULL DEFAULT 'announcement',
            `reference_id` int(10) UNSIGNED DEFAULT NULL,
            `recipient_user_id` int(10) UNSIGNED DEFAULT NULL,
            `recipient_type` varchar(30) NOT NULL,
            `recipient_name` varchar(190) DEFAULT NULL,
            `phone` varchar(32) NOT NULL,
            `template_name` varchar(190) NOT NULL,
            `message_preview` text DEFAULT NULL,
            `status` enum('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
            `provider_message_id` varchar(190) DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `provider_response` mediumtext DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `sent_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_school_announcement` (`school_id`, `announcement_id`),
            KEY `idx_school_feature` (`school_id`, `feature`, `reference_id`),
            KEY `idx_status` (`status`),
            KEY `idx_phone` (`phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getApiKeysTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(255) NOT NULL,
            `api_key` varchar(100) NOT NULL,
            `api_secret` varchar(100) DEFAULT NULL,
            `permissions` text COMMENT 'JSON encoded permissions',
            `rate_limit_per_minute` int(10) DEFAULT 60,
            `rate_limit_per_hour` int(10) DEFAULT 1000,
            `rate_limit_per_day` int(10) DEFAULT 10000,
            `allowed_ips` text COMMENT 'JSON array of allowed IPs',
            `allowed_origins` text COMMENT 'JSON array of allowed origins',
            `expires_at` timestamp NULL DEFAULT NULL,
            `last_used_at` timestamp NULL DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `api_key` (`api_key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_expires_at` (`expires_at`),
            KEY `idx_school_active` (`school_id`,`is_active`,`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getApiUsageTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `api_usage` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `api_key_id` int(10) UNSIGNED DEFAULT NULL,
            `endpoint` varchar(500) NOT NULL,
            `method` varchar(10) NOT NULL,
            `request_count` int(10) DEFAULT 1,
            `total_response_time` decimal(12,4) DEFAULT 0,
            `failed_count` int(10) DEFAULT 0,
            `period` enum('minute','hour','day','month') DEFAULT 'day',
            `period_start` timestamp NOT NULL,
            `period_end` timestamp NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_api_usage` (`school_id`,`api_key_id`,`endpoint`,`method`,`period`,`period_start`),
            KEY `api_key_id` (`api_key_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_endpoint` (`endpoint`),
            KEY `idx_period` (`period`),
            KEY `idx_period_start` (`period_start`),
            KEY `idx_school_period` (`school_id`,`period`,`period_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMaintenanceLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `maintenance_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `maintenance_type` enum('database_optimization','cache_clear','backup_cleanup','storage_cleanup','system_update') NOT NULL,
            `description` text NOT NULL,
            `status` enum('pending','running','completed','failed','cancelled') DEFAULT 'pending',
            `started_at` timestamp NULL DEFAULT NULL,
            `completed_at` timestamp NULL DEFAULT NULL,
            `duration_seconds` int(10) DEFAULT NULL,
            `affected_records` int(10) DEFAULT NULL,
            `freed_space` bigint(20) DEFAULT NULL,
            `error_message` text,
            `performed_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `performed_by` (`performed_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_maintenance_type` (`maintenance_type`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_type` (`school_id`,`maintenance_type`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSystemAlertsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `system_alerts` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `alert_type` enum('storage_limit','user_limit','subscription_expiry','payment_failed','performance_issue','security_issue','system_error') NOT NULL,
            `severity` enum('info','warning','error','critical') DEFAULT 'info',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `data` text COMMENT 'JSON encoded data',
            `is_resolved` tinyint(1) DEFAULT 0,
            `resolved_at` timestamp NULL DEFAULT NULL,
            `resolved_by` int(10) UNSIGNED DEFAULT NULL,
            `resolution_notes` text,
            `acknowledged` tinyint(1) DEFAULT 0,
            `acknowledged_at` timestamp NULL DEFAULT NULL,
            `acknowledged_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_alert_type` (`alert_type`),
            KEY `idx_severity` (`severity`),
            KEY `idx_is_resolved` (`is_resolved`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_resolved` (`school_id`,`is_resolved`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- NEW MISSING TABLES (Certificates, Messaging, Exams, Geofence, Leave, Library, Payroll, Academics, Staff) -----

    private static function getCertificateTemplatesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `certificate_templates` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(255) NOT NULL,
            `type` enum('leaving','character','achievement','participation','other') DEFAULT 'other',
            `template_html` text NOT NULL,
            `orientation` enum('portrait','landscape') DEFAULT 'portrait',
            `default_fields` text DEFAULT NULL COMMENT 'JSON of placeholder fields',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_certificate_templates_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getCertificatesIssuedTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `certificates_issued` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `template_id` int(10) UNSIGNED DEFAULT NULL,
            `certificate_number` varchar(100) NOT NULL,
            `issue_date` date NOT NULL,
            `reason` varchar(255) DEFAULT NULL,
            `file_path` varchar(500) DEFAULT NULL,
            `metadata` text DEFAULT NULL,
            `issued_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `certificate_number` (`certificate_number`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_template` (`template_id`),
            KEY `idx_issued_by` (`issued_by`),
            CONSTRAINT `fk_certificates_issued_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_certificates_issued_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_certificates_issued_template` FOREIGN KEY (`template_id`) REFERENCES `certificate_templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_certificates_issued_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getConversationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `conversations` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `conversation_type` enum('individual','group') DEFAULT 'individual',
            `subject` varchar(255) DEFAULT NULL,
            `created_by` int(10) UNSIGNED NOT NULL,
            `last_message_id` int(10) UNSIGNED DEFAULT NULL,
            `last_message_at` timestamp NULL DEFAULT NULL,
            `is_archived` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_created_by` (`created_by`),
            KEY `idx_last_message` (`last_message_id`),
            KEY `idx_last_message_at` (`last_message_at`),
            CONSTRAINT `fk_conversations_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getConversationParticipantsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `conversation_participants` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `user_type` enum('admin','teacher','student','parent','accountant','librarian','receptionist') NOT NULL,
            `last_read_at` timestamp NULL DEFAULT NULL,
            `is_muted` tinyint(1) DEFAULT 0,
            `is_archived` tinyint(1) DEFAULT 0,
            `is_deleted` tinyint(1) DEFAULT 0,
            `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `left_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_participant` (`conversation_id`,`user_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_conversation` (`conversation_id`),
            KEY `idx_user_unread` (`user_id`,`last_read_at`),
            KEY `idx_user_archived` (`user_id`,`is_archived`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMessagesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `messages` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` int(10) UNSIGNED NOT NULL,
            `sender_id` int(10) UNSIGNED NOT NULL,
            `sender_type` enum('admin','teacher','student','parent','accountant','librarian','receptionist') NOT NULL,
            `message_type` enum('text','image','file','audio','video','system') DEFAULT 'text',
            `message` text NOT NULL,
            `metadata` text DEFAULT NULL,
            `is_delivered` tinyint(1) DEFAULT 0,
            `delivered_at` timestamp NULL DEFAULT NULL,
            `is_read` tinyint(1) DEFAULT 0,
            `read_at` timestamp NULL DEFAULT NULL,
            `is_starred` tinyint(1) DEFAULT 0,
            `is_pinned` tinyint(1) DEFAULT 0,
            `is_deleted` tinyint(1) DEFAULT 0,
            `deleted_at` timestamp NULL DEFAULT NULL,
            `reply_to_id` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_conversation` (`conversation_id`),
            KEY `idx_sender` (`sender_id`),
            KEY `idx_reply_to` (`reply_to_id`),
            KEY `idx_conversation_created` (`conversation_id`,`created_at`),
            KEY `idx_conversation_read` (`conversation_id`,`is_read`),
            KEY `idx_sender_conversation` (`sender_id`,`conversation_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMessageAttachmentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `message_attachments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` int(10) UNSIGNED NOT NULL,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `file_size` bigint(20) NOT NULL,
            `mime_type` varchar(100) NOT NULL,
            `file_extension` varchar(20) DEFAULT NULL,
            `thumbnail_path` varchar(500) DEFAULT NULL,
            `duration` int(10) DEFAULT NULL,
            `dimensions` varchar(50) DEFAULT NULL,
            `is_downloaded` tinyint(1) DEFAULT 0,
            `download_count` int(10) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_message` (`message_id`),
            KEY `idx_file_type` (`mime_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMessageBlocksTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `message_blocks` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `blocked_user_id` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_block` (`user_id`,`blocked_user_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_blocked` (`blocked_user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_message_blocks_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMessageDraftsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `message_drafts` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `conversation_id` int(10) UNSIGNED DEFAULT NULL,
            `recipient_id` int(10) UNSIGNED DEFAULT NULL,
            `recipient_type` enum('teacher','parent','student') DEFAULT NULL,
            `message` text NOT NULL,
            `attachments` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_conversation` (`conversation_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_message_drafts_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMessageReactionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `message_reactions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `reaction` varchar(50) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_reaction` (`message_id`,`user_id`,`reaction`),
            KEY `idx_message` (`message_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMessageStatusTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `message_status` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `status` enum('sent','delivered','read') DEFAULT 'sent',
            `status_changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_message_user` (`message_id`,`user_id`),
            KEY `idx_message` (`message_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamPapersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exam_papers` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `exam_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `total_marks` decimal(5,2) NOT NULL,
            `duration_minutes` int(10) UNSIGNED DEFAULT NULL,
            `paper_type` enum('cbt','printed') NOT NULL,
            `status` enum('draft','submitted','approved','rejected') DEFAULT 'draft',
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_exam` (`exam_id`),
            KEY `idx_subject` (`subject_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_teacher` (`teacher_id`),
            CONSTRAINT `fk_exam_papers_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamQuestionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exam_questions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `paper_id` int(10) UNSIGNED NOT NULL,
            `question_text` text NOT NULL,
            `question_type` enum('multiple_choice','true_false','essay') NOT NULL,
            `marks` decimal(5,2) NOT NULL,
            `attachment` varchar(500) DEFAULT NULL,
            `order` int(10) UNSIGNED DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_paper` (`paper_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamOptionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exam_options` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `question_id` int(10) UNSIGNED NOT NULL,
            `option_text` text NOT NULL,
            `is_correct` tinyint(1) DEFAULT 0,
            `order` int(10) UNSIGNED DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_question` (`question_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getGeofenceLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `geofence_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `action` enum('clock_in','clock_out') NOT NULL,
            `latitude` decimal(10,8) NOT NULL,
            `longitude` decimal(11,8) NOT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `is_within_allowed` tinyint(1) NOT NULL DEFAULT 0,
            `distance_meters` decimal(10,2) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_created_at` (`created_at`),
            CONSTRAINT `fk_geofence_logs_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_geofence_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLeaveTypesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `leave_types` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `max_days_per_year` int(10) UNSIGNED DEFAULT NULL,
            `applicable_to` enum('teacher','staff','student','all') DEFAULT 'all',
            `is_paid` tinyint(1) DEFAULT 1,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_leave_type` (`school_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_leave_types_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLeaveRequestsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `leave_requests` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `user_type` enum('teacher','staff','student') NOT NULL,
            `leave_type_id` int(10) UNSIGNED NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `reason` text NOT NULL,
            `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
            `approved_by` int(10) UNSIGNED DEFAULT NULL,
            `approved_at` timestamp NULL DEFAULT NULL,
            `rejection_reason` text DEFAULT NULL,
            `applied_on` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_leave_type` (`leave_type_id`),
            KEY `idx_status` (`status`),
            KEY `idx_dates` (`start_date`,`end_date`),
            KEY `idx_approved_by` (`approved_by`),
            CONSTRAINT `fk_leave_requests_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_leave_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_leave_requests_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLibraryCategoriesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `library_categories` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_category_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_library_categories_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLibraryBooksTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `library_books` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `isbn` varchar(20) DEFAULT NULL,
            `title` varchar(255) NOT NULL,
            `author` varchar(255) NOT NULL,
            `publisher` varchar(255) DEFAULT NULL,
            `edition` varchar(50) DEFAULT NULL,
            `category_id` int(10) UNSIGNED DEFAULT NULL,
            `shelf_location` varchar(100) DEFAULT NULL,
            `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
            `available_quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
            `price` decimal(10,2) DEFAULT NULL,
            `purchase_date` date DEFAULT NULL,
            `description` text DEFAULT NULL,
            `cover_image` varchar(500) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_category` (`category_id`),
            KEY `idx_isbn` (`isbn`),
            KEY `idx_title` (`title`),
            CONSTRAINT `fk_library_books_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_library_books_category` FOREIGN KEY (`category_id`) REFERENCES `library_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLibraryMembersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `library_members` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `membership_number` varchar(50) NOT NULL,
            `membership_type` enum('student','teacher','staff') NOT NULL,
            `issued_date` date NOT NULL,
            `expiry_date` date DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `membership_number` (`membership_number`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_user` (`user_id`),
            CONSTRAINT `fk_library_members_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_library_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLibraryIssuesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `library_issues` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `book_id` int(10) UNSIGNED NOT NULL,
            `member_id` int(10) UNSIGNED NOT NULL,
            `issue_date` date NOT NULL,
            `due_date` date NOT NULL,
            `return_date` date DEFAULT NULL,
            `status` enum('issued','returned','overdue','lost') DEFAULT 'issued',
            `fine_amount` decimal(10,2) DEFAULT 0.00,
            `fine_paid` tinyint(1) DEFAULT 0,
            `issued_by` int(10) UNSIGNED NOT NULL,
            `returned_by` int(10) UNSIGNED DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_book` (`book_id`),
            KEY `idx_member` (`member_id`),
            KEY `idx_issued_by` (`issued_by`),
            KEY `idx_returned_by` (`returned_by`),
            KEY `idx_status` (`status`),
            KEY `idx_due_date` (`due_date`),
            CONSTRAINT `fk_library_issues_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_library_issues_book` FOREIGN KEY (`book_id`) REFERENCES `library_books` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_library_issues_member` FOREIGN KEY (`member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLibraryReservationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `library_reservations` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `book_id` int(10) UNSIGNED NOT NULL,
            `member_id` int(10) UNSIGNED NOT NULL,
            `reservation_date` date NOT NULL,
            `expiry_date` date NOT NULL,
            `status` enum('pending','fulfilled','cancelled','expired') DEFAULT 'pending',
            `notified` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_book` (`book_id`),
            KEY `idx_member` (`member_id`),
            CONSTRAINT `fk_library_reservations_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_library_reservations_book` FOREIGN KEY (`book_id`) REFERENCES `library_books` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_library_reservations_member` FOREIGN KEY (`member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLibraryFineSettingsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `library_fine_settings` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `fine_per_day` decimal(10,2) NOT NULL DEFAULT 0.00,
            `max_fine` decimal(10,2) DEFAULT NULL,
            `grace_days` int(10) UNSIGNED DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `school_id` (`school_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_library_fine_settings_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollSalaryGradesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_salary_grades` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `grade_name` varchar(100) NOT NULL,
            `basic_salary` decimal(10,2) NOT NULL,
            `house_allowance` decimal(10,2) DEFAULT 0.00,
            `transport_allowance` decimal(10,2) DEFAULT 0.00,
            `medical_allowance` decimal(10,2) DEFAULT 0.00,
            `other_allowances` decimal(10,2) DEFAULT 0.00,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_grade_school` (`school_id`,`grade_name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_payroll_salary_grades_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollEmployeesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_employees` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `employee_number` varchar(50) NOT NULL,
            `department` varchar(100) DEFAULT NULL,
            `designation` varchar(100) DEFAULT NULL,
            `joining_date` date DEFAULT NULL,
            `salary_grade_id` int(10) UNSIGNED DEFAULT NULL,
            `basic_salary` decimal(10,2) DEFAULT NULL,
            `bank_name` varchar(255) DEFAULT NULL,
            `bank_account` varchar(50) DEFAULT NULL,
            `ifsc_code` varchar(20) DEFAULT NULL,
            `tax_id` varchar(50) DEFAULT NULL,
            `pan_number` varchar(20) DEFAULT NULL,
            `pf_number` varchar(50) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `employee_number` (`employee_number`),
            UNIQUE KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_salary_grade` (`salary_grade_id`),
            CONSTRAINT `fk_payroll_employees_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_payroll_employees_salary_grade` FOREIGN KEY (`salary_grade_id`) REFERENCES `payroll_salary_grades` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_payroll_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollAllowancesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_allowances` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(10) UNSIGNED NOT NULL,
            `allowance_type` varchar(100) NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `effective_from` date NOT NULL,
            `effective_to` date DEFAULT NULL,
            `is_recurring` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollDeductionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_deductions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(10) UNSIGNED NOT NULL,
            `deduction_type` varchar(100) NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `effective_from` date NOT NULL,
            `effective_to` date DEFAULT NULL,
            `is_recurring` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollPeriodsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_periods` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `status` enum('open','processing','closed','archived') DEFAULT 'open',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            CONSTRAINT `fk_payroll_periods_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollRunsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_runs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `period_id` int(10) UNSIGNED NOT NULL,
            `processed_by` int(10) UNSIGNED NOT NULL,
            `processed_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `total_gross` decimal(12,2) DEFAULT 0.00,
            `total_deductions` decimal(12,2) DEFAULT 0.00,
            `total_net` decimal(12,2) DEFAULT 0.00,
            `status` enum('draft','approved','paid','cancelled') DEFAULT 'draft',
            `payment_date` date DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_period` (`period_id`),
            KEY `idx_processed_by` (`processed_by`),
            CONSTRAINT `fk_payroll_runs_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_payroll_runs_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPayrollSlipsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payroll_slips` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `payroll_run_id` int(10) UNSIGNED NOT NULL,
            `employee_id` int(10) UNSIGNED NOT NULL,
            `gross_salary` decimal(10,2) NOT NULL,
            `total_allowances` decimal(10,2) DEFAULT 0.00,
            `total_deductions` decimal(10,2) DEFAULT 0.00,
            `net_salary` decimal(10,2) NOT NULL,
            `payment_method` enum('bank_transfer','cash','cheque') DEFAULT 'bank_transfer',
            `payment_status` enum('pending','paid','failed') DEFAULT 'pending',
            `payment_date` date DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `pdf_path` varchar(500) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_payroll_run` (`payroll_run_id`),
            KEY `idx_employee` (`employee_id`),
            CONSTRAINT `fk_payroll_slips_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_payroll_slips_payroll_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_payroll_slips_employee` FOREIGN KEY (`employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getReportCardsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `report_cards` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED DEFAULT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `file_path` varchar(500) DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 0,
            `published_by` int(10) UNSIGNED DEFAULT NULL,
            `published_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_year` (`academic_year_id`),
            KEY `idx_term` (`academic_term_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_published_by` (`published_by`),
            CONSTRAINT `fk_report_cards_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_report_cards_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_report_cards_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_report_cards_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_report_cards_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStudentPromotionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `student_promotions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `from_academic_year_id` int(10) UNSIGNED NOT NULL,
            `to_academic_year_id` int(10) UNSIGNED NOT NULL,
            `from_class_id` int(10) UNSIGNED NOT NULL,
            `to_class_id` int(10) UNSIGNED NOT NULL,
            `from_section_id` int(10) UNSIGNED DEFAULT NULL,
            `to_section_id` int(10) UNSIGNED DEFAULT NULL,
            `from_campus_id` int(10) UNSIGNED DEFAULT NULL,
            `to_campus_id` int(10) UNSIGNED DEFAULT NULL,
            `promotion_date` date NOT NULL,
            `remarks` text DEFAULT NULL,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_from_academic_year` (`from_academic_year_id`),
            KEY `idx_to_academic_year` (`to_academic_year_id`),
            KEY `idx_from_class` (`from_class_id`),
            KEY `idx_to_class` (`to_class_id`),
            KEY `idx_from_section` (`from_section_id`),
            KEY `idx_to_section` (`to_section_id`),
            KEY `idx_from_campus` (`from_campus_id`),
            KEY `idx_to_campus` (`to_campus_id`),
            KEY `idx_created_by` (`created_by`),
            CONSTRAINT `fk_student_promotions_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_from_academic_year` FOREIGN KEY (`from_academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_to_academic_year` FOREIGN KEY (`to_academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_from_class` FOREIGN KEY (`from_class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_to_class` FOREIGN KEY (`to_class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_from_section` FOREIGN KEY (`from_section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_student_promotions_to_section` FOREIGN KEY (`to_section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStaffAttendanceTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `staff_attendance` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `date` date NOT NULL,
            `clock_in_time` timestamp NULL DEFAULT NULL,
            `clock_out_time` timestamp NULL DEFAULT NULL,
            `clock_in_lat` decimal(10,8) DEFAULT NULL,
            `clock_in_lng` decimal(11,8) DEFAULT NULL,
            `clock_out_lat` decimal(10,8) DEFAULT NULL,
            `clock_out_lng` decimal(11,8) DEFAULT NULL,
            `clock_in_ip` varchar(45) DEFAULT NULL,
            `clock_out_ip` varchar(45) DEFAULT NULL,
            `clock_in_method` enum('manual','biometric','location','mobile') DEFAULT 'manual',
            `clock_out_method` enum('manual','biometric','location','mobile') DEFAULT 'manual',
            `status` enum('present','absent','late','half_day','holiday') DEFAULT 'present',
            `work_hours` decimal(5,2) GENERATED ALWAYS AS (timestampdiff(HOUR,`clock_in_time`,`clock_out_time`)) STORED,
            `approved_by` int(10) UNSIGNED DEFAULT NULL,
            `approved_at` timestamp NULL DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_staff_attendance` (`user_id`,`date`),
            KEY `idx_school` (`school_id`),
            KEY `idx_campus` (`campus_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_date` (`date`),
            KEY `idx_status` (`status`),
            KEY `idx_approved_by` (`approved_by`),
            CONSTRAINT `fk_staff_attendance_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_staff_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    // ----- Remaining Methods (unchanged from original) -----

    // ... (all previously existing methods like createSchoolAdminUser, getSchoolStatistics, etc., remain exactly as they were) ...

    // For brevity, we are not repeating all the unchanged methods here,
    // but they should be kept in the final file exactly as in your original Tenant.php.
    // The above includes all table definitions and the modified createSchoolDatabase / createTablesOnly / insertDefaultData.
    // Ensure you merge this with your existing helper methods (logInfo, logError, etc.) and other functions.


    /**
     * Create performance indexes
     * @param PDO $db
     */
    private static function createPerformanceIndexes($db)
    {
        try {
            // Add performance indexes for commonly queried columns
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_users_email_type ON users(email, user_type)",
                "CREATE INDEX IF NOT EXISTS idx_students_admission_date ON students(admission_date)",
                "CREATE INDEX IF NOT EXISTS idx_attendance_student_date ON attendance(student_id, date)",
                "CREATE INDEX IF NOT EXISTS idx_payments_invoice_date ON payments(invoice_id, payment_date)",
                "CREATE INDEX IF NOT EXISTS idx_subscriptions_status_end ON subscriptions(status, current_period_end)",
                "CREATE INDEX IF NOT EXISTS idx_storage_usage_school_type ON storage_usage(school_id, storage_type)",
                "CREATE INDEX IF NOT EXISTS idx_api_logs_school_endpoint ON api_logs(school_id, endpoint, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_audit_logs_school_action ON audit_logs(school_id, action, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_backup_history_school_status ON backup_history(school_id, status, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_notifications_school_user_read ON notifications(school_id, user_id, is_read, created_at)"
            ];

            foreach ($indexes as $indexSql) {
                try {
                    $db->exec($indexSql);
                } catch (Exception $e) {
                    self::logWarning("Failed to create index: " . $e->getMessage());
                }
            }

            self::logInfo("Created performance indexes");
        } catch (Exception $e) {
            self::logError("Error creating performance indexes", $e);
        }
    }


    /**
     * Original createSchoolDatabase method (renamed)
     */
    private static function createSchoolDatabaseFull($schoolData)
    {
        try {
            // Validate required data
            $requiredFields = ['id', 'admin_name', 'admin_email', 'admin_phone', 'admin_password'];
            foreach ($requiredFields as $field) {
                if (!isset($schoolData[$field]) || empty($schoolData[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            // Generate database name based on school ID
            $dbName = DB_SCHOOL_PREFIX . $schoolData['id'];
            self::logInfo("Creating school database: " . $dbName);

            // Rest of your original method continues...
            // [Keep all your existing code here]

        } catch (Exception $e) {
            self::logError("Failed to create school database", $e);
            return [
                'success' => false,
                'message' => 'Failed to create school database: ' . $e->getMessage()
            ];
        }
    }

// Add to Tenant class
    /**
     * Create school admin user (for provisioning compatibility)
     */
    public static function createSchoolAdminUser($schoolId, $adminEmail, $adminPassword, $adminName = 'School Admin')
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return null;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);
            $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT);

            // Insert user
            $stmt = $schoolDb->prepare("
            INSERT INTO users 
            (school_id, name, email, password, user_type, is_active, created_at) 
            VALUES (?, ?, ?, ?, 'admin', 1, NOW())
        ");

            $stmt->execute([$schoolId, $adminName, $adminEmail, $hashedPassword]);

            $adminUserId = $schoolDb->lastInsertId();

            // Assign role
            $roleStmt = $schoolDb->prepare("
            INSERT INTO user_roles (user_id, role_id) 
            SELECT ?, id FROM roles 
            WHERE slug = 'school_admin' AND school_id = ? 
            LIMIT 1
        ");

            $roleStmt->execute([$adminUserId, $schoolId]);

            return $adminUserId;
        } catch (Exception $e) {
            self::logError("Failed to create admin user", $e);
            return null;
        }
    }

    /**
     * Get connection to school database (for provisioning compatibility)
     */
    public static function getSchoolConnection($schoolId)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return null;
            }

            return Database::getSchoolConnection($school['database_name']);
        } catch (Exception $e) {
            self::logError("Failed to get school connection", $e);
            return null;
        }
    }

    /**
     * Get school statistics for dashboard
     * @param int $schoolId
     * @return array
     */
    public static function getSchoolStatistics($schoolId)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return ['students' => 0, 'teachers' => 0, 'admins' => 0, 'parents' => 0];
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            $stats = [
                'students' => 0,
                'teachers' => 0,
                'admins' => 0,
                'parents' => 0
            ];

            // Get student count
            try {
                $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
                $stmt->execute();
                $result = $stmt->fetch();
                $stats['students'] = (int)$result['count'] ?? 0;
            } catch (Exception $e) {
                self::logError("Error counting students", $e);
            }

            // Get teacher count
            try {
                $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM teachers WHERE is_active = 1");
                $stmt->execute();
                $result = $stmt->fetch();
                $stats['teachers'] = (int)$result['count'] ?? 0;
            } catch (Exception $e) {
                self::logError("Error counting teachers", $e);
            }

            // Get admin count
            try {
                $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin' AND is_active = 1");
                $stmt->execute();
                $result = $stmt->fetch();
                $stats['admins'] = (int)$result['count'] ?? 0;
            } catch (Exception $e) {
                self::logError("Error counting admins", $e);
            }

            // Get parent count
            try {
                $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM users WHERE user_type = 'parent' AND is_active = 1");
                $stmt->execute();
                $result = $stmt->fetch();
                $stats['parents'] = (int)$result['count'] ?? 0;
            } catch (Exception $e) {
                self::logError("Error counting parents", $e);
            }

            return $stats;
        } catch (Exception $e) {
            self::logError("Error getting school statistics", $e);
            return ['students' => 0, 'teachers' => 0, 'admins' => 0, 'parents' => 0];
        }
    }

    /**
     * Create initial admin user in school database
     * @param PDO $db School database connection
     * @param array $schoolData
     * @return int|false Admin user ID
     */
    private static function createInitialAdmin($db, $schoolData, $campusId = null)
    {
        try {
            $hashedPassword = password_hash($schoolData['admin_password'], PASSWORD_BCRYPT);

            // Insert admin user
            $stmt = $db->prepare("
            INSERT INTO users 
            (school_id, campus_id, name, email, phone, password, user_type, is_active, email_verified_at, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'admin', 1, NOW(), NOW(), NOW())
        ");

            $stmt->execute([
                $schoolData['id'],
                $campusId,
                $schoolData['admin_name'],  // This should match the key in $adminData
                $schoolData['admin_email'], // This should match the key in $adminData
                $schoolData['admin_phone'], // This should match the key in $adminData
                $hashedPassword
            ]);

            $adminUserId = $db->lastInsertId();
            self::logInfo("Admin user created with ID: " . $adminUserId);

            // Get school_admin role ID
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'school_admin' AND school_id = ? LIMIT 1");
            $roleStmt->execute([$schoolData['id']]);
            $role = $roleStmt->fetch();

            if ($role) {
                $roleId = $role['id'];

                // Assign role to user
                $userRoleStmt = $db->prepare("INSERT INTO user_roles (school_id, campus_id, user_id, role_id) VALUES (?, ?, ?, ?)");
                $userRoleStmt->execute([$schoolData['id'], $campusId, $adminUserId, $roleId]);
                self::logInfo("Assigned role ID " . $roleId . " to admin user");
            } else {
                self::logWarning("school_admin role not found for school ID " . $schoolData['id']);
                return false;
            }

            return $adminUserId;
        } catch (Exception $e) {
            self::logError("Failed to create initial admin", $e);
            return false;
        }
    }

    /**
     * Initialize subscription data for new school
     * @param PDO $db
     * @param int $schoolId
     */
    private static function initializeSubscriptionData($db, $schoolId)
    {
        try {
            // Insert default free subscription
            $stmt = $db->prepare("
                INSERT INTO subscriptions 
                (school_id, plan_id, plan_name, status, billing_cycle, amount, 
                 storage_limit, user_limit, student_limit, 
                 current_period_start, current_period_end, created_at) 
                VALUES (?, 'free_tier', 'Free Plan', 'active', 'monthly', 0.00, 
                        1073741824, 100, 500, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), NOW())
            ");
            $stmt->execute([$schoolId]);

            self::logInfo("Initialized subscription data for school ID: " . $schoolId);
        } catch (Exception $e) {
            self::logError("Error initializing subscription data", $e);
        }
    }

    /**
     * Create initial backup for new school
     * @param int $schoolId
     */
    private static function createInitialBackup($schoolId)
    {
        try {
            // This would call your backup method
            // For now, just log it
            self::logInfo("Initial backup triggered for school ID: " . $schoolId);
        } catch (Exception $e) {
            self::logError("Error creating initial backup", $e);
        }
    }

    /**
     * =================================================================
     * ENHANCED FEATURE METHODS
     * =================================================================
     */

    /**
     * Check subscription limits before creating school
     * @param int $schoolId
     * @return bool
     */
    private static function checkSubscriptionLimits($schoolId)
    {
        // This would check against platform-wide subscription limits
        // For now, we'll just return true
        return true;
    }

    /**
     * Check if school has exceeded storage limits
     * @param int $schoolId
     * @param string $storageType
     * @return array [isExceeded, usedBytes, limitBytes, percentage]
     */
    public static function checkStorageLimit($schoolId, $storageType = 'total')
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return [false, 0, 0, 0];
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            if ($storageType === 'total') {
                $stmt = $schoolDb->prepare("
                    SELECT SUM(used_bytes) as total_used, SUM(limit_bytes) as total_limit 
                    FROM storage_usage 
                    WHERE school_id = ?
                ");
                $stmt->execute([$schoolId]);
            } else {
                $stmt = $schoolDb->prepare("
                    SELECT used_bytes, limit_bytes 
                    FROM storage_usage 
                    WHERE school_id = ? AND storage_type = ?
                ");
                $stmt->execute([$schoolId, $storageType]);
            }

            $result = $stmt->fetch();

            if (!$result) {
                return [false, 0, 0, 0];
            }

            $usedBytes = (int)$result['total_used'] ?? (int)$result['used_bytes'];
            $limitBytes = (int)$result['total_limit'] ?? (int)$result['limit_bytes'];
            $percentage = $limitBytes > 0 ? ($usedBytes / $limitBytes) * 100 : 0;

            $isExceeded = $usedBytes >= $limitBytes;

            // Create alert if approaching limit (80% or more)
            if ($percentage >= 80 && $percentage < 100) {
                self::createStorageAlert($schoolId, 'warning', $percentage, $storageType);
            } elseif ($isExceeded) {
                self::createStorageAlert($schoolId, 'critical', 100, $storageType);
            }

            return [$isExceeded, $usedBytes, $limitBytes, $percentage];
        } catch (Exception $e) {
            self::logError("Error checking storage limit", $e);
            return [false, 0, 0, 0];
        }
    }

    /**
     * Update storage usage
     * @param int $schoolId
     * @param string $storageType
     * @param int $additionalBytes
     * @return bool
     */
    public static function updateStorageUsage($schoolId, $storageType, $additionalBytes)
    {
        try {
            // Check current limit before updating
            list($isExceeded, $usedBytes, $limitBytes) = self::checkStorageLimit($schoolId, $storageType);

            if ($isExceeded && $additionalBytes > 0) {
                throw new Exception("Storage limit exceeded for $storageType");
            }

            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            $stmt = $schoolDb->prepare("
                INSERT INTO storage_usage (school_id, storage_type, used_bytes, limit_bytes) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                used_bytes = used_bytes + VALUES(used_bytes),
                last_calculated = NOW()
            ");

            $limitBytes = self::getStorageLimitForSchool($schoolId, $storageType);

            $stmt->execute([
                $schoolId,
                $storageType,
                $additionalBytes,
                $limitBytes
            ]);

            self::logInfo("Updated storage usage for school $schoolId, type $storageType: +$additionalBytes bytes");

            return true;
        } catch (Exception $e) {
            self::logError("Error updating storage usage", $e);
            return false;
        }
    }

    /**
     * Get storage limit for school based on subscription
     * @param int $schoolId
     * @param string $storageType
     * @return int
     */
    private static function getStorageLimitForSchool($schoolId, $storageType)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return self::$storageLimits['free'];
            }

            // Get plan from platform database
            $platformDb = Database::getPlatformConnection();
            $stmt = $platformDb->prepare("
            SELECT p.storage_limit 
            FROM schools s
            JOIN plans p ON s.plan_id = p.id
            WHERE s.id = ?
        ");
            $stmt->execute([$schoolId]);
            $result = $stmt->fetch();

            if (!$result || empty($result['storage_limit'])) {
                return self::$storageLimits['free'];
            }

            $totalLimit = (int)$result['storage_limit'] * 1024 * 1024; // Convert MB to bytes

            // Same allocation logic as before
            $allocations = [
                'starter' => ['database' => 0.3, 'files' => 0.4, 'backups' => 0.2, 'attachments' => 0.1],
                'growth' => ['database' => 0.4, 'files' => 0.3, 'backups' => 0.2, 'attachments' => 0.1],
                'enterprise' => ['database' => 0.5, 'files' => 0.3, 'backups' => 0.1, 'attachments' => 0.1]
            ];

            $planSlug = $school['plan_name'] ?? 'starter';
            $allocation = $allocations[$planSlug] ?? $allocations['starter'];

            if ($storageType === 'total') {
                return $totalLimit;
            }

            return (int)($totalLimit * ($allocation[$storageType] ?? 0.1));
        } catch (Exception $e) {
            return self::$storageLimits['free'];
        }
    }

    /**
     * Check if enhanced features are available
     */
    public static function hasEnhancedFeatures($schoolId)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            // Check if storage_usage table exists
            $tables = $schoolDb->query("SHOW TABLES LIKE 'storage_usage'")->fetchAll();

            return count($tables) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Safe storage check with fallback
     */
    public static function safeCheckStorageLimit($schoolId, $storageType = 'total')
    {
        if (!self::hasEnhancedFeatures($schoolId)) {
            // Return unlimited for schools without enhanced features
            return [false, 0, PHP_INT_MAX, 0];
        }

        return self::checkStorageLimit($schoolId, $storageType);
    }

    /**
     * Create storage alert
     * @param int $schoolId
     * @param string $severity
     * @param float $percentage
     * @param string $storageType
     */
    private static function createStorageAlert($schoolId, $severity, $percentage, $storageType)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            $title = "Storage Limit " . ($percentage >= 100 ? "Exceeded" : "Warning");
            $message = "Storage usage for $storageType is at " . round($percentage, 1) . "% of limit";

            $stmt = $schoolDb->prepare("
                INSERT INTO system_alerts 
                (school_id, alert_type, severity, title, message, data, created_at) 
                VALUES (?, 'storage_limit', ?, ?, ?, ?, NOW())
            ");

            $data = json_encode([
                'storage_type' => $storageType,
                'percentage' => $percentage,
                'threshold' => $percentage >= 100 ? 'exceeded' : 'warning'
            ]);

            $stmt->execute([$schoolId, $severity, $title, $message, $data]);
        } catch (Exception $e) {
            self::logError("Error creating storage alert", $e);
        }
    }

    /**
     * Track performance metric
     * @param string $metricType
     * @param int $schoolId
     * @param array $data
     */
    public static function logPerformanceMetric($metricType, $schoolId, $data = [])
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            $endpoint = $data['endpoint'] ?? null;
            $value = $data['value'] ?? 0;
            $unit = $data['unit'] ?? null;

            $stmt = $schoolDb->prepare("
                INSERT INTO performance_metrics 
                (school_id, metric_type, endpoint, value, unit, metadata, recorded_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            $metadata = json_encode($data);

            $stmt->execute([$schoolId, $metricType, $endpoint, $value, $unit, $metadata]);
        } catch (Exception $e) {
            self::logError("Error logging performance metric", $e);
        }
    }

    /**
     * Check API rate limit
     * @param int $schoolId
     * @param string $endpoint
     * @param string $ipAddress
     * @param int $userId
     * @param int $limit
     * @param int $windowSeconds
     * @return array [allowed, remaining, resetTime]
     */
    public static function checkRateLimit($schoolId, $endpoint, $ipAddress, $userId = null, $limit = 60, $windowSeconds = 60)
    {
        $key = "{$schoolId}_{$endpoint}_{$ipAddress}" . ($userId ? "_{$userId}" : '');

        if (!isset(self::$rateLimits[$key])) {
            self::$rateLimits[$key] = [
                'count' => 0,
                'first_request' => time(),
                'window_reset' => time() + $windowSeconds
            ];
        }

        $rateLimit = self::$rateLimits[$key];

        // Reset if window has passed
        if (time() > $rateLimit['window_reset']) {
            $rateLimit['count'] = 0;
            $rateLimit['first_request'] = time();
            $rateLimit['window_reset'] = time() + $windowSeconds;
        }

        // Check if limit exceeded
        if ($rateLimit['count'] >= $limit) {
            // Log security event
            self::logSecurityEvent($schoolId, 'rate_limit_exceeded', $endpoint, $ipAddress, $userId);

            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_time' => $rateLimit['window_reset'],
                'retry_after' => $rateLimit['window_reset'] - time()
            ];
        }

        // Increment count
        $rateLimit['count']++;
        self::$rateLimits[$key] = $rateLimit;

        // Also log to database for persistence
        self::logRateLimitToDatabase($schoolId, $endpoint, $ipAddress, $userId, $rateLimit['count']);

        return [
            'allowed' => true,
            'remaining' => $limit - $rateLimit['count'],
            'reset_time' => $rateLimit['window_reset']
        ];
    }

    /**
     * Log rate limit to database
     * @param int $schoolId
     * @param string $endpoint
     * @param string $ipAddress
     * @param int $userId
     * @param int $requestCount
     */
    private static function logRateLimitToDatabase($schoolId, $endpoint, $ipAddress, $userId, $requestCount)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            $stmt = $schoolDb->prepare("
                INSERT INTO rate_limits 
                (school_id, endpoint, ip_address, user_id, request_count, window_reset, first_request, last_request) 
                VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                request_count = VALUES(request_count),
                last_request = NOW(),
                window_reset = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
            ");

            $stmt->execute([$schoolId, $endpoint, $ipAddress, $userId, $requestCount]);
        } catch (Exception $e) {
            self::logError("Error logging rate limit", $e);
        }
    }

    /**
     * Log security event
     * @param int $schoolId
     * @param string $eventType
     * @param string $endpoint
     * @param string $ipAddress
     * @param int $userId
     */
    private static function logSecurityEvent($schoolId, $eventType, $endpoint, $ipAddress, $userId = null)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);

            $severity = in_array($eventType, ['rate_limit_exceeded', 'suspicious_activity']) ? 'high' : 'medium';
            $details = "Endpoint: $endpoint, IP: $ipAddress";

            $stmt = $schoolDb->prepare("
                INSERT INTO security_logs 
                (school_id, event_type, severity, user_id, ip_address, details, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([$schoolId, $eventType, $severity, $userId, $ipAddress, $details]);
        } catch (Exception $e) {
            self::logError("Error logging security event", $e);
        }
    }

    /**
     * =================================================================
     * LOGGING METHODS
     * =================================================================
     */

    /**
     * Log info message
     * @param string $message
     */
    private static function logInfo($message)
    {
        error_log("[INFO] " . $message);
    }

    /**
     * Log warning message
     * @param string $message
     */
    private static function logWarning($message)
    {
        error_log("[WARNING] " . $message);
    }

    /**
     * Log error message
     * @param string $message
     * @param Exception $exception
     */
    private static function logError($message, $exception = null)
    {
        $fullMessage = $message;
        if ($exception) {
            $fullMessage .= " - " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine();
        }

        error_log("[ERROR] " . $fullMessage);
    }

    /**
     * =================================================================
     * ADDITIONAL METHODS FROM ORIGINAL TENANT.PHP
     * =================================================================
     */

    /**
     * Create school directories
     * @param int $schoolId
     * @return bool
     */
    public static function createSchoolDirectories($schoolId)
    {
        try {
            $basePath = realpath(__DIR__ . '/../../../') . '/assets/uploads/schools/';

            self::logInfo("Creating directories at: " . $basePath);

            // Create base uploads directory if it doesn't exist
            if (!file_exists($basePath)) {
                if (!mkdir($basePath, 0755, true)) {
                    self::logError("Failed to create base uploads directory");
                    return false;
                }
            }

            // Create school directory
            $schoolPath = $basePath . $schoolId . '/';
            if (!file_exists($schoolPath)) {
                if (!mkdir($schoolPath, 0755, true)) {
                    self::logError("Failed to create school directory: " . $schoolPath);
                    return false;
                }
            }

            // Create logo directory
            $logoDir = $schoolPath . 'logo/';
            if (!file_exists($logoDir)) {
                if (!mkdir($logoDir, 0755, true)) {
                    self::logError("Failed to create logo directory: " . $logoDir);
                    return false;
                }
            }

            // Create other directories
            $subDirs = ['students/photos', 'students/documents', 'teachers/photos', 'reports', 'temp'];
            foreach ($subDirs as $dir) {
                $fullPath = $schoolPath . $dir . '/';
                if (!file_exists($fullPath)) {
                    @mkdir($fullPath, 0755, true);
                }
            }

            return true;
        } catch (Exception $e) {
            self::logError("Directory creation error", $e);
            return false;
        }
    }

    /**
     * Ensure a school-specific tenant portal folder exists.
     *
     * @param array $school Must include a slug key.
     * @return bool
     */
    public static function ensureSchoolPortal(array $school)
    {
        try {
            $slug = $school['slug'] ?? '';
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                self::logWarning("Cannot create school portal with invalid slug: " . $slug);
                return false;
            }

            $portalPath = dirname(__DIR__) . '/tenant/' . $slug;
            if (is_dir($portalPath) && file_exists($portalPath . '/login.php')) {
                self::logInfo("School portal already exists for slug: " . $slug);
                return true;
            }

            $portalCreatorPath = __DIR__ . '/PortalCreator.php';
            if (!class_exists('PortalCreator') && file_exists($portalCreatorPath)) {
                require_once $portalCreatorPath;
            }

            if (!class_exists('PortalCreator')) {
                self::logWarning("PortalCreator class not available while creating portal for slug: " . $slug);
                return false;
            }

            $created = PortalCreator::createSchoolPortal($slug);
            if ($created) {
                self::logInfo("School portal created for slug: " . $slug);
            }

            return (bool)$created;
        } catch (Exception $e) {
            self::logError("Failed to ensure school portal", $e);
            return false;
        }
    }

    /**
     * Split SQL into individual queries
     * @param string $sql
     * @return array
     */
    private static function splitSql($sql)
    {
        $queries = [];
        $currentQuery = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $commentType = '';

        $sql = trim($sql);
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = $i < $length - 1 ? $sql[$i + 1] : '';

            // Handle comments
            if (!$inString) {
                // Single line comment
                if ($char == '#' || ($char == '-' && $nextChar == '-')) {
                    $inComment = true;
                    $commentType = 'single';
                    $i += ($char == '-' && $nextChar == '-') ? 1 : 0;
                    continue;
                }

                // Multi-line comment
                if ($char == '/' && $nextChar == '*') {
                    $inComment = true;
                    $commentType = 'multi';
                    $i++;
                    continue;
                }

                // End of multi-line comment
                if ($inComment && $commentType == 'multi' && $char == '*' && $nextChar == '/') {
                    $inComment = false;
                    $i++;
                    continue;
                }

                // End of single line comment
                if ($inComment && $commentType == 'single' && ($char == "\n" || $char == "\r")) {
                    $inComment = false;
                }

                // Skip comment characters
                if ($inComment) {
                    continue;
                }
            }

            // Handle string literals
            if (($char == "'" || $char == '"') && ($i == 0 || $sql[$i - 1] != '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char == $stringChar) {
                    $inString = false;
                }
            }

            $currentQuery .= $char;

            // End of query (semicolon outside string and comments)
            if ($char == ';' && !$inString && !$inComment) {
                $queries[] = trim($currentQuery);
                $currentQuery = '';
            }
        }

        // Add any remaining query
        if (trim($currentQuery) !== '') {
            $queries[] = trim($currentQuery);
        }

        return array_filter($queries, function ($query) {
            return !empty(trim($query));
        });
    }

    /**
     * Get school upload path
     * @param int $schoolId
     * @param string $type
     * @return string
     */
    public static function getSchoolUploadPath($schoolId, $type = '')
    {
        $basePath = __DIR__ . '/../../assets/uploads/schools/' . $schoolId . '/';

        if (empty($type)) {
            return $basePath;
        }

        $typePaths = [
            'logo' => 'logo/',
            'student_photo' => 'students/photos/',
            'student_document' => 'students/documents/',
            'student_assignment' => 'students/assignments/',
            'teacher_photo' => 'teachers/photos/',
            'teacher_document' => 'teachers/documents/',
            'parent_document' => 'parents/documents/',
            'assignment' => 'assignments/',
            'report' => 'reports/',
            'timetable' => 'timetables/',
            'announcement' => 'announcements/',
            'library' => 'library/',
            'temp' => 'temp/'
        ];

        if (isset($typePaths[$type])) {
            return $basePath . $typePaths[$type];
        }

        return $basePath . $type . '/';
    }

    /**
     * Get school file URL for web access
     * @param int $schoolId
     * @param string $path
     * @return string
     */
    public static function getSchoolFileUrl($schoolId, $path)
    {
        return APP_URL . '/assets/uploads/schools/' . $schoolId . '/' . ltrim($path, '/');
    }

    /**
     * Get all schools
     * @param string $status
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAllSchools($status = null, $limit = 0, $offset = 0)
    {
        try {
            $db = Database::getPlatformConnection();

            $where = '';
            $params = [];

            if ($status) {
                $where = "WHERE status = ?";
                $params[] = $status;
            }

            $sql = "SELECT * FROM schools $where ORDER BY created_at DESC";

            if ($limit > 0) {
                $sql .= " LIMIT $limit";
                if ($offset > 0) {
                    $sql .= " OFFSET $offset";
                }
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            self::logError("Failed to get all schools", $e);
            return [];
        }
    }

    /**
     * Count schools by status
     * @param string $status
     * @return int
     */
    public static function countSchools($status = null)
    {
        try {
            $db = Database::getPlatformConnection();

            $where = '';
            $params = [];

            if ($status) {
                $where = "WHERE status = ?";
                $params[] = $status;
            }

            $sql = "SELECT COUNT(*) as count FROM schools $where";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();

            return (int)$result['count'];
        } catch (Exception $e) {
            self::logError("Failed to count schools", $e);
            return 0;
        }
    }

    /**
     * Update school status
     * @param int $schoolId
     * @param string $status
     * @return bool
     */
    public static function updateSchoolStatus($schoolId, $status)
    {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("UPDATE schools SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $schoolId]);
            return true;
        } catch (Exception $e) {
            self::logError("Failed to update school status", $e);
            return false;
        }
    }

    /**
     * Delete school (soft delete)
     * @param int $schoolId
     * @return bool
     */
    public static function deleteSchool($schoolId)
    {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("UPDATE schools SET status = 'deleted', deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$schoolId]);
            return true;
        } catch (Exception $e) {
            self::logError("Failed to delete school", $e);
            return false;
        }
    }

    /**
     * Backup school database
     * @param int $schoolId
     * @return string|false Backup file path
     */
    public static function backupSchoolDatabase($schoolId)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }

            $backupDir = __DIR__ . '/../../../backups/schools/' . $schoolId;
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $backupFile = $backupDir . '/' . $school['database_name'] . '_' . date('Y-m-d_H-i-s') . '.sql';

            return Database::backupDatabase($school['database_name'], $backupFile);
        } catch (Exception $e) {
            self::logError("Failed to backup school database", $e);
            return false;
        }
    }


    /**
     * Test database connection
     */
    public static function testConnection($host, $username, $password, $database = null)
    {
        try {
            if ($database) {
                $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
            } else {
                $dsn = "mysql:host=$host;charset=utf8mb4";
            }

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];

            new PDO($dsn, $username, $password, $options);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    private static function validateDatabaseCreation()
    {
        // Check if root access is allowed
        if (!defined('ALLOW_ROOT_DB_CREATION') || !ALLOW_ROOT_DB_CREATION) {
            throw new Exception("Database creation via root is disabled");
        }

        // Validate credentials exist
        if (!defined('ROOT_DB_USER') || empty(ROOT_DB_USER)) {
            throw new Exception("Root database user not configured");
        }

        // Rate limiting
        $maxPerHour = 10; // Adjust based on your needs
        $count = self::getRecentDatabaseCreations();
        if ($count >= $maxPerHour) {
            throw new Exception("Rate limit exceeded: Maximum $maxPerHour databases per hour");
        }
    }

    private static function getRecentDatabaseCreations()
    {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM school_database_credentials 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
            $stmt->execute();
            $result = $stmt->fetch();
            return (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Restore school database from backup
     * @param int $schoolId
     * @param string $backupFile
     * @return bool
     */
    public static function restoreSchoolDatabase($schoolId, $backupFile)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }

            if (!file_exists($backupFile)) {
                return false;
            }

            return Database::restoreDatabase($school['database_name'], $backupFile);
        } catch (Exception $e) {
            self::logError("Failed to restore school database", $e);
            return false;
        }
    }
}
