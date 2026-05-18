<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/api_crud.log');

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost && strcasecmp($originHost, preg_replace('/:\d+$/', '', $host)) === 0) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/autoload.php';

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function jsonError($message, $status = 400) {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

function authenticateRequest() {
    if (!isset($_SESSION['school_auth'])) {
        jsonError('Authentication required', 401);
    }
    $schoolSlug = $_GET['school_slug'] ?? $_POST['school_slug'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($schoolSlug)) $schoolSlug = $input['school_slug'] ?? '';
    if (empty($schoolSlug) && function_exists('school_subdomain_slug')) $schoolSlug = school_subdomain_slug() ?? '';
    if (empty($schoolSlug)) jsonError('School slug required', 400);

    $auth = $_SESSION['school_auth'];
    if (($auth['school_slug'] ?? '') !== $schoolSlug) {
        jsonError('Unauthorized', 403);
    }
    if (($auth['user_type'] ?? '') !== 'admin') {
        jsonError('Admin privileges required', 403);
    }
    $info = $_SESSION['school_info'][$schoolSlug] ?? [];
    if (empty($info)) jsonError('School not found', 404);

    return [
        'school_id' => $info['id'] ?? 0,
        'school_slug' => $schoolSlug,
        'school_info' => $info,
        'user_id' => $auth['user_id'] ?? 0,
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $schoolSlug = $_GET['school_slug'] ?? $_POST['school_slug'] ?? '';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($schoolSlug)) $schoolSlug = $input['school_slug'] ?? '';
    if (empty($schoolSlug) && function_exists('school_subdomain_slug')) $schoolSlug = school_subdomain_slug() ?? '';
    if (empty($action)) $action = $input['action'] ?? '';

    if (empty($schoolSlug)) jsonError('School slug required', 400);
    if (empty($action)) jsonError('Action required', 400);

    $auth = authenticateRequest();
    $db = Database::getSchoolConnection($auth['school_info']['database_name']);
    require_once __DIR__ . '/../../includes/Services/CrudHandler.php';
    $crud = new \AcademixSuite\Services\CrudHandler($db, $auth['school_id']);

    switch ($action) {
        case 'list':
            $table = $input['table'] ?? $_GET['table'] ?? '';
            if (empty($table)) jsonError('Table parameter required');
            $params = array_merge($_GET, $input);
            jsonResponse($crud->listAll($table, $params));
            break;

        case 'get':
            $table = $input['table'] ?? $_GET['table'] ?? '';
            $id = $input['id'] ?? $_GET['id'] ?? '';
            if (empty($table) || empty($id)) jsonError('Table and id required');
            $result = $crud->get($table, $id);
            if (!$result) jsonError('Record not found', 404);
            jsonResponse($result);
            break;

        case 'create':
            $table = $input['table'] ?? '';
            $data = $input['data'] ?? [];
            if (empty($table)) jsonError('Table required');
            jsonResponse($crud->create($table, $data));
            break;

        case 'update':
            $table = $input['table'] ?? '';
            $id = $input['id'] ?? '';
            $data = $input['data'] ?? [];
            if (empty($table) || empty($id)) jsonError('Table and id required');
            jsonResponse($crud->update($table, $id, $data));
            break;

        case 'delete':
            $table = $input['table'] ?? '';
            $id = $input['id'] ?? '';
            if (empty($table) || empty($id)) jsonError('Table and id required');
            jsonResponse($crud->delete($table, $id));
            break;

        case 'schema':
            $table = $input['table'] ?? $_GET['table'] ?? '';
            if (empty($table)) jsonError('Table parameter required');
            jsonResponse([
                'success' => true,
                'schema' => $crud->getSchema($table),
                'related' => $crud->getRelatedData($table),
            ]);
            break;

        case 'tables':
            $tables = $crud->getTables();
            $result = [];
            foreach ($tables as $t) {
                $result[] = [
                    'name' => $t,
                    'label' => $crud->getDisplayInfo($t),
                ];
            }
            jsonResponse(['success' => true, 'tables' => $result]);
            break;

        default:
            jsonError('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    error_log("CRUD API Error: " . $e->getMessage());
    jsonError('Internal error: ' . $e->getMessage(), 500);
}
