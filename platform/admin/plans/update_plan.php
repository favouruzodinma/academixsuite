<?php
// update_plan.php
require_once __DIR__ . '/../../../includes/autoload.php';

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get database connection
$db = Database::getPlatformConnection();

// Get POST data
$planId = $_POST['plan_id'] ?? null;
$name = trim($_POST['name'] ?? '');
$slug = trim($_POST['slug'] ?? '');
$description = trim($_POST['description'] ?? '');
$priceMonthly = floatval($_POST['price_monthly'] ?? 0);
$priceYearly = floatval($_POST['price_yearly'] ?? 0);
$studentLimit = intval($_POST['student_limit'] ?? 50);
$teacherLimit = intval($_POST['teacher_limit'] ?? 10);
$campusLimit = intval($_POST['campus_limit'] ?? 1);
$storageLimit = intval($_POST['storage_limit'] ?? 1024);
$features = $_POST['features'] ?? '[]';
$sortOrder = intval($_POST['sort_order'] ?? 0);
$isDefault = intval($_POST['is_default'] ?? 0);
$isActive = intval($_POST['is_active'] ?? 1);

// Validate required fields
if (empty($name) || empty($slug) || empty($description)) {
    echo json_encode(['success' => false, 'message' => 'Name, slug, and description are required']);
    exit;
}

// Validate features JSON
$featuresArray = json_decode($features, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid features JSON format']);
    exit;
}

// Check if slug already exists (excluding current plan for updates)
$slugCheckQuery = "SELECT id FROM plans WHERE slug = ?";
$params = [$slug];

if ($planId) {
    $slugCheckQuery .= " AND id != ?";
    $params[] = $planId;
}

$stmt = $db->prepare($slugCheckQuery);
$stmt->execute($params);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => false, 'message' => 'Plan slug already exists']);
    exit;
}

// If setting as default, remove default from other plans
if ($isDefault == 1) {
    try {
        $db->query("UPDATE plans SET is_default = 0 WHERE is_default = 1");
    } catch (Exception $e) {
        error_log("Error updating default plans: " . $e->getMessage());
    }
}

try {
    if ($planId) {
        // Update existing plan
        $stmt = $db->prepare("
            UPDATE plans SET 
                name = ?,
                slug = ?,
                description = ?,
                price_monthly = ?,
                price_yearly = ?,
                student_limit = ?,
                teacher_limit = ?,
                campus_limit = ?,
                storage_limit = ?,
                features = ?,
                is_active = ?,
                is_default = ?,
                sort_order = ?,
                created_at = created_at
            WHERE id = ?
        ");
        
        $stmt->execute([
            $name,
            $slug,
            $description,
            $priceMonthly,
            $priceYearly,
            $studentLimit,
            $teacherLimit,
            $campusLimit,
            $storageLimit,
            $features,
            $isActive,
            $isDefault,
            $sortOrder,
            $planId
        ]);
        
        $message = 'Plan updated successfully';
    } else {
        // Insert new plan
        $stmt = $db->prepare("
            INSERT INTO plans (
                name, slug, description, price_monthly, price_yearly, 
                student_limit, teacher_limit, campus_limit, storage_limit, features, 
                is_active, is_default, sort_order, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $name,
            $slug,
            $description,
            $priceMonthly,
            $priceYearly,
            $studentLimit,
            $teacherLimit,
            $campusLimit,
            $storageLimit,
            $features,
            $isActive,
            $isDefault,
            $sortOrder
        ]);
        
        $planId = $db->lastInsertId();
        $message = 'Plan created successfully';
    }
    
    // Log the action
    logAudit(
        $_SESSION['super_admin']['id'],
        'plan_' . ($planId ? 'updated' : 'created'),
        'plans',
        $planId,
        [
            'name' => $name,
            'slug' => $slug,
            'price_monthly' => $priceMonthly
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'plan_id' => $planId
    ]);
    
} catch (Exception $e) {
    error_log("Error saving plan: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save plan. Please try again.'
    ]);
}

/**
 * Log audit trail
 */
function logAudit($userId, $event, $auditableType, $auditableId, $data = []) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                user_id, user_type, event, auditable_type, auditable_id,
                new_values, url, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            'super_admin',
            $event,
            $auditableType,
            $auditableId,
            json_encode($data),
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Error logging audit: " . $e->getMessage());
    }
}