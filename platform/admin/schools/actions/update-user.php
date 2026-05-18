<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    http_response_code(415);
    echo json_encode(['success' => false, 'message' => 'Expected JSON request']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$csrfToken = $data['csrf_token'] ?? '';
if (empty($csrfToken) || !isset($_SESSION['csrf_tokens'][$csrfToken]) || $_SESSION['csrf_tokens'][$csrfToken] < time()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}

$schoolId = (int) ($data['school_id'] ?? 0);
$userId = (int) ($data['user_id'] ?? 0);

if ($schoolId <= 0 || $userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school or user ID']);
    exit;
}

try {
    $platformDb = Database::getPlatformConnection();
    $stmt = $platformDb->prepare("SELECT id, name, database_name FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();

    if (!$school || empty($school['database_name'])) {
        echo json_encode(['success' => false, 'message' => 'School not found or database missing']);
        exit;
    }

    $schoolDb = Database::getSchoolConnection($school['database_name']);
    $columns = $schoolDb->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    $allowed = ['name', 'email', 'phone', 'username', 'user_type', 'is_active', 'address', 'gender'];
    $updates = [];
    $params = [];

    foreach ($allowed as $column) {
        if (array_key_exists($column, $data) && in_array($column, $columns, true)) {
            $value = $data[$column];
            if ($column === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address']);
                exit;
            }
            if ($column === 'user_type') {
                $validTypes = ['admin', 'teacher', 'student', 'parent', 'accountant', 'librarian'];
                if (!in_array($value, $validTypes, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user type']);
                    exit;
                }
            }
            if ($column === 'is_active') {
                $value = (int) (bool) $value;
            }
            $updates[] = "`$column` = ?";
            $params[] = $value === '' ? null : $value;
        }
    }

    if (!empty($data['password']) && in_array('password', $columns, true)) {
        if (strlen($data['password']) < PASSWORD_MIN_LENGTH) {
            echo json_encode(['success' => false, 'message' => 'Password is too short']);
            exit;
        }
        $updates[] = '`password` = ?';
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }

    if (!$updates) {
        echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
        exit;
    }

    if (in_array('updated_at', $columns, true)) {
        $updates[] = 'updated_at = NOW()';
    }

    $params[] = $userId;
    $where = 'id = ?';
    if (in_array('school_id', $columns, true)) {
        $where .= ' AND school_id = ?';
        $params[] = $schoolId;
    }

    $stmt = $schoolDb->prepare('UPDATE users SET ' . implode(', ', $updates) . " WHERE $where");
    $stmt->execute($params);

    $logStmt = $platformDb->prepare("
        INSERT INTO platform_audit_logs (school_id, event, description, user_type, created_at)
        VALUES (?, 'school_user_updated', ?, 'super_admin', NOW())
    ");
    $logStmt->execute([$schoolId, "Updated user #{$userId} in {$school['name']}"]);

    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
} catch (Exception $e) {
    error_log('update-user error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to update user']);
}
