<?php
// Start session and load required files
require_once __DIR__ . '/../../../includes/autoload.php';

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

// Get super admin data
$superAdmin = $_SESSION['super_admin'];

// Get database connection
$db = Database::getPlatformConnection();

// Fetch plans from database
$plans = [];
$planStats = [];
$exchange_rate = 1500; // USD to NGN conversion rate

try {
    // Fetch all plans
    $stmt = $db->query("SELECT * FROM plans ORDER BY sort_order, price_monthly ASC");
    $plans = $stmt->fetchAll();
    
    // Get statistics for each plan
    foreach ($plans as $plan) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM schools WHERE plan_id = ? AND status IN ('active', 'trial')");
        $stmt->execute([$plan['id']]);
        $result = $stmt->fetch();
        $planStats[$plan['id']] = $result['count'];
    }
    
    // Get total ARR (Annual Recurring Revenue)
    $stmt = $db->query("
        SELECT SUM(p.price_monthly * 12) as total_arr 
        FROM schools s 
        JOIN plans p ON s.plan_id = p.id 
        WHERE s.status IN ('active', 'trial')
    ");
    $result = $stmt->fetch();
    $totalArr = $result['total_arr'] ?? 0;
    $totalArrNgn = $totalArr * $exchange_rate;
    
    // Get total active plans count
    $activePlansCount = count($plans);
    
    // Get total schools
    $stmt = $db->query("SELECT COUNT(*) as total_schools FROM schools WHERE status IN ('active', 'trial')");
    $result = $stmt->fetch();
    $totalSchools = $result['total_schools'] ?? 0;
    
} catch (Exception $e) {
    error_log("Failed to fetch plans: " . $e->getMessage());
    $plans = [];
    $planStats = [];
    $totalArrNgn = 0;
    $activePlansCount = 0;
    $totalSchools = 0;
}

// Function to parse JSON features from database
function parseFeatures($featuresJson) {
    if (empty($featuresJson)) {
        return [];
    }
    
    try {
        $features = json_decode($featuresJson, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($features)) {
            return $features;
        }
    } catch (Exception $e) {
        error_log("Error parsing features JSON: " . $e->getMessage());
    }
    
    // Fallback: try to parse as plain text
    if (is_string($featuresJson)) {
        return array_filter(array_map('trim', explode(',', $featuresJson)));
    }
    
    return [];
}

// Function to get plan icon based on name
function getPlanIcon($planName) {
    $name = strtolower($planName);
    if (strpos($name, 'starter') !== false || strpos($name, 'basic') !== false) return 'fas fa-rocket';
    if (strpos($name, 'growth') !== false || strpos($name, 'pro') !== false || strpos($name, 'district') !== false) return 'fas fa-chart-line';
    if (strpos($name, 'multi-campus') !== false || strpos($name, 'multi campus') !== false) return 'fas fa-school-circle-check';
    if (strpos($name, 'enterprise') !== false || strpos($name, 'premium') !== false) return 'fas fa-university';
    return 'fas fa-layer-group';
}

// Function to get plan color class based on name
function getPlanColorClass($planName) {
    $name = strtolower($planName);
    if (strpos($name, 'starter') !== false || strpos($name, 'basic') !== false) return 'bg-gradient-to-br from-blue-50 to-blue-100 border-blue-200';
    if (strpos($name, 'growth') !== false || strpos($name, 'pro') !== false || strpos($name, 'district') !== false) return 'bg-gradient-to-br from-emerald-50 to-emerald-100 border-emerald-200';
    if (strpos($name, 'multi-campus') !== false || strpos($name, 'multi campus') !== false) return 'bg-gradient-to-br from-purple-50 to-purple-100 border-purple-200';
    if (strpos($name, 'enterprise') !== false || strpos($name, 'premium') !== false) return 'bg-gradient-to-br from-slate-900 to-slate-800 border-slate-700';
    return 'bg-gradient-to-br from-slate-50 to-slate-100 border-slate-200';
}

// Function to get plan button class based on name
function getPlanButtonClass($planName) {
    $name = strtolower($planName);
    if (strpos($name, 'starter') !== false || strpos($name, 'basic') !== false) return 'bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800';
    if (strpos($name, 'growth') !== false || strpos($name, 'pro') !== false || strpos($name, 'district') !== false) return 'bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800';
    if (strpos($name, 'multi-campus') !== false || strpos($name, 'multi campus') !== false) return 'bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800';
    if (strpos($name, 'enterprise') !== false || strpos($name, 'premium') !== false) return 'bg-gradient-to-r from-slate-800 to-slate-900 hover:from-slate-900 hover:to-slate-800';
    return 'bg-gradient-to-r from-slate-600 to-slate-700 hover:from-slate-700 hover:to-slate-800';
}

// Function to get plan badge
function getPlanBadge($plan) {
    if ($plan['is_default']) {
        return ['text' => 'Recommended', 'class' => 'bg-gradient-to-r from-blue-500 to-blue-600'];
    }
    
    $priceMonthly = (float)$plan['price_monthly'];
    if ($priceMonthly >= 199) {
        return ['text' => 'Premium', 'class' => 'bg-gradient-to-r from-purple-500 to-purple-600'];
    } elseif ($priceMonthly >= 50) {
        return ['text' => 'Popular', 'class' => 'bg-gradient-to-r from-emerald-500 to-emerald-600'];
    }
    
    return ['text' => 'Starter', 'class' => 'bg-gradient-to-r from-blue-400 to-blue-500'];
}

