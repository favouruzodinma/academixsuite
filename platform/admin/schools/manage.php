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

// Get school ID from query parameter
$schoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($schoolId <= 0) {
    header("Location: index.php?error=invalid_school");
    exit;
}

// Get school data from platform database
$db = Database::getPlatformConnection();
$stmt = $db->prepare("SELECT s.*, p.name as plan_name, p.price_monthly, p.features,
                             sub.status as subscription_status, sub.current_period_end,
                             sub.current_period_start, sub.billing_cycle
                      FROM schools s 
                      LEFT JOIN subscriptions sub ON s.id = sub.school_id
                      LEFT JOIN plans p ON sub.plan_id = p.id
                      WHERE s.id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    header("Location: index.php?error=school_not_found");
    exit;
}

// Get latest invoice
$invoice = null;
try {
    $invStmt = $db->prepare("
        SELECT * FROM invoices 
        WHERE school_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $invStmt->execute([$schoolId]);
    $invoice = $invStmt->fetch();
} catch (Exception $e) {
    error_log("Error getting invoice: " . $e->getMessage());
}

// Get school statistics from school's database
$schoolStats = [
    'total_users' => 0,
    'active_users' => 0,
    'teachers' => 0,
    'students' => 0,
    'admins' => 0,
    'parents' => 0,
    'classes' => 0,
    'subjects' => 0,
    'storage_used' => 0,
    'last_login' => null,
    'database_size' => 0
];

// Function to get database statistics
function getDatabaseStats($dbName) {
    $stats = [
        'size' => 0,
        'tables' => 0,
        'rows' => 0
    ];
    
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get database size
        $stmt = $pdo->prepare("
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb,
                COUNT(*) as table_count
            FROM information_schema.tables 
            WHERE table_schema = ?
        ");
        $stmt->execute([$dbName]);
        $result = $stmt->fetch();
        
        if ($result) {
            $stats['size'] = $result['size_mb'] ?? 0;
            $stats['tables'] = $result['table_count'] ?? 0;
        }
        
        // Try to get row count for users table
        try {
            $pdo->exec("USE `$dbName`");
            $userStmt = $pdo->query("SELECT COUNT(*) as count FROM users");
            $userResult = $userStmt->fetch();
            $stats['rows'] = $userResult['count'] ?? 0;
        } catch (Exception $e) {
            // Users table might not exist yet
        }
        
    } catch (Exception $e) {
        error_log("Error getting database stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Get school database statistics
$dbStats = getDatabaseStats($school['database_name'] ?? '');
$schoolStats['database_size'] = $dbStats['size'];

// Connect to school's database to get user statistics
try {
    if (!empty($school['database_name']) && Database::schoolDatabaseExists($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        // Get user counts
        $userStmt = $schoolDb->prepare("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN user_type = 'teacher' AND is_active = 1 THEN 1 ELSE 0 END) as teachers,
                SUM(CASE WHEN user_type = 'student' AND is_active = 1 THEN 1 ELSE 0 END) as students,
                SUM(CASE WHEN user_type = 'admin' AND is_active = 1 THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN user_type = 'parent' AND is_active = 1 THEN 1 ELSE 0 END) as parents,
                MAX(last_login_at) as last_login
            FROM users
        ");
        $userStmt->execute();
        $userStats = $userStmt->fetch();
        if ($userStats) {
            $schoolStats = array_merge($schoolStats, $userStats);
        }
        
        // Get class count
        try {
            $classStmt = $schoolDb->query("SELECT COUNT(*) as count FROM classes");
            $classResult = $classStmt->fetch();
            $schoolStats['classes'] = $classResult['count'] ?? 0;
        } catch (Exception $e) {
            // Classes table might not exist
        }
        
        // Get subject count
        try {
            $subjectStmt = $schoolDb->query("SELECT COUNT(*) as count FROM subjects");
            $subjectResult = $subjectStmt->fetch();
            $schoolStats['subjects'] = $subjectResult['count'] ?? 0;
        } catch (Exception $e) {
            // Subjects table might not exist
        }
    }
} catch (Exception $e) {
    error_log("Error getting school statistics: " . $e->getMessage());
}

// Get recent activities from platform audit logs
$recentActivities = [];
try {
    $activityStmt = $db->prepare("
        SELECT * FROM platform_audit_logs 
        WHERE school_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $activityStmt->execute([$schoolId]);
    $recentActivities = $activityStmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist
    error_log("Error getting activity logs: " . $e->getMessage());
}

// Format dates
$createdDate = date('F j, Y', strtotime($school['created_at']));
$subscriptionEnd = $school['current_period_end'] ? 
    date('F j, Y', strtotime($school['current_period_end'])) : 'No active subscription';
$daysUntilRenewal = $school['current_period_end'] ? 
    ceil((strtotime($school['current_period_end']) - time()) / (60 * 60 * 24)) : 0;
$isTrial = $school['status'] === 'trial' || ($school['trial_ends_at'] && strtotime($school['trial_ends_at']) > time());

// Calculate storage usage (simulated - in a real system you'd track actual storage)
$maxStorage = 10240; // 10GB default
$storageUsedMB = $schoolStats['database_size'] + ($schoolStats['total_users'] * 0.5); // Rough estimate
$storageUsedGB = $storageUsedMB / 1024;
$storagePercentage = min(100, ($storageUsedGB / ($maxStorage / 1024)) * 100);

// Status colors and icons
$statusConfig = [
    'active' => [
        'color' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'icon' => 'fa-check-circle',
        'label' => 'Active',
        'action' => 'suspend'
    ],
    'trial' => [
        'color' => 'bg-amber-100 text-amber-800 border-amber-200',
        'icon' => 'fa-clock',
        'label' => 'Trial',
        'action' => 'activate'
    ],
    'suspended' => [
        'color' => 'bg-red-100 text-red-800 border-red-200',
        'icon' => 'fa-pause-circle',
        'label' => 'Suspended',
        'action' => 'activate'
    ],
    'pending' => [
        'color' => 'bg-blue-100 text-blue-800 border-blue-200',
        'icon' => 'fa-hourglass-half',
        'label' => 'Pending',
        'action' => 'activate'
    ],
    'expired' => [
        'color' => 'bg-slate-100 text-slate-800 border-slate-200',
        'icon' => 'fa-calendar-times',
        'label' => 'Expired',
        'action' => 'extend'
    ]
];

$currentStatus = $school['status'] ?? 'pending';
$statusInfo = $statusConfig[$currentStatus] ?? $statusConfig['pending'];

// Generate CSRF token
$csrfToken = bin2hex(random_bytes(32));
// Store with expiration (e.g., 1 hour)
$_SESSION['csrf_tokens'][$csrfToken] = time() + 3600;

// Check if database exists
$databaseExists = Database::schoolDatabaseExists($school['database_name'] ?? '');

// ================== LIVE CURRENCY CONVERSION ==================
// Try to get live exchange rate from API, fallback to cached value
function getExchangeRate() {
    $cacheFile = __DIR__ . '/../../../cache/exchange_rate.json';
    $cacheTime = 3600; // 1 hour cache
    
    // Check cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (isset($cache['rate']) && $cache['rate'] > 0) {
            return $cache['rate'];
        }
    }
    
    // Try multiple APIs with fallback
    $apis = [
        'https://api.exchangerate-api.com/v4/latest/USD',
        'https://api.frankfurter.app/latest?from=USD&to=NGN',
        'https://open.er-api.com/v6/latest/USD'
    ];
    
    foreach ($apis as $api) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                // Extract NGN rate from different API formats
                if (isset($data['rates']['NGN'])) {
                    $rate = $data['rates']['NGN'];
                } elseif (isset($data['rates']['NGN'])) {
                    $rate = $data['rates']['NGN'];
                } elseif (isset($data['rates']['NGN'])) {
                    $rate = $data['rates']['NGN'];
                }
                
                if (isset($rate) && $rate > 0) {
                    // Cache the rate
                    file_put_contents($cacheFile, json_encode([
                        'rate' => $rate,
                        'timestamp' => time(),
                        'source' => $api
                    ]));
                    return $rate;
                }
            }
        } catch (Exception $e) {
            error_log("Currency API error ($api): " . $e->getMessage());
            continue;
        }
    }
    
    // Fallback to cached or default rate
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        return $cache['rate'] ?? 1400;
    }
    
    return 1400; // Default fallback
}

$exchangeRate = getExchangeRate();

// Currency conversion functions
function usdToNaira($amount) {
    global $exchangeRate;
    return $amount * $exchangeRate;
}

function formatNaira($amount, $decimals = 2) {
    return '₦' . number_format($amount, $decimals);
}

function formatUsd($amount, $decimals = 2) {
    return '$' . number_format($amount, $decimals);
}

// Calculate Naira equivalents
$priceMonthlyNGN = usdToNaira($school['price_monthly'] ?? 0);
$annualPriceNGN = usdToNaira(($school['price_monthly'] ?? 0) * 12);

// If invoice exists, convert its amount
$invoiceAmountNGN = $invoice ? usdToNaira($invoice['amount']) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>Manage <?php echo htmlspecialchars($school['name']); ?> | <?php echo APP_NAME; ?> Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        * { box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg); 
            color: #1e293b; 
            -webkit-tap-highlight-color: transparent;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            font-size: 15px;
            line-height: 1.5;
        }

        /* Mobile-specific adjustments */
        @media (max-width: 640px) {
            body { font-size: 14px; }
            h1, .text-2xl { font-size: 1.5rem !important; }
            h2, .text-xl { font-size: 1.25rem !important; }
            h3, .text-lg { font-size: 1.125rem !important; }
            .text-sm { font-size: 0.875rem !important; }
            .text-xs { font-size: 0.75rem !important; }
            .detail-card { padding: 1rem !important; }
            .modal-content { margin: 0.5rem; max-height: 85vh; }
            .grid-cols-4 { grid-template-columns: repeat(2, 1fr) !important; }
            .grid-cols-3 { grid-template-columns: 1fr !important; }
            .grid-cols-2 { grid-template-columns: 1fr !important; }
        }

        /* Tablet adjustments */
        @media (min-width: 641px) and (max-width: 768px) {
            body { font-size: 15px; }
            .grid-cols-4 { grid-template-columns: repeat(2, 1fr) !important; }
            .grid-cols-3 { grid-template-columns: repeat(2, 1fr) !important; }
        }

        /* Mobile-optimized scrollbar */
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        /* Fixed Header - Desktop & Mobile */
        .fixed-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            z-index: 50;
            height: 64px;
            border-bottom: 1px solid #e2e8f0;
        }

        .main-content {
            margin-top: 64px; /* Height of fixed header */
            height: calc(100vh - 64px);
            overflow-y: auto;
        }
        
        .detail-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); 
            border-radius: 16px;
            overflow: hidden;
            background: white;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: 2px solid;
        }

        .progress-container {
            width: 100%;
            height: 8px;
            background-color: #f1f5f9;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            border-color: #2563eb;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: #475569;
            border-color: #cbd5e1;
        }
        
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-color: #10b981;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border-color: #f59e0b;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-color: #ef4444;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 1rem;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        @media (max-width: 640px) {
            .modal-overlay {
                align-items: flex-end;
                padding: 0;
            }
            
            .modal-overlay.active .modal-content {
                animation: slideUp 0.3s ease;
                border-radius: 20px 20px 0 0;
                max-height: 90vh;
            }
            
            @keyframes slideUp {
                from { transform: translateY(100%); }
                to { transform: translateY(0); }
            }
        }

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
            white-space: nowrap;
        }
        
        .tab-button:hover { color: #2563eb; }
        .tab-button.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: linear-gradient(to top, rgba(37, 99, 235, 0.05), transparent);
        }

        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .touch-target {
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: #374151;
        }
        
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.15s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
            display: none;
        }
        
        .sidebar-overlay.active {
            display: block;
        }

        .currency-toggle {
            background: #f1f5f9;
            border-radius: 20px;
            padding: 4px;
            display: inline-flex;
            gap: 4px;
            margin-left: 10px;
        }
        
        .currency-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 16px;
            background: transparent;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .currency-btn.active {
            background: white;
            color: #2563eb;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .exchange-rate-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        /* Mobile button adjustments */
        @media (max-width: 640px) {
            .action-btn, .btn, button {
                font-size: 0.875rem !important;
                padding: 8px 12px !important;
            }
            
            .tab-button {
                font-size: 12px !important;
                padding: 10px 12px !important;
            }
            
            input, select, textarea {
                font-size: 16px !important;
                min-height: 44px;
            }
            
            /* Better touch targets for mobile */
            .touch-target {
                padding: 12px;
            }
        }

        /* Mobile table improvements */
        @media (max-width: 768px) {
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 0.5rem !important;
            }
            
            .overflow-x-auto {
                -webkit-overflow-scrolling: touch;
            }
        }

        /* Mobile icon sizing */
        @media (max-width: 640px) {
            i.fas {
                font-size: 0.875em;
            }
        }

        /* Mobile sidebar */
        @media (max-width: 1024px) {
            aside, [class*="sidebar"] {
                width: 280px !important;
            }
        }

        /* Prevent horizontal scrolling */
        @media (max-width: 640px) {
            html, body {
                overflow-x: hidden;
                max-width: 100%;
            }
        }

        /* Mobile-only classes */
        @media (max-width: 640px) {
            .xs-hidden { display: none !important; }
            .xs-block { display: block !important; }
            .xs-flex-col { flex-direction: column !important; }
            .xs-w-full { width: 100% !important; }
            .xs-text-center { text-align: center !important; }
            .xs-p-2 { padding: 0.5rem !important; }
            .xs-space-y-2 > * + * { margin-top: 0.5rem !important; }
            .xs-text-sm { font-size: 0.875rem !important; }
            .xs-text-xs { font-size: 0.75rem !important; }
        }

        /* Scrollbar hide */
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
    </style>
