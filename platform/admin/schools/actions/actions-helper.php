<?php
// actions-helper.php or add to the top of each action file
require_once __DIR__ . '/../../../../includes/autoload.php';

session_start();

function validateCsrfToken() {
    if (!isset($_SERVER['HTTP_CONTENT_TYPE']) || 
        strpos($_SERVER['HTTP_CONTENT_TYPE'], 'application/json') === false) {
        die(json_encode(['success' => false, 'message' => 'Invalid content type']));
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['csrf_token']) || 
        !isset($_SESSION['csrf_token']) || 
        $input['csrf_token'] !== $_SESSION['csrf_token']) {
        die(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
    }
    
    return $input;
}