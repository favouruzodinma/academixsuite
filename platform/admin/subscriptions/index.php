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

// Define exchange rate
$exchange_rate = 1500; // USD to NGN conversion rate

// Initialize variables
$subscriptions = [];
$stats = [];
$activeFilter = 'all';
$searchTerm = '';
$currentTab = 'all';

// Handle search and filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $searchTerm = $_GET['search'] ?? '';
    $activeFilter = $_GET['filter'] ?? 'all';
    $currentTab = $_GET['tab'] ?? 'all';
}

try {
    // Build query based on filters
    $query = "
        SELECT 
            s.*,
            p.name as plan_name,
            p.price_monthly,
            p.price_yearly,
            sub.status as subscription_status,
            sub.billing_cycle,
            sub.current_period_start,
            sub.current_period_end,
            sub.amount as subscription_amount,
            sub.currency as subscription_currency,
            (SELECT COUNT(*) FROM school_admins sa WHERE sa.school_id = s.id) as admin_count
        FROM schools s
        LEFT JOIN plans p ON s.plan_id = p.id
        LEFT JOIN subscriptions sub ON s.id = sub.school_id AND sub.status = 'active'
        WHERE 1=1
    ";

    $params = [];
    
    // Apply search filter
    if (!empty($searchTerm)) {
        $query .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.slug LIKE ?)";
        $searchParam = "%{$searchTerm}%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }
    
    // Apply status filter
    switch ($activeFilter) {
        case 'active':
            $query .= " AND s.status = 'active'";
            break;
        case 'expiring':
            $query .= " AND s.status = 'active' 
                       AND sub.current_period_end IS NOT NULL 
                       AND sub.current_period_end <= DATE_ADD(NOW(), INTERVAL 30 DAY)
                       AND sub.current_period_end >= NOW()";
            break;
        case 'expired':
            $query .= " AND s.status = 'active' 
                       AND sub.current_period_end IS NOT NULL 
                       AND sub.current_period_end < NOW()";
            break;
        case 'canceled':
            $query .= " AND s.status = 'cancelled'";
            break;
        case 'trial':
            $query .= " AND s.status = 'trial'";
            break;
        // 'all' includes all schools
    }
    
    // Apply tab-specific filters
    switch ($currentTab) {
        case 'expiring':
            $query .= " AND s.status = 'active' 
                       AND sub.current_period_end IS NOT NULL 
                       AND sub.current_period_end <= DATE_ADD(NOW(), INTERVAL 30 DAY)
                       AND sub.current_period_end >= NOW()";
            break;
        case 'expired':
            $query .= " AND (s.status = 'active' AND sub.current_period_end < NOW()) 
                       OR s.status = 'suspended'";
            break;
        case 'renewals':
            $query .= " AND s.status = 'active' 
                       AND sub.current_period_end IS NOT NULL
                       AND sub.current_period_start >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        // 'all' and 'analytics' tabs show all
    }
    
    $query .= " ORDER BY s.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll();
    
    // Calculate statistics
    $stats = calculateSubscriptionStats($db);
    
    // Get expiring soon subscriptions for countdown
    $expiringQuery = "
        SELECT 
            COUNT(*) as count,
            SUM(p.price_monthly * $exchange_rate) as total_value
        FROM schools s
        JOIN subscriptions sub ON s.id = sub.school_id
        JOIN plans p ON s.plan_id = p.id
        WHERE s.status = 'active'
        AND sub.status = 'active'
        AND sub.current_period_end <= DATE_ADD(NOW(), INTERVAL 30 DAY)
        AND sub.current_period_end >= NOW()
    ";
    
    $expiringResult = $db->query($expiringQuery)->fetch();
    $expiringCount = $expiringResult['count'] ?? 0;
    $expiringValue = $expiringResult['total_value'] ?? 0;
    
} catch (Exception $e) {
    error_log("Failed to fetch subscriptions: " . $e->getMessage());
    $subscriptions = [];
    $stats = [];
    $expiringCount = 0;
    $expiringValue = 0;
}

// Helper function to calculate statistics
function calculateSubscriptionStats($db) {
    $stats = [];
    
    // Total MRR (Monthly Recurring Revenue)
    $mrrQuery = "
        SELECT 
            SUM(CASE 
                WHEN sub.billing_cycle = 'yearly' THEN p.price_monthly 
                ELSE p.price_monthly 
            END) as mrr
        FROM schools s
        JOIN subscriptions sub ON s.id = sub.school_id
        JOIN plans p ON s.plan_id = p.id
        WHERE s.status = 'active'
        AND sub.status = 'active'
    ";
    
    $result = $db->query($mrrQuery)->fetch();
    $stats['mrr'] = $result['mrr'] ?? 0;
    
    // Active subscriptions count
    $activeQuery = "
        SELECT COUNT(*) as count
        FROM schools s
        JOIN subscriptions sub ON s.id = sub.school_id
        WHERE s.status = 'active'
        AND sub.status = 'active'
    ";
    
    $result = $db->query($activeQuery)->fetch();
    $stats['active_count'] = $result['count'] ?? 0;
    
    // Renewals this month
    $renewalsQuery = "
        SELECT COUNT(*) as count
        FROM subscriptions
        WHERE status = 'active'
        AND MONTH(current_period_start) = MONTH(NOW())
        AND YEAR(current_period_start) = YEAR(NOW())
    ";
    
    $result = $db->query($renewalsQuery)->fetch();
    $stats['renewals_this_month'] = $result['count'] ?? 0;
    
    // Churn rate (cancelled subscriptions last 30 days)
    $churnQuery = "
        SELECT 
            COUNT(*) as churned,
            (SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND current_period_start <= DATE_SUB(NOW(), INTERVAL 30 DAY)) as starting_total
        FROM schools
        WHERE status = 'cancelled'
        AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    
    $result = $db->query($churnQuery)->fetch();
    $churned = $result['churned'] ?? 0;
    $startingTotal = $result['starting_total'] ?? 1; // Avoid division by zero
    
    $stats['churn_rate'] = $startingTotal > 0 ? ($churned / $startingTotal) * 100 : 0;
    
    // Total subscriptions count
    $totalQuery = "SELECT COUNT(*) as count FROM schools WHERE status IN ('active', 'trial')";
    $result = $db->query($totalQuery)->fetch();
    $stats['total_subscriptions'] = $result['count'] ?? 0;
    
    // Counts by filter
    $stats['filter_counts'] = [
        'all' => $stats['total_subscriptions'],
        'active' => $stats['active_count'],
        'expiring' => $expiringCount ?? 0,
        'expired' => getCountByStatus($db, ['active', 'suspended'], true),
        'canceled' => getCountByStatus($db, ['cancelled']),
        'trial' => getCountByStatus($db, ['trial'])
    ];
    
    return $stats;
}

