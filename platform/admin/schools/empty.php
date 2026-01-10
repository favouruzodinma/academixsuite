<?php
// platform/admin/schools/view.php
require_once __DIR__ . '/../../../includes/autoload.php';

// Require super admin login
$auth = new Auth();
$auth->requireLogin('super_admin');



// Get school ID from query parameter
$schoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($schoolId <= 0) {
    header("Location: ../index.php");
    exit;
}

// Get school data from platform database
$db = Database::getPlatformConnection();
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    header("Location: ../index.php?error=school_not_found");
    exit;
}

// Get school statistics - FIXED: Check if Tenant exists
$stats = ['students' => 0, 'teachers' => 0, 'admins' => 0, 'parents' => 0];
try {
    if (class_exists('Tenant')) {
        $tenant = new Tenant();
        $tenantStats = $tenant->getSchoolStatistics($schoolId);
        if (is_array($tenantStats)) {
            $stats = $tenantStats;
        }
    }
} catch (Exception $e) {
    error_log("Error getting school statistics: " . $e->getMessage());
}

// Get current subscription
$subscription = null;
try {
    $subStmt = $db->prepare("
        SELECT s.*, p.name as plan_name, p.price_monthly 
        FROM subscriptions s 
        LEFT JOIN plans p ON s.plan_id = p.id 
        WHERE s.school_id = ? 
        ORDER BY s.created_at DESC 
        LIMIT 1
    ");
    $subStmt->execute([$schoolId]);
    $subscription = $subStmt->fetch();
} catch (Exception $e) {
    error_log("Error getting subscription: " . $e->getMessage());
}

// Try to get admin user from school's database
$admin = null;
$recentActivities = [];
try {
    // Connect to school's database
    if (!empty($school['database_name'])) {
        // FIXED: Use safer connection method
        if (Database::schoolDatabaseExists($school['database_name'])) {
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            // Get admin user (first user with admin role)
            $adminStmt = $schoolDb->prepare("
                SELECT u.* FROM users u 
                WHERE u.user_type = 'admin' AND u.is_active = 1 
                ORDER BY u.id ASC 
                LIMIT 1
            ");
            $adminStmt->execute();
            $admin = $adminStmt->fetch();
            
            // Get recent activities - FIXED: Safer table existence check
            try {
                $checkTable = $schoolDb->prepare("SHOW TABLES LIKE ?");
                $checkTable->execute(['audit_logs']);
                if ($checkTable->fetch()) {
                    $activitiesStmt = $schoolDb->prepare("
                        SELECT * FROM audit_logs 
                        WHERE school_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ");
                    $activitiesStmt->execute([$schoolId]);
                    $recentActivities = $activitiesStmt->fetchAll();
                }
            } catch (Exception $e) {
                error_log("Error checking audit logs table: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    // Log error but don't crash the page
    error_log("Error accessing school database for school ID {$schoolId}: " . $e->getMessage());
}

// Get recent activities from platform audit_logs as fallback
if (empty($recentActivities)) {
    try {
        // FIXED: Check if platform_audit_logs table exists
        $checkTable = $db->prepare("SHOW TABLES LIKE ?");
        $checkTable->execute(['platform_audit_logs']);
        
        if ($checkTable->fetch()) {
            $activitiesStmt = $db->prepare("
                SELECT * FROM platform_audit_logs 
                WHERE school_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $activitiesStmt->execute([$schoolId]);
            $recentActivities = $activitiesStmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Error accessing platform audit logs: " . $e->getMessage());
        $recentActivities = [];
    }
}

// Format data for display
$statusClass = '';
$statusText = 'Pending';
switch($school['status'] ?? 'pending') {
    case 'active':
        $statusClass = 'status-active';
        $statusText = 'Operational';
        break;
    case 'trial':
        $statusClass = 'status-trial';
        $statusText = 'Trial';
        break;
    case 'suspended':
        $statusClass = 'status-suspended';
        $statusText = 'Suspended';
        break;
    case 'pending':
        $statusClass = 'status-pending';
        $statusText = 'Pending';
        break;
    default:
        $statusClass = 'status-pending';
        $statusText = ucfirst($school['status'] ?? 'pending');
}

// Calculate renewal date
$renewalDate = null;
if ($subscription && isset($subscription['current_period_end']) && $subscription['current_period_end']) {
    $renewalDate = date('F j, Y', strtotime($subscription['current_period_end']));
}

// Calculate uptime (simulated)
$uptime = 94.7 + (rand(-10, 10) / 10);

// Prepare JSON data for charts
$chartData = [
    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    'users' => [],
    'apiCalls' => []
];

// User distribution based on stats
$userDistribution = [
    'labels' => ['Teachers', 'Students', 'Administrators', 'Parents'],
    'data' => [
        $stats['teachers'] ?? 0,
        $stats['students'] ?? 0,
        $stats['admins'] ?? 0,
        $stats['parents'] ?? 0
    ]
];

// Generate simulated data for charts
for ($i = 0; $i < 7; $i++) {
    $baseUsers = ($stats['students'] ?? 0) + ($stats['teachers'] ?? 0) + ($stats['admins'] ?? 0);
    $chartData['users'][] = $baseUsers + rand(-200, 200);
    $chartData['apiCalls'][] = rand(40000, 80000);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo htmlspecialchars($school['name'] ?? 'School Details'); ?> | AcademixSuite Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg); 
            color: #1e293b; 
            -webkit-tap-highlight-color: transparent;
        }

        /* Mobile-optimized scrollbar */
        ::-webkit-scrollbar { 
            width: 4px; 
            height: 4px; 
        }
        ::-webkit-scrollbar-track { 
            background: #f1f5f9; 
        }
        ::-webkit-scrollbar-thumb { 
            background: #cbd5e1; 
            border-radius: 10px; 
        }

        .sidebar-link { 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
        }
        .sidebar-link:hover { 
            background: #f1f5f9; 
            color: var(--brand-primary); 
        }
        .active-link { 
            background: #eff6ff; 
            color: var(--brand-primary); 
            border-left-color: var(--brand-primary); 
            font-weight: 600; 
        }
        
        .dropdown-content { 
            max-height: 0; 
            overflow: hidden; 
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .dropdown-open .dropdown-content { 
            max-height: 500px; 
        }
        .dropdown-open .chevron { 
            transform: rotate(180deg); 
        }

        /* Mobile-first responsive design */
        @media (max-width: 640px) {
            .mobile-stack { flex-direction: column; }
            .mobile-full { width: 100%; }
            .mobile-text-center { text-align: center; }
            .mobile-p-4 { padding: 1rem; }
            .mobile-space-y-4 > * + * { margin-top: 1rem; }
        }

        @media (max-width: 768px) {
            .tablet-hide { display: none; }
            .tablet-full { width: 100%; }
        }

        /* Touch-friendly sizes */
        .touch-target {
            min-height: 44px;
            min-width: 44px;
        }

        .glass-header { 
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
        }
        
        .detail-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); 
            border-radius: 20px;
        }

        /* Status indicators */
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
        
        .status-trial {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-suspended {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .status-pending {
            background-color: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        /* Progress bars */
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
        
        .progress-success {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }
        
        .progress-danger {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        /* Timeline */
        .timeline-item {
            position: relative;
            padding-left: 24px;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #2563eb;
            background: white;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: 5px;
            top: 20px;
            width: 2px;
            height: calc(100% + 12px);
            background: #e2e8f0;
        }
        
        .timeline-item:last-child::after {
            display: none;
        }

        /* Tabs */
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

        /* Card hover effects */
        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        /* Modal */
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
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        /* Mobile menu overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Form elements */
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            background: white;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-blue-100">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">Edit School Details</h3>
                <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600 touch-target">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="editSchoolForm" method="POST" action="update_school.php">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                
                <div class="space-y-4">
                    <div class="form-group">
                        <label class="form-label">Institution Name</label>
                        <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($school['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="active" <?php echo ($school['status'] ?? '') == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="trial" <?php echo ($school['status'] ?? '') == 'trial' ? 'selected' : ''; ?>>Trial</option>
                            <option value="suspended" <?php echo ($school['status'] ?? '') == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="pending" <?php echo ($school['status'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($school['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-input" value="<?php echo htmlspecialchars($school['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-input" rows="3"><?php echo htmlspecialchars($school['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-input" value="<?php echo htmlspecialchars($school['city'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" name="state" class="form-input" value="<?php echo htmlspecialchars($school['state'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button type="button" onclick="closeModal('editModal')" class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Modal -->
    <div id="actionsModal" class="modal-overlay">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">School Actions</h3>
                <button onclick="closeModal('actionsModal')" class="text-slate-400 hover:text-slate-600 touch-target">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="space-y-3">
                <button onclick="performAction('backup', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-emerald-200 hover:bg-emerald-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                        <i class="fas fa-database text-emerald-600"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900">Create Backup</h4>
                        <p class="text-sm text-slate-500">Generate system backup</p>
                    </div>
                </button>
                
                <button onclick="performAction('restart', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-amber-200 hover:bg-amber-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                        <i class="fas fa-redo text-amber-600"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900">Restart Services</h4>
                        <p class="text-sm text-slate-500">Restart school services</p>
                    </div>
                </button>
                
                <button onclick="performAction('suspend', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-red-200 hover:bg-red-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                        <i class="fas fa-pause text-red-600"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900">Suspend Account</h4>
                        <p class="text-sm text-slate-500">Temporarily suspend school</p>
                    </div>
                </button>
                
                <button onclick="performAction('terminate', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-red-200 hover:bg-red-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                        <i class="fas fa-trash text-red-600"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900">Terminate Account</h4>
                        <p class="text-sm text-slate-500">Permanently delete school</p>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">

        <?php 
        // FIXED: Use proper path for sidebar
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include $sidebarPath;
        } else {
            // Fallback minimal sidebar
            echo '<div class="w-64 bg-white border-r border-slate-200 p-4">Sidebar not found</div>';
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">School Performance</h1>
                        <span class="px-2 py-0.5 bg-blue-600 text-[10px] text-white font-black rounded uppercase">Live</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="../index.php" class="hidden sm:flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-blue-600 text-sm font-medium transition">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Registry</span>
                    </a>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-clock"></i>
                        <span id="timestamp">Loading...</span>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-4 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchTab(event, 'overview')">
                            <i class="fas fa-chart-bar mr-2"></i>Overview
                        </button>
                        <button class="tab-button" onclick="switchTab(event, 'users')">
                            <i class="fas fa-users mr-2"></i>Users
                        </button>
                        <button class="tab-button" onclick="switchTab(event, 'analytics')">
                            <i class="fas fa-chart-line mr-2"></i>Analytics
                        </button>
                        <button class="tab-button" onclick="switchTab(event, 'settings')">
                            <i class="fas fa-cog mr-2"></i>Settings
                        </button>
                        <button class="tab-button" onclick="switchTab(event, 'logs')">
                            <i class="fas fa-history mr-2"></i>Activity Logs
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- School Header -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="bg-white detail-card p-6">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-6">
                            <div class="flex items-center gap-4">
                                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                                    <i class="fas fa-university text-white text-2xl"></i>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-black text-slate-900 mb-1"><?php echo htmlspecialchars($school['name'] ?? 'Unnamed School'); ?></h2>
                                    <div class="flex items-center gap-3">
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <i class="fas fa-circle text-[8px] mr-1"></i> <?php echo $statusText; ?>
                                        </span>
                                        <span class="text-sm text-slate-500 font-medium">
                                            <i class="fas fa-hashtag mr-1"></i><?php echo $school['id']; ?>
                                        </span>
                                        <span class="text-sm text-slate-500 font-medium">
                                            <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars(($school['city'] ?? '') . ', ' . ($school['state'] ?? '')); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-3">
                                <button onclick="openModal('editModal')" class="px-5 py-2.5 bg-white border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2 touch-target">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button onclick="openModal('actionsModal')" class="px-5 py-2.5 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center gap-2 touch-target">
                                    <i class="fas fa-cog"></i> Actions
                                </button>
                                <button onclick="generateReport(<?php echo $schoolId; ?>)" class="px-5 py-2.5 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition flex items-center gap-2 touch-target">
                                    <i class="fas fa-file-export"></i> Export Report
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-slate-50 rounded-xl p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Active Users</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-2xl font-black text-slate-900"><?php echo ($stats['students'] ?? 0) + ($stats['teachers'] ?? 0) + ($stats['admins'] ?? 0) + ($stats['parents'] ?? 0); ?></p>
                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded">+12%</span>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50 rounded-xl p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Engagement Rate</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-2xl font-black text-slate-900">87.3%</p>
                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded">+3.2%</span>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50 rounded-xl p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Avg Session</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-2xl font-black text-slate-900">34m</p>
                                    <span class="text-xs font-bold text-amber-600 bg-amber-100 px-2 py-1 rounded">-2%</span>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50 rounded-xl p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Health Score</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-2xl font-black text-slate-900"><?php echo number_format($uptime, 1); ?>%</p>
                                    <div class="w-3 h-3 bg-emerald-500 rounded-full animate-pulse"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Overview -->
                <div id="overviewTab" class="max-w-7xl mx-auto space-y-6 tab-content active">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Left Column -->
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Performance Metrics -->
                            <div class="bg-white detail-card p-6 hover-lift">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-lg font-bold text-slate-900">Performance Metrics</h3>
                                    <select class="text-sm border border-slate-200 rounded-lg px-3 py-1">
                                        <option>Last 7 days</option>
                                        <option>Last 30 days</option>
                                        <option>Last quarter</option>
                                    </select>
                                </div>
                                <div class="h-64">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Resource Utilization -->
                            <div class="bg-white detail-card p-6 hover-lift">
                                <h3 class="text-lg font-bold text-slate-900 mb-6">Resource Utilization</h3>
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="font-medium text-slate-700">Database Size</span>
                                            <span class="font-bold">
                                                <?php 
                                                    $dbSize = 0;
                                                    if (!empty($school['database_name'])) {
                                                        $dbSize = rand(50, 200);
                                                    }
                                                    echo $dbSize; ?> MB / 200 MB
                                            </span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar progress-success" style="width: <?php echo ($dbSize / 200) * 100; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="font-medium text-slate-700">Storage Usage</span>
                                            <span class="font-bold">145 GB / 200 GB</span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar progress-warning" style="width: 72.5%"></div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="font-medium text-slate-700">Bandwidth</span>
                                            <span class="font-bold">18.2 TB / 25 TB</span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar progress-success" style="width: 72.8%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="space-y-6">
                            <!-- School Details -->
                            <div class="bg-white detail-card p-6 hover-lift">
                                <h3 class="text-lg font-bold text-slate-900 mb-4">Institution Details</h3>
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Type</p>
                                        <p class="text-sm font-medium"><?php echo ucfirst($school['type'] ?? 'Secondary'); ?> School</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Subscription</p>
                                        <span class="inline-block px-3 py-1 bg-slate-900 text-white text-xs font-bold rounded-lg">
                                            <?php echo $subscription['plan_name'] ?? 'No Subscription'; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Onboarded</p>
                                        <p class="text-sm font-medium"><?php echo date('F j, Y', strtotime($school['created_at'] ?? 'now')); ?></p>
                                    </div>
                                    <?php if ($renewalDate): ?>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Renewal Date</p>
                                        <p class="text-sm font-medium"><?php echo $renewalDate; ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Monthly Cost</p>
                                        <p class="text-sm font-bold text-slate-900">₦<?php echo number_format($subscription['price_monthly'] ?? 0, 2); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Admin Contact -->
                            <div class="bg-white detail-card p-6 hover-lift">
                                <h3 class="text-lg font-bold text-slate-900 mb-4">Primary Administrator</h3>
                                <?php if ($admin): ?>
                                <div class="flex items-center gap-3 mb-4">
                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin['name'] ?? 'Admin'); ?>&background=2563eb&color=fff" class="w-12 h-12 rounded-xl">
                                    <div>
                                        <p class="font-bold text-slate-900"><?php echo htmlspecialchars($admin['name'] ?? 'Administrator'); ?></p>
                                        <p class="text-sm text-slate-500"><?php echo ucfirst($admin['user_type'] ?? 'Administrator'); ?></p>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2 text-sm">
                                        <i class="fas fa-envelope text-slate-400"></i>
                                        <span><?php echo htmlspecialchars($admin['email'] ?? ''); ?></span>
                                    </div>
                                    <?php if (!empty($admin['phone'])): ?>
                                    <div class="flex items-center gap-2 text-sm">
                                        <i class="fas fa-phone text-slate-400"></i>
                                        <span><?php echo htmlspecialchars($admin['phone']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-2 text-sm">
                                        <i class="fas fa-calendar text-slate-400"></i>
                                        <span>Last active: <?php echo !empty($admin['last_login_at']) ? date('M j, Y', strtotime($admin['last_login_at'])) : 'Never'; ?></span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <p class="text-slate-500 text-sm">No admin assigned</p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Quick Actions -->
                            <div class="bg-gradient-to-br from-blue-500 to-blue-600 detail-card p-6 text-white">
                                <h3 class="text-lg font-bold mb-4">Quick Actions</h3>
                                <div class="space-y-3">
                                    <button onclick="sendMessage(<?php echo $schoolId; ?>)" class="w-full text-left p-3 rounded-lg bg-white/10 hover:bg-white/20 transition flex items-center gap-3 touch-target">
                                        <i class="fas fa-comment"></i>
                                        <span>Send Message</span>
                                    </button>
                                    <button onclick="scheduleCall(<?php echo $schoolId; ?>)" class="w-full text-left p-3 rounded-lg bg-white/10 hover:bg-white/20 transition flex items-center gap-3 touch-target">
                                        <i class="fas fa-phone"></i>
                                        <span>Schedule Call</span>
                                    </button>
                                    <button onclick="viewBilling(<?php echo $schoolId; ?>)" class="w-full text-left p-3 rounded-lg bg-white/10 hover:bg-white/20 transition flex items-center gap-3 touch-target">
                                        <i class="fas fa-receipt"></i>
                                        <span>View Billing</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="bg-white detail-card p-6 hover-lift">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Recent Activity</h3>
                        <div class="timeline">
                            <?php if (!empty($recentActivities)): ?>
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
                            <?php else: ?>
                                <p class="text-slate-500 text-center py-4">No recent activity</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Users -->
                <div id="usersTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-white detail-card p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-slate-900">User Management</h3>
                            <button onclick="addUser(<?php echo $schoolId; ?>)" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                <i class="fas fa-user-plus mr-2"></i>Add User
                            </button>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-slate-100">
                                        <th class="text-left py-3 px-4 text-sm font-bold text-slate-500">User</th>
                                        <th class="text-left py-3 px-4 text-sm font-bold text-slate-500">Role</th>
                                        <th class="text-left py-3 px-4 text-sm font-bold text-slate-500">Status</th>
                                        <th class="text-left py-3 px-4 text-sm font-bold text-slate-500">Last Active</th>
                                        <th class="text-left py-3 px-4 text-sm font-bold text-slate-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php if ($admin): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-4 px-4">
                                            <div class="flex items-center gap-3">
                                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin['name'] ?? 'Admin'); ?>&background=2563eb&color=fff" class="w-8 h-8 rounded-lg">
                                                <div>
                                                    <p class="font-medium text-slate-900"><?php echo htmlspecialchars($admin['name'] ?? 'Administrator'); ?></p>
                                                    <p class="text-sm text-slate-500"><?php echo htmlspecialchars($admin['email'] ?? ''); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded"><?php echo ucfirst($admin['user_type'] ?? 'admin'); ?></span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="flex items-center gap-2">
                                                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                                <span class="text-sm text-slate-700">Active</span>
                                            </span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="text-sm text-slate-600"><?php echo !empty($admin['last_login_at']) ? date('M j, Y', strtotime($admin['last_login_at'])) : 'Never'; ?></span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <button onclick="editUser(<?php echo $admin['id'] ?? 0; ?>)" class="text-blue-600 hover:text-blue-700 touch-target">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <!-- Additional users would be fetched from the school's database -->
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-4 px-4">
                                            <div class="flex items-center gap-3">
                                                <img src="https://ui-avatars.com/api/?name=Teacher+Sample&background=059669&color=fff" class="w-8 h-8 rounded-lg">
                                                <div>
                                                    <p class="font-medium text-slate-900">Sample Teacher</p>
                                                    <p class="text-sm text-slate-500">teacher@<?php echo htmlspecialchars($school['slug'] ?? 'school'); ?>.edu</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="px-2 py-1 bg-slate-100 text-slate-700 text-xs font-bold rounded">Teacher</span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="flex items-center gap-2">
                                                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                                <span class="text-sm text-slate-700">Active</span>
                                            </span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="text-sm text-slate-600">Yesterday</span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <button onclick="editUser(2)" class="text-blue-600 hover:text-blue-700 touch-target">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Analytics -->
                <div id="analyticsTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white detail-card p-6 hover-lift">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Usage Trends</h3>
                            <div class="h-64">
                                <canvas id="usageChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="bg-white detail-card p-6 hover-lift">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">User Distribution</h3>
                            <div class="h-64">
                                <canvas id="distributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white detail-card p-6 hover-lift">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Key Performance Indicators</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                            <div class="text-center">
                                <div class="text-3xl font-black text-blue-600 mb-2"><?php echo number_format($uptime, 1); ?>%</div>
                                <p class="text-sm text-slate-600">Uptime (30 days)</p>
                            </div>
                            <div class="text-center">
                                <div class="text-3xl font-black text-emerald-600 mb-2">1.2s</div>
                                <p class="text-sm text-slate-600">Avg Response Time</p>
                            </div>
                            <div class="text-center">
                                <div class="text-3xl font-black text-amber-600 mb-2">0.03%</div>
                                <p class="text-sm text-slate-600">Error Rate</p>
                            </div>
                            <div class="text-center">
                                <div class="text-3xl font-black text-purple-600 mb-2">99.1%</div>
                                <p class="text-sm text-slate-600">Satisfaction Score</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Settings -->
                <div id="settingsTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-white detail-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">System Configuration</h3>
                        
                        <div class="space-y-6">
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">General Settings</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="form-group">
                                        <label class="form-label">API Rate Limit</label>
                                        <select class="form-input">
                                            <option>100 requests/min</option>
                                            <option selected>500 requests/min</option>
                                            <option>1000 requests/min</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Data Retention</label>
                                        <select class="form-input">
                                            <option>30 days</option>
                                            <option selected>90 days</option>
                                            <option>365 days</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">Security Settings</h4>
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                        <div>
                                            <p class="font-medium text-slate-900">Two-Factor Authentication</p>
                                            <p class="text-sm text-slate-500">Require 2FA for all admin accounts</p>
                                        </div>
                                        <input type="checkbox" class="toggle-switch" checked>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                        <div>
                                            <p class="font-medium text-slate-900">IP Whitelisting</p>
                                            <p class="text-sm text-slate-500">Restrict access to specific IP ranges</p>
                                        </div>
                                        <input type="checkbox" class="toggle-switch">
                                    </label>
                                </div>
                            </div>
                            
                            <div class="pt-6 border-t border-slate-100">
                                <button onclick="saveSettings(<?php echo $schoolId; ?>)" class="px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                    Save Configuration
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Logs -->
                <div id="logsTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-white detail-card p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-slate-900">Activity Logs</h3>
                            <div class="flex gap-3">
                                <select class="text-sm border border-slate-200 rounded-lg px-3 py-1">
                                    <option>All Activities</option>
                                    <option>User Actions</option>
                                    <option>System Events</option>
                                    <option>Security Events</option>
                                </select>
                                <button onclick="exportLogs(<?php echo $schoolId; ?>)" class="px-4 py-2 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                    <i class="fas fa-download mr-2"></i>Export
                                </button>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                <div class="flex items-center justify-between p-4 border border-slate-100 rounded-xl hover:bg-slate-50 transition">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                            <?php 
                                                $icon = 'fa-history';
                                                $event = $activity['event'] ?? '';
                                                if (stripos($event, 'login') !== false) $icon = 'fa-sign-in-alt';
                                                elseif (stripos($event, 'logout') !== false) $icon = 'fa-sign-out-alt';
                                                elseif (stripos($event, 'create') !== false) $icon = 'fa-plus';
                                                elseif (stripos($event, 'update') !== false) $icon = 'fa-edit';
                                                elseif (stripos($event, 'delete') !== false) $icon = 'fa-trash';
                                            ?>
                                            <i class="fas <?php echo $icon; ?> text-blue-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-slate-900"><?php echo htmlspecialchars($activity['event'] ?? 'Activity'); ?></p>
                                            <p class="text-sm text-slate-500"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-medium text-slate-900"><?php echo date('h:i A', strtotime($activity['created_at'] ?? 'now')); ?></p>
                                        <p class="text-xs text-slate-500"><?php echo date('M j, Y', strtotime($activity['created_at'] ?? 'now')); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-slate-500 text-center py-4">No activity logs found</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize timestamp
        function updateTimestamp() {
            const now = new Date();
            const options = { 
                weekday: 'short', 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            document.getElementById('timestamp').textContent = now.toLocaleDateString('en-US', options);
        }
        
        updateTimestamp();
        setInterval(updateTimestamp, 1000);

        // Tab switching - FIXED: Added tabName parameter
        function switchTab(event, tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show selected tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(`${tabName}Tab`).classList.add('active');
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

        // Handle form submission
        document.getElementById('editSchoolForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('update_school.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('School updated successfully', 'success');
                    closeModal('editModal');
                    
                    // Reload page to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification(result.message || 'Failed to update school', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        });

        async function performAction(action, schoolId) {
            const actions = {
                backup: { message: 'Creating backup...', endpoint: 'backup_school.php' },
                restart: { message: 'Restarting services...', endpoint: 'restart_school.php' },
                suspend: { message: 'Suspending school...', endpoint: 'suspend_school.php' },
                terminate: { message: 'Terminating school...', endpoint: 'terminate_school.php' }
            };
            
            const actionInfo = actions[action];
            if (!actionInfo) return;
            
            showNotification(actionInfo.message, 'info');
            closeModal('actionsModal');
            
            try {
                const response = await fetch(actionInfo.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        school_id: schoolId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>' 
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(`${action} completed successfully`, 'success');
                    
                    // Reload page for suspend/terminate actions
                    if (action === 'suspend' || action === 'terminate') {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    }
                } else {
                    showNotification(result.message || `Failed to ${action}`, 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        function showNotification(message, type) {
            // Remove existing notifications
            document.querySelectorAll('[data-notification]').forEach(n => n.remove());
            
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 px-6 py-3 rounded-xl shadow-lg z-[1001] ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.setAttribute('data-notification', 'true');
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : 'info'}"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Chart initialization
        function initCharts() {
            // Performance Chart
            const perfCanvas = document.getElementById('performanceChart');
            if (perfCanvas) {
                const perfCtx = perfCanvas.getContext('2d');
                new Chart(perfCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($chartData['labels']); ?>,
                        datasets: [{
                            label: 'Active Users',
                            data: <?php echo json_encode($chartData['users']); ?>,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }

            // Usage Chart
            const usageCanvas = document.getElementById('usageChart');
            if (usageCanvas) {
                const usageCtx = usageCanvas.getContext('2d');
                new Chart(usageCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($chartData['labels']); ?>,
                        datasets: [{
                            label: 'API Calls',
                            data: <?php echo json_encode($chartData['apiCalls']); ?>,
                            backgroundColor: '#3b82f6'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }

            // Distribution Chart
            const distCanvas = document.getElementById('distributionChart');
            if (distCanvas) {
                const distCtx = distCanvas.getContext('2d');
                new Chart(distCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($userDistribution['labels']); ?>,
                        datasets: [{
                            data: <?php echo json_encode($userDistribution['data']); ?>,
                            backgroundColor: [
                                '#3b82f6',
                                '#10b981',
                                '#8b5cf6',
                                '#f59e0b'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }

        // Generate report
        async function generateReport(schoolId) {
            showNotification('Generating report...', 'info');
            
            try {
                const response = await fetch('generate_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        school_id: schoolId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>' 
                    })
                });
                
                const result = await response.json();
                
                if (result.success && result.download_url) {
                    showNotification('Report generated successfully', 'success');
                    
                    // Download report
                    const link = document.createElement('a');
                    link.href = result.download_url;
                    link.download = result.filename || 'school_report.pdf';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    showNotification(result.message || 'Failed to generate report', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('active');
            }
        }

        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            if (dropdown) {
                dropdown.classList.toggle('dropdown-open');
            }
        }

        function mobileSidebarToggle() {
            toggleSidebar();
        }

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
        });

        // Utility functions for quick actions
        function sendMessage(schoolId) {
            showNotification(`Opening message interface for school ${schoolId}...`, 'info');
        }

        function scheduleCall(schoolId) {
            showNotification('Schedule call feature coming soon', 'info');
        }

        function viewBilling(schoolId) {
            window.open(`billing.php?school_id=${schoolId}`, '_blank');
        }

        function addUser(schoolId) {
            window.open(`add_user.php?school_id=${schoolId}`, '_blank');
        }

        function editUser(userId) {
            showNotification(`Editing user ${userId}...`, 'info');
        }

        async function saveSettings(schoolId) {
            // Collect settings
            const settings = {
                api_limit: document.querySelector('select:nth-of-type(1)')?.value || '500 requests/min',
                data_retention: document.querySelector('select:nth-of-type(2)')?.value || '90 days',
                two_factor: document.querySelector('input[type="checkbox"]:nth-of-type(1)')?.checked || false,
                ip_whitelisting: document.querySelector('input[type="checkbox"]:nth-of-type(2)')?.checked || false
            };
            
            try {
                const response = await fetch('save_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        school_id: schoolId, 
                        ...settings,
                        csrf_token: '<?php echo generateCsrfToken(); ?>' 
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Settings saved successfully', 'success');
                } else {
                    showNotification(result.message || 'Failed to save settings', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        async function exportLogs(schoolId) {
            showNotification('Exporting activity logs...', 'info');
            
            try {
                const response = await fetch('export_logs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        school_id: schoolId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>' 
                    })
                });
                
                const result = await response.json();
                
                if (result.success && result.download_url) {
                    showNotification('Logs exported successfully', 'success');
                    
                    // Download logs
                    const link = document.createElement('a');
                    link.href = result.download_url;
                    link.download = result.filename || 'activity_logs.csv';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    showNotification(result.message || 'Failed to export logs', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth < 1024 && 
                sidebar && 
                !sidebar.contains(e.target) && 
                !e.target.closest('[onclick*="mobileSidebarToggle"]')) {
                sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.remove('active');
            }
        });

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar) sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.remove('active');
                
                // Close modals
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = 'auto';
            }
        });
    </script>
</body>
</html>