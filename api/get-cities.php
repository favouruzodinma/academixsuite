<?php
require_once __DIR__ . '/../includes/autoload.php';

header('Content-Type: application/json');

$state = $_GET['state'] ?? '';

if (empty($state)) {
    echo json_encode([]);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    $stmt = $db->prepare("
        SELECT DISTINCT city 
        FROM schools 
        WHERE city IS NOT NULL AND city != '' 
        AND state = ? 
        AND status IN ('active', 'trial')
        ORDER BY city
    ");
    $stmt->execute([$state]);
    $cities = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    echo json_encode($cities);
} catch (Exception $e) {
    echo json_encode([]);
}
?>