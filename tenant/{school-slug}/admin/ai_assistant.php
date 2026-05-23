<?php
/**
 * AI Assistant Endpoint
 *
 * Handles two modes:
 *
 * 1. AI conversation (default):
 *    POST messages + csrf_token → runs DeepSeek with school tools → returns reply.
 *
 * 2. Direct send_email action (no AI involved):
 *    POST action=send_email + subject + body_html + audience + csrf_token
 *    → resolves recipients via SchoolEmailSender → sends → returns result.
 *
 * 3. Preview recipients:
 *    POST action=preview_recipients + audience + csrf_token
 *    → returns count + sample addresses (no email sent).
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/ai_assistant.log');

if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    $sessionConfig = __DIR__ . '/../../../includes/session_config.php';
    if (is_file($sessionConfig)) {
        require_once $sessionConfig;
    }
    session_start(function_exists('academix_session_options') ? academix_session_options() : []);
}

require_once __DIR__ . '/../../../includes/autoload.php';

header('Content-Type: application/json');

$jsonInput = [];
$rawInput = file_get_contents('php://input');
if (is_string($rawInput) && trim($rawInput) !== '') {
    $decodedInput = json_decode($rawInput, true);
    if (is_array($decodedInput)) {
        $jsonInput = $decodedInput;
    }
}

// ── Auth & CSRF ───────────────────────────────────────────────────────────────
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    echo json_encode(['success' => false, 'message' => 'School context missing.']); exit;
}

$school = $schoolData ?: ($_SESSION['school_info'][$schoolSlug] ?? []);
if (empty($school['id'])) {
    echo json_encode(['success' => false, 'message' => 'School profile could not be loaded. Please refresh and try again.']); exit;
}
$school['id'] = (int)$school['id'];
$school['slug'] = $school['slug'] ?? $schoolSlug;
$school['name'] = $school['name'] ?? 'School';

if (empty($_SESSION['school_auth']) || $_SESSION['school_auth']['school_slug'] !== $schoolSlug
    || ($_SESSION['school_auth']['user_type'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorised.']); exit;
}

if (!function_exists('academix_ai_validate_csrf_token')) {
    function academix_ai_validate_csrf_token(?string $token): bool {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }

        $sessionTokens = [
            $_SESSION['ai_csrf_token'] ?? null,
            $_SESSION['csrf_token'] ?? null,
            $_SESSION['admin_csrf_token'] ?? null,
        ];

        foreach ($sessionTokens as $sessionToken) {
            if (is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
                return true;
            }
        }

        if (!empty($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
            if (isset($_SESSION['csrf_tokens'][$token])) {
                $expiry = $_SESSION['csrf_tokens'][$token];
                if (is_numeric($expiry)) {
                    return (int)$expiry >= time();
                }
                return true;
            }

            foreach ($_SESSION['csrf_tokens'] as $csrfTokenData) {
                if (is_array($csrfTokenData) && isset($csrfTokenData['token'], $csrfTokenData['expiry'])) {
                    if ((int)$csrfTokenData['expiry'] >= time() && hash_equals((string)$csrfTokenData['token'], $token)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
$token = $_POST['csrf_token'] ?? ($jsonInput['csrf_token'] ?? '');
if (!academix_ai_validate_csrf_token(is_string($token) ? $token : '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']); exit;
}

// ── Bootstrap school dependencies ─────────────────────────────────────────────
$userId     = (int) ($_SESSION['school_auth']['user_id'] ?? 0);
$userType   = $_SESSION['school_auth']['user_type'] ?? 'admin';
$platformDb = Database::getPlatformConnection();
$schoolDb   = null;

try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
    }
} catch (Throwable $e) {
    error_log('AI assistant: school DB unavailable – ' . $e->getMessage());
}

require_once __DIR__ . '/../../../includes/SchoolActionManager.php';
require_once __DIR__ . '/../../../includes/DeepSeekClient.php';
require_once __DIR__ . '/../../../includes/Services/SchoolEmailSender.php';
require_once __DIR__ . '/../../../includes/Services/WhatsAppService.php';

// Load onboarding helpers (functions only — no side-effect vars needed here)
$_ob_path = __DIR__ . '/includes/onboarding-guide.php';
if (file_exists($_ob_path) && !defined('ACADEMIX_ONBOARDING_LOADED')) {
    // Load helper functions only without triggering the detection block
    require_once $_ob_path;
}

$manager = new SchoolActionManager($platformDb, $schoolDb, $school['id'], $schoolSlug, $userId);

// Load EventManager if available
$eventManager = null;
$eventManagerPath = __DIR__ . '/../../../includes/EventManager.php';
if ($schoolDb && file_exists($eventManagerPath)) {
    require_once $eventManagerPath;
    try {
        $eventManager = new EventManager($schoolDb, $platformDb, $school['id'], $userId, $userType, $school);
    } catch (Throwable $e) {
        error_log('AI assistant: EventManager init failed – ' . $e->getMessage());
    }
}

if (!function_exists('academix_ai_table_exists')) {
    function academix_ai_table_exists(PDO $db, string $table): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $stmt = $db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("AI assistant table check failed for {$table}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('academix_ai_column_exists')) {
    function academix_ai_column_exists(PDO $db, string $table, string $column): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('academix_ai_code_from_name')) {
    function academix_ai_code_from_name(string $name, string $prefix = ''): string {
        $name = trim($name);
        $words = preg_split('/\s+/', preg_replace('/[^a-zA-Z0-9\s]/', ' ', $name)) ?: [];
        $code = '';

        foreach ($words as $word) {
            if ($word !== '') {
                $code .= strtoupper(substr($word, 0, 3));
            }
            if (strlen($code) >= 8) {
                break;
            }
        }

        $code = preg_replace('/[^A-Z0-9]/', '', $code) ?: strtoupper($prefix ?: 'ITEM');
        return substr($code, 0, 12);
    }
}

if (!function_exists('academix_ai_normalize_operation_args')) {
    function academix_ai_normalize_operation_args(array $args): array {
        $normalized = [];
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = academix_ai_normalize_operation_args($value);
            } elseif (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = function_exists('mb_strtolower') ? mb_strtolower($trimmed) : strtolower($trimmed);
            } else {
                $normalized[$key] = $value;
            }
        }
        ksort($normalized);
        return $normalized;
    }
}

if (!function_exists('academix_ai_recent_duplicate_action')) {
    function academix_ai_recent_duplicate_action(string $schoolSlug, string $toolName, array $args, int $ttlSeconds = 300): ?array {
        $fingerprint = hash('sha256', $schoolSlug . '|' . $toolName . '|' . json_encode(academix_ai_normalize_operation_args($args)));
        $now = time();
        $_SESSION['ai_recent_actions'][$schoolSlug] = $_SESSION['ai_recent_actions'][$schoolSlug] ?? [];

        foreach ($_SESSION['ai_recent_actions'][$schoolSlug] as $key => $item) {
            if (!is_array($item) || (($item['created_at'] ?? 0) + $ttlSeconds) < $now) {
                unset($_SESSION['ai_recent_actions'][$schoolSlug][$key]);
            }
        }

        if (!empty($_SESSION['ai_recent_actions'][$schoolSlug][$fingerprint])) {
            return [
                'success' => true,
                'duplicate' => true,
                'message' => 'I already completed that action recently, so I did not create a second copy.',
            ];
        }

        return null;
    }
}

if (!function_exists('academix_ai_remember_action')) {
    function academix_ai_remember_action(string $schoolSlug, string $toolName, array $args, array $result): void {
        if (empty($result['success'])) {
            return;
        }

        $fingerprint = hash('sha256', $schoolSlug . '|' . $toolName . '|' . json_encode(academix_ai_normalize_operation_args($args)));
        $_SESSION['ai_recent_actions'][$schoolSlug] = $_SESSION['ai_recent_actions'][$schoolSlug] ?? [];
        $_SESSION['ai_recent_actions'][$schoolSlug][$fingerprint] = [
            'tool' => $toolName,
            'created_at' => time(),
        ];
    }
}

if (!function_exists('academix_ai_default_academic_year_id')) {
    function academix_ai_default_academic_year_id(PDO $schoolDb, int $schoolId): int {
        if (!academix_ai_table_exists($schoolDb, 'academic_years')) {
            return 0;
        }

        try {
            $stmt = $schoolDb->prepare("
                SELECT id
                FROM academic_years
                WHERE school_id = ?
                ORDER BY is_default DESC, start_date DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$schoolId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('AI assistant default academic year lookup failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('academix_ai_send_whatsapp_batch')) {
    function academix_ai_send_whatsapp_batch(
        PDO $schoolDb,
        array $school,
        string $feature,
        array $recipients,
        string $title,
        string $message,
        string $portalPath = 'login.php',
        int $referenceId = 0
    ): array {
        $schoolId = (int)($school['id'] ?? 0);
        if (!class_exists('WhatsAppService')) {
            return ['success' => false, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'WhatsApp service is unavailable.'];
        }

        if (!WhatsAppService::featureEnabled($schoolDb, $schoolId, $feature, true)) {
            return ['success' => false, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => "WhatsApp {$feature} notifications are disabled in settings."];
        }

        $service = new WhatsAppService($schoolDb, $school);
        $service->ensureTables();
        $seen = [];
        $cleanRecipients = [];

        foreach ($recipients as $recipient) {
            $phoneKey = preg_replace('/\D+/', '', (string)($recipient['phone'] ?? ''));
            if ($phoneKey === '' || isset($seen[$phoneKey])) {
                continue;
            }
            $seen[$phoneKey] = true;
            $cleanRecipients[] = $recipient;
        }

        if (!$cleanRecipients) {
            return ['success' => false, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'No WhatsApp-ready recipients were found.'];
        }

        $max = 200;
        $selected = array_slice($cleanRecipients, 0, $max);
        $skipped = max(0, count($cleanRecipients) - count($selected));
        $sent = 0;
        $failed = 0;

        foreach ($selected as $recipient) {
            $result = $service->sendDirectNotification($feature, $referenceId, $recipient, $title, $message, $portalPath);
            if (!empty($result['success'])) {
                $sent++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        $total = count($cleanRecipients);
        return [
            'success' => $sent > 0,
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'message' => "WhatsApp sent to {$sent} of {$total} recipient(s)."
                . ($failed ? " {$failed} failed." : '')
                . ($skipped ? " {$skipped} skipped." : ''),
        ];
    }
}

if (!function_exists('academix_ai_columns')) {
    function academix_ai_columns(PDO $db, string $table): array {
        static $cache = [];

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !academix_ai_table_exists($db, $table)) {
            return [];
        }

        $key = spl_object_hash($db) . ':' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $cache[$key] = array_values(array_filter(array_map(static function ($row) {
                return $row['Field'] ?? null;
            }, $rows)));
            return $cache[$key];
        } catch (Throwable $e) {
            error_log("AI assistant column lookup failed for {$table}: " . $e->getMessage());
            $cache[$key] = [];
            return [];
        }
    }
}

if (!function_exists('academix_ai_has_column')) {
    function academix_ai_has_column(PDO $db, string $table, string $column): bool {
        return in_array($column, academix_ai_columns($db, $table), true);
    }
}

if (!function_exists('academix_ai_first_column')) {
    function academix_ai_first_column(PDO $db, string $table, array $candidates): string {
        foreach ($candidates as $column) {
            if (academix_ai_has_column($db, $table, $column)) {
                return $column;
            }
        }
        return '';
    }
}

if (!function_exists('academix_ai_table_list')) {
    function academix_ai_table_list(PDO $db): array {
        try {
            $stmt = $db->query('SHOW TABLES');
            return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable $e) {
            error_log('AI assistant table list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('academix_ai_sensitive_column')) {
    function academix_ai_sensitive_column(string $column): bool {
        return (bool)preg_match('/(password|passwd|token|secret|api[_-]?key|private[_-]?key|access[_-]?key|refresh[_-]?key|remember|reset|salt|hash|otp|pin)/i', $column);
    }
}

if (!function_exists('academix_ai_sensitive_table')) {
    function academix_ai_sensitive_table(string $table): bool {
        return (bool)preg_match('/(session|password|credential|secret|api[_-]?key|token|migration|schema|audit|log)$/i', $table);
    }
}

if (!function_exists('academix_ai_safe_export_columns')) {
    function academix_ai_safe_export_columns(array $columns): array {
        return array_values(array_filter($columns, static function ($column) {
            $column = (string)$column;
            return $column !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $column) && !academix_ai_sensitive_column($column);
        }));
    }
}

if (!function_exists('academix_ai_scalar')) {
    function academix_ai_scalar(PDO $db, string $sql, array $params = [], $default = 0) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : $value;
        } catch (Throwable $e) {
            error_log('AI assistant scalar query failed: ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('academix_ai_fetch_all')) {
    function academix_ai_fetch_all(PDO $db, string $sql, array $params = []): array {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('AI assistant fetch query failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('academix_ai_simple_export_rows')) {
    function academix_ai_simple_export_rows(PDO $db, string $table, int $schoolId, array $preferredColumns, int $limit, array $extraWhere = [], array $extraParams = []): array {
        if (!academix_ai_table_exists($db, $table)) {
            return ['columns' => [], 'rows' => []];
        }

        $available = academix_ai_columns($db, $table);
        $columns = array_values(array_intersect($preferredColumns, $available));
        if (!$columns) {
            $columns = array_slice($available, 0, 12);
        }
        $columns = academix_ai_safe_export_columns($columns);
        if (!$columns) {
            return ['columns' => [], 'rows' => []];
        }

        $select = array_map(static function ($column) {
            return "`{$column}`";
        }, $columns);

        $where = [];
        $params = [];
        if (in_array('school_id', $available, true)) {
            $where[] = '`school_id` = ?';
            $params[] = $schoolId;
        }

        $where = array_merge($where, $extraWhere);
        $params = array_merge($params, $extraParams);
        $orderBy = in_array('created_at', $available, true)
            ? '`created_at` DESC' . (in_array('id', $available, true) ? ', `id` DESC' : '')
            : (in_array('id', $available, true) ? '`id` DESC' : '`' . $columns[0] . '` ASC');

        $sql = 'SELECT ' . implode(', ', $select) . " FROM `{$table}`"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ' . $orderBy
            . ' LIMIT ' . max(1, min(5000, $limit));

        return [
            'columns' => $columns,
            'rows' => academix_ai_fetch_all($db, $sql, $params),
        ];
    }
}

if (!function_exists('academix_ai_csv_value')) {
    function academix_ai_csv_value($value): string {
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif ($value === null) {
            $value = '';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        $value = (string)$value;
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }
}

if (!function_exists('academix_ai_write_csv_export')) {
    function academix_ai_write_csv_export(array $school, string $reportType, array $columns, array $rows): array {
        $root = dirname(__DIR__, 3);
        $schoolId = (int)($school['id'] ?? 0);
        $schoolSlug = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($school['slug'] ?? 'school')) ?: 'school';
        $dirRel = 'assets/uploads/ai_exports/' . max(0, $schoolId);
        $dirAbs = $root . '/' . $dirRel;

        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            return ['success' => false, 'message' => 'Could not create export directory. Please check upload permissions.'];
        }

        $safeReport = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($reportType)) ?: 'export';
        $filename = "{$schoolSlug}-{$safeReport}-" . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.csv';
        $path = $dirAbs . '/' . $filename;
        $handle = fopen($path, 'w');
        if (!$handle) {
            return ['success' => false, 'message' => 'Could not create the CSV file. Please check upload permissions.'];
        }

        if (!$columns && $rows) {
            $columns = array_keys($rows[0]);
        }
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = academix_ai_csv_value($row[$column] ?? '');
            }
            fputcsv($handle, $line);
        }
        fclose($handle);

        return [
            'success' => true,
            'filename' => $filename,
            'path' => $path,
            'url' => '/' . $dirRel . '/' . $filename,
        ];
    }
}

if (!function_exists('academix_ai_school_intelligence')) {
    function academix_ai_school_intelligence(PDO $db, array $school): array {
        $schoolId = (int)($school['id'] ?? 0);
        $today = date('Y-m-d');

        $activeStudentWhere = academix_ai_has_column($db, 'students', 'status')
            ? "school_id = ? AND (`status` IS NULL OR `status` = 'active')"
            : "school_id = ?";
        $studentCount = academix_ai_table_exists($db, 'students')
            ? (int)academix_ai_scalar($db, "SELECT COUNT(*) FROM students WHERE {$activeStudentWhere}", [$schoolId], 0)
            : 0;

        $teacherCount = 0;
        $parentCount = 0;
        $staffCount = 0;
        if (academix_ai_table_exists($db, 'users') && academix_ai_has_column($db, 'users', 'user_type')) {
            $teacherCount = (int)academix_ai_scalar($db, "SELECT COUNT(*) FROM users WHERE school_id = ? AND user_type = 'teacher'", [$schoolId], 0);
            $parentCount = (int)academix_ai_scalar($db, "SELECT COUNT(*) FROM users WHERE school_id = ? AND user_type IN ('parent','guardian')", [$schoolId], 0);
            $staffCount = (int)academix_ai_scalar($db, "SELECT COUNT(*) FROM users WHERE school_id = ? AND user_type NOT IN ('student','parent','guardian','teacher','admin','super_admin')", [$schoolId], 0);
        }

        $classCount = academix_ai_table_exists($db, 'classes')
            ? (int)academix_ai_scalar($db, 'SELECT COUNT(*) FROM classes WHERE school_id = ?', [$schoolId], 0)
            : 0;
        $subjectCount = academix_ai_table_exists($db, 'subjects')
            ? (int)academix_ai_scalar($db, 'SELECT COUNT(*) FROM subjects WHERE school_id = ?', [$schoolId], 0)
            : 0;

        $attendance = [
            'date' => $today,
            'records' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'half_day' => 0,
            'coverage_percent' => 0,
        ];
        if (academix_ai_table_exists($db, 'attendance')) {
            $dateCol = academix_ai_first_column($db, 'attendance', ['date', 'attendance_date', 'created_at']);
            $statusCol = academix_ai_first_column($db, 'attendance', ['status', 'attendance_status']);
            if ($dateCol) {
                $whereDate = $dateCol === 'created_at' ? "DATE(`{$dateCol}`) = ?" : "`{$dateCol}` = ?";
                $attendance['records'] = (int)academix_ai_scalar($db, "SELECT COUNT(*) FROM attendance WHERE school_id = ? AND {$whereDate}", [$schoolId, $today], 0);
                if ($statusCol) {
                    $rows = academix_ai_fetch_all($db, "SELECT LOWER(`{$statusCol}`) AS status, COUNT(*) AS total FROM attendance WHERE school_id = ? AND {$whereDate} GROUP BY LOWER(`{$statusCol}`)", [$schoolId, $today]);
                    foreach ($rows as $row) {
                        $key = str_replace(' ', '_', (string)($row['status'] ?? ''));
                        if (array_key_exists($key, $attendance)) {
                            $attendance[$key] = (int)$row['total'];
                        }
                    }
                }
                $attendance['coverage_percent'] = $studentCount > 0 ? min(100, (int)round(($attendance['records'] / $studentCount) * 100)) : 0;
            }
        }

        $finance = [
            'open_invoices' => 0,
            'outstanding_amount' => 0.0,
            'payments_today' => 0.0,
        ];
        if (academix_ai_table_exists($db, 'invoices')) {
            $statusCol = academix_ai_first_column($db, 'invoices', ['status', 'payment_status']);
            $amountCol = academix_ai_first_column($db, 'invoices', ['balance', 'amount_due', 'outstanding_amount', 'total_amount', 'amount']);
            $openWhere = $statusCol ? "school_id = ? AND LOWER(`{$statusCol}`) NOT IN ('paid','cancelled','void')" : 'school_id = ?';
            $finance['open_invoices'] = (int)academix_ai_scalar($db, "SELECT COUNT(*) FROM invoices WHERE {$openWhere}", [$schoolId], 0);
            if ($amountCol) {
                $finance['outstanding_amount'] = (float)academix_ai_scalar($db, "SELECT COALESCE(SUM(`{$amountCol}`),0) FROM invoices WHERE {$openWhere}", [$schoolId], 0);
            }
        }
        if (academix_ai_table_exists($db, 'fee_payments')) {
            $amountCol = academix_ai_first_column($db, 'fee_payments', ['amount', 'paid_amount', 'amount_paid']);
            $dateCol = academix_ai_first_column($db, 'fee_payments', ['payment_date', 'paid_at', 'created_at']);
            if ($amountCol && $dateCol) {
                $whereDate = $dateCol === 'created_at' || $dateCol === 'paid_at' ? "DATE(`{$dateCol}`) = ?" : "`{$dateCol}` = ?";
                $finance['payments_today'] = (float)academix_ai_scalar($db, "SELECT COALESCE(SUM(`{$amountCol}`),0) FROM fee_payments WHERE school_id = ? AND {$whereDate}", [$schoolId, $today], 0);
            }
        }

        $upcomingEvents = [];
        if (academix_ai_table_exists($db, 'events')) {
            $titleCol = academix_ai_first_column($db, 'events', ['title', 'name', 'event_title']);
            $dateCol = academix_ai_first_column($db, 'events', ['start_date', 'event_date', 'date']);
            $timeCol = academix_ai_first_column($db, 'events', ['start_time', 'event_time', 'time']);
            $typeCol = academix_ai_first_column($db, 'events', ['type', 'event_type', 'category']);
            if ($titleCol && $dateCol) {
                $select = "`{$titleCol}` AS title, `{$dateCol}` AS event_date"
                    . ($timeCol ? ", `{$timeCol}` AS event_time" : ", '' AS event_time")
                    . ($typeCol ? ", `{$typeCol}` AS type" : ", '' AS type");
                $upcomingEvents = academix_ai_fetch_all($db, "SELECT {$select} FROM events WHERE school_id = ? AND `{$dateCol}` >= ? ORDER BY `{$dateCol}` ASC LIMIT 6", [$schoolId, $today]);
            }
        }

        $recentAnnouncements = [];
        foreach (['announcements', 'notices', 'notice_board'] as $noticeTable) {
            if (!academix_ai_table_exists($db, $noticeTable)) {
                continue;
            }
            $titleCol = academix_ai_first_column($db, $noticeTable, ['title', 'name', 'subject']);
            $targetCol = academix_ai_first_column($db, $noticeTable, ['target', 'audience', 'notice_for']);
            $createdCol = academix_ai_first_column($db, $noticeTable, ['created_at', 'date', 'start_date']);
            if ($titleCol) {
                $select = "`{$titleCol}` AS title"
                    . ($targetCol ? ", `{$targetCol}` AS audience" : ", '' AS audience")
                    . ($createdCol ? ", `{$createdCol}` AS created_at" : ", '' AS created_at");
                $recentAnnouncements = academix_ai_fetch_all($db, "SELECT {$select} FROM `{$noticeTable}` WHERE school_id = ? ORDER BY `id` DESC LIMIT 5", [$schoolId]);
                break;
            }
        }

        $signals = [];
        if ($studentCount === 0) {
            $signals[] = ['level' => 'warning', 'title' => 'No students enrolled', 'detail' => 'Student counts are empty. Import or create students before running attendance and fee reports.'];
        }
        if ($attendance['coverage_percent'] > 0 && $attendance['coverage_percent'] < 90) {
            $signals[] = ['level' => 'warning', 'title' => 'Attendance incomplete today', 'detail' => "Only {$attendance['coverage_percent']}% of active students have attendance records for today."];
        }
        if ($attendance['absent'] > 0) {
            $signals[] = ['level' => 'alert', 'title' => 'Absentees today', 'detail' => "{$attendance['absent']} student(s) are marked absent today. Consider sending parent alerts."];
        }
        if ($finance['open_invoices'] > 0) {
            $signals[] = ['level' => 'finance', 'title' => 'Outstanding invoices', 'detail' => "{$finance['open_invoices']} invoice(s) still appear open. You can ask me to create a fee reminder CSV or send reminders."];
        }
        if (!$upcomingEvents) {
            $signals[] = ['level' => 'info', 'title' => 'No upcoming events found', 'detail' => 'The school calendar has no upcoming events in the events table.'];
        }

        return [
            'success' => true,
            '__type' => 'school_intelligence',
            'generated_at' => date('c'),
            'school' => [
                'id' => $schoolId,
                'name' => $school['name'] ?? 'School',
                'slug' => $school['slug'] ?? '',
            ],
            'overview' => [
                'students' => $studentCount,
                'teachers' => $teacherCount,
                'parents' => $parentCount,
                'staff' => $staffCount,
                'classes' => $classCount,
                'subjects' => $subjectCount,
            ],
            'attendance_today' => $attendance,
            'finance' => $finance,
            'upcoming_events' => $upcomingEvents,
            'recent_announcements' => $recentAnnouncements,
            'signals' => $signals,
        ];
    }
}

if (!function_exists('academix_ai_create_csv_export')) {
    function academix_ai_create_csv_export(PDO $db, array $school, array $args): array {
        $schoolId = (int)($school['id'] ?? 0);
        $reportType = strtolower(trim((string)($args['report_type'] ?? 'students')));
        $reportType = str_replace(['-', ' '], '_', $reportType);
        $aliases = [
            'cvs' => 'students',
            'student' => 'students',
            'students_list' => 'students',
            'pupils' => 'students',
            'learners' => 'students',
            'parent' => 'parents',
            'guardian' => 'parents',
            'guardians' => 'parents',
            'teacher' => 'teachers',
            'employee' => 'staff',
            'employees' => 'staff',
            'class' => 'classes',
            'course' => 'subjects',
            'courses' => 'subjects',
            'subject' => 'subjects',
            'fee' => 'fee_balances',
            'fees' => 'fee_balances',
            'unpaid_fees' => 'fee_balances',
            'outstanding_fees' => 'fee_balances',
            'fee_reminders' => 'fee_balances',
            'payments' => 'fee_payments',
            'payment' => 'fee_payments',
            'transactions' => 'transactions',
            'transaction' => 'transactions',
            'calendar' => 'events',
            'school_events' => 'events',
            'event' => 'events',
            'notice' => 'announcements',
            'notices' => 'announcements',
            'notice_board' => 'announcements',
            'announcement' => 'announcements',
        ];
        $reportType = $aliases[$reportType] ?? $reportType;
        $limit = max(1, min(5000, (int)($args['limit'] ?? 1000)));
        $rows = [];
        $columns = [];

        switch ($reportType) {
            case 'students': {
                if (!academix_ai_table_exists($db, 'students')) {
                    return ['success' => false, '__type' => 'csv_export', 'message' => 'The students table is not available.'];
                }
                $joins = '';
                $select = ['s.id AS student_id'];
                foreach (['admission_number', 'roll_number', 'gender', 'status', 'created_at'] as $column) {
                    $select[] = academix_ai_has_column($db, 'students', $column) ? "s.`{$column}` AS {$column}" : "'' AS {$column}";
                }
                $nameParts = [];
                foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                    if (academix_ai_has_column($db, 'students', $column)) {
                        $nameParts[] = "NULLIF(s.`{$column}`,'')";
                    }
                }
                $select[] = $nameParts ? 'CONCAT_WS(\' \', ' . implode(', ', $nameParts) . ') AS student_name' : "'Student' AS student_name";

                if (academix_ai_table_exists($db, 'classes') && academix_ai_has_column($db, 'students', 'class_id')) {
                    $joins .= ' LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id';
                    $select[] = academix_ai_has_column($db, 'classes', 'name') ? 'c.name AS class_name' : "'' AS class_name";
                } else {
                    $select[] = "'' AS class_name";
                }
                if (academix_ai_table_exists($db, 'sections') && academix_ai_has_column($db, 'students', 'section_id')) {
                    $joins .= ' LEFT JOIN sections sec ON sec.id = s.section_id AND sec.school_id = s.school_id';
                    $select[] = academix_ai_has_column($db, 'sections', 'name') ? 'sec.name AS section_name' : "'' AS section_name";
                } else {
                    $select[] = "'' AS section_name";
                }
                if (academix_ai_table_exists($db, 'users') && academix_ai_has_column($db, 'students', 'user_id')) {
                    $joins .= ' LEFT JOIN users u ON u.id = s.user_id AND u.school_id = s.school_id';
                    $select[] = academix_ai_has_column($db, 'users', 'email') ? 'u.email AS email' : "'' AS email";
                    $select[] = academix_ai_has_column($db, 'users', 'phone') ? 'u.phone AS phone' : "'' AS phone";
                } else {
                    $select[] = "'' AS email";
                    $select[] = "'' AS phone";
                }
                $where = ['s.school_id = ?'];
                $params = [$schoolId];
                if (!empty($args['class_id']) && academix_ai_has_column($db, 'students', 'class_id')) {
                    $where[] = 's.class_id = ?';
                    $params[] = (int)$args['class_id'];
                }
                if (!empty($args['section_id']) && academix_ai_has_column($db, 'students', 'section_id')) {
                    $where[] = 's.section_id = ?';
                    $params[] = (int)$args['section_id'];
                }
                if (!empty($args['status']) && academix_ai_has_column($db, 'students', 'status')) {
                    $where[] = 'LOWER(s.status) = ?';
                    $params[] = strtolower((string)$args['status']);
                }
                if (!empty($args['search'])) {
                    $search = '%' . addcslashes((string)$args['search'], '%_\\') . '%';
                    $searchOr = [];
                    foreach (['first_name', 'last_name', 'middle_name', 'admission_number', 'roll_number'] as $_col) {
                        if (academix_ai_has_column($db, 'students', $_col)) {
                            $searchOr[] = "s.`{$_col}` LIKE ?";
                            $params[] = $search;
                        }
                    }
                    if ($searchOr) { $where[] = '(' . implode(' OR ', $searchOr) . ')'; }
                }
                $rows = academix_ai_fetch_all($db, 'SELECT ' . implode(', ', $select) . " FROM students s {$joins} WHERE " . implode(' AND ', $where) . " ORDER BY s.id DESC LIMIT {$limit}", $params);
                break;
            }

            case 'parents':
            case 'teachers':
            case 'staff':
            case 'users': {
                if (!academix_ai_table_exists($db, 'users')) {
                    return ['success' => false, '__type' => 'csv_export', 'message' => 'The users table is not available.'];
                }
                $userColumns = ['id', 'name', 'email', 'phone', 'user_type', 'is_active', 'status', 'created_at'];
                $select = [];
                foreach ($userColumns as $column) {
                    if (academix_ai_has_column($db, 'users', $column)) {
                        $select[] = "`{$column}`";
                    }
                }
                if (!$select) {
                    $select = array_map(static fn($column) => "`{$column}`", array_slice(academix_ai_safe_export_columns(academix_ai_columns($db, 'users')), 0, 12));
                }
                if (!$select) {
                    return ['success' => false, '__type' => 'csv_export', 'message' => 'No safe user columns are available for export.'];
                }
                $where = [];
                $params = [];
                if (academix_ai_has_column($db, 'users', 'school_id')) {
                    $where[] = 'school_id = ?';
                    $params[] = $schoolId;
                }
                if ($reportType === 'parents' && academix_ai_has_column($db, 'users', 'user_type')) {
                    $where[] = "user_type IN ('parent','guardian')";
                } elseif ($reportType === 'teachers' && academix_ai_has_column($db, 'users', 'user_type')) {
                    $where[] = "user_type = 'teacher'";
                } elseif ($reportType === 'staff' && academix_ai_has_column($db, 'users', 'user_type')) {
                    $where[] = "user_type NOT IN ('student','parent','guardian','teacher','admin','super_admin')";
                }
                if (!empty($args['search'])) {
                    $search = '%' . addcslashes((string)$args['search'], '%_\\') . '%';
                    $searchOr = [];
                    foreach (['name', 'email', 'phone', 'username'] as $_col) {
                        if (academix_ai_has_column($db, 'users', $_col)) {
                            $searchOr[] = "`{$_col}` LIKE ?";
                            $params[] = $search;
                        }
                    }
                    if ($searchOr) { $where[] = '(' . implode(' OR ', $searchOr) . ')'; }
                }
                $rows = academix_ai_fetch_all($db, 'SELECT ' . implode(', ', $select) . ' FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT {$limit}", $params);
                break;
            }

            case 'classes':
                $data = academix_ai_simple_export_rows($db, 'classes', $schoolId, ['id', 'name', 'code', 'grade_level', 'capacity', 'campus_id', 'status', 'created_at'], $limit);
                $rows = $data['rows'];
                $columns = $data['columns'];
                break;

            case 'subjects':
                $data = academix_ai_simple_export_rows($db, 'subjects', $schoolId, ['id', 'name', 'code', 'type', 'credit_hours', 'class_id', 'status', 'created_at'], $limit);
                $rows = $data['rows'];
                $columns = $data['columns'];
                break;

            case 'attendance': {
                if (!academix_ai_table_exists($db, 'attendance')) {
                    return ['success' => false, '__type' => 'csv_export', 'message' => 'The attendance table is not available.'];
                }
                $dateCol = academix_ai_first_column($db, 'attendance', ['date', 'attendance_date', 'created_at']);
                $where = ['a.school_id = ?'];
                $params = [$schoolId];
                if (!empty($args['date']) && $dateCol) {
                    $where[] = $dateCol === 'created_at' ? "DATE(a.`{$dateCol}`) = ?" : "a.`{$dateCol}` = ?";
                    $params[] = date('Y-m-d', strtotime((string)$args['date']) ?: time());
                }
                if (!empty($args['start_date']) && !empty($args['end_date']) && $dateCol) {
                    $where[] = $dateCol === 'created_at' ? "DATE(a.`{$dateCol}`) BETWEEN ? AND ?" : "a.`{$dateCol}` BETWEEN ? AND ?";
                    $params[] = date('Y-m-d', strtotime((string)$args['start_date']) ?: time());
                    $params[] = date('Y-m-d', strtotime((string)$args['end_date']) ?: time());
                }
                $joins = '';
                $select = ['a.*'];
                if (academix_ai_table_exists($db, 'students') && academix_ai_has_column($db, 'attendance', 'student_id')) {
                    $joins .= ' LEFT JOIN students s ON s.id = a.student_id AND s.school_id = a.school_id';
                    $nameParts = [];
                    foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                        if (academix_ai_has_column($db, 'students', $column)) {
                            $nameParts[] = "NULLIF(s.`{$column}`,'')";
                        }
                    }
                    if ($nameParts) {
                        $select[] = 'CONCAT_WS(\' \', ' . implode(', ', $nameParts) . ') AS student_name';
                    }
                }
                if (!empty($args['search']) && $joins) {
                    $search = '%' . addcslashes((string)$args['search'], '%_\\') . '%';
                    $searchOr = [];
                    foreach (['first_name', 'last_name', 'middle_name'] as $_col) {
                        if (academix_ai_has_column($db, 'students', $_col)) {
                            $searchOr[] = "s.`{$_col}` LIKE ?";
                            $params[] = $search;
                        }
                    }
                    if ($searchOr) { $where[] = '(' . implode(' OR ', $searchOr) . ')'; }
                }
                $rows = academix_ai_fetch_all($db, 'SELECT ' . implode(', ', $select) . " FROM attendance a {$joins} WHERE " . implode(' AND ', $where) . " ORDER BY a.id DESC LIMIT {$limit}", $params);
                break;
            }

            case 'fee_balances':
            case 'invoices': {
                if (academix_ai_table_exists($db, 'invoices')) {
                    $extraWhere = [];
                    $extraParams = [];
                    $statusCol = academix_ai_first_column($db, 'invoices', ['status', 'payment_status']);
                    $balanceCol = academix_ai_first_column($db, 'invoices', ['balance_amount', 'balance', 'amount_due', 'outstanding_amount']);
                    $outstandingOnly = !empty($args['outstanding_only']) || $reportType === 'fee_balances';
                    if (!empty($args['status']) && $statusCol) {
                        $extraWhere[] = "LOWER(`{$statusCol}`) = ?";
                        $extraParams[] = strtolower((string)$args['status']);
                    } elseif ($outstandingOnly && $statusCol) {
                        $extraWhere[] = "LOWER(`{$statusCol}`) NOT IN ('paid','cancelled','void','completed','success','successful')";
                    }
                    if ($outstandingOnly && $balanceCol) {
                        $extraWhere[] = "COALESCE(`{$balanceCol}`, 0) > 0";
                    }
                    $data = academix_ai_simple_export_rows($db, 'invoices', $schoolId, ['id', 'invoice_number', 'student_id', 'class_id', 'term_id', 'total_amount', 'amount', 'amount_due', 'balance', 'status', 'due_date', 'created_at'], $limit, $extraWhere, $extraParams);
                    $rows = $data['rows'];
                    $columns = $data['columns'];
                } elseif (academix_ai_table_exists($db, 'student_fees')) {
                    $extraWhere = [];
                    $extraParams = [];
                    $statusCol = academix_ai_first_column($db, 'student_fees', ['status', 'payment_status']);
                    $balanceCol = academix_ai_first_column($db, 'student_fees', ['balance_amount', 'balance', 'amount_due', 'outstanding_amount']);
                    $outstandingOnly = !empty($args['outstanding_only']) || $reportType === 'fee_balances';
                    if (!empty($args['status']) && $statusCol) {
                        $extraWhere[] = "LOWER(`{$statusCol}`) = ?";
                        $extraParams[] = strtolower((string)$args['status']);
                    } elseif ($outstandingOnly && $statusCol) {
                        $extraWhere[] = "LOWER(`{$statusCol}`) NOT IN ('paid','cancelled','void','completed','success','successful')";
                    }
                    if ($outstandingOnly && $balanceCol) {
                        $extraWhere[] = "COALESCE(`{$balanceCol}`, 0) > 0";
                    }
                    $data = academix_ai_simple_export_rows($db, 'student_fees', $schoolId, ['id', 'student_id', 'class_id', 'fee_structure_id', 'amount', 'paid_amount', 'balance', 'status', 'due_date', 'created_at'], $limit, $extraWhere, $extraParams);
                    $rows = $data['rows'];
                    $columns = $data['columns'];
                } else {
                    return ['success' => false, '__type' => 'csv_export', 'message' => 'No invoice or student fee table is available.'];
                }
                break;
            }

            case 'events':
                $extraWhere = [];
                $extraParams = [];
                $dateCol = academix_ai_first_column($db, 'events', ['start_date', 'event_date', 'date']);
                if ($dateCol && !empty($args['start_date']) && !empty($args['end_date'])) {
                    $extraWhere[] = "`{$dateCol}` BETWEEN ? AND ?";
                    $extraParams[] = date('Y-m-d', strtotime((string)$args['start_date']) ?: time());
                    $extraParams[] = date('Y-m-d', strtotime((string)$args['end_date']) ?: time());
                }
                if (!empty($args['search'])) {
                    $search = '%' . addcslashes((string)$args['search'], '%_\\') . '%';
                    $searchOr = [];
                    foreach (['title', 'description', 'venue', 'type'] as $_col) {
                        if (academix_ai_has_column($db, 'events', $_col)) {
                            $searchOr[] = "`{$_col}` LIKE ?";
                            $extraParams[] = $search;
                        }
                    }
                    if ($searchOr) { $extraWhere[] = '(' . implode(' OR ', $searchOr) . ')'; }
                }
                $data = academix_ai_simple_export_rows($db, 'events', $schoolId, ['id', 'title', 'description', 'type', 'start_date', 'end_date', 'start_time', 'end_time', 'venue', 'status', 'created_at'], $limit, $extraWhere, $extraParams);
                $rows = $data['rows'];
                $columns = $data['columns'];
                break;

            case 'announcements': {
                $table = academix_ai_table_exists($db, 'announcements') ? 'announcements' : (academix_ai_table_exists($db, 'notices') ? 'notices' : 'notice_board');
                if (!academix_ai_table_exists($db, $table)) {
                    return ['success' => false, '__type' => 'csv_export', 'message' => 'No announcements table is available.'];
                }
                $annExtraWhere = [];
                $annExtraParams = [];
                if (!empty($args['search'])) {
                    $search = '%' . addcslashes((string)$args['search'], '%_\\') . '%';
                    $searchOr = [];
                    foreach (['title', 'description', 'target', 'audience'] as $_col) {
                        if (academix_ai_has_column($db, $table, $_col)) {
                            $searchOr[] = "`{$_col}` LIKE ?";
                            $annExtraParams[] = $search;
                        }
                    }
                    if ($searchOr) { $annExtraWhere[] = '(' . implode(' OR ', $searchOr) . ')'; }
                }
                $data = academix_ai_simple_export_rows($db, $table, $schoolId, ['id', 'title', 'description', 'target', 'audience', 'start_date', 'end_date', 'status', 'created_at'], $limit, $annExtraWhere, $annExtraParams);
                $rows = $data['rows'];
                $columns = $data['columns'];
                break;
            }

            default:
                $table = $reportType;
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || academix_ai_sensitive_table($table) || !academix_ai_table_exists($db, $table)) {
                    $available = array_values(array_filter(academix_ai_table_list($db), static function ($tableName) use ($db) {
                        return preg_match('/^[a-zA-Z0-9_]+$/', $tableName)
                            && !academix_ai_sensitive_table($tableName)
                            && academix_ai_safe_export_columns(academix_ai_columns($db, $tableName));
                    }));
                    return [
                        'success' => false,
                        '__type' => 'csv_export',
                        'message' => "Unsupported CSV report type: {$reportType}. Try one of: " . implode(', ', array_slice($available, 0, 18)) . '.',
                    ];
                }

                $available = academix_ai_safe_export_columns(academix_ai_columns($db, $table));
                $preferred = array_values(array_intersect(
                    ['id', 'name', 'title', 'email', 'phone', 'type', 'status', 'amount', 'date', 'created_at', 'updated_at'],
                    $available
                ));
                $data = academix_ai_simple_export_rows($db, $table, $schoolId, $preferred ?: array_slice($available, 0, 14), $limit);
                $rows = $data['rows'];
                $columns = $data['columns'];
                break;
        }

        if (!$columns && $rows) {
            $columns = array_keys($rows[0]);
        }

        $export = academix_ai_write_csv_export($school, $reportType, $columns, $rows);
        if (empty($export['success'])) {
            return array_merge(['__type' => 'csv_export'], $export);
        }

        return [
            'success' => true,
            '__type' => 'csv_export',
            'message' => 'CSV export created successfully.',
            'report_type' => $reportType,
            'rows' => count($rows),
            'columns' => $columns,
            'filename' => $export['filename'],
            'url' => $export['url'],
        ];
    }
}

if (!function_exists('academix_ai_ensure_chat_table')) {
    function academix_ai_ensure_chat_table(PDO $db): bool {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS ai_chat_messages (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    school_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL DEFAULT 0,
                    role VARCHAR(20) NOT NULL,
                    content LONGTEXT NOT NULL,
                    metadata LONGTEXT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_ai_chat_school_user (school_id, user_id, id),
                    KEY idx_ai_chat_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return true;
        } catch (Throwable $e) {
            error_log('AI assistant chat memory setup failed: ' . $e->getMessage());
            return academix_ai_table_exists($db, 'ai_chat_messages');
        }
    }
}

if (!function_exists('academix_ai_store_chat_message')) {
    function academix_ai_store_chat_message(PDO $db, int $schoolId, int $userId, string $role, string $content, array $metadata = []): void {
        $role = in_array($role, ['user', 'assistant', 'system', 'tool'], true) ? $role : 'assistant';
        $content = trim($content);
        if ($schoolId <= 0 || $content === '' || !academix_ai_table_exists($db, 'ai_chat_messages')) {
            return;
        }

        $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        if (is_string($metadataJson) && strlen($metadataJson) > 60000) {
            $metadataJson = json_encode(['truncated' => true], JSON_UNESCAPED_SLASHES);
        }

        try {
            $stmt = $db->prepare('INSERT INTO ai_chat_messages (school_id, user_id, role, content, metadata, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$schoolId, max(0, $userId), $role, $content, $metadataJson]);
        } catch (Throwable $e) {
            error_log('AI assistant chat store failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('academix_ai_fetch_chat_history')) {
    function academix_ai_fetch_chat_history(PDO $db, int $schoolId, int $userId, int $limit = 80): array {
        if ($schoolId <= 0 || !academix_ai_table_exists($db, 'ai_chat_messages')) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        try {
            $stmt = $db->prepare("
                SELECT id, role, content, metadata, created_at
                FROM ai_chat_messages
                WHERE school_id = ? AND user_id = ?
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$schoolId, max(0, $userId)]);
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            foreach ($rows as &$row) {
                $metadata = json_decode((string)($row['metadata'] ?? ''), true);
                $row['metadata'] = is_array($metadata) ? $metadata : [];
            }
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            error_log('AI assistant chat fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('academix_ai_clear_chat_history')) {
    function academix_ai_clear_chat_history(PDO $db, int $schoolId, int $userId): bool {
        if ($schoolId <= 0 || !academix_ai_table_exists($db, 'ai_chat_messages')) {
            return false;
        }

        try {
            $stmt = $db->prepare('DELETE FROM ai_chat_messages WHERE school_id = ? AND user_id = ?');
            return $stmt->execute([$schoolId, max(0, $userId)]);
        } catch (Throwable $e) {
            error_log('AI assistant chat clear failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('academix_ai_history_as_messages')) {
    function academix_ai_history_as_messages(PDO $db, int $schoolId, int $userId, int $limit = 24): array {
        $history = academix_ai_fetch_chat_history($db, $schoolId, $userId, $limit);
        $messages = [];
        foreach ($history as $item) {
            $role = (string)($item['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim((string)($item['content'] ?? ''));
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        return $messages;
    }
}

if (!function_exists('academix_ai_is_csv_request')) {
    function academix_ai_is_csv_request(string $text): bool {
        return (bool)preg_match('/\b(csv|cvs|spreadsheet|excel|xlsx|export|downloadable\s+file|download\s+file)\b/i', $text);
    }
}

if (!function_exists('academix_ai_infer_csv_args')) {
    function academix_ai_infer_csv_args(string $text, PDO $db): array {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $reportType = 'students';
        $map = [
            'attendance' => ['attendance', 'absent', 'late', 'present'],
            'fee_balances' => ['unpaid fee', 'outstanding fee', 'fee reminder', 'debt', 'balance', 'invoice', 'fees', 'fee'],
            'parents' => ['parents', 'parent', 'guardian', 'guardians'],
            'teachers' => ['teachers', 'teacher'],
            'staff' => ['staff', 'employee', 'employees'],
            'classes' => ['classes', 'class list', 'class'],
            'subjects' => ['subjects', 'subject', 'courses', 'course'],
            'events' => ['events', 'event', 'calendar'],
            'announcements' => ['announcements', 'announcement', 'notice', 'notices'],
            'users' => ['users', 'accounts', 'admin users'],
            'students' => ['students', 'student', 'pupils', 'learners'],
        ];

        foreach ($map as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $reportType = $type;
                    break 2;
                }
            }
        }

        foreach (academix_ai_table_list($db) as $table) {
            $normalized = str_replace('_', ' ', strtolower($table));
            if (str_contains($lower, strtolower($table)) || str_contains($lower, $normalized)) {
                $reportType = $table;
                break;
            }
        }

        $args = ['report_type' => $reportType, 'limit' => 1000];
        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $text, $match)) {
            $args['date'] = $match[1];
        } elseif (preg_match('/\btoday\b/i', $text)) {
            $args['date'] = date('Y-m-d');
        }
        if (preg_match('/\b(last|past)\s+(\d{1,3})\s+days?\b/i', $text, $match)) {
            $days = max(1, min(365, (int)$match[2]));
            $args['start_date'] = date('Y-m-d', strtotime("-{$days} days"));
            $args['end_date'] = date('Y-m-d');
        }
        if (preg_match('/\b(unpaid|outstanding|pending|overdue)\b/i', $text)) {
            $args['outstanding_only'] = true;
        }
        return $args;
    }
}

$aiChatMemoryReady = $schoolDb ? academix_ai_ensure_chat_table($schoolDb) : false;

// ── Handle direct (non-AI) actions ───────────────────────────────────────────
$directAction = $_POST['action'] ?? ($jsonInput['action'] ?? '');

if ($directAction === 'send_email') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, 'message' => 'School database unavailable.']); exit;
    }
    $subject  = trim((string)($_POST['subject']   ?? ($jsonInput['subject']   ?? '')));
    $bodyHtml = trim((string)($_POST['body_html'] ?? ($jsonInput['body_html'] ?? '')));
    $audience = trim((string)($_POST['audience']  ?? ($jsonInput['audience']  ?? 'all')));

    if ($subject === '' || $bodyHtml === '') {
        echo json_encode(['success' => false, 'message' => 'Subject and body are required.']); exit;
    }

    $sender = new SchoolEmailSender($schoolDb, $school);
    $result = $sender->send($audience, $subject, $bodyHtml);
    echo json_encode($result);
    exit;
}

if ($directAction === 'preview_recipients') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, 'message' => 'School database unavailable.']); exit;
    }
    $audience = trim((string)($_POST['audience'] ?? ($jsonInput['audience'] ?? 'all')));
    $sender   = new SchoolEmailSender($schoolDb, $school);
    $preview  = $sender->resolveRecipients($audience);
    echo json_encode(array_merge(['success' => true], $preview));
    exit;
}

// ── Onboarding: fetch current checklist status (non-AI) ──────────────────────
if ($directAction === 'onboarding_status') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, 'message' => 'School database unavailable.']); exit;
    }
    $steps   = onboarding_build_steps($schoolDb, (int)$school['id'], $school);
    $done    = count(array_filter($steps, fn($s) => $s['done']));
    $total   = count($steps);
    $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
    echo json_encode([
        'success' => true,
        'steps'   => $steps,
        'done'    => $done,
        'total'   => $total,
        'percent' => $percent,
        'completed' => $done >= $total,
    ]);
    exit;
}

// ── Onboarding: mark a step done (non-AI, called by frontend on page visit) ──
if ($directAction === 'mark_onboarding_step') {
    $stepKey = trim((string)($_POST['step'] ?? ($jsonInput['step'] ?? '')));
    if ($schoolDb && $stepKey) {
        onboarding_mark_step($schoolDb, (int)$school['id'], $stepKey);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($directAction === 'history') {
    $messages = ($schoolDb && $aiChatMemoryReady)
        ? academix_ai_fetch_chat_history($schoolDb, (int)$school['id'], $userId, (int)($_POST['limit'] ?? ($jsonInput['limit'] ?? 80)))
        : [];
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'csrf_token' => $_SESSION['ai_csrf_token'] ?? '',
    ]);
    exit;
}

if ($directAction === 'clear_history') {
    $cleared = $schoolDb && $aiChatMemoryReady
        ? academix_ai_clear_chat_history($schoolDb, (int)$school['id'], $userId)
        : false;
    echo json_encode([
        'success' => (bool)$cleared,
        'message' => $cleared ? 'Chat memory cleared.' : 'Chat memory is not available.',
        'csrf_token' => $_SESSION['ai_csrf_token'] ?? '',
    ]);
    exit;
}

if ($directAction === 'csv_export') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, '__type' => 'csv_export', 'message' => 'School database unavailable.']); exit;
    }
    $args = [
        'report_type' => trim((string)($_POST['report_type'] ?? ($jsonInput['report_type'] ?? 'students'))),
        'class_id' => $_POST['class_id'] ?? ($jsonInput['class_id'] ?? null),
        'section_id' => $_POST['section_id'] ?? ($jsonInput['section_id'] ?? null),
        'date' => $_POST['date'] ?? ($jsonInput['date'] ?? null),
        'start_date' => $_POST['start_date'] ?? ($jsonInput['start_date'] ?? null),
        'end_date' => $_POST['end_date'] ?? ($jsonInput['end_date'] ?? null),
        'status' => $_POST['status'] ?? ($jsonInput['status'] ?? null),
        'outstanding_only' => $_POST['outstanding_only'] ?? ($jsonInput['outstanding_only'] ?? null),
        'limit' => $_POST['limit'] ?? ($jsonInput['limit'] ?? 1000),
    ];
    echo json_encode(academix_ai_create_csv_export($schoolDb, $school, $args));
    exit;
}

// ── Parse incoming messages ───────────────────────────────────────────────────
$incomingMessage = trim((string)($_POST['message'] ?? ($jsonInput['message'] ?? '')));
$rawMessages = $_POST['messages'] ?? ($jsonInput['messages'] ?? '');
if (is_string($rawMessages)) {
    $rawMessages = json_decode($rawMessages, true) ?? [];
}
if ($incomingMessage === '' && is_array($rawMessages)) {
    for ($i = count($rawMessages) - 1; $i >= 0; $i--) {
        if (($rawMessages[$i]['role'] ?? '') === 'user' && trim((string)($rawMessages[$i]['content'] ?? '')) !== '') {
            $incomingMessage = trim((string)$rawMessages[$i]['content']);
            break;
        }
    }
}
if ($incomingMessage === '') {
    echo json_encode(['success' => false, 'message' => 'No messages provided.']); exit;
}

if ($schoolDb && academix_ai_is_csv_request($incomingMessage)) {
    if ($aiChatMemoryReady) {
        academix_ai_store_chat_message($schoolDb, (int)$school['id'], $userId, 'user', $incomingMessage);
    }
    $csvArgs = academix_ai_infer_csv_args($incomingMessage, $schoolDb);
    $csvResult = academix_ai_create_csv_export($schoolDb, $school, $csvArgs);
    $reply = !empty($csvResult['success'])
        ? 'I created the CSV export for ' . str_replace('_', ' ', (string)$csvResult['report_type']) . '. It has ' . (int)($csvResult['rows'] ?? 0) . ' row(s), and you can download it from the card below.'
        : 'I could not create that CSV export yet: ' . (string)($csvResult['message'] ?? 'Unknown export error.');
    if ($aiChatMemoryReady) {
        academix_ai_store_chat_message($schoolDb, (int)$school['id'], $userId, 'assistant', $reply, [
            'tool_calls_made' => [
                ['tool' => 'create_csv_export', 'args' => $csvArgs, 'result' => $csvResult],
            ],
        ]);
    }
    echo json_encode([
        'success' => !empty($csvResult['success']),
        'reply' => $reply,
        'message' => empty($csvResult['success']) ? $reply : '',
        'tool_calls_made' => [
            ['tool' => 'create_csv_export', 'args' => $csvArgs, 'result' => json_encode($csvResult)],
        ],
        'csrf_token' => $_SESSION['ai_csrf_token'] ?? '',
    ]);
    exit;
}

// ── System prompt ─────────────────────────────────────────────────────────────
$schoolName   = htmlspecialchars_decode($school['name'] ?? 'the school');
$today        = date('l, F j Y');
$isOnboarding = !empty($_POST['is_onboarding']) || !empty($jsonInput['is_onboarding']);

// Build live checklist for onboarding mode
$obStepsJson = '[]';
if ($isOnboarding && $schoolDb) {
    $obSteps    = onboarding_build_steps($schoolDb, (int)$school['id'], $school);
    $obStepsJson = json_encode($obSteps, JSON_PRETTY_PRINT);
}

$systemPrompt = $isOnboarding ? <<<PROMPT
You are a friendly onboarding assistant built into AcademixSuite, a school management platform.
You are helping the admin of "{$schoolName}" set up their school portal for the first time.
Today is {$today}.

Your mission is to guide the admin step by step through the school setup checklist below.
Work through the steps in order, but be flexible if they want to skip or come back to one.

SETUP CHECKLIST (live status):
{$obStepsJson}

HOW TO GUIDE:
1. Start by warmly welcoming the admin and showing them their progress.
2. Tell them which step to do next and WHY it matters.
3. Use the navigate_to_page tool to send them to the right page — this shows a "Take me there →" button.
4. Wait for them to confirm they've completed a step, then call mark_onboarding_step.
5. Once a step is marked done, move to the next one.
6. After all steps: celebrate, then switch to normal assistant mode.

Keep responses SHORT and encouraging. Use simple language.
If they ask a question unrelated to setup, answer briefly and steer back to the checklist.
PROMPT
: <<<PROMPT
You are an AI assistant built into AcademixSuite, a school management platform.
You are helping the admin of "{$schoolName}" manage their school efficiently.
Today is {$today}.

Your job is to help with:
- Creating announcements / notices for students, staff, or parents
- Creating school events and calendar entries
- Creating classes and subjects
- Summarising fee payment status and outstanding balances
- Sending WhatsApp messages, fee reminders, and attendance alerts
- Reporting on student enrolment and class data
- Detecting what is happening in the school right now: attendance coverage, absentees, unpaid invoices, upcoming events, recent notices, and setup gaps
- Creating downloadable CSV files for school data such as students, parents, teachers, staff, classes, subjects, attendance, invoices, events, and announcements
- Drafting and sending email notifications to parents, teachers, staff, or students
- Fetching individual users (teachers, staff, students, parents) by name or type for communication
- Fetching parents of students in a specific class
- Marking attendance for a class (present, absent, late, half_day, holiday)
- Answering general school management questions

When the admin asks you to create or retrieve data, use the provided tools.
Always confirm what you did after using a tool. Be concise, friendly, and professional.
If a required field is missing (e.g. a date for an event), ask for it before calling the tool.
For bulk WhatsApp actions, make sure the audience, title, and message are clear before sending.
When the admin asks "what is happening", "what needs attention", "urgent tasks", "school pulse", or anything similar, call get_school_intelligence first and summarize the signals.
When the admin asks for a CSV, spreadsheet, Excel-style file, or raw data export, call create_csv_export with the closest report_type.
When the admin asks for a PDF, printed report, formatted document, official list, or printable export, call create_pdf_export with the closest report_type.

TASK CONTINUATION RULES (critical — read carefully):
- If your PREVIOUS message announced you were about to do something (e.g. "I'll assign...", "Let me now...", "I'll do them all at once"), and the admin replies with a confirmation such as "yes", "go ahead", "do it", "do them all at once", "proceed", "ok", "sure", "complete it", "let me know when done", or any similar short affirmation — you MUST immediately execute the announced task using the appropriate tools. Do NOT ask for clarification. Do NOT say "what would you like me to do?". Do NOT restart the conversation.
- A short follow-up message from the admin (under 20 words) that echoes or references your last statement is ALWAYS a continuation, not a new request.
- Only flag something as a duplicate if the admin is starting a brand-new separate request for an action that was FULLY completed and already reported in a prior message. Never flag a continuation as a duplicate.
- If you already announced an action and called tools for it, but the admin is asking for status ("are you done?", "what happened?"), summarize the tool results you already obtained — do not re-run them.

For email requests: ALWAYS call draft_email first and wait for the admin to confirm before sending.
Never send email directly without showing the draft. The draft_email tool returns a preview card —
tell the admin to review it and click "Send" when ready.

When the admin asks about contacting specific people, use get_users_list or get_parents_by_class
to fetch their details first. When asked to mark attendance for a class, use mark_attendance —
you need the class_id and status (present/absent/late/half_day/holiday). Date defaults to today.

Available tools for student data: get_student_details (search by name/admission/class), generate_student_list
(class/section lists with optional guardian contacts).
Available tools for attendance: get_attendance_report (summary with percentages by date range/class/student).
Available tools for fees: get_fee_structure (class/year), get_student_fee_balance (outstanding invoices),
record_fee_payment (add payment to an invoice).
Available tools for timetables: get_class_timetable (view existing timetable by class/section/day), create_full_timetable (build a complete weekly timetable with teacher assignments for a class — call when admin asks to create, generate, build, or set up a timetable/schedule for a class), generate_timetable_pdf (generate a downloadable, print-ready timetable HTML file — call when admin asks to download, print, export, or save the timetable as PDF).
Available tools for exams: get_exam_schedule (by class/year with optional schedule detail).
Available tools for academic years: create_academic_year (name, dates, optional is_default).
Available tools for promotions: promote_students (move active students between classes, requires confirm=true).
Available tools for communication: send_email_now (sends directly to audience — parents/teachers/staff/students/class).
Available tools for school intelligence and files: get_school_intelligence (live operational pulse), create_csv_export (downloadable CSV spreadsheet — call when admin asks for a CSV, Excel, or spreadsheet export), create_pdf_export (downloadable print-ready PDF document — call when admin asks for a PDF report, printed list, formatted document, or official-looking export; supports students, teachers, parents, staff, attendance, fee_balances, invoices, events, announcements).

Available tools for user management: create_user (add admin/accountant/librarian/receptionist), update_user (edit profile), get_user (lookup by ID or name), toggle_user_status (activate/deactivate).
Available tools for student management: create_student (enroll with auto-generated admission number), update_student (edit details), get_student_by_admission (lookup by admission number), transfer_student (move student to another class/section), update_student_status (graduate/withdraw/transfer).
Available tools for teacher management: create_teacher (register with auto-generated employee ID), update_teacher (edit qualifications/bank details), get_teacher (lookup by teacher or user ID).
Available tools for guardians: create_guardian (add parent to student), update_guardian (edit details), get_student_guardians (list all guardians of a student).
Available tools for library: create_library_book (add books), update_library_book (edit details), search_library_books (find by title/author/ISBN), get_library_book (view full details), create_library_member (register borrowers), list_library_members (browse members), issue_book (borrow to member), return_book (record return), list_library_issues (view all records), get_overdue_books (list past-due books with member info).
Available tools for leave management: create_leave_type (define leave categories), list_leave_types (browse categories), create_leave_request (submit leave), approve_leave_request (approve or reject pending requests).
PROMPT;

$conversationMessages = [];
if ($schoolDb && $aiChatMemoryReady) {
    $conversationMessages = academix_ai_history_as_messages($schoolDb, (int)$school['id'], $userId, 24);
} elseif (is_array($rawMessages)) {
    foreach ($rawMessages as $message) {
        $role = (string)($message['role'] ?? '');
        $content = trim((string)($message['content'] ?? ''));
        if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
            $conversationMessages[] = ['role' => $role, 'content' => $content];
        }
    }
    $conversationMessages = array_slice($conversationMessages, -24);
}

$lastMessage = end($conversationMessages);
if (is_array($lastMessage) && ($lastMessage['role'] ?? '') === 'user' && trim((string)($lastMessage['content'] ?? '')) === $incomingMessage) {
    array_pop($conversationMessages);
}

// ── Continuation detection ────────────────────────────────────────────────────
// When the admin sends a short confirmation after the AI announced an action,
// inject a reminder so the model continues the task instead of starting fresh.
// This fixes the bug where the AI says "I'll do them all at once!" but then
// responds to the follow-up confirmation as if the conversation never happened.
$continuationKeywords = [
    'yes', 'yep', 'yeah', 'ok', 'okay', 'sure', 'go ahead', 'proceed',
    'do it', 'do that', 'do this', 'do them', 'do them all', 'do them all at once',
    'all at once', 'continue', 'carry on', 'get it done', "let's go", 'lets go',
    'sounds good', 'great', 'perfect', 'correct', 'confirmed', 'alright',
    'please do', 'just do it', 'start now', 'start', 'finish it', 'complete it',
    'finish them', 'complete them', 'let me know when done', 'when you are done',
    'when done', 'notify me', 'tell me when', 'go on', 'proceed with it',
    'do all of them', 'do all', 'i said do it', 'execute', 'run it', 'run them',
    'assign them', 'assign all', 'create them', 'add them all', 'do the task',
    'complete the task', 'finish the task', 'i told you to', 'as i said',
];
$incomingLower        = strtolower(trim($incomingMessage));
$isShortFollowUp      = mb_strlen($incomingMessage) < 160;
$isContinuationSignal = false;
foreach ($continuationKeywords as $kw) {
    if (str_contains($incomingLower, $kw)) { $isContinuationSignal = true; break; }
}

// Find the last assistant message to see if it announced an intention to act
$lastAssistantContent = '';
foreach (array_reverse($conversationMessages) as $cm) {
    if (($cm['role'] ?? '') === 'assistant' && trim((string)($cm['content'] ?? '')) !== '') {
        $lastAssistantContent = (string)$cm['content'];
        break;
    }
}
$aiAnnouncedAction = $lastAssistantContent !== '' && (bool) preg_match(
    '/\b(i\'ll|i will|i am going to|i shall|let me now|i\'m about to|i\'m going to'
    . '|i\'ll do|i\'ll assign|i\'ll create|i\'ll add|i\'ll send|i\'ll update'
    . '|i\'ll check|let me assign|let me create|let me add|let me build|let me run'
    . '|i can do|i can assign|i can create|assigning now|creating now|i\'ll handle'
    . '|here we go|here i go|executing|proceeding|starting now|doing it now'
    . '|all at once|in one go|bulk|now assign|now create|now add|now run)\b/i',
    $lastAssistantContent
);

$isContinuation = $isShortFollowUp && $isContinuationSignal && $aiAnnouncedAction;

// Build effective system prompt — append continuation context block when needed
$activeSystemPrompt = $systemPrompt;
if ($isContinuation) {
    $truncatedPrev = mb_strlen($lastAssistantContent) > 500
        ? mb_substr($lastAssistantContent, 0, 500) . '…'
        : $lastAssistantContent;
    $activeSystemPrompt .= "\n\n"
        . "== CONTINUATION — CRITICAL INSTRUCTION ==\n"
        . "The admin's CURRENT message is a CONFIRMATION of your PREVIOUS statement. Context:\n"
        . "YOUR LAST MESSAGE: \"{$truncatedPrev}\"\n\n"
        . "You announced you would perform a task. The admin has just confirmed it.\n"
        . "RULES:\n"
        . "1. DO NOT ask 'what would you like me to do?' — you already know.\n"
        . "2. DO NOT say 'I need more context' — the context is your previous message above.\n"
        . "3. DO NOT start a fresh greeting or new topic.\n"
        . "4. IMMEDIATELY call the appropriate tool(s) to execute what you announced.\n"
        . "5. After completing, report the results clearly and concisely.";
}

$messages = array_merge(
    [['role' => 'system', 'content' => $activeSystemPrompt]],
    array_values($conversationMessages),
    [['role' => 'user', 'content' => $incomingMessage]]
);

if ($schoolDb && $aiChatMemoryReady) {
    academix_ai_store_chat_message($schoolDb, (int)$school['id'], $userId, 'user', $incomingMessage);
}

// ── Tool definitions ──────────────────────────────────────────────────────────
$tools = [

    // 1. Create Announcement
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_announcement',
            'description' => 'Create and publish a school notice or announcement.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Short title of the announcement'],
                    'description' => ['type' => 'string', 'description' => 'Full body / details of the announcement'],
                    'target'      => [
                        'type'        => 'string',
                        'enum'        => ['all', 'students', 'teachers', 'parents'],
                        'description' => 'Who the announcement is for. Default: all',
                    ],
                    'start_date'  => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (optional)'],
                    'end_date'    => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (optional)'],
                ],
                'required' => ['title', 'description'],
            ],
        ],
    ],

    // 2. Create Event
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_event',
            'description' => 'Add a new event to the school calendar.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Event title'],
                    'description' => ['type' => 'string', 'description' => 'Event details or agenda'],
                    'type'        => [
                        'type'        => 'string',
                        'enum'        => ['academic', 'sports', 'cultural', 'holiday', 'exam', 'meeting', 'other'],
                        'description' => 'Category of event',
                    ],
                    'start_date'  => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                    'end_date'    => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (defaults to start_date)'],
                    'start_time'  => ['type' => 'string', 'description' => 'Start time HH:MM (optional)'],
                    'end_time'    => ['type' => 'string', 'description' => 'End time HH:MM (optional)'],
                    'venue'       => ['type' => 'string', 'description' => 'Location / venue (optional)'],
                ],
                'required' => ['title', 'start_date', 'type'],
            ],
        ],
    ],

    // 3. Fee Summary
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_fee_summary',
            'description' => 'Get a summary of fee payments — total collected, outstanding, and a breakdown by class or term.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'academic_year_id' => ['type' => 'integer', 'description' => 'Filter by academic year ID (optional)'],
                    'class_id'         => ['type' => 'integer', 'description' => 'Filter by class ID (optional)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 4. Student Report
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_student_report',
            'description' => 'Get student enrolment and class data — total students, breakdown by class, recent additions.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id' => ['type' => 'integer', 'description' => 'Filter by class ID (optional)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 5. List Classes (helper)
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_classes',
            'description' => 'Return a list of all classes with their IDs — useful before filtering other queries by class.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 6. List Academic Years (helper)
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_academic_years',
            'description' => 'Return all academic years with their IDs — useful before filtering by year.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 6b. School Intelligence
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_school_intelligence',
            'description' => 'Detect what is happening in the school right now: attendance coverage, absentees, finance signals, upcoming events, recent announcements, and operational gaps.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'scope' => [
                        'type'        => 'string',
                        'enum'        => ['all', 'today', 'attendance', 'finance', 'calendar', 'setup'],
                        'description' => 'Optional focus area. Default: all',
                    ],
                ],
                'required' => [],
            ],
        ],
    ],

    // 6c. CSV Export
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_csv_export',
            'description' => 'Create a downloadable CSV file for school data such as students, parents, teachers, staff, classes, subjects, attendance, invoices, fee balances, events, announcements, or users.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'report_type' => [
                        'type' => 'string',
                        'description' => 'The CSV data to export. Use known types such as students, parents, teachers, staff, classes, subjects, attendance, fee_balances, invoices, events, announcements, users, or a safe table name from the school database.',
                    ],
                    'class_id'   => ['type' => 'integer', 'description' => 'Optional class ID filter.'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID filter.'],
                    'date'       => ['type' => 'string', 'description' => 'Optional exact date filter YYYY-MM-DD for attendance.'],
                    'start_date' => ['type' => 'string', 'description' => 'Optional start date YYYY-MM-DD.'],
                    'end_date'   => ['type' => 'string', 'description' => 'Optional end date YYYY-MM-DD.'],
                    'status'     => ['type' => 'string', 'description' => 'Optional status filter.'],
                    'outstanding_only' => ['type' => 'boolean', 'description' => 'Use true for unpaid or outstanding fee exports.'],
                    'search'     => ['type' => 'string', 'description' => 'Optional search term.'],
                    'limit'      => ['type' => 'integer', 'description' => 'Maximum rows to export. Default 1000, max 5000.'],
                ],
                'required' => ['report_type'],
            ],
        ],
    ],

    // 6b. Create PDF Export (print-ready HTML document)
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_pdf_export',
            'description' => 'Generate a professionally styled, print-ready PDF document for school data. '
                           . 'Produces a formatted document with the school header, report title, and a data table. '
                           . 'The admin can open the link, then print or Save as PDF using the browser. '
                           . 'Use this when the admin asks for a PDF report, printed list, formatted document, '
                           . 'or any export that needs to look like an official document rather than a spreadsheet. '
                           . 'Supports: students, teachers, parents, staff, attendance, fee_balances, invoices, events, announcements.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'report_type'      => ['type' => 'string', 'description' => 'Data type to export: students, teachers, parents, staff, attendance, fee_balances, invoices, events, announcements.'],
                    'title'            => ['type' => 'string', 'description' => 'Optional custom title for the PDF document header.'],
                    'class_id'         => ['type' => 'integer', 'description' => 'Optional class ID filter.'],
                    'section_id'       => ['type' => 'integer', 'description' => 'Optional section ID filter.'],
                    'date'             => ['type' => 'string', 'description' => 'Optional exact date YYYY-MM-DD (for attendance).'],
                    'start_date'       => ['type' => 'string', 'description' => 'Optional start date YYYY-MM-DD.'],
                    'end_date'         => ['type' => 'string', 'description' => 'Optional end date YYYY-MM-DD.'],
                    'status'           => ['type' => 'string', 'description' => 'Optional status filter.'],
                    'outstanding_only' => ['type' => 'boolean', 'description' => 'True to show only unpaid/outstanding fees.'],
                    'search'           => ['type' => 'string', 'description' => 'Optional search/filter term.'],
                    'limit'            => ['type' => 'integer', 'description' => 'Max rows. Default 500, max 2000.'],
                ],
                'required' => ['report_type'],
            ],
        ],
    ],

    // 7. Get Onboarding Status
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_onboarding_status',
            'description' => 'Returns the live setup checklist showing which steps are done and which are pending. Use this at the start of an onboarding session.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 8. Mark Onboarding Step Complete
    [
        'type' => 'function',
        'function' => [
            'name'        => 'mark_onboarding_step',
            'description' => 'Mark a setup step as completed after the admin confirms they have finished it.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'step_key' => [
                        'type'        => 'string',
                        'enum'        => ['school_profile','academic_year','classes','subjects','teachers','students','fee_structure'],
                        'description' => 'The key of the step to mark as done.',
                    ],
                ],
                'required' => ['step_key'],
            ],
        ],
    ],

    // 9. Navigate to Page
    [
        'type' => 'function',
        'function' => [
            'name'        => 'navigate_to_page',
            'description' => 'Send the admin to a specific page in the portal. Returns a navigation card with a "Take me there" button. Use when guiding the admin to complete a setup step.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'page'        => [
                        'type'        => 'string',
                        'enum'        => [
                            'general.php', 'class-list.php', 'subject-list.php',
                            'add-new-teacher.php', 'teacher-list.php',
                            'add-new-student.php', 'student-list.php',
                            'transaction.php', 'event.php', 'notice-board.php',
                            'timetable-list.php', 'school-profile.php',
                        ],
                        'description' => 'The admin page filename to navigate to.',
                    ],
                    'label'       => ['type' => 'string', 'description' => 'Human-friendly page name, e.g. "School Settings"'],
                    'description' => ['type' => 'string', 'description' => 'One sentence explaining what to do on this page.'],
                ],
                'required' => ['page', 'label'],
            ],
        ],
    ],

    // 10. Draft Email Notification
    [
        'type' => 'function',
        'function' => [
            'name'        => 'draft_email',
            'description' => 'Draft a school email notification to be reviewed and sent by the admin. '
                           . 'Returns a structured draft with subject, HTML body, and recipient count. '
                           . 'Do NOT call send_email — the admin must confirm first.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'audience'    => [
                        'type'        => 'string',
                        'enum'        => ['all', 'parents', 'teachers', 'staff', 'students'],
                        'description' => 'Who this email is for.',
                    ],
                    'subject'     => ['type' => 'string', 'description' => 'Email subject line'],
                    'body'        => ['type' => 'string', 'description' => 'Full email body text (plain text or simple HTML)'],
                    'greeting'    => ['type' => 'string', 'description' => 'Opening salutation, e.g. "Dear Parent,"'],
                    'cta_text'    => ['type' => 'string', 'description' => 'Optional call-to-action button label'],
                    'cta_url'     => ['type' => 'string', 'description' => 'Optional call-to-action button URL'],
                ],
                'required' => ['audience', 'subject', 'body'],
            ],
        ],
    ],

    // 11. Create Class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_class',
            'description' => 'Create a new school class. If academic_year_id is not provided, the current/default academic year is used.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'             => ['type' => 'string', 'description' => 'Class name, e.g. JSS 1, Grade 4, Nursery 2'],
                    'code'             => ['type' => 'string', 'description' => 'Short unique class code. Optional; generated from name if omitted.'],
                    'description'      => ['type' => 'string', 'description' => 'Optional class description'],
                    'grade_level'      => ['type' => 'integer', 'description' => 'Optional numeric grade level'],
                    'capacity'         => ['type' => 'integer', 'description' => 'Optional student capacity'],
                    'room_number'      => ['type' => 'string', 'description' => 'Optional classroom or room number'],
                    'class_teacher_id' => ['type' => 'integer', 'description' => 'Optional user ID of class teacher'],
                    'academic_year_id' => ['type' => 'integer', 'description' => 'Optional academic year ID'],
                ],
                'required' => ['name'],
            ],
        ],
    ],

    // 12. Create Subject
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_subject',
            'description' => 'Create a new school subject.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'         => ['type' => 'string', 'description' => 'Subject name, e.g. Mathematics'],
                    'code'         => ['type' => 'string', 'description' => 'Short unique subject code. Optional; generated from name if omitted.'],
                    'type'         => ['type' => 'string', 'enum' => ['core', 'elective'], 'description' => 'Subject type'],
                    'credit_hours' => ['type' => 'number', 'description' => 'Optional credit hours'],
                    'description'  => ['type' => 'string', 'description' => 'Optional subject description'],
                ],
                'required' => ['name'],
            ],
        ],
    ],

    // 13. Assign Subject to Class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'assign_subject_to_class',
            'description' => 'Assign an existing subject to an existing class, optionally with a teacher.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID'],
                    'subject_id' => ['type' => 'integer', 'description' => 'Subject ID'],
                    'teacher_id' => ['type' => 'integer', 'description' => 'Optional teacher user ID'],
                ],
                'required' => ['class_id', 'subject_id'],
            ],
        ],
    ],

    // 14. List Subjects
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_subjects',
            'description' => 'Return a list of active subjects with their IDs.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 15. Send WhatsApp Message
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_whatsapp_message',
            'description' => 'Send a WhatsApp template message to parents, teachers, students, staff, or everyone.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'audience'   => ['type' => 'string', 'enum' => ['all', 'parents', 'teachers', 'students', 'staff'], 'description' => 'Recipient audience'],
                    'title'      => ['type' => 'string', 'description' => 'Short message title'],
                    'message'    => ['type' => 'string', 'description' => 'Full WhatsApp message body'],
                    'class_id'   => ['type' => 'integer', 'description' => 'Optional class ID filter for parents/students'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID filter for parents/students'],
                ],
                'required' => ['audience', 'title', 'message'],
            ],
        ],
    ],

    // 16. Send Fee Reminders
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_fee_reminders',
            'description' => 'Send WhatsApp fee reminders to parents for unpaid or overdue invoices.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id'    => ['type' => 'integer', 'description' => 'Optional student ID filter'],
                    'class_id'      => ['type' => 'integer', 'description' => 'Optional class ID filter'],
                    'status_filter' => ['type' => 'string', 'enum' => ['all', 'due', 'overdue'], 'description' => 'Which unpaid invoices to remind about'],
                    'message'       => ['type' => 'string', 'description' => 'Optional custom reminder message appended to invoice details'],
                    'limit'         => ['type' => 'integer', 'description' => 'Maximum reminders to send, up to 200'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 17. Send Attendance Alerts
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_attendance_alerts',
            'description' => 'Send WhatsApp attendance alerts to parents based on marked attendance records.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'date'       => ['type' => 'string', 'description' => 'Attendance date YYYY-MM-DD. Defaults to today.'],
                    'class_id'   => ['type' => 'integer', 'description' => 'Optional class ID filter'],
                    'student_id' => ['type' => 'integer', 'description' => 'Optional student ID filter'],
                    'status'     => ['type' => 'string', 'enum' => ['all', 'present', 'absent', 'late', 'half_day', 'holiday'], 'description' => 'Attendance status filter. Defaults to absent.'],
                    'message'    => ['type' => 'string', 'description' => 'Optional custom alert message appended to attendance details'],
                    'limit'      => ['type' => 'integer', 'description' => 'Maximum alerts to send, up to 200'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 18. Get Users List
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_users_list',
            'description' => 'Fetch a list of individual users (teachers, staff, students, or parents) with their names, emails, and IDs. '
                           . 'Use this when the admin wants to email specific people, view contact info, or select recipients.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'user_type' => [
                        'type'        => 'string',
                        'enum'        => ['teachers', 'staff', 'students', 'parents', 'all'],
                        'description' => 'Which group of users to fetch.',
                    ],
                    'class_id'  => ['type' => 'integer', 'description' => 'Optional class ID to filter students by class.'],
                    'search'    => ['type' => 'string', 'description' => 'Optional search term to filter by name or email.'],
                    'limit'     => ['type' => 'integer', 'description' => 'Maximum results to return (default 50, max 200).'],
                ],
                'required' => ['user_type'],
            ],
        ],
    ],

    // 19. Get Parents By Class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_parents_by_class',
            'description' => 'Fetch parents/guardians of students enrolled in a specific class. '
                           . 'Returns parent names, emails, phone numbers, and their children names.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID to fetch parents for.'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID to narrow results.'],
                    'limit'      => ['type' => 'integer', 'description' => 'Maximum results to return (default 50, max 200).'],
                ],
                'required' => ['class_id'],
            ],
        ],
    ],

    // 20. Mark Attendance
    [
        'type' => 'function',
        'function' => [
            'name'        => 'mark_attendance',
            'description' => 'Mark or update attendance for students in a class on a given date. '
                           . 'If attendance already exists for a student on that date, it will be updated. '
                           . 'All students in the class will be marked with the same status unless individual statuses are provided.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID to mark attendance for.'],
                    'date'       => ['type' => 'string', 'description' => 'Attendance date in YYYY-MM-DD format. Defaults to today.'],
                    'status'     => [
                        'type'        => 'string',
                        'enum'        => ['present', 'absent', 'late', 'half_day', 'holiday'],
                        'description' => 'Attendance status to apply to all students (unless individual_statuses provided).',
                    ],
                    'remark'     => ['type' => 'string', 'description' => 'Optional remark or note about the attendance.'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID to mark only a specific section within the class.'],
                ],
                'required' => ['class_id', 'status'],
            ],
        ],
    ],

    // 21. Get Student Details
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_student_details',
            'description' => 'Search for students by name, admission number, student ID, or class. Returns full profile including guardians, fee balance, and class info.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'search'    => ['type' => 'string', 'description' => 'Search term — student name, admission number, or ID.'],
                    'class_id'  => ['type' => 'integer', 'description' => 'Optional class ID to filter by class.'],
                    'limit'     => ['type' => 'integer', 'description' => 'Maximum results (default 20, max 100).'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 22. Get Attendance Report
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_attendance_report',
            'description' => 'Get attendance summary statistics for a class or individual student over a date range. Returns present/absent/late counts and percentages.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID to get report for (required unless student_id provided).'],
                    'student_id' => ['type' => 'integer', 'description' => 'Optional student ID to get individual report.'],
                    'start_date' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD. Defaults to 30 days ago.'],
                    'end_date'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD. Defaults to today.'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 23. Get Fee Structure
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_fee_structure',
            'description' => 'View fee categories and amounts configured for each class. Shows what fees are set up per academic year and term.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'         => ['type' => 'integer', 'description' => 'Optional class ID to filter fees for a specific class.'],
                    'academic_year_id' => ['type' => 'integer', 'description' => 'Optional academic year ID. Defaults to current/default year.'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 24. Get Student Fee Balance
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_student_fee_balance',
            'description' => 'Check a specific student\'s fee balance — total invoiced, total paid, and outstanding amount.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id' => ['type' => 'integer', 'description' => 'Student ID to check balance for.'],
                ],
                'required' => ['student_id'],
            ],
        ],
    ],

    // 25. Record Fee Payment
    [
        'type' => 'function',
        'function' => [
            'name'        => 'record_fee_payment',
            'description' => 'Record a fee payment received from a student against an invoice. Updates the invoice paid amount and balance.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'invoice_id'      => ['type' => 'integer', 'description' => 'Invoice ID to record payment against.'],
                    'amount'          => ['type' => 'number', 'description' => 'Amount paid.'],
                    'payment_method'  => ['type' => 'string', 'enum' => ['cash', 'bank_transfer', 'card', 'cheque', 'mobile_money', 'other'], 'description' => 'Payment method.'],
                    'transaction_id'  => ['type' => 'string', 'description' => 'Optional transaction reference or receipt number.'],
                ],
                'required' => ['invoice_id', 'amount'],
            ],
        ],
    ],

    // 26. Get Class Timetable
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_class_timetable',
            'description' => 'Show the weekly timetable for a class or section. Returns periods grouped by day with subject, teacher, time, and room.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID.'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID.'],
                    'day'        => ['type' => 'string', 'description' => 'Optional day name (e.g. Monday) to filter.'],
                ],
                'required' => ['class_id'],
            ],
        ],
    ],

    // 27. Generate Student List
    [
        'type' => 'function',
        'function' => [
            'name'        => 'generate_student_list',
            'description' => 'Generate a formatted list of students filtered by class, section, or status. Returns student names and optionally guardian contacts.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'    => ['type' => 'integer', 'description' => 'Optional class ID filter.'],
                    'section_id'  => ['type' => 'integer', 'description' => 'Optional section ID filter.'],
                    'include_contacts' => ['type' => 'boolean', 'description' => 'Include parent phone and email (default false).'],
                    'limit'       => ['type' => 'integer', 'description' => 'Maximum results (default 100, max 500).'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 28. Create Academic Year
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_academic_year',
            'description' => 'Create a new academic year with start and end dates. Optionally set it as the default year.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'       => ['type' => 'string', 'description' => 'Academic year name, e.g. "2025/2026" or "2025-2026".'],
                    'start_date' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD.'],
                    'end_date'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD.'],
                    'is_default' => ['type' => 'boolean', 'description' => 'Set as the default active year (default false).'],
                ],
                'required' => ['name', 'start_date', 'end_date'],
            ],
        ],
    ],

    // 29. Get Exam Schedule
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_exam_schedule',
            'description' => 'View upcoming exam schedules for a class. Returns exam name, subjects, dates, times, and rooms.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'         => ['type' => 'integer', 'description' => 'Class ID to view exams for.'],
                    'academic_year_id' => ['type' => 'integer', 'description' => 'Optional academic year ID. Defaults to current.'],
                ],
                'required' => ['class_id'],
            ],
        ],
    ],

    // 30. Promote Students
    [
        'type' => 'function',
        'function' => [
            'name'        => 'promote_students',
            'description' => 'Promote all active students from one class to another at the end of an academic year. This updates each student\'s class_id and optionally resets their enrollment status for the new year.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'from_class_id'        => ['type' => 'integer', 'description' => 'Source class ID — students are promoted FROM this class.'],
                    'to_class_id'          => ['type' => 'integer', 'description' => 'Target class ID — students are promoted TO this class.'],
                    'academic_year_id'     => ['type' => 'integer', 'description' => 'Optional new academic year ID to assign to promoted students.'],
                    'confirm'              => ['type' => 'boolean', 'description' => 'Must be true to actually execute. Prevents accidental promotion.'],
                ],
                'required' => ['from_class_id', 'to_class_id', 'confirm'],
            ],
        ],
    ],

    // 31. Send Email Now
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_email_now',
            'description' => 'Actually send an email to a specific audience immediately. The draft_email tool shows a preview — use this tool ONLY after the admin has confirmed they want to send. '
                           . 'Requires audience, subject, and body. Sends via the school\'s configured email provider.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'audience'    => [
                        'type'        => 'string',
                        'enum'        => ['all', 'parents', 'teachers', 'staff', 'students'],
                        'description' => 'Who this email is for.',
                    ],
                    'subject'     => ['type' => 'string', 'description' => 'Email subject line.'],
                    'body'        => ['type' => 'string', 'description' => 'Full email body text.'],
                    'greeting'    => ['type' => 'string', 'description' => 'Optional opening salutation.'],
                    'class_id'    => ['type' => 'integer', 'description' => 'Optional class ID to filter parents/students.'],
                ],
                'required' => ['audience', 'subject', 'body'],
            ],
        ],
    ],

    // 32. Create User
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_user',
            'description' => 'Create a new user account (admin, accountant, librarian, receptionist, staff). Auto-generates a password if not provided.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'          => ['type' => 'string', 'description' => 'Full name of the user'],
                    'email'         => ['type' => 'string', 'description' => 'Email address'],
                    'phone'         => ['type' => 'string', 'description' => 'Phone number (optional)'],
                    'username'      => ['type' => 'string', 'description' => 'Username (optional; defaults to email)'],
                    'password'      => ['type' => 'string', 'description' => 'Password (optional; auto-generated if omitted)'],
                    'user_type'     => ['type' => 'string', 'enum' => ['admin', 'accountant', 'librarian', 'receptionist', 'staff'], 'description' => 'User role type'],
                    'gender'        => ['type' => 'string', 'enum' => ['male', 'female', 'other'], 'description' => 'Gender (optional)'],
                    'date_of_birth' => ['type' => 'string', 'description' => 'Date of birth YYYY-MM-DD (optional)'],
                    'address'       => ['type' => 'string', 'description' => 'Address (optional)'],
                    'is_active'     => ['type' => 'boolean', 'description' => 'Whether the user is active (default true)'],
                ],
                'required' => ['name', 'email', 'user_type'],
            ],
        ],
    ],

    // 33. Update User
    [
        'type' => 'function',
        'function' => [
            'name'        => 'update_user',
            'description' => 'Update an existing user account\'s profile information (name, email, phone, gender, date_of_birth, address).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'user_id'       => ['type' => 'integer', 'description' => 'User ID to update'],
                    'name'          => ['type' => 'string', 'description' => 'New full name (optional)'],
                    'email'         => ['type' => 'string', 'description' => 'New email address (optional)'],
                    'phone'         => ['type' => 'string', 'description' => 'New phone number (optional)'],
                    'gender'        => ['type' => 'string', 'enum' => ['male', 'female', 'other'], 'description' => 'Gender (optional)'],
                    'date_of_birth' => ['type' => 'string', 'description' => 'Date of birth YYYY-MM-DD (optional)'],
                    'address'       => ['type' => 'string', 'description' => 'Address (optional)'],
                ],
                'required' => ['user_id'],
            ],
        ],
    ],

    // 34. Get User
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_user',
            'description' => 'Fetch details of a single user by ID or search by name/email. Returns full profile including type, contact info, and status.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => 'User ID (optional if search term is provided)'],
                    'search'  => ['type' => 'string', 'description' => 'Search by name or email (optional if user_id provided)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 35. Toggle User Status
    [
        'type' => 'function',
        'function' => [
            'name'        => 'toggle_user_status',
            'description' => 'Activate or deactivate a user account. Use this to enable or disable login access for any user.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'user_id'   => ['type' => 'integer', 'description' => 'User ID to toggle'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Set true to activate, false to deactivate'],
                ],
                'required' => ['user_id', 'is_active'],
            ],
        ],
    ],

    // 36. Create Student
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_student',
            'description' => 'Enroll a new student. Creates a user account and student record. Auto-generates admission number and password if not provided.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'first_name'         => ['type' => 'string', 'description' => 'Student first name'],
                    'middle_name'        => ['type' => 'string', 'description' => 'Student middle name (optional)'],
                    'last_name'          => ['type' => 'string', 'description' => 'Student last name (optional)'],
                    'email'              => ['type' => 'string', 'description' => 'Email address (optional)'],
                    'phone'              => ['type' => 'string', 'description' => 'Phone number (optional)'],
                    'gender'             => ['type' => 'string', 'enum' => ['male', 'female', 'other'], 'description' => 'Gender'],
                    'date_of_birth'      => ['type' => 'string', 'description' => 'Date of birth YYYY-MM-DD'],
                    'class_id'           => ['type' => 'integer', 'description' => 'Class ID to enroll in'],
                    'section_id'         => ['type' => 'integer', 'description' => 'Optional section ID'],
                    'admission_date'     => ['type' => 'string', 'description' => 'Admission date YYYY-MM-DD (optional, defaults to today)'],
                    'current_address'    => ['type' => 'string', 'description' => 'Current address (optional)'],
                    'permanent_address'  => ['type' => 'string', 'description' => 'Permanent address (optional)'],
                    'blood_group'        => ['type' => 'string', 'description' => 'Blood group (optional)'],
                    'allergies'          => ['type' => 'string', 'description' => 'Allergies / medical notes (optional)'],
                    'medical_conditions' => ['type' => 'string', 'description' => 'Medical conditions (optional)'],
                    'previous_school'    => ['type' => 'string', 'description' => 'Previous school name (optional)'],
                    'previous_class'     => ['type' => 'string', 'description' => 'Previous class attended (optional)'],
                ],
                'required' => ['first_name', 'gender', 'date_of_birth', 'class_id'],
            ],
        ],
    ],

    // 37. Update Student
    [
        'type' => 'function',
        'function' => [
            'name'        => 'update_student',
            'description' => 'Update an existing student\'s personal and contact information.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id'         => ['type' => 'integer', 'description' => 'Student ID to update'],
                    'first_name'         => ['type' => 'string', 'description' => 'First name (optional)'],
                    'middle_name'        => ['type' => 'string', 'description' => 'Middle name (optional)'],
                    'last_name'          => ['type' => 'string', 'description' => 'Last name (optional)'],
                    'date_of_birth'      => ['type' => 'string', 'description' => 'Date of birth YYYY-MM-DD (optional)'],
                    'current_address'    => ['type' => 'string', 'description' => 'Current address (optional)'],
                    'permanent_address'  => ['type' => 'string', 'description' => 'Permanent address (optional)'],
                    'blood_group'        => ['type' => 'string', 'description' => 'Blood group (optional)'],
                    'allergies'          => ['type' => 'string', 'description' => 'Allergies (optional)'],
                    'medical_conditions' => ['type' => 'string', 'description' => 'Medical conditions (optional)'],
                ],
                'required' => ['student_id'],
            ],
        ],
    ],

    // 38. Get Student By Admission Number
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_student_by_admission',
            'description' => 'Look up a student by their admission number. Returns full profile including class, section, guardian contacts, and fee balance.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'admission_number' => ['type' => 'string', 'description' => 'The admission number to search for'],
                ],
                'required' => ['admission_number'],
            ],
        ],
    ],

    // 39. Transfer Student
    [
        'type' => 'function',
        'function' => [
            'name'        => 'transfer_student',
            'description' => 'Transfer a student from one class/section to another. Updates the student\'s class_id and optionally section_id.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id'     => ['type' => 'integer', 'description' => 'Student ID to transfer'],
                    'new_class_id'   => ['type' => 'integer', 'description' => 'New class ID'],
                    'new_section_id' => ['type' => 'integer', 'description' => 'Optional new section ID'],
                ],
                'required' => ['student_id', 'new_class_id'],
            ],
        ],
    ],

    // 40. Update Student Status
    [
        'type' => 'function',
        'function' => [
            'name'        => 'update_student_status',
            'description' => 'Change a student\'s enrollment status: active, graduated, transferred, or withdrawn.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id' => ['type' => 'integer', 'description' => 'Student ID'],
                    'status'     => ['type' => 'string', 'enum' => ['active', 'graduated', 'transferred', 'withdrawn'], 'description' => 'New enrollment status'],
                ],
                'required' => ['student_id', 'status'],
            ],
        ],
    ],

    // 41. Create Teacher
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_teacher',
            'description' => 'Register a new teacher. Creates a user account and teacher record. Auto-generates employee ID and password if not provided.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'             => ['type' => 'string', 'description' => 'Full name of the teacher'],
                    'email'            => ['type' => 'string', 'description' => 'Email address (optional)'],
                    'phone'            => ['type' => 'string', 'description' => 'Phone number (optional)'],
                    'gender'           => ['type' => 'string', 'enum' => ['male', 'female', 'other'], 'description' => 'Gender'],
                    'qualification'    => ['type' => 'string', 'description' => 'Qualification, e.g. B.Ed, MSc (optional)'],
                    'specialization'   => ['type' => 'string', 'description' => 'Subject specialization (optional)'],
                    'experience_years' => ['type' => 'integer', 'description' => 'Years of teaching experience (optional)'],
                    'joining_date'     => ['type' => 'string', 'description' => 'Joining date YYYY-MM-DD (optional, defaults to today)'],
                    'salary_grade'     => ['type' => 'string', 'description' => 'Salary grade / level (optional)'],
                ],
                'required' => ['name', 'gender'],
            ],
        ],
    ],

    // 42. Update Teacher
    [
        'type' => 'function',
        'function' => [
            'name'        => 'update_teacher',
            'description' => 'Update an existing teacher\'s employment and bank details.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'teacher_id'       => ['type' => 'integer', 'description' => 'Teacher record ID (from teachers table) to update'],
                    'qualification'    => ['type' => 'string', 'description' => 'Qualification (optional)'],
                    'specialization'   => ['type' => 'string', 'description' => 'Specialization (optional)'],
                    'experience_years' => ['type' => 'integer', 'description' => 'Years of experience (optional)'],
                    'salary_grade'     => ['type' => 'string', 'description' => 'Salary grade / level (optional)'],
                    'bank_name'        => ['type' => 'string', 'description' => 'Bank name (optional)'],
                    'bank_account'     => ['type' => 'string', 'description' => 'Bank account number (optional)'],
                    'ifsc_code'        => ['type' => 'string', 'description' => 'IFSC / bank routing code (optional)'],
                    'is_active'        => ['type' => 'boolean', 'description' => 'Whether the teacher is active (optional)'],
                ],
                'required' => ['teacher_id'],
            ],
        ],
    ],

    // 43. Get Teacher
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_teacher',
            'description' => 'Fetch teacher details including user profile, qualifications, specialization, and employment info by teacher ID or user ID.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'teacher_id' => ['type' => 'integer', 'description' => 'Teacher record ID (optional if user_id provided)'],
                    'user_id'    => ['type' => 'integer', 'description' => 'User ID (optional if teacher_id provided)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 44. Create Guardian
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_guardian',
            'description' => 'Add a guardian (parent) to a student. Creates a user account for the guardian and links them to the student. Auto-generates password.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id'       => ['type' => 'integer', 'description' => 'Student ID to link the guardian to'],
                    'name'             => ['type' => 'string', 'description' => 'Guardian full name'],
                    'email'            => ['type' => 'string', 'description' => 'Email address (optional)'],
                    'phone'            => ['type' => 'string', 'description' => 'Phone number (optional)'],
                    'relationship'     => ['type' => 'string', 'enum' => ['father', 'mother', 'brother', 'sister', 'uncle', 'aunt', 'grandfather', 'grandmother', 'guardian', 'other'], 'description' => 'Relationship to student'],
                    'is_primary'       => ['type' => 'boolean', 'description' => 'Whether this is the primary guardian (default true)'],
                    'can_pickup'       => ['type' => 'boolean', 'description' => 'Whether this guardian can pick up the student (default true)'],
                    'emergency_contact' => ['type' => 'boolean', 'description' => 'Whether this is an emergency contact (default false)'],
                ],
                'required' => ['student_id', 'name', 'relationship'],
            ],
        ],
    ],

    // 45. Update Guardian
    [
        'type' => 'function',
        'function' => [
            'name'        => 'update_guardian',
            'description' => 'Update an existing guardian record and their linked user profile.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'guardian_id'      => ['type' => 'integer', 'description' => 'Guardian record ID (from guardians table)'],
                    'name'             => ['type' => 'string', 'description' => 'New name (optional)'],
                    'email'            => ['type' => 'string', 'description' => 'New email (optional)'],
                    'phone'            => ['type' => 'string', 'description' => 'New phone (optional)'],
                    'relationship'     => ['type' => 'string', 'enum' => ['father', 'mother', 'brother', 'sister', 'uncle', 'aunt', 'grandfather', 'grandmother', 'guardian', 'other'], 'description' => 'New relationship (optional)'],
                    'is_primary'       => ['type' => 'boolean', 'description' => 'Whether primary guardian (optional)'],
                    'can_pickup'       => ['type' => 'boolean', 'description' => 'Whether can pick up student (optional)'],
                    'emergency_contact' => ['type' => 'boolean', 'description' => 'Whether emergency contact (optional)'],
                ],
                'required' => ['guardian_id'],
            ],
        ],
    ],

    // 46. Get Student Guardians
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_student_guardians',
            'description' => 'Fetch all guardians linked to a specific student. Returns names, contact info, relationship, and contact preferences.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id' => ['type' => 'integer', 'description' => 'Student ID to fetch guardians for'],
                ],
                'required' => ['student_id'],
            ],
        ],
    ],

    // 47. Create Library Book
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_library_book',
            'description' => 'Add a new book to the school library inventory.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'          => ['type' => 'string', 'description' => 'Book title / name'],
                    'author'        => ['type' => 'string', 'description' => 'Author name (optional)'],
                    'publisher'     => ['type' => 'string', 'description' => 'Publisher (optional)'],
                    'isbn'          => ['type' => 'string', 'description' => 'ISBN number (optional)'],
                    'quantity'      => ['type' => 'integer', 'description' => 'Total quantity (default 1)'],
                    'rack_no'       => ['type' => 'string', 'description' => 'Shelf / rack location (optional)'],
                    'subject'       => ['type' => 'string', 'description' => 'Subject category (optional)'],
                    'price'         => ['type' => 'number', 'description' => 'Book price (optional)'],
                    'purchase_date' => ['type' => 'string', 'description' => 'Purchase date YYYY-MM-DD (optional, defaults to today)'],
                ],
                'required' => ['name'],
            ],
        ],
    ],

    // 48. Update Library Book
    [
        'type' => 'function',
        'function' => [
            'name'        => 'update_library_book',
            'description' => 'Update information for an existing library book — title, author, publisher, quantity, location, or price.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'book_id'    => ['type' => 'integer', 'description' => 'Book ID to update'],
                    'name'       => ['type' => 'string', 'description' => 'New title (optional)'],
                    'author'     => ['type' => 'string', 'description' => 'New author (optional)'],
                    'publisher'  => ['type' => 'string', 'description' => 'New publisher (optional)'],
                    'isbn'       => ['type' => 'string', 'description' => 'New ISBN (optional)'],
                    'quantity'   => ['type' => 'integer', 'description' => 'New total quantity (optional)'],
                    'rack_no'    => ['type' => 'string', 'description' => 'New shelf location (optional)'],
                    'subject'    => ['type' => 'string', 'description' => 'New subject (optional)'],
                    'price'      => ['type' => 'number', 'description' => 'New price (optional)'],
                ],
                'required' => ['book_id'],
            ],
        ],
    ],

    // 49. Search Library Books
    [
        'type' => 'function',
        'function' => [
            'name'        => 'search_library_books',
            'description' => 'Search the library catalogue by title, author, ISBN, or subject. Returns matching books with availability status.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'search'  => ['type' => 'string', 'description' => 'Search term for title, author, ISBN, or subject'],
                    'subject' => ['type' => 'string', 'description' => 'Filter by subject (optional)'],
                    'limit'   => ['type' => 'integer', 'description' => 'Maximum results (default 20, max 100)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 50. Get Library Book
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_library_book',
            'description' => 'Get detailed information about a specific library book by its ID, including current availability and issue history.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'book_id' => ['type' => 'integer', 'description' => 'Book ID to retrieve'],
                ],
                'required' => ['book_id'],
            ],
        ],
    ],

    // 51. Create Library Member
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_library_member',
            'description' => 'Register a new member for the school library (student, teacher, or staff).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string', 'description' => 'Member full name'],
                    'member_type' => ['type' => 'string', 'enum' => ['student', 'teacher', 'staff'], 'description' => 'Type of membership'],
                    'email'       => ['type' => 'string', 'description' => 'Email address (optional)'],
                    'phone'       => ['type' => 'string', 'description' => 'Phone number (optional)'],
                    'address'     => ['type' => 'string', 'description' => 'Address (optional)'],
                ],
                'required' => ['name', 'member_type'],
            ],
        ],
    ],

    // 52. List Library Members
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_library_members',
            'description' => 'List all registered library members, with optional filter by member type.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'member_type' => ['type' => 'string', 'enum' => ['student', 'teacher', 'staff', 'all'], 'description' => 'Filter by member type (default all)'],
                    'limit'       => ['type' => 'integer', 'description' => 'Maximum results (default 50, max 200)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 53. Issue Book
    [
        'type' => 'function',
        'function' => [
            'name'        => 'issue_book',
            'description' => 'Issue a library book to a member. Creates an issue record and decrements the available quantity. Default due date is 14 days from issue.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'book_id'    => ['type' => 'integer', 'description' => 'Book ID to issue'],
                    'member_id'  => ['type' => 'integer', 'description' => 'Library member ID'],
                    'issue_date' => ['type' => 'string', 'description' => 'Issue date YYYY-MM-DD (optional, defaults to today)'],
                    'due_date'   => ['type' => 'string', 'description' => 'Due date YYYY-MM-DD (optional, defaults to 14 days from issue)'],
                ],
                'required' => ['book_id', 'member_id'],
            ],
        ],
    ],

    // 54. Return Book
    [
        'type' => 'function',
        'function' => [
            'name'        => 'return_book',
            'description' => 'Record the return of a borrowed library book. Updates the issue status to returned and increments the available quantity.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'issue_id'    => ['type' => 'integer', 'description' => 'Library issue record ID'],
                    'return_date' => ['type' => 'string', 'description' => 'Return date YYYY-MM-DD (optional, defaults to today)'],
                ],
                'required' => ['issue_id'],
            ],
        ],
    ],

    // 55. List Library Issues
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_library_issues',
            'description' => 'List library book issue records with optional filters by book, member, or status (issued/returned).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'book_id'   => ['type' => 'integer', 'description' => 'Filter by book ID (optional)'],
                    'member_id' => ['type' => 'integer', 'description' => 'Filter by member ID (optional)'],
                    'status'    => ['type' => 'string', 'enum' => ['issued', 'returned', 'all'], 'description' => 'Filter by issue status (default all)'],
                    'limit'     => ['type' => 'integer', 'description' => 'Maximum results (default 50, max 200)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 56. Get Overdue Books
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_overdue_books',
            'description' => 'List all overdue library books — issued books past their due date that have not been returned. Includes days overdue and member details.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'days_overdue' => ['type' => 'integer', 'description' => 'Minimum days overdue to filter (optional, default 1)'],
                    'limit'        => ['type' => 'integer', 'description' => 'Maximum results (default 50, max 200)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 57. Create Leave Type
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_leave_type',
            'description' => 'Create a new leave type / category (e.g. Sick Leave, Annual Leave, Maternity Leave, Casual Leave).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'             => ['type' => 'string', 'description' => 'Leave type name'],
                    'description'      => ['type' => 'string', 'description' => 'Description of this leave type (optional)'],
                    'max_days_per_year' => ['type' => 'integer', 'description' => 'Maximum days allowed per year (optional)'],
                    'applicable_to'    => ['type' => 'string', 'enum' => ['all', 'teacher', 'staff', 'student'], 'description' => 'Who this leave type applies to (default all)'],
                    'is_paid'          => ['type' => 'boolean', 'description' => 'Whether this is paid leave (default true)'],
                ],
                'required' => ['name'],
            ],
        ],
    ],

    // 58. List Leave Types
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_leave_types',
            'description' => 'List all leave types configured for the school, with optional filter by applicable user type.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'applicable_to' => ['type' => 'string', 'enum' => ['all', 'teacher', 'staff', 'student'], 'description' => 'Filter by who the leave applies to (optional)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 59. Create Leave Request
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_leave_request',
            'description' => 'Submit a leave request for a teacher, staff member, or student. Status defaults to pending.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'user_id'       => ['type' => 'integer', 'description' => 'User ID of the person requesting leave'],
                    'user_type'     => ['type' => 'string', 'enum' => ['teacher', 'staff', 'student'], 'description' => 'Type of user requesting leave'],
                    'leave_type_id' => ['type' => 'integer', 'description' => 'Leave type ID'],
                    'start_date'    => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                    'end_date'      => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    'reason'        => ['type' => 'string', 'description' => 'Reason for leave'],
                ],
                'required' => ['user_id', 'user_type', 'leave_type_id', 'start_date', 'end_date', 'reason'],
            ],
        ],
    ],

    // 60. Approve / Reject Leave Request
    [
        'type' => 'function',
        'function' => [
            'name'        => 'approve_leave_request',
            'description' => 'Approve or reject a pending leave request. Requires the leave request ID and the new status.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'leave_request_id' => ['type' => 'integer', 'description' => 'Leave request ID to process'],
                    'status'           => ['type' => 'string', 'enum' => ['approved', 'rejected'], 'description' => 'New status — approved or rejected'],
                    'rejection_reason' => ['type' => 'string', 'description' => 'Reason for rejection (required if status is rejected)'],
                ],
                'required' => ['leave_request_id', 'status'],
            ],
        ],
    ],

    // 61. Bulk Assign Campus
    [
        'type' => 'function',
        'function' => [
            'name'        => 'bulk_assign_campus',
            'description' => 'Assign multiple students to a campus. Provide either a list of student IDs or a class ID to assign all students in that class.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'campus_id'   => ['type' => 'integer', 'description' => 'Campus ID to assign students to'],
                    'student_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of student IDs to assign'],
                    'class_id'    => ['type' => 'integer', 'description' => 'Class ID to assign all students in that class (alternative to student_ids)'],
                ],
                'required' => ['campus_id'],
            ],
        ],
    ],

    // 62. Create Full Timetable
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_full_timetable',
            'description' => 'Create a complete weekly timetable for a class with subject and teacher assignments for every period across all school days. '
                           . 'Call get_class_timetable first to check if one already exists. '
                           . 'Use this when the admin asks to create, build, generate, or set up a timetable/schedule for a class. '
                           . 'Pass a "periods" array describing every slot. Each entry needs: day, period_number, start_time (HH:MM), end_time (HH:MM), '
                           . 'subject_id (0 for a break), teacher_id (0 for a break), and optionally room_number and is_break. '
                           . 'If academic_year_id or academic_term_id are unknown, omit them and the system will use the current defaults. '
                           . 'Returns a summary of how many periods were inserted and any errors.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'          => ['type' => 'integer', 'description' => 'The class ID to create the timetable for.'],
                    'section_id'        => ['type' => 'integer', 'description' => 'Optional section ID within the class.'],
                    'academic_year_id'  => ['type' => 'integer', 'description' => 'Academic year ID. Defaults to current active year if omitted.'],
                    'academic_term_id'  => ['type' => 'integer', 'description' => 'Academic term ID. Defaults to the latest term if omitted.'],
                    'replace_existing'  => ['type' => 'boolean', 'description' => 'If true, delete the existing timetable for this class first. Default false.'],
                    'periods'           => [
                        'type'        => 'array',
                        'description' => 'Array of period objects for the full week.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'day'           => ['type' => 'string', 'description' => 'Day name: monday, tuesday, wednesday, thursday, friday, saturday'],
                                'period_number' => ['type' => 'integer', 'description' => 'Period number within the day (1, 2, 3…)'],
                                'start_time'    => ['type' => 'string', 'description' => 'Start time in HH:MM format, e.g. 08:00'],
                                'end_time'      => ['type' => 'string', 'description' => 'End time in HH:MM format, e.g. 08:45'],
                                'subject_id'    => ['type' => 'integer', 'description' => 'Subject ID. Use 0 for break periods.'],
                                'teacher_id'    => ['type' => 'integer', 'description' => 'Teacher user ID. Use 0 for break periods.'],
                                'room_number'   => ['type' => 'string', 'description' => 'Optional room/classroom identifier.'],
                                'is_break'      => ['type' => 'boolean', 'description' => 'True if this slot is a break (no subject/teacher needed).'],
                            ],
                            'required' => ['day', 'period_number', 'start_time', 'end_time'],
                        ],
                    ],
                ],
                'required' => ['class_id', 'periods'],
            ],
        ],
    ],

    // 63. Generate Timetable PDF
    [
        'type' => 'function',
        'function' => [
            'name'        => 'generate_timetable_pdf',
            'description' => 'Generate a professionally styled, print-ready timetable document (HTML file saved as PDF-ready) for a class. '
                           . 'The admin can open the link and print it or save it as PDF using the browser. '
                           . 'Call this after creating a timetable, or whenever the admin asks to download, print, or export the timetable as PDF. '
                           . 'Fetches the live timetable from the database and builds the formatted document automatically.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID whose timetable should be printed.'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID.'],
                    'class_name' => ['type' => 'string', 'description' => 'Human-readable class name for the document heading, e.g. "JSS 1".'],
                ],
                'required' => ['class_id'],
            ],
        ],
    ],
];

// ── Tool executor ─────────────────────────────────────────────────────────────
$toolExecutor = function (string $toolName, array $args) use ($manager, $eventManager, $schoolDb, $school, $schoolSlug, $userId): string {

    switch ($toolName) {

        // ── create_announcement ───────────────────────────────────────────────
        case 'create_announcement': {
            $data = [
                'title'       => trim($args['title']        ?? ''),
                'description' => trim($args['description']  ?? ''),
                'target'      => $args['target']             ?? 'all',
                'start_date'  => $args['start_date']         ?? null,
                'end_date'    => $args['end_date']           ?? null,
                'class_id'    => null,
                'section_id'  => null,
            ];
            if ($duplicate = academix_ai_recent_duplicate_action($schoolSlug, $toolName, $data)) {
                return json_encode($duplicate);
            }
            $result = $manager->createAnnouncement($data);
            academix_ai_remember_action($schoolSlug, $toolName, $data, is_array($result) ? $result : ['success' => (bool)$result]);
            return json_encode($result);
        }

        // ── create_event ──────────────────────────────────────────────────────
        case 'create_event': {
            if (!$eventManager) {
                return json_encode(['success' => false, 'message' => 'Event system not available.']);
            }
            $data = [
                'title'       => trim($args['title']       ?? ''),
                'description' => trim($args['description'] ?? ''),
                'type'        => $args['type']              ?? 'other',
                'start_date'  => $args['start_date']        ?? date('Y-m-d'),
                'end_date'    => $args['end_date']           ?? ($args['start_date'] ?? date('Y-m-d')),
                'start_time'  => $args['start_time']         ?? null,
                'end_time'    => $args['end_time']           ?? null,
                'venue'       => $args['venue']              ?? null,
                'is_public'   => 1,
            ];
            if ($duplicate = academix_ai_recent_duplicate_action($schoolSlug, $toolName, $data)) {
                return json_encode($duplicate);
            }
            try {
                $result = $eventManager->createEvent($data, false);
                academix_ai_remember_action($schoolSlug, $toolName, $data, is_array($result) ? $result : ['success' => (bool)$result]);
                return json_encode(is_array($result)
                    ? $result
                    : ['success' => (bool)$result, 'message' => $result ? 'Event created.' : 'Failed to create event.']);
            } catch (Throwable $e) {
                return json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── get_fee_summary ───────────────────────────────────────────────────
        case 'get_fee_summary': {
            if (!$schoolDb) return json_encode(['error' => 'School database unavailable.']);

            $summary = ['total_invoiced' => 0, 'total_paid' => 0, 'total_outstanding' => 0, 'by_class' => []];

            try {
                // Try fee_payments table first (common schema)
                $tables = array_column(
                    $schoolDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0
                );

                if (in_array('fee_payments', $tables)) {
                    $paymentAmount = academix_ai_column_exists($schoolDb, 'fee_payments', 'amount_paid')
                        ? 'fp.amount_paid'
                        : (academix_ai_column_exists($schoolDb, 'fee_payments', 'amount') ? 'fp.amount' : '0');
                    $hasFeeStructureId = academix_ai_column_exists($schoolDb, 'fee_payments', 'fee_structure_id');
                    $join = $hasFeeStructureId && in_array('fee_structures', $tables, true)
                        ? 'LEFT JOIN fee_structures fs ON fs.id = fp.fee_structure_id'
                        : '';
                    $invoiceExpr = $join !== '' ? 'COALESCE(SUM(fs.amount), 0)' : '0';

                    $where = 'WHERE fp.school_id = ' . (int)$school['id'];
                    if ($join !== '' && !empty($args['academic_year_id'])) {
                        $where .= ' AND fs.academic_year_id = ' . (int)$args['academic_year_id'];
                    }
                    if ($join !== '' && !empty($args['class_id'])) {
                        $where .= ' AND fs.class_id = ' . (int)$args['class_id'];
                    }

                    $sql = "SELECT
                                {$invoiceExpr}                       AS total_invoiced,
                                COALESCE(SUM({$paymentAmount}), 0)   AS total_paid,
                                COUNT(DISTINCT fp.student_id)        AS paying_students
                            FROM fee_payments fp
                            {$join}
                            {$where}";
                    $row = $schoolDb->query($sql)->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $summary['total_invoiced']   = (float)$row['total_invoiced'];
                        $summary['total_paid']        = (float)$row['total_paid'];
                        $summary['total_outstanding'] = max(0, $summary['total_invoiced'] - $summary['total_paid']);
                        $summary['paying_students']   = (int)$row['paying_students'];
                    }
                } elseif (in_array('student_fees', $tables)) {
                    $sql = "SELECT
                                COALESCE(SUM(fee_amount), 0) AS total_invoiced,
                                COALESCE(SUM(amount_paid),  0) AS total_paid
                            FROM student_fees WHERE school_id = " . (int)$school['id'];
                    $row = $schoolDb->query($sql)->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $summary['total_invoiced']   = (float)$row['total_invoiced'];
                        $summary['total_paid']        = (float)$row['total_paid'];
                        $summary['total_outstanding'] = max(0, $summary['total_invoiced'] - $summary['total_paid']);
                    }
                }

                // Class breakdown (if class_id not already filtered)
                if (empty($args['class_id']) && in_array('classes', $tables)) {
                    $clsRows = $schoolDb->query("
                        SELECT c.name, COUNT(s.id) AS student_count
                        FROM classes c
                        LEFT JOIN students s ON s.class_id = c.id AND s.status = 'active'
                        WHERE c.school_id = " . (int)$school['id'] . "
                        GROUP BY c.id ORDER BY c.name LIMIT 10
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    $summary['class_student_counts'] = $clsRows;
                }
            } catch (Throwable $e) {
                error_log('AI fee_summary error: ' . $e->getMessage());
                return json_encode(['error' => 'Could not query fee data: ' . $e->getMessage()]);
            }

            $currency = $school['currency'] ?? 'NGN';
            $summary['currency'] = $currency;
            return json_encode($summary);
        }

        // ── get_student_report ────────────────────────────────────────────────
        case 'get_student_report': {
            if (!$schoolDb) return json_encode(['error' => 'School database unavailable.']);

            try {
                $total = (int)$schoolDb->query(
                    "SELECT COUNT(*) FROM students WHERE school_id = " . (int)$school['id'] . " AND status = 'active'"
                )->fetchColumn();

                $classFilter = !empty($args['class_id'])
                    ? ' AND s.class_id = ' . (int)$args['class_id']
                    : '';

                $byClass = $schoolDb->query("
                    SELECT c.name AS class_name, COUNT(s.id) AS student_count
                    FROM students s
                    JOIN classes c ON c.id = s.class_id
                    WHERE s.school_id = " . (int)$school['id'] . " AND s.status = 'active' {$classFilter}
                    GROUP BY c.id ORDER BY c.name LIMIT 20
                ")->fetchAll(PDO::FETCH_ASSOC);

                $recent = $schoolDb->query("
                    SELECT CONCAT(first_name,' ',last_name) AS name, created_at
                    FROM students
                    WHERE school_id = " . (int)$school['id'] . "
                    ORDER BY created_at DESC LIMIT 5
                ")->fetchAll(PDO::FETCH_ASSOC);

                return json_encode([
                    'total_active_students' => $total,
                    'by_class'              => $byClass,
                    'recently_enrolled'     => $recent,
                ]);
            } catch (Throwable $e) {
                error_log('AI student_report error: ' . $e->getMessage());
                return json_encode(['error' => $e->getMessage()]);
            }
        }

        // ── list_classes ──────────────────────────────────────────────────────
        case 'list_classes': {
            $classes = $manager->getClasses();
            return json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $classes));
        }

        // ── list_academic_years ───────────────────────────────────────────────
        case 'list_academic_years': {
            $years = $manager->getAcademicYears();
            return json_encode(array_map(fn($y) => [
                'id'   => $y['id'],
                'name' => $y['name'],
                'is_default' => (bool)($y['is_default'] ?? false),
            ], $years));
        }

        // ── get_school_intelligence ─────────────────────────────────────────
        case 'get_school_intelligence': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            return json_encode(academix_ai_school_intelligence($schoolDb, $school));
        }

        // ── create_csv_export ───────────────────────────────────────────────
        case 'create_csv_export': {
            if (!$schoolDb) {
                return json_encode(['success' => false, '__type' => 'csv_export', 'message' => 'School database unavailable.']);
            }
            return json_encode(academix_ai_create_csv_export($schoolDb, $school, $args));
        }

        // ── create_pdf_export ───────────────────────────────────────────────
        case 'create_pdf_export': {
            if (!$schoolDb) {
                return json_encode(['success' => false, '__type' => 'pdf_export', 'message' => 'School database unavailable.']);
            }
            try {
                // ── Normalise args ────────────────────────────────────────
                $pdfReportType = strtolower(trim((string)($args['report_type'] ?? 'students')));
                $pdfReportType = str_replace(['-',' '], '_', $pdfReportType);
                $pdfAliases = [
                    'student'=>'students','pupils'=>'students','learners'=>'students',
                    'teacher'=>'teachers','employee'=>'staff','employees'=>'staff',
                    'parent'=>'parents','guardian'=>'parents','guardians'=>'parents',
                    'fee'=>'fee_balances','fees'=>'fee_balances','unpaid_fees'=>'fee_balances',
                    'outstanding_fees'=>'fee_balances','invoice'=>'invoices',
                    'attendance_report'=>'attendance','event'=>'events','calendar'=>'events',
                    'notice'=>'announcements','notices'=>'announcements','announcement'=>'announcements',
                ];
                $pdfReportType = $pdfAliases[$pdfReportType] ?? $pdfReportType;
                $pdfLimit  = max(1, min(2000, (int)($args['limit'] ?? 500)));
                $schoolId  = (int)($school['id'] ?? 0);
                $schoolName = htmlspecialchars((string)($school['name'] ?? 'School'), ENT_QUOTES);
                $customTitle = !empty($args['title']) ? htmlspecialchars((string)$args['title'], ENT_QUOTES) : null;

                // ── Reuse CSV data-fetching logic ─────────────────────────
                $csvResult = academix_ai_create_csv_export($schoolDb, $school, array_merge($args, [
                    'report_type' => $pdfReportType,
                    'limit'       => $pdfLimit,
                ]));
                if (empty($csvResult['success'])) {
                    return json_encode(array_merge($csvResult, ['__type' => 'pdf_export']));
                }

                // ── Re-fetch rows as PHP arrays for HTML rendering ────────
                // Use the CSV function rows by re-running the query via a second internal call.
                // To avoid code duplication, we read the CSV file we just wrote and parse it.
                $csvFilePath = isset($csvResult['url']) ? DOCUMENT_ROOT . ltrim((string)$csvResult['url'], '/') : '';
                $htmlRows    = [];
                $htmlCols    = [];
                if ($csvFilePath && file_exists($csvFilePath)) {
                    $fh = fopen($csvFilePath, 'r');
                    if ($fh) {
                        $htmlCols = fgetcsv($fh) ?: [];
                        while (($row = fgetcsv($fh)) !== false) {
                            $htmlRows[] = $row;
                        }
                        fclose($fh);
                    }
                }

                // ── Fallback: columns/rows from CSV result ─────────────────
                if (!$htmlCols && isset($csvResult['columns'])) {
                    $htmlCols = $csvResult['columns'];
                }

                // ── Build report title ─────────────────────────────────────
                $titles = [
                    'students'     => 'Student List',
                    'teachers'     => 'Teacher Directory',
                    'parents'      => 'Parent / Guardian Directory',
                    'staff'        => 'Staff Directory',
                    'users'        => 'User Account List',
                    'attendance'   => 'Attendance Report',
                    'fee_balances' => 'Outstanding Fee Balances',
                    'invoices'     => 'Invoice Report',
                    'events'       => 'School Events',
                    'announcements'=> 'Announcements / Notices',
                    'classes'      => 'Class List',
                    'subjects'     => 'Subject List',
                ];
                $reportTitle = $customTitle ?? ($titles[$pdfReportType] ?? ucwords(str_replace('_',' ', $pdfReportType)));
                $dateGenerated = date('d M Y, H:i');
                $filterNote = '';
                if (!empty($args['start_date']) && !empty($args['end_date'])) {
                    $filterNote = 'Period: ' . date('d M Y', strtotime((string)$args['start_date'])) . ' – ' . date('d M Y', strtotime((string)$args['end_date']));
                } elseif (!empty($args['date'])) {
                    $filterNote = 'Date: ' . date('d M Y', strtotime((string)$args['date']));
                }
                if (!empty($args['search'])) {
                    $filterNote .= ($filterNote ? ' · ' : '') . 'Search: "' . htmlspecialchars((string)$args['search'], ENT_QUOTES) . '"';
                }

                // ── Build HTML header rows ─────────────────────────────────
                $thHtml = '';
                foreach ($htmlCols as $col) {
                    $label = ucwords(str_replace(['_','-'], ' ', (string)$col));
                    $thHtml .= '<th>' . htmlspecialchars($label, ENT_QUOTES) . '</th>';
                }

                $tbodyHtml = '';
                if ($htmlRows) {
                    foreach ($htmlRows as $i => $row) {
                        $rowClass = $i % 2 === 0 ? 'even' : 'odd';
                        $tbodyHtml .= "<tr class=\"{$rowClass}\">";
                        foreach ($row as $cell) {
                            $tbodyHtml .= '<td>' . htmlspecialchars((string)($cell ?? ''), ENT_QUOTES) . '</td>';
                        }
                        $tbodyHtml .= '</tr>';
                    }
                } else {
                    $colCount = max(1, count($htmlCols));
                    $tbodyHtml = "<tr><td colspan=\"{$colCount}\" style=\"text-align:center;color:#888;padding:20px;\">No records found.</td></tr>";
                }

                $totalRows = count($htmlRows);

                // ── Assemble full HTML document ────────────────────────────
                $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$reportTitle} – {$schoolName}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1a1a2e; background: #f7f8fc; }
  .page-wrap { max-width: 1100px; margin: 0 auto; padding: 20px 24px 60px; }

  /* ── Print button bar ── */
  .print-bar { display: flex; align-items: center; justify-content: space-between;
    background: #fff; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08); gap: 10px; }
  .print-bar-title { font-weight: 700; font-size: 13px; color: #374151; }
  .print-bar-hint  { font-size: 11.5px; color: #6b7280; }
  .btn-print { background: #7c3aed; color: #fff; border: none; border-radius: 8px;
    padding: 9px 20px; font-size: 12.5px; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
  .btn-print:hover { background: #6d28d9; }

  /* ── Document header ── */
  .doc-header { background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);
    color: #fff; border-radius: 12px; padding: 24px 28px 20px; margin-bottom: 18px; }
  .doc-header h1 { font-size: 22px; font-weight: 800; letter-spacing: -.3px; margin-bottom: 3px; }
  .doc-header .school-name { font-size: 13px; opacity: .88; font-weight: 600; margin-bottom: 6px; }
  .doc-meta { display: flex; flex-wrap: wrap; gap: 16px; font-size: 11px; opacity: .82; margin-top: 8px; }
  .doc-meta span { display: inline-flex; align-items: center; gap: 4px; }

  /* ── Summary chips ── */
  .summary-row { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
  .chip { background: #fff; border-radius: 20px; padding: 5px 13px;
    font-size: 11.5px; font-weight: 600; color: #374151;
    box-shadow: 0 1px 3px rgba(0,0,0,.07); border: 1px solid #e5e7eb; }
  .chip.purple { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }

  /* ── Table ── */
  .table-wrap { background: #fff; border-radius: 10px; overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  thead th { background: #1e1b4b; color: #e0e7ff; font-weight: 700;
    padding: 10px 12px; text-align: left; font-size: 11px;
    text-transform: uppercase; letter-spacing: .5px; }
  tbody tr.even td { background: #fff; }
  tbody tr.odd  td { background: #f9fafb; }
  tbody tr:hover td { background: #f0f0ff; }
  td { padding: 9px 12px; border-bottom: 1px solid #f0f0f5; vertical-align: middle;
    max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  td:first-child { font-weight: 600; color: #374151; }

  /* ── Footer ── */
  .doc-footer { margin-top: 20px; text-align: center; font-size: 11px; color: #9ca3af; }

  /* ── Print media ── */
  @media print {
    body { background: #fff; font-size: 10.5px; }
    .page-wrap { max-width: 100%; padding: 0; }
    .print-bar { display: none !important; }
    .doc-header { -webkit-print-color-adjust: exact; print-color-adjust: exact;
      border-radius: 0; margin-bottom: 14px; }
    .table-wrap { box-shadow: none; border-radius: 0; border: 1px solid #ddd; }
    thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tbody tr.odd td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tbody tr { page-break-inside: avoid; }
    td { white-space: normal; }
  }
</style>
</head>
<body>
<div class="page-wrap">

  <!-- Print Bar -->
  <div class="print-bar">
    <div>
      <div class="print-bar-title">🖨️ {$reportTitle}</div>
      <div class="print-bar-hint">Click <strong>Print / Save as PDF</strong> → choose "Save as PDF" as the destination.</div>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
  </div>

  <!-- Document Header -->
  <div class="doc-header">
    <div class="school-name">📚 {$schoolName}</div>
    <h1>{$reportTitle}</h1>
    <div class="doc-meta">
      <span>📅 Generated: {$dateGenerated}</span>
      <span>📊 Records: {$totalRows}</span>
      {$filterNote}
    </div>
  </div>

  <!-- Summary chips -->
  <div class="summary-row">
    <span class="chip purple">📋 {$reportTitle}</span>
    <span class="chip">Total: {$totalRows} records</span>
    <span class="chip">Generated: {$dateGenerated}</span>
  </div>

  <!-- Data Table -->
  <div class="table-wrap">
    <table>
      <thead><tr>{$thHtml}</tr></thead>
      <tbody>{$tbodyHtml}</tbody>
    </table>
  </div>

  <div class="doc-footer">
    Generated by AcademiX AI · {$schoolName} · {$dateGenerated}
  </div>

</div>
<script>
  // Auto-trigger print dialog when opened via AI link (optional — remove if not wanted)
  // window.onload = function() { window.print(); };
</script>
</body>
</html>
HTML;

                // ── Save to uploads ────────────────────────────────────────
                $dirRel  = "tenant/{$school['slug']}/assets/uploads/ai_exports/{$schoolId}";
                $dirAbs  = rtrim(DOCUMENT_ROOT, '/') . '/' . $dirRel;
                if (!is_dir($dirAbs)) { mkdir($dirAbs, 0775, true); }
                $slug    = preg_replace('/[^a-z0-9]+/', '-', $pdfReportType);
                $ts      = date('Ymd-His');
                $filename = "report-{$slug}-{$ts}.html";
                $filePath = $dirAbs . '/' . $filename;
                file_put_contents($filePath, $html);
                $downloadUrl = '/' . $dirRel . '/' . $filename;

                return json_encode([
                    '__type'      => 'pdf_export',
                    'success'     => true,
                    'message'     => "{$reportTitle} is ready — {$totalRows} records. Open the link below and click Print / Save as PDF.",
                    'url'         => $downloadUrl,
                    'filename'    => $filename,
                    'report_type' => $pdfReportType,
                    'title'       => $reportTitle,
                    'rows'        => $totalRows,
                ]);

            } catch (Throwable $e) {
                error_log('AI create_pdf_export error: ' . $e->getMessage());
                return json_encode(['success' => false, '__type' => 'pdf_export', 'message' => 'Could not generate PDF report: ' . $e->getMessage()]);
            }
        }

        // ── create_class ─────────────────────────────────────────────────────
        case 'create_class': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }

            $name = trim((string)($args['name'] ?? ''));
            if ($name === '') {
                return json_encode(['success' => false, 'message' => 'Class name is required.']);
            }

            $academicYearId = (int)($args['academic_year_id'] ?? 0);
            if ($academicYearId <= 0) {
                $academicYearId = academix_ai_default_academic_year_id($schoolDb, (int)$school['id']);
            }
            if ($academicYearId <= 0) {
                return json_encode(['success' => false, 'message' => 'Create an academic year first, then create the class.']);
            }

            $code = trim((string)($args['code'] ?? ''));
            if ($code === '') {
                $code = academix_ai_code_from_name($name, 'CLS');
            }

            $data = [
                'name' => $name,
                'code' => strtoupper($code),
                'description' => trim((string)($args['description'] ?? '')),
                'grade_level' => isset($args['grade_level']) ? (int)$args['grade_level'] : null,
                'class_teacher_id' => !empty($args['class_teacher_id']) ? (int)$args['class_teacher_id'] : null,
                'capacity' => !empty($args['capacity']) ? max(1, (int)$args['capacity']) : 40,
                'room_number' => trim((string)($args['room_number'] ?? '')),
                'academic_year_id' => $academicYearId,
            ];

            if ($duplicate = academix_ai_recent_duplicate_action($schoolSlug, $toolName, $data)) {
                return json_encode($duplicate);
            }
            $result = $manager->createClass($data);
            academix_ai_remember_action($schoolSlug, $toolName, $data, is_array($result) ? $result : ['success' => (bool)$result]);
            return json_encode($result);
        }

        // ── create_subject ───────────────────────────────────────────────────
        case 'create_subject': {
            $name = trim((string)($args['name'] ?? ''));
            if ($name === '') {
                return json_encode(['success' => false, 'message' => 'Subject name is required.']);
            }

            $code = trim((string)($args['code'] ?? ''));
            if ($code === '') {
                $code = academix_ai_code_from_name($name, 'SUB');
            }

            $data = [
                'name' => $name,
                'code' => strtoupper($code),
                'type' => in_array(($args['type'] ?? 'core'), ['core', 'elective'], true) ? $args['type'] : 'core',
                'credit_hours' => isset($args['credit_hours']) ? (float)$args['credit_hours'] : 1.0,
                'description' => trim((string)($args['description'] ?? '')),
            ];

            if ($duplicate = academix_ai_recent_duplicate_action($schoolSlug, $toolName, $data)) {
                return json_encode($duplicate);
            }
            $result = $manager->createSubject($data);
            academix_ai_remember_action($schoolSlug, $toolName, $data, is_array($result) ? $result : ['success' => (bool)$result]);
            return json_encode($result);
        }

        // ── assign_subject_to_class ──────────────────────────────────────────
        case 'assign_subject_to_class': {
            $classId = (int)($args['class_id'] ?? 0);
            $subjectId = (int)($args['subject_id'] ?? 0);
            if ($classId <= 0 || $subjectId <= 0) {
                return json_encode(['success' => false, 'message' => 'Class ID and subject ID are required.']);
            }

            return json_encode($manager->assignSubjectToClass([
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'teacher_id' => !empty($args['teacher_id']) ? (int)$args['teacher_id'] : null,
            ]));
        }

        // ── list_subjects ────────────────────────────────────────────────────
        case 'list_subjects': {
            $subjects = $manager->getSubjects();
            return json_encode(array_map(fn($s) => [
                'id' => $s['id'],
                'name' => $s['name'],
                'code' => $s['code'] ?? '',
                'type' => $s['type'] ?? 'core',
            ], $subjects));
        }

        // ── send_whatsapp_message ────────────────────────────────────────────
        case 'send_whatsapp_message': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }

            $audience = strtolower(trim((string)($args['audience'] ?? 'all')));
            $title = trim((string)($args['title'] ?? 'School Notification'));
            $message = trim((string)($args['message'] ?? ''));
            if ($message === '') {
                return json_encode(['success' => false, 'message' => 'WhatsApp message body is required.']);
            }

            $allowed = ['all', 'parents', 'teachers', 'students', 'staff'];
            if (!in_array($audience, $allowed, true)) {
                $audience = 'all';
            }

            $service = new WhatsAppService($schoolDb, $school);
            $recipients = $service->resolveAnnouncementRecipients(
                in_array($audience, ['parents', 'teachers', 'students'], true) ? $audience : 'all',
                !empty($args['class_id']) ? (int)$args['class_id'] : null,
                !empty($args['section_id']) ? (int)$args['section_id'] : null,
                [$audience]
            );

            $result = academix_ai_send_whatsapp_batch($schoolDb, $school, 'announcement', $recipients, $title, $message, 'login.php');
            return json_encode($result);
        }

        // ── send_fee_reminders ───────────────────────────────────────────────
        case 'send_fee_reminders': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            if (!academix_ai_table_exists($schoolDb, 'invoices')) {
                return json_encode(['success' => false, 'message' => 'The invoices table is not available in this school database.']);
            }
            if (!WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'fees', true)) {
                return json_encode(['success' => false, 'message' => 'WhatsApp fee reminders are disabled in settings.']);
            }

            $statusFilter = strtolower(trim((string)($args['status_filter'] ?? 'all')));
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            $customMessage = trim((string)($args['message'] ?? ''));

            $where = [
                'i.school_id = ?',
                "i.status IN ('pending','partial','overdue')",
                'COALESCE(i.balance_amount, i.total_amount, 0) > 0',
            ];
            $params = [(int)$school['id']];

            if (!empty($args['student_id'])) {
                $where[] = 'i.student_id = ?';
                $params[] = (int)$args['student_id'];
            }
            if (!empty($args['class_id'])) {
                $where[] = 'i.class_id = ?';
                $params[] = (int)$args['class_id'];
            }
            if ($statusFilter === 'overdue') {
                $where[] = "(i.status = 'overdue' OR i.due_date < CURDATE())";
            } elseif ($statusFilter === 'due') {
                $where[] = "(i.status <> 'overdue' AND i.due_date >= CURDATE())";
            }

            try {
                $sql = "
                    SELECT
                        i.id AS invoice_id,
                        i.invoice_number,
                        i.due_date,
                        i.total_amount,
                        i.balance_amount,
                        i.status,
                        s.id AS student_id,
                        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name,
                        c.name AS class_name,
                        u.id AS guardian_user_id,
                        u.name AS guardian_name,
                        u.phone
                    FROM invoices i
                    INNER JOIN students s ON s.id = i.student_id AND s.school_id = i.school_id
                    LEFT JOIN classes c ON c.id = i.class_id AND c.school_id = i.school_id
                    INNER JOIN guardians g ON g.student_id = s.id AND g.school_id = s.school_id
                    INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id
                    WHERE " . implode(' AND ', $where) . "
                      AND u.is_active = 1
                      AND u.phone IS NOT NULL
                      AND u.phone != ''
                    ORDER BY i.due_date ASC, i.id ASC
                    LIMIT {$limit}
                ";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('AI fee reminder query failed: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch fee reminders: ' . $e->getMessage()]);
            }

            if (!$rows) {
                return json_encode(['success' => false, 'message' => 'No unpaid invoices matched the selected reminder filter.']);
            }

            $service = new WhatsAppService($schoolDb, $school);
            $sent = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $currency = $school['currency_symbol'] ?? ($school['currency'] ?? 'NGN');
                $balance = $currency . ' ' . number_format((float)($row['balance_amount'] ?? $row['total_amount'] ?? 0), 2);
                $dueDate = !empty($row['due_date']) ? date('F j, Y', strtotime($row['due_date'])) : 'the due date';
                $studentName = trim((string)($row['student_name'] ?? 'your child'));
                $title = 'Fee Payment Reminder';
                $body = "Invoice {$row['invoice_number']} for {$studentName} has an outstanding balance of {$balance}, due on {$dueDate}.";
                if ($customMessage !== '') {
                    $body .= ' ' . $customMessage;
                }

                $result = $service->sendDirectNotification(
                    'fee',
                    (int)$row['invoice_id'],
                    [
                        'user_id' => (int)$row['guardian_user_id'],
                        'name' => $row['guardian_name'] ?? 'Parent',
                        'phone' => $row['phone'] ?? '',
                        'recipient_type' => 'parent',
                    ],
                    $title,
                    $body,
                    'parent/fees.php'
                );

                if (!empty($result['success'])) {
                    $sent++;
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            return json_encode([
                'success' => $sent > 0,
                'total' => count($rows),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'message' => "Fee reminders processed for " . count($rows) . " recipient(s): {$sent} sent, {$failed} failed, {$skipped} skipped.",
            ]);
        }

        // ── send_attendance_alerts ───────────────────────────────────────────
        case 'send_attendance_alerts': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            if (!academix_ai_table_exists($schoolDb, 'attendance')) {
                return json_encode(['success' => false, 'message' => 'The attendance table is not available in this school database.']);
            }
            if (!WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'attendance', true)) {
                return json_encode(['success' => false, 'message' => 'WhatsApp attendance alerts are disabled in settings.']);
            }

            $date = trim((string)($args['date'] ?? date('Y-m-d')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d', strtotime($date) ?: time());
            }
            $status = strtolower(trim((string)($args['status'] ?? 'absent')));
            $allowedStatuses = ['all', 'present', 'absent', 'late', 'half_day', 'holiday'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'absent';
            }
            $limit = min(200, max(1, (int)($args['limit'] ?? 100)));
            $customMessage = trim((string)($args['message'] ?? ''));

            $where = ['a.school_id = ?', 'a.date = ?'];
            $params = [(int)$school['id'], $date];

            if ($status !== 'all') {
                $where[] = 'a.status = ?';
                $params[] = $status;
            }
            if (!empty($args['class_id'])) {
                $where[] = 'a.class_id = ?';
                $params[] = (int)$args['class_id'];
            }
            if (!empty($args['student_id'])) {
                $where[] = 'a.student_id = ?';
                $params[] = (int)$args['student_id'];
            }

            try {
                $sql = "
                    SELECT
                        a.id AS attendance_id,
                        a.status,
                        a.date,
                        a.remark,
                        s.id AS student_id,
                        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name,
                        c.name AS class_name,
                        u.id AS guardian_user_id,
                        u.name AS guardian_name,
                        u.phone
                    FROM attendance a
                    INNER JOIN students s ON s.id = a.student_id AND s.school_id = a.school_id
                    LEFT JOIN classes c ON c.id = a.class_id AND c.school_id = a.school_id
                    INNER JOIN guardians g ON g.student_id = s.id AND g.school_id = s.school_id
                    INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id
                    WHERE " . implode(' AND ', $where) . "
                      AND u.is_active = 1
                      AND u.phone IS NOT NULL
                      AND u.phone != ''
                    ORDER BY c.name, s.first_name
                    LIMIT {$limit}
                ";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('AI attendance alert query failed: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch attendance records: ' . $e->getMessage()]);
            }

            if (!$rows) {
                return json_encode(['success' => false, 'message' => 'No attendance records matched the selected alert filter.']);
            }

            $statusLabels = [
                'present' => 'Present',
                'absent' => 'Absent',
                'late' => 'Late',
                'half_day' => 'Half Day',
                'holiday' => 'Holiday',
            ];
            $service = new WhatsAppService($schoolDb, $school);
            $sent = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $studentName = trim((string)($row['student_name'] ?? 'your child'));
                $statusText = $statusLabels[$row['status']] ?? ucfirst((string)$row['status']);
                $formattedDate = date('F j, Y', strtotime($row['date']));
                $className = $row['class_name'] ?? 'their class';
                $title = "Attendance Alert: {$studentName}";
                $body = "{$studentName} from {$className} was marked as {$statusText} on {$formattedDate}.";
                if (!empty($row['remark'])) {
                    $body .= " Remark: {$row['remark']}.";
                }
                if ($customMessage !== '') {
                    $body .= ' ' . $customMessage;
                }

                $result = $service->sendDirectNotification(
                    'attendance',
                    (int)$row['attendance_id'],
                    [
                        'user_id' => (int)$row['guardian_user_id'],
                        'name' => $row['guardian_name'] ?? 'Parent',
                        'phone' => $row['phone'] ?? '',
                        'recipient_type' => 'parent',
                    ],
                    $title,
                    $body,
                    'parent/attendance.php'
                );

                if (!empty($result['success'])) {
                    $sent++;
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            return json_encode([
                'success' => $sent > 0,
                'total' => count($rows),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'message' => "Attendance alerts processed for " . count($rows) . " recipient(s): {$sent} sent, {$failed} failed, {$skipped} skipped.",
            ]);
        }

        // ── draft_email ───────────────────────────────────────────────────────
        // Generates the template draft and returns recipient count.
        // The actual sending happens via the direct send_email action (not AI).
        case 'draft_email': {
            $audience = $args['audience'] ?? 'all';
            $subject  = trim($args['subject'] ?? '');
            $body     = trim($args['body']    ?? '');
            $greeting = trim($args['greeting'] ?? '');
            $ctaText  = trim($args['cta_text'] ?? '');
            $ctaUrl   = trim($args['cta_url']  ?? '');

            // Count recipients so admin can see who will receive it
            $recipientCount = 0;
            if ($schoolDb) {
                $sender = new SchoolEmailSender($schoolDb, $school);
                $preview = $sender->resolveRecipients($audience);
                $recipientCount = $preview['count'];
            }

            // Return a structured draft the chat UI will render as a preview card
            return json_encode([
                '__type'          => 'email_draft',   // UI marker
                'audience'        => $audience,
                'subject'         => $subject,
                'body_html'       => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                'body_plain'      => $body,
                'greeting'        => $greeting,
                'cta_text'        => $ctaText,
                'cta_url'         => $ctaUrl,
                'recipient_count' => $recipientCount,
            ]);
        }

        // ── get_onboarding_status ─────────────────────────────────────────────
        case 'get_onboarding_status': {
            if (!$schoolDb) return json_encode(['error' => 'School database unavailable.']);
            $steps   = onboarding_build_steps($schoolDb, (int)$school['id'], $school);
            $done    = count(array_filter($steps, fn($s) => $s['done']));
            $total   = count($steps);
            $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
            return json_encode([
                'steps'   => $steps,
                'done'    => $done,
                'total'   => $total,
                'percent' => $percent,
                'message' => "{$done} of {$total} setup steps completed ({$percent}%).",
            ]);
        }

        // ── mark_onboarding_step ──────────────────────────────────────────────
        case 'mark_onboarding_step': {
            $stepKey = $args['step_key'] ?? '';
            if ($schoolDb && $stepKey) {
                onboarding_mark_step($schoolDb, (int)$school['id'], $stepKey);
                return json_encode(['success' => true, 'message' => "Step '{$stepKey}' marked as complete."]);
            }
            return json_encode(['success' => false, 'message' => 'No step key provided.']);
        }

        // ── navigate_to_page ──────────────────────────────────────────────────
        case 'navigate_to_page': {
            $page  = $args['page']        ?? 'general.php';
            $label = $args['label']       ?? 'Portal Page';
            $desc  = $args['description'] ?? '';
            $slug  = $school['slug']      ?? '';

            // Build absolute URL — works with both subdomain and path-based routing
            $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
            $url  = function_exists('school_route_url')
                ? school_route_url($slug, 'admin', $page, false)
                : "{$base}/tenant/{$slug}/admin/{$page}";

            // Icon map
            $iconMap = [
                'general.php'         => 'ri-settings-3-line',
                'school-profile.php'  => 'ri-building-2-line',
                'class-list.php'      => 'ri-school-line',
                'subject-list.php'    => 'ri-book-open-line',
                'add-new-teacher.php' => 'ri-user-star-line',
                'teacher-list.php'    => 'ri-user-star-line',
                'add-new-student.php' => 'ri-graduation-cap-line',
                'student-list.php'    => 'ri-graduation-cap-line',
                'transaction.php'     => 'ri-wallet-3-line',
                'event.php'           => 'ri-calendar-event-line',
                'notice-board.php'    => 'ri-megaphone-line',
                'timetable-list.php'  => 'ri-time-line',
            ];

            return json_encode([
                '__type'      => 'navigation',   // UI marker — renders as a nav card
                'page'        => $page,
                'url'         => $url,
                'label'       => $label,
                'description' => $desc,
                'icon'        => $iconMap[$page] ?? 'ri-arrow-right-line',
            ]);
        }

        // ── get_users_list ─────────────────────────────────────────────────────
        case 'get_users_list': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $userType = $args['user_type'] ?? 'all';
            $classId = !empty($args['class_id']) ? (int)$args['class_id'] : 0;
            $search = trim((string)($args['search'] ?? ''));
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            $allowedTypes = ['teachers', 'staff', 'students', 'parents', 'all'];
            if (!in_array($userType, $allowedTypes, true)) {
                $userType = 'all';
            }
            try {
                $where = ['u.school_id = ?', 'u.is_active = 1'];
                $params = [(int)$school['id']];
                $select = "u.id, u.name, u.email, u.phone, u.user_type";
                $from = "FROM users u";
                if ($userType === 'teachers') {
                    $where[] = "u.user_type = 'teacher'";
                } elseif ($userType === 'staff') {
                    $where[] = "u.user_type IN ('accountant','librarian','receptionist')";
                } elseif ($userType === 'students') {
                    $select .= ", CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name, c.name AS class_name";
                    $from .= " INNER JOIN students s ON s.user_id = u.id AND s.school_id = u.school_id";
                    $from .= " LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id";
                    $where[] = "u.user_type = 'student'";
                    if ($classId > 0) {
                        $where[] = 's.class_id = ?';
                        $params[] = $classId;
                    }
                } elseif ($userType === 'parents') {
                    $select .= ", u.name AS guardian_name";
                    $where[] = "u.user_type = 'parent'";
                } else {
                    $where[] = "u.user_type IN ('admin','teacher','student','parent','accountant','librarian','receptionist')";
                }
                if ($search !== '') {
                    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
                    $params[] = "%{$search}%";
                    $params[] = "%{$search}%";
                }
                $sql = "SELECT {$select} {$from} WHERE " . implode(' AND ', $where) . " ORDER BY u.name LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'users' => $rows, 'total' => count($rows)]);
            } catch (Throwable $e) {
                error_log('AI get_users_list error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch users: ' . $e->getMessage()]);
            }
        }

        // ── get_parents_by_class ───────────────────────────────────────────────
        case 'get_parents_by_class': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $classId = (int)($args['class_id'] ?? 0);
            $sectionId = !empty($args['section_id']) ? (int)$args['section_id'] : 0;
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            if ($classId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid class ID is required.']);
            }
            try {
                $where = [
                    'u.school_id = ?',
                    "u.user_type = 'parent'",
                    'u.is_active = 1',
                    's.class_id = ?',
                ];
                $params = [(int)$school['id'], $classId];
                if ($sectionId > 0) {
                    $where[] = 's.section_id = ?';
                    $params[] = $sectionId;
                }
                $sql = "
                    SELECT DISTINCT
                        u.id AS parent_user_id,
                        u.name AS parent_name,
                        u.email AS parent_email,
                        u.phone AS parent_phone,
                        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name,
                        c.name AS class_name
                    FROM users u
                    INNER JOIN guardians g ON g.user_id = u.id AND g.school_id = u.school_id
                    INNER JOIN students s ON s.id = g.student_id AND s.school_id = g.school_id
                    LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY u.name
                    LIMIT {$limit}
                ";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'parents' => $rows, 'total' => count($rows)]);
            } catch (Throwable $e) {
                error_log('AI get_parents_by_class error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch parents: ' . $e->getMessage()]);
            }
        }

        // ── mark_attendance ────────────────────────────────────────────────────
        case 'mark_attendance': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $classId = (int)($args['class_id'] ?? 0);
            $date = trim((string)($args['date'] ?? date('Y-m-d')));
            $status = $args['status'] ?? 'present';
            $remark = trim((string)($args['remark'] ?? ''));
            $sectionId = !empty($args['section_id']) ? (int)$args['section_id'] : 0;
            $allowedStatuses = ['present', 'absent', 'late', 'half_day', 'holiday'];
            if (!in_array($status, $allowedStatuses, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid status. Allowed: present, absent, late, half_day, holiday.']);
            }
            if ($classId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid class ID is required.']);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d', strtotime($date) ?: time());
            }
            try {
                $studentWhere = ['s.school_id = ?', 's.class_id = ?', '(s.status IS NULL OR s.status = ?)'];
                $studentParams = [(int)$school['id'], $classId, 'active'];
                if ($sectionId > 0) {
                    $studentWhere[] = 's.section_id = ?';
                    $studentParams[] = $sectionId;
                }
                $studentSql = "
                    SELECT s.id, s.user_id, CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name
                    FROM students s
                    WHERE " . implode(' AND ', $studentWhere) . "
                    ORDER BY s.first_name
                ";
                $studentStmt = $schoolDb->prepare($studentSql);
                $studentStmt->execute($studentParams);
                $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!$students) {
                    return json_encode(['success' => false, 'message' => 'No active students found in this class.']);
                }
                $inserted = 0;
                $updated = 0;
                $schoolDb->beginTransaction();
                foreach ($students as $student) {
                    $checkStmt = $schoolDb->prepare("
                        SELECT id FROM attendance
                        WHERE student_id = ? AND date = ? AND school_id = ?
                        LIMIT 1
                    ");
                    $checkStmt->execute([$student['id'], $date, $school['id']]);
                    $existingId = $checkStmt->fetchColumn();
                    if ($existingId) {
                        $updateStmt = $schoolDb->prepare("
                            UPDATE attendance SET status = ?, remark = ?, marked_by = ?, session = 'full_day'
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$status, $remark, $userId, $existingId]);
                        $updated++;
                    } else {
                        $insertStmt = $schoolDb->prepare("
                            INSERT INTO attendance (school_id, student_id, class_id, date, status, remark, marked_by, session)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'full_day')
                        ");
                        $insertStmt->execute([$school['id'], $student['id'], $classId, $date, $status, $remark, $userId]);
                        $inserted++;
                    }
                }
                $schoolDb->commit();
                return json_encode([
                    'success' => true,
                    'message' => "Attendance marked for {$classId}: {$inserted} new, {$updated} updated. Total: " . count($students) . " students.",
                    'class_id' => $classId,
                    'date' => $date,
                    'status' => $status,
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'total_students' => count($students),
                ]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) {
                    $schoolDb->rollBack();
                }
                error_log('AI mark_attendance error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not mark attendance: ' . $e->getMessage()]);
            }
        }

        // ── get_student_details ────────────────────────────────────────────────
        case 'get_student_details': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $search = trim((string)($args['search'] ?? ''));
            $classId = (int)($args['class_id'] ?? 0);
            $limit = min(100, max(1, (int)($args['limit'] ?? 20)));
            try {
                $where = ['s.school_id = ?'];
                $params = [(int)$school['id']];
                if ($classId > 0) {
                    $where[] = 's.class_id = ?';
                    $params[] = $classId;
                }
                if ($search !== '') {
                    $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.admission_number LIKE ?)';
                    $searchTerm = "%{$search}%";
                    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
                }
                $sql = "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.admission_number,
                               s.roll_number, s.date_of_birth, stu.gender AS gender, s.status,
                               c.name AS class_name, sec.name AS section_name,
                               (SELECT SUM(COALESCE(i.balance_amount, 0)) FROM invoices i WHERE i.student_id = s.id AND i.school_id = s.school_id AND i.status NOT IN ('paid','cancelled')) AS fee_balance
                        FROM students s
                        LEFT JOIN users stu ON stu.id = s.user_id AND stu.school_id = s.school_id
                        LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
                        LEFT JOIN sections sec ON sec.id = s.section_id AND sec.school_id = s.school_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY s.first_name LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($students && $search !== '') {
                    foreach ($students as &$st) {
                        $gStmt = $schoolDb->prepare("SELECT u.id, u.name, u.email, u.phone, u.user_type FROM guardians g INNER JOIN users u ON u.id = g.user_id WHERE g.student_id = ? AND g.school_id = ?");
                        $gStmt->execute([$st['id'], $school['id']]);
                        $st['guardians'] = $gStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }
                }
                return json_encode(['success' => true, 'students' => $students, 'total' => count($students)]);
            } catch (Throwable $e) {
                error_log('AI get_student_details error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch students: ' . $e->getMessage()]);
            }
        }

        // ── get_attendance_report ───────────────────────────────────────────────
        case 'get_attendance_report': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            if (!academix_ai_table_exists($schoolDb, 'attendance')) {
                return json_encode(['success' => false, 'message' => 'Attendance table not available.']);
            }
            $classId = (int)($args['class_id'] ?? 0);
            $studentId = (int)($args['student_id'] ?? 0);
            $startDate = trim((string)($args['start_date'] ?? date('Y-m-d', strtotime('-30 days'))));
            $endDate = trim((string)($args['end_date'] ?? date('Y-m-d')));
            try {
                $where = ['a.school_id = ?'];
                $params = [(int)$school['id']];
                if ($classId > 0) { $where[] = 'a.class_id = ?'; $params[] = $classId; }
                if ($studentId > 0) { $where[] = 'a.student_id = ?'; $params[] = $studentId; }
                $where[] = 'a.date >= ?'; $params[] = $startDate;
                $where[] = 'a.date <= ?'; $params[] = $endDate;
                $sql = "SELECT a.status, COUNT(*) as count FROM attendance a WHERE " . implode(' AND ', $where) . " GROUP BY a.status ORDER BY a.status";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
                $total = array_sum($stats);
                $labels = ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'half_day' => 'Half Day', 'holiday' => 'Holiday'];
                $breakdown = [];
                foreach ($labels as $key => $label) {
                    $count = (int)($stats[$key] ?? 0);
                    $breakdown[] = ['status' => $key, 'label' => $label, 'count' => $count, 'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0];
                }
                return json_encode(['success' => true, 'total_records' => $total, 'start_date' => $startDate, 'end_date' => $endDate, 'breakdown' => $breakdown]);
            } catch (Throwable $e) {
                error_log('AI get_attendance_report error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch attendance report: ' . $e->getMessage()]);
            }
        }

        // ── get_fee_structure ──────────────────────────────────────────────────
        case 'get_fee_structure': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $classId = (int)($args['class_id'] ?? 0);
            $yearId = (int)($args['academic_year_id'] ?? 0);
            if ($yearId <= 0) { $yearId = academix_ai_default_academic_year_id($schoolDb, (int)$school['id']); }
            try {
                $where = ['fs.school_id = ?'];
                $params = [(int)$school['id']];
                if ($classId > 0) { $where[] = 'fs.class_id = ?'; $params[] = $classId; }
                if ($yearId > 0) { $where[] = 'fs.academic_year_id = ?'; $params[] = $yearId; }
                $sql = "SELECT fs.id, fs.amount, fs.due_date, fs.late_fee,
                               fc.name AS category_name, fc.description AS category_desc,
                               c.name AS class_name, ay.name AS year_name
                        FROM fee_structures fs
                        INNER JOIN fee_categories fc ON fc.id = fs.fee_category_id AND fc.school_id = fs.school_id
                        LEFT JOIN classes c ON c.id = fs.class_id AND c.school_id = fs.school_id
                        LEFT JOIN academic_years ay ON ay.id = fs.academic_year_id AND ay.school_id = fs.school_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY c.name, fc.name";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'fee_items' => $rows, 'total' => count($rows)]);
            } catch (Throwable $e) {
                error_log('AI get_fee_structure error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch fee structure: ' . $e->getMessage()]);
            }
        }

        // ── get_student_fee_balance ─────────────────────────────────────────────
        case 'get_student_fee_balance': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $studentId = (int)($args['student_id'] ?? 0);
            if ($studentId <= 0) return json_encode(['success' => false, 'message' => 'A valid student ID is required.']);
            try {
                $stmt = $schoolDb->prepare("SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS student_name, admission_number, class_id FROM students WHERE id = ? AND school_id = ?");
                $stmt->execute([$studentId, $school['id']]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) return json_encode(['success' => false, 'message' => 'Student not found.']);
                $invStmt = $schoolDb->prepare("SELECT COUNT(*) as total_invoices, COALESCE(SUM(total_amount), 0) as total_invoiced, COALESCE(SUM(paid_amount), 0) as total_paid, COALESCE(SUM(balance_amount), 0) as total_balance FROM invoices WHERE student_id = ? AND school_id = ?");
                $invStmt->execute([$studentId, $school['id']]);
                $summary = $invStmt->fetch(PDO::FETCH_ASSOC);
                $stmt2 = $schoolDb->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, i.balance_amount, i.due_date, i.status FROM invoices i WHERE i.student_id = ? AND i.school_id = ? AND i.status NOT IN ('paid','cancelled') ORDER BY i.due_date ASC");
                $stmt2->execute([$studentId, $school['id']]);
                $invoices = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'student' => $student, 'summary' => $summary, 'outstanding_invoices' => $invoices]);
            } catch (Throwable $e) {
                error_log('AI get_student_fee_balance error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch fee balance: ' . $e->getMessage()]);
            }
        }

        // ── record_fee_payment ──────────────────────────────────────────────────
        case 'record_fee_payment': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $invoiceId = (int)($args['invoice_id'] ?? 0);
            $amount = (float)($args['amount'] ?? 0);
            $method = $args['payment_method'] ?? 'cash';
            $txnId = trim((string)($args['transaction_id'] ?? ''));
            if ($invoiceId <= 0 || $amount <= 0) return json_encode(['success' => false, 'message' => 'Invoice ID and a positive amount are required.']);
            $allowedMethods = ['cash', 'bank_transfer', 'card', 'cheque', 'mobile_money', 'other'];
            if (!in_array($method, $allowedMethods, true)) $method = 'cash';
            try {
                $invStmt = $schoolDb->prepare("SELECT id, total_amount, paid_amount, balance_amount, status FROM invoices WHERE id = ? AND school_id = ?");
                $invStmt->execute([$invoiceId, $school['id']]);
                $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
                if (!$invoice) return json_encode(['success' => false, 'message' => 'Invoice not found.']);
                $newPaid = (float)$invoice['paid_amount'] + $amount;
                $newBalance = max(0, (float)$invoice['balance_amount'] - $amount);
                $newStatus = $newBalance <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : $invoice['status']);
                $updateStmt = $schoolDb->prepare("UPDATE invoices SET paid_amount = ?, balance_amount = ?, status = ?, payment_method = ?, transaction_id = COALESCE(NULLIF(?, ''), transaction_id), paid_at = NOW() WHERE id = ? AND school_id = ?");
                $updateStmt->execute([$newPaid, $newBalance, $newStatus, $method, $txnId, $invoiceId, $school['id']]);
                return json_encode(['success' => true, 'message' => "Payment of {$amount} recorded for invoice #{$invoiceId}. New balance: {$newBalance}. Status: {$newStatus}.",
                    'invoice_id' => $invoiceId, 'amount' => $amount, 'new_balance' => $newBalance, 'new_status' => $newStatus]);
            } catch (Throwable $e) {
                error_log('AI record_fee_payment error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not record payment: ' . $e->getMessage()]);
            }
        }

        // ── get_class_timetable ─────────────────────────────────────────────────
        case 'get_class_timetable': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $classId = (int)($args['class_id'] ?? 0);
            $sectionId = (int)($args['section_id'] ?? 0);
            $dayFilter = trim((string)($args['day'] ?? ''));
            if ($classId <= 0) return json_encode(['success' => false, 'message' => 'A valid class ID is required.']);
            try {
                $where = ['t.class_id = ?', 't.school_id = ?'];
                $params = [$classId, (int)$school['id']];
                if ($sectionId > 0) { $where[] = 't.section_id = ?'; $params[] = $sectionId; }
                if ($dayFilter !== '') { $where[] = 't.day = ?'; $params[] = $dayFilter; }
                $sql = "SELECT t.id, t.day, t.period_number, t.start_time, t.end_time, t.room_number, t.is_break,
                               sub.name AS subject_name, sub.code AS subject_code,
                               u.name AS teacher_name,
                               sec.name AS section_name
                        FROM timetables t
                        LEFT JOIN subjects sub ON sub.id = t.subject_id AND sub.school_id = t.school_id
                        LEFT JOIN users u ON u.id = t.teacher_id AND u.school_id = t.school_id
                        LEFT JOIN sections sec ON sec.id = t.section_id AND sec.school_id = t.school_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(t.day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), t.period_number";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $days = [];
                foreach ($rows as $r) {
                    $days[$r['day']][] = $r;
                }
                return json_encode(['success' => true, 'class_id' => $classId, 'timetable' => $rows, 'grouped_by_day' => $days]);
            } catch (Throwable $e) {
                error_log('AI get_class_timetable error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch timetable: ' . $e->getMessage()]);
            }
        }

        // ── generate_student_list ───────────────────────────────────────────────
        case 'generate_student_list': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $classId = (int)($args['class_id'] ?? 0);
            $sectionId = (int)($args['section_id'] ?? 0);
            $includeContacts = !empty($args['include_contacts']);
            $limit = min(500, max(1, (int)($args['limit'] ?? 100)));
            try {
                $where = ['s.school_id = ?'];
                $params = [(int)$school['id']];
                if ($classId > 0) { $where[] = 's.class_id = ?'; $params[] = $classId; }
                if ($sectionId > 0) { $where[] = 's.section_id = ?'; $params[] = $sectionId; }
                $extraSelect = '';
                $extraJoin = '';
                if ($includeContacts) {
                    $extraSelect = ", GROUP_CONCAT(DISTINCT CONCAT(u.name, '|', COALESCE(u.phone, ''), '|', COALESCE(u.email, '')) SEPARATOR '; ') AS guardian_contacts";
                    $extraJoin = " LEFT JOIN guardians g ON g.student_id = s.id AND g.school_id = s.school_id LEFT JOIN users u ON u.id = g.user_id AND u.school_id = s.school_id";
                }
                $sql = "SELECT s.id, CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name,
                               s.admission_number, s.roll_number, stu.gender AS gender, c.name AS class_name, sec.name AS section_name
                               {$extraSelect}
                        FROM students s
                        LEFT JOIN users stu ON stu.id = s.user_id AND stu.school_id = s.school_id
                        LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
                        LEFT JOIN sections sec ON sec.id = s.section_id AND sec.school_id = s.school_id
                        {$extraJoin}
                        WHERE " . implode(' AND ', $where) . " GROUP BY s.id ORDER BY s.first_name LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'students' => $students, 'total' => count($students)]);
            } catch (Throwable $e) {
                error_log('AI generate_student_list error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not generate student list: ' . $e->getMessage()]);
            }
        }

        // ── create_academic_year ────────────────────────────────────────────────
        case 'create_academic_year': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $name = trim((string)($args['name'] ?? ''));
            $startDate = trim((string)($args['start_date'] ?? ''));
            $endDate = trim((string)($args['end_date'] ?? ''));
            $isDefault = !empty($args['is_default']);
            if ($name === '' || $startDate === '' || $endDate === '') {
                return json_encode(['success' => false, 'message' => 'Name, start_date, and end_date are required.']);
            }
            try {
                $stmt = $schoolDb->prepare("INSERT INTO academic_years (school_id, name, start_date, end_date, is_default, status, created_at) VALUES (?, ?, ?, ?, ?, 'upcoming', NOW())");
                $stmt->execute([$school['id'], $name, $startDate, $endDate, $isDefault ? 1 : 0]);
                $yearId = $schoolDb->lastInsertId();
                if ($isDefault) {
                    $schoolDb->prepare("UPDATE academic_years SET is_default = 0 WHERE school_id = ? AND id != ?")->execute([$school['id'], $yearId]);
                }
                return json_encode(['success' => true, 'message' => "Academic year '{$name}' created successfully.", 'id' => $yearId]);
            } catch (Throwable $e) {
                error_log('AI create_academic_year error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create academic year: ' . $e->getMessage()]);
            }
        }

        // ── get_exam_schedule ───────────────────────────────────────────────────
        case 'get_exam_schedule': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $classId = (int)($args['class_id'] ?? 0);
            $yearId = (int)($args['academic_year_id'] ?? 0);
            if ($classId <= 0) return json_encode(['success' => false, 'message' => 'A valid class ID is required.']);
            try {
                $eWhere = ['e.school_id = ?'];
                $eParams = [(int)$school['id']];
                if ($yearId > 0) { $eWhere[] = 'e.academic_year_id = ?'; $eParams[] = $yearId; }
                $sql = "SELECT e.id, e.name AS exam_name, e.description, e.start_date, e.end_date,
                               ay.name AS academic_year, at.name AS term_name
                        FROM exams e
                        LEFT JOIN academic_years ay ON ay.id = e.academic_year_id AND ay.school_id = e.school_id
                        LEFT JOIN academic_terms at ON at.id = e.academic_term_id AND at.school_id = e.school_id
                        WHERE " . implode(' AND ', $eWhere) . " ORDER BY e.start_date DESC";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($eParams);
                $exams = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (academix_ai_table_exists($schoolDb, 'exam_schedules')) {
                    foreach ($exams as &$exam) {
                        $sStmt = $schoolDb->prepare("SELECT es.id, sub.name AS subject_name, es.exam_date, es.start_time, es.end_time, es.room FROM exam_schedules es INNER JOIN subjects sub ON sub.id = es.subject_id WHERE es.exam_id = ? AND es.class_id = ? AND es.school_id = ? ORDER BY es.exam_date");
                        $sStmt->execute([$exam['id'], $classId, $school['id']]);
                        $exam['schedules'] = $sStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }
                } else {
                    $schoolDb = $schoolDb; // noop
                }
                return json_encode(['success' => true, 'exams' => $exams]);
            } catch (Throwable $e) {
                error_log('AI get_exam_schedule error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch exam schedule: ' . $e->getMessage()]);
            }
        }

        // ── promote_students ────────────────────────────────────────────────────
        case 'promote_students': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $fromClassId = (int)($args['from_class_id'] ?? 0);
            $toClassId = (int)($args['to_class_id'] ?? 0);
            $yearId = (int)($args['academic_year_id'] ?? 0);
            $confirmed = !empty($args['confirm']);
            if ($fromClassId <= 0 || $toClassId <= 0) return json_encode(['success' => false, 'message' => 'Both from_class_id and to_class_id are required.']);
            if (!$confirmed) return json_encode(['success' => false, 'message' => 'Please set confirm=true to execute promotion. This will move all active students from the source class to the target class.']);
            try {
                $checkStmt = $schoolDb->prepare("SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS student_name FROM students WHERE school_id = ? AND class_id = ? AND (status IS NULL OR status = 'active')");
                $checkStmt->execute([$school['id'], $fromClassId]);
                $students = $checkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!$students) return json_encode(['success' => false, 'message' => 'No active students found in the source class.']);
                $schoolDb->beginTransaction();
                $updateSql = "UPDATE students SET class_id = ?";
                $updateParams = [$toClassId];
                if ($yearId > 0) { $updateSql .= ", academic_year_id = ?"; $updateParams[] = $yearId; }
                $updateSql .= " WHERE school_id = ? AND class_id = ? AND (status IS NULL OR status = 'active')";
                $updateParams[] = $school['id'];
                $updateParams[] = $fromClassId;
                $upStmt = $schoolDb->prepare($updateSql);
                $upStmt->execute($updateParams);
                $count = $upStmt->rowCount();
                $schoolDb->commit();
                return json_encode(['success' => true, 'message' => "{$count} students promoted successfully from class {$fromClassId} to class {$toClassId}.", 'promoted' => $count, 'from_class_id' => $fromClassId, 'to_class_id' => $toClassId]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI promote_students error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not promote students: ' . $e->getMessage()]);
            }
        }

        // ── send_email_now ──────────────────────────────────────────────────────
        case 'send_email_now': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $audience = $args['audience'] ?? 'all';
            $subject = trim((string)($args['subject'] ?? ''));
            $body = trim((string)($args['body'] ?? ''));
            $greeting = trim((string)($args['greeting'] ?? ''));
            $classId = !empty($args['class_id']) ? (int)$args['class_id'] : 0;
            if ($subject === '' || $body === '') return json_encode(['success' => false, 'message' => 'Subject and body are required.']);
            $allowedAudiences = ['all', 'parents', 'teachers', 'staff', 'students'];
            if (!in_array($audience, $allowedAudiences, true)) $audience = 'all';
            try {
                $emailConfigPath = __DIR__ . '/../../../config/mail.php';
                if (file_exists($emailConfigPath)) require_once $emailConfigPath;
                if (!class_exists('SchoolEmailSender')) {
                    $senderPath = __DIR__ . '/../../../includes/Services/SchoolEmailSender.php';
                    if (file_exists($senderPath)) require_once $senderPath;
                }
                if (!class_exists('SchoolEmailSender')) {
                    return json_encode(['success' => false, 'message' => 'Email service not available.']);
                }
                $sender = new SchoolEmailSender($schoolDb, $school);
                $preview = $sender->resolveRecipients($audience, $classId);
                if ($preview['count'] <= 0) return json_encode(['success' => false, 'message' => 'No recipients found for the selected audience.']);
                $htmlBody = '<p>' . nl2br(htmlspecialchars($greeting ? $greeting . '<br><br>' : '', ENT_QUOTES, 'UTF-8')) . '</p>';
                $htmlBody .= '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>';
                $sent = $sender->send($audience, $subject, $htmlBody, $body, $classId);
                if ($sent > 0) {
                    return json_encode(['success' => true, 'message' => "Email sent to {$sent} recipient(s).", 'sent' => $sent, 'audience' => $audience, 'subject' => $subject]);
                } else {
                    return json_encode(['success' => false, 'message' => 'Failed to send email. Check email configuration.', 'sent' => 0]);
                }
            } catch (Throwable $e) {
                error_log('AI send_email_now error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not send email: ' . $e->getMessage()]);
            }
        }

        // ── create_user ──────────────────────────────────────────────
        case 'create_user': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $name = trim((string)($args['name'] ?? ''));
            $email = trim((string)($args['email'] ?? ''));
            $phone = trim((string)($args['phone'] ?? ''));
            $username = trim((string)($args['username'] ?? ''));
            $password = (string)($args['password'] ?? '');
            $userType = (string)($args['user_type'] ?? 'staff');
            $gender = $args['gender'] ?? null;
            $dob = $args['date_of_birth'] ?? null;
            $address = trim((string)($args['address'] ?? ''));
            $isActive = isset($args['is_active']) ? ($args['is_active'] ? 1 : 0) : 1;
            if ($name === '' || $email === '') {
                return json_encode(['success' => false, 'message' => 'Name and email are required.']);
            }
            $allowedTypes = ['admin', 'accountant', 'librarian', 'receptionist', 'staff'];
            if (!in_array($userType, $allowedTypes, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid user type. Allowed: ' . implode(', ', $allowedTypes)]);
            }
            try {
                $check = $schoolDb->prepare("SELECT id FROM users WHERE email = ? AND school_id = ? LIMIT 1");
                $check->execute([$email, (int)$school['id']]);
                if ($check->fetchColumn()) {
                    return json_encode(['success' => false, 'message' => 'A user with this email already exists.']);
                }
                if ($password === '') {
                    $password = password_hash(bin2hex(random_bytes(3)), PASSWORD_DEFAULT);
                } else {
                    $password = password_hash($password, PASSWORD_DEFAULT);
                }
                if ($username === '') $username = $email;
                $stmt = $schoolDb->prepare("INSERT INTO users (school_id, name, email, phone, username, password, user_type, gender, date_of_birth, address, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([(int)$school['id'], $name, $email, $phone ?: null, $username, $password, $userType, $gender, $dob ?: null, $address ?: null, $isActive]);
                $newId = $schoolDb->lastInsertId();
                return json_encode(['success' => true, 'message' => "User '{$name}' created successfully.", 'user_id' => $newId, 'user_type' => $userType]);
            } catch (Throwable $e) {
                error_log('AI create_user error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create user: ' . $e->getMessage()]);
            }
        }

        // ── update_user ──────────────────────────────────────────────
        case 'update_user': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $tgtUserId = (int)($args['user_id'] ?? 0);
            if ($tgtUserId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid user ID is required.']);
            }
            $fields = [];
            $params = [];
            foreach (['name', 'email', 'phone', 'address'] as $f) {
                if (isset($args[$f])) {
                    $fields[] = "{$f} = ?";
                    $params[] = trim((string)$args[$f]) ?: null;
                }
            }
            foreach (['gender', 'date_of_birth'] as $f) {
                if (isset($args[$f])) {
                    $fields[] = "{$f} = ?";
                    $params[] = $args[$f] ?: null;
                }
            }
            if (!$fields) {
                return json_encode(['success' => false, 'message' => 'No fields to update.']);
            }
            $params[] = (int)$school['id'];
            $params[] = $tgtUserId;
            try {
                $stmt = $schoolDb->prepare("UPDATE users SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE school_id = ? AND id = ?");
                $stmt->execute($params);
                return json_encode(['success' => true, 'message' => 'User updated successfully.']);
            } catch (Throwable $e) {
                error_log('AI update_user error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not update user: ' . $e->getMessage()]);
            }
        }

        // ── get_user ─────────────────────────────────────────────────
        case 'get_user': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $tgtUserId = (int)($args['user_id'] ?? 0);
            $search = trim((string)($args['search'] ?? ''));
            try {
                if ($tgtUserId > 0) {
                    $stmt = $schoolDb->prepare("SELECT id, name, email, phone, username, user_type, gender, date_of_birth, address, is_active, created_at FROM users WHERE id = ? AND school_id = ?");
                    $stmt->execute([$tgtUserId, (int)$school['id']]);
                } elseif ($search !== '') {
                    $searchTerm = "%{$search}%";
                    $stmt = $schoolDb->prepare("SELECT id, name, email, phone, username, user_type, gender, date_of_birth, address, is_active, created_at FROM users WHERE school_id = ? AND (name LIKE ? OR email LIKE ?) LIMIT 1");
                    $stmt->execute([(int)$school['id'], $searchTerm, $searchTerm]);
                } else {
                    return json_encode(['success' => false, 'message' => 'Provide a user_id or a search term.']);
                }
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    return json_encode(['success' => false, 'message' => 'User not found.']);
                }
                return json_encode(['success' => true, 'user' => $user]);
            } catch (Throwable $e) {
                error_log('AI get_user error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch user: ' . $e->getMessage()]);
            }
        }

        // ── toggle_user_status ────────────────────────────────────────
        case 'toggle_user_status': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $tgtUserId = (int)($args['user_id'] ?? 0);
            $isActive = !empty($args['is_active']);
            if ($tgtUserId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid user ID is required.']);
            }
            try {
                $stmt = $schoolDb->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                $stmt->execute([$isActive ? 1 : 0, $tgtUserId, (int)$school['id']]);
                return json_encode(['success' => true, 'message' => 'User ' . ($isActive ? 'activated' : 'deactivated') . ' successfully.']);
            } catch (Throwable $e) {
                error_log('AI toggle_user_status error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not toggle user status: ' . $e->getMessage()]);
            }
        }

        // ── create_student ────────────────────────────────────────────
        case 'create_student': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $firstName = trim((string)($args['first_name'] ?? ''));
            $middleName = trim((string)($args['middle_name'] ?? ''));
            $lastName = trim((string)($args['last_name'] ?? ''));
            $email = trim((string)($args['email'] ?? ''));
            $phone = trim((string)($args['phone'] ?? ''));
            $gender = $args['gender'] ?? '';
            $dob = $args['date_of_birth'] ?? '';
            $classId = (int)($args['class_id'] ?? 0);
            $sectionId = (int)($args['section_id'] ?? 0);
            $admissionDate = trim((string)($args['admission_date'] ?? date('Y-m-d')));
            if ($firstName === '' || $gender === '' || $dob === '' || $classId <= 0) {
                return json_encode(['success' => false, 'message' => 'First name, gender, date_of_birth, and class_id are required.']);
            }
            $allowedGenders = ['male', 'female', 'other'];
            if (!in_array($gender, $allowedGenders, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid gender.']);
            }
            try {
                $admissionNumber = 'ADM-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $fullName = $firstName . ($middleName !== '' ? ' ' . $middleName : '') . ($lastName !== '' ? ' ' . $lastName : '');
                $plainPassword = bin2hex(random_bytes(3));
                $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
                $schoolDb->beginTransaction();
                $userStmt = $schoolDb->prepare("INSERT INTO users (school_id, name, email, phone, username, password, user_type, gender, date_of_birth, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'student', ?, ?, 1, NOW(), NOW())");
                $userStmt->execute([(int)$school['id'], $fullName, $email ?: null, $phone ?: null, $email ?: $admissionNumber, $hashedPassword, $gender, $dob]);
                $newUserId = $schoolDb->lastInsertId();
                $studentStmt = $schoolDb->prepare("INSERT INTO students (school_id, user_id, admission_number, class_id, section_id, admission_date, first_name, middle_name, last_name, date_of_birth, current_address, permanent_address, previous_school, previous_class, blood_group, allergies, medical_conditions, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())");
                $studentStmt->execute([
                    (int)$school['id'], $newUserId, $admissionNumber, $classId,
                    $sectionId > 0 ? $sectionId : null, $admissionDate, $firstName,
                    $middleName ?: null, $lastName ?: null, $dob,
                    trim((string)($args['current_address'] ?? '')) ?: null,
                    trim((string)($args['permanent_address'] ?? '')) ?: null,
                    trim((string)($args['previous_school'] ?? '')) ?: null,
                    trim((string)($args['previous_class'] ?? '')) ?: null,
                    trim((string)($args['blood_group'] ?? '')) ?: null,
                    trim((string)($args['allergies'] ?? '')) ?: null,
                    trim((string)($args['medical_conditions'] ?? '')) ?: null,
                ]);
                $newStudentId = $schoolDb->lastInsertId();
                $schoolDb->commit();
                return json_encode([
                    'success' => true,
                    'message' => "Student '{$fullName}' enrolled successfully. Admission number: {$admissionNumber}.",
                    'student_id' => $newStudentId,
                    'user_id' => $newUserId,
                    'admission_number' => $admissionNumber,
                    'password' => $plainPassword,
                ]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI create_student error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create student: ' . $e->getMessage()]);
            }
        }

        // ── update_student ────────────────────────────────────────────
        case 'update_student': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $studentId = (int)($args['student_id'] ?? 0);
            if ($studentId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid student ID is required.']);
            }
            $fields = [];
            $params = [];
            foreach (['first_name', 'middle_name', 'last_name', 'current_address', 'permanent_address', 'blood_group', 'allergies', 'medical_conditions'] as $f) {
                if (isset($args[$f])) {
                    $fields[] = "{$f} = ?";
                    $params[] = trim((string)$args[$f]) ?: null;
                }
            }
            if (isset($args['date_of_birth'])) {
                $fields[] = 'date_of_birth = ?';
                $params[] = $args['date_of_birth'] ?: null;
            }
            if (!$fields) {
                return json_encode(['success' => false, 'message' => 'No fields to update.']);
            }
            $params[] = (int)$school['id'];
            $params[] = $studentId;
            try {
                $stmt = $schoolDb->prepare("UPDATE students SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE school_id = ? AND id = ?");
                $stmt->execute($params);
                return json_encode(['success' => true, 'message' => 'Student updated successfully.']);
            } catch (Throwable $e) {
                error_log('AI update_student error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not update student: ' . $e->getMessage()]);
            }
        }

        // ── get_student_by_admission ───────────────────────────────────
        case 'get_student_by_admission': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $admission = trim((string)($args['admission_number'] ?? ''));
            if ($admission === '') {
                return json_encode(['success' => false, 'message' => 'Admission number is required.']);
            }
            try {
                $stmt = $schoolDb->prepare("SELECT s.id, s.first_name, s.middle_name, s.last_name, s.admission_number, s.roll_number, s.date_of_birth, s.status, s.blood_group, s.allergies, s.medical_conditions, c.name AS class_name, sec.name AS section_name, (SELECT SUM(COALESCE(i.balance_amount, 0)) FROM invoices i WHERE i.student_id = s.id AND i.school_id = s.school_id AND i.status NOT IN ('paid','cancelled')) AS fee_balance FROM students s LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id LEFT JOIN sections sec ON sec.id = s.section_id AND sec.school_id = s.school_id WHERE s.admission_number = ? AND s.school_id = ? LIMIT 1");
                $stmt->execute([$admission, (int)$school['id']]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    return json_encode(['success' => false, 'message' => 'Student not found with that admission number.']);
                }
                $gStmt = $schoolDb->prepare("SELECT u.id AS user_id, u.name, u.email, u.phone, g.relationship, g.is_primary FROM guardians g INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id WHERE g.student_id = ? AND g.school_id = ?");
                $gStmt->execute([$student['id'], (int)$school['id']]);
                $student['guardians'] = $gStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'student' => $student]);
            } catch (Throwable $e) {
                error_log('AI get_student_by_admission error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch student: ' . $e->getMessage()]);
            }
        }

        // ── transfer_student ───────────────────────────────────────────
        case 'transfer_student': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $studentId = (int)($args['student_id'] ?? 0);
            $newClassId = (int)($args['new_class_id'] ?? 0);
            $newSectionId = (int)($args['new_section_id'] ?? 0);
            if ($studentId <= 0 || $newClassId <= 0) {
                return json_encode(['success' => false, 'message' => 'Student ID and new class ID are required.']);
            }
            try {
                $check = $schoolDb->prepare("SELECT id FROM students WHERE id = ? AND school_id = ?");
                $check->execute([$studentId, (int)$school['id']]);
                if (!$check->fetchColumn()) {
                    return json_encode(['success' => false, 'message' => 'Student not found.']);
                }
                if ($newSectionId > 0) {
                    $stmt = $schoolDb->prepare("UPDATE students SET class_id = ?, section_id = ?, status = 'active', updated_at = NOW() WHERE id = ? AND school_id = ?");
                    $stmt->execute([$newClassId, $newSectionId, $studentId, (int)$school['id']]);
                } else {
                    $stmt = $schoolDb->prepare("UPDATE students SET class_id = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                    $stmt->execute([$newClassId, $studentId, (int)$school['id']]);
                }
                return json_encode(['success' => true, 'message' => 'Student transferred successfully.', 'student_id' => $studentId, 'new_class_id' => $newClassId]);
            } catch (Throwable $e) {
                error_log('AI transfer_student error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not transfer student: ' . $e->getMessage()]);
            }
        }

        // ── update_student_status ──────────────────────────────────────
        case 'update_student_status': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $studentId = (int)($args['student_id'] ?? 0);
            $status = (string)($args['status'] ?? '');
            $allowed = ['active', 'graduated', 'transferred', 'withdrawn'];
            if ($studentId <= 0 || !in_array($status, $allowed, true)) {
                return json_encode(['success' => false, 'message' => 'A valid student ID and status (active/graduated/transferred/withdrawn) are required.']);
            }
            try {
                $stmt = $schoolDb->prepare("UPDATE students SET status = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                $stmt->execute([$status, $studentId, (int)$school['id']]);
                return json_encode(['success' => true, 'message' => "Student status updated to '{$status}'."]);
            } catch (Throwable $e) {
                error_log('AI update_student_status error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not update student status: ' . $e->getMessage()]);
            }
        }

        // ── create_teacher ─────────────────────────────────────────────
        case 'create_teacher': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $name = trim((string)($args['name'] ?? ''));
            $email = trim((string)($args['email'] ?? ''));
            $phone = trim((string)($args['phone'] ?? ''));
            $gender = $args['gender'] ?? '';
            $qualification = trim((string)($args['qualification'] ?? ''));
            $specialization = trim((string)($args['specialization'] ?? ''));
            $experienceYears = (int)($args['experience_years'] ?? 0);
            $joiningDate = trim((string)($args['joining_date'] ?? date('Y-m-d')));
            $salaryGrade = trim((string)($args['salary_grade'] ?? ''));
            if ($name === '' || $gender === '') {
                return json_encode(['success' => false, 'message' => 'Name and gender are required.']);
            }
            $allowedGenders = ['male', 'female', 'other'];
            if (!in_array($gender, $allowedGenders, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid gender.']);
            }
            try {
                $employeeId = 'EMP-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $plainPassword = bin2hex(random_bytes(3));
                $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
                $schoolDb->beginTransaction();
                $userStmt = $schoolDb->prepare("INSERT INTO users (school_id, name, email, phone, username, password, user_type, gender, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?, 1, NOW(), NOW())");
                $userStmt->execute([(int)$school['id'], $name, $email ?: null, $phone ?: null, $email ?: $employeeId, $hashedPassword, $gender]);
                $newUserId = $schoolDb->lastInsertId();
                $teacherStmt = $schoolDb->prepare("INSERT INTO teachers (school_id, user_id, employee_id, qualification, specialization, experience_years, joining_date, salary_grade, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
                $teacherStmt->execute([(int)$school['id'], $newUserId, $employeeId, $qualification ?: null, $specialization ?: null, $experienceYears > 0 ? $experienceYears : null, $joiningDate, $salaryGrade ?: null]);
                $newTeacherId = $schoolDb->lastInsertId();
                $schoolDb->commit();
                return json_encode([
                    'success' => true,
                    'message' => "Teacher '{$name}' registered successfully. Employee ID: {$employeeId}.",
                    'teacher_id' => $newTeacherId,
                    'user_id' => $newUserId,
                    'employee_id' => $employeeId,
                    'password' => $plainPassword,
                ]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI create_teacher error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create teacher: ' . $e->getMessage()]);
            }
        }

        // ── update_teacher ─────────────────────────────────────────────
        case 'update_teacher': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $teacherId = (int)($args['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid teacher ID is required.']);
            }
            $fields = [];
            $params = [];
            foreach (['qualification', 'specialization', 'salary_grade', 'bank_name', 'bank_account', 'ifsc_code'] as $f) {
                if (isset($args[$f])) {
                    $fields[] = "{$f} = ?";
                    $params[] = trim((string)$args[$f]) ?: null;
                }
            }
            if (isset($args['experience_years'])) {
                $fields[] = 'experience_years = ?';
                $params[] = (int)$args['experience_years'];
            }
            if (isset($args['is_active'])) {
                $fields[] = 'is_active = ?';
                $params[] = $args['is_active'] ? 1 : 0;
            }
            if (!$fields) {
                return json_encode(['success' => false, 'message' => 'No fields to update.']);
            }
            $params[] = (int)$school['id'];
            $params[] = $teacherId;
            try {
                $stmt = $schoolDb->prepare("UPDATE teachers SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE school_id = ? AND id = ?");
                $stmt->execute($params);
                return json_encode(['success' => true, 'message' => 'Teacher updated successfully.']);
            } catch (Throwable $e) {
                error_log('AI update_teacher error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not update teacher: ' . $e->getMessage()]);
            }
        }

        // ── get_teacher ────────────────────────────────────────────────
        case 'get_teacher': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $teacherId = (int)($args['teacher_id'] ?? 0);
            $tgtUserId = (int)($args['user_id'] ?? 0);
            try {
                if ($teacherId > 0) {
                    $stmt = $schoolDb->prepare("SELECT t.id AS teacher_id, t.employee_id, t.qualification, t.specialization, t.experience_years, t.joining_date, t.salary_grade, t.bank_name, t.bank_account, t.ifsc_code, t.is_active, u.id AS user_id, u.name, u.email, u.phone, u.gender, u.date_of_birth, u.address FROM teachers t INNER JOIN users u ON u.id = t.user_id AND u.school_id = t.school_id WHERE t.id = ? AND t.school_id = ? LIMIT 1");
                    $stmt->execute([$teacherId, (int)$school['id']]);
                } elseif ($tgtUserId > 0) {
                    $stmt = $schoolDb->prepare("SELECT t.id AS teacher_id, t.employee_id, t.qualification, t.specialization, t.experience_years, t.joining_date, t.salary_grade, t.bank_name, t.bank_account, t.ifsc_code, t.is_active, u.id AS user_id, u.name, u.email, u.phone, u.gender, u.date_of_birth, u.address FROM teachers t INNER JOIN users u ON u.id = t.user_id AND u.school_id = t.school_id WHERE t.user_id = ? AND t.school_id = ? LIMIT 1");
                    $stmt->execute([$tgtUserId, (int)$school['id']]);
                } else {
                    return json_encode(['success' => false, 'message' => 'Provide a teacher_id or user_id.']);
                }
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$teacher) {
                    return json_encode(['success' => false, 'message' => 'Teacher not found.']);
                }
                return json_encode(['success' => true, 'teacher' => $teacher]);
            } catch (Throwable $e) {
                error_log('AI get_teacher error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch teacher: ' . $e->getMessage()]);
            }
        }

        // ── create_guardian ────────────────────────────────────────────
        case 'create_guardian': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $studentId = (int)($args['student_id'] ?? 0);
            $name = trim((string)($args['name'] ?? ''));
            $email = trim((string)($args['email'] ?? ''));
            $phone = trim((string)($args['phone'] ?? ''));
            $relationship = (string)($args['relationship'] ?? 'guardian');
            $isPrimary = isset($args['is_primary']) ? ($args['is_primary'] ? 1 : 0) : 1;
            $canPickup = isset($args['can_pickup']) ? ($args['can_pickup'] ? 1 : 0) : 1;
            $emergencyContact = isset($args['emergency_contact']) ? ($args['emergency_contact'] ? 1 : 0) : 0;
            if ($studentId <= 0 || $name === '') {
                return json_encode(['success' => false, 'message' => 'Student ID and guardian name are required.']);
            }
            $allowedRelationships = ['father', 'mother', 'brother', 'sister', 'uncle', 'aunt', 'grandfather', 'grandmother', 'guardian', 'other'];
            if (!in_array($relationship, $allowedRelationships, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid relationship.']);
            }
            try {
                $checkStudent = $schoolDb->prepare("SELECT id FROM students WHERE id = ? AND school_id = ?");
                $checkStudent->execute([$studentId, (int)$school['id']]);
                if (!$checkStudent->fetchColumn()) {
                    return json_encode(['success' => false, 'message' => 'Student not found.']);
                }
                $plainPassword = bin2hex(random_bytes(3));
                $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
                $schoolDb->beginTransaction();
                $userStmt = $schoolDb->prepare("INSERT INTO users (school_id, name, email, phone, username, password, user_type, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'parent', 1, NOW(), NOW())");
                $userStmt->execute([(int)$school['id'], $name, $email ?: null, $phone ?: null, $email ?: strtolower(str_replace(' ', '_', $name)), $hashedPassword]);
                $newUserId = $schoolDb->lastInsertId();
                $guardStmt = $schoolDb->prepare("INSERT INTO guardians (school_id, user_id, student_id, relationship, is_primary, can_pickup, emergency_contact, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $guardStmt->execute([(int)$school['id'], $newUserId, $studentId, $relationship, $isPrimary, $canPickup, $emergencyContact]);
                $newGuardianId = $schoolDb->lastInsertId();
                $schoolDb->commit();
                return json_encode([
                    'success' => true,
                    'message' => "Guardian '{$name}' added successfully.",
                    'guardian_id' => $newGuardianId,
                    'user_id' => $newUserId,
                    'password' => $plainPassword,
                ]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI create_guardian error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create guardian: ' . $e->getMessage()]);
            }
        }

        // ── update_guardian ────────────────────────────────────────────
        case 'update_guardian': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $guardianId = (int)($args['guardian_id'] ?? 0);
            if ($guardianId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid guardian ID is required.']);
            }
            try {
                $guardStmt = $schoolDb->prepare("SELECT user_id FROM guardians WHERE id = ? AND school_id = ?");
                $guardStmt->execute([$guardianId, (int)$school['id']]);
                $guardRow = $guardStmt->fetch(PDO::FETCH_ASSOC);
                if (!$guardRow) {
                    return json_encode(['success' => false, 'message' => 'Guardian not found.']);
                }
                $guardianUserId = (int)$guardRow['user_id'];
                $guardFields = [];
                $guardParams = [];
                foreach (['relationship', 'is_primary', 'can_pickup', 'emergency_contact'] as $f) {
                    if (isset($args[$f])) {
                        if (in_array($f, ['is_primary', 'can_pickup', 'emergency_contact'], true)) {
                            $guardFields[] = "{$f} = ?";
                            $guardParams[] = $args[$f] ? 1 : 0;
                        } else {
                            $guardFields[] = "{$f} = ?";
                            $guardParams[] = $args[$f];
                        }
                    }
                }
                if (isset($args['relationship'])) {
                    $allowed = ['father', 'mother', 'brother', 'sister', 'uncle', 'aunt', 'grandfather', 'grandmother', 'guardian', 'other'];
                    if (!in_array($args['relationship'], $allowed, true)) {
                        return json_encode(['success' => false, 'message' => 'Invalid relationship.']);
                    }
                }
                $userFields = [];
                $userParams = [];
                foreach (['name', 'email', 'phone'] as $f) {
                    if (isset($args[$f])) {
                        $userFields[] = "{$f} = ?";
                        $userParams[] = trim((string)$args[$f]) ?: null;
                    }
                }
                $schoolDb->beginTransaction();
                if ($guardFields) {
                    $guardParams[] = $guardianId;
                    $guardParams[] = (int)$school['id'];
                    $gStmt = $schoolDb->prepare("UPDATE guardians SET " . implode(', ', $guardFields) . " WHERE id = ? AND school_id = ?");
                    $gStmt->execute($guardParams);
                }
                if ($userFields) {
                    $userParams[] = (int)$school['id'];
                    $userParams[] = $guardianUserId;
                    $uStmt = $schoolDb->prepare("UPDATE users SET " . implode(', ', $userFields) . ", updated_at = NOW() WHERE school_id = ? AND id = ?");
                    $uStmt->execute($userParams);
                }
                $schoolDb->commit();
                return json_encode(['success' => true, 'message' => 'Guardian updated successfully.']);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI update_guardian error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not update guardian: ' . $e->getMessage()]);
            }
        }

        // ── get_student_guardians ──────────────────────────────────────
        case 'get_student_guardians': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $studentId = (int)($args['student_id'] ?? 0);
            if ($studentId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid student ID is required.']);
            }
            try {
                $stmt = $schoolDb->prepare("SELECT g.id AS guardian_id, g.relationship, g.is_primary, g.can_pickup, g.emergency_contact, u.id AS user_id, u.name, u.email, u.phone FROM guardians g INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id WHERE g.student_id = ? AND g.school_id = ? ORDER BY g.is_primary DESC, g.id ASC");
                $stmt->execute([$studentId, (int)$school['id']]);
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'guardians' => $guardians, 'total' => count($guardians)]);
            } catch (Throwable $e) {
                error_log('AI get_student_guardians error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch guardians: ' . $e->getMessage()]);
            }
        }

        // ── create_library_book ────────────────────────────────────────
        case 'create_library_book': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $name = trim((string)($args['name'] ?? ''));
            $author = trim((string)($args['author'] ?? ''));
            $publisher = trim((string)($args['publisher'] ?? ''));
            $isbn = trim((string)($args['isbn'] ?? ''));
            $quantity = max(1, (int)($args['quantity'] ?? 1));
            $rackNo = trim((string)($args['rack_no'] ?? ''));
            $subject = trim((string)($args['subject'] ?? ''));
            $price = (float)($args['price'] ?? 0);
            $purchaseDate = trim((string)($args['purchase_date'] ?? date('Y-m-d')));
            if ($name === '') {
                return json_encode(['success' => false, 'message' => 'Book name is required.']);
            }
            try {
                $availableCol = academix_ai_column_exists($schoolDb, 'library_books', 'available_quantity') ? 'available_quantity' : (academix_ai_column_exists($schoolDb, 'library_books', 'available') ? 'available' : null);
                $nameCol = academix_ai_column_exists($schoolDb, 'library_books', 'title') ? 'title' : 'name';
                $shelfCol = academix_ai_column_exists($schoolDb, 'library_books', 'shelf_location') ? 'shelf_location' : 'rack_no';
                $dateCol = academix_ai_column_exists($schoolDb, 'library_books', 'purchase_date') ? 'purchase_date' : 'post_date';
                $statusCol = academix_ai_column_exists($schoolDb, 'library_books', 'is_active') ? 'is_active' : 'status';
                $cols = ["school_id", $nameCol, "author", "publisher", "isbn", "quantity", $shelfCol, "subject", "price", $dateCol, $statusCol, "created_at"];
                $placeholders = ["?", "?", "?", "?", "?", "?", "?", "?", "?", "?", "1", "NOW()"];
                if ($availableCol) {
                    $cols[] = $availableCol;
                    $placeholders[] = '?';
                }
                $sql = "INSERT INTO library_books (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $vals = [(int)$school['id'], $name, $author ?: null, $publisher ?: null, $isbn ?: null, $quantity, $rackNo ?: null, $subject ?: null, $price > 0 ? $price : null, $purchaseDate];
                if ($availableCol) $vals[] = $quantity;
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($vals);
                $bookId = $schoolDb->lastInsertId();
                return json_encode(['success' => true, 'message' => "Book '{$name}' added to library.", 'book_id' => $bookId]);
            } catch (Throwable $e) {
                error_log('AI create_library_book error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not add book: ' . $e->getMessage()]);
            }
        }

        // ── update_library_book ────────────────────────────────────────
        case 'update_library_book': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $bookId = (int)($args['book_id'] ?? 0);
            if ($bookId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid book ID is required.']);
            }
            try {
                $nameCol = academix_ai_column_exists($schoolDb, 'library_books', 'title') ? 'title' : 'name';
                $shelfCol = academix_ai_column_exists($schoolDb, 'library_books', 'shelf_location') ? 'shelf_location' : 'rack_no';
                $fieldMap = ['name' => $nameCol, 'author' => 'author', 'publisher' => 'publisher', 'isbn' => 'isbn', 'quantity' => 'quantity', 'rack_no' => $shelfCol, 'subject' => 'subject', 'price' => 'price'];
                $fields = [];
                $params = [];
                foreach ($fieldMap as $argKey => $dbCol) {
                    if (isset($args[$argKey])) {
                        $fields[] = "{$dbCol} = ?";
                        if ($argKey === 'quantity') {
                            $params[] = max(1, (int)$args[$argKey]);
                        } elseif ($argKey === 'price') {
                            $params[] = (float)$args[$argKey];
                        } else {
                            $params[] = trim((string)$args[$argKey]) ?: null;
                        }
                    }
                }
                if (!$fields) {
                    return json_encode(['success' => false, 'message' => 'No fields to update.']);
                }
                $params[] = (int)$school['id'];
                $params[] = $bookId;
                $stmt = $schoolDb->prepare("UPDATE library_books SET " . implode(', ', $fields) . " WHERE school_id = ? AND id = ?");
                $stmt->execute($params);
                return json_encode(['success' => true, 'message' => 'Book updated successfully.']);
            } catch (Throwable $e) {
                error_log('AI update_library_book error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not update book: ' . $e->getMessage()]);
            }
        }

        // ── search_library_books ───────────────────────────────────────
        case 'search_library_books': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $search = trim((string)($args['search'] ?? ''));
            $subjectFilter = trim((string)($args['subject'] ?? ''));
            $limit = min(100, max(1, (int)($args['limit'] ?? 20)));
            try {
                $nameCol = academix_ai_column_exists($schoolDb, 'library_books', 'title') ? 'title' : 'name';
                $availCol = academix_ai_column_exists($schoolDb, 'library_books', 'available_quantity') ? 'available_quantity' : (academix_ai_column_exists($schoolDb, 'library_books', 'available') ? 'available' : 'quantity');
                $shelfCol = academix_ai_column_exists($schoolDb, 'library_books', 'shelf_location') ? 'shelf_location' : 'rack_no';
                $where = ["school_id = ?"];
                $params = [(int)$school['id']];
                if ($search !== '') {
                    $where[] = "({$nameCol} LIKE ? OR author LIKE ? OR isbn LIKE ? OR subject LIKE ?)";
                    $term = "%{$search}%";
                    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
                }
                if ($subjectFilter !== '') {
                    $where[] = "subject = ?";
                    $params[] = $subjectFilter;
                }
                $sql = "SELECT id, {$nameCol} AS book_name, author, publisher, isbn, quantity, {$availCol} AS available, {$shelfCol} AS rack_no, subject, price FROM library_books WHERE " . implode(' AND ', $where) . " ORDER BY {$nameCol} ASC LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $books = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'books' => $books, 'total' => count($books)]);
            } catch (Throwable $e) {
                error_log('AI search_library_books error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not search books: ' . $e->getMessage()]);
            }
        }

        // ── get_library_book ───────────────────────────────────────────
        case 'get_library_book': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $bookId = (int)($args['book_id'] ?? 0);
            if ($bookId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid book ID is required.']);
            }
            try {
                $nameCol = academix_ai_column_exists($schoolDb, 'library_books', 'title') ? 'title' : 'name';
                $availCol = academix_ai_column_exists($schoolDb, 'library_books', 'available_quantity') ? 'available_quantity' : (academix_ai_column_exists($schoolDb, 'library_books', 'available') ? 'available' : 'quantity');
                $shelfCol = academix_ai_column_exists($schoolDb, 'library_books', 'shelf_location') ? 'shelf_location' : 'rack_no';
                $dateCol = academix_ai_column_exists($schoolDb, 'library_books', 'purchase_date') ? 'purchase_date' : 'post_date';
                $sql = "SELECT id, {$nameCol} AS book_name, author, publisher, isbn, quantity, {$availCol} AS available, {$shelfCol} AS rack_no, subject, price, {$dateCol} AS purchase_date FROM library_books WHERE id = ? AND school_id = ? LIMIT 1";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute([$bookId, (int)$school['id']]);
                $book = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$book) {
                    return json_encode(['success' => false, 'message' => 'Book not found.']);
                }
                $issueStmt = $schoolDb->prepare("SELECT li.id AS issue_id, li.issue_date, li.due_date, li.return_date, li.status, lm.name AS member_name FROM library_issues li LEFT JOIN library_members lm ON lm.id = li.member_id AND lm.school_id = li.school_id WHERE li.book_id = ? AND li.school_id = ? ORDER BY li.issue_date DESC LIMIT 10");
                $issueStmt->execute([$bookId, (int)$school['id']]);
                $book['recent_issues'] = $issueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'book' => $book]);
            } catch (Throwable $e) {
                error_log('AI get_library_book error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch book: ' . $e->getMessage()]);
            }
        }

        // ── create_library_member ──────────────────────────────────────
        case 'create_library_member': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $memberName = trim((string)($args['name'] ?? ''));
            $memberType = (string)($args['member_type'] ?? 'student');
            $email = trim((string)($args['email'] ?? ''));
            $phone = trim((string)($args['phone'] ?? ''));
            $address = trim((string)($args['address'] ?? ''));
            if ($memberName === '') {
                return json_encode(['success' => false, 'message' => 'Member name is required.']);
            }
            $allowedTypes = ['student', 'teacher', 'staff'];
            if (!in_array($memberType, $allowedTypes, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid member type.']);
            }
            try {
                $typeCol = academix_ai_column_exists($schoolDb, 'library_members', 'membership_type') ? 'membership_type' : 'member_type';
                $statusCol = academix_ai_column_exists($schoolDb, 'library_members', 'is_active') ? 'is_active' : 'status';
                $cols = ["school_id", "name", $typeCol, "email", "phone", "address", $statusCol, "created_at"];
                $placeholders = ["?", "?", "?", "?", "?", "?", "1", "NOW()"];
                $sql = "INSERT INTO library_members (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute([(int)$school['id'], $memberName, $memberType, $email ?: null, $phone ?: null, $address ?: null]);
                $memberId = $schoolDb->lastInsertId();
                return json_encode(['success' => true, 'message' => "Library member '{$memberName}' registered.", 'member_id' => $memberId]);
            } catch (Throwable $e) {
                error_log('AI create_library_member error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create library member: ' . $e->getMessage()]);
            }
        }

        // ── list_library_members ───────────────────────────────────────
        case 'list_library_members': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $memberType = (string)($args['member_type'] ?? 'all');
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            try {
                $typeCol = academix_ai_column_exists($schoolDb, 'library_members', 'membership_type') ? 'membership_type' : 'member_type';
                $statusCol = academix_ai_column_exists($schoolDb, 'library_members', 'is_active') ? 'is_active' : 'status';
                $where = ["school_id = ?"];
                $params = [(int)$school['id']];
                if ($memberType !== 'all') {
                    $where[] = "{$typeCol} = ?";
                    $params[] = $memberType;
                }
                $sql = "SELECT id, name, {$typeCol} AS member_type, email, phone, address, {$statusCol} AS status, created_at FROM library_members WHERE " . implode(' AND ', $where) . " ORDER BY name ASC LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'members' => $members, 'total' => count($members)]);
            } catch (Throwable $e) {
                error_log('AI list_library_members error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not list members: ' . $e->getMessage()]);
            }
        }

        // ── issue_book ─────────────────────────────────────────────────
        case 'issue_book': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $bookId = (int)($args['book_id'] ?? 0);
            $memberId = (int)($args['member_id'] ?? 0);
            $issueDate = trim((string)($args['issue_date'] ?? date('Y-m-d')));
            $dueDate = trim((string)($args['due_date'] ?? date('Y-m-d', strtotime('+14 days'))));
            if ($bookId <= 0 || $memberId <= 0) {
                return json_encode(['success' => false, 'message' => 'Book ID and member ID are required.']);
            }
            try {
                $availCol = academix_ai_column_exists($schoolDb, 'library_books', 'available_quantity') ? 'available_quantity' : (academix_ai_column_exists($schoolDb, 'library_books', 'available') ? 'available' : null);
                $checkBook = $schoolDb->prepare("SELECT id, quantity FROM library_books WHERE id = ? AND school_id = ? LIMIT 1");
                $checkBook->execute([$bookId, (int)$school['id']]);
                $book = $checkBook->fetch(PDO::FETCH_ASSOC);
                if (!$book) {
                    return json_encode(['success' => false, 'message' => 'Book not found.']);
                }
                if ($availCol) {
                    $availStmt = $schoolDb->prepare("SELECT {$availCol} FROM library_books WHERE id = ? AND school_id = ?");
                    $availStmt->execute([$bookId, (int)$school['id']]);
                    $available = (int)$availStmt->fetchColumn();
                    if ($available <= 0) {
                        return json_encode(['success' => false, 'message' => 'No copies available for issue.']);
                    }
                }
                $checkMember = $schoolDb->prepare("SELECT id FROM library_members WHERE id = ? AND school_id = ? LIMIT 1");
                $checkMember->execute([$memberId, (int)$school['id']]);
                if (!$checkMember->fetchColumn()) {
                    return json_encode(['success' => false, 'message' => 'Library member not found.']);
                }
                $schoolDb->beginTransaction();
                $issueStmt = $schoolDb->prepare("INSERT INTO library_issues (school_id, book_id, member_id, issue_date, due_date, status, created_at) VALUES (?, ?, ?, ?, ?, 'issued', NOW())");
                $issueStmt->execute([(int)$school['id'], $bookId, $memberId, $issueDate, $dueDate]);
                $issueId = $schoolDb->lastInsertId();
                if ($availCol) {
                    $schoolDb->prepare("UPDATE library_books SET {$availCol} = {$availCol} - 1 WHERE id = ? AND school_id = ?")->execute([$bookId, (int)$school['id']]);
                }
                $schoolDb->commit();
                return json_encode(['success' => true, 'message' => 'Book issued successfully.', 'issue_id' => $issueId, 'due_date' => $dueDate]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI issue_book error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not issue book: ' . $e->getMessage()]);
            }
        }

        // ── return_book ────────────────────────────────────────────────
        case 'return_book': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $issueId = (int)($args['issue_id'] ?? 0);
            $returnDate = trim((string)($args['return_date'] ?? date('Y-m-d')));
            if ($issueId <= 0) {
                return json_encode(['success' => false, 'message' => 'A valid issue ID is required.']);
            }
            try {
                $check = $schoolDb->prepare("SELECT li.id, li.book_id, li.status FROM library_issues li WHERE li.id = ? AND li.school_id = ?");
                $check->execute([$issueId, (int)$school['id']]);
                $issue = $check->fetch(PDO::FETCH_ASSOC);
                if (!$issue) {
                    return json_encode(['success' => false, 'message' => 'Issue record not found.']);
                }
                if ($issue['status'] === 'returned') {
                    return json_encode(['success' => false, 'message' => 'This book has already been returned.']);
                }
                $availCol = academix_ai_column_exists($schoolDb, 'library_books', 'available_quantity') ? 'available_quantity' : (academix_ai_column_exists($schoolDb, 'library_books', 'available') ? 'available' : null);
                $schoolDb->beginTransaction();
                $returnStmt = $schoolDb->prepare("UPDATE library_issues SET status = 'returned', return_date = ? WHERE id = ? AND school_id = ?");
                $returnStmt->execute([$returnDate, $issueId, (int)$school['id']]);
                if ($availCol) {
                    $schoolDb->prepare("UPDATE library_books SET {$availCol} = {$availCol} + 1 WHERE id = ? AND school_id = ?")->execute([$issue['book_id'], (int)$school['id']]);
                }
                $schoolDb->commit();
                return json_encode(['success' => true, 'message' => 'Book returned successfully.', 'issue_id' => $issueId, 'return_date' => $returnDate]);
            } catch (Throwable $e) {
                if ($schoolDb && $schoolDb->inTransaction()) $schoolDb->rollBack();
                error_log('AI return_book error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not return book: ' . $e->getMessage()]);
            }
        }

        // ── list_library_issues ────────────────────────────────────────
        case 'list_library_issues': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $bookIdFilter = (int)($args['book_id'] ?? 0);
            $memberIdFilter = (int)($args['member_id'] ?? 0);
            $statusFilter = (string)($args['status'] ?? 'all');
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            try {
                $where = ['li.school_id = ?'];
                $params = [(int)$school['id']];
                if ($bookIdFilter > 0) { $where[] = 'li.book_id = ?'; $params[] = $bookIdFilter; }
                if ($memberIdFilter > 0) { $where[] = 'li.member_id = ?'; $params[] = $memberIdFilter; }
                if ($statusFilter !== 'all') { $where[] = 'li.status = ?'; $params[] = $statusFilter; }
                $nameCol = academix_ai_column_exists($schoolDb, 'library_books', 'title') ? 'title' : 'name';
                $sql = "SELECT li.id AS issue_id, li.book_id, li.member_id, li.issue_date, li.due_date, li.return_date, li.status, lb.{$nameCol} AS book_name, lb.isbn, lm.name AS member_name, lm.member_type FROM library_issues li INNER JOIN library_books lb ON lb.id = li.book_id AND lb.school_id = li.school_id INNER JOIN library_members lm ON lm.id = li.member_id AND lm.school_id = li.school_id WHERE " . implode(' AND ', $where) . " ORDER BY li.issue_date DESC LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $issues = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'issues' => $issues, 'total' => count($issues)]);
            } catch (Throwable $e) {
                error_log('AI list_library_issues error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not list issues: ' . $e->getMessage()]);
            }
        }

        // ── get_overdue_books ──────────────────────────────────────────
        case 'get_overdue_books': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $minDays = max(1, (int)($args['days_overdue'] ?? 1));
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            try {
                $nameCol = academix_ai_column_exists($schoolDb, 'library_books', 'title') ? 'title' : 'name';
                $sql = "SELECT li.id AS issue_id, li.book_id, li.member_id, li.issue_date, li.due_date, DATEDIFF(CURDATE(), li.due_date) AS days_overdue, lb.{$nameCol} AS book_name, lb.isbn, lm.name AS member_name, lm.member_type, lm.email AS member_email, lm.phone AS member_phone FROM library_issues li INNER JOIN library_books lb ON lb.id = li.book_id AND lb.school_id = li.school_id INNER JOIN library_members lm ON lm.id = li.member_id AND lm.school_id = li.school_id WHERE li.school_id = ? AND li.status = 'issued' AND li.due_date < CURDATE() AND DATEDIFF(CURDATE(), li.due_date) >= ? ORDER BY li.due_date ASC LIMIT {$limit}";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute([(int)$school['id'], $minDays]);
                $overdue = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'overdue_books' => $overdue, 'total' => count($overdue)]);
            } catch (Throwable $e) {
                error_log('AI get_overdue_books error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch overdue books: ' . $e->getMessage()]);
            }
        }

        // ── create_leave_type ──────────────────────────────────────────
        case 'create_leave_type': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $name = trim((string)($args['name'] ?? ''));
            $description = trim((string)($args['description'] ?? ''));
            $maxDays = (int)($args['max_days_per_year'] ?? 0);
            $applicableTo = (string)($args['applicable_to'] ?? 'all');
            $isPaid = isset($args['is_paid']) ? ($args['is_paid'] ? 1 : 0) : 1;
            if ($name === '') {
                return json_encode(['success' => false, 'message' => 'Leave type name is required.']);
            }
            $allowedApplicable = ['all', 'teacher', 'staff', 'student'];
            if (!in_array($applicableTo, $allowedApplicable, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid applicable_to value.']);
            }
            try {
                $stmt = $schoolDb->prepare("INSERT INTO leave_types (school_id, name, description, max_days_per_year, applicable_to, is_paid, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([(int)$school['id'], $name, $description ?: null, $maxDays > 0 ? $maxDays : null, $applicableTo, $isPaid]);
                $typeId = $schoolDb->lastInsertId();
                return json_encode(['success' => true, 'message' => "Leave type '{$name}' created.", 'leave_type_id' => $typeId]);
            } catch (Throwable $e) {
                error_log('AI create_leave_type error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create leave type: ' . $e->getMessage()]);
            }
        }

        // ── list_leave_types ───────────────────────────────────────────
        case 'list_leave_types': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $applicableFilter = (string)($args['applicable_to'] ?? '');
            try {
                $where = ['school_id = ?', 'is_active = 1'];
                $params = [(int)$school['id']];
                if ($applicableFilter !== '' && in_array($applicableFilter, ['all', 'teacher', 'staff', 'student'], true)) {
                    $where[] = '(applicable_to = ? OR applicable_to = ?)';
                    $params[] = $applicableFilter;
                    $params[] = 'all';
                }
                $sql = "SELECT id, name, description, max_days_per_year, applicable_to, is_paid FROM leave_types WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return json_encode(['success' => true, 'leave_types' => $types, 'total' => count($types)]);
            } catch (Throwable $e) {
                error_log('AI list_leave_types error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not list leave types: ' . $e->getMessage()]);
            }
        }

        // ── create_leave_request ───────────────────────────────────────
        case 'create_leave_request': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $tgtUserId = (int)($args['user_id'] ?? 0);
            $userType = (string)($args['user_type'] ?? '');
            $leaveTypeId = (int)($args['leave_type_id'] ?? 0);
            $startDate = trim((string)($args['start_date'] ?? ''));
            $endDate = trim((string)($args['end_date'] ?? ''));
            $reason = trim((string)($args['reason'] ?? ''));
            if ($tgtUserId <= 0 || $userType === '' || $leaveTypeId <= 0 || $startDate === '' || $endDate === '' || $reason === '') {
                return json_encode(['success' => false, 'message' => 'User ID, user_type, leave_type_id, start_date, end_date, and reason are all required.']);
            }
            $allowedTypes = ['teacher', 'staff', 'student'];
            if (!in_array($userType, $allowedTypes, true)) {
                return json_encode(['success' => false, 'message' => 'Invalid user_type.']);
            }
            try {
                $checkUser = $schoolDb->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND is_active = 1");
                $checkUser->execute([$tgtUserId, (int)$school['id']]);
                if (!$checkUser->fetchColumn()) {
                    return json_encode(['success' => false, 'message' => 'Active user not found.']);
                }
                $stmt = $schoolDb->prepare("INSERT INTO leave_requests (school_id, user_id, user_type, leave_type_id, start_date, end_date, reason, status, applied_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
                $stmt->execute([(int)$school['id'], $tgtUserId, $userType, $leaveTypeId, $startDate, $endDate, $reason]);
                $requestId = $schoolDb->lastInsertId();
                return json_encode(['success' => true, 'message' => 'Leave request submitted (pending approval).', 'leave_request_id' => $requestId]);
            } catch (Throwable $e) {
                error_log('AI create_leave_request error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not submit leave request: ' . $e->getMessage()]);
            }
        }

        // ── approve_leave_request ──────────────────────────────────────
        case 'approve_leave_request': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            $leaveRequestId = (int)($args['leave_request_id'] ?? 0);
            $status = (string)($args['status'] ?? '');
            $rejectionReason = trim((string)($args['rejection_reason'] ?? ''));
            if ($leaveRequestId <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
                return json_encode(['success' => false, 'message' => 'A valid leave_request_id and status (approved/rejected) are required.']);
            }
            if ($status === 'rejected' && $rejectionReason === '') {
                return json_encode(['success' => false, 'message' => 'Rejection reason is required when rejecting a leave request.']);
            }
            try {
                $check = $schoolDb->prepare("SELECT id, status FROM leave_requests WHERE id = ? AND school_id = ?");
                $check->execute([$leaveRequestId, (int)$school['id']]);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return json_encode(['success' => false, 'message' => 'Leave request not found.']);
                }
                if ($row['status'] !== 'pending') {
                    return json_encode(['success' => false, 'message' => 'Only pending leave requests can be approved or rejected. Current status: ' . $row['status']]);
                }
                $stmt = $schoolDb->prepare("UPDATE leave_requests SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND school_id = ?");
                $stmt->execute([$status, $userId, $status === 'rejected' ? $rejectionReason : null, $leaveRequestId, (int)$school['id']]);
                return json_encode(['success' => true, 'message' => 'Leave request ' . ($status === 'approved' ? 'approved' : 'rejected') . '.']);
            } catch (Throwable $e) {
                error_log('AI approve_leave_request error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not process leave request: ' . $e->getMessage()]);
            }
        }

        case 'bulk_assign_campus': {
            try {
                $campusId = (int)($args['campus_id'] ?? 0);
                if ($campusId <= 0) {
                    return json_encode(['success' => false, 'message' => 'Invalid campus ID.']);
                }
                $stmt = $schoolDb->prepare("SELECT id FROM campuses WHERE id = ? AND school_id = ?");
                $stmt->execute([$campusId, (int)$school['id']]);
                if (!$stmt->fetch()) {
                    return json_encode(['success' => false, 'message' => 'Campus not found.']);
                }
                $updated = 0;
                if (!empty($args['class_id'])) {
                    $classId = (int)$args['class_id'];
                    $stmt = $schoolDb->prepare("UPDATE students SET campus_id = ? WHERE class_id = ? AND school_id = ?");
                    $stmt->execute([$campusId, $classId, (int)$school['id']]);
                    $updated = $stmt->rowCount();
                } elseif (!empty($args['student_ids']) && is_array($args['student_ids'])) {
                    $ids = array_map('intval', $args['student_ids']);
                    $ids = array_filter($ids, fn($v) => $v > 0);
                    if (empty($ids)) {
                        return json_encode(['success' => false, 'message' => 'No valid student IDs provided.']);
                    }
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $schoolDb->prepare("UPDATE students SET campus_id = ? WHERE id IN ($placeholders) AND school_id = ?");
                    $stmt->execute(array_merge([$campusId], $ids, [(int)$school['id']]));
                    $updated = $stmt->rowCount();
                } else {
                    return json_encode(['success' => false, 'message' => 'Provide either student_ids or class_id.']);
                }
                return json_encode(['success' => true, 'message' => "$updated student(s) assigned to campus successfully."]);
            } catch (Throwable $e) {
                error_log('AI bulk_assign_campus error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not assign campus: ' . $e->getMessage()]);
            }
        }

        // ── create_full_timetable ─────────────────────────────────────────────
        case 'create_full_timetable': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $classId    = (int)($args['class_id'] ?? 0);
            $sectionId  = (int)($args['section_id'] ?? 0);
            $periods    = $args['periods'] ?? [];
            $replace    = !empty($args['replace_existing']);

            if ($classId <= 0) return json_encode(['success' => false, 'message' => 'A valid class_id is required.']);
            if (empty($periods) || !is_array($periods)) return json_encode(['success' => false, 'message' => 'No periods provided. Please supply a "periods" array.']);

            try {
                // Resolve academic year
                $yearId = (int)($args['academic_year_id'] ?? 0);
                if ($yearId <= 0) {
                    $yearId = academix_ai_default_academic_year_id($schoolDb, (int)$school['id']);
                }

                // Resolve academic term (use latest term for the year)
                $termId = (int)($args['academic_term_id'] ?? 0);
                if ($termId <= 0 && academix_ai_table_exists($schoolDb, 'academic_terms')) {
                    $tStmt = $schoolDb->prepare(
                        'SELECT id FROM academic_terms WHERE school_id = ? AND academic_year_id = ? ORDER BY id DESC LIMIT 1'
                    );
                    $tStmt->execute([(int)$school['id'], $yearId]);
                    $termId = (int)($tStmt->fetchColumn() ?: 0);
                }
                if ($termId <= 0) $termId = 1; // fallback

                // Optionally wipe existing timetable for this class
                if ($replace) {
                    $delWhere = ['school_id = ?', 'class_id = ?'];
                    $delParams = [(int)$school['id'], $classId];
                    if ($sectionId > 0) { $delWhere[] = 'section_id = ?'; $delParams[] = $sectionId; }
                    $schoolDb->prepare('DELETE FROM timetables WHERE ' . implode(' AND ', $delWhere))->execute($delParams);
                }

                $validDays   = ['monday','tuesday','wednesday','thursday','friday','saturday'];
                $insertStmt  = $schoolDb->prepare(
                    'INSERT INTO timetables
                     (school_id, class_id, section_id, academic_year_id, academic_term_id,
                      day, period_number, start_time, end_time, subject_id, teacher_id, room_number, is_break, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );

                $inserted = 0;
                $skipped  = 0;
                $errors   = [];

                foreach ($periods as $p) {
                    $day      = strtolower(trim((string)($p['day'] ?? '')));
                    $periodNo = (int)($p['period_number'] ?? 0);
                    $start    = trim((string)($p['start_time'] ?? ''));
                    $end      = trim((string)($p['end_time'] ?? ''));
                    $isBreak  = !empty($p['is_break']);
                    $subId    = $isBreak ? 0 : (int)($p['subject_id'] ?? 0);
                    $teachId  = $isBreak ? 0 : (int)($p['teacher_id'] ?? 0);
                    $room     = trim((string)($p['room_number'] ?? ''));

                    if (!in_array($day, $validDays, true) || $periodNo <= 0 || $start === '' || $end === '') {
                        $skipped++;
                        $errors[] = "Skipped invalid period: day={$day} period={$periodNo}";
                        continue;
                    }
                    if (!$isBreak && ($subId <= 0 || $teachId <= 0)) {
                        $skipped++;
                        $errors[] = "Skipped period {$periodNo} on {$day}: subject_id and teacher_id required for non-break slots.";
                        continue;
                    }

                    $insertStmt->execute([
                        (int)$school['id'], $classId,
                        $sectionId > 0 ? $sectionId : null,
                        $yearId, $termId,
                        $day, $periodNo, $start, $end,
                        $subId, $teachId,
                        $room !== '' ? $room : null,
                        $isBreak ? 1 : 0,
                    ]);
                    $inserted++;
                }

                $summary = "Timetable created for class ID {$classId}: {$inserted} period(s) inserted.";
                if ($skipped > 0) $summary .= " {$skipped} period(s) skipped.";
                if (!empty($errors)) $summary .= ' Notes: ' . implode(' | ', array_slice($errors, 0, 5));

                return json_encode([
                    'success'  => $inserted > 0,
                    'message'  => $summary,
                    'inserted' => $inserted,
                    'skipped'  => $skipped,
                    'class_id' => $classId,
                ]);
            } catch (Throwable $e) {
                error_log('AI create_full_timetable error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not create timetable: ' . $e->getMessage()]);
            }
        }

        // ── generate_timetable_pdf ────────────────────────────────────────────
        case 'generate_timetable_pdf': {
            if (!$schoolDb) return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            $classId   = (int)($args['class_id'] ?? 0);
            $sectionId = (int)($args['section_id'] ?? 0);
            $className = trim((string)($args['class_name'] ?? ''));
            if ($classId <= 0) return json_encode(['success' => false, 'message' => 'A valid class_id is required.']);

            try {
                // Fetch the timetable data
                $where  = ['t.school_id = ?', 't.class_id = ?'];
                $params = [(int)$school['id'], $classId];
                if ($sectionId > 0) { $where[] = 't.section_id = ?'; $params[] = $sectionId; }

                $stmt = $schoolDb->prepare(
                    "SELECT t.day, t.period_number, t.start_time, t.end_time, t.room_number, t.is_break,
                            sub.name AS subject_name, sub.code AS subject_code,
                            u.name AS teacher_name,
                            c.name AS class_name,
                            sec.name AS section_name
                     FROM timetables t
                     LEFT JOIN subjects sub ON sub.id = t.subject_id
                     LEFT JOIN users u ON u.id = t.teacher_id
                     LEFT JOIN classes c ON c.id = t.class_id
                     LEFT JOIN sections sec ON sec.id = t.section_id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY FIELD(t.day,'monday','tuesday','wednesday','thursday','friday','saturday'), t.period_number"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (empty($rows)) {
                    return json_encode(['success' => false, 'message' => "No timetable found for class ID {$classId}. Please create the timetable first."]);
                }

                // Resolve class name from data if not supplied
                if ($className === '') {
                    $className = $rows[0]['class_name'] ?? "Class {$classId}";
                    if (!empty($rows[0]['section_name'])) $className .= ' — ' . $rows[0]['section_name'];
                }

                // Group by day
                $days = ['monday','tuesday','wednesday','thursday','friday','saturday'];
                $byDay = [];
                foreach ($rows as $r) {
                    $byDay[strtolower($r['day'])][] = $r;
                }
                // Remove days with no data
                $activeDays = array_filter($days, fn($d) => !empty($byDay[$d]));

                $schoolName  = htmlspecialchars($school['name'] ?? 'School', ENT_QUOTES);
                $classNameH  = htmlspecialchars($className, ENT_QUOTES);
                $generated   = date('F j, Y \a\t g:i A');

                // ── Build the HTML ───────────────────────────────────────────
                $dayLabels = [
                    'monday'    => 'Monday',
                    'tuesday'   => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday'  => 'Thursday',
                    'friday'    => 'Friday',
                    'saturday'  => 'Saturday',
                ];

                // Collect all unique period time slots for column headers
                $allSlots = [];
                foreach ($rows as $r) {
                    $key = str_pad((string)$r['period_number'], 2, '0', STR_PAD_LEFT);
                    $allSlots[$key] = [
                        'num'   => (int)$r['period_number'],
                        'start' => substr($r['start_time'], 0, 5),
                        'end'   => substr($r['end_time'], 0, 5),
                    ];
                }
                ksort($allSlots);
                $allSlots = array_values($allSlots);

                // Colour palette for subjects
                $colours = [
                    '#dbeafe','#fce7f3','#d1fae5','#fef3c7','#ede9fe','#fee2e2',
                    '#e0f2fe','#fef9c3','#f3e8ff','#ecfdf5','#fff7ed','#f0fdf4',
                ];
                $subjectColours = [];
                $colIdx = 0;

                ob_start();
                ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Timetable — <?php echo $classNameH; ?> | <?php echo $schoolName; ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f8fafc;color:#1e293b;font-size:13px;}
.page{max-width:1100px;margin:0 auto;padding:28px 24px 40px;}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;padding-bottom:18px;border-bottom:3px solid #7c3aed;}
.header-left h1{font-size:22px;font-weight:800;color:#7c3aed;margin-bottom:3px;}
.header-left h2{font-size:15px;font-weight:600;color:#334155;}
.header-left p{font-size:12px;color:#64748b;margin-top:4px;}
.header-right{text-align:right;}
.header-right .school{font-size:16px;font-weight:700;color:#1e293b;}
.header-right .date{font-size:11.5px;color:#64748b;margin-top:3px;}
.badge{display:inline-block;background:#7c3aed;color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;margin-top:6px;}
table{width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);}
thead tr{background:linear-gradient(135deg,#7c3aed,#06b6d4);}
thead th{padding:11px 10px;color:#fff;font-weight:700;font-size:12px;text-align:center;white-space:nowrap;}
thead th.day-col{text-align:left;padding-left:14px;min-width:100px;}
tbody tr{border-bottom:1px solid #e2e8f0;}
tbody tr:last-child{border-bottom:none;}
tbody tr:nth-child(even){background:#f8fafc;}
tbody td{padding:9px 8px;text-align:center;vertical-align:middle;}
tbody td.day-cell{text-align:left;padding-left:14px;font-weight:700;font-size:12.5px;color:#334155;background:#f1f5f9;white-space:nowrap;}
.period-cell{border-radius:8px;padding:8px 7px;min-height:52px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;}
.period-cell .subject{font-weight:700;font-size:12px;color:#1e293b;line-height:1.3;}
.period-cell .teacher{font-size:10.5px;color:#475569;}
.period-cell .room{font-size:10px;color:#94a3b8;}
.break-cell{background:#f1f5f9 !important;color:#94a3b8;font-size:11px;font-weight:600;letter-spacing:.04em;border-radius:8px;padding:8px 4px;}
.empty-cell{color:#cbd5e1;font-size:11px;}
.footer{margin-top:20px;display:flex;justify-content:space-between;align-items:center;color:#94a3b8;font-size:11px;border-top:1px solid #e2e8f0;padding-top:12px;}
.legend{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
.legend-item{display:flex;align-items:center;gap:5px;font-size:11.5px;color:#334155;}
.legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;}
@media print{
  body{background:#fff;}
  .page{padding:10px;}
  table{box-shadow:none;}
  .no-print{display:none;}
}
.print-btn{position:fixed;bottom:24px;right:24px;background:#7c3aed;color:#fff;border:none;padding:12px 22px;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(124,58,237,.3);display:flex;align-items:center;gap:8px;}
.print-btn:hover{background:#6d28d9;}
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <div class="header-left">
      <h1>📅 Class Timetable</h1>
      <h2><?php echo $classNameH; ?></h2>
      <p>Academic Schedule — All Days</p>
    </div>
    <div class="header-right">
      <div class="school"><?php echo $schoolName; ?></div>
      <div class="date">Generated: <?php echo $generated; ?></div>
      <span class="badge">AcademiX Suite</span>
    </div>
  </div>

<?php
                // Legend
                $legendItems = [];
                foreach ($rows as $r) {
                    if (!$r['is_break'] && !empty($r['subject_name'])) {
                        $subKey = $r['subject_name'];
                        if (!isset($subjectColours[$subKey])) {
                            $subjectColours[$subKey] = $colours[$colIdx % count($colours)];
                            $colIdx++;
                        }
                        $legendItems[$subKey] = $subjectColours[$subKey];
                    }
                }
                if (!empty($legendItems)): ?>
  <div class="legend">
<?php foreach ($legendItems as $subName => $colour): ?>
    <div class="legend-item">
      <div class="legend-dot" style="background:<?php echo $colour; ?>;border:1px solid #e2e8f0;"></div>
      <?php echo htmlspecialchars($subName, ENT_QUOTES); ?>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>

  <table>
    <thead>
      <tr>
        <th class="day-col">Day</th>
<?php foreach ($allSlots as $slot): ?>
        <th>P<?php echo $slot['num']; ?><br><span style="font-weight:400;font-size:10.5px;"><?php echo $slot['start']; ?>–<?php echo $slot['end']; ?></span></th>
<?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($activeDays as $day):
    $dayPeriods = [];
    foreach ($byDay[$day] as $r) {
        $dayPeriods[$r['period_number']] = $r;
    }
    ?>
      <tr>
        <td class="day-cell"><?php echo $dayLabels[$day]; ?></td>
<?php foreach ($allSlots as $slot):
    $p = $dayPeriods[$slot['num']] ?? null;
    if ($p === null): ?>
        <td><div class="empty-cell">—</div></td>
<?php elseif ($p['is_break']): ?>
        <td><div class="break-cell">☕ BREAK</div></td>
<?php else:
    $subKey = $p['subject_name'] ?? '';
    $bg     = $subjectColours[$subKey] ?? '#f1f5f9';
    ?>
        <td>
          <div class="period-cell" style="background:<?php echo $bg; ?>;">
            <span class="subject"><?php echo htmlspecialchars($p['subject_name'] ?? '', ENT_QUOTES); ?></span>
            <?php if (!empty($p['teacher_name'])): ?>
            <span class="teacher">👤 <?php echo htmlspecialchars($p['teacher_name'], ENT_QUOTES); ?></span>
            <?php endif; ?>
            <?php if (!empty($p['room_number'])): ?>
            <span class="room">🏫 <?php echo htmlspecialchars($p['room_number'], ENT_QUOTES); ?></span>
            <?php endif; ?>
          </div>
        </td>
<?php endif; endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <div class="footer">
    <span><?php echo $schoolName; ?> &mdash; <?php echo $classNameH; ?> Timetable</span>
    <span>Printed from AcademiX Suite &bull; <?php echo $generated; ?></span>
  </div>
</div>

<button class="print-btn no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
<script>
  // Auto-trigger browser print dialog for seamless PDF save experience
  window.addEventListener('load', function() {
    // Small delay so the page renders fully first
    setTimeout(function() {
      document.querySelector('.print-btn') && document.querySelector('.print-btn').focus();
    }, 400);
  });
</script>
</body>
</html>
<?php
                $html = ob_get_clean();

                // ── Save to uploads folder ───────────────────────────────────
                $root    = dirname(__DIR__, 3);
                $schoolId = (int)$school['id'];
                $dirRel  = 'assets/uploads/ai_exports/' . $schoolId;
                $dirAbs  = $root . '/' . $dirRel;
                if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);

                $safeClass = preg_replace('/[^a-z0-9_-]+/i', '-', $className) ?: 'class';
                $filename  = 'timetable-' . strtolower($safeClass) . '-' . date('Ymd-His') . '.html';
                $filePath  = $dirAbs . '/' . $filename;
                file_put_contents($filePath, $html);

                $downloadUrl = '/' . $dirRel . '/' . $filename;

                return json_encode([
                    '__type'    => 'timetable_pdf',
                    'success'   => true,
                    'message'   => "Timetable document for {$className} is ready. Open the link below, then click the 🖨️ Print / Save as PDF button in the page to download your PDF.",
                    'url'       => $downloadUrl,
                    'filename'  => $filename,
                    'class'     => $className,
                    'periods'   => count($rows),
                    'days'      => count($activeDays),
                ]);
            } catch (Throwable $e) {
                error_log('AI generate_timetable_pdf error: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not generate timetable document: ' . $e->getMessage()]);
            }
        }

        default:
            return json_encode(['error' => "Unknown tool: {$toolName}"]);
    }
};

// ── Run DeepSeek ───────────────────────────────────────────────────────────────
$apiKey = $_ENV['DEEPSEEK_API_KEY'] ?? getenv('DEEPSEEK_API_KEY') ?? '';
$model  = $_ENV['DEEPSEEK_MODEL']   ?? getenv('DEEPSEEK_MODEL')   ?? 'deepseek-v4-flash';

if (empty($apiKey) || $apiKey === 'sk-your-key-here') {
    echo json_encode([
        'success' => false,
        'message' => 'DeepSeek API key not configured. Please add DEEPSEEK_API_KEY to your .env file.',
    ]);
    exit;
}

try {
    $deepseek = new DeepSeekClient($apiKey, $model);
    $result = $deepseek->run($messages, $tools, $toolExecutor, 4, 700);

    $isError = strncmp((string)($result['reply'] ?? ''), 'AI error: ', 10) === 0;
    if ($schoolDb && $aiChatMemoryReady) {
        academix_ai_store_chat_message($schoolDb, (int)$school['id'], $userId, 'assistant', (string)($result['reply'] ?? ''), [
            'success' => !$isError,
            'tool_calls_made' => $result['tool_calls_made'] ?? [],
        ]);
    }

    echo json_encode([
        'success'         => !$isError,
        'reply'           => $result['reply'],
        'message'         => $isError ? $result['reply'] : '',
        'tool_calls_made' => $result['tool_calls_made'],
        'csrf_token'      => $_SESSION['ai_csrf_token'] ?? '',
    ]);
} catch (Throwable $e) {
    error_log('AI assistant fatal: ' . $e->getMessage());
    if ($schoolDb && $aiChatMemoryReady) {
        academix_ai_store_chat_message($schoolDb, (int)$school['id'], $userId, 'assistant', 'AI service error: ' . $e->getMessage(), ['success' => false]);
    }
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . $e->getMessage()]);
}