</head>
<body class="antialiased selection:bg-blue-100">

    <!-- Create Database Modal -->
    <div id="createDatabaseModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Create Database</h3>
                <button onclick="closeModal('createDatabaseModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-database text-blue-600 text-2xl"></i>
                </div>
                <p class="text-center text-slate-600 mb-2">Create database for <?php echo htmlspecialchars($school['name']); ?></p>
                <p class="text-center text-sm text-slate-500 mb-4">This will create the school's database with all necessary tables.</p>
                
                <div class="space-y-4">
                    <div>
                        <label class="form-label">Database Name</label>
                        <input type="text" id="dbName" class="form-input" value="<?php echo htmlspecialchars($school['database_name'] ?? 'school_' . $schoolId); ?>" readonly>
                    </div>
                    
                    <div>
                        <label class="form-label">Include Sample Data</label>
                        <select id="includeSampleData" class="form-input">
                            <option value="none">No sample data</option>
                            <option value="basic">Basic sample data (10 users, 5 classes)</option>
                            <option value="full">Full sample data (50 users, 15 classes, subjects, etc.)</option>
                        </select>
                    </div>
                    
                    <div class="p-4 bg-blue-50 rounded-lg">
                        <h4 class="font-bold text-blue-800 mb-2">What will be created:</h4>
                        <ul class="text-sm text-blue-700 space-y-1">
                            <li class="flex items-center gap-2">
                                <i class="fas fa-check-circle text-blue-600"></i>
                                <span>All core tables (users, classes, subjects, etc.)</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <i class="fas fa-check-circle text-blue-600"></i>
                                <span>Default admin account</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <i class="fas fa-check-circle text-blue-600"></i>
                                <span>Default school settings</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <i class="fas fa-check-circle text-blue-600"></i>
                                <span>Initial user roles and permissions</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-col xs:flex-row justify-end gap-3">
                <button onclick="closeModal('createDatabaseModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                    Cancel
                </button>
                <button onclick="createDatabase()" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                    Create Database
                </button>
            </div>
        </div>
    </div>
    <!-- Action Modals -->
    <div id="suspendModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Suspend School</h3>
                <button onclick="closeModal('suspendModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-amber-100 flex items-center justify-center">
                    <i class="fas fa-pause-circle text-amber-600 text-2xl"></i>
                </div>
                <p class="text-center text-slate-600 mb-2">Suspend <?php echo htmlspecialchars($school['name']); ?>?</p>
                <p class="text-center text-sm text-slate-500">The school will be temporarily disabled. Users won't be able to access the platform.</p>
                
                <div class="mt-4 p-4 bg-amber-50 rounded-lg">
                    <label class="flex items-center gap-3">
                        <input type="checkbox" id="notifySchool" class="rounded border-slate-300" checked>
                        <span class="text-sm text-slate-700">Notify school administrators via email</span>
                    </label>
                    <textarea id="suspendReason" 
                              class="w-full mt-3 p-3 border border-slate-300 rounded-lg text-sm"
                              rows="3"
                              placeholder="Optional: Reason for suspension (will be included in notification)"></textarea>
                </div>
            </div>
            
            <div class="flex flex-col xs:flex-row justify-end gap-3">
                <button onclick="closeModal('suspendModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                    Cancel
                </button>
                <button onclick="performAction('suspend')" class="w-full xs:w-auto px-6 py-3 bg-amber-600 text-white font-bold rounded-xl hover:bg-amber-700 transition touch-target">
                    Suspend School
                </button>
            </div>
        </div>
    </div>

    <div id="activateModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Activate School</h3>
                <button onclick="closeModal('activateModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-100 flex items-center justify-center">
                    <i class="fas fa-play-circle text-emerald-600 text-2xl"></i>
                </div>
                <p class="text-center text-slate-600 mb-2">Activate <?php echo htmlspecialchars($school['name']); ?>?</p>
                <p class="text-center text-sm text-slate-500">The school will be restored to active status. Users can access the platform immediately.</p>
            </div>
            
            <div class="flex flex-col xs:flex-row justify-end gap-3">
                <button onclick="closeModal('activateModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                    Cancel
                </button>
                <button onclick="performAction('activate')" class="w-full xs:w-auto px-6 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition touch-target">
                    Activate School
                </button>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Delete School</h3>
                <button onclick="closeModal('deleteModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <p class="text-center text-slate-600 mb-2">Delete <?php echo htmlspecialchars($school['name']); ?> permanently?</p>
                <p class="text-center text-sm text-slate-500 mb-4">This action cannot be undone. All school data will be permanently removed.</p>
                
                <div class="p-4 bg-red-50 rounded-lg border border-red-200">
                    <p class="font-bold text-red-800 mb-2">Warning: This will delete:</p>
                    <ul class="text-sm text-red-700 space-y-1">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-database"></i>
                            <span>School database (<?php echo htmlspecialchars($school['database_name'] ?? 'N/A'); ?>)</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-users"></i>
                            <span>All user accounts (<?php echo $schoolStats['total_users']; ?> users)</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-file-alt"></i>
                            <span>All academic records and files</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-history"></i>
                            <span>All activity logs and history</span>
                        </li>
                    </ul>
                </div>
                
                <div class="mt-4">
                    <label class="flex items-center gap-3 text-sm text-slate-700">
                        <input type="checkbox" id="confirmDelete" class="rounded border-slate-300">
                        <span>I understand this action is irreversible</span>
                    </label>
                </div>
            </div>
            
            <div class="flex flex-col xs:flex-row justify-end gap-3">
                <button onclick="closeModal('deleteModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                    Cancel
                </button>
                <button onclick="performAction('delete')" class="w-full xs:w-auto px-6 py-3 bg-red-600 text-white font-bold rounded-xl hover:bg-red-700 transition touch-target" disabled id="deleteBtn">
                    Delete Permanently
                </button>
            </div>
        </div>
    </div>

    <div id="extendModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Extend Subscription</h3>
                <button onclick="closeModal('extendModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-calendar-plus text-blue-600 text-2xl"></i>
                </div>
                <p class="text-center text-slate-600 mb-4">Extend subscription for <?php echo htmlspecialchars($school['name']); ?></p>
                
                <div class="space-y-4">
                    <div>
                        <label class="form-label">Extension Period</label>
                        <select id="extensionPeriod" class="form-input">
                            <option value="7">7 days</option>
                            <option value="30">30 days</option>
                            <option value="90">90 days</option>
                            <option value="180">6 months</option>
                            <option value="365">1 year</option>
                            <option value="custom">Custom days</option>
                        </select>
                    </div>
                    
                    <div id="customDaysContainer" class="hidden">
                        <label class="form-label">Custom Days</label>
                        <input type="number" id="customDays" class="form-input" min="1" max="3650" placeholder="Enter number of days">
                    </div>
                    
                    <div>
                        <label class="form-label">Reason (Optional)</label>
                        <textarea id="extensionReason" class="form-input" rows="3" placeholder="Reason for extension"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-col xs:flex-row justify-end gap-3">
                <button onclick="closeModal('extendModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                    Cancel
                </button>
                <button onclick="performAction('extend')" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                    Extend Subscription
                </button>
            </div>
        </div>
    </div>

    <div id="invoiceModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Invoice Management</h3>
                <button onclick="closeModal('invoiceModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <?php if ($invoice): ?>
                <div class="info-card mb-4">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <p class="text-sm font-bold text-slate-500 uppercase">Invoice #<?php echo $invoice['invoice_number']; ?></p>
                            <p class="text-lg font-black text-slate-900 invoice-amount" data-usd="<?php echo number_format($invoice['amount'], 2); ?>" data-ngn="<?php echo number_format($invoiceAmountNGN, 2); ?>">
                                <?php echo formatNaira($invoiceAmountNGN, 2); ?>
                            </p>
                            <p class="text-xs text-slate-500 mt-1 usd-equivalent">
                                ≈ <?php echo formatUsd($invoice['amount'], 2); ?>
                            </p>
                        </div>
                        <span class="badge <?php echo $invoice['status'] == 'paid' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo ucfirst($invoice['status']); ?>
                        </span>
                    </div>
                    <div class="space-y-2 text-sm text-slate-600">
                        <p><span class="font-bold">Due Date:</span> <?php echo date('F j, Y', strtotime($invoice['due_date'])); ?></p>
                        <p><span class="font-bold">Period:</span> <?php echo date('M j', strtotime($invoice['start_date'])); ?> - <?php echo date('M j, Y', strtotime($invoice['end_date'])); ?></p>
                        <p><span class="font-bold">Plan:</span> <?php echo $school['plan_name'] ?? 'N/A'; ?></p>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <button onclick="performAction('approve_invoice')" class="w-full action-btn btn-success touch-target">
                        <i class="fas fa-check-circle mr-2"></i> Approve & Mark as Paid
                    </button>
                    <button onclick="performAction('reject_invoice')" class="w-full action-btn btn-danger touch-target">
                        <i class="fas fa-times-circle mr-2"></i> Reject Invoice
                    </button>
                    <button onclick="performAction('resend_invoice')" class="w-full action-btn btn-secondary touch-target">
                        <i class="fas fa-paper-plane mr-2"></i> Resend to School
                    </button>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-slate-100 flex items-center justify-center">
                        <i class="fas fa-file-invoice text-slate-400 text-2xl"></i>
                    </div>
                    <p class="text-slate-600 mb-2">No invoice found for this school</p>
                    <p class="text-sm text-slate-500">Generate an invoice to continue</p>
                    <button onclick="performAction('generate_invoice')" class="mt-4 px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                        Generate Invoice
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Insert Data Modal -->
    <div id="insertDataModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Insert Data from File</h3>
                <button onclick="closeModal('insertDataModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-file-import text-blue-600 text-2xl"></i>
                </div>
                <p class="text-center text-slate-600 mb-4">Import data from CSV/Excel file</p>
                
                <div class="space-y-4">
                    <!-- Target Table Selection -->
                    <div>
                        <label class="form-label">Target Table</label>
                        <select id="targetTable" class="form-input text-sm">
                            <option value="all">All Tables (Full Import)</option>
                            <optgroup label="User Tables">
                                <option value="users">Users</option>
                                <option value="students">Students</option>
                                <option value="teachers">Teachers</option>
                                <option value="parents">Parents</option>
                                <option value="admins">Admins</option>
                            </optgroup>
                            <optgroup label="Academic Tables">
                                <option value="classes">Classes</option>
                                <option value="subjects">Subjects</option>
                                <option value="academic_years">Academic Years</option>
                                <option value="academic_terms">Academic Terms</option>
                            </optgroup>
                            <optgroup label="Other Tables">
                                <option value="attendance">Attendance</option>
                                <option value="exams">Exams</option>
                                <option value="exam_grades">Exam Grades</option>
                                <option value="homework">Homework</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <!-- File Upload -->
                    <div>
                        <label class="form-label">Upload File</label>
                        <div id="dropZone" class="border-2 border-dashed border-slate-300 rounded-lg p-6 text-center cursor-pointer hover:border-blue-500 transition">
                            <input type="file" id="dataFile" class="hidden" accept=".csv">
                            <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-3"></i>
                            <p class="text-sm text-slate-600 mb-1">Drag & drop your file here</p>
                            <p class="text-xs text-slate-500">or click to browse</p>
                            <p class="text-xs text-slate-400 mt-2">Supports CSV files up to 10MB</p>
                        </div>
                        <div id="fileInfo" class="hidden mt-3 p-3 bg-slate-50 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-file text-slate-600"></i>
                                    <span id="fileName" class="text-sm font-medium text-slate-700"></span>
                                </div>
                                <button type="button" onclick="removeFile()" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="text-xs text-slate-500 mt-1" id="fileSize"></div>
                        </div>
                    </div>
                    
                    <!-- Import Options -->
                    <div>
                        <label class="form-label mb-2">Import Options</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" id="hasHeaders" class="rounded border-slate-300" checked>
                                <span class="text-sm text-slate-700">File contains headers</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" id="skipDuplicates" class="rounded border-slate-300" checked>
                                <span class="text-sm text-slate-700">Skip duplicate records</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" id="sendNotification" class="rounded border-slate-300" checked>
                                <span class="text-sm text-slate-700">Send completion notification</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Preview Area -->
                    <div id="previewArea" class="hidden">
                        <label class="form-label">Data Preview (First 5 rows)</label>
                        <div class="overflow-x-auto border border-slate-200 rounded-lg">
                            <table class="min-w-full text-xs" id="previewTable">
                                <!-- Preview will be inserted here -->
                            </table>
                        </div>
                        <p class="text-xs text-slate-500 mt-2" id="previewInfo"></p>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-col xs:flex-row justify-end gap-3">
                <button onclick="closeModal('insertDataModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target text-sm">
                    Cancel
                </button>
                <button onclick="importData()" id="importBtn" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target text-sm" disabled>
                    <i class="fas fa-upload mr-2"></i> Import Data
                </button>
            </div>
        </div>
    </div>

    <!-- Edit School User Modal -->
    <div id="editUserModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Edit School User</h3>
                <button onclick="closeModal('editUserModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            <input type="hidden" id="editUserId">
            <div class="space-y-4">
                <div>
                    <label class="form-label">Name</label>
                    <input id="editUserName" class="form-input text-sm" type="text">
                </div>
                <div>
                    <label class="form-label">Email</label>
                    <input id="editUserEmail" class="form-input text-sm" type="email">
                </div>
                <div>
                    <label class="form-label">Phone</label>
                    <input id="editUserPhone" class="form-input text-sm" type="text">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">User Type</label>
                        <select id="editUserType" class="form-input text-sm">
                            <option value="admin">Admin</option>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                            <option value="parent">Parent</option>
                            <option value="accountant">Accountant</option>
                            <option value="librarian">Librarian</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select id="editUserActive" class="form-input text-sm">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label">New Password</label>
                    <input id="editUserPassword" class="form-input text-sm" type="password" placeholder="Leave blank to keep current password">
                </div>
            </div>
            <div class="flex flex-col xs:flex-row justify-end gap-3 mt-6">
                <button onclick="closeModal('editUserModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target text-sm">Cancel</button>
                <button onclick="saveSchoolUser()" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target text-sm">
                    <i class="fas fa-save mr-2"></i> Save User
                </button>
            </div>
        </div>
    </div>

     <!-- Sidebar Overlay (for mobile) -->
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="mobileSidebarToggle()"></div>

    <div class="flex min-h-screen">

        <?php 
        // Include sidebar
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include $sidebarPath;
        } else {
            echo '<div class="w-64 bg-white border-r border-slate-200 p-4 hidden lg:block">Sidebar not found</div>';
        }
        ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- Fixed Header (Desktop & Mobile) -->
            <header class="fixed-header px-4 lg:px-8 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <a href="index.php" class="text-slate-400 hover:text-slate-600 touch-target p-2">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest truncate-mobile" style="max-width: 200px;">
                            Manage School
                        </h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-clock hidden xs:inline"></i>
                        <span id="timestamp" class="truncate-mobile" style="max-width: 120px;"><?php echo date('h:i A'); ?></span>
                        <span class="exchange-rate-badge hidden sm:inline" title="Current exchange rate" id="exchangeRateBadge">
                            $1 = ₦<?php echo number_format($exchangeRate, 0); ?>
                        </span>
                    </div>
                    <div class="currency-toggle" id="currencyToggle">
                        <button class="currency-btn active" data-currency="NGN">NGN</button>
                        <button class="currency-btn" data-currency="USD">USD</button>
                    </div>
                </div>
            </header>

            <!-- Main Content Area (scrolls under fixed header) -->
            <div class="main-content">
                <!-- School Header -->
                <div class="school-header border-b border-slate-200 bg-white">
                    <div class="max-w-7xl mx-auto px-4 lg:px-8 py-4 lg:py-6">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 lg:w-16 lg:h-16 rounded-xl lg:rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-university text-white text-lg lg:text-2xl"></i>
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-lg lg:text-2xl font-black text-slate-900 mb-1 truncate"><?php echo htmlspecialchars($school['name']); ?></h2>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="status-badge <?php echo $statusInfo['color']; ?> text-xs">
                                            <i class="fas <?php echo $statusInfo['icon']; ?> text-[8px] lg:text-[10px] mr-1 lg:mr-2"></i>
                                            <?php echo $statusInfo['label']; ?>
                                            <?php if ($isTrial): ?> (Trial)<?php endif; ?>
                                        </span>
                                        <span class="text-xs lg:text-sm text-slate-500 font-medium whitespace-nowrap">
                                            <i class="fas fa-hashtag mr-1"></i><?php echo $school['id']; ?>
                                        </span>
                                        <span class="text-xs lg:text-sm text-slate-500 font-medium whitespace-nowrap">
                                            <i class="fas fa-calendar-day mr-1"></i>Joined <?php echo $createdDate; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-2 lg:gap-3 justify-start lg:justify-end">
                                <?php if (!$databaseExists): ?>
                                <button onclick="openModal('createDatabaseModal')" class="action-btn btn-success touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-database mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Create DB</span>
                                </button>
                                <?php endif; ?>
                                <button onclick="openModal('invoiceModal')" class="action-btn btn-primary touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-file-invoice-dollar mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Invoice</span>
                                </button>
                                <button onclick="openModal('insertDataModal')" class="action-btn btn-success touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-file-import mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Insert Data</span>
                                </button>
                                <button onclick="openModal('extendModal')" class="action-btn btn-success touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-calendar-plus mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Extend</span>
                                </button>
                                <?php if ($currentStatus !== 'suspended'): ?>
                                <button onclick="openModal('suspendModal')" class="action-btn btn-warning touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-pause-circle mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Suspend</span>
                                </button>
                                <?php else: ?>
                                <button onclick="openModal('activateModal')" class="action-btn btn-success touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-play-circle mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Activate</span>
                                </button>
                                <?php endif; ?>
                                <button onclick="openModal('deleteModal')" class="action-btn btn-danger touch-target text-xs lg:text-sm px-3 lg:px-4 py-2 lg:py-2.5">
                                    <i class="fas fa-trash mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                    <span class="hidden sm:inline">Delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="border-b border-slate-200 bg-white">
                    <div class="max-w-7xl mx-auto px-2 lg:px-8">
                        <div class="flex overflow-x-auto scrollbar-hide -mx-2 px-2">
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3 active" onclick="switchTab('overview')">
                                <i class="fas fa-chart-bar mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Overview</span>
                            </button>
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3" onclick="switchTab('subscription')">
                                <i class="fas fa-credit-card mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Subscription</span>
                            </button>
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3" onclick="switchTab('database')">
                                <i class="fas fa-database mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Database</span>
                            </button>
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3" onclick="switchTab('insert')">
                                <i class="fas fa-file-import mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Insert Data</span>
                            </button>
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3" onclick="switchTab('users')">
                                <i class="fas fa-users mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Users</span>
                            </button>
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3" onclick="switchTab('settings')">
                                <i class="fas fa-cog mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Settings</span>
                            </button>
                            <button class="tab-button text-xs lg:text-sm px-3 lg:px-6 py-2 lg:py-3" onclick="switchTab('activity')">
                                <i class="fas fa-history mr-1 lg:mr-2 text-xs lg:text-sm"></i>
                                <span>Activity</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tab Content -->
                <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                    <!-- Tab Content: Overview -->
                    <div id="overview" class="tab-content active">
                        <div class="max-w-7xl mx-auto space-y-6">
                            <!-- Stats Cards -->
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
                                <div class="detail-card p-4 lg:p-6 hover-lift">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Total Users</p>
                                            <p class="text-xl lg:text-2xl font-black text-slate-900"><?php echo $schoolStats['total_users']; ?></p>
                                        </div>
                                        <div class="w-10 h-10 lg:w-12 lg:h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-users text-blue-600 text-sm lg:text-lg"></i>
                                        </div>
                                    </div>
                                    <div class="mt-3 lg:mt-4">
                                        <p class="text-xs lg:text-sm text-slate-600">
                                            <span class="font-bold text-emerald-600"><?php echo $schoolStats['active_users']; ?></span> active users
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="detail-card p-4 lg:p-6 hover-lift">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Database</p>
                                            <p class="text-xl lg:text-2xl font-black text-slate-900"><?php echo number_format($schoolStats['database_size'], 2); ?> MB</p>
                                        </div>
                                        <div class="w-10 h-10 lg:w-12 lg:h-12 rounded-xl bg-purple-100 flex items-center justify-center">
                                            <i class="fas fa-database text-purple-600 text-sm lg:text-lg"></i>
                                        </div>
                                    </div>
                                    <div class="mt-3 lg:mt-4">
                                        <p class="text-xs lg:text-sm text-slate-600">
                                            <span class="font-bold"><?php echo $dbStats['tables']; ?></span> tables, 
                                            <span class="font-bold"><?php echo $dbStats['rows']; ?></span> rows
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="detail-card p-4 lg:p-6 hover-lift">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Storage Usage</p>
                                            <p class="text-xl lg:text-2xl font-black text-slate-900"><?php echo number_format($storageUsedGB, 2); ?> GB</p>
                                        </div>
                                        <div class="w-10 h-10 lg:w-12 lg:h-12 rounded-xl bg-emerald-100 flex items-center justify-center">
                                            <i class="fas fa-hdd text-emerald-600 text-sm lg:text-lg"></i>
                                        </div>
                                    </div>
                                    <div class="mt-3 lg:mt-4">
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="text-slate-600"><?php echo number_format($storagePercentage, 1); ?>% used</span>
                                            <span class="font-bold">10 GB limit</span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar <?php echo $storagePercentage > 90 ? 'progress-danger' : ($storagePercentage > 70 ? 'progress-warning' : 'progress-success'); ?>" 
                                                  style="width: <?php echo $storagePercentage; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-card p-4 lg:p-6 hover-lift">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Last Active</p>
                                            <p class="text-xl lg:text-2xl font-black text-slate-900">
                                                <?php if ($schoolStats['last_login']): ?>
                                                    <?php echo date('M j', strtotime($schoolStats['last_login'])); ?>
                                                <?php else: ?>
                                                    Never
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="w-10 h-10 lg:w-12 lg:h-12 rounded-xl bg-amber-100 flex items-center justify-center">
                                            <i class="fas fa-sign-in-alt text-amber-600 text-sm lg:text-lg"></i>
                                        </div>
                                    </div>
                                    <div class="mt-3 lg:mt-4">
                                        <p class="text-xs lg:text-sm text-slate-600">
                                            <?php if ($schoolStats['last_login']): ?>
                                                <?php echo date('h:i A', strtotime($schoolStats['last_login'])); ?>
                                            <?php else: ?>
                                                No login activity
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- User Breakdown & Database Status -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6">
                                <!-- User Breakdown -->
                                <div class="detail-card p-4 lg:p-6">
                                    <h3 class="text-base lg:text-lg font-bold text-slate-900 mb-3 lg:mb-4">User Breakdown</h3>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 lg:gap-4">
                                        <div class="text-center p-3 lg:p-4 bg-blue-50 rounded-xl">
                                            <p class="text-xl lg:text-2xl font-black text-blue-600"><?php echo $schoolStats['admins']; ?></p>
                                            <p class="text-xs font-bold text-slate-500 uppercase">Admins</p>
                                        </div>
                                        <div class="text-center p-3 lg:p-4 bg-emerald-50 rounded-xl">
                                            <p class="text-xl lg:text-2xl font-black text-emerald-600"><?php echo $schoolStats['teachers']; ?></p>
                                            <p class="text-xs font-bold text-slate-500 uppercase">Teachers</p>
                                        </div>
                                        <div class="text-center p-3 lg:p-4 bg-purple-50 rounded-xl">
                                            <p class="text-xl lg:text-2xl font-black text-purple-600"><?php echo $schoolStats['students']; ?></p>
                                            <p class="text-xs font-bold text-slate-500 uppercase">Students</p>
                                        </div>
                                        <div class="text-center p-3 lg:p-4 bg-amber-50 rounded-xl">
                                            <p class="text-xl lg:text-2xl font-black text-amber-600"><?php echo $schoolStats['parents']; ?></p>
                                            <p class="text-xs font-bold text-slate-500 uppercase">Parents</p>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid grid-cols-2 gap-3 lg:gap-4">
                                        <div class="text-center p-3 bg-slate-50 rounded-lg">
                                            <p class="text-lg font-black text-slate-700"><?php echo $schoolStats['classes']; ?></p>
                                            <p class="text-xs text-slate-500">Classes</p>
                                        </div>
                                        <div class="text-center p-3 bg-slate-50 rounded-lg">
                                            <p class="text-lg font-black text-slate-700"><?php echo $schoolStats['subjects']; ?></p>
                                            <p class="text-xs text-slate-500">Subjects</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Database Status -->
                                <div class="detail-card p-4 lg:p-6">
                                    <h3 class="text-base lg:text-lg font-bold text-slate-900 mb-3 lg:mb-4">Database Status</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <p class="text-sm font-bold text-slate-700 mb-1">Database Name</p>
                                            <div class="flex flex-col xs:flex-row xs:items-center xs:justify-between gap-2">
                                                <code class="text-sm bg-slate-100 px-3 py-1 rounded truncate"><?php echo htmlspecialchars($school['database_name'] ?? 'Not created'); ?></code>
                                                <span class="badge <?php echo $databaseExists ? 'badge-success' : 'badge-warning'; ?> whitespace-nowrap">
                                                    <?php echo $databaseExists ? 'Online' : 'Offline'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-3 gap-3 lg:gap-4">
                                            <div class="text-center p-3 bg-slate-50 rounded-lg">
                                                <p class="text-lg font-black text-slate-700"><?php echo $dbStats['tables']; ?></p>
                                                <p class="text-xs text-slate-500">Tables</p>
                                            </div>
                                            <div class="text-center p-3 bg-slate-50 rounded-lg">
                                                <p class="text-lg font-black text-slate-700"><?php echo number_format($dbStats['rows']); ?></p>
                                                <p class="text-xs text-slate-500">Rows</p>
                                            </div>
                                            <div class="text-center p-3 bg-slate-50 rounded-lg">
                                                <p class="text-lg font-black text-slate-700"><?php echo number_format($schoolStats['database_size'], 1); ?> MB</p>
                                                <p class="text-xs text-slate-500">Size</p>
                                            </div>
                                        </div>
                                        
                                        <?php if (!$databaseExists): ?>
                                        <div class="p-4 bg-amber-50 rounded-lg border border-amber-200">
                                            <div class="flex items-center gap-3">
                                                <i class="fas fa-exclamation-triangle text-amber-600"></i>
                                                <div class="flex-1">
                                                    <p class="font-bold text-amber-800">Database Not Created</p>
                                                    <p class="text-sm text-amber-700">This school doesn't have a database yet.</p>
                                                </div>
                                                <button onclick="openModal('createDatabaseModal')" class="px-4 py-2 bg-amber-600 text-white font-bold rounded-lg hover:bg-amber-700 transition text-sm whitespace-nowrap">
                                                    Create Database
                                                </button>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <div class="pt-4 border-t border-slate-200">
                                            <div class="flex flex-col xs:flex-row gap-2 xs:gap-3">
                                                <button onclick="performAction('backup_db')" class="flex-1 action-btn btn-secondary touch-target text-sm">
                                                    <i class="fas fa-download mr-2"></i> Backup
                                                </button>
                                                <button onclick="performAction('optimize_db')" class="flex-1 action-btn btn-secondary touch-target text-sm">
                                                    <i class="fas fa-wrench mr-2"></i> Optimize
                                                </button>
                                                <button onclick="performAction('reset_db')" class="flex-1 action-btn btn-danger touch-target text-sm">
                                                    <i class="fas fa-trash mr-2"></i> Reset
                                                </button>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- School Information -->
                            <div class="detail-card p-4 lg:p-6">
                                <h3 class="text-base lg:text-lg font-bold text-slate-900 mb-3 lg:mb-4">School Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Contact Email</p>
                                        <p class="text-sm font-medium text-slate-900 truncate"><?php echo htmlspecialchars($school['email']); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Phone</p>
                                        <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($school['phone'] ?? 'Not specified'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Location</p>
                                        <p class="text-sm font-medium text-slate-900">
                                            <?php echo htmlspecialchars($school['city'] ?? ''); ?>, <?php echo htmlspecialchars($school['state'] ?? ''); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Created</p>
                                        <p class="text-sm font-medium text-slate-900"><?php echo $createdDate; ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Plan</p>
                                        <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($school['plan_name'] ?? 'No Plan'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Billing Cycle</p>
                                        <p class="text-sm font-medium text-slate-900"><?php echo ucfirst($school['billing_cycle'] ?? 'monthly'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                     <!-- Tab Content: Subscription -->
                <div id="subscription" class="tab-content">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <div class="detail-card p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-slate-900">Subscription Details</h3>
                                <div class="flex gap-3">
                                    <button onclick="openModal('extendModal')" class="action-btn btn-success touch-target">
                                        <i class="fas fa-calendar-plus mr-2"></i> Extend
                                    </button>
                                    <button onclick="openModal('invoiceModal')" class="action-btn btn-primary touch-target">
                                        <i class="fas fa-file-invoice-dollar mr-2"></i> Invoice
                                    </button>
                                </div>
                            </div>

                            <?php if ($school['plan_name']): ?>
                            <div class="space-y-6">
                                <!-- Current Plan -->
                                <div class="p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-100">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h4 class="text-xl font-black text-slate-900 mb-1"><?php echo htmlspecialchars($school['plan_name']); ?></h4>
                                            <p class="text-sm text-slate-600">Current <?php echo $isTrial ? 'trial' : 'active'; ?> plan</p>
                                        </div>
                                        <span class="badge <?php echo $school['subscription_status'] == 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                            <?php echo ucfirst($school['subscription_status'] ?? 'pending'); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Billing Cycle</p>
                                            <p class="text-sm font-medium text-slate-900"><?php echo ucfirst($school['billing_cycle'] ?? 'monthly'); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Started</p>
                                            <p class="text-sm font-medium text-slate-900">
                                                <?php echo $school['current_period_start'] ? date('F j, Y', strtotime($school['current_period_start'])) : 'Not started'; ?>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Renews</p>
                                            <p class="text-sm font-medium text-slate-900"><?php echo $subscriptionEnd; ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Monthly Amount</p>
                                            <p class="text-2xl font-black text-slate-900 monthly-amount" data-usd="<?php echo formatUsd($school['price_monthly'] ?? 0, 2); ?>" data-ngn="<?php echo formatNaira($priceMonthlyNGN, 2); ?>">
                                                <?php echo formatNaira($priceMonthlyNGN, 2); ?>
                                                <span class="text-sm font-normal text-slate-500">/month</span>
                                            </p>
                                            <p class="text-xs text-slate-500 mt-1 monthly-equivalent">
                                                ≈ <?php echo formatUsd($school['price_monthly'] ?? 0, 2); ?> USD
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Days until renewal</p>
                                            <p class="text-2xl font-black <?php echo $daysUntilRenewal <= 7 ? 'text-red-600' : 'text-emerald-600'; ?>">
                                                <?php echo $daysUntilRenewal > 0 ? $daysUntilRenewal : 'Expired'; ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 pt-4 border-t border-blue-200">
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <p class="text-sm font-bold text-slate-700">Annual Value</p>
                                                <p class="text-lg font-black text-slate-900 annual-amount" data-usd="<?php echo formatUsd(($school['price_monthly'] ?? 0) * 12, 2); ?>" data-ngn="<?php echo formatNaira($annualPriceNGN, 2); ?>">
                                                    <?php echo formatNaira($annualPriceNGN, 2); ?>
                                                    <span class="text-xs font-normal text-slate-500">/year</span>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-sm font-bold text-slate-700">Exchange Rate</p>
                                                <p class="text-sm text-slate-600">$1 = ₦<?php echo number_format($exchangeRate, 0); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($isTrial && $school['trial_ends_at']): ?>
                                    <div class="mt-4 p-4 bg-amber-50 rounded-lg border border-amber-200">
                                        <div class="flex items-center gap-3">
                                            <i class="fas fa-clock text-amber-600"></i>
                                            <div>
                                                <p class="font-bold text-amber-800">Trial Period Active</p>
                                                <p class="text-sm text-amber-700">
                                                    Trial ends on <?php echo date('F j, Y', strtotime($school['trial_ends_at'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Plan Features -->
                                <?php if (!empty($school['features'])): ?>
                                <div>
                                    <h4 class="text-lg font-bold text-slate-900 mb-4">Plan Features</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                        <?php 
                                        $features = json_decode($school['features'], true) ?: [];
                                        foreach ($features as $feature): 
                                        ?>
                                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                                            <i class="fas fa-check text-emerald-600"></i>
                                            <span class="text-sm text-slate-700"><?php echo htmlspecialchars($feature); ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-12">
                                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-slate-100 flex items-center justify-center">
                                    <i class="fas fa-credit-card text-slate-400 text-3xl"></i>
                                </div>
                                <h4 class="text-xl font-black text-slate-900 mb-2">No Active Subscription</h4>
                                <p class="text-slate-600 mb-6">This school is not currently subscribed to any plan</p>
                                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                    <button onclick="openModal('extendModal')" class="px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                        <i class="fas fa-plus mr-2"></i> Add Subscription
                                    </button>
                                    <button onclick="window.location.href='../plans/index.php'" class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                        <i class="fas fa-layer-group mr-2"></i> View Plans
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                    <!-- Tab Content: Database -->
                    <div id="database" class="tab-content">
                        <div class="max-w-7xl mx-auto space-y-6">
                            <div class="detail-card p-4 lg:p-6">
                                <h3 class="text-base lg:text-lg font-bold text-slate-900 mb-4 lg:mb-6">Database Management</h3>
                                
                                <?php if (!$databaseExists): ?>
                                <!-- Database Creation Prompt -->
                                <div class="p-6 lg:p-8 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-100 text-center">
                                    <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-database text-blue-600 text-3xl"></i>
                                    </div>
                                    <h4 class="text-xl font-black text-slate-900 mb-3">Database Not Created</h4>
                                    <p class="text-slate-600 mb-6">This school doesn't have a database yet. You need to create the database before managing it.</p>
                                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                        <button onclick="openModal('createDatabaseModal')" class="px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                            <i class="fas fa-database mr-2"></i> Create Database
                                        </button>
                                        <button onclick="performAction('create_db_advanced')" class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                            <i class="fas fa-cogs mr-2"></i> Advanced Setup
                                        </button>
                                    </div>
                                </div>
                                <?php else: ?>
                                <!-- Database Content when exists -->
                                <div class="space-y-6">
                                    <!-- Database Status -->
                                    <div class="p-6 bg-gradient-to-r from-purple-50 to-indigo-50 rounded-2xl border border-purple-100">
                                        <div class="flex flex-col lg:flex-row lg:justify-between lg:items-start gap-4 mb-4">
                                            <div>
                                                <h4 class="text-xl font-black text-slate-900 mb-1">Database Information</h4>
                                                <p class="text-sm text-slate-600">Complete schema with all educational tables</p>
                                            </div>
                                            <span class="badge badge-success self-start">
                                                Online
                                            </span>
                                        </div>
                                        
                                        <div class="space-y-4">
                                            <div>
                                                <p class="text-sm font-bold text-slate-700 mb-1">Database Name</p>
                                                <div class="flex flex-col xs:flex-row xs:items-center gap-3">
                                                    <code class="flex-1 text-sm bg-white px-3 py-2 rounded border border-purple-200 font-mono truncate">
                                                        <?php echo htmlspecialchars($school['database_name'] ?? 'Not created'); ?>
                                                    </code>
                                                    <button onclick="copyToClipboard('<?php echo htmlspecialchars($school['database_name'] ?? ''); ?>')" 
                                                            class="px-3 py-2 bg-purple-100 text-purple-600 rounded-lg hover:bg-purple-200 transition touch-target">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 lg:gap-6">
                                                <div class="p-4 bg-white rounded-xl border border-purple-100">
                                                    <p class="text-xs font-bold text-slate-500 uppercase mb-1">Tables</p>
                                                    <p class="text-2xl font-black text-purple-600"><?php echo $dbStats['tables']; ?></p>
                                                </div>
                                                <div class="p-4 bg-white rounded-xl border border-purple-100">
                                                    <p class="text-xs font-bold text-slate-500 uppercase mb-1">Rows</p>
                                                    <p class="text-2xl font-black text-purple-600"><?php echo number_format($dbStats['rows']); ?></p>
                                                </div>
                                                <div class="p-4 bg-white rounded-xl border border-purple-100">
                                                    <p class="text-xs font-bold text-slate-500 uppercase mb-1">Size</p>
                                                    <p class="text-2xl font-black text-purple-600"><?php echo number_format($schoolStats['database_size'], 1); ?> MB</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Database Actions -->
                                    <div>
                                        <h4 class="text-base lg:text-lg font-bold text-slate-900 mb-4">Database Actions</h4>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 lg:gap-4">
                                            <button onclick="performAction('backup_db')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                                        <i class="fas fa-download text-blue-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-slate-900">Backup Database</p>
                                                        <p class="text-sm text-slate-500">Create full database backup</p>
                                                    </div>
                                                </div>
                                            </button>
                                            
                                            <button onclick="performAction('optimize_db')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                                        <i class="fas fa-wrench text-emerald-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-slate-900">Optimize Tables</p>
                                                        <p class="text-sm text-slate-500">Optimize and repair tables</p>
                                                    </div>
                                                </div>
                                            </button>
                                            
                                            <button onclick="performAction('check_tables')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                                        <i class="fas fa-search text-amber-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-slate-900">Check Tables</p>
                                                        <p class="text-sm text-slate-500">Verify table integrity</p>
                                                    </div>
                                                </div>
                                            </button>
                                            
                                            <button onclick="performAction('export_schema')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                                        <i class="fas fa-file-export text-purple-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-slate-900">Export Schema</p>
                                                        <p class="text-sm text-slate-500">Export database structure</p>
                                                    </div>
                                                </div>
                                            </button>
                                            
                                            <button onclick="performAction('import_data')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
                                                        <i class="fas fa-file-import text-indigo-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-slate-900">Import Data</p>
                                                        <p class="text-sm text-slate-500">Import sample or backup data</p>
                                                    </div>
                                                </div>
                                            </button>
                                            
                                            <button onclick="performAction('reset_db')" class="p-4 border border-red-200 rounded-xl hover:bg-red-50 transition text-left">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                                                        <i class="fas fa-trash text-red-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-red-800">Reset Database</p>
                                                        <p class="text-sm text-red-600">Wipe all school data</p>
                                                    </div>
                                                </div>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Table List -->
                                    <div>
                                        <h4 class="text-base lg:text-lg font-bold text-slate-900 mb-4">Database Tables</h4>
                                        <?php if ($dbStats['tables'] > 0): ?>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                            <?php 
                                            // List of core tables from your schema
                                            $coreTables = [
                                                'users', 'students', 'teachers', 'classes', 'subjects',
                                                'academic_years', 'academic_terms', 'attendance',
                                                'exams', 'exam_grades', 'fee_structures', 'invoices',
                                                'payments', 'homework', 'timetables', 'announcements',
                                                'events', 'settings', 'roles', 'user_roles'
                                            ];
                                            
                                            foreach ($coreTables as $table): ?>
                                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                                                <div class="flex items-center gap-2">
                                                    <i class="fas fa-table text-slate-400"></i>
                                                    <span class="text-sm font-medium text-slate-700"><?php echo $table; ?></span>
                                                </div>
                                                <span class="text-xs text-slate-500">✓</span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-center py-8">
                                            <p class="text-slate-500">No database tables found</p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <!-- Tab Content: Insert Data -->
                <div id="insert" class="tab-content">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <div class="detail-card p-4 sm:p-6">
                            <div class="flex justify-between items-center mb-4 sm:mb-6">
                                <h3 class="text-base sm:text-lg font-bold text-slate-900">Data Import Tool</h3>
                                <button onclick="openModal('insertDataModal')" class="action-btn btn-primary touch-target text-sm">
                                    <i class="fas fa-file-import mr-2"></i> Import File
                                </button>
                            </div>
                            
                            <div class="space-y-6">
                                <!-- Quick Import -->
                                <div class="p-4 sm:p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-100">
                                    <h4 class="text-base sm:text-lg font-bold text-slate-900 mb-3">Quick Import Options</h4>
                                    <p class="text-xs sm:text-sm text-slate-600 mb-4">Select a table to import sample data or use your own file</p>
                                    
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                        <button onclick="quickImport('users')" class="p-3 border border-slate-200 rounded-xl hover:bg-white transition text-left bg-white">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-users text-blue-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900 text-sm">Users</p>
                                                    <p class="text-xs text-slate-500">Import user accounts</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="quickImport('students')" class="p-3 border border-slate-200 rounded-xl hover:bg-white transition text-left bg-white">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
                                                    <i class="fas fa-graduation-cap text-emerald-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900 text-sm">Students</p>
                                                    <p class="text-xs text-slate-500">Import student data</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="quickImport('teachers')" class="p-3 border border-slate-200 rounded-xl hover:bg-white transition text-left bg-white">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center">
                                                    <i class="fas fa-chalkboard-teacher text-purple-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900 text-sm">Teachers</p>
                                                    <p class="text-xs text-slate-500">Import teacher data</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="quickImport('classes')" class="p-3 border border-slate-200 rounded-xl hover:bg-white transition text-left bg-white">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center">
                                                    <i class="fas fa-chalkboard text-amber-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900 text-sm">Classes</p>
                                                    <p class="text-xs text-slate-500">Import class structure</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="quickImport('subjects')" class="p-3 border border-slate-200 rounded-xl hover:bg-white transition text-left bg-white">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center">
                                                    <i class="fas fa-book text-red-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900 text-sm">Subjects</p>
                                                    <p class="text-xs text-slate-500">Import subject list</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="quickImport('all')" class="p-3 border border-blue-200 rounded-xl hover:bg-blue-50 transition text-left bg-blue-50">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-layer-group text-blue-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-blue-800 text-sm">Full Database</p>
                                                    <p class="text-xs text-blue-600">Complete school setup</p>
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </div>

                                <!-- Recent Imports -->
                                <div>
                                    <h4 class="text-base sm:text-lg font-bold text-slate-900 mb-3">Recent Imports</h4>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full text-xs sm:text-sm">
                                            <thead>
                                                <tr class="bg-slate-50">
                                                    <th class="py-2 px-3 text-left font-bold text-slate-700">Date</th>
                                                    <th class="py-2 px-3 text-left font-bold text-slate-700">Table</th>
                                                    <th class="py-2 px-3 text-left font-bold text-slate-700">Records</th>
                                                    <th class="py-2 px-3 text-left font-bold text-slate-700">Status</th>
                                                    <th class="py-2 px-3 text-left font-bold text-slate-700">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="recentImports">
                                                <tr>
                                                    <td colspan="5" class="py-4 text-center text-slate-500">No recent imports</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Import Templates -->
                                <div class="pt-4 border-t border-slate-200">
                                    <h4 class="text-base sm:text-lg font-bold text-slate-900 mb-3">Download Templates</h4>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                        <a href="#" onclick="downloadTemplate('users')" class="p-3 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-center">
                                            <i class="fas fa-users text-blue-600 text-lg mb-2"></i>
                                            <p class="font-medium text-slate-900 text-sm">Users Template</p>
                                            <p class="text-xs text-slate-500">CSV format</p>
                                        </a>
                                        
                                        <a href="#" onclick="downloadTemplate('students')" class="p-3 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-center">
                                            <i class="fas fa-graduation-cap text-emerald-600 text-lg mb-2"></i>
                                            <p class="font-medium text-slate-900 text-sm">Students Template</p>
                                            <p class="text-xs text-slate-500">CSV format</p>
                                        </a>
                                        
                                        <a href="#" onclick="downloadTemplate('teachers')" class="p-3 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-center">
                                            <i class="fas fa-chalkboard-teacher text-purple-600 text-lg mb-2"></i>
                                            <p class="font-medium text-slate-900 text-sm">Teachers Template</p>
                                            <p class="text-xs text-slate-500">CSV format</p>
                                        </a>
                                        
                                        <a href="#" onclick="downloadTemplate('all')" class="p-3 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-center">
                                            <i class="fas fa-file-excel text-green-600 text-lg mb-2"></i>
                                            <p class="font-medium text-slate-900 text-sm">Full Template Pack</p>
                                            <p class="text-xs text-slate-500">ZIP with all templates</p>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Users -->
                <div id="users" class="tab-content">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <div class="detail-card p-6">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">User Management</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                                <div class="p-6 bg-blue-50 rounded-2xl">
                                    <div class="flex items-center justify-between mb-4">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Administrators</p>
                                            <p class="text-3xl font-black text-blue-600"><?php echo $schoolStats['admins']; ?></p>
                                        </div>
                                        <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-user-shield text-blue-600 text-lg"></i>
                                        </div>
                                    </div>
                                    <p class="text-sm text-slate-600">School administrators with full access</p>
                                </div>
                                
                                <div class="p-6 bg-emerald-50 rounded-2xl">
                                    <div class="flex items-center justify-between mb-4">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Teachers</p>
                                            <p class="text-3xl font-black text-emerald-600"><?php echo $schoolStats['teachers']; ?></p>
                                        </div>
                                        <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center">
                                            <i class="fas fa-chalkboard-teacher text-emerald-600 text-lg"></i>
                                        </div>
                                    </div>
                                    <p class="text-sm text-slate-600">Teaching staff with class management</p>
                                </div>
                                
                                <div class="p-6 bg-purple-50 rounded-2xl">
                                    <div class="flex items-center justify-between mb-4">
                                        <div>
                                            <p class="text-xs font-bold text-slate-500 uppercase mb-1">Students</p>
                                            <p class="text-3xl font-black text-purple-600"><?php echo $schoolStats['students']; ?></p>
                                        </div>
                                        <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center">
                                            <i class="fas fa-graduation-cap text-purple-600 text-lg"></i>
                                        </div>
                                    </div>
                                    <p class="text-sm text-slate-600">Enrolled students with learning access</p>
                                </div>
                            </div>

                            <!-- User Management Actions -->
                            <div class="pt-6 border-t border-slate-200">
                                <h4 class="text-lg font-bold text-slate-900 mb-4">User Management Actions</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <button onclick="performAction('create_admin')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user-plus text-blue-600"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900">Create Admin</p>
                                                <p class="text-sm text-slate-500">Add new school administrator</p>
                                            </div>
                                        </div>
                                    </button>
                                    
                                    <button onclick="performAction('reset_passwords')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                                <i class="fas fa-key text-amber-600"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900">Reset Passwords</p>
                                                <p class="text-sm text-slate-500">Reset passwords for all users</p>
                                            </div>
                                        </div>
                                    </button>
                                    
                                    <button onclick="performAction('export_users')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                                <i class="fas fa-file-export text-emerald-600"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900">Export Users</p>
                                                <p class="text-sm text-slate-500">Export user list to CSV</p>
                                            </div>
                                        </div>
                                    </button>
                                    
                                    <button onclick="performAction('send_broadcast')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                                <i class="fas fa-bullhorn text-purple-600"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900">Send Broadcast</p>
                                                <p class="text-sm text-slate-500">Send message to all users</p>
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <div class="pt-6 mt-6 border-t border-slate-200">
                                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
                                    <div>
                                        <h4 class="text-lg font-bold text-slate-900">School Users</h4>
                                        <p class="text-sm text-slate-500">View and edit users inside this school's database.</p>
                                    </div>
                                    <div class="flex flex-col sm:flex-row gap-3">
                                        <select id="userTypeFilter" class="form-input text-sm">
                                            <option value="all">All user types</option>
                                            <option value="admin">Admins</option>
                                            <option value="teacher">Teachers</option>
                                            <option value="student">Students</option>
                                            <option value="parent">Parents</option>
                                        </select>
                                        <input id="userSearchInput" class="form-input text-sm" type="search" placeholder="Search name, email, phone">
                                        <button onclick="loadSchoolUsers()" class="action-btn btn-primary touch-target text-sm">
                                            <i class="fas fa-search mr-2"></i> Load
                                        </button>
                                    </div>
                                </div>
                                <div class="overflow-x-auto border border-slate-200 rounded-xl">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-slate-50 text-slate-500 uppercase text-xs">
                                            <tr>
                                                <th class="text-left px-4 py-3">Name</th>
                                                <th class="text-left px-4 py-3">Email</th>
                                                <th class="text-left px-4 py-3">Type</th>
                                                <th class="text-left px-4 py-3">Status</th>
                                                <th class="text-left px-4 py-3">Last Login</th>
                                                <th class="text-right px-4 py-3">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="schoolUsersBody">
                                            <tr>
                                                <td colspan="6" class="px-4 py-8 text-center text-slate-500">Click Load to fetch school users.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Settings -->
                <div id="settings" class="tab-content">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <div class="detail-card p-6">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">School Settings</h3>
                            
                            <div class="space-y-6">
                                <!-- Database Settings -->
                                <div>
                                    <h4 class="text-lg font-bold text-slate-900 mb-4">Database Configuration</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <p class="text-sm font-bold text-slate-700 mb-1">Database Name</p>
                                            <p class="text-lg font-medium text-slate-900"><?php echo htmlspecialchars($school['database_name'] ?? 'Not created'); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-700 mb-1">Status</p>
                                            <span class="badge <?php echo $databaseExists ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo $databaseExists ? 'Created' : 'Not Created'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-4 flex gap-3">
                                        <button onclick="performAction('backup_db')" class="action-btn btn-secondary touch-target">
                                            <i class="fas fa-database mr-2"></i> Backup Database
                                        </button>
                                        <button onclick="performAction('optimize_db')" class="action-btn btn-secondary touch-target">
                                            <i class="fas fa-tachometer-alt mr-2"></i> Optimize Database
                                        </button>
                                    </div>
                                </div>

                                <!-- System Actions -->
                                <div>
                                    <h4 class="text-lg font-bold text-slate-900 mb-4">System Actions</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <button onclick="performAction('clear_cache')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                            <div class="flex items-center gap-3 mb-2">
                                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-broom text-blue-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900">Clear Cache</p>
                                                    <p class="text-sm text-slate-500">Clear school system cache</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="performAction('reset_passwords')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                            <div class="flex items-center gap-3 mb-2">
                                                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                                    <i class="fas fa-key text-amber-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900">Reset Passwords</p>
                                                    <p class="text-sm text-slate-500">Reset all user passwords</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="performAction('send_broadcast')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                            <div class="flex items-center gap-3 mb-2">
                                                <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                                    <i class="fas fa-bullhorn text-emerald-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900">Send Broadcast</p>
                                                    <p class="text-sm text-slate-500">Send message to all users</p>
                                                </div>
                                            </div>
                                        </button>
                                        
                                        <button onclick="performAction('generate_report')" class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition text-left">
                                            <div class="flex items-center gap-3 mb-2">
                                                <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                                    <i class="fas fa-chart-pie text-purple-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900">Generate Report</p>
                                                    <p class="text-sm text-slate-500">Create usage analytics report</p>
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </div>

                                <!-- Danger Zone -->
                                <div class="pt-6 border-t border-slate-200">
                                    <h4 class="text-lg font-bold text-red-700 mb-4">Danger Zone</h4>
                                    <div class="p-6 bg-red-50 rounded-2xl border border-red-200">
                                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                            <div>
                                                <p class="font-bold text-red-800 mb-1">Reset School Database</p>
                                                <p class="text-sm text-red-600">Wipe all school data and reset to initial state</p>
                                            </div>
                                            <button onclick="performAction('reset_school')" class="px-6 py-3 bg-white text-red-600 font-bold rounded-xl border-2 border-red-600 hover:bg-red-50 transition touch-target whitespace-nowrap">
                                                <i class="fas fa-undo mr-2"></i> Reset School
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Activity -->
                <div id="activity" class="tab-content">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <div class="detail-card p-6">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Activity Logs</h3>
                            
                            <?php if (!empty($recentActivities)): ?>
                            <div class="space-y-4">
                                <?php foreach ($recentActivities as $activity): ?>
                                <div class="timeline-item">
                                    <div class="bg-slate-50 rounded-xl p-4">
                                        <div class="flex justify-between items-start mb-2">
                                            <p class="font-bold text-slate-900"><?php echo htmlspecialchars($activity['event'] ?? 'Activity'); ?></p>
                                            <span class="text-xs text-slate-500"><?php echo date('M j, H:i', strtotime($activity['created_at'] ?? 'now')); ?></span>
                                        </div>
                                        <p class="text-sm text-slate-600"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                                        <?php if (!empty($activity['user_type'])): ?>
                                        <p class="text-xs text-slate-500 mt-1">By: <?php echo htmlspecialchars($activity['user_type']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <p class="text-slate-500">No activity logs found</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

      <script>
        // Tab switching
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Find and activate the clicked tab button
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => {
                if (button.textContent.toLowerCase().includes(tabName.toLowerCase())) {
                    button.classList.add('active');
                }
            });
            
            // Show selected tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            
            // Close mobile sidebar if open
            mobileSidebarToggle(true);
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Delete confirmation toggle
        document.getElementById('confirmDelete')?.addEventListener('change', function() {
            const deleteBtn = document.getElementById('deleteBtn');
            if (deleteBtn) {
                deleteBtn.disabled = !this.checked;
            }
        });

        // Copy to clipboard
        function copyToClipboard(text) {
            if (!text) {
                showNotification('No text to copy', 'error');
                return;
            }
            
            navigator.clipboard.writeText(text).then(() => {
                showNotification('Copied to clipboard', 'success');
            }).catch(err => {
                showNotification('Failed to copy', 'error');
            });
        }

        // Sidebar toggle for mobile
        function mobileSidebarToggle(forceClose = false) {
            const sidebar = document.querySelector('aside, [class*="sidebar"]');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && overlay) {
                if (forceClose || sidebar.classList.contains('translate-x-0')) {
                    sidebar.classList.remove('translate-x-0');
                    sidebar.classList.add('-translate-x-full');
                    overlay.classList.remove('active');
                    document.body.style.overflow = 'auto';
                } else {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.add('translate-x-0');
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            }
        }

        // Currency conversion functionality
        let currentCurrency = 'NGN'; // Default to Naira
        let exchangeRate = <?php echo $exchangeRate; ?>;
        
        // Fetch live exchange rate
        async function fetchExchangeRate() {
            try {
                const response = await fetch('actions/get-exchange-rate.php');
                const data = await response.json();
                if (data.success && data.rate) {
                    exchangeRate = data.rate;
                    // Update display
                    const badge = document.getElementById('exchangeRateBadge');
                    if (badge) {
                        badge.textContent = `$1 = ₦${exchangeRate.toLocaleString('en-US', {maximumFractionDigits: 0})}`;
                        badge.title = `Live rate updated: ${new Date().toLocaleTimeString()}`;
                    }
                    // Reconvert all amounts
                    if (currentCurrency === 'NGN') {
                        convertCurrency('NGN');
                    }
                }
            } catch (error) {
                console.error('Failed to fetch exchange rate:', error);
            }
        }
        
        // Currency toggle
        document.querySelectorAll('.currency-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const currency = this.dataset.currency;
                if (currentCurrency !== currency) {
                    currentCurrency = currency;
                    
                    // Update button states
                    document.querySelectorAll('.currency-btn').forEach(b => {
                        b.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    // Convert all amounts on the page
                    convertCurrency(currency);
                }
            });
        });
        
        function convertCurrency(targetCurrency) {
            // Helper function to extract number from currency string
            function extractAmount(str) {
                const match = str.match(/[\d,.]+/);
                return match ? parseFloat(match[0].replace(/,/g, '')) : 0;
            }
            
            // Convert all currency elements
            document.querySelectorAll('[data-usd], [data-ngn]').forEach(el => {
                if (targetCurrency === 'NGN' && el.dataset.ngn) {
                    el.textContent = el.dataset.ngn;
                } else if (targetCurrency === 'USD' && el.dataset.usd) {
                    el.textContent = el.dataset.usd;
                }
            });
            
            // Update notification if needed
            if (targetCurrency === 'NGN') {
                showNotification('Showing amounts in Naira (₦)', 'info');
            } else {
                showNotification('Showing amounts in US Dollars ($)', 'info');
            }
        }
        
        // Create database function using Tenant class
        async function createDatabase() {
            const includeSampleData = document.getElementById('includeSampleData').value;
            
            const data = {
                school_id: <?php echo $schoolId; ?>,
                database_name: document.getElementById('dbName').value,
                include_sample_data: includeSampleData,
                csrf_token: '<?php echo $csrfToken; ?>'
            };
            
            try {
                const response = await fetch('actions/create-database.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message || 'Database created successfully', 'success');
                    closeModal('createDatabaseModal');
                    
                    // Reload page after 2 seconds
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showNotification(result.message || 'Failed to create database', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }
        
        // Perform actions
        async function performAction(action) {
            let endpoint = '';
            let method = 'POST';
            let data = {
                school_id: <?php echo $schoolId; ?>,
                csrf_token: '<?php echo $csrfToken; ?>',
                database_name: '<?php echo $school['database_name'] ?? ''; ?>'
            };
            
            // Add action-specific data
            switch (action) {
                case 'create_db_advanced':
                    endpoint = 'actions/create-database.php';
                    data.advanced = true;
                    break;
                    
                case 'suspend':
                    endpoint = 'actions/suspend-school.php';
                    data.notify = document.getElementById('notifySchool')?.checked || false;
                    data.reason = document.getElementById('suspendReason')?.value || '';
                    break;
                    
                case 'activate':
                    endpoint = 'actions/activate-school.php';
                    break;
                    
                case 'delete':
                    endpoint = 'actions/delete-school.php';
                    break;
                    
                case 'extend':
                    endpoint = 'actions/extend-subscription.php';
                    const periodSelect = document.getElementById('extensionPeriod');
                    const period = periodSelect?.value || '30';
                    if (period === 'custom') {
                        data.days = document.getElementById('customDays')?.value || '30';
                    } else {
                        data.days = period;
                    }
                    data.reason = document.getElementById('extensionReason')?.value || '';
                    break;
                    
                case 'backup_db':
                    endpoint = 'actions/backup-database.php';
                    showNotification('Starting database backup...', 'info');
                    break;
                    
                case 'optimize_db':
                    endpoint = 'actions/optimize-database.php';
                    showNotification('Optimizing database tables...', 'info');
                    break;
                    
                case 'check_tables':
                    endpoint = 'actions/check-tables.php';
                    showNotification('Checking table integrity...', 'info');
                    break;
                    
                case 'export_schema':
                    endpoint = 'actions/export-schema.php';
                    showNotification('Exporting database schema...', 'info');
                    break;
                    
                case 'import_data':
                    showNotification('Opening data import dialog...', 'info');
                    openModal('insertDataModal');
                    return;

                case 'create_admin':
                    endpoint = 'actions/create-admin.php';
                    break;

                case 'reset_passwords':
                    if (!confirm('Reset passwords for all users in this school? Temporary passwords will be generated.')) {
                        return;
                    }
                    endpoint = 'actions/reset-passwords.php';
                    break;

                case 'export_users':
                    endpoint = 'actions/export-users.php';
                    data.format = 'csv';
                    data.user_types = ['admin', 'teacher', 'student', 'parent', 'accountant', 'librarian'];
                    showNotification('Preparing user export...', 'info');
                    break;

                case 'send_broadcast':
                    const broadcastSubject = prompt('Broadcast subject');
                    if (!broadcastSubject) {
                        return;
                    }
                    const broadcastMessage = prompt('Broadcast message');
                    if (!broadcastMessage) {
                        return;
                    }
                    endpoint = 'actions/send-broadcast.php';
                    data.subject = broadcastSubject;
                    data.message = broadcastMessage;
                    data.user_types = ['admin', 'teacher', 'student', 'parent', 'accountant', 'librarian'];
                    showNotification('Sending broadcast...', 'info');
                    break;
                    
                case 'reset_db':
                    if (!confirm('Are you sure you want to reset the school database? This will delete all data!')) {
                        return;
                    }
                    endpoint = 'actions/reset-database.php';
                    showNotification('Resetting database...', 'warning');
                    break;

                default:
                    showNotification('Unknown action: ' + action, 'error');
                    return;
            }
            
            try {
                const response = await fetch(endpoint, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message || 'Action completed successfully', 'success');
                    
                    // Close modal if open
                    const activeModal = document.querySelector('.modal-overlay.active');
                    if (activeModal) {
                        closeModal(activeModal.id);
                    }
                    
                    // Reload page for major actions
                    if (['create_db_advanced', 'suspend', 'activate', 'delete', 'extend', 'reset_db'].includes(action)) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    }
                    
                    // Handle file downloads
                    if (result.download_url) {
                        const link = document.createElement('a');
                        link.href = result.download_url;
                        link.download = result.filename || 'download';
                        link.style.display = 'none';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    }
                } else {
                    showNotification(result.message || 'Failed to complete action', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }
        
        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal-overlay.active');
                if (activeModal) {
                    closeModal(activeModal.id);
                }
                
                // Also close mobile sidebar if open
                const sidebarOverlay = document.getElementById('sidebarOverlay');
                if (sidebarOverlay && sidebarOverlay.classList.contains('active')) {
                    mobileSidebarToggle();
                }
            }
        });

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            // Update timestamp
            function updateTimestamp() {
                const now = new Date();
                const timestampElement = document.getElementById('timestamp');
                if (timestampElement) {
                    timestampElement.textContent = now.toLocaleTimeString('en-US', { 
                        hour: '2-digit', 
                        minute: '2-digit',
                        hour12: true 
                    });
                }
            }
            updateTimestamp();
            setInterval(updateTimestamp, 1000);
            
            // Initialize currency conversion
            convertCurrency('NGN');
            
            // Fetch exchange rate every 5 minutes
            fetchExchangeRate();
            setInterval(fetchExchangeRate, 5 * 60 * 1000);
        });

        let schoolUsers = [];

        async function loadSchoolUsers() {
            const type = document.getElementById('userTypeFilter')?.value || 'all';
            const search = document.getElementById('userSearchInput')?.value || '';
            const tbody = document.getElementById('schoolUsersBody');
            if (!tbody) return;

            tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Loading users...</td></tr>`;

            try {
                const params = new URLSearchParams({
                    school_id: '<?php echo $schoolId; ?>',
                    type,
                    search
                });
                const response = await fetch(`actions/list-users.php?${params.toString()}`);
                const result = await response.json();

                if (!result.success) {
                    tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-red-500">${result.message || 'Unable to load users'}</td></tr>`;
                    return;
                }

                schoolUsers = result.users || [];
                if (!schoolUsers.length) {
                    tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No users found.</td></tr>`;
                    return;
                }

                tbody.innerHTML = schoolUsers.map(user => {
                    const active = Number(user.is_active) === 1;
                    const name = escapeHtml(user.name || 'Unnamed User');
                    const email = escapeHtml(user.email || 'No email');
                    const type = escapeHtml(user.user_type || 'user');
                    const lastLogin = user.last_login_at ? escapeHtml(user.last_login_at) : 'Never';
                    return `
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-3 font-semibold text-slate-900">${name}</td>
                            <td class="px-4 py-3 text-slate-600">${email}</td>
                            <td class="px-4 py-3"><span class="badge bg-blue-50 text-blue-700">${type}</span></td>
                            <td class="px-4 py-3"><span class="badge ${active ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}">${active ? 'Active' : 'Inactive'}</span></td>
                            <td class="px-4 py-3 text-slate-500">${lastLogin}</td>
                            <td class="px-4 py-3 text-right">
                                <button onclick="openEditUser(${Number(user.id)})" class="px-3 py-2 rounded-lg bg-slate-900 text-white text-xs font-bold hover:bg-blue-700 transition">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-red-500">Network error: ${escapeHtml(error.message)}</td></tr>`;
            }
        }

        function openEditUser(userId) {
            const user = schoolUsers.find(item => Number(item.id) === Number(userId));
            if (!user) {
                showNotification('User not found in loaded list', 'error');
                return;
            }

            document.getElementById('editUserId').value = user.id || '';
            document.getElementById('editUserName').value = user.name || '';
            document.getElementById('editUserEmail').value = user.email || '';
            document.getElementById('editUserPhone').value = user.phone || '';
            document.getElementById('editUserType').value = user.user_type || 'student';
            document.getElementById('editUserActive').value = Number(user.is_active) === 1 ? '1' : '0';
            document.getElementById('editUserPassword').value = '';
            openModal('editUserModal');
        }

        async function saveSchoolUser() {
            const payload = {
                school_id: <?php echo $schoolId; ?>,
                csrf_token: '<?php echo $csrfToken; ?>',
                user_id: document.getElementById('editUserId').value,
                name: document.getElementById('editUserName').value,
                email: document.getElementById('editUserEmail').value,
                phone: document.getElementById('editUserPhone').value,
                user_type: document.getElementById('editUserType').value,
                is_active: document.getElementById('editUserActive').value === '1' ? 1 : 0,
                password: document.getElementById('editUserPassword').value
            };

            if (!payload.password) {
                delete payload.password;
            }

            try {
                const response = await fetch('actions/update-user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();
                if (result.success) {
                    showNotification(result.message || 'User updated', 'success');
                    closeModal('editUserModal');
                    loadSchoolUsers();
                } else {
                    showNotification(result.message || 'Unable to update user', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));
        }

        // File import helpers
        document.getElementById('dropZone')?.addEventListener('click', () => document.getElementById('dataFile')?.click());
        document.getElementById('dataFile')?.addEventListener('change', function() {
            const file = this.files?.[0];
            const importBtn = document.getElementById('importBtn');
            const fileInfo = document.getElementById('fileInfo');
            if (!file) return;

            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = `${(file.size / 1024).toFixed(1)} KB`;
            fileInfo?.classList.remove('hidden');
            if (importBtn) importBtn.disabled = false;
        });

        function removeFile() {
            const input = document.getElementById('dataFile');
            if (input) input.value = '';
            document.getElementById('fileInfo')?.classList.add('hidden');
            const importBtn = document.getElementById('importBtn');
            if (importBtn) importBtn.disabled = true;
        }

        async function importData() {
            const file = document.getElementById('dataFile')?.files?.[0];
            if (!file) {
                showNotification('Choose a CSV file first', 'error');
                return;
            }

            if (!file.name.toLowerCase().endsWith('.csv')) {
                showNotification('CSV import is supported here. Use CSV templates for best results.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');
            formData.append('school_id', '<?php echo $schoolId; ?>');
            formData.append('table_name', document.getElementById('targetTable')?.value || 'users');
            formData.append('skip_duplicates', document.getElementById('skipDuplicates')?.checked ? 'true' : 'false');
            formData.append('has_headers', document.getElementById('hasHeaders')?.checked ? 'true' : 'false');
            formData.append('csv_file', file);

            const btn = document.getElementById('importBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Importing...';
            }

            try {
                const response = await fetch('actions/import-csv.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showNotification(result.message || 'Import completed', 'success');
                    closeModal('insertDataModal');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showNotification(result.message || 'Import failed', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload mr-2"></i> Import Data';
                }
            }
        }

        function quickImport(tableName) {
            document.getElementById('targetTable').value = tableName;
            openModal('insertDataModal');
        }

        function downloadTemplate(tableName) {
            const templates = {
                users: 'name,email,phone,username,password,user_type,gender,address\\nJane Doe,jane@example.com,08000000000,jane.doe,password123,teacher,female,Lagos',
                students: 'first_name,last_name,email,phone,admission_number,roll_number,gender,date_of_birth,current_address\\nAda,Okoro,ada@example.com,08000000001,ADM-001,001,female,2012-01-01,Lagos',
                teachers: 'name,email,phone,employee_id,qualification,specialization,joining_date\\nMr John,john@example.com,08000000002,TCH-001,B.Ed,Mathematics,2026-01-01',
                classes: 'name,code,description,grade_level,capacity,room_number,academic_year_id\\nPrimary 1,PRI1,Primary one,1,40,A1,1',
                subjects: 'name,code,type,description,credit_hours\\nMathematics,MATH,core,General mathematics,1'
            };
            const content = templates[tableName] || Object.values(templates).join('\\n\\n');
            const blob = new Blob([content], { type: 'text/csv;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${tableName}-template.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
        }

        // Notification system
        function showNotification(message, type) {
            // Remove existing notifications
            document.querySelectorAll('[data-notification]').forEach(n => n.remove());
            
            const notification = document.createElement('div');
            notification.className = `fixed top-20 right-4 left-4 sm:left-auto sm:right-4 sm:w-auto px-6 py-3 rounded-xl shadow-lg z-[1001] ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                type === 'warning' ? 'bg-amber-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.setAttribute('data-notification', 'true');
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : type === 'warning' ? 'exclamation-triangle' : 'info'}"></i>
                    <span class="text-sm">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white/80 hover:text-white">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            document.body.appendChild(notification);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }
    </script>
</body>
</html>
