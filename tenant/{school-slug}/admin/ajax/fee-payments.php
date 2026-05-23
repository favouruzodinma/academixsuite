<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../../logs/ajax_fee_payments.log');

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('academix_tenant');
        $sessionConfig = __DIR__ . '/../../../../includes/session_config.php';
        if (is_file($sessionConfig)) {
            require_once $sessionConfig;
            session_start(academix_session_options());
        } else {
            session_start();
        }
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

header('Content-Type: application/json');

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$campusId = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Student ID required']);
    exit;
}

$schoolAuth = $_SESSION['school_auth'] ?? [];
if (!is_array($schoolAuth) || empty($schoolAuth['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $autoloadPath = __DIR__ . '/../../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) throw new Exception("Autoload file not found");
    require_once $autoloadPath;
    if (!class_exists('Database')) throw new Exception("Database class not found");
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Configuration loading failed']);
    exit;
}

$schoolSlug = (string)($schoolAuth['school_slug'] ?? '');
$schoolId = (int)($schoolAuth['school_id'] ?? 0);
$databaseName = (string)($schoolAuth['database_name'] ?? ($_SESSION['school_info'][$schoolSlug]['database_name'] ?? ''));

if ($databaseName === '') {
    try {
        $platformDb = Database::getPlatformConnection();
        $stmt = $platformDb->prepare('SELECT database_name FROM schools WHERE id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$schoolId, $schoolSlug]);
        $databaseName = (string)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("ERROR: Could not load school database name: " . $e->getMessage());
    }
}

if ($databaseName === '') {
    echo json_encode(['success' => false, 'message' => 'School database not configured']);
    exit;
}

try {
    $schoolDb = Database::getSchoolConnection($databaseName);
    if (!$schoolDb) throw new Exception("Could not connect to school database");

    $columnExists = static function (PDO $db, string $table, string $column): bool {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Column check failed for {$table}.{$column}: " . $e->getMessage());
            return false;
        }
    };

    $campusFilter = '';
    $params = [$studentId, $schoolId];
    if ($campusId > 0 && $columnExists($schoolDb, 'fee_payments', 'campus_id')) {
        $campusFilter = ' AND fp.campus_id = ?';
        $params[] = $campusId;
    }

    $stmt = $schoolDb->prepare("
        SELECT fp.id, fp.amount, fp.discount_amount, fp.payment_method, fp.reference, fp.notes, fp.paid_at, fp.created_at,
               ft.name AS fee_type_name
        FROM fee_payments fp
        LEFT JOIN fee_types ft ON ft.id = fp.fee_type_id AND ft.school_id = fp.school_id
        WHERE fp.student_id = ? AND fp.school_id = ? {$campusFilter}
        ORDER BY fp.created_at DESC
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($payments as &$p) {
        $p['amount'] = (float)$p['amount'];
        $p['discount_amount'] = (float)($p['discount_amount'] ?? 0);
    }
    unset($p);

    echo json_encode(['success' => true, 'payments' => $payments]);
} catch (Exception $e) {
    error_log("Error fetching fee payments: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
