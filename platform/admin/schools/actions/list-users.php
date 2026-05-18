<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$schoolId = isset($_GET['school_id']) ? (int) $_GET['school_id'] : 0;
$type = $_GET['type'] ?? 'all';
$search = trim($_GET['search'] ?? '');

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
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

    $select = [
        'id',
        in_array('name', $columns, true) ? 'name' : "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) AS name",
        in_array('email', $columns, true) ? 'email' : "'' AS email",
        in_array('phone', $columns, true) ? 'phone' : "'' AS phone",
        in_array('user_type', $columns, true) ? 'user_type' : "'user' AS user_type",
        in_array('is_active', $columns, true) ? 'is_active' : '1 AS is_active',
        in_array('last_login_at', $columns, true) ? 'last_login_at' : 'NULL AS last_login_at',
        in_array('created_at', $columns, true) ? 'created_at' : 'NULL AS created_at',
    ];

    $where = [];
    $params = [];

    if (in_array('school_id', $columns, true)) {
        $where[] = 'school_id = ?';
        $params[] = $schoolId;
    }

    if ($type !== 'all' && in_array('user_type', $columns, true)) {
        $allowedTypes = ['admin', 'teacher', 'student', 'parent', 'accountant', 'librarian'];
        if (in_array($type, $allowedTypes, true)) {
            $where[] = 'user_type = ?';
            $params[] = $type;
        }
    }

    if ($search !== '') {
        $searchParts = [];
        foreach (['name', 'email', 'phone', 'username', 'admission_number', 'staff_id'] as $column) {
            if (in_array($column, $columns, true)) {
                $searchParts[] = "$column LIKE ?";
                $params[] = '%' . $search . '%';
            }
        }
        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM users';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT 500';

    $stmt = $schoolDb->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'school' => ['id' => $school['id'], 'name' => $school['name']],
        'users' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Exception $e) {
    error_log('list-users error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load users']);
}
