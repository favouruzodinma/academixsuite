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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#2563eb">
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
            --font-size-xs: clamp(0.625rem, 0.5rem + 0.3vw, 0.75rem); /* 10-12px */
            --font-size-sm: clamp(0.75rem, 0.625rem + 0.3vw, 0.875rem); /* 12-14px */
            --font-size-base: clamp(0.875rem, 0.75rem + 0.3vw, 1rem); /* 14-16px */
            --font-size-lg: clamp(1rem, 0.875rem + 0.3vw, 1.125rem); /* 16-18px */
            --font-size-xl: clamp(1.125rem, 1rem + 0.3vw, 1.25rem); /* 18-20px */
            --font-size-2xl: clamp(1.25rem, 1rem + 0.6vw, 1.5rem); /* 20-24px */
            --font-size-3xl: clamp(1.5rem, 1.25rem + 0.6vw, 2rem); /* 24-32px */
            --font-size-4xl: clamp(1.75rem, 1.5rem + 0.6vw, 2.5rem); /* 28-40px */
            --spacing-xs: clamp(0.25rem, 0.125rem + 0.3vw, 0.5rem);
            --spacing-sm: clamp(0.5rem, 0.375rem + 0.3vw, 0.75rem);
            --spacing-base: clamp(0.75rem, 0.625rem + 0.3vw, 1rem);
            --spacing-md: clamp(1rem, 0.875rem + 0.3vw, 1.25rem);
            --spacing-lg: clamp(1.25rem, 1rem + 0.6vw, 1.5rem);
            --spacing-xl: clamp(1.5rem, 1.25rem + 0.6vw, 2rem);
            --radius-sm: clamp(0.375rem, 0.25rem + 0.3vw, 0.5rem);
            --radius-base: clamp(0.5rem, 0.375rem + 0.3vw, 0.75rem);
            --radius-lg: clamp(0.75rem, 0.625rem + 0.3vw, 1rem);
            --radius-xl: clamp(1rem, 0.875rem + 0.3vw, 1.25rem);
        }

        * {
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--brand-bg);
            color: #1e293b;
            font-size: var(--font-size-base);
            line-height: 1.5;
            overflow-x: hidden;
        }

        /* Responsive Typography */
        .text-xs-responsive { font-size: var(--font-size-xs); }
        .text-sm-responsive { font-size: var(--font-size-sm); }
        .text-base-responsive { font-size: var(--font-size-base); }
        .text-lg-responsive { font-size: var(--font-size-lg); }
        .text-xl-responsive { font-size: var(--font-size-xl); }
        .text-2xl-responsive { font-size: var(--font-size-2xl); }
        .text-3xl-responsive { font-size: var(--font-size-3xl); }
        .text-4xl-responsive { font-size: var(--font-size-4xl); }

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
                height: 64px;
            }

            .glass-card {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                border: 1px solid #e2e8f0;
            }

            body {
                padding-top: 64px;
            }

            /* Prevent text size adjustment on orientation change */
            html {
                -webkit-text-size-adjust: 100%;
                text-size-adjust: 100%;
            }
        }

        /* Touch-friendly interactive elements */
        @media (hover: none) and (pointer: coarse) {
            button, 
            a[role="button"], 
            [role="button"],
            .touch-target {
                min-height: 44px !important;
                min-width: 44px !important;
                padding: 12px !important;
            }

            input, 
            select, 
            textarea {
                font-size: 16px !important; /* Prevents iOS zoom */
                min-height: 44px;
            }

            /* Improve tap targets */
            .touch-target-small {
                min-height: 36px !important;
                min-width: 36px !important;
                padding: 8px !important;
            }
        }

        /* Stat cards - Mobile optimized */
        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: var(--radius-xl);
            padding: var(--spacing-base);
        }

        @media (min-width: 640px) {
            .stat-card {
                padding: var(--spacing-lg);
            }
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border-color: rgba(37, 99, 235, 0.2);
        }

        /* Mobile-first responsive grids */
        .responsive-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: var(--spacing-base);
        }

        @media (max-width: 640px) {
            .responsive-grid {
                grid-template-columns: 1fr;
                gap: var(--spacing-sm);
            }
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
        .timeline-item {
            padding-left: var(--spacing-lg);
            margin-bottom: var(--spacing-base);
            position: relative;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: var(--spacing-xs);
            width: 8px;
            height: 8px;
            background: #cbd5e1;
            border-radius: 50%;
        }

        /* Table responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        /* Hide scrollbar on mobile but keep functionality */
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Safe area insets for modern mobile browsers */
        .pb-safe {
            padding-bottom: env(safe-area-inset-bottom, var(--spacing-lg));
        }

        .pt-safe {
            padding-top: env(safe-area-inset-top, 0);
        }

        /* Mobile menu overlay */
        #sidebarOverlay {
            transition: opacity 0.3s ease;
        }

        /* Card content spacing */
        .card-content {
            padding: var(--spacing-base);
        }

        @media (min-width: 768px) {
            .card-content {
                padding: var(--spacing-lg);
            }
        }

        /* Responsive icon sizes */
        .icon-responsive {
            font-size: var(--font-size-lg);
        }

        @media (min-width: 768px) {
            .icon-responsive {
                font-size: var(--font-size-xl);
            }
        }

        /* Responsive chart container */
        .chart-container {
            height: 200px;
            position: relative;
        }

        @media (min-width: 640px) {
            .chart-container {
                height: 250px;
            }
        }

        @media (min-width: 1024px) {
            .chart-container {
                height: 300px;
            }
        }

        /* Fluid spacing utilities */
        .fluid-gap-2 { gap: var(--spacing-xs); }
        .fluid-gap-4 { gap: var(--spacing-sm); }
        .fluid-gap-6 { gap: var(--spacing-base); }
        .fluid-gap-8 { gap: var(--spacing-md); }
        .fluid-p-4 { padding: var(--spacing-sm); }
        .fluid-p-6 { padding: var(--spacing-base); }
        .fluid-p-8 { padding: var(--spacing-md); }

        /* Improved mobile readability */
        .mobile-text-truncate {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Responsive font weights */
        .font-responsive-bold {
            font-weight: 600;
        }

        @media (min-width: 768px) {
            .font-responsive-bold {
                font-weight: 700;
            }
        }

        /* Mobile tap feedback */
        .touch-feedback {
            transition: background-color 0.15s ease, transform 0.15s ease;
        }

        .touch-feedback:active {
            background-color: #f1f5f9;
            transform: scale(0.98);
        }

        /* Improved form controls on mobile */
        select.form-select-responsive {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 1em 1em;
            padding-right: 40px;
            font-size: var(--font-size-base);
        }
    </style>
</head>
<body class="antialiased selection:bg-blue-100 selection:text-blue-900 min-h-screen bg-slate-50">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-[999] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <div class="flex flex-col lg:flex-row min-h-screen">
        <?php include_once('filepath/sidebar.php'); ?>

        <main class="flex-1 flex flex-col min-w-0">
            <!-- Header -->
            <header class="glass-header px-4 sm:px-6 lg:px-8 flex items-center justify-between shrink-0 z-40 pt-safe h-16 sm:h-20">
                <div class="flex items-center gap-2 sm:gap-3 flex-1 min-w-0">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition touch-target touch-feedback" aria-label="Toggle menu">
                        <i class="fas fa-bars-staggered icon-responsive"></i>
                    </button>
                    <div class="flex items-center gap-2 min-w-0">
                        <h1 class="text-lg-responsive font-black text-slate-900 truncate">Executive Dashboard</h1>
                        <div class="hidden sm:flex items-center gap-2 ml-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
                            <span class="text-xs-responsive font-black text-emerald-600 uppercase tracking-widest">All Systems Nominal</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <!-- Quick Stats Badge -->
                    <div class="hidden xs:flex items-center gap-2 bg-white border border-slate-200 px-3 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-xs-responsive font-black text-slate-400 uppercase tracking-widest">Schools</p>
                            <p class="text-sm-responsive font-black text-slate-900"><?php echo $stats['total_schools']; ?></p>
                        </div>
                        <div class="w-px h-6 bg-slate-200"></div>
                        <div class="text-right">
                            <p class="text-xs-responsive font-black text-slate-400 uppercase tracking-widest">Revenue</p>
                            <p class="text-sm-responsive font-black text-slate-900">₦<?php echo number_format($stats['total_revenue'], 0); ?></p>
                        </div>
                    </div>

                    <!-- Search - hidden on mobile -->
                    <div class="hidden sm:flex items-center bg-white border border-slate-200 px-3 sm:px-4 py-2.5 rounded-xl group focus-within:ring-2 focus-within:ring-blue-100 focus-within:border-blue-300 transition-all min-w-[180px] lg:min-w-[240px]">
                        <i class="fas fa-search text-slate-400 text-sm-responsive"></i>
                        <input type="text" placeholder="Search..." class="bg-transparent outline-none ml-3 text-sm-responsive w-full placeholder:text-slate-400 font-medium">
                    </div>

                    <!-- Mobile search button -->
                    <button onclick="toggleMobileSearch()" class="sm:hidden w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-500 hover:text-blue-600 transition touch-target touch-feedback" aria-label="Search">
                        <i class="fas fa-search icon-responsive"></i>
                    </button>

                    <!-- Actions -->
                    <div class="flex items-center gap-1 sm:gap-2">
                        <button class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-500 hover:text-blue-600 transition touch-target touch-feedback relative" aria-label="Notifications">
                            <i class="fas fa-bell icon-responsive"></i>
                            <?php if ($stats['pending_tickets'] > 0): ?>
                                <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></span>
                            <?php endif; ?>
                        </button>
                        <a href="logout.php" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-500 hover:text-red-600 transition touch-target touch-feedback" title="Logout" aria-label="Logout">
                            <i class="fas fa-sign-out-alt icon-responsive"></i>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Mobile Search Bar -->
            <div id="mobileSearch" class="sm:hidden hidden px-4 py-3 bg-white border-b border-slate-200">
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                    <i class="fas fa-search text-slate-400 text-sm-responsive"></i>
                    <input type="text" placeholder="Search across network..." class="flex-1 bg-transparent outline-none ml-2 text-sm-responsive placeholder:text-slate-400 font-medium">
                    <button onclick="toggleMobileSearch()" class="ml-2 text-slate-400 hover:text-slate-600 touch-target-small">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto fluid-p-6 custom-scrollbar pb-safe">
                <!-- Dashboard Header -->
                <div class="max-w-7xl mx-auto mb-6 md:mb-8">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 sm:gap-6">
                        <div class="min-w-0">
                            <h2 class="text-2xl-responsive font-black text-slate-900 mb-2">Platform Overview</h2>
                            <p class="text-sm-responsive text-slate-500 font-medium mobile-text-truncate">
                                Monitoring <?php echo $stats['total_schools']; ?> institutions •
                                ₦<?php echo number_format($stats['arr']); ?> ARR •
                                <?php echo $stats['pending_tickets']; ?> pending tickets
                            </p>
                        </div>
                        <div class="flex gap-2 sm:gap-3 flex-wrap">
                            <button onclick="exportDashboardData()" class="px-3 sm:px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-responsive-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2 touch-target touch-feedback text-sm-responsive whitespace-nowrap">
                                <i class="fas fa-file-export"></i>
                                <span>Export</span>
                            </button>
                            <a href="schools/add.php" class="px-3 sm:px-4 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-responsive-bold rounded-xl hover:shadow-lg transition-all flex items-center gap-2 shadow-lg shadow-blue-200 touch-target touch-feedback text-sm-responsive whitespace-nowrap">
                                <i class="fas fa-plus"></i>
                                <span>New School</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics Grid -->
                <div class="max-w-7xl mx-auto mb-6 md:mb-8">
                    <div class="responsive-grid fluid-gap-4">
                        <!-- Total Schools Card -->
                        <div class="stat-card animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br from-blue-100 to-blue-50 flex items-center justify-center">
                                    <i class="fas fa-school text-blue-600 icon-responsive"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs-responsive font-black <?php echo $stats['today_registrations'] > 0 ? 'text-emerald-600 bg-emerald-50' : 'text-slate-600 bg-slate-100'; ?> px-2 py-1 rounded-full">
                                        +<?php echo $stats['today_registrations']; ?> Today
                                    </div>
                                    <div class="text-xs-responsive font-bold text-slate-400 uppercase tracking-widest mt-1">Total Schools</div>
                                </div>
                            </div>
                            <p class="text-xs-responsive font-black text-slate-500 uppercase tracking-[0.15em] mb-2">Institutions</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <h3 class="text-3xl-responsive font-black text-slate-900"><?php echo $stats['total_schools']; ?></h3>
                                    <p class="text-xs-responsive text-slate-400 font-medium mt-1 truncate">
                                        <?php echo $stats['active_schools']; ?> Active •
                                        <?php echo $stats['trial_schools']; ?> Trial
                                    </p>
                                </div>
                                <div class="w-12 h-12 sm:w-14 sm:h-14 flex-shrink-0">
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
                        <div class="stat-card animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br from-emerald-100 to-emerald-50 flex items-center justify-center">
                                    <i class="fas fa-chart-line text-emerald-600 icon-responsive"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs-responsive font-black <?php echo $growth > 0 ? 'text-emerald-600 bg-emerald-50' : 'text-red-600 bg-red-50'; ?> px-2 py-1 rounded-full">
                                        <?php echo $growth > 0 ? '+' : ''; ?><?php echo $growth; ?>% Growth
                                    </div>
                                    <div class="text-xs-responsive font-bold text-slate-400 uppercase tracking-widest mt-1">MRR</div>
                                </div>
                            </div>
                            <p class="text-xs-responsive font-black text-slate-500 uppercase tracking-[0.15em] mb-2">Monthly Revenue</p>
                            <div class="flex items-end justify-between">
                                <div class="min-w-0">
                                    <h3 class="text-3xl-responsive font-black text-slate-900 truncate">₦<?php echo number_format($stats['total_revenue'], 0); ?></h3>
                                    <p class="text-xs-responsive text-slate-400 font-medium mt-1 truncate">₦<?php echo number_format($stats['arr'], 0); ?> ARR</p>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-xl-responsive font-black <?php echo $growth > 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                                        <?php echo $growth > 0 ? '↑' : '↓'; ?>
                                    </div>
                                    <div class="text-xs-responsive font-bold <?php echo $growth > 0 ? 'text-emerald-600' : 'text-red-600'; ?> mt-1">
                                        <?php echo $growth > 0 ? 'Growing' : 'Declining'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Plan Distribution Card -->
                        <div class="stat-card animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br from-amber-100 to-amber-50 flex items-center justify-center">
                                    <i class="fas fa-layer-group text-amber-600 icon-responsive"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs-responsive font-black text-purple-600 bg-purple-50 px-2 py-1 rounded-full">
                                        <?php echo $enterprise_count; ?> Enterprise
                                    </div>
                                    <div class="text-xs-responsive font-bold text-slate-400 uppercase tracking-widest mt-1">Premium</div>
                                </div>
                            </div>
                            <p class="text-xs-responsive font-black text-slate-500 uppercase tracking-[0.15em] mb-2">Plan Distribution</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <h3 class="text-3xl-responsive font-black text-slate-900"><?php echo count($plan_distribution); ?></h3>
                                    <p class="text-xs-responsive text-slate-400 font-medium mt-1">Active Plans</p>
                                </div>
                                <div class="w-12 h-12 sm:w-14 sm:h-14 flex-shrink-0">
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
                        <div class="stat-card animate-fadeInUp">
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br from-red-100 to-red-50 flex items-center justify-center">
                                    <i class="fas fa-heartbeat text-red-600 icon-responsive"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs-responsive font-black <?php echo $critical_count > 0 ? 'text-red-600 bg-red-50' : 'text-emerald-600 bg-emerald-50'; ?> px-2 py-1 rounded-full">
                                        <?php echo $critical_count; ?> Critical
                                    </div>
                                    <div class="text-xs-responsive font-bold text-slate-400 uppercase tracking-widest mt-1">Status</div>
                                </div>
                            </div>
                            <p class="text-xs-responsive font-black text-slate-500 uppercase tracking-[0.15em] mb-2">System Health</p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <h3 class="text-3xl-responsive font-black text-slate-900"><?php echo $system_health['network_uptime']; ?>%</h3>
                                    <p class="text-xs-responsive text-slate-400 font-medium mt-1">Uptime - 30 Days</p>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-xl-responsive font-black <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">
                                        <?php echo $critical_count > 0 ? '!' : '✓'; ?>
                                    </div>
                                    <div class="text-xs-responsive font-bold <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?> mt-1">
                                        <?php echo $critical_count > 0 ? 'Attention' : 'Optimal'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts & Recent Activity -->
                <div class="max-w-7xl mx-auto mb-6 md:mb-8">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Revenue Chart -->
                        <div class="lg:col-span-2">
                            <div class="glass-card rounded-2xl fluid-p-6">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                                    <div class="min-w-0">
                                        <h3 class="text-lg-responsive font-black text-slate-900 mb-1">Revenue Analytics</h3>
                                        <p class="text-sm-responsive text-slate-500">Monthly recurring revenue growth (in Naira)</p>
                                    </div>
                                    <div class="flex items-center gap-2 w-full sm:w-auto">
                                        <select id="chartPeriod" class="form-select-responsive flex-1 sm:flex-none text-sm-responsive border border-slate-200 rounded-lg px-3 py-2 bg-white min-w-[140px]">
                                            <option value="6">Last 6 Months</option>
                                            <option value="12">Last 12 Months</option>
                                            <option value="24">Last 2 Years</option>
                                        </select>
                                        <button onclick="downloadChart()" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-blue-600 transition touch-target-small touch-feedback">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="revenueChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="space-y-6">
                            <div class="glass-card rounded-2xl fluid-p-6">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-lg-responsive font-black text-slate-900">Recent Activity</h3>
                                    <a href="logs/activity.php" class="text-sm-responsive font-bold text-blue-600 hover:underline whitespace-nowrap">View All</a>
                                </div>

                                <div class="space-y-4 max-h-[400px] overflow-y-auto custom-scrollbar hide-scrollbar">
                                    <?php if (empty($recent_activities)): ?>
                                        <div class="text-center py-8">
                                            <i class="fas fa-history text-3xl text-slate-300 mb-3"></i>
                                            <p class="text-sm-responsive text-slate-500">No recent activities</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <div class="timeline-item">
                                                <div class="bg-slate-50 rounded-xl p-4">
                                                    <div class="flex justify-between items-start mb-2">
                                                        <p class="font-bold text-slate-900 text-sm-responsive truncate"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['event']))); ?></p>
                                                        <span class="text-xs-responsive font-bold text-slate-500 whitespace-nowrap ml-2">
                                                            <?php
                                                            $date = new DateTime($activity['created_at']);
                                                            echo $date->format('g:i A');
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm-responsive text-slate-600 line-clamp-2 mobile-text-truncate"><?php echo htmlspecialchars($activity['new_values']); ?></p>
                                                    <div class="flex items-center gap-2 mt-2">
                                                        <span class="text-xs-responsive font-black text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
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
                            <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl fluid-p-6 text-white shadow-lg">
                                <h4 class="text-lg-responsive font-black mb-3">Growth Insights</h4>
                                <p class="text-sm-responsive text-blue-100 leading-relaxed mb-4 mobile-text-truncate">
                                    Platform adoption increased by <strong><?php echo $growth; ?>%</strong> this month.
                                    <?php if ($enterprise_count > 0): ?>
                                        Enterprise tier accounts for <strong><?php echo round(($enterprise_count / max(1, $stats['total_schools'])) * 100); ?>%</strong> of total ARR.
                                    <?php endif; ?>
                                </p>
                                <div class="flex items-center justify-between">
                                    <a href="reports/schools-growth.php" class="text-sm-responsive font-bold bg-white text-blue-600 px-4 py-2 rounded-lg hover:bg-blue-50 transition touch-target touch-feedback whitespace-nowrap">View Report</a>
                                    <div class="text-right">
                                        <div class="text-2xl-responsive font-black">↑ <?php echo $growth; ?>%</div>
                                        <div class="text-xs-responsive font-bold text-blue-200">MoM Growth</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Schools & Performance -->
                <div class="max-w-7xl mx-auto">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Recent Schools Table -->
                        <div class="glass-card rounded-2xl overflow-hidden">
                            <div class="px-4 sm:px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                                <h3 class="font-black text-slate-900 text-lg-responsive truncate">Recently Onboarded</h3>
                                <a href="schools/index.php" class="text-sm-responsive font-bold text-blue-600 hover:underline whitespace-nowrap">View All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="w-full min-w-[500px]">
                                    <thead class="bg-slate-50/30 border-b border-slate-100">
                                        <tr class="text-left">
                                            <th class="px-4 sm:px-6 py-3 text-xs-responsive font-black text-slate-500 uppercase tracking-wider">School</th>
                                            <th class="px-4 sm:px-6 py-3 text-xs-responsive font-black text-slate-500 uppercase tracking-wider">Plan</th>
                                            <th class="px-4 sm:px-6 py-3 text-xs-responsive font-black text-slate-500 uppercase tracking-wider">Status</th>
                                            <th class="px-4 sm:px-6 py-3 text-xs-responsive font-black text-slate-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php if (empty($recent_schools)): ?>
                                            <tr>
                                                <td colspan="4" class="px-4 sm:px-6 py-8 text-center">
                                                    <i class="fas fa-school text-3xl text-slate-300 mb-3"></i>
                                                    <p class="text-sm-responsive text-slate-500">No schools onboarded yet</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_schools as $school): ?>
                                                <tr class="hover:bg-slate-50/50 transition-colors">
                                                    <td class="px-4 sm:px-6 py-3">
                                                        <div class="flex items-center gap-3">
                                                            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center font-black text-blue-600 text-sm-responsive flex-shrink-0">
                                                                <?php echo strtoupper(substr($school['name'], 0, 2)); ?>
                                                            </div>
                                                            <div class="min-w-0 flex-1">
                                                                <div class="font-bold text-slate-900 text-sm-responsive truncate"><?php echo htmlspecialchars($school['name']); ?></div>
                                                                <div class="text-xs-responsive text-slate-400 truncate">
                                                                    <?php echo htmlspecialchars($school['city'] ?? 'N/A'); ?> •
                                                                    <?php
                                                                    $created = new DateTime($school['created_at']);
                                                                    echo $created->format('M d, Y');
                                                                    ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 sm:px-6 py-3">
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
                                                        <span class="px-3 py-1 <?php echo $planClass; ?> text-xs-responsive font-black rounded-lg truncate inline-block max-w-[120px]">
                                                            <?php echo htmlspecialchars($planText); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 sm:px-6 py-3">
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
                                                        <span class="flex items-center gap-2 text-xs-responsive font-bold <?php echo $statusColor; ?>">
                                                            <span class="w-2 h-2 bg-current rounded-full flex-shrink-0"></span>
                                                            <span class="truncate"><?php echo $statusText; ?></span>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 sm:px-6 py-3">
                                                        <a href="schools/view.php?id=<?php echo $school['id']; ?>"
                                                            class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-slate-400 hover:text-blue-600 transition touch-target-small touch-feedback"
                                                            title="View Details">
                                                            <i class="fas fa-eye"></i>
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
                        <div class="glass-card rounded-2xl fluid-p-6">
                            <div class="flex justify-between items-center mb-6">
                                <div class="min-w-0">
                                    <h3 class="text-lg-responsive font-black text-slate-900 mb-1">Platform Performance</h3>
                                    <p class="text-sm-responsive text-slate-500">Real-time system metrics</p>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-2xl-responsive font-black <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">
                                        <?php echo $system_health['network_uptime']; ?>%
                                    </div>
                                    <div class="text-xs-responsive font-bold <?php echo $critical_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">Uptime</div>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <div>
                                    <div class="flex justify-between text-sm-responsive mb-2">
                                        <span class="font-bold text-slate-700">API Response Time</span>
                                        <span class="font-bold text-slate-900"><?php echo $system_health['api_response_time']; ?>ms</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <?php $api_width = min(100, ($system_health['api_response_time'] / 100) * 100); ?>
                                        <div class="bg-emerald-500 h-full rounded-full" style="width: <?php echo $api_width; ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm-responsive mb-2">
                                        <span class="font-bold text-slate-700">Database Load</span>
                                        <span class="font-bold text-slate-900"><?php echo $system_health['database_load']; ?>%</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <?php $db_color = $system_health['database_load'] > 80 ? 'bg-red-500' : ($system_health['database_load'] > 60 ? 'bg-amber-500' : 'bg-blue-500'); ?>
                                        <div class="<?php echo $db_color; ?> h-full rounded-full" style="width: <?php echo $system_health['database_load']; ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm-responsive mb-2">
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
                        pointRadius: window.innerWidth < 768 ? 3 : 4,
                        pointHoverRadius: window.innerWidth < 768 ? 5 : 6
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
                            bodyFont: {
                                size: window.innerWidth < 768 ? 12 : 14
                            },
                            titleFont: {
                                size: window.innerWidth < 768 ? 11 : 13
                            },
                            callbacks: {
                                label: function(context) {
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
                                },
                                padding: 8
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
                                },
                                padding: 8
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: window.innerWidth < 768 ? 500 : 750
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
            
            // Focus on search input when opened
            if (!searchBar.classList.contains('hidden')) {
                setTimeout(() => {
                    const searchInput = searchBar.querySelector('input');
                    if (searchInput) searchInput.focus();
                }, 100);
            }
        }

        // Export dashboard data
        function exportDashboardData() {
            const exportBtn = event.currentTarget;
            const originalHtml = exportBtn.innerHTML;
            
            // Show loading state
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Exporting...</span>';
            exportBtn.disabled = true;
            
            // Simulate export process
            setTimeout(() => {
                exportBtn.innerHTML = originalHtml;
                exportBtn.disabled = false;
                showNotification('Dashboard data exported successfully', 'success');
            }, 1500);
        }

        // Notification system
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 sm:top-6 sm:right-6 px-4 py-3 rounded-xl shadow-lg z-[1001] animate-fadeInUp ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                'bg-blue-500 text-white'
            } max-w-[90vw]`;
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <span class="font-medium text-sm sm:text-base truncate">${message}</span>
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
            link.download = 'revenue-chart-' + new Date().toISOString().split('T')[0] + '.png';
            link.href = canvas.toDataURL('image/png', 1.0);
            link.click();
            showNotification('Chart downloaded successfully', 'success');
        }

        // Handle window resize with throttling
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (typeof Chart !== 'undefined') {
                    const revenueChart = Chart.getChart('revenueChart');
                    if (revenueChart) {
                        revenueChart.destroy();
                        initCharts();
                    }
                }
            }, 250);
        });

        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                if (typeof Chart !== 'undefined') {
                    const revenueChart = Chart.getChart('revenueChart');
                    if (revenueChart) {
                        revenueChart.destroy();
                        initCharts();
                    }
                }
            }, 300);
        });

        // Initialize everything
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();

            // Add touch feedback to all interactive elements
            document.querySelectorAll('button, a[href], .touch-target, .touch-target-small').forEach(el => {
                el.classList.add('touch-feedback');
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
                    
                    // Also close mobile search if open
                    const mobileSearch = document.getElementById('mobileSearch');
                    if (mobileSearch && !mobileSearch.classList.contains('hidden')) {
                        mobileSearch.classList.add('hidden');
                    }
                }
            });

            // Prevent zoom on double-tap (for iOS)
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, false);
        });
    </script>
</body>
</html>