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

// ================== CURRENCY CONVERSION SETTINGS ==================
// Current exchange rate (as of current date - update this regularly)
// You can also fetch this from an API or database
define('USD_TO_NGN_RATE', 1400); // $1 = ₦1400 (approx current rate)

// Currency conversion functions
function usdToNaira($amount) {
    return $amount * USD_TO_NGN_RATE;
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

// Get current exchange rate for display
$exchangeRate = USD_TO_NGN_RATE;
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
        /* Include all previous styles from the manage.php file */
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
        }

        /* Mobile-optimized scrollbar */
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .glass-header { 
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 50;
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
        
        .progress-success { background: linear-gradient(90deg, #10b981, #34d399); }
        .progress-warning { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .progress-danger { background: linear-gradient(90deg, #ef4444, #f87171); }
        .progress-info { background: linear-gradient(90deg, #3b82f6, #60a5fa); }

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

        @media (max-width: 640px) {
            .xs-hidden { display: none !important; }
            .xs-block { display: block !important; }
            .xs-w-full { width: 100%; }
            .xs-text-center { text-align: center; }
            .xs-p-3 { padding: 0.75rem; }
            .xs-space-y-3 > * + * { margin-top: 0.75rem; }
            
            .tab-button {
                padding: 10px 16px;
                font-size: 13px;
            }
            
            .status-badge {
                font-size: 10px;
                padding: 4px 10px;
            }
            
            .currency-toggle {
                margin-left: 5px;
                padding: 3px;
            }
            
            .currency-btn {
                padding: 4px 8px;
                font-size: 11px;
            }
        }

        @media (min-width: 641px) and (max-width: 768px) {
            .sm-hidden { display: none !important; }
        }
    </style>
</head>
<body class="antialiased overflow-x-hidden selection:bg-blue-100">

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

    <!-- Sidebar Overlay (for mobile) -->
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="mobileSidebarToggle()"></div>

    <div class="flex h-screen overflow-hidden">

        <?php 
        // Include sidebar
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include $sidebarPath;
        } else {
            echo '<div class="w-64 bg-white border-r border-slate-200 p-4 hidden lg:block">Sidebar not found</div>';
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <a href="index.php" class="text-slate-400 hover:text-slate-600">
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
                        <span class="exchange-rate-badge hidden sm:inline" title="Current exchange rate">
                            $1 = ₦<?php echo number_format($exchangeRate, 0); ?>
                        </span>
                    </div>
                    <div class="currency-toggle" id="currencyToggle">
                        <button class="currency-btn active" data-currency="NGN">NGN</button>
                        <button class="currency-btn" data-currency="USD">USD</button>
                    </div>
                </div>
            </header>

            <!-- School Header -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-4 lg:px-8 py-6">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                                <i class="fas fa-university text-white text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-black text-slate-900 mb-1"><?php echo htmlspecialchars($school['name']); ?></h2>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <span class="status-badge <?php echo $statusInfo['color']; ?>">
                                        <i class="fas <?php echo $statusInfo['icon']; ?> text-[10px] mr-2"></i>
                                        <?php echo $statusInfo['label']; ?>
                                        <?php if ($isTrial): ?> (Trial)<?php endif; ?>
                                    </span>
                                    <span class="text-sm text-slate-500 font-medium">
                                        <i class="fas fa-hashtag mr-1"></i><?php echo $school['id']; ?>
                                    </span>
                                    <span class="text-sm text-slate-500 font-medium">
                                        <i class="fas fa-calendar-day mr-1"></i>Joined <?php echo $createdDate; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-wrap gap-3">
                            <button onclick="openModal('invoiceModal')" class="action-btn btn-primary touch-target">
                                <i class="fas fa-file-invoice-dollar mr-2"></i> Invoice
                            </button>
                            <button onclick="openModal('extendModal')" class="action-btn btn-success touch-target">
                                <i class="fas fa-calendar-plus mr-2"></i> Extend
                            </button>
                            <?php if ($currentStatus !== 'suspended'): ?>
                            <button onclick="openModal('suspendModal')" class="action-btn btn-warning touch-target">
                                <i class="fas fa-pause-circle mr-2"></i> Suspend
                            </button>
                            <?php else: ?>
                            <button onclick="openModal('activateModal')" class="action-btn btn-success touch-target">
                                <i class="fas fa-play-circle mr-2"></i> Activate
                            </button>
                            <?php endif; ?>
                            <button onclick="openModal('deleteModal')" class="action-btn btn-danger touch-target">
                                <i class="fas fa-trash mr-2"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-4 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchTab('overview')">
                            <i class="fas fa-chart-bar mr-2"></i>Overview
                        </button>
                        <button class="tab-button" onclick="switchTab('subscription')">
                            <i class="fas fa-credit-card mr-2"></i>Subscription
                        </button>
                        <button class="tab-button" onclick="switchTab('database')">
                            <i class="fas fa-database mr-2"></i>Database
                        </button>
                        <button class="tab-button" onclick="switchTab('users')">
                            <i class="fas fa-users mr-2"></i>Users
                        </button>
                        <button class="tab-button" onclick="switchTab('settings')">
                            <i class="fas fa-cog mr-2"></i>Settings
                        </button>
                        <button class="tab-button" onclick="switchTab('activity')">
                            <i class="fas fa-history mr-2"></i>Activity
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- Tab Content: Overview -->
                <div id="overview" class="tab-content active">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <!-- Stats Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="detail-card p-6 hover-lift">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Total Users</p>
                                        <p class="text-2xl font-black text-slate-900"><?php echo $schoolStats['total_users']; ?></p>
                                    </div>
                                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-users text-blue-600 text-lg"></i>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <p class="text-sm text-slate-600">
                                        <span class="font-bold text-emerald-600"><?php echo $schoolStats['active_users']; ?></span> active users
                                    </p>
                                </div>
                            </div>
                            
                            <div class="detail-card p-6 hover-lift">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Database</p>
                                        <p class="text-2xl font-black text-slate-900"><?php echo number_format($schoolStats['database_size'], 2); ?> MB</p>
                                    </div>
                                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center">
                                        <i class="fas fa-database text-purple-600 text-lg"></i>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <p class="text-sm text-slate-600">
                                        <span class="font-bold"><?php echo $dbStats['tables']; ?></span> tables, 
                                        <span class="font-bold"><?php echo $dbStats['rows']; ?></span> rows
                                    </p>
                                </div>
                            </div>
                            
                            <div class="detail-card p-6 hover-lift">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Storage Usage</p>
                                        <p class="text-2xl font-black text-slate-900"><?php echo number_format($storageUsedGB, 2); ?> GB</p>
                                    </div>
                                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center">
                                        <i class="fas fa-hdd text-emerald-600 text-lg"></i>
                                    </div>
                                </div>
                                <div class="mt-4">
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
                            
                            <div class="detail-card p-6 hover-lift">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Last Active</p>
                                        <p class="text-2xl font-black text-slate-900">
                                            <?php if ($schoolStats['last_login']): ?>
                                                <?php echo date('M j', strtotime($schoolStats['last_login'])); ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center">
                                        <i class="fas fa-sign-in-alt text-amber-600 text-lg"></i>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <p class="text-sm text-slate-600">
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
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- User Breakdown -->
                            <div class="detail-card p-6">
                                <h3 class="text-lg font-bold text-slate-900 mb-4">User Breakdown</h3>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div class="text-center p-4 bg-blue-50 rounded-xl">
                                        <p class="text-2xl font-black text-blue-600"><?php echo $schoolStats['admins']; ?></p>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Admins</p>
                                    </div>
                                    <div class="text-center p-4 bg-emerald-50 rounded-xl">
                                        <p class="text-2xl font-black text-emerald-600"><?php echo $schoolStats['teachers']; ?></p>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Teachers</p>
                                    </div>
                                    <div class="text-center p-4 bg-purple-50 rounded-xl">
                                        <p class="text-2xl font-black text-purple-600"><?php echo $schoolStats['students']; ?></p>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Students</p>
                                    </div>
                                    <div class="text-center p-4 bg-amber-50 rounded-xl">
                                        <p class="text-2xl font-black text-amber-600"><?php echo $schoolStats['parents']; ?></p>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Parents</p>
                                    </div>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-4">
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
                            <div class="detail-card p-6">
                                <h3 class="text-lg font-bold text-slate-900 mb-4">Database Status</h3>
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-sm font-bold text-slate-700 mb-1">Database Name</p>
                                        <div class="flex items-center justify-between">
                                            <code class="text-sm bg-slate-100 px-3 py-1 rounded"><?php echo htmlspecialchars($school['database_name'] ?? 'Not created'); ?></code>
                                            <span class="badge <?php echo $databaseExists ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo $databaseExists ? 'Online' : 'Offline'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-3 gap-4">
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
                                    
                                    <div class="pt-4 border-t border-slate-200">
                                        <div class="flex gap-3">
                                            <button onclick="performAction('backup_db')" class="flex-1 action-btn btn-secondary touch-target">
                                                <i class="fas fa-download mr-2"></i> Backup
                                            </button>
                                            <button onclick="performAction('optimize_db')" class="flex-1 action-btn btn-secondary touch-target">
                                                <i class="fas fa-wrench mr-2"></i> Optimize
                                            </button>
                                            <button onclick="performAction('reset_db')" class="flex-1 action-btn btn-danger touch-target">
                                                <i class="fas fa-trash mr-2"></i> Reset
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- School Information -->
                        <div class="detail-card p-6">
                            <h3 class="text-lg font-bold text-slate-900 mb-4">School Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div>
                                    <p class="text-xs font-bold text-slate-500 uppercase mb-1">Contact Email</p>
                                    <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($school['email']); ?></p>
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
                        <div class="detail-card p-6">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Database Management</h3>
                            
                            <div class="space-y-6">
                                <!-- Database Status -->
                                <div class="p-6 bg-gradient-to-r from-purple-50 to-indigo-50 rounded-2xl border border-purple-100">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h4 class="text-xl font-black text-slate-900 mb-1">Database Information</h4>
                                            <p class="text-sm text-slate-600">Complete schema with all educational tables</p>
                                        </div>
                                        <span class="badge <?php echo $databaseExists ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo $databaseExists ? 'Online' : 'Offline'; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <p class="text-sm font-bold text-slate-700 mb-1">Database Name</p>
                                            <div class="flex items-center gap-3">
                                                <code class="flex-1 text-sm bg-white px-3 py-2 rounded border border-purple-200 font-mono">
                                                    <?php echo htmlspecialchars($school['database_name'] ?? 'Not created'); ?>
                                                </code>
                                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($school['database_name'] ?? ''); ?>')" 
                                                        class="px-3 py-2 bg-purple-100 text-purple-600 rounded-lg hover:bg-purple-200 transition">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
                                    <h4 class="text-lg font-bold text-slate-900 mb-4">Database Actions</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
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
                                    <h4 class="text-lg font-bold text-slate-900 mb-4">Database Tables</h4>
                                    <?php if ($databaseExists && $dbStats['tables'] > 0): ?>
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

        // Extension period toggle
        const extensionPeriod = document.getElementById('extensionPeriod');
        if (extensionPeriod) {
            extensionPeriod.addEventListener('change', function() {
                const customContainer = document.getElementById('customDaysContainer');
                if (this.value === 'custom') {
                    customContainer.classList.remove('hidden');
                } else {
                    customContainer.classList.add('hidden');
                }
            });
        }

        // Copy to clipboard
        function copyToClipboard(text) {
            if (!text) {
                showNotification('No text to copy', 'error');
                return;
            }
            
            navigator.clipboard.writeText(text).then(() => {
                showNotification('Database name copied to clipboard', 'success');
            }).catch(err => {
                showNotification('Failed to copy: ' + err, 'error');
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
        const exchangeRate = <?php echo $exchangeRate; ?>;
        
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
            // Convert monthly subscription amount
            const monthlyElements = document.querySelectorAll('.monthly-amount');
            monthlyElements.forEach(el => {
                if (targetCurrency === 'NGN') {
                    el.innerHTML = el.dataset.ngn + ' <span class="text-sm font-normal text-slate-500">/month</span>';
                } else {
                    el.innerHTML = el.dataset.usd + ' <span class="text-sm font-normal text-slate-500">/month</span>';
                }
            });
            
            // Convert annual amount
            const annualElements = document.querySelectorAll('.annual-amount');
            annualElements.forEach(el => {
                if (targetCurrency === 'NGN') {
                    el.innerHTML = el.dataset.ngn + ' <span class="text-xs font-normal text-slate-500">/year</span>';
                } else {
                    el.innerHTML = el.dataset.usd + ' <span class="text-xs font-normal text-slate-500">/year</span>';
                }
            });
            
            // Convert invoice amount
            const invoiceElements = document.querySelectorAll('.invoice-amount');
            invoiceElements.forEach(el => {
                if (targetCurrency === 'NGN') {
                    el.textContent = '₦' + el.dataset.ngn;
                } else {
                    el.textContent = '$' + el.dataset.usd;
                }
            });
            
            // Show/hide equivalent amounts
            const equivalentElements = document.querySelectorAll('.usd-equivalent, .monthly-equivalent');
            equivalentElements.forEach(el => {
                if (targetCurrency === 'NGN') {
                    el.style.display = 'block';
                } else {
                    el.style.display = 'none';
                }
            });
            
            // Update notification if needed
            if (targetCurrency === 'NGN') {
                showNotification('Showing amounts in Naira (₦)', 'info');
            } else {
                showNotification('Showing amounts in US Dollars ($)', 'info');
            }
        }
        
        // Initialize currency conversion
        document.addEventListener('DOMContentLoaded', function() {
            convertCurrency('NGN'); // Start with Naira
        });

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
                const options = { 
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                };
                const timestampElement = document.getElementById('timestamp');
                if (timestampElement) {
                    timestampElement.textContent = now.toLocaleTimeString('en-US', options);
                }
            }
            updateTimestamp();
            setInterval(updateTimestamp, 1000);
            
            // Auto-hide notifications on click
            document.addEventListener('click', (e) => {
                if (e.target.closest('[data-notification]')) {
                    e.target.closest('[data-notification]').remove();
                }
            });
        });

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
                    
                case 'approve_invoice':
                    endpoint = 'actions/approve-invoice.php';
                    break;
                    
                case 'reject_invoice':
                    endpoint = 'actions/reject-invoice.php';
                    break;
                    
                case 'resend_invoice':
                    endpoint = 'actions/resend-invoice.php';
                    break;
                    
                case 'generate_invoice':
                    endpoint = 'actions/generate-invoice.php';
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
                    endpoint = 'actions/import-data.php';
                    showNotification('Opening data import dialog...', 'info');
                    break;
                    
                case 'reset_db':
                    if (!confirm('Are you sure you want to reset the school database? This will delete all data!')) {
                        return;
                    }
                    endpoint = 'actions/reset-database.php';
                    showNotification('Resetting database...', 'warning');
                    break;
                    
                case 'clear_cache':
                    endpoint = 'actions/clear-cache.php';
                    showNotification('Clearing cache...', 'info');
                    break;
                    
                case 'reset_passwords':
                    endpoint = 'actions/reset-passwords.php';
                    showNotification('Resetting user passwords...', 'info');
                    break;
                    
                case 'send_broadcast':
                    endpoint = 'actions/send-broadcast.php';
                    showNotification('Preparing broadcast message...', 'info');
                    break;
                    
                case 'generate_report':
                    endpoint = 'actions/generate-report.php';
                    showNotification('Generating report...', 'info');
                    break;
                    
                case 'reset_school':
                    if (!confirm('WARNING: This will reset ALL school data to factory settings. Are you absolutely sure?')) {
                        return;
                    }
                    endpoint = 'actions/reset-school.php';
                    showNotification('Resetting school data...', 'warning');
                    break;
                    
                case 'create_admin':
                    endpoint = 'actions/create-admin.php';
                    showNotification('Creating admin user...', 'info');
                    break;
                    
                case 'export_users':
                    endpoint = 'actions/export-users.php';
                    showNotification('Exporting user data...', 'info');
                    break;
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
                    const modal = document.querySelector('.modal-overlay.active');
                    if (modal) {
                        const modalId = modal.id;
                        closeModal(modalId);
                    }
                    
                    // Reload page for major actions
                    if (['suspend', 'activate', 'delete', 'extend', 'approve_invoice', 'reject_invoice', 'reset_db', 'reset_school'].includes(action)) {
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

        // Notification system
        function showNotification(message, type) {
            // Remove existing notifications
            document.querySelectorAll('[data-notification]').forEach(n => n.remove());
            
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 left-4 sm:left-auto px-6 py-3 rounded-xl shadow-lg z-[1001] ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                type === 'warning' ? 'bg-amber-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.setAttribute('data-notification', 'true');
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : type === 'warning' ? 'exclamation-triangle' : 'info'}"></i>
                    <span>${message}</span>
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