function getCountByStatus($db, $statuses, $expiredOnly = false) {
    if ($expiredOnly) {
        $query = "
            SELECT COUNT(*) as count
            FROM schools s
            JOIN subscriptions sub ON s.id = sub.school_id
            WHERE s.status IN ('" . implode("','", $statuses) . "')
            AND sub.current_period_end < NOW()
        ";
    } else {
        $query = "
            SELECT COUNT(*) as count
            FROM schools
            WHERE status IN ('" . implode("','", $statuses) . "')
        ";
    }
    
    $result = $db->query($query)->fetch();
    return $result['count'] ?? 0;
}

// Helper functions for display
function getStatusBadge($schoolStatus, $subscriptionEnd = null) {
    $currentTime = time();
    $subscriptionEndTime = $subscriptionEnd ? strtotime($subscriptionEnd) : null;
    
    if ($schoolStatus === 'cancelled') {
        return ['class' => 'status-canceled', 'text' => 'Canceled'];
    } elseif ($schoolStatus === 'suspended') {
        return ['class' => 'status-expired', 'text' => 'Suspended'];
    } elseif ($schoolStatus === 'trial') {
        return ['class' => 'status-expiring', 'text' => 'Trial'];
    } elseif ($schoolStatus === 'active' && $subscriptionEndTime) {
        $daysRemaining = floor(($subscriptionEndTime - $currentTime) / (60 * 60 * 24));
        
        if ($daysRemaining < 0) {
            return ['class' => 'status-expired', 'text' => 'Expired'];
        } elseif ($daysRemaining <= 7) {
            return ['class' => 'status-expiring', 'text' => 'Expiring Soon'];
        } else {
            return ['class' => 'status-active', 'text' => 'Active'];
        }
    } elseif ($schoolStatus === 'active') {
        return ['class' => 'status-active', 'text' => 'Active'];
    } else {
        return ['class' => 'status-canceled', 'text' => ucfirst($schoolStatus)];
    }
}

function getPlanBadge($planName) {
    $planName = strtolower($planName);
    if (strpos($planName, 'enterprise') !== false) {
        return ['class' => 'plan-enterprise', 'text' => 'Enterprise'];
    } elseif (strpos($planName, 'growth') !== false) {
        return ['class' => 'plan-pro', 'text' => 'Growth'];
    } elseif (strpos($planName, 'starter') !== false) {
        return ['class' => 'plan-basic', 'text' => 'Starter'];
    } else {
        return ['class' => 'plan-basic', 'text' => 'Basic'];
    }
}



function getDaysRemainingText($endDate) {
    if (!$endDate) return 'No end date';
    
    $currentTime = time();
    $endTime = strtotime($endDate);
    $daysRemaining = floor(($endTime - $currentTime) / (60 * 60 * 24));
    
    if ($daysRemaining < 0) {
        return abs($daysRemaining) . ' days ago';
    } elseif ($daysRemaining === 0) {
        return 'Today';
    } elseif ($daysRemaining === 1) {
        return 'Tomorrow';
    } else {
        return 'in ' . $daysRemaining . ' days';
    }
}

function getIconForPlan($planName) {
    $planName = strtolower($planName);
    if (strpos($planName, 'enterprise') !== false) return 'fa-university';
    if (strpos($planName, 'growth') !== false) return 'fa-chart-line';
    if (strpos($planName, 'starter') !== false) return 'fa-rocket';
    return 'fa-school';
}

function getIconColorForPlan($planName) {
    $planName = strtolower($planName);
    if (strpos($planName, 'enterprise') !== false) return 'from-purple-50 to-pink-50';
    if (strpos($planName, 'growth') !== false) return 'from-emerald-50 to-teal-50';
    if (strpos($planName, 'starter') !== false) return 'from-blue-50 to-indigo-50';
    return 'from-slate-50 to-gray-50';
}