// Function to format storage limit
function formatStorage($mb) {
    if ($mb >= 1024) {
        return round($mb / 1024, 1) . ' GB';
    }
    return $mb . ' MB';
}

// Function to get plan status color
function getPlanStatusColor($isActive) {
    return $isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Plans | AcademixSuite Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Responsive container */
        .responsive-container {
            width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        
        @media (min-width: 640px) {
            .responsive-container {
                max-width: 640px;
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }
        }
        
        @media (min-width: 768px) {
            .responsive-container {
                max-width: 768px;
            }
        }
        
        @media (min-width: 1024px) {
            .responsive-container {
                max-width: 1024px;
                padding-left: 2rem;
                padding-right: 2rem;
            }
        }
        
        @media (min-width: 1280px) {
            .responsive-container {
                max-width: 1280px;
            }
        }
        
        /* Header styles */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 40;
            padding: 1rem 0;
        }
        
        @media (min-width: 768px) {
            .header {
                padding: 1.25rem 0;
            }
        }
        
        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        @media (min-width: 640px) {
            .stats-grid {
                gap: 1rem;
            }
        }
        
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.25rem;
                margin-bottom: 2rem;
            }
        }
        
        /* Stats card */
        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1rem;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
        }
        
        @media (min-width: 768px) {
            .stat-card {
                padding: 1.25rem;
            }
        }
        
        /* Pricing cards grid */
        .pricing-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media (min-width: 768px) {
            .pricing-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 2rem;
            }
        }
        
        @media (min-width: 1024px) {
            .pricing-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        /* Pricing card */
        .pricing-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.1);
            border-color: #3b82f6;
        }
        
        .pricing-card.featured {
            border: 2px solid #3b82f6;
            box-shadow: 0 20px 25px -5px rgb(59 130 246 / 0.1);
        }
        
        .pricing-card.featured::before {
            content: 'RECOMMENDED';
            position: absolute;
            top: 12px;
            right: -32px;
            background: #3b82f6;
            color: white;
            padding: 0.25rem 2rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
            transform: rotate(45deg);
            z-index: 10;
            letter-spacing: 0.05em;
        }
        
        .pricing-content {
            padding: 1.5rem;
            flex: 1;
        }
        
        @media (min-width: 768px) {
            .pricing-content {
                padding: 2rem;
            }
        }
        
        /* Table container */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -1rem;
            padding: 0 1rem;
        }
        
        @media (min-width: 768px) {
            .table-container {
                margin: 0;
                padding: 0;
            }
        }
        
        .responsive-table {
            width: 100%;
            min-width: 768px;
            border-collapse: collapse;
        }
        
        .responsive-table th,
        .responsive-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        @media (min-width: 768px) {
            .responsive-table th,
            .responsive-table td {
                padding: 1rem 1.5rem;
            }
        }
        
        /* Feature list */
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .feature-list li {
            position: relative;
            padding-left: 1.75rem;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        
        .feature-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            width: 1.25rem;
            height: 1.25rem;
            background: #d1fae5;
            color: #059669;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        @media (min-width: 768px) {
            .feature-list li {
                padding-left: 2rem;
                font-size: 0.875rem;
            }
            
            .feature-list li::before {
                width: 1.5rem;
                height: 1.5rem;
                font-size: 0.875rem;
            }
        }
        
        /* Modal */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
            padding: 1rem;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 1rem;
            width: 100%;
            max-width: 32rem;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        @media (min-width: 768px) {
            .modal-content {
                max-width: 42rem;
            }
        }
        
        /* Form inputs */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.625rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        @media (min-width: 768px) {
            .form-label {
                font-size: 0.875rem;
            }
            
            .form-input {
                padding: 0.75rem 1rem;
                font-size: 1rem;
            }
        }
        
        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 3.75rem;
            height: 2rem;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 2rem;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 1.5rem;
            width: 1.5rem;
            left: 0.25rem;
            bottom: 0.25rem;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #3b82f6;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(1.75rem);
        }
        
        /* Button styles */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            min-height: 2.75rem;
        }
        
        @media (min-width: 768px) {
            .btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.875rem;
            }
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }
        
        .btn-icon {
            width: 2.5rem;
            height: 2.5rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 1rem;
            right: 1rem;
            background: white;
            border-radius: 0.75rem;
            padding: 1rem 1.5rem;
            box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 50;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .notification.success {
            border-left: 4px solid #10b981;
        }
        
        .notification.error {
            border-left: 4px solid #ef4444;
        }
        
        .notification.info {
            border-left: 4px solid #3b82f6;
        }
        
        /* Mobile menu button */
        .mobile-menu-btn {
            display: block;
        }
        
        @media (min-width: 1024px) {
            .mobile-menu-btn {
                display: none;
            }
        }
        
        /* Typography */
        .text-xs {
            font-size: 0.75rem;
            line-height: 1rem;
        }
        
        .text-sm {
            font-size: 0.875rem;
            line-height: 1.25rem;
        }
        
        .text-base {
            font-size: 1rem;
            line-height: 1.5rem;
        }
        
        .text-lg {
            font-size: 1.125rem;
            line-height: 1.75rem;
        }
        
        .text-xl {
            font-size: 1.25rem;
            line-height: 1.75rem;
        }
        
        .text-2xl {
            font-size: 1.5rem;
            line-height: 2rem;
        }
        
        .text-3xl {
            font-size: 1.875rem;
            line-height: 2.25rem;
        }
        
        @media (min-width: 768px) {
            .text-xl {
                font-size: 1.5rem;
                line-height: 2rem;
            }
            
            .text-2xl {
                font-size: 1.875rem;
                line-height: 2.5rem;
            }
            
            .text-3xl {
                font-size: 2.25rem;
                line-height: 2.5rem;
            }
        }
        
        /* Utility classes */
        .font-semibold {
            font-weight: 600;
        }
        
        .font-bold {
            font-weight: 700;
        }
        
        .font-extrabold {
            font-weight: 800;
        }
        
        .text-slate-900 {
            color: #0f172a;
        }
        
        .text-slate-700 {
            color: #334155;
        }
        
        .text-slate-600 {
            color: #475569;
        }
        
        .text-slate-500 {
            color: #64748b;
        }
        
        .text-slate-400 {
            color: #94a3b8;
        }
        
        .text-blue-600 {
            color: #2563eb;
        }
        
        .text-emerald-600 {
            color: #059669;
        }
        
        .text-purple-600 {
            color: #7c3aed;
        }
        
        .bg-white {
            background-color: white;
        }
        
        .bg-slate-50 {
            background-color: #f8fafc;
        }
        
        .bg-slate-100 {
            background-color: #f1f5f9;
        }
        
        .rounded-lg {
            border-radius: 0.5rem;
        }
        
        .rounded-xl {
            border-radius: 0.75rem;
        }
        
        .rounded-2xl {
            border-radius: 1rem;
        }
        
        .shadow-sm {
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }
        
        .shadow {
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        
        .shadow-lg {
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        }
        
        .border {
            border-width: 1px;
        }
        
        .border-slate-200 {
            border-color: #e2e8f0;
        }
        
        .border-slate-300 {
            border-color: #cbd5e1;
        }
        
        .gap-2 {
            gap: 0.5rem;
        }
        
        .gap-3 {
            gap: 0.75rem;
        }
        
        .gap-4 {
            gap: 1rem;
        }
        
        .gap-6 {
            gap: 1.5rem;
        }
        
        .gap-8 {
            gap: 2rem;
        }
        
        .p-4 {
            padding: 1rem;
        }
        
        .p-6 {
            padding: 1.5rem;
        }
        
        .p-8 {
            padding: 2rem;
        }
        
        .px-4 {
            padding-left: 1rem;
            padding-right: 1rem;
        }
        
        .py-3 {
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        
        .py-4 {
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem;
        }
        
        .mb-3 {
            margin-bottom: 0.75rem;
        }
        
        .mb-4 {
            margin-bottom: 1rem;
        }
        
        .mb-6 {
            margin-bottom: 1.5rem;
        }
        
        .mb-8 {
            margin-bottom: 2rem;
        }
        
        .mt-2 {
            margin-top: 0.5rem;
        }
        
        .mt-4 {
            margin-top: 1rem;
        }
        
        .mt-6 {
            margin-top: 1.5rem;
        }
        
        .mt-8 {
            margin-top: 2rem;
        }
        
        .flex {
            display: flex;
        }
        
        .flex-col {
            flex-direction: column;
        }
        
        .items-center {
            align-items: center;
        }
        
        .justify-between {
            justify-content: space-between;
        }
        
        .justify-center {
            justify-content: center;
        }
        
        .hidden {
            display: none;
        }
        
        @media (min-width: 640px) {
            .sm\:flex {
                display: flex;
            }
            
            .sm\:hidden {
                display: none;
            }
        }
        
        @media (min-width: 768px) {
            .md\:flex {
                display: flex;
            }
            
            .md\:hidden {
                display: none;
            }
            
            .md\:grid-cols-2 {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .md\:grid-cols-3 {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .lg\:hidden {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Notification Container -->
    <div id="notificationContainer"></div>
    
    <!-- Edit Plan Modal -->
    <div id="editPlanModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-slate-900" id="modalTitle">Add New Plan</h3>
                    <button onclick="closeModal('editPlanModal')" class="btn-icon text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="editPlanForm" method="POST" action="update_plan.php" onsubmit="return submitPlanForm(event)">
                    <input type="hidden" name="plan_id" id="editPlanId" value="">
                    
                    <div class="space-y-4">
                        <div class="grid md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label">Plan Name *</label>
                                <input type="text" id="editPlanName" name="name" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Plan Slug *</label>
                                <input type="text" id="editPlanSlug" name="slug" class="form-input" required>
                                <p class="text-xs text-slate-500 mt-1">URL-friendly version (e.g., enterprise-plan)</p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Description *</label>
                            <textarea id="editPlanDescription" name="description" class="form-input" rows="2" required></textarea>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label">Monthly Price ($) *</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-500">$</span>
                                    <input type="number" id="editPlanPrice" name="price_monthly" class="form-input pl-8" step="0.01" min="0" required>
                                </div>
                                <div class="text-xs text-slate-500 mt-1" id="priceNgn"></div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Yearly Price ($)</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-500">$</span>
                                    <input type="number" id="editYearlyPrice" name="price_yearly" class="form-input pl-8" step="0.01" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-3 gap-4">
                            <div class="form-group">
                                <label class="form-label">Student Limit *</label>
                                <input type="number" id="editStudentLimit" name="student_limit" class="form-input" min="0" value="50" required>
                                <p class="text-xs text-slate-500 mt-1">0 = Unlimited</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Teacher Limit *</label>
                                <input type="number" id="editTeacherLimit" name="teacher_limit" class="form-input" min="0" value="10" required>
                                <p class="text-xs text-slate-500 mt-1">0 = Unlimited</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Storage Limit (MB) *</label>
                                <input type="number" id="editStorageLimit" name="storage_limit" class="form-input" min="0" value="1024" required>
                                <p class="text-xs text-slate-500 mt-1">Storage in megabytes</p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Features (JSON Array) *</label>
                            <textarea id="editPlanFeatures" name="features" class="form-input" rows="4" required>["Student Management", "Attendance Tracking", "Basic Reports", "Email Support"]</textarea>
                            <p class="text-xs text-slate-500 mt-1">Enter features as a JSON array. Example: ["Feature 1", "Feature 2"]</p>
                        </div>
                        
                        <div class="grid md:grid-cols-3 gap-4">
                            <div class="form-group">
                                <label class="form-label">Sort Order *</label>
                                <input type="number" id="editSortOrder" name="sort_order" class="form-input" min="0" value="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Default Plan</label>
                                <select id="editIsDefault" name="is_default" class="form-input">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status *</label>
                                <select id="editPlanStatus" name="is_active" class="form-input" required>
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row gap-3 mt-8 pt-6 border-t border-slate-200">
                        <button type="button" onclick="closeModal('editPlanModal')" class="btn btn-secondary flex-1">
                            Cancel
                        </button>
                        <button type="submit" id="submitPlanBtn" class="btn btn-primary flex-1">
                            <i class="fas fa-save mr-2"></i>
                            Save Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Plan Modal -->
    <div id="deletePlanModal" class="modal">
        <div class="modal-content">
            <div class="p-8 text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-900 mb-2">Delete Plan?</h3>
                <p class="text-sm text-slate-600 mb-4">Are you sure you want to delete the "<span id="deletePlanName" class="font-bold"></span>" plan?</p>
                <p class="text-xs text-slate-500 mb-6">This action cannot be undone. Schools using this plan will need to select a new plan.</p>
                <div class="space-y-3">
                    <button onclick="confirmDeletePlan()" class="btn btn-danger w-full">
                        <i class="fas fa-trash mr-2"></i>
                        Yes, Delete Plan
                    </button>
                    <button onclick="closeModal('deletePlanModal')" class="btn btn-secondary w-full">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="flex min-h-screen">
        <?php include '../filepath/sidebar.php'; ?>
        
        <main class="flex-1 lg:ml-64 flex flex-col min-w-0 overflow-hidden">
            <!-- Header -->
            <header class="header">
                <div class="responsive-container">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <button onclick="toggleMobileSidebar()" class="mobile-menu-btn btn-icon text-slate-600 hover:text-slate-900">
                                <i class="fas fa-bars"></i>
                            </button>
                            <div>
                                <h1 class="text-xl font-bold text-slate-900">Subscription Plans</h1>
                                <p class="text-sm text-slate-500">Manage pricing tiers and features</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <div class="md:flex items-center gap-4 bg-white border border-slate-200 px-4 py-2 rounded-xl hidden">
                                <div class="text-center">
                                    <p class="text-xs font-semibold text-slate-400 uppercase">Total ARR</p>
                                    <p class="text-sm font-bold text-slate-900">₦<?php echo number_format($totalArrNgn, 0); ?></p>
                                </div>
                                <div class="h-8 w-px bg-slate-200"></div>
                                <div class="text-center">
                                    <p class="text-xs font-semibold text-slate-400 uppercase">Schools</p>
                                    <p class="text-sm font-bold text-emerald-600"><?php echo $totalSchools; ?></p>
                                </div>
                            </div>
                            
                            <button onclick="createNewPlan()" class="btn btn-primary">
                                <i class="fas fa-plus mr-2"></i>
                                <span class="sm:inline hidden">New Plan</span>
                                <span class="sm:hidden">Add</span>
                            </button>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Main Content -->
            <div class="responsive-container py-6">
                <!-- Stats Overview -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                                <i class="fas fa-layer-group text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Total Plans</p>
                                <p class="text-2xl font-bold text-slate-900"><?php echo $activePlansCount; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-school text-emerald-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Active Schools</p>
                                <p class="text-2xl font-bold text-slate-900"><?php echo $totalSchools; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-50 to-purple-100 flex items-center justify-center">
                                <i class="fas fa-chart-line text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Monthly MRR</p>
                                <p class="text-2xl font-bold text-slate-900">₦<?php echo number_format($totalArrNgn / 12, 0); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-trophy text-amber-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Most Popular</p>
                                <p class="text-2xl font-bold text-slate-900">
                                    <?php 
                                    $mostPopular = '';
                                    $maxCount = 0;
                                    foreach ($planStats as $planId => $count) {
                                        if ($count > $maxCount) {
                                            $maxCount = $count;
                                            foreach ($plans as $plan) {
                                                if ($plan['id'] == $planId) {
                                                    $mostPopular = $plan['name'];
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    echo $mostPopular ?: 'N/A';
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Billing Toggle -->
                <div class="bg-white rounded-xl p-6 text-center shadow-sm mb-8">
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Choose Billing Cycle</h3>
                    <p class="text-sm text-slate-500 mb-6">Select your preferred billing frequency</p>
                    
                    <div class="flex items-center justify-center gap-6 mb-6">
                        <span class="text-sm font-semibold <?php echo !isset($_GET['billing']) || $_GET['billing'] === 'monthly' ? 'text-blue-600' : 'text-slate-500'; ?>">Monthly</span>
                        <label class="toggle-switch">
                            <input type="checkbox" id="billingToggle" onchange="toggleBillingCycle()">
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="text-sm font-semibold <?php echo isset($_GET['billing']) && $_GET['billing'] === 'yearly' ? 'text-blue-600' : 'text-slate-500'; ?>">
                            Yearly <span class="text-emerald-600">(Save 20%)</span>
                        </span>
                    </div>
                </div>
                
                <!-- Pricing Cards -->
                <div class="mb-8">
                    <?php if (empty($plans)): ?>
                        <div class="text-center py-12">
                            <div class="w-20 h-20 bg-gradient-to-br from-blue-50 to-blue-100 rounded-full flex items-center justify-center mx-auto mb-6">
                                <i class="fas fa-layer-group text-3xl text-blue-600"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-slate-900 mb-3">No Subscription Plans Yet</h3>
                            <p class="text-sm text-slate-600 mb-6 max-w-md mx-auto">Create your first subscription plan to start offering services to schools</p>
                            <button onclick="createNewPlan()" class="btn btn-primary">
                                <i class="fas fa-plus mr-2"></i>
                                Create First Plan
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="pricing-grid">
                            <?php foreach ($plans as $plan): 
                                $planId = $plan['id'];
                                $isFeatured = $plan['is_default'];
                                $schoolCount = $planStats[$planId] ?? 0;
                                $planBadge = getPlanBadge($plan);
                                $planFeatures = parseFeatures($plan['features']);
                                $monthlyPriceNgn = $plan['price_monthly'] * $exchange_rate;
                                $yearlyPriceNgn = $plan['price_yearly'] ? $plan['price_yearly'] * $exchange_rate : $monthlyPriceNgn * 12 * 0.8;
                            ?>
                            <div class="pricing-card <?php echo $isFeatured ? 'featured' : ''; ?>" style="position: relative;">
                                <div class="pricing-content">
                                    <div class="flex items-center justify-between mb-6">
                                        <div class="w-14 h-14 rounded-2xl <?php echo getPlanColorClass($plan['name']); ?> flex items-center justify-center border">
                                            <i class="<?php echo getPlanIcon($plan['name']); ?> text-xl"></i>
                                        </div>
                                        <span class="px-3 py-1 text-xs font-bold rounded-full <?php echo $planBadge['class']; ?> text-white">
                                            <?php echo $planBadge['text']; ?>
                                        </span>
                                    </div>
                                    
                                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="text-sm text-slate-600 mb-6"><?php echo htmlspecialchars($plan['description']); ?></p>
                                    
                                    <div class="mb-6">
                                        <div class="flex items-baseline mb-1">
                                            <span class="text-3xl font-bold text-slate-900 price-display" 
                                                  data-monthly="<?php echo $monthlyPriceNgn; ?>" 
                                                  data-yearly="<?php echo $yearlyPriceNgn; ?>">
                                                ₦<?php echo number_format($monthlyPriceNgn, 0); ?>
                                            </span>
                                            <span class="text-sm text-slate-500 font-medium ml-2 billing-period">/month</span>
                                        </div>
                                        <p class="text-xs text-slate-500 billing-yearly">
                                            ₦<?php echo number_format($yearlyPriceNgn, 0); ?> billed yearly
                                        </p>
                                    </div>
                                    
                                    <div class="space-y-3 mb-6">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-slate-600">Students</span>
                                            <span class="font-bold text-slate-900">
                                                <?php echo $plan['student_limit'] == 0 ? 'Unlimited' : number_format($plan['student_limit']); ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-slate-600">Teachers</span>
                                            <span class="font-bold text-slate-900">
                                                <?php echo $plan['teacher_limit'] == 0 ? 'Unlimited' : number_format($plan['teacher_limit']); ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-slate-600">Storage</span>
                                            <span class="font-bold text-slate-900"><?php echo formatStorage($plan['storage_limit']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-6">
                                        <h4 class="text-xs font-bold text-slate-900 mb-3">KEY FEATURES</h4>
                                        <ul class="feature-list">
                                            <?php 
                                            $featureCount = 0;
                                            foreach ($planFeatures as $feature): 
                                                $featureCount++;
                                                if ($featureCount > 5) break;
                                            ?>
                                            <li class="text-sm text-slate-700">
                                                <?php echo htmlspecialchars($feature); ?>
                                            </li>
                                            <?php endforeach; ?>
                                            <?php if (count($planFeatures) > 5): ?>
                                            <li class="text-sm text-blue-600 font-medium">
                                                + <?php echo count($planFeatures) - 5; ?> more features
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="p-6 pt-0 mt-auto">
                                    <div class="space-y-4">
                                        <button onclick="selectPlan(<?php echo $planId; ?>)" 
                                                class="btn <?php echo getPlanButtonClass($plan['name']); ?> text-white w-full">
                                            <i class="fas fa-check-circle mr-2"></i>
                                            Select Plan
                                        </button>
                                        
                                        <div class="text-center">
                                            <p class="text-xs text-slate-500">
                                                <span class="font-bold text-slate-900"><?php echo $schoolCount; ?></span> schools using this plan
                                            </p>
                                            <div class="flex items-center justify-center gap-4 mt-3">
                                                <button onclick="editPlan(<?php echo $planId; ?>)" 
                                                        class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                                    <i class="fas fa-edit mr-1"></i>Edit
                                                </button>
                                                <button onclick="duplicatePlan(<?php echo $planId; ?>)" 
                                                        class="text-xs text-emerald-600 hover:text-emerald-800 font-medium">
                                                    <i class="fas fa-copy mr-1"></i>Duplicate
                                                </button>
                                                <button onclick="deletePlan(<?php echo $planId; ?>, '<?php echo htmlspecialchars($plan['name']); ?>')" 
                                                        class="text-xs text-red-600 hover:text-red-800 font-medium">
                                                    <i class="fas fa-trash mr-1"></i>Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <!-- Add New Plan Card -->
                            <div class="pricing-card border-2 border-dashed border-slate-300 hover:border-blue-400">
                                <div class="h-full flex flex-col items-center justify-center p-8 text-center">
                                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center mx-auto mb-6">
                                        <i class="fas fa-plus text-blue-600 text-3xl"></i>
                                    </div>
                                    <h3 class="text-xl font-bold text-slate-900 mb-3">Create Custom Plan</h3>
                                    <p class="text-sm text-slate-600 mb-6">Design a custom subscription plan for specific needs</p>
                                    <button onclick="createNewPlan()" class="btn btn-primary">
                                        <i class="fas fa-plus mr-2"></i>
                                        Create New Plan
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Plan Management Table -->
                <div class="bg-white rounded-xl overflow-hidden shadow-sm">
                    <div class="p-6 border-b border-slate-200">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                            <div>
                                <h3 class="text-lg font-bold text-slate-900">Plan Management</h3>
                                <p class="text-sm text-slate-500">Manage and configure all subscription plans</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="exportPlans()" class="btn btn-secondary">
                                    <i class="fas fa-file-export mr-2"></i>
                                    <span class="sm:inline hidden">Export</span>
                                </button>
                                <button onclick="createNewPlan()" class="btn btn-primary">
                                    <i class="fas fa-plus mr-2"></i>
                                    <span class="sm:inline hidden">Add Plan</span>
                                    <span class="sm:hidden">Add</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <table class="responsive-table">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="text-xs font-bold text-slate-500 uppercase py-3">Plan Details</th>
                                    <th class="text-xs font-bold text-slate-500 uppercase py-3">Pricing</th>
                                    <th class="text-xs font-bold text-slate-500 uppercase py-3">Limits</th>
                                    <th class="text-xs font-bold text-slate-500 uppercase py-3">Status</th>
                                    <th class="text-xs font-bold text-slate-500 uppercase py-3">Schools</th>
                                    <th class="text-xs font-bold text-slate-500 uppercase py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($plans)): ?>
                                    <tr>
                                        <td colspan="6" class="py-8 text-center">
                                            <i class="fas fa-layer-group text-3xl text-slate-300 mb-3"></i>
                                            <p class="text-sm text-slate-500">No subscription plans created yet</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($plans as $plan): 
                                        $planId = $plan['id'];
                                        $schoolCount = $planStats[$planId] ?? 0;
                                        $isActive = $plan['is_active'];
                                        $isDefault = $plan['is_default'];
                                    ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 rounded-xl <?php echo getPlanColorClass($plan['name']); ?> flex items-center justify-center flex-shrink-0 border">
                                                    <i class="<?php echo getPlanIcon($plan['name']); ?>"></i>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="text-sm font-bold text-slate-900 truncate"><?php echo htmlspecialchars($plan['name']); ?></div>
                                                    <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($plan['slug']); ?></div>
                                                    <div class="text-xs text-slate-400 truncate md:block hidden"><?php echo htmlspecialchars(substr($plan['description'], 0, 50) . (strlen($plan['description']) > 50 ? '...' : '')); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4">
                                            <div class="text-sm font-bold text-slate-900">
                                                $<?php echo number_format($plan['price_monthly'], 2); ?>
                                                <span class="text-xs text-slate-500 font-normal">/month</span>
                                            </div>
                                            <div class="text-xs text-slate-600">
                                                ₦<?php echo number_format($plan['price_monthly'] * $exchange_rate, 0); ?> NGN
                                            </div>
                                        </td>
                                        <td class="py-4">
                                            <div class="space-y-1 text-xs">
                                                <div>
                                                    <span class="text-slate-600">Students:</span>
                                                    <span class="font-bold text-slate-900 ml-1">
                                                        <?php echo $plan['student_limit'] == 0 ? '∞' : number_format($plan['student_limit']); ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <span class="text-slate-600">Teachers:</span>
                                                    <span class="font-bold text-slate-900 ml-1">
                                                        <?php echo $plan['teacher_limit'] == 0 ? '∞' : number_format($plan['teacher_limit']); ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <span class="text-slate-600">Storage:</span>
                                                    <span class="font-bold text-slate-900 ml-1"><?php echo formatStorage($plan['storage_limit']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4">
                                            <div class="flex flex-col gap-1">
                                                <span class="px-2 py-1 text-xs font-bold rounded-full <?php echo getPlanStatusColor($isActive); ?> w-fit">
                                                    <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                                </span>
                                                <?php if ($isDefault): ?>
                                                <span class="px-2 py-1 text-xs font-bold rounded-full bg-gradient-to-r from-blue-100 to-blue-200 text-blue-800 w-fit">
                                                    Default
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-4">
                                            <div class="text-2xl font-bold text-slate-900"><?php echo $schoolCount; ?></div>
                                            <div class="text-xs text-slate-500">active schools</div>
                                        </td>
                                        <td class="py-4">
                                            <div class="flex items-center gap-1">
                                                <button onclick="editPlan(<?php echo $planId; ?>)" 
                                                        class="btn-icon bg-white border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200"
                                                        title="Edit Plan">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="duplicatePlan(<?php echo $planId; ?>)" 
                                                        class="btn-icon bg-white border border-slate-200 text-slate-400 hover:text-emerald-600 hover:border-emerald-200"
                                                        title="Duplicate Plan">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <button onclick="deletePlan(<?php echo $planId; ?>, '<?php echo htmlspecialchars($plan['name']); ?>')" 
                                                        class="btn-icon bg-white border border-slate-200 text-slate-400 hover:text-red-600 hover:border-red-200"
                                                        title="Delete Plan">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Global variables
        let isYearlyBilling = false;
        const exchangeRate = <?php echo $exchange_rate; ?>;
        
        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize billing toggle from URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const billingParam = urlParams.get('billing');
            if (billingParam === 'yearly') {
                isYearlyBilling = true;
                const toggle = document.getElementById('billingToggle');
                if (toggle) toggle.checked = true;
                updatePricingDisplay();
            }
            
            // Initialize price conversion listener
            const priceInput = document.getElementById('editPlanPrice');
            if (priceInput) {
                priceInput.addEventListener('input', updatePriceConversion);
            }
            
            // Initialize slug generation
            const nameInput = document.getElementById('editPlanName');
            const slugInput = document.getElementById('editPlanSlug');
            if (nameInput && slugInput) {
                nameInput.addEventListener('input', function() {
                    if (!slugInput.value || slugInput.value.endsWith('-copy')) {
                        const slug = this.value
                            .toLowerCase()
                            .replace(/[^a-z0-9\s-]/g, '')
                            .replace(/\s+/g, '-')
                            .replace(/-+/g, '-')
                            .trim();
                        slugInput.value = slug;
                    }
                });
            }
            
            // Close modals with escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllModals();
                }
            });
            
            // Close modal when clicking outside
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal(this.id);
                    }
                });
            });
        });
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const sidebar = document.querySelector('aside');
            if (sidebar) {
                sidebar.classList.toggle('mobile-nav-active');
                document.body.style.overflow = sidebar.classList.contains('mobile-nav-active') ? 'hidden' : '';
            }
        }
        
        // Billing toggle
        function toggleBillingCycle() {
            isYearlyBilling = !isYearlyBilling;
            updatePricingDisplay();
        }
        
        function updatePricingDisplay() {
            const priceElements = document.querySelectorAll('.price-display');
            const periodElements = document.querySelectorAll('.billing-period');
            const yearlyElements = document.querySelectorAll('.billing-yearly');
            
            priceElements.forEach(element => {
                const monthlyPrice = parseFloat(element.getAttribute('data-monthly'));
                const yearlyPrice = parseFloat(element.getAttribute('data-yearly'));
                
                if (isYearlyBilling) {
                    const monthlyEquivalent = yearlyPrice / 12;
                    element.textContent = '₦' + Math.floor(monthlyEquivalent).toLocaleString();
                    periodElements.forEach(p => p.textContent = '/month');
                    yearlyElements.forEach(y => y.style.display = 'block');
                } else {
                    element.textContent = '₦' + Math.floor(monthlyPrice).toLocaleString();
                    periodElements.forEach(p => p.textContent = '/month');
                    yearlyElements.forEach(y => y.style.display = 'block');
                }
            });
        }
        
        // Price conversion helper
        function updatePriceConversion() {
            const usdPrice = document.getElementById('editPlanPrice')?.value;
            const priceNgn = document.getElementById('priceNgn');
            
            if (usdPrice && priceNgn) {
                const ngnPrice = usdPrice * exchangeRate;
                const yearlyPrice = ngnPrice * 12 * 0.8;
                priceNgn.innerHTML = `
                    <div>₦${ngnPrice.toLocaleString()} NGN per month</div>
                    <div class="text-emerald-600">₦${yearlyPrice.toLocaleString()} NGN per year (Save 20%)</div>
                `;
            }
        }
        
        // Plan management functions
        function createNewPlan() {
            const form = document.getElementById('editPlanForm');
            if (form) {
                form.reset();
                document.getElementById('editPlanId').value = '';
                document.getElementById('modalTitle').textContent = 'Add New Plan';
                document.getElementById('submitPlanBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Plan';
                document.getElementById('editPlanStatus').value = '1';
                document.getElementById('editIsDefault').value = '0';
                document.getElementById('editSortOrder').value = '0';
                document.getElementById('editPlanFeatures').value = '["Student Management", "Attendance Tracking", "Basic Reports", "Email Support"]';
                
                openModal('editPlanModal');
                updatePriceConversion();
            }
        }
        
        async function editPlan(planId) {
            try {
                const response = await fetch(`get_plan.php?id=${planId}`);
                const plan = await response.json();
                
                if (plan) {
                    populateEditForm(plan);
                    openModal('editPlanModal');
                }
            } catch (error) {
                console.error('Error fetching plan:', error);
                showNotification('Failed to load plan data', 'error');
            }
        }
        
        function populateEditForm(plan) {
            document.getElementById('editPlanId').value = plan.id;
            document.getElementById('editPlanName').value = plan.name;
            document.getElementById('editPlanSlug').value = plan.slug;
            document.getElementById('editPlanDescription').value = plan.description;
            document.getElementById('editPlanPrice').value = plan.price_monthly;
            document.getElementById('editYearlyPrice').value = plan.price_yearly;
            document.getElementById('editStudentLimit').value = plan.student_limit;
            document.getElementById('editTeacherLimit').value = plan.teacher_limit;
            document.getElementById('editStorageLimit').value = plan.storage_limit;
            document.getElementById('editSortOrder').value = plan.sort_order;
            document.getElementById('editIsDefault').value = plan.is_default ? '1' : '0';
            document.getElementById('editPlanStatus').value = plan.is_active ? '1' : '0';
            
            let features = plan.features;
            if (typeof features === 'string') {
                try {
                    features = JSON.parse(features);
                } catch (e) {
                    features = [features];
                }
            }
            document.getElementById('editPlanFeatures').value = JSON.stringify(features, null, 2);
            
            document.getElementById('modalTitle').textContent = 'Edit Plan';
            document.getElementById('submitPlanBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update Plan';
        }
        
        async function duplicatePlan(planId) {
            try {
                const response = await fetch(`get_plan.php?id=${planId}`);
                const plan = await response.json();
                
                if (plan) {
                    document.getElementById('editPlanId').value = '';
                    document.getElementById('editPlanName').value = plan.name + ' (Copy)';
                    document.getElementById('editPlanSlug').value = plan.slug + '-copy';
                    document.getElementById('editPlanDescription').value = plan.description;
                    document.getElementById('editPlanPrice').value = plan.price_monthly;
                    document.getElementById('editYearlyPrice').value = plan.price_yearly;
                    document.getElementById('editStudentLimit').value = plan.student_limit;
                    document.getElementById('editTeacherLimit').value = plan.teacher_limit;
                    document.getElementById('editStorageLimit').value = plan.storage_limit;
                    document.getElementById('editSortOrder').value = (plan.sort_order || 0) + 1;
                    document.getElementById('editIsDefault').value = '0';
                    document.getElementById('editPlanStatus').value = '1';
                    
                    let features = plan.features;
                    if (typeof features === 'string') {
                        try {
                            features = JSON.parse(features);
                        } catch (e) {
                            features = [features];
                        }
                    }
                    document.getElementById('editPlanFeatures').value = JSON.stringify(features, null, 2);
                    
                    document.getElementById('modalTitle').textContent = 'Duplicate Plan';
                    document.getElementById('submitPlanBtn').innerHTML = '<i class="fas fa-copy mr-2"></i>Save Duplicate';
                    
                    openModal('editPlanModal');
                    updatePriceConversion();
                }
            } catch (error) {
                console.error('Error duplicating plan:', error);
                showNotification('Failed to duplicate plan', 'error');
            }
        }
        
        function deletePlan(planId, planName) {
            document.getElementById('deletePlanName').textContent = planName;
            document.getElementById('deletePlanModal').setAttribute('data-plan-id', planId);
            openModal('deletePlanModal');
        }
        
        async function confirmDeletePlan() {
            const planId = document.getElementById('deletePlanModal').getAttribute('data-plan-id');
            
            try {
                const response = await fetch('delete_plan.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ plan_id: planId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(result.message, 'error');
                    closeModal('deletePlanModal');
                }
            } catch (error) {
                console.error('Error deleting plan:', error);
                showNotification('Failed to delete plan', 'error');
                closeModal('deletePlanModal');
            }
        }
        
        // Form submission
        async function submitPlanForm(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const submitBtn = document.getElementById('submitPlanBtn');
            const originalContent = submitBtn.innerHTML;
            
            // Validate JSON features
            try {
                JSON.parse(document.getElementById('editPlanFeatures').value);
            } catch (e) {
                showNotification('Invalid features JSON format', 'error');
                return false;
            }
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch('update_plan.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(result.message, 'error');
                    submitBtn.innerHTML = originalContent;
                    submitBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error saving plan:', error);
                showNotification('Failed to save plan', 'error');
                submitBtn.innerHTML = originalContent;
                submitBtn.disabled = false;
            }
        }
        
        // Plan selection
        function selectPlan(planId) {
            showNotification(`Selecting plan #${planId}...`, 'info');
        }
        
        // Export function
        function exportPlans() {
            const csvContent = "data:text/csv;charset=utf-8,Plan Name,Monthly Price,Yearly Price,Student Limit,Teacher Limit,Storage,Status,Schools\n" +
                <?php foreach ($plans as $plan): ?>
                "<?php echo htmlspecialchars($plan['name']); ?>,$<?php echo $plan['price_monthly']; ?>,$<?php echo $plan['price_yearly']; ?>,<?php echo $plan['student_limit']; ?>,<?php echo $plan['teacher_limit']; ?>,<?php echo formatStorage($plan['storage_limit']); ?>,<?php echo $plan['is_active'] ? 'Active' : 'Inactive'; ?>,<?php echo $planStats[$plan['id']] ?? 0; ?>\n" +
                <?php endforeach; ?>
                "";
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "plans_export_<?php echo date('Y-m-d'); ?>.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification('Plans data exported successfully', 'success');
        }
        
        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.classList.remove('active');
            });
            document.body.style.overflow = '';
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            
            const colors = {
                success: 'success',
                error: 'error',
                info: 'info'
            };
            
            const icons = {
                success: 'check-circle',
                error: 'exclamation-circle',
                info: 'info-circle'
            };
            
            notification.className = `notification ${colors[type]}`;
            notification.innerHTML = `
                <i class="fas fa-${icons[type]}"></i>
                <span class="text-sm font-medium">${message}</span>
            `;
            
            container.appendChild(notification);
            
            // Auto remove after 4 seconds
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 4000);
        }
        
        // Window resize handler
        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('aside');
            if (window.innerWidth >= 1024 && sidebar) {
                sidebar.classList.remove('mobile-nav-active');
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>