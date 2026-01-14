<?php
require_once '../../../includes/autoload.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate request
    if (empty($input['school_id']) || empty($input['amount']) || empty($input['email'])) {
        throw new \Exception('Missing required parameters');
    }
    
    $paymentService = new AcademixSuite\Services\PaymentService($input['school_id']);
    
    $result = $paymentService->initializePayment([
        'type' => $input['type'] ?? 'general',
        'amount' => $input['amount'],
        'email' => $input['email'],
        'metadata' => $input['metadata'] ?? []
    ]);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}