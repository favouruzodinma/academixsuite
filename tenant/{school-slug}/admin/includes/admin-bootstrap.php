<?php
/**
 * Shared school-admin bootstrap.
 *
 * Use this in admin pages that need a clean, authenticated context with:
 * $schoolSlug, $school, $platformDb, $schoolDb, $adminUser, $schoolLogoUrl.
 */

if (defined('ACADEMIX_SCHOOL_ADMIN_BOOTSTRAPPED')) {
    return;
}

define('ACADEMIX_SCHOOL_ADMIN_BOOTSTRAPPED', true);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__, 4) . '/logs/school_admin_portal.log');

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 4));
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    $sessionConfig = ROOT_PATH . '/includes/session_config.php';
    if (is_file($sessionConfig)) {
        require_once $sessionConfig;
        session_start(academix_session_options());
    } else {
        session_start();
    }
}

require_once ROOT_PATH . '/includes/autoload.php';

if (!function_exists('academix_admin_e')) {
    function academix_admin_e($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('academix_admin_table_exists')) {
    function academix_admin_table_exists(PDO $db, string $table): bool {
        try {
            $stmt = $db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Admin table check failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('academix_admin_columns')) {
    function academix_admin_columns(PDO $db, string $table): array {
        static $cache = [];
        $key = spl_object_id($db) . ':' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        try {
            $safeTable = str_replace('`', '', $table);
            $rows = $db->query("SHOW COLUMNS FROM `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC);
            $cache[$key] = array_column($rows, 'Field');
        } catch (Throwable $e) {
            error_log('Admin column check failed for ' . $table . ': ' . $e->getMessage());
            $cache[$key] = [];
        }

        return $cache[$key];
    }
}

if (!function_exists('academix_admin_has_column')) {
    function academix_admin_has_column(PDO $db, string $table, string $column): bool {
        return in_array($column, academix_admin_columns($db, $table), true);
    }
}

if (!function_exists('academix_admin_current_slug')) {
    function academix_admin_current_slug(): string {
        $slug = (string) ($GLOBALS['SCHOOL_SLUG'] ?? ($_GET['school_slug'] ?? ($_SESSION['school_auth']['school_slug'] ?? '')));
        if ($slug === '' && function_exists('school_subdomain_slug')) {
            $slug = (string) school_subdomain_slug();
        }
        return trim($slug, '/');
    }
}

if (!function_exists('academix_admin_url')) {
    function academix_admin_url(string $page = 'index.php'): string {
        $schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? academix_admin_current_slug();
        if (function_exists('school_route_url')) {
            return school_route_url($schoolSlug, 'admin', ltrim($page, '/'), false);
        }
        return '/tenant/' . rawurlencode($schoolSlug) . '/admin/' . ltrim($page, '/');
    }
}

if (!function_exists('academix_admin_logout_url')) {
    function academix_admin_logout_url(): string {
        $schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? academix_admin_current_slug();
        if (function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug)) {
            return '/logout.php';
        }
        return '/tenant/logout.php?school_slug=' . rawurlencode($schoolSlug);
    }
}

if (!function_exists('academix_admin_asset')) {
    function academix_admin_asset(string $path): string {
        return '/tenant/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('academix_admin_csrf_token')) {
    function academix_admin_csrf_token(): string {
        if (function_exists('generateCsrfToken')) {
            return generateCsrfToken();
        }
        if (empty($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['admin_csrf_token'];
    }
}

if (!function_exists('academix_admin_validate_csrf')) {
    function academix_admin_validate_csrf(?string $token): bool {
        if ($token === null || $token === '') {
            return false;
        }
        if (function_exists('validateCsrfToken')) {
            return validateCsrfToken($token);
        }
        return !empty($_SESSION['admin_csrf_token']) && hash_equals($_SESSION['admin_csrf_token'], $token);
    }
}

$schoolSlug = academix_admin_current_slug();
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$baseUrl = $GLOBALS['BASE_URL'] ?? academix_admin_url('');

if ($schoolSlug === '' || !preg_match('/^[a-z0-9_-]+$/i', $schoolSlug)) {
    http_response_code(400);
    exit('School identifier missing.');
}

try {
    $platformDb = Database::getPlatformConnection();
    $stmt = $platformDb->prepare('SELECT * FROM schools WHERE slug = ? LIMIT 1');
    $stmt->execute([$schoolSlug]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Admin bootstrap platform database error: ' . $e->getMessage());
    http_response_code(500);
    exit('School portal is temporarily unavailable.');
}

if (!$school) {
    http_response_code(404);
    exit('School not found.');
}

$_SESSION['school_info'][$schoolSlug] = $school;
$schoolData = $school;
$GLOBALS['SCHOOL_SLUG'] = $schoolSlug;
$GLOBALS['USER_TYPE'] = $userType;
$GLOBALS['CURRENT_PAGE'] = $currentPage;
$GLOBALS['SCHOOL_DATA'] = $school;
$GLOBALS['BASE_URL'] = $baseUrl;

$schoolAuth = $_SESSION['school_auth'] ?? [];
$isAdminSession = is_array($schoolAuth)
    && (($schoolAuth['school_slug'] ?? '') === $schoolSlug)
    && (($schoolAuth['user_type'] ?? '') === 'admin');

if (!$isAdminSession) {
    $loginUrl = function_exists('school_login_url')
        ? school_login_url($schoolSlug, false)
        : '/tenant/login.php?school_slug=' . rawurlencode($schoolSlug);
    header('Location: ' . $loginUrl);
    exit;
}

$userId = (int) ($schoolAuth['user_id'] ?? 0);
$userType = (string) ($schoolAuth['user_type'] ?? $userType);
$GLOBALS['SCHOOL_AUTH'] = $schoolAuth;

$schoolDb = null;
if (!empty($school['database_name'])) {
    try {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
    } catch (Throwable $e) {
        error_log('Admin bootstrap school database unavailable for ' . $schoolSlug . ': ' . $e->getMessage());
    }
}

$adminUser = [
    'name' => $schoolAuth['user_name'] ?? 'Admin User',
    'email' => $schoolAuth['user_email'] ?? '',
    'role_name' => $schoolAuth['role_name'] ?? 'Administrator',
    'avatar' => '',
    'profile_photo' => '',
];

if ($schoolDb && academix_admin_table_exists($schoolDb, 'users')) {
    try {
        $stmt = $schoolDb->prepare('SELECT * FROM users WHERE id = ? AND school_id = ? LIMIT 1');
        $stmt->execute([(int) ($schoolAuth['user_id'] ?? 0), (int) $school['id']]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            $adminUser = array_merge($adminUser, $userRow);
            $adminUser['role_name'] = $adminUser['role_name'] ?? ($schoolAuth['role_name'] ?? 'Administrator');
        }
    } catch (Throwable $e) {
        error_log('Admin bootstrap user lookup failed: ' . $e->getMessage());
    }
}

$schoolLogoUrl = function_exists('school_logo_url') ? school_logo_url($school, false) : academix_admin_asset('images/logo.png');
$schoolLogoAbsoluteUrl = function_exists('school_logo_url') ? school_logo_url($school, true) : academix_admin_asset('images/logo.png');
$csrfToken = academix_admin_csrf_token();
$notifications = is_array($notifications ?? null) ? $notifications : [];
$unreadCount = (int) ($unreadCount ?? 0);
$notificationCount = (int) ($notificationCount ?? $unreadCount);