function getIconTextColorForPlan($planName) {
    $planName = strtolower($planName);
    if (strpos($planName, 'enterprise') !== false) return 'text-purple-600';
    if (strpos($planName, 'growth') !== false) return 'text-emerald-600';
    if (strpos($planName, 'starter') !== false) return 'text-blue-600';
    return 'text-slate-600';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Subscription Management | AcademixSuite Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg); 
            color: #1e293b; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Glassmorphism effects */
        .glass-header { 
            background: rgba(255, 255, 255, 0.92); 
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.3);
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.5);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
        }

        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
        }
        
        .toast {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease forwards;
            opacity: 0;
            transform: translateX(100px);
            transition: opacity 0.3s, transform 0.3s;
        }
        
        .toast-success {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border-left: 4px solid #059669;
        }
        
        .toast-info {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: white;
            border-left: 4px solid #2563eb;
        }
        
        .toast-warning {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
            border-left: 4px solid #d97706;
        }
        
        .toast-error {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            border-left: 4px solid #dc2626;
        }
        
        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }
        
        .toast-exit {
            animation: fadeOut 0.3s ease forwards;
        }
        
        .toast-icon {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .toast-content {
            flex: 1;
        }
        
        .toast-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        
        .toast-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Sidebar styling */
        .sidebar-link { 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
            position: relative;
        }
        
        .sidebar-link:hover { 
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.05) 0%, rgba(37, 99, 235, 0.02) 100%);
            color: var(--brand-primary); 
            border-left-color: rgba(37, 99, 235, 0.3);
        }
        
        .active-link { 
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.05) 100%);
            color: var(--brand-primary); 
            border-left-color: var(--brand-primary); 
            font-weight: 700;
        }
        
        .active-link::before {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--brand-primary);
            border-radius: 4px 0 0 4px;
        }

        .dropdown-content { 
            max-height: 0; 
            overflow: hidden; 
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        
        .dropdown-open .dropdown-content { 
            max-height: 500px; 
        }
        
        .dropdown-open .chevron { 
            transform: rotate(180deg); 
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .status-expiring {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-expired {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .status-canceled {
            background-color: #f3f4f6;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }

        /* Plan badges */
        .plan-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .plan-enterprise {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: white;
        }
        
        .plan-pro {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .plan-basic {
            background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
            color: white;
        }

        /* Countdown styling */
        .countdown-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px;
            min-width: 50px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        
        .countdown-value {
            font-size: 18px;
            font-weight: 900;
            color: #1e293b;
        }
        
        .countdown-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Progress bars */
        .progress-bar {
            height: 6px;
            border-radius: 3px;
            background: #f1f5f9;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-green {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        
        .progress-yellow {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }
        
        .progress-red {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        /* Gradient badges */
        .gradient-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            letter-spacing: 0.05em;
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .glass-header {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: white;
            }
            
            .countdown-item {
                min-width: 40px;
                padding: 6px;
            }
            
            .countdown-value {
                font-size: 16px;
            }
            
            .toast-container {
                left: 20px;
                right: 20px;
                max-width: none;
            }
        }

        /* Tab styling */
        .tab-button {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .tab-button:hover {
            color: #2563eb;
        }
        
        .tab-button.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: linear-gradient(to top, rgba(37, 99, 235, 0.05), transparent);
        }

        /* Filter chips */
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-chip:hover {
            background: #e2e8f0;
        }
        
        .filter-chip.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        
        /* Form styles */
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Responsive table */
        @media (max-width: 768px) {
            .mobile-table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .mobile-table-container table {
                min-width: 800px;
            }
        }

        /* Loading state */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #cbd5e1;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="antialiased selection:bg-blue-100 selection:text-blue-900">

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Renew Modal -->
    <div id="renewModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md w-11/12">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">Renew Subscription</h3>
                <button onclick="closeModal('renewModal')" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="renewForm" method="POST" action="renew_subscription.php">
                <input type="hidden" name="school_id" id="renewSchoolId">
                <input type="hidden" name="subscription_id" id="renewSubscriptionId">
                
                <div class="mb-6">
                    <div class="flex items-center gap-3 mb-4 p-4 bg-slate-50 rounded-xl">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-purple-50 flex items-center justify-center">
                            <i class="fas fa-school text-blue-600"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900" id="renewSchoolName"></p>
                            <p class="text-sm text-slate-500" id="renewSchoolCode"></p>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="form-label">Current Plan</label>
                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <span class="font-bold text-slate-900" id="renewCurrentPlan"></span>
                                <span class="text-sm text-slate-500" id="renewCurrentPrice"></span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Billing Cycle</label>
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="billing_cycle" value="monthly" class="text-blue-600" checked>
                                    <span class="text-sm">Monthly</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="billing_cycle" value="yearly" class="text-blue-600">
                                    <span class="text-sm">Yearly (Save 20%)</span>
                                </label>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Renewal Period</label>
                            <select name="period_years" class="form-input" id="renewPeriod">
                                <option value="1">1 Year</option>
                                <option value="2">2 Years</option>
                                <option value="3">3 Years</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="pt-6 border-t border-slate-100">
                    <div class="flex justify-between items-center mb-6">
                        <span class="text-sm font-bold text-slate-700">Total Amount</span>
                        <span class="text-xl font-black text-blue-600" id="renewTotalAmount"></span>
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="button" onclick="closeModal('renewModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition">
                            Process Renewal
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Upgrade Modal -->
    <div id="upgradeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md w-11/12">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">Upgrade Subscription</h3>
                <button onclick="closeModal('upgradeModal')" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="upgradeForm" method="POST" action="upgrade_subscription.php">
                <input type="hidden" name="school_id" id="upgradeSchoolId">
                <input type="hidden" name="current_plan_id" id="currentPlanId">
                
                <div class="mb-6">
                    <div class="flex items-center gap-3 mb-4 p-4 bg-slate-50 rounded-xl">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-purple-50 flex items-center justify-center">
                            <i class="fas fa-school text-blue-600"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900" id="upgradeSchoolName"></p>
                            <p class="text-sm text-slate-500" id="upgradeCurrentPlan"></p>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div id="availablePlans">
                            <!-- Plans will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <div class="pt-6 border-t border-slate-100">
                    <div class="flex justify-between items-center mb-6">
                        <span class="text-sm font-bold text-slate-700">Additional Cost</span>
                        <span class="text-xl font-black text-emerald-600" id="upgradeAdditionalCost">+$0/month</span>
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="button" onclick="closeModal('upgradeModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition">
                            Upgrade Now
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Export Modal -->
    <div id="exportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md w-11/12">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">Export Subscription Data</h3>
                <button onclick="closeModal('exportModal')" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="exportForm" method="POST" action="export_subscriptions.php" target="_blank">
                <div class="space-y-6">
                    <div>
                        <label class="form-label">Export Format</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl cursor-pointer">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
                                        <i class="fas fa-file-pdf text-red-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">PDF Report</p>
                                        <p class="text-xs text-slate-500">Formatted document</p>
                                    </div>
                                </div>
                                <input type="radio" name="format" value="pdf" class="text-blue-600" checked>
                            </label>
                            
                            <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl cursor-pointer">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                                        <i class="fas fa-file-excel text-emerald-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Excel</p>
                                        <p class="text-xs text-slate-500">Spreadsheet data</p>
                                    </div>
                                </div>
                                <input type="radio" name="format" value="excel" class="text-blue-600">
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <label class="form-label">Date Range</label>
                        <select name="date_range" class="form-input">
                            <option value="all">All subscriptions</option>
                            <option value="expiring_30">Expiring within 30 days</option>
                            <option value="expiring_90">Expiring within 90 days</option>
                            <option value="active">Active subscriptions only</option>
                            <option value="last_30_days">Last 30 days activity</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="form-label">Include Data</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="include[]" value="school_info" checked class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">School information</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="include[]" value="subscription_details" checked class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">Subscription details</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="include[]" value="payment_history" class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">Payment history</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="include[]" value="renewal_dates" checked class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">Renewal dates</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button type="button" onclick="closeModal('exportModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition">
                        Export Data
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">
        
        <?php include '../filepath/sidebar.php'; ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <!-- Header -->
            <header class="h-20 glass-header px-6 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">Subscription Management</h1>
                        <div class="hidden lg:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest"><?php echo $stats['active_count'] ?? 0; ?> Active Subscriptions</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="hidden md:flex items-center gap-2 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Expiring Soon</p>
                            <p class="text-sm font-black text-red-600"><?php echo $expiringCount; ?></p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button onclick="openModal('exportModal')" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-file-export"></i>
                            <span class="hidden sm:inline">Export</span>
                        </button>
                        <button onclick="sendRenewalReminders()" class="px-4 py-2 bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-amber-200">
                            <i class="fas fa-bell"></i>
                            <span class="hidden sm:inline">Send Reminders</span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <a href="?tab=all&filter=<?php echo $activeFilter; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                           class="tab-button <?php echo $currentTab === 'all' ? 'active' : ''; ?>" data-tab="all">
                            <i class="fas fa-list mr-2"></i>All Subscriptions
                        </a>
                        <a href="?tab=expiring&filter=<?php echo $activeFilter; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                           class="tab-button <?php echo $currentTab === 'expiring' ? 'active' : ''; ?>" data-tab="expiring">
                            <i class="fas fa-clock mr-2"></i>Expiring Soon
                        </a>
                        <a href="?tab=expired&filter=<?php echo $activeFilter; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                           class="tab-button <?php echo $currentTab === 'expired' ? 'active' : ''; ?>" data-tab="expired">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Expired
                        </a>
                        <a href="?tab=renewals&filter=<?php echo $activeFilter; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                           class="tab-button <?php echo $currentTab === 'renewals' ? 'active' : ''; ?>" data-tab="renewals">
                            <i class="fas fa-sync-alt mr-2"></i>Recent Renewals
                        </a>
                        <a href="?tab=analytics&filter=<?php echo $activeFilter; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                           class="tab-button <?php echo $currentTab === 'analytics' ? 'active' : ''; ?>" data-tab="analytics">
                            <i class="fas fa-chart-bar mr-2"></i>Analytics
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-8 custom-scrollbar">
                <!-- Page Header & Filters -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">School Subscriptions Overview</h2>
                            <p class="text-slate-500 font-medium">Monitor subscription status, renewal dates, and manage school plans</p>
                        </div>
                        <div class="flex gap-3">
                            <form method="GET" class="relative">
                                <input type="hidden" name="tab" value="<?php echo $currentTab; ?>">
                                <input type="hidden" name="filter" value="<?php echo $activeFilter; ?>">
                                <input type="text" name="search" placeholder="Search schools..." value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                       class="pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl text-sm w-64">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400"></i>
                            </form>
                            <button onclick="filterSubscriptions()" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                <i class="fas fa-filter"></i>
                                <span class="hidden sm:inline">Filters</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2 mt-6">
                        <a href="?tab=<?php echo $currentTab; ?>&filter=all&search=<?php echo urlencode($searchTerm); ?>" 
                           class="filter-chip <?php echo $activeFilter === 'all' ? 'active' : ''; ?>" data-filter="all">
                            <i class="fas fa-globe"></i> All (<?php echo $stats['filter_counts']['all'] ?? 0; ?>)
                        </a>
                        <a href="?tab=<?php echo $currentTab; ?>&filter=active&search=<?php echo urlencode($searchTerm); ?>" 
                           class="filter-chip <?php echo $activeFilter === 'active' ? 'active' : ''; ?>" data-filter="active">
                            <i class="fas fa-check-circle"></i> Active (<?php echo $stats['filter_counts']['active'] ?? 0; ?>)
                        </a>
                        <a href="?tab=<?php echo $currentTab; ?>&filter=expiring&search=<?php echo urlencode($searchTerm); ?>" 
                           class="filter-chip <?php echo $activeFilter === 'expiring' ? 'active' : ''; ?>" data-filter="expiring">
                            <i class="fas fa-clock"></i> Expiring Soon (<?php echo $stats['filter_counts']['expiring'] ?? 0; ?>)
                        </a>
                        <a href="?tab=<?php echo $currentTab; ?>&filter=expired&search=<?php echo urlencode($searchTerm); ?>" 
                           class="filter-chip <?php echo $activeFilter === 'expired' ? 'active' : ''; ?>" data-filter="expired">
                            <i class="fas fa-exclamation-circle"></i> Expired (<?php echo $stats['filter_counts']['expired'] ?? 0; ?>)
                        </a>
                        <a href="?tab=<?php echo $currentTab; ?>&filter=canceled&search=<?php echo urlencode($searchTerm); ?>" 
                           class="filter-chip <?php echo $activeFilter === 'canceled' ? 'active' : ''; ?>" data-filter="canceled">
                            <i class="fas fa-ban"></i> Canceled (<?php echo $stats['filter_counts']['canceled'] ?? 0; ?>)
                        </a>
                        <a href="?tab=<?php echo $currentTab; ?>&filter=trial&search=<?php echo urlencode($searchTerm); ?>" 
                           class="filter-chip <?php echo $activeFilter === 'trial' ? 'active' : ''; ?>" data-filter="trial">
                            <i class="fas fa-hourglass-half"></i> Trial (<?php echo $stats['filter_counts']['trial'] ?? 0; ?>)
                        </a>
                    </div>
                </div>

                <!-- Key Metrics Cards -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- MRR Card -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">MONTHLY REVENUE</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo formatCurrency(($stats['mrr'] ?? 0) * $exchange_rate, 'NGN'); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-chart-line text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> 12.5%</span>
                            <span class="text-slate-500">from last month</span>
                        </div>
                    </div>
                    
                    <!-- Active Subscriptions Card -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">ACTIVE SUBSCRIPTIONS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $stats['active_count'] ?? 0; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                                <i class="fas fa-shield-check text-blue-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="progress-bar">
                            <?php 
                            $activePercentage = $stats['total_subscriptions'] > 0 ? ($stats['active_count'] / $stats['total_subscriptions']) * 100 : 0;
                            ?>
                            <div class="progress-fill progress-green" style="width: <?php echo $activePercentage; ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2"><?php echo round($activePercentage); ?>% of total subscriptions</p>
                    </div>
                    
                    <!-- Renewals This Month Card -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.3s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">RENEWALS THIS MONTH</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $stats['renewals_this_month'] ?? 0; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-sync-alt text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="countdown-item inline-flex">
                            <span class="countdown-value">8</span>
                            <span class="countdown-label">Days</span>
                        </div>
                        <span class="text-xs text-slate-500 ml-3">until next batch</span>
                    </div>
                    
                    <!-- Churn Rate Card -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">CHURN RATE</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo round($stats['churn_rate'] ?? 0, 1); ?>%</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-50 to-red-100 flex items-center justify-center">
                                <i class="fas fa-chart-area text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-red-600 font-bold"><i class="fas fa-arrow-down mr-1"></i> 0.4%</span>
                            <span class="text-slate-500">from last month</span>
                        </div>
                    </div>
                </div>

                <!-- Subscriptions Table -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl overflow-hidden animate-fadeInUp" style="animation-delay: 0.5s">
                    <div class="p-6 border-b border-slate-100">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-black text-slate-900" id="tableTitle">
                                <?php 
                                $titles = [
                                    'all' => 'All School Subscriptions',
                                    'expiring' => 'Subscriptions Expiring Soon',
                                    'expired' => 'Expired Subscriptions',
                                    'renewals' => 'Recent Subscription Renewals',
                                    'analytics' => 'Subscription Analytics'
                                ];
                                echo $titles[$currentTab] ?? 'School Subscriptions';
                                ?>
                            </h3>
                            <div class="flex items-center gap-3">
                                <a href="add.php" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center gap-2">
                                    <i class="fas fa-plus"></i>
                                    <span>Add Subscription</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto mobile-table-container" id="tableContainer">
                        <table class="w-full" id="subscriptionsTable">
                            <thead>
                                <tr class="bg-slate-50">
                                    <th class="text-left p-4 text-sm font-bold text-slate-600 uppercase tracking-wider">School</th>
                                    <th class="text-left p-4 text-sm font-bold text-slate-600 uppercase tracking-wider">Subscription Plan</th>
                                    <th class="text-left p-4 text-sm font-bold text-slate-600 uppercase tracking-wider">Status</th>
                                    <th class="text-left p-4 text-sm font-bold text-slate-600 uppercase tracking-wider">Renewal Date</th>
                                    <th class="text-left p-4 text-sm font-bold text-slate-600 uppercase tracking-wider">Value</th>
                                    <th class="text-left p-4 text-sm font-bold text-slate-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($subscriptions)): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-4">
                                                    <i class="fas fa-inbox text-slate-400 text-2xl"></i>
                                                </div>
                                                <h4 class="text-lg font-bold text-slate-700 mb-2">No subscriptions found</h4>
                                                <p class="text-slate-500">
                                                    <?php if (!empty($searchTerm)): ?>
                                                        No results for "<?php echo htmlspecialchars($searchTerm); ?>"
                                                    <?php else: ?>
                                                        No subscriptions match the selected filters
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($subscriptions as $subscription): 
                                        $statusInfo = getStatusBadge($subscription['status'], $subscription['current_period_end']);
                                        $planBadge = getPlanBadge($subscription['plan_name']);
                                        $daysText = getDaysRemainingText($subscription['current_period_end']);
                                        
                                        // Calculate value
                                        $monthlyValue = $subscription['price_monthly'] ?? 0;
                                        $yearlyValue = $subscription['price_yearly'] ?? 0;
                                        $currentValue = $monthlyValue;
                                        $valueText = '$' . number_format($currentValue, 2) . '/month';
                                        $valueNGN = '₦' . number_format($currentValue * $exchange_rate, 0) . ' NGN/month';
                                    ?>
                                    <tr class="hover:bg-slate-50 transition" data-school-id="<?php echo $subscription['id']; ?>">
                                        <td class="p-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?php echo getIconColorForPlan($subscription['plan_name']); ?> flex items-center justify-center">
                                                    <i class="fas <?php echo getIconForPlan($subscription['plan_name']); ?> <?php echo getIconTextColorForPlan($subscription['plan_name']); ?>"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900"><?php echo htmlspecialchars($subscription['name']); ?></p>
                                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($subscription['slug']); ?> • <?php echo htmlspecialchars($subscription['city'] ?? 'Unknown'); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-4">
                                            <span class="plan-badge <?php echo $planBadge['class']; ?>"><?php echo $planBadge['text']; ?></span>
                                        </td>
                                        <td class="p-4">
                                            <span class="status-badge <?php echo $statusInfo['class']; ?>">
                                                <i class="fas fa-circle text-[8px] mr-1.5"></i> <?php echo $statusInfo['text']; ?>
                                            </span>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex items-center gap-2">
                                                <div class="flex flex-col">
                                                    <span class="font-bold text-slate-900">
                                                        <?php echo $subscription['current_period_end'] ? date('M d, Y', strtotime($subscription['current_period_end'])) : 'No end date'; ?>
                                                    </span>
                                                    <span class="text-xs <?php echo strpos($daysText, 'ago') !== false ? 'text-red-500' : 'text-slate-500'; ?> font-bold">
                                                        <?php echo $daysText; ?>
                                                    </span>
                                                </div>
                                                <?php if (strpos($statusInfo['class'], 'expiring') !== false): ?>
                                                    <div class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="p-4">
                                            <span class="font-bold text-slate-900"><?php echo $valueText; ?></span>
                                            <div class="text-xs text-slate-500"><?php echo $valueNGN; ?></div>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex items-center gap-2">
                                                <?php if (in_array($subscription['status'], ['active', 'trial'])): ?>
                                                    <button onclick="openRenewModal(
                                                        <?php echo $subscription['id']; ?>,
                                                        '<?php echo $subscription['subscription_id'] ?? ''; ?>',
                                                        '<?php echo htmlspecialchars($subscription['name']); ?>',
                                                        '<?php echo htmlspecialchars($subscription['slug']); ?>',
                                                        '<?php echo htmlspecialchars($subscription['plan_name']); ?>',
                                                        <?php echo $monthlyValue; ?>,
                                                        <?php echo $yearlyValue; ?>
                                                    )" class="px-3 py-1.5 <?php echo strpos($statusInfo['class'], 'expiring') !== false ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'; ?> font-bold rounded-lg transition text-xs">
                                                        <?php echo strpos($statusInfo['class'], 'expiring') !== false ? 'Renew Now' : 'Renew'; ?>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($subscription['status'] === 'suspended' || strpos($statusInfo['class'], 'expired') !== false): ?>
                                                    <button onclick="openRenewModal(
                                                        <?php echo $subscription['id']; ?>,
                                                        '<?php echo $subscription['subscription_id'] ?? ''; ?>',
                                                        '<?php echo htmlspecialchars($subscription['name']); ?>',
                                                        '<?php echo htmlspecialchars($subscription['slug']); ?>',
                                                        '<?php echo htmlspecialchars($subscription['plan_name']); ?>',
                                                        <?php echo $monthlyValue; ?>,
                                                        <?php echo $yearlyValue; ?>
                                                    )" class="px-3 py-1.5 bg-red-600 text-white font-bold rounded-lg hover:bg-red-700 transition text-xs">
                                                        Reactivate
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($subscription['status'] === 'cancelled'): ?>
                                                    <button onclick="winBack('<?php echo htmlspecialchars($subscription['name']); ?>')" class="px-3 py-1.5 bg-purple-100 text-purple-700 font-bold rounded-lg hover:bg-purple-200 transition text-xs">
                                                        Win Back
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button onclick="openUpgradeModal(
                                                    <?php echo $subscription['id']; ?>,
                                                    '<?php echo htmlspecialchars($subscription['name']); ?>',
                                                    '<?php echo htmlspecialchars($subscription['plan_name']); ?>',
                                                    <?php echo $subscription['plan_id'] ?? 0; ?>
                                                )" class="px-3 py-1.5 <?php echo strpos($planBadge['text'], 'Enterprise') !== false ? 'bg-slate-100 text-slate-700 hover:bg-slate-200' : 'bg-blue-100 text-blue-700 hover:bg-blue-200'; ?> font-bold rounded-lg transition text-xs">
                                                    <?php echo strpos($planBadge['text'], 'Enterprise') !== false ? '<i class="fas fa-arrow-up"></i>' : 'Upgrade'; ?>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="p-6 border-t border-slate-100">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-slate-500" id="tableCount">
                                Showing <span class="font-bold"><?php echo count($subscriptions); ?></span> of <span class="font-bold"><?php echo $stats['filter_counts'][$activeFilter] ?? 0; ?></span> subscriptions
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Countdown Section -->
                <?php if ($expiringCount > 0): ?>
                <div class="max-w-7xl mx-auto mt-8">
                    <div class="glass-card rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Next Batch Renewal Countdown</h3>
                                <p class="text-slate-500"><?php echo $expiringCount; ?> subscription(s) expiring in the next 30 days</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 bg-amber-500 rounded-full animate-pulse"></div>
                                <span class="text-sm font-bold text-amber-600">ACTION REQUIRED</span>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-center gap-6 mb-6">
                            <div class="countdown-item">
                                <span class="countdown-value" id="countdown-days">12</span>
                                <span class="countdown-label">Days</span>
                            </div>
                            <div class="countdown-item">
                                <span class="countdown-value" id="countdown-hours">06</span>
                                <span class="countdown-label">Hours</span>
                            </div>
                            <div class="countdown-item">
                                <span class="countdown-value" id="countdown-minutes">24</span>
                                <span class="countdown-label">Minutes</span>
                            </div>
                            <div class="countdown-item">
                                <span class="countdown-value" id="countdown-seconds">18</span>
                                <span class="countdown-label">Seconds</span>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-between">
                            <div class="text-sm">
                                <span class="font-bold text-slate-900"><?php echo formatCurrency($expiringValue, 'NGN'); ?></span>
                                <span class="text-slate-500"> in revenue at risk</span>
                            </div>
                            <button onclick="bulkRenew()" class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                                <i class="fas fa-sync-alt mr-2"></i>Bulk Renew Now
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Toast Notification System
        class Toast {
            static show(message, type = 'info', duration = 5000) {
                const container = document.getElementById('toastContainer');
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                
                const icons = {
                    success: 'fa-check-circle',
                    info: 'fa-info-circle',
                    warning: 'fa-exclamation-triangle',
                    error: 'fa-times-circle'
                };
                
                toast.innerHTML = `
                    <i class="fas ${icons[type]} toast-icon"></i>
                    <div class="toast-content">${message}</div>
                    <button class="toast-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                container.appendChild(toast);
                
                // Trigger animation
                setTimeout(() => {
                    toast.style.opacity = '1';
                    toast.style.transform = 'translateX(0)';
                }, 10);
                
                // Auto remove after duration
                if (duration > 0) {
                    setTimeout(() => {
                        toast.classList.add('toast-exit');
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.remove();
                            }
                        }, 300);
                    }, duration);
                }
                
                return toast;
            }
            
            static success(message, duration = 5000) {
                return this.show(message, 'success', duration);
            }
            
            static info(message, duration = 5000) {
                return this.show(message, 'info', duration);
            }
            
            static warning(message, duration = 5000) {
                return this.show(message, 'warning', duration);
            }
            
            static error(message, duration = 5000) {
                return this.show(message, 'error', duration);
            }
        }

        // Fetch available plans for upgrade modal
        async function fetchAvailablePlans() {
            try {
                const response = await fetch('get_plans.php');
                const plans = await response.json();
                return plans;
            } catch (error) {
                console.error('Error fetching plans:', error);
                return [];
            }
        }

        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        // Dropdown functionality
        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            dropdown.classList.toggle('dropdown-open');
        }

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Open renew modal with data
        async function openRenewModal(schoolId, subscriptionId, schoolName, schoolCode, currentPlan, monthlyPrice, yearlyPrice) {
            document.getElementById('renewSchoolId').value = schoolId;
            document.getElementById('renewSubscriptionId').value = subscriptionId;
            document.getElementById('renewSchoolName').textContent = schoolName;
            document.getElementById('renewSchoolCode').textContent = schoolCode + ' • ' + (schoolCode.includes('NX-') ? 'Premium Node' : 'Standard Node');
            document.getElementById('renewCurrentPlan').textContent = currentPlan;
            document.getElementById('renewCurrentPrice').textContent = `$${monthlyPrice}/month`;
            
            // Calculate initial total
            updateRenewTotal();
            
            openModal('renewModal');
        }

        // Update renewal total amount
        function updateRenewTotal() {
            const monthlyPrice = parseFloat(document.getElementById('renewCurrentPrice').textContent.replace('$', '').replace('/month', ''));
            const billingCycle = document.querySelector('input[name="billing_cycle"]:checked').value;
            const periodYears = parseInt(document.getElementById('renewPeriod').value);
            
            let total = monthlyPrice * 12 * periodYears;
            
            if (billingCycle === 'yearly') {
                total *= 0.8; // 20% discount for yearly billing
            }
            
            document.getElementById('renewTotalAmount').textContent = `$${total.toFixed(2)}`;
        }

        // Attach event listeners for renewal form
        document.addEventListener('DOMContentLoaded', function() {
            const renewForm = document.getElementById('renewForm');
            if (renewForm) {
                renewForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                    submitBtn.disabled = true;
                    
                    try {
                        const response = await fetch('renew_subscription.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            Toast.success(result.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            Toast.error(result.message);
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }
                    } catch (error) {
                        console.error('Error renewing subscription:', error);
                        Toast.error('Failed to process renewal');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                });
            }
            
            // Update total when billing cycle or period changes
            document.querySelectorAll('input[name="billing_cycle"]').forEach(input => {
                input.addEventListener('change', updateRenewTotal);
            });
            
            document.getElementById('renewPeriod').addEventListener('change', updateRenewTotal);
            
            // Fetch support ticket count
            fetchSupportCount();
        });

        // Open upgrade modal
        async function openUpgradeModal(schoolId, schoolName, currentPlan, currentPlanId) {
            document.getElementById('upgradeSchoolId').value = schoolId;
            document.getElementById('upgradeSchoolName').textContent = schoolName;
            document.getElementById('upgradeCurrentPlan').textContent = 'Current: ' + currentPlan;
            document.getElementById('currentPlanId').value = currentPlanId;
            
            // Fetch available plans
            const plans = await fetchAvailablePlans();
            const availablePlansContainer = document.getElementById('availablePlans');
            availablePlansContainer.innerHTML = '';
            
            // Filter out current plan and inactive plans
            const availablePlans = plans.filter(plan => plan.id != currentPlanId && plan.is_active);
            
            if (availablePlans.length === 0) {
                availablePlansContainer.innerHTML = '<p class="text-slate-500 text-center py-4">No upgrade options available</p>';
                return;
            }
            
            availablePlans.forEach((plan, index) => {
                const isEnterprise = plan.name.toLowerCase().includes('enterprise');
                const planElement = document.createElement('label');
                planElement.className = `flex items-center justify-between p-4 border ${isEnterprise ? 'border-blue-200 rounded-xl bg-blue-50' : 'border-slate-200 rounded-xl'} cursor-pointer`;
                planElement.innerHTML = `
                    <div>
                        <div class="flex items-center gap-2">
                            <input type="radio" name="new_plan_id" value="${plan.id}" class="text-blue-600" ${index === 0 ? 'checked' : ''}>
                            <span class="font-bold text-slate-900">${plan.name} Plan</span>
                        </div>
                        <p class="text-sm text-slate-500 mt-1 ml-6">${plan.description || 'No description available'}</p>
                    </div>
                    <span class="font-bold ${isEnterprise ? 'text-blue-600' : 'text-slate-900'}">$${plan.price_monthly}/month</span>
                `;
                
                planElement.querySelector('input').addEventListener('change', updateUpgradeCost);
                availablePlansContainer.appendChild(planElement);
            });
            
            // Calculate initial additional cost
            updateUpgradeCost();
            
            openModal('upgradeModal');
        }

        // Update upgrade cost
        function updateUpgradeCost() {
            const currentPlanPrice = <?php echo $subscriptions[0]['price_monthly'] ?? 0; ?>;
            const newPlanElement = document.querySelector('input[name="new_plan_id"]:checked');
            
            if (newPlanElement) {
                const newPlanPrice = parseFloat(newPlanElement.parentElement.parentElement.nextElementSibling.textContent.replace('$', '').replace('/month', ''));
                const additionalCost = newPlanPrice - currentPlanPrice;
                
                document.getElementById('upgradeAdditionalCost').textContent = additionalCost >= 0 
                    ? `+$${additionalCost.toFixed(2)}/month` 
                    : `-$${Math.abs(additionalCost).toFixed(2)}/month`;
                
                document.getElementById('upgradeAdditionalCost').className = additionalCost >= 0 
                    ? 'text-xl font-black text-emerald-600' 
                    : 'text-xl font-black text-blue-600';
            }
        }

        // Attach event listener for upgrade form
        document.addEventListener('DOMContentLoaded', function() {
            const upgradeForm = document.getElementById('upgradeForm');
            if (upgradeForm) {
                upgradeForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                    submitBtn.disabled = true;
                    
                    try {
                        const response = await fetch('upgrade_subscription.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            Toast.success(result.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            Toast.error(result.message);
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }
                    } catch (error) {
                        console.error('Error upgrading subscription:', error);
                        Toast.error('Failed to process upgrade');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                });
            }
        });

        // Send renewal reminders
        async function sendRenewalReminders() {
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Sending...';
            btn.disabled = true;
            
            try {
                const response = await fetch('send_reminders.php');
                const result = await response.json();
                
                if (result.success) {
                    Toast.success(`Renewal reminders sent to ${result.count} schools!<br>All administrators have been notified via email.`);
                } else {
                    Toast.error(result.message || 'Failed to send reminders');
                }
            } catch (error) {
                console.error('Error sending reminders:', error);
                Toast.error('Failed to send renewal reminders');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Fetch support ticket count
        async function fetchSupportCount() {
            try {
                const response = await fetch('get_support_count.php');
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('supportCount').textContent = result.count;
                }
            } catch (error) {
                console.error('Error fetching support count:', error);
            }
        }

        // Countdown timer
        function updateCountdown() {
            // Set target date (12 days from now)
            const targetDate = new Date();
            targetDate.setDate(targetDate.getDate() + 12);
            targetDate.setHours(0, 0, 0, 0);
            
            const now = new Date().getTime();
            const distance = targetDate - now;
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            document.getElementById('countdown-days').textContent = days.toString().padStart(2, '0');
            document.getElementById('countdown-hours').textContent = hours.toString().padStart(2, '0');
            document.getElementById('countdown-minutes').textContent = minutes.toString().padStart(2, '0');
            document.getElementById('countdown-seconds').textContent = seconds.toString().padStart(2, '0');
        }

        // Initialize countdown
        setInterval(updateCountdown, 1000);
        updateCountdown();

        // Utility functions with toasts
        function viewDetails(schoolName = 'this subscription') {
            Toast.info(`Opening details for ${schoolName}...`);
        }

        function winBack(schoolName = 'this school') {
            Toast.info(`Initiating win-back campaign for ${schoolName}...<br>Special offer email sent.`);
        }

        function bulkRenew() {
            Toast.info('Opening bulk renewal tool...<br>You can renew multiple subscriptions at once.');
        }

        function filterSubscriptions() {
            Toast.info('Advanced filter panel opened.<br>You can apply multiple filters.');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + E for export
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                openModal('exportModal');
                Toast.info('Export modal opened (Ctrl+E)');
            }
            
            // Esc to close modals
            if (e.key === 'Escape') {
                closeModal('renewModal');
                closeModal('upgradeModal');
                closeModal('exportModal');
            }
            
            // Ctrl/Cmd + F for search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
                Toast.info('Search input focused (Ctrl+F)');
            }
        });

        // Welcome toast
        setTimeout(() => {
            Toast.success('Subscription Management Dashboard loaded successfully!', 3000);
        }, 1000);
    </script>
</body>
</html>