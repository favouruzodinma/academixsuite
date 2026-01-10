<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
    exit;
}

$planId = (int)$_GET['id'];
$db = Database::getPlatformConnection();

try {
    // Make sure the query includes campus_limit
    $stmt = $db->prepare("SELECT *, campus_limit FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    
    if ($plan) {
        // Parse features if it's a JSON string
        if (isset($plan['features']) && is_string($plan['features'])) {
            try {
                $features = json_decode($plan['features'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $plan['features'] = $features;
                }
            } catch (Exception $e) {
                // Keep as is if parsing fails
            }
        }
        
        // Ensure campus_limit has a default value if null
        if ($plan['campus_limit'] === null) {
            $plan['campus_limit'] = 1;
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'plan' => $plan
        ]);
    } else {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['success' => false, 'message' => 'Plan not found']);
    }
} catch (Exception $e) {
    error_log("Error fetching plan: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>