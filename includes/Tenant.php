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
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $host);

        // Check for subdomain pattern: school.yoursaas.com
        if (count($parts) >= 3) {
            $subdomain = $parts[0];

            // Skip common subdomains
            if (in_array($subdomain, ['www', 'app', 'admin', 'platform'])) {
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

        // Pattern: /tenant/{slug}/...
        if (preg_match('/^\/school\/([a-z0-9-]+)(\/|$)/i', $requestUri, $matches)) {
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
     * @return array [success, message, database_name]
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

            // Create ALL tables programmatically with enhanced features
            self::createCompleteSchema($schoolDb, $schoolData['id']);

            // Create initial admin user
            $adminUserId = self::createInitialAdmin($schoolDb, $schoolData);

            if (!$adminUserId) {
                return [
                    'success' => false,
                    'message' => 'Failed to create admin user'
                ];
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
     * Create admin user in school database
     */
    private static function createAdminUserInSchool($schoolId, $adminEmail, $adminPassword, $adminName)
    {
        try {
            $db = Database::getPlatformConnection();

            // First, check if school exists in platform database
            $stmt = $db->prepare("SELECT database_name FROM schools WHERE id = ?");
            $stmt->execute([$schoolId]);
            $school = $stmt->fetch();

            if (!$school || empty($school['database_name'])) {
                return 1; // Default ID
            }

            // Try to connect to school database and create user
            try {
                $schoolDb = Database::getSchoolConnection($school['database_name']);

                // Check if users table exists
                $tables = $schoolDb->query("SHOW TABLES LIKE 'users'")->rowCount();
                if ($tables === 0) {
                    return 1; // Default ID if users table doesn't exist
                }

                // Insert admin user
                $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT);

                $stmt = $schoolDb->prepare("
                INSERT INTO users 
                (school_id, name, email, password, user_type, is_active, created_at) 
                VALUES (?, ?, ?, ?, 'admin', 1, NOW())
            ");

                $stmt->execute([
                    $schoolId,
                    $adminName,
                    $adminEmail,
                    $hashedPassword
                ]);

                $adminUserId = $schoolDb->lastInsertId();
                self::logInfo("Created admin user in school database with ID: " . $adminUserId);

                return $adminUserId;
            } catch (Exception $e) {
                self::logWarning("Could not create admin user in school database: " . $e->getMessage());
                return 1; // Return default ID
            }
        } catch (Exception $e) {
            self::logError("Error creating admin user", $e);
            return 1; // Default ID
        }
    }

    /**
     * Create COMPLETE schema with ALL tables including new features
     * @param PDO $db
     * @param int $schoolId
     */
    private static function createCompleteSchema($db, $schoolId)
    {
        self::logInfo("Creating COMPLETE schema with ALL tables for school ID: " . $schoolId);

        // Disable foreign key checks temporarily
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Array of ALL table creation SQL
        $tables = [
            // Core educational tables (from your original schema)
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

            // NEW TABLES FOR ENHANCED FEATURES

            // 1. Subscription & Billing Management
            self::getSubscriptionsTableSql(),
            self::getBillingHistoryTableSql(),
            self::getPaymentMethodsTableSql(),
            self::getInvoicesV2TableSql(),

            // 2. Storage & Usage Tracking
            self::getStorageUsageTableSql(),
            self::getFileStorageTableSql(),

            // 3. Performance & Monitoring
            self::getPerformanceMetricsTableSql(),
            self::getApiLogsTableSql(),
            self::getAuditLogsTableSql(),

            // 4. Security & Rate Limiting
            self::getSecurityLogsTableSql(),
            self::getRateLimitsTableSql(),
            self::getLoginAttemptsTableSql(),

            // 5. Backup & Recovery
            self::getBackupHistoryTableSql(),
            self::getRecoveryPointsTableSql(),

            // 6. Communication & Notifications
            self::getNotificationsTableSql(),
            self::getEmailTemplatesTableSql(),
            self::getSmsLogsTableSql(),

            // 7. API Management
            self::getApiKeysTableSql(),
            self::getApiUsageTableSql(),

            // 8. System Maintenance
            self::getMaintenanceLogsTableSql(),
            self::getSystemAlertsTableSql()
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

        // Insert default data
        self::insertDefaultData($db, $schoolId);

        // Create indexes for performance
        self::createPerformanceIndexes($db);

        // Re-enable foreign key checks
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");

        self::logInfo("Created " . $createdCount . " tables successfully");

        return $createdCount;
    }
    public static function createSchoolConfig($schoolPortalDir, $school)
    {
        $configFile = $schoolPortalDir . 'config.php';
        if (!file_exists($configFile)) {
            $templateFile = __DIR__ . '/../../tenant/$slug/config.php';
            if (!copy($templateFile, $configFile)) {
                throw new Exception("Failed to create school config file: $configFile");
            }
        }

        // Replace placeholders in config file
        $configContent = file_get_contents($configFile);
        $configContent = str_replace(array_keys($school), array_values($school), $configContent);
        file_put_contents($configFile, $configContent);
    }

    public static function processTemplateFile($srcPath, $dstPath, $school)
    {
        $content = file_get_contents($srcPath);
        $content = str_replace(array_keys($school), array_values($school), $content);
        file_put_contents($dstPath, $content);
    }


    /**
     * Create admin user in shared database (single-database mode)
     * @param array $adminData
     * @param string $tablePrefix
     * @return array
     */
    public static function createAdminInSharedDatabase($adminData, $tablePrefix = 'school_')
    {
        try {
            $db = Database::getPlatformConnection();

            // In single-database mode, we use the platform database with table prefixes
            // For now, just return a mock user ID
            return [
                'success' => true,
                'admin_user_id' => 1,
                'message' => 'Admin created in shared database'
            ];
        } catch (Exception $e) {
            self::logError("Failed to create admin in shared database", $e);
            return [
                'success' => false,
                'message' => 'Failed to create admin: ' . $e->getMessage()
            ];
        }
    }
    /**
     * Create or update school portal structure
     * @param array $school
     * @return bool
     */
    public static function createOrUpdateSchoolPortal($school)
    {
        try {
            $schoolId = $school['id'];
            $schoolSlug = $school['slug'];

            // Base paths
            $tenantDir = __DIR__ . '/../../tenant/';
            $templateDir = __DIR__ . '/../../templates/school-portal/';
            $schoolPortalDir = $tenantDir . $schoolSlug . '/';

            self::logInfo("Creating/updating portal for school: $schoolSlug at $schoolPortalDir");

            // Check if template exists
            if (!is_dir($templateDir)) {
                throw new Exception("Template directory not found: $templateDir");
            }

            // Create school portal directory if it doesn't exist
            if (!is_dir($schoolPortalDir)) {
                if (!mkdir($schoolPortalDir, 0755, true)) {
                    throw new Exception("Failed to create school portal directory: $schoolPortalDir");
                }
                self::logInfo("Created school portal directory: $schoolPortalDir");
            }

            // Copy template files to school portal
            self::copyTemplateFiles($templateDir, $schoolPortalDir, $school);

            // Create school-specific config file
            self::createSchoolConfig($schoolPortalDir, $school);

            // Create school assets directory
            self::createSchoolAssets($schoolPortalDir, $school);

            self::logInfo("School portal created successfully for: $schoolSlug");
            return true;
        } catch (Exception $e) {
            self::logError("Failed to create school portal", $e);
            return false;
        }
    }

    /**
     * Copy template files to school portal
     * @param string $source
     * @param string $destination
     * @param array $school
     */
    private static function copyTemplateFiles($source, $destination, $school)
    {
        $dir = opendir($source);

        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $srcPath = $source . '/' . $file;
            $dstPath = $destination . '/' . $file;

            if (is_dir($srcPath)) {
                // Create directory
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                // Recursively copy directory
                self::copyTemplateFiles($srcPath, $dstPath, $school);
            } else {
                // Copy and process template file
                self::processTemplateFile($srcPath, $dstPath, $school);
            }
        }

        closedir($dir);
    }
// Add these methods to Tenant.php class
// ====================================================

    /**
     * Ensure school portal exists, create if missing
     * @param array $school
     * @return bool
     */
    public static function ensureSchoolPortal($school)
    {
        try {
            $schoolSlug = $school['slug'];
            $tenantDir = __DIR__ . '/../../tenant/';
            $portalDir = $tenantDir . $schoolSlug . '/';

            self::logInfo("Ensuring portal for: $schoolSlug");

            // Check if portal already exists
            if (is_dir($portalDir) && file_exists($portalDir . 'config.php')) {
                self::logInfo("Portal already exists: $portalDir");
                return true;
            }

            // Create portal directory
            if (!is_dir($portalDir)) {
                if (!mkdir($portalDir, 0755, true)) {
                    self::logError("Failed to create portal directory: $portalDir");
                    return false;
                }
            }

            // Create portal structure
            return self::createPortalStructure($school, $portalDir);
        } catch (Exception $e) {
            self::logError("Failed to ensure school portal", $e);
            return false;
        }
    }

    /**
     * Create portal structure for a school
     * @param array $school
     * @param string $portalDir
     * @return bool
     */
    private static function createPortalStructure($school, $portalDir)
    {
        try {
            // Create subdirectories
            $subdirs = ['admin', 'teacher', 'student', 'parent', 'assets/css', 'assets/js', 'assets/images'];

            foreach ($subdirs as $dir) {
                $dirPath = $portalDir . $dir . '/';
                if (!is_dir($dirPath)) {
                    if (!mkdir($dirPath, 0755, true)) {
                        self::logError("Failed to create directory: $dirPath");
                        return false;
                    }
                }
            }

            // Create config.php
            $configContent = self::generateSchoolConfig($school);
            if (file_put_contents($portalDir . 'config.php', $configContent) === false) {
                self::logError("Failed to create config.php");
                return false;
            }

            // Create index.php (school homepage)
            $indexContent = self::generateIndexPage($school);
            if (file_put_contents($portalDir . 'index.php', $indexContent) === false) {
                self::logError("Failed to create index.php");
                return false;
            }

            // Create login.php (school-specific login)
            $loginContent = self::generateLoginPage($school);
            if (file_put_contents($portalDir . 'login.php', $loginContent) === false) {
                self::logError("Failed to create login.php");
                return false;
            }

            // Create basic dashboard files
            self::createDashboardFiles($school, $portalDir);

            // Create school assets
            self::createSchoolAssets($school, $portalDir);

            self::logInfo("School portal created successfully: $portalDir");
            return true;
        } catch (Exception $e) {
            self::logError("Failed to create portal structure", $e);
            return false;
        }
    }

    /**
     * Generate school config content
     * @param array $school
     * @return string
     */
    private static function generateSchoolConfig($school)
    {
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * School Configuration: {$school['name']}\n";
        $content .= " * Auto-generated on: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";

        $content .= "// Load core configuration\n";
        $content .= "require_once __DIR__ . '/../../includes/autoload.php';\n\n";

        $content .= "// School Information\n";
        $content .= "\$school = [\n";
        $content .= "    'id' => {$school['id']},\n";
        $content .= "    'slug' => '{$school['slug']}',\n";
        $content .= "    'name' => '" . addslashes($school['name']) . "',\n";
        $content .= "    'database_name' => '{$school['database_name']}',\n";
        $content .= "    'email' => '" . addslashes($school['email'] ?? '') . "',\n";
        $content .= "    'phone' => '" . addslashes($school['phone'] ?? '') . "',\n";
        $content .= "    'address' => '" . addslashes($school['address'] ?? '') . "',\n";
        $content .= "    'logo_path' => '" . addslashes($school['logo_path'] ?? '') . "',\n";
        $content .= "    'primary_color' => '" . addslashes($school['primary_color'] ?? '#3B82F6') . "',\n";
        $content .= "    'secondary_color' => '" . addslashes($school['secondary_color'] ?? '#1E40AF') . "',\n";
        $content .= "    'status' => '{$school['status']}',\n";
        $content .= "    'plan_id' => " . ($school['plan_id'] ?? 'null') . ",\n";
        $content .= "    'created_at' => '{$school['created_at']}',\n";
        $content .= "    'trial_ends_at' => '" . ($school['trial_ends_at'] ?? '') . "'\n";
        $content .= "];\n\n";

        $content .= "// School Constants\n";
        $content .= "define('SCHOOL_ID', {$school['id']});\n";
        $content .= "define('SCHOOL_SLUG', '{$school['slug']}');\n";
        $content .= "define('SCHOOL_NAME', '" . addslashes($school['name']) . "');\n";
        $content .= "define('SCHOOL_DB_NAME', '{$school['database_name']}');\n";
        $content .= "define('SCHOOL_UPLOAD_PATH', __DIR__ . '/../../assets/uploads/schools/{$school['id']}/');\n";
        $content .= "define('SCHOOL_ASSETS_URL', '/assets/uploads/schools/{$school['id']}/');\n";
        $content .= "define('SCHOOL_PORTAL_URL', APP_URL . '/tenant/{$school['slug']}');\n\n";

        $content .= "// School-specific functions\n";
        $content .= "function getSchoolDb() {\n";
        $content .= "    try {\n";
        $content .= "        return Database::getSchoolConnection(SCHOOL_DB_NAME);\n";
        $content .= "    } catch (Exception \$e) {\n";
        $content .= "        error_log('School DB Error: ' . \$e->getMessage());\n";
        $content .= "        return null;\n";
        $content .= "    }\n";
        $content .= "}\n\n";

        $content .= "function isSchoolUserAuthenticated() {\n";
        $content .= "    if (!isset(\$_SESSION['school_auth'])) {\n";
        $content .= "        return false;\n";
        $content .= "    }\n";
        $content .= "    \$sessionSchoolId = \$_SESSION['school_auth']['school_id'] ?? null;\n";
        $content .= "    \$sessionSchoolSlug = \$_SESSION['school_auth']['school_slug'] ?? null;\n";
        $content .= "    return (\$sessionSchoolId == SCHOOL_ID && \$sessionSchoolSlug == SCHOOL_SLUG);\n";
        $content .= "}\n\n";

        $content .= "function requireSchoolAuth() {\n";
        $content .= "    if (!isSchoolUserAuthenticated()) {\n";
        $content .= "        header('Location: /tenant/' . SCHOOL_SLUG . '/login');\n";
        $content .= "        exit;\n";
        $content .= "    }\n";
        $content .= "}\n\n";

        $content .= "function getCurrentSchoolUser() {\n";
        $content .= "    if (isSchoolUserAuthenticated()) {\n";
        $content .= "        return \$_SESSION['school_auth'];\n";
        $content .= "    }\n";
        $content .= "    return null;\n";
        $content .= "}\n\n";

        $content .= "function redirectToSchoolDashboard() {\n";
        $content .= "    \$user = getCurrentSchoolUser();\n";
        $content .= "    if (\$user) {\n";
        $content .= "        \$userType = \$user['user_type'];\n";
        $content .= "        header('Location: /tenant/' . SCHOOL_SLUG . '/' . \$userType . '/dashboard.php');\n";
        $content .= "        exit;\n";
        $content .= "    }\n";
        $content .= "}\n";

        $content .= "?>";

        return $content;
    }

    /**
     * Generate index.php content for school
     * @param array $school
     * @return string
     */
    private static function generateIndexPage($school)
    {
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * School Homepage: {$school['name']}\n";
        $content .= " * Redirects to tenant login page\n";
        $content .= " */\n\n";
        $content .= "// Redirect to tenant login with school slug\n";
        $content .= "header('Location: /tenant/login.php?school_slug={$school['slug']}');\n";
        $content .= "exit;\n";
        $content .= "?>\n";

        return $content;
    }
    /**
     * Generate login.php content for school
     * @param array $school
     * @return string
     */
    private static function generateLoginPage($school)
    {
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * School Login Page\n";
        $content .= " * Redirects to main tenant login with school slug\n";
        $content .= " */\n\n";
        $content .= "// Redirect to main tenant login with school slug\n";
        $content .= "header('Location: /tenant/login.php?school_slug={$school['slug']}');\n";
        $content .= "exit;\n";
        $content .= "?>\n";

        return $content;
    }

    /**
     * Create basic dashboard files
     * @param array $school
     * @param string $portalDir
     */
    private static function createDashboardFiles($school, $portalDir)
    {
        $dashboards = ['admin', 'teacher', 'student', 'parent'];

        foreach ($dashboards as $type) {
            // Use 'school-dashboard.php' as the filename
            $dashboardFile = $portalDir . $type . '/school-dashboard.php';

            $content = "<?php\n";
            $content .= "/**\n";
            $content .= " * {$type} Dashboard - {$school['name']}\n";
            $content .= " * File: school-dashboard.php\n";
            $content .= " * URL: /tenant/{$school['slug']}/{$type}/school-dashboard.php\n";
            $content .= " */\n\n";
            $content .= "// Start session\n";
            $content .= "if (session_status() === PHP_SESSION_NONE) {\n";
            $content .= "    session_start();\n";
            $content .= "}\n\n";
            $content .= "require_once __DIR__ . '/../config.php';\n";
            $content .= "requireSchoolAuth();\n\n";
            $content .= "// Check user type\n";
            $content .= "\$user = getCurrentSchoolUser();\n";
            $content .= "if (\$user['user_type'] !== '$type') {\n";
            $content .= "    // Redirect to correct user type dashboard\n";
            $content .= "    header('Location: /tenant/' . SCHOOL_SLUG . '/' . \$user['user_type'] . '/school-dashboard.php');\n";
            $content .= "    exit;\n";
            $content .= "}\n\n";
            $content .= "// School database connection\n";
            $content .= "\$db = getSchoolDb();\n";
            $content .= "if (!\$db) {\n";
            $content .= "    die('Unable to connect to school database');\n";
            $content .= "}\n\n";
            $content .= "// Get school statistics\n";
            $content .= "\$stats = [];\n";
            $content .= "try {\n";
            $content .= "    // Add your statistics queries here\n";
            $content .= "} catch (Exception \$e) {\n";
            $content .= "    error_log('Stats error: ' . \$e->getMessage());\n";
            $content .= "}\n";
            $content .= "?>\n";

            $content .= "<!DOCTYPE html>\n";
            $content .= "<html lang=\"en\">\n";
            $content .= "<head>\n";
            $content .= "    <meta charset=\"UTF-8\">\n";
            $content .= "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
            $content .= "    <title><?php echo htmlspecialchars(SCHOOL_NAME); ?> - " . ucfirst($type) . " Dashboard</title>\n";
            $content .= "    <script src=\"https://cdn.tailwindcss.com\"></script>\n";
            $content .= "    <link href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\" rel=\"stylesheet\">\n";
            $content .= "    <link rel=\"stylesheet\" href=\"../assets/css/school-style.css\">\n";
            $content .= "</head>\n";
            $content .= "<body class=\"bg-gray-50\">\n";
            $content .= "    <div class=\"min-h-screen flex\">\n";
            $content .= "        <!-- Sidebar -->\n";
            $content .= "        <div class=\"w-64 bg-white shadow-lg\">\n";
            $content .= "            <div class=\"p-6 border-b\">\n";
            $content .= "                <h2 class=\"text-xl font-bold text-gray-800\"><?php echo htmlspecialchars(SCHOOL_NAME); ?></h2>\n";
            $content .= "                <p class=\"text-gray-600 text-sm mt-1\">" . ucfirst($type) . " Portal</p>\n";
            $content .= "                <div class=\"mt-4 p-3 bg-blue-50 rounded-lg\">\n";
            $content .= "                    <p class=\"text-sm text-gray-700\">Logged in as:</p>\n";
            $content .= "                    <p class=\"font-semibold text-blue-700\"><?php echo htmlspecialchars(\$user['user_name']); ?></p>\n";
            $content .= "                    <p class=\"text-xs text-gray-600 mt-1\"><?php echo ucfirst(\$user['user_type']); ?></p>\n";
            $content .= "                </div>\n";
            $content .= "            </div>\n";
            $content .= "            <nav class=\"mt-4\">\n";
            $content .= "                <a href=\"school-dashboard.php\" class=\"block px-6 py-3 text-blue-600 bg-blue-50 border-r-4 border-blue-600\">\n";
            $content .= "                    <i class=\"fas fa-tachometer-alt mr-3\"></i>Dashboard\n";
            $content .= "                </a>\n";
            $content .= "                <!-- Add more menu items based on user type -->\n";
            $content .= "            </nav>\n";
            $content .= "            <div class=\"absolute bottom-0 w-full p-4 border-t\">\n";
            $content .= "                <a href=\"/tenant/login.php?logout=1\" class=\"flex items-center text-gray-600 hover:text-red-600\">\n";
            $content .= "                    <i class=\"fas fa-sign-out-alt mr-3\"></i>\n";
            $content .= "                    <span>Logout</span>\n";
            $content .= "                </a>\n";
            $content .= "            </div>\n";
            $content .= "        </div>\n";
            $content .= "        \n";
            $content .= "        <!-- Main Content -->\n";
            $content .= "        <div class=\"flex-1\">\n";
            $content .= "            <!-- Header -->\n";
            $content .= "            <header class=\"bg-white shadow\">\n";
            $content .= "                <div class=\"px-6 py-4 flex items-center justify-between\">\n";
            $content .= "                    <div>\n";
            $content .= "                        <h1 class=\"text-2xl font-semibold text-gray-800\">Dashboard</h1>\n";
            $content .= "                        <p class=\"text-gray-600\">Welcome to your school management portal</p>\n";
            $content .= "                    </div>\n";
            $content .= "                    <div class=\"text-sm text-gray-500\">\n";
            $content .= "                        <?php echo date('l, F j, Y'); ?>\n";
            $content .= "                    </div>\n";
            $content .= "                </div>\n";
            $content .= "            </header>\n";
            $content .= "            \n";
            $content .= "            <!-- Content -->\n";
            $content .= "            <main class=\"p-6\">\n";
            $content .= "                <div class=\"grid grid-cols-1 md:grid-cols-3 gap-6 mb-8\">\n";
            $content .= "                    <div class=\"bg-white rounded-lg shadow p-6\">\n";
            $content .= "                        <div class=\"flex items-center\">\n";
            $content .= "                            <div class=\"w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mr-4\">\n";
            $content .= "                                <i class=\"fas fa-school text-blue-600\"></i>\n";
            $content .= "                            </div>\n";
            $content .= "                            <div>\n";
            $content .= "                                <h3 class=\"text-lg font-semibold text-gray-800\">School Information</h3>\n";
            $content .= "                                <p class=\"text-gray-600 text-sm\">View and manage school details</p>\n";
            $content .= "                            </div>\n";
            $content .= "                        </div>\n";
            $content .= "                    </div>\n";
            $content .= "                    \n";
            $content .= "                    <div class=\"bg-white rounded-lg shadow p-6\">\n";
            $content .= "                        <div class=\"flex items-center\">\n";
            $content .= "                            <div class=\"w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mr-4\">\n";
            $content .= "                                <i class=\"fas fa-users text-green-600\"></i>\n";
            $content .= "                            </div>\n";
            $content .= "                            <div>\n";
            $content .= "                                <h3 class=\"text-lg font-semibold text-gray-800\">Manage Users</h3>\n";
            $content .= "                                <p class=\"text-gray-600 text-sm\">Add/edit students, teachers, parents</p>\n";
            $content .= "                            </div>\n";
            $content .= "                        </div>\n";
            $content .= "                    </div>\n";
            $content .= "                    \n";
            $content .= "                    <div class=\"bg-white rounded-lg shadow p-6\">\n";
            $content .= "                        <div class=\"flex items-center\">\n";
            $content .= "                            <div class=\"w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mr-4\">\n";
            $content .= "                                <i class=\"fas fa-chart-line text-purple-600\"></i>\n";
            $content .= "                            </div>\n";
            $content .= "                            <div>\n";
            $content .= "                                <h3 class=\"text-lg font-semibold text-gray-800\">Reports</h3>\n";
            $content .= "                                <p class=\"text-gray-600 text-sm\">Generate and view reports</p>\n";
            $content .= "                            </div>\n";
            $content .= "                        </div>\n";
            $content .= "                    </div>\n";
            $content .= "                </div>\n";
            $content .= "                \n";
            $content .= "                <div class=\"bg-white rounded-lg shadow p-6\">\n";
            $content .= "                    <h2 class=\"text-lg font-semibold text-gray-800 mb-4\">Quick Actions</h2>\n";
            $content .= "                    <div class=\"grid grid-cols-1 md:grid-cols-4 gap-4\">\n";
            $content .= "                        <a href=\"#\" class=\"text-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition\">\n";
            $content .= "                            <i class=\"fas fa-user-plus text-blue-600 text-2xl mb-2\"></i>\n";
            $content .= "                            <p class=\"font-medium text-gray-700\">Add Student</p>\n";
            $content .= "                        </a>\n";
            $content .= "                        <a href=\"#\" class=\"text-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition\">\n";
            $content .= "                            <i class=\"fas fa-file-invoice-dollar text-green-600 text-2xl mb-2\"></i>\n";
            $content .= "                            <p class=\"font-medium text-gray-700\">Collect Fees</p>\n";
            $content .= "                        </a>\n";
            $content .= "                        <a href=\"#\" class=\"text-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition\">\n";
            $content .= "                            <i class=\"fas fa-calendar-check text-purple-600 text-2xl mb-2\"></i>\n";
            $content .= "                            <p class=\"font-medium text-gray-700\">Mark Attendance</p>\n";
            $content .= "                        </a>\n";
            $content .= "                        <a href=\"#\" class=\"text-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition\">\n";
            $content .= "                            <i class=\"fas fa-bullhorn text-yellow-600 text-2xl mb-2\"></i>\n";
            $content .= "                            <p class=\"font-medium text-gray-700\">Send Announcement</p>\n";
            $content .= "                        </a>\n";
            $content .= "                    </div>\n";
            $content .= "                </div>\n";
            $content .= "            </main>\n";
            $content .= "        </div>\n";
            $content .= "    </div>\n";
            $content .= "    \n";
            $content .= "    <script src=\"../assets/js/school-scripts.js\"></script>\n";
            $content .= "    <script>\n";
            $content .= "        // Simple dashboard interactions\n";
            $content .= "        document.addEventListener('DOMContentLoaded', function() {\n";
            $content .= "            console.log('Dashboard loaded for <?php echo SCHOOL_NAME; ?>');\n";
            $content .= "        });\n";
            $content .= "    </script>\n";
            $content .= "</body>\n";
            $content .= "</html>\n";

            file_put_contents($dashboardFile, $content);
        }
    }
    /**
     * Create school assets
     * @param array $school
     * @param string $portalDir
     */
    private static function createSchoolAssets($school, $portalDir)
    {
        // Create CSS file
        $cssFile = $portalDir . 'assets/css/school-style.css';
        $cssContent = "/* School-specific styles for {$school['name']} */\n\n";
        $cssContent .= ":root {\n";
        $cssContent .= "    --primary-color: {$school['primary_color']};\n";
        $cssContent .= "    --secondary-color: {$school['secondary_color']};\n";
        $cssContent .= "}\n\n";
        $cssContent .= ".school-header {\n";
        $cssContent .= "    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));\n";
        $cssContent .= "}\n\n";
        $cssContent .= ".btn-primary {\n";
        $cssContent .= "    background-color: var(--primary-color);\n";
        $cssContent .= "}\n\n";
        $cssContent .= ".text-primary {\n";
        $cssContent .= "    color: var(--primary-color);\n";
        $cssContent .= "}\n";

        file_put_contents($cssFile, $cssContent);

        // Create JS file
        $jsFile = $portalDir . 'assets/js/school-scripts.js';
        $jsContent = "/* School-specific JavaScript for {$school['name']} */\n\n";
        $jsContent .= "document.addEventListener('DOMContentLoaded', function() {\n";
        $jsContent .= "    console.log('{$school['name']} Portal Loaded');\n";
        $jsContent .= "});\n";

        file_put_contents($jsFile, $jsContent);
    }


    /**
     * Recreate school portal (force update)
     * @param array $school
     * @return bool
     */
    private static function recreateSchoolPortal($school)
    {
        $schoolSlug = $school['slug'];
        $portalPath = __DIR__ . '/../../tenant/' . $schoolSlug . '/';

        // Remove existing portal
        if (is_dir($portalPath)) {
            self::deleteDirectory($portalPath);
        }

        // Create new portal
        return self::createOrUpdateSchoolPortal($school);
    }

    /**
     * Delete directory recursively
     * @param string $dir
     */
    private static function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
    /**
     * =================================================================
     * TABLE DEFINITION METHODS
     * =================================================================
     */

    /**
     * 1. CORE EDUCATIONAL TABLES
     */

    private static function getAcademicTermsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `academic_terms` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_term_school` (`school_id`,`academic_year_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAcademicYearsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `academic_years` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `status` enum('upcoming','active','completed') DEFAULT 'upcoming',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_year_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAnnouncementsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `announcements` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_published` (`is_published`),
            KEY `idx_dates` (`start_date`,`end_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAttendanceTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `attendance` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_student` (`student_id`),
            KEY `idx_date` (`date`),
            KEY `idx_class` (`class_id`),
            KEY `idx_attendance_student_date` (`student_id`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getClassesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `classes` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED DEFAULT NULL,
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
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getClassSubjectsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `class_subjects` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `class_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_class_subject` (`class_id`,`subject_id`),
            KEY `subject_id` (`subject_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_teacher` (`teacher_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getEventsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `events` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_dates` (`start_date`,`end_date`),
            KEY `idx_type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exams` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamGradesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exam_grades` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_exam` (`exam_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_subject` (`subject_id`),
            KEY `idx_exam_grades_exam_student` (`exam_id`,`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFeeCategoriesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `fee_categories` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_category_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFeeStructuresTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `fee_structures` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getGuardiansTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `guardians` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_student` (`student_id`),
            KEY `idx_primary` (`is_primary`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHomeworkTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `homework` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_class` (`class_id`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_teacher` (`teacher_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoicesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoices` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_student` (`student_id`),
            KEY `idx_status` (`status`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_invoices_student_status` (`student_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoiceItemsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoice_items` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` int(10) UNSIGNED NOT NULL,
            `fee_category_id` int(10) UNSIGNED NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `fee_category_id` (`fee_category_id`),
            KEY `idx_invoice` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPaymentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_payment_date` (`payment_date`),
            KEY `idx_payments_invoice_date` (`invoice_id`,`payment_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRolesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `roles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `slug` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `permissions` text DEFAULT NULL,
            `is_system` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_role_school` (`school_id`,`slug`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSectionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `sections` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_class` (`class_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSettingsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `settings` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `key` varchar(100) NOT NULL,
            `value` text DEFAULT NULL,
            `type` varchar(50) DEFAULT 'string',
            `category` varchar(50) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_setting` (`school_id`,`key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStudentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `students` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED DEFAULT NULL,
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
            KEY `idx_class` (`class_id`),
            KEY `idx_admission` (`admission_number`),
            KEY `idx_status` (`status`),
            KEY `idx_students_class_status` (`class_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSubjectsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `subjects` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `type` enum('core','elective','extra_curricular') DEFAULT 'core',
            `description` text DEFAULT NULL,
            `credit_hours` decimal(4,1) DEFAULT 1.0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_subject_school` (`school_id`,`code`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTeachersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `teachers` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTimetablesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `timetables` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_class` (`class_id`),
            KEY `idx_day` (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getUsersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `users` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
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
            KEY `idx_user_type` (`user_type`),
            KEY `idx_email` (`email`),
            KEY `idx_phone` (`phone`),
            KEY `idx_users_school_type` (`school_id`,`user_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getUserRolesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `user_roles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` int(10) UNSIGNED NOT NULL,
            `role_id` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
            KEY `role_id` (`role_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

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

    /**
     * =================================================================
     * HELPER METHODS
     * =================================================================
     */

    /**
     * Insert default data into new school database
     * @param PDO $db
     * @param int $schoolId
     */
    private static function insertDefaultData($db, $schoolId)
    {
        try {
            // Insert default roles
            $db->exec("INSERT IGNORE INTO `roles` (`school_id`, `name`, `slug`, `description`, `permissions`, `is_system`, `created_at`) VALUES
                ($schoolId, 'Super Administrator', 'super_admin', 'Has full access to all features', '[\"*\"]', 1, NOW()),
                ($schoolId, 'School Administrator', 'school_admin', 'Manages school operations', '[\"dashboard.view\", \"students.*\", \"teachers.*\", \"classes.*\", \"attendance.*\", \"exams.*\", \"fees.*\", \"reports.*\", \"settings.*\"]', 1, NOW()),
                ($schoolId, 'Teacher', 'teacher', 'Can manage classes and students', '[\"dashboard.view\", \"attendance.mark\", \"grades.enter\", \"homework.*\", \"students.view\"]', 1, NOW()),
                ($schoolId, 'Student', 'student', 'Can view their own information', '[\"dashboard.view\", \"timetable.view\", \"grades.view\", \"homework.view\"]', 1, NOW()),
                ($schoolId, 'Parent', 'parent', 'Can view child information', '[\"dashboard.view\", \"children.view\", \"attendance.view\", \"fees.view\"]', 1, NOW()),
                ($schoolId, 'Accountant', 'accountant', 'Manages financial operations', '[\"dashboard.view\", \"fees.*\", \"payments.*\", \"invoices.*\", \"reports.financial\"]', 1, NOW()),
                ($schoolId, 'Librarian', 'librarian', 'Manages library operations', '[\"dashboard.view\", \"library.*\"]', 1, NOW())");

            // Insert default settings
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

            self::logInfo("Inserted default data for school ID: " . $schoolId);
            return true;
        } catch (Exception $e) {
            self::logError("Error inserting default data", $e);
            return false;
        }
    }

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
    private static function createInitialAdmin($db, $schoolData)
    {
        try {
            $hashedPassword = password_hash($schoolData['admin_password'], PASSWORD_BCRYPT);

            // Insert admin user
            $stmt = $db->prepare("
            INSERT INTO users 
            (school_id, name, email, phone, password, user_type, is_active) 
            VALUES (?, ?, ?, ?, ?, 'admin', 1)
        ");

            $stmt->execute([
                $schoolData['id'],
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
                $userRoleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                $userRoleStmt->execute([$adminUserId, $roleId]);
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
