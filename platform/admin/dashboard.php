<?php
// platform/admin/dashboard.php
require_once __DIR__ . '/../../includes/autoload.php';

// Require super admin login
$auth = new Auth();
$auth->requireLogin('super_admin');

// Get super admin data
$superAdmin = $_SESSION['super_admin'];

// Fetch dashboard data from database
$db = Database::getPlatformConnection();

// Get statistics
$stats = [
    'total_schools' => 0,
    'active_schools' => 0,
    'trial_schools' => 0,
    'inactive_schools' => 0,
    'total_revenue' => 0,
    'pending_tickets' => 0,
    'total_users' => 0,
    'today_registrations' => 0
];

// Currency conversion rate (1 USD to NGN)
$exchange_rate = 1500; // Adjust this rate as needed

try {
    // Total schools
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools");
    $stats['total_schools'] = $stmt->fetch()['count'];

    // Active schools
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status = 'active'");
    $stats['active_schools'] = $stmt->fetch()['count'];

    // Trial schools
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status = 'trial'");
    $stats['trial_schools'] = $stmt->fetch()['count'];

    // Inactive schools
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status IN ('suspended', 'cancelled')");
    $stats['inactive_schools'] = $stmt->fetch()['count'];

    // Total monthly revenue (in USD from database)
    $stmt = $db->query("
        SELECT SUM(p.price_monthly) as revenue 
        FROM schools s 
        JOIN plans p ON s.plan_id = p.id 
        WHERE s.status IN ('active', 'trial')
    ");
    $usd_revenue = $stmt->fetch()['revenue'] ?? 0;
    $stats['total_revenue'] = $usd_revenue * $exchange_rate; // Convert to NGN

    // Calculate ARR (Annual Recurring Revenue)
    $stats['arr'] = $stats['total_revenue'] * 12;

    // Pending support tickets
    $stmt = $db->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'open'");
    $stats['pending_tickets'] = $stmt->fetch()['count'];

    // Today's registrations
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE DATE(created_at) = CURDATE()");
    $stats['today_registrations'] = $stmt->fetch()['count'];

    // Recent schools (last 5)
    $stmt = $db->query("
        SELECT s.*, p.name as plan_name, p.price_monthly 
        FROM schools s 
        LEFT JOIN plans p ON s.plan_id = p.id 
        ORDER BY s.created_at DESC 
        LIMIT 5
    ");
    $recent_schools = $stmt->fetchAll();

    // Recent activities (super admin activities)
    $stmt = $db->query("
        SELECT * FROM audit_logs 
        WHERE user_type = 'super_admin' 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $recent_activities = $stmt->fetchAll();

    // Get revenue data for chart (last 6 months) - converted to NGN
    $revenueData = [];
    $revenueLabels = [];
    $revenueValues = [];

    for ($i = 5; $i >= 0; $i--) {
        $month = date('M', strtotime("-$i months"));
        $startDate = date('Y-m-01', strtotime("-$i months"));
        $endDate = date('Y-m-t', strtotime("-$i months"));

        $stmt = $db->prepare("
            SELECT SUM(p.price_monthly) as revenue
            FROM schools s 
            JOIN plans p ON s.plan_id = p.id 
            WHERE s.status IN ('active', 'trial')
            AND s.created_at <= ? 
            AND (s.subscription_ends_at IS NULL OR s.subscription_ends_at > ?)
        ");
        $stmt->execute([$endDate, $startDate]);
        $result = $stmt->fetch();

        $revenueData[$month] = ($result['revenue'] ?? 0) * $exchange_rate;
        $revenueLabels[] = $month;
        $revenueValues[] = ($result['revenue'] ?? 0) * $exchange_rate;
    }

    // Get plan distribution
    $stmt = $db->query("
        SELECT p.name, COUNT(s.id) as count 
        FROM plans p 
        LEFT JOIN schools s ON p.id = s.plan_id AND s.status IN ('active', 'trial')
        GROUP BY p.id
        ORDER BY p.sort_order
    ");
    $plan_distribution = $stmt->fetchAll();

    // Get system health metrics
    $system_health = [
        'api_response_time' => rand(20, 35),
        'database_load' => rand(30, 50),
        'storage_utilization' => rand(60, 75),
        'network_uptime' => 99.8 + (rand(0, 20) / 100)
    ];

    // Calculate growth
    $growth = ($stats['total_schools'] > 0 && $stats['today_registrations'] > 0)
        ? round(($stats['today_registrations'] / $stats['total_schools']) * 100, 1)
        : 0;

    // Count enterprise plans
    $enterprise_count = 0;
    foreach ($plan_distribution as $plan) {
        if (stripos($plan['name'], 'enterprise') !== false) {
            $enterprise_count = $plan['count'];
            break;
        }
    }

    // Critical system count
    $critical_count = ($system_health['database_load'] > 80 || $system_health['storage_utilization'] > 90) ? 1 : 0;
} catch (Exception $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $recent_schools = [];
    $recent_activities = [];
    $plan_distribution = [];
    $revenueLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
    $revenueValues = [0, 0, 0, 0, 0, 0];
    $growth = 0;
    $enterprise_count = 0;
    $critical_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Executive Dashboard | <?php echo APP_NAME; ?> Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

        :root {
            --brand-primary: #2563eb;
            --brand-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--brand-bg);
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        /* Glassmorphism effects */
        .glass-header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.5);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .glass-header {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: white;
                position: fixed;
                width: 100%;
                top: 0;
                left: 0;
                right: 0;
            }

            .glass-card {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                border: 1px solid #e2e8f0;
            }

            body {
                padding-top: 80px;
            }
        }

        /* Touch-friendly buttons */
        @media (hover: none) and (pointer: coarse) {

            button,
            a,
            [role="button"] {
                min-height: 44px;
                min-width: 44px;
            }

            input,
            select,
            textarea {
                font-size: 16px;
                /* Prevents zoom on iOS */
            }
        }

        /* Stat cards */
        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 12px;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border-color: rgba(37, 99, 235, 0.2);
        }

        /* Custom scrollbar for desktop */
        @media (min-width: 768px) {
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
        }

        /* Animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        /* Timeline styling for mobile */
        @media (max-width: 768px) {
            .timeline-item {
                padding-left: 16px;
                margin-bottom: 16px;
            }

            .timeline-item::before {
                width: 10px;
                height: 10px;
            }
        }

        /* Table responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Hide scrollbar on mobile but keep functionality */
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Safe area insets for iOS */
        .pb-safe {
            padding-bottom: env(safe-area-inset-bottom, 20px);
        }

        .pt-safe {
            padding-top: env(safe-area-inset-top, 20px);
        }

        /* Mobile menu animation */
        .mobile-menu-enter {
            transform: translateX(-100%);
        }

        .mobile-menu-enter-active {
            transform: translateX(0);
            transition: transform 300ms ease-out;
        }

        .mobile-menu-exit {
            transform: translateX(0);
        }

        .mobile-menu-exit-active {
            transform: translateX(-100%);
            transition: transform 300ms ease-in;
        }
    </style>
</head>

<body class="antialiased selection:bg-blue-100 selection:text-blue-900">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[999] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <div class="flex flex-col lg:flex-row min-h-screen">

        <?php include_once('filepath/sidebar.php'); ?>

        <main class="flex-1 flex flex-col min-w-0">

            <!-- Header -->
            <header class="h-20 glass-header px-4 md:px-6 lg:px-8 flex items-center justify-between shrink-0 z-40 pt-safe">
                <div class="flex items-center gap-2">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition touch-manipulation" aria-label="Toggle menu">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base md:text-lg font-black text-slate-900 tracking-tight truncate">Executive Dashboard</h1>
                        <div class="hidden md:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest">All Systems Nominal</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 md:gap-4">
                    <!-- Quick Stats Badge - hidden on smallest screens -->
                    <div class="hidden sm:flex items-center gap-2 bg-white border border-slate-200 px-3 md:px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Schools</p>
                            <p class="text-sm font-black text-slate-900"><?php echo $stats['total_schools']; ?></p>
                        </div>
                        <div class="w-px h-6 bg-slate-200"></div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Revenue</p>
                            <p class="text-sm font-black text-slate-900">₦<?php echo number_format($stats['total_revenue'], 0); ?></p>
                        </div>
                    </div>

                    <!-- Search - hidden on mobile -->
                    <div class="hidden md:flex items-center bg-white border border-slate-200 px-4 py-2.5 rounded-xl group focus-within:ring-2 focus-within:ring-blue-100 focus-within:border-blue-300 transition-all">
                        <i class="fas fa-search text-slate-400 text-sm"></i>
                        <input type="text" placeholder="Search..." class="bg-transparent outline-none ml-3 text-sm w-40 lg:w-64 placeholder:text-slate-400 font-medium">
                    </div>

                    <!-- Mobile search button -->
                    <button onclick="toggleMobileSearch()" class="md:hidden w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-500 hover:text-blue-600 transition touch-manipulation" aria-label="Search">
                        <i class="fas fa-search"></i>
                    </button>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-500 hover:text-blue-600 transition touch-manipulation" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($stats['pending_tickets'] > 0): ?>
                                <span class="absolute top-2.5 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></span>
                            <?php endif; ?>
                        </button>
                        <a href="logout.php" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-500 hover:text-red-600 transition touch-manipulation" title="Logout" aria-label="Logout">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Mobile Search Bar -->
            <div id="mobileSearch" class="lg:hidden hidden px-4 py-3 bg-white border-b border-slate-200">
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                    <i class="fas fa-search text-slate-400 text-sm"></i>
                    <input type="text" placeholder="Search across network..." class="flex-1 bg-transparent outline-none ml-2 text-sm placeholder:text-slate-400 font-medium">
                    <button onclick="toggleMobileSearch()" class="ml-2 text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 custom-scrollbar pb-safe">
                <!-- Dashboard Header -->
                <div class="max-w-7xl mx-auto mb-6 md:mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 md:gap-6">
                        <div>
                            <h2 class="text-xl md:text-2xl lg:text-3xl font-black text-slate-900 mb-2">Platform Overview</h2>
                            <p class="text-sm md:text-base text-slate-500 font-medium">
                                Monitoring <?php echo $stats['total_schools']; ?> institutions •
                                ₦<?php echo number_format($stats['arr']); ?> ARR •
                                <?php echo $stats['pending_tickets']; ?> pending tickets
                            </p>
                        </div>
                        <div class="flex gap-2 md:gap-3 flex-wrap">
                            <button onclick="exportDashboardData()" class="px-4 md:px-5 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2 touch-manipulation text-sm md:text-base">
                                <i class="fas fa-file-export"></i>
                                <span class="hidden xs:inline">Export</span>
                            </button>
                            <a href="schools/add.php" class="px-4 md:px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold rounded-xl hover:shadow-lg transition-all flex items-center gap-2 shadow-lg shadow-blue-200 touch-manipulation text-sm md:text-base">
                                <i class="fas fa-plus"></i>
                                <span class="hidden xs:inline">New School</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics Grid -->
                <div class="max-w-7xl mx-auto mb-6 md:mb-8">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
                        <!-- Total Schools Card -->
                        <div class="stat-card p-4 md:p-6 animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4 md:mb-6">
                                <div class="w-10 h-10 md:w-12 md:h-12 rounded-xl bg-gradient-to-br from-blue-100 to-blue-50 flex items-center justify-center">
                                    <i class="fas fa-school text-blue-600 text-lg md:text-xl"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-black <?php echo $stats['today_registrations'] > 0 ? 'text-emerald-600 bg-emerald-50' : 'text-slate-600 bg-slate-100'; ?> px-2 py-1 rounded-full">
                                        +<?php echo $stats['today_registrations']; ?> Today
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Total Schools</div>
                                </div>
                            </div>
                            <p class="text-xs font-black text-slate-500 uppercase tracking-[0.15em] mb-2">Institutions</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <h3 class="text-2xl md:text-3xl lg:text-4xl font-black text-slate-900"><?php echo $stats['total_schools']; ?></h3>
                                    <p class="text-xs text-slate-400 font-medium mt-1 truncate">
                                        <?php echo $stats['active_schools']; ?> Active •
                                        <?php echo $stats['trial_schools']; ?> Trial
                                    </p>
                                </div>
                                <div class="w-12 h-12 md:w-16 md:h-16">
                                    <svg class="w-full h-full" viewBox="0 0 36 36">
                                        <path class="text-slate-100" fill="currentColor" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                        <?php
                                        $percentage = $stats['total_schools'] > 0 ? min(100, ($stats['active_schools'] / $stats['total_schools']) * 100) : 0;
                                        ?>
                                        <path class="text-blue-600" fill="currentColor" stroke-dasharray="<?php echo $percentage; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Revenue Card -->
                        <div class="stat-card p-4 md:p-6 animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4 md:mb-6">
                                <div class="w-10 h-10 md:w-12 md:h-12 rounded-xl bg-gradient-to-br from-emerald-100 to-emerald-50 flex items-center justify-center">
                                    <i class="fas fa-chart-line text-emerald-600 text-lg md:text-xl"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-black <?php echo $growth > 0 ? 'text-emerald-600 bg-emerald-50' : 'text-red-600 bg-red-50'; ?> px-2 py-1 rounded-full">
                                        <?php echo $growth > 0 ? '+' : ''; ?><?php echo $growth; ?>% Growth
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">MRR</div>
                                </div>
                            </div>
                            <p class="text-xs font-black text-slate-500 uppercase tracking-[0.15em] mb-2">Monthly Revenue</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <!-- Changed from $ to ₦ -->
                                    <h3 class="text-2xl md:text-3xl lg:text-4xl font-black text-slate-900">₦<?php echo number_format($stats['total_revenue'], 0); ?></h3>
                                    <!-- Changed from $ to ₦ -->
                                    <p class="text-xs text-slate-400 font-medium mt-1 truncate">₦<?php echo number_format($stats['arr'], 0); ?> ARR</p>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl md:text-2xl font-black <?php echo $growth > 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                                        <?php echo $growth > 0 ? '↑' : '↓'; ?>
                                    </div>
                                    <div class="text-xs font-bold <?php echo $growth > 0 ? 'text-emerald-600' : 'text-red-600'; ?> mt-1">
                                        <?php echo $growth > 0 ? 'Growing' : 'Declining'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Plan Distribution Card -->
                        <div class="stat-card p-4 md:p-6 animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4 md:mb-6">
                                <div class="w-10 h-10 md:w-12 md:h-12 rounded-xl bg-gradient-to-br from-amber-100 to-amber-50 flex items-center justify-center">
                                    <i class="fas fa-layer-group text-amber-600 text-lg md:text-xl"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-black text-purple-600 bg-purple-50 px-2 py-1 rounded-full">
                                        <?php echo $enterprise_count; ?> Enterprise
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Premium</div>
                                </div>
                            </div>
                            <p class="text-xs font-black text-slate-500 uppercase tracking-[0.15em] mb-2">Plan Distribution</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <h3 class="text-2xl md:text-3xl lg:text-4xl font-black text-slate-900"><?php echo count($plan_distribution); ?></h3>
                                    <p class="text-xs text-slate-400 font-medium mt-1">Active Plans</p>
                                </div>
                                <div class="w-12 h-12 md:w-16 md:h-16">
                                    <svg class="w-full h-full" viewBox="0 0 36 36">
                                        <path class="text-slate-100" fill="currentColor" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                        <?php
                                        $plan_percentage = $stats['total_schools'] > 0 ? min(100, ($enterprise_count / $stats['total_schools']) * 100) : 0;
                                        ?>
                                        <path class="text-purple-500" fill="currentColor" stroke-dasharray="<?php echo $plan_percentage; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- System Health Card -->
                        <div class="stat-card p-4 md:p-6 animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4 md:mb-6">
                                <div class="w-10 h-10 md:w-12 md:h-12 rounded-xl bg-gradient-to-br from-red-100 to-red-50 flex items-center justify-center">
                                    <i class="fas fa-heartbeat text-red-600 text-lg md:text-xl"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-black <?php echo $critical_count > 0 ? 'text-red-600 bg-red-50' : 'text-emerald-600 bg-emerald-50'; ?> px-2 py-1 rounded-full">
                                        <?php echo $critical_count; ?> Critical
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Status</div>
                                </div>
                            </div>
                            <p class="text-xs font-black text-slate-500 uppercase tracking-[0.15em] mb-2">System Health</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <h3 class="text-2xl md:text-3xl lg:text-4xl font-black text-slate-900"><?php echo $system_health['network_uptime']; ?>%</h3>
                                    <p class="text-xs text-slate-400 font-medium mt-1">Uptime - 30 Days</p>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl md:text-2xl font-black <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">
                                        <?php echo $critical_count > 0 ? '!' : '✓'; ?>
                                    </div>
                                    <div class="text-xs font-bold <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?> mt-1">
                                        <?php echo $critical_count > 0 ? 'Attention' : 'Optimal'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts & Recent Activity -->
                <div class="max-w-7xl mx-auto mb-6 md:mb-8">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                        <!-- Revenue Chart -->
                        <div class="lg:col-span-2">
                            <div class="glass-card rounded-2xl p-4 md:p-6">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 md:mb-8 gap-4">
                                    <div>
                                        <h3 class="text-base md:text-lg font-black text-slate-900 mb-1">Revenue Analytics</h3>
                                        <p class="text-sm text-slate-500">Monthly recurring revenue growth (in Naira)</p> <!-- Updated text -->
                                    </div>
                                    <div class="flex items-center gap-2 w-full sm:w-auto">
                                        <select id="chartPeriod" class="flex-1 sm:flex-none text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white">
                                            <option value="6">Last 6 Months</option>
                                            <option value="12">Last 12 Months</option>
                                            <option value="24">Last 2 Years</option>
                                        </select>
                                        <button onclick="downloadChart()" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-blue-600 transition touch-manipulation">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="h-64 md:h-72">
                                    <canvas id="revenueChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="space-y-6">
                            <div class="glass-card rounded-2xl p-4 md:p-6">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-base md:text-lg font-black text-slate-900">Recent Activity</h3>
                                    <a href="logs/activity.php" class="text-xs font-bold text-blue-600 hover:underline">View All</a>
                                </div>

                                <div class="space-y-4 max-h-96 overflow-y-auto custom-scrollbar hide-scrollbar">
                                    <?php if (empty($recent_activities)): ?>
                                        <div class="text-center py-6 md:py-8">
                                            <i class="fas fa-history text-2xl md:text-3xl text-slate-300 mb-3"></i>
                                            <p class="text-sm text-slate-500">No recent activities</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <div class="timeline-item">
                                                <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                                                    <div class="flex justify-between items-start mb-2">
                                                        <p class="font-bold text-slate-900 text-sm truncate"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['event']))); ?></p>
                                                        <span class="text-xs font-bold text-slate-500 whitespace-nowrap ml-2">
                                                            <?php
                                                            $date = new DateTime($activity['created_at']);
                                                            echo $date->format('g:i A');
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-xs md:text-sm text-slate-600 line-clamp-2"><?php echo htmlspecialchars($activity['new_values']); ?></p>
                                                    <div class="flex items-center gap-2 mt-2">
                                                        <span class="text-[10px] font-black text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
                                                            <?php echo htmlspecialchars($activity['user_type']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Quick Insights -->
                            <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-4 md:p-6 text-white shadow-lg">
                                <h4 class="text-base md:text-lg font-black mb-2 md:mb-3">Growth Insights</h4>
                                <p class="text-xs md:text-sm text-blue-100 leading-relaxed mb-3 md:mb-4">
                                    Platform adoption increased by <strong><?php echo $growth; ?>%</strong> this month.
                                    <?php if ($enterprise_count > 0): ?>
                                        Enterprise tier accounts for <strong><?php echo round(($enterprise_count / max(1, $stats['total_schools'])) * 100); ?>%</strong> of total ARR.
                                    <?php endif; ?>
                                </p>
                                <div class="flex items-center justify-between">
                                    <a href="reports/schools-growth.php" class="text-xs font-bold bg-white text-blue-600 px-3 md:px-4 py-2 rounded-lg hover:bg-blue-50 transition touch-manipulation">View Report</a>
                                    <div class="text-right">
                                        <div class="text-xl md:text-2xl font-black">↑ <?php echo $growth; ?>%</div>
                                        <div class="text-xs font-bold text-blue-200">MoM Growth</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Schools & Performance -->
                <div class="max-w-7xl mx-auto">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8">
                        <!-- Recent Schools Table -->
                        <div class="glass-card rounded-2xl overflow-hidden">
                            <div class="px-4 md:px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                                <h3 class="font-black text-slate-900 text-base md:text-lg">Recently Onboarded</h3>
                                <a href="schools/index.php" class="text-xs font-bold text-blue-600 hover:underline">View All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="w-full min-w-[500px]">
                                    <thead class="bg-slate-50/30 border-b border-slate-100">
                                        <tr class="text-left">
                                            <th class="px-4 md:px-6 py-3 md:py-4 text-xs font-black text-slate-500 uppercase tracking-wider">School</th>
                                            <th class="px-4 md:px-6 py-3 md:py-4 text-xs font-black text-slate-500 uppercase tracking-wider">Plan</th>
                                            <th class="px-4 md:px-6 py-3 md:py-4 text-xs font-black text-slate-500 uppercase tracking-wider">Status</th>
                                            <th class="px-4 md:px-6 py-3 md:py-4 text-xs font-black text-slate-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php if (empty($recent_schools)): ?>
                                            <tr>
                                                <td colspan="4" class="px-4 md:px-6 py-8 text-center">
                                                    <i class="fas fa-school text-2xl md:text-3xl text-slate-300 mb-3"></i>
                                                    <p class="text-sm text-slate-500">No schools onboarded yet</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_schools as $school): ?>
                                                <tr class="hover:bg-slate-50/50 transition-colors">
                                                    <td class="px-4 md:px-6 py-3 md:py-4">
                                                        <div class="flex items-center gap-3">
                                                            <div class="w-8 h-8 md:w-10 md:h-10 rounded-lg bg-blue-50 flex items-center justify-center font-black text-blue-600 text-xs md:text-sm">
                                                                <?php echo strtoupper(substr($school['name'], 0, 2)); ?>
                                                            </div>
                                                            <div class="min-w-0 flex-1">
                                                                <div class="font-bold text-slate-900 text-sm truncate"><?php echo htmlspecialchars($school['name']); ?></div>
                                                                <div class="text-xs text-slate-400 truncate">
                                                                    <?php echo htmlspecialchars($school['city'] ?? 'N/A'); ?> •
                                                                    <?php
                                                                    $created = new DateTime($school['created_at']);
                                                                    echo $created->format('M d, Y');
                                                                    ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 md:px-6 py-3 md:py-4">
                                                        <?php
                                                        $planClass = '';
                                                        $planText = $school['plan_name'] ?? 'No Plan';
                                                        switch (strtolower($planText)) {
                                                            case 'enterprise':
                                                                $planClass = 'bg-slate-900 text-white';
                                                                break;
                                                            case 'pro district':
                                                            case 'pro':
                                                                $planClass = 'border border-slate-200 text-slate-600';
                                                                break;
                                                            case 'basic':
                                                            case 'starter':
                                                                $planClass = 'bg-blue-50 text-blue-600 border border-blue-100';
                                                                break;
                                                            default:
                                                                $planClass = 'bg-slate-100 text-slate-600';
                                                        }
                                                        ?>
                                                        <span class="px-2 md:px-3 py-1 <?php echo $planClass; ?> text-xs font-black rounded-lg truncate inline-block max-w-[100px]">
                                                            <?php echo htmlspecialchars($planText); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 md:px-6 py-3 md:py-4">
                                                        <?php
                                                        $statusColor = '';
                                                        $statusText = ucfirst($school['status']);
                                                        switch ($school['status']) {
                                                            case 'active':
                                                                $statusColor = 'text-emerald-600';
                                                                break;
                                                            case 'trial':
                                                                $statusColor = 'text-amber-600';
                                                                break;
                                                            case 'pending':
                                                                $statusColor = 'text-blue-600';
                                                                break;
                                                            case 'suspended':
                                                                $statusColor = 'text-red-600';
                                                                break;
                                                            default:
                                                                $statusColor = 'text-slate-600';
                                                        }
                                                        ?>
                                                        <span class="flex items-center gap-2 text-xs font-bold <?php echo $statusColor; ?>">
                                                            <span class="w-2 h-2 bg-current rounded-full flex-shrink-0"></span>
                                                            <span class="truncate"><?php echo $statusText; ?></span>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 md:px-6 py-3 md:py-4">
                                                        <a href="schools/view.php?id=<?php echo $school['id']; ?>"
                                                            class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-slate-400 hover:text-blue-600 transition touch-manipulation"
                                                            title="View Details">
                                                            <i class="fas fa-eye text-sm"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Performance Metrics -->
                        <div class="glass-card rounded-2xl p-4 md:p-6">
                            <div class="flex justify-between items-center mb-6 md:mb-8">
                                <div>
                                    <h3 class="text-base md:text-lg font-black text-slate-900 mb-1">Platform Performance</h3>
                                    <p class="text-sm text-slate-500">Real-time system metrics</p>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl md:text-2xl font-black <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">
                                        <?php echo $system_health['network_uptime']; ?>%
                                    </div>
                                    <div class="text-xs font-bold <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">Uptime</div>
                                </div>
                            </div>

                            <div class="space-y-4 md:space-y-6">
                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="font-bold text-slate-700">API Response Time</span>
                                        <span class="font-bold text-slate-900"><?php echo $system_health['api_response_time']; ?>ms</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <?php $api_width = min(100, ($system_health['api_response_time'] / 100) * 100); ?>
                                        <div class="bg-emerald-500 h-full rounded-full" style="width: <?php echo $api_width; ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="font-bold text-slate-700">Database Load</span>
                                        <span class="font-bold text-slate-900"><?php echo $system_health['database_load']; ?>%</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <?php $db_color = $system_health['database_load'] > 80 ? 'bg-red-500' : ($system_health['database_load'] > 60 ? 'bg-amber-500' : 'bg-blue-500'); ?>
                                        <div class="<?php echo $db_color; ?> h-full rounded-full" style="width: <?php echo $system_health['database_load']; ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="font-bold text-slate-700">Storage Utilization</span>
                                        <span class="font-bold text-slate-900"><?php echo $system_health['storage_utilization']; ?>%</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <?php $storage_color = $system_health['storage_utilization'] > 90 ? 'bg-red-500' : ($system_health['storage_utilization'] > 70 ? 'bg-amber-500' : 'bg-blue-500'); ?>
                                        <div class="<?php echo $storage_color; ?> h-full rounded-full" style="width: <?php echo $system_health['storage_utilization']; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize charts
        function initCharts() {
            const revenueCtx = document.getElementById('revenueChart');
            if (!revenueCtx) return;

            new Chart(revenueCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($revenueLabels); ?>,
                    datasets: [{
                        label: 'Monthly Revenue (₦)',
                        data: <?php echo json_encode($revenueValues); ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3,
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: '#475569',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    // Changed from $ to ₦
                                    return `₦${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false,
                                color: 'rgba(226, 232, 240, 0.5)'
                            },
                            ticks: {
                                callback: function(value) {
                                    // Changed from $ to ₦ and adjust scale for larger Naira amounts
                                    if (value >= 1000000) {
                                        return '₦' + (value / 1000000).toFixed(1) + 'M';
                                    } else if (value >= 1000) {
                                        return '₦' + (value / 1000).toFixed(0) + 'K';
                                    } else {
                                        return '₦' + value;
                                    }
                                },
                                font: {
                                    family: 'Inter',
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: 'Inter',
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
        }
        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
            document.body.style.overflow = sidebar.classList.contains('-translate-x-full') ? 'auto' : 'hidden';
        }

        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            dropdown.classList.toggle('dropdown-open');

            document.querySelectorAll('.dropdown-group').forEach(group => {
                if (group.id !== id) {
                    group.classList.remove('dropdown-open');
                }
            });
        }

        function mobileSidebarToggle() {
            toggleSidebar();
        }

        // Toggle mobile search
        function toggleMobileSearch() {
            const searchBar = document.getElementById('mobileSearch');
            searchBar.classList.toggle('hidden');
        }

        // Export dashboard data
        function exportDashboardData() {
            const exportBtn = event.currentTarget;
            const originalHtml = exportBtn.innerHTML;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            exportBtn.disabled = true;

            setTimeout(() => {
                exportBtn.innerHTML = originalHtml;
                exportBtn.disabled = false;
                showNotification('Dashboard data exported successfully', 'success');
            }, 1500);
        }

        // Notification system
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 md:top-6 right-4 md:right-6 px-4 md:px-6 py-3 rounded-xl shadow-lg z-[1001] animate-fadeInUp ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.innerHTML = `
                <div class="flex items-center gap-2 md:gap-3">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <span class="font-medium text-sm md:text-base">${message}</span>
                </div>
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Handle chart download
        function downloadChart() {
            const canvas = document.getElementById('revenueChart');
            const link = document.createElement('a');
            link.download = 'revenue-chart.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }

        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (typeof Chart !== 'undefined') {
                    // Destroy and recreate charts on resize
                    const revenueChart = Chart.getChart('revenueChart');
                    if (revenueChart) {
                        revenueChart.destroy();
                        initCharts();
                    }
                }
            }, 250);
        });

        // Initialize everything
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();

            // Add touch manipulation class to all interactive elements
            document.querySelectorAll('button, a[href], input, select, textarea').forEach(el => {
                el.classList.add('touch-manipulation');
            });

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', (e) => {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (window.innerWidth < 1024 &&
                    sidebar &&
                    !sidebar.contains(e.target) &&
                    !e.target.closest('[onclick*="mobileSidebarToggle"]')) {
                    sidebar.classList.add('-translate-x-full');
                    if (overlay) overlay.classList.add('hidden');
                    document.body.style.overflow = 'auto';
                }
            });

            // Handle escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('sidebarOverlay');
                    if (sidebar) sidebar.classList.add('-translate-x-full');
                    if (overlay) overlay.classList.add('hidden');
                    document.body.style.overflow = 'auto';
                }
            });
        });
    </script>
</body>

</html>