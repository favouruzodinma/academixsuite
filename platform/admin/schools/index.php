<?php
// platform/admin/schools/index.php
require_once __DIR__ . '/../../../includes/autoload.php';

// Require super admin login
$auth = new Auth();
$auth->requireLogin('super_admin');

// Get super admin data
$superAdmin = $_SESSION['super_admin'];

// Fetch dashboard data from database
$db = Database::getPlatformConnection();

// NEW: Get current dollar to naira exchange rate
$exchangeRate = 1500; // You can make this dynamic by fetching from an API or database
// Example dynamic approach:
// $exchangeRate = $db->query("SELECT rate FROM exchange_rates WHERE currency_from = 'USD' AND currency_to = 'NGN' ORDER BY created_at DESC LIMIT 1")->fetch()['rate'] ?? 1500;

// NEW: Auto-suspend schools whose trial has ended but haven't subscribed
$currentDate = date('Y-m-d H:i:s');
$autoSuspendQuery = "UPDATE schools s 
                     LEFT JOIN subscriptions sub ON s.id = sub.school_id 
                     SET s.status = 'suspended' 
                     WHERE s.status = 'trial' 
                     AND s.trial_ends_at IS NOT NULL 
                     AND s.trial_ends_at < ? 
                     AND (sub.id IS NULL OR sub.status != 'active')";
$db->prepare($autoSuspendQuery)->execute([$currentDate]);

// Get filter parameters
$status = $_GET['status'] ?? null;
$search = $_GET['search'] ?? '';
$subscription_status = $_GET['subscription_status'] ?? null;
$plan_id = $_GET['plan_id'] ?? null;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get available plans for filter
$plans = $db->query("SELECT id, name FROM plans WHERE is_active = 1 ORDER BY price_monthly DESC")->fetchAll();

// Build WHERE clause
$whereConditions = [];
$params = [];

if ($status && in_array($status, ['pending', 'trial', 'active', 'suspended', 'cancelled'])) {
    $whereConditions[] = "s.status = ?";
    $params[] = $status;
}

if ($subscription_status && in_array($subscription_status, ['active', 'pending', 'canceled', 'past_due'])) {
    $whereConditions[] = "sub.status = ?";
    $params[] = $subscription_status;
}

if ($plan_id && is_numeric($plan_id)) {
    $whereConditions[] = "s.plan_id = ?";
    $params[] = $plan_id;
}

if (!empty($search)) {
    $whereConditions[] = "(s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.admin_name LIKE ? OR s.admin_email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$countSql = "SELECT COUNT(*) as total FROM schools s LEFT JOIN subscriptions sub ON s.id = sub.school_id $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalSchools = $countStmt->fetch()['total'];
$totalPages = ceil($totalSchools / $perPage);

// Get schools with subscription data
$sql = "
    SELECT 
        s.*,
        p.name as plan_name,
        p.price_monthly,
        p.student_limit,
        p.teacher_limit,
        p.storage_limit,
        sub.status as subscription_status,
        sub.current_period_end,
        s.trial_ends_at,
        sub.canceled_at,
        (SELECT COUNT(*) FROM school_admins sa WHERE sa.school_id = s.id) as admin_count,
        (SELECT COUNT(*) FROM invoices i WHERE i.school_id = s.id AND i.status = 'paid') as paid_invoices,
        (SELECT SUM(amount) FROM invoices i WHERE i.school_id = s.id AND i.status = 'paid') as total_revenue,
        DATEDIFF(
            COALESCE(sub.current_period_end, s.subscription_ends_at, DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 
            CURDATE()
        ) as days_until_renewal
    FROM schools s 
    LEFT JOIN plans p ON s.plan_id = p.id
    LEFT JOIN subscriptions sub ON s.id = sub.school_id
    $whereClause 
    ORDER BY s.created_at DESC 
    LIMIT ? OFFSET ?
";

$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$schools = $stmt->fetchAll();

// Get enhanced statistics with subscription data
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN s.status = 'trial' THEN 1 ELSE 0 END) as trial,
        SUM(CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN s.status = 'suspended' THEN 1 ELSE 0 END) as suspended,
        SUM(CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN s.status IN ('active', 'trial') THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN s.status = 'trial' AND s.trial_ends_at IS NOT NULL AND s.trial_ends_at > NOW() THEN 1 ELSE 0 END) as in_trial,
        SUM(CASE WHEN sub.status = 'active' AND sub.current_period_end < DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as renewing_soon,
        SUM(CASE WHEN sub.status = 'past_due' THEN 1 ELSE 0 END) as past_due,
        SUM(CASE WHEN sub.status = 'canceled' THEN 1 ELSE 0 END) as expired,
        SUM(p.price_monthly) as mrr,
        COUNT(DISTINCT p.id) as plan_count
    FROM schools s 
    LEFT JOIN plans p ON s.plan_id = p.id
    LEFT JOIN subscriptions sub ON s.id = sub.school_id
    WHERE s.status IN ('active', 'trial')
";
$statsStmt = $db->query($statsSql);
$stats = $statsStmt->fetch();

// Get revenue statistics
$revenueSql = "
    SELECT 
        YEAR(i.paid_at) as year,
        MONTH(i.paid_at) as month,
        SUM(i.amount) as revenue
    FROM invoices i
    WHERE i.status = 'paid' AND i.paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY YEAR(i.paid_at), MONTH(i.paid_at)
    ORDER BY year DESC, month DESC
    LIMIT 6
";
$revenueStmt = $db->query($revenueSql);
$revenueData = $revenueStmt->fetchAll();

// Calculate monthly recurring revenue (MRR) in Naira
$mrr_usd = $stats['mrr'] ?? 0;
$mrr = $mrr_usd * $exchangeRate; // Convert to Naira

// Calculate annual recurring revenue (ARR) in Naira
$arr = $mrr * 12;

// NEW: Function to convert USD to Naira
function convertToNaira($usdAmount, $exchangeRate = 1500) {
    if (!$usdAmount) return 0;
    return $usdAmount * $exchangeRate;
}

// Function to get status badge class
function getStatusBadge($status) {
    $badges = [
        'active' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'trial' => 'bg-blue-50 text-blue-600 border-blue-100',
        'pending' => 'bg-amber-50 text-amber-600 border-amber-100',
        'suspended' => 'bg-red-50 text-red-600 border-red-100',
        'cancelled' => 'bg-slate-50 text-slate-600 border-slate-100'
    ];
    return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
}

// Function to get subscription status badge
function getSubscriptionBadge($status) {
    if (!$status) {
        return 'bg-slate-50 text-slate-600 border-slate-100';
    }
    
    $badges = [
        'active' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'pending' => 'bg-amber-50 text-amber-600 border-amber-100',
        'canceled' => 'bg-rose-50 text-rose-600 border-rose-100',
        'expired' => 'bg-red-50 text-red-600 border-red-100',
        'past_due' => 'bg-orange-50 text-orange-600 border-orange-100'
    ];
    return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
}

// Function to get renewal status - updated to handle auto-suspension
function getRenewalStatus($school) {
    $schoolStatus = $school['status'] ?? 'pending';
    $subscriptionStatus = $school['subscription_status'] ?? null;
    $currentPeriodEnd = $school['current_period_end'] ?? null;
    $trialEndsAt = $school['trial_ends_at'] ?? null;
    $daysUntilRenewal = $school['days_until_renewal'] ?? 0;
    
    // Check if trial has ended but not subscribed
    if ($schoolStatus === 'trial' && $trialEndsAt && strtotime($trialEndsAt) < time()) {
        return ['class' => 'bg-red-100 text-red-600', 'text' => 'Trial Ended - Suspended'];
    }
    
    if ($schoolStatus !== 'active' && $schoolStatus !== 'trial') {
        return ['class' => 'bg-slate-100 text-slate-600', 'text' => ucfirst($schoolStatus)];
    }
    
    if ($subscriptionStatus === 'canceled') {
        return ['class' => 'bg-rose-100 text-rose-600', 'text' => 'Cancelled'];
    }
    
    if ($subscriptionStatus === 'past_due') {
        return ['class' => 'bg-orange-100 text-orange-600', 'text' => 'Past Due'];
    }
    
    if ($subscriptionStatus !== 'active') {
        return ['class' => 'bg-slate-100 text-slate-600', 'text' => 'Inactive'];
    }
    
    if (!$currentPeriodEnd) {
        return ['class' => 'bg-blue-100 text-blue-600', 'text' => 'No End Date'];
    }
    
    if ($daysUntilRenewal <= 0) {
        return ['class' => 'bg-red-100 text-red-600', 'text' => 'Expired'];
    } elseif ($daysUntilRenewal <= 7) {
        return ['class' => 'bg-orange-100 text-orange-600', 'text' => 'Renewing Soon'];
    } elseif ($daysUntilRenewal <= 30) {
        return ['class' => 'bg-amber-100 text-amber-600', 'text' => 'Next Month'];
    } else {
        return ['class' => 'bg-emerald-100 text-emerald-600', 'text' => 'Active'];
    }
}

// Function to get tier badge class
function getTierBadge($planName) {
    if (!$planName) {
        return 'bg-slate-100 text-slate-700';
    }
    
    $planName = strtolower($planName);
    if (strpos($planName, 'enterprise') !== false) {
        return 'bg-slate-900 text-white';
    } elseif (strpos($planName, 'growth') !== false) {
        return 'bg-purple-600 text-white';
    } elseif (strpos($planName, 'tether') !== false) {
        return 'bg-blue-600 text-white';
    } elseif (strpos($planName, 'starter') !== false || strpos($planName, 'basic') !== false) {
        return 'bg-slate-100 text-slate-700';
    } else {
        return 'bg-slate-100 text-slate-700';
    }
}

// Function to get initials from school name
function getInitials($name) {
    if (!$name) return 'SC';
    
    $words = explode(' ', $name);
    $initials = '';
    $count = 0;
    foreach ($words as $word) {
        if ($count >= 2) break;
        if (!empty(trim($word))) {
            $initials .= strtoupper(substr($word, 0, 1));
            $count++;
        }
    }
    return $initials ?: substr(strtoupper($name), 0, 2);
}

// Function to get random color class for avatar
function getAvatarColor($initials) {
    $colors = [
        'blue' => 'bg-blue-50 text-blue-600 border-blue-100',
        'purple' => 'bg-purple-50 text-purple-600 border-purple-100',
        'amber' => 'bg-amber-50 text-amber-600 border-amber-100',
        'emerald' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'rose' => 'bg-rose-50 text-rose-600 border-rose-100'
    ];
    $hash = crc32($initials) % count($colors);
    return array_values($colors)[$hash];
}

// Function to format money in Naira
function formatMoney($amount, $currency = '₦') {
    if (!$amount) return $currency . '0';
    
    $amount = floatval($amount);
    if ($amount >= 1000000) {
        return $currency . number_format($amount / 1000000, 1) . 'M';
    } elseif ($amount >= 1000) {
        return $currency . number_format($amount / 1000, 1) . 'K';
    } else {
        return $currency . number_format($amount, 0);
    }
}

// NEW: Function to show both USD and Naira
function formatDualCurrency($usdAmount, $exchangeRate = 1500, $usdSymbol = '$', $nairaSymbol = '₦') {
    $nairaAmount = convertToNaira($usdAmount, $exchangeRate);
    if ($usdAmount <= 0) {
        return '<span class="text-slate-400">Free</span>';
    }
    return '<div>
                <span class="font-bold text-slate-800">' . $nairaSymbol . number_format($nairaAmount, 0) . '</span>
                <div class="text-xs text-slate-500 mt-0.5">' . $usdSymbol . number_format($usdAmount, 2) . ' USD</div>
            </div>';
}

// Function to format storage
function formatStorage($bytes) {
    if (!$bytes || $bytes <= 0) return '0 GB';
    
    if ($bytes >= 1073741824) { // 1GB
        return number_format($bytes / 1073741824, 1) . ' GB';
    } elseif ($bytes >= 1048576) { // 1MB
        return number_format($bytes / 1048576, 1) . ' MB';
    } else {
        return number_format($bytes / 1024, 1) . ' KB';
    }
}

// Calculate health index
$healthIndex = $stats['total'] > 0 
    ? round(($stats['operational'] / $stats['total']) * 100, 1) 
    : 100;

// Calculate churn risk
$churnRisk = 0;
if ($stats['total'] > 0) {
    $churnRisk = (($stats['past_due'] ?? 0) + ($stats['renewing_soon'] ?? 0)) / $stats['total'] * 100;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Schools Registry | <?php echo APP_NAME; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

        .glass-header { 
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
        }
        
        .registry-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); 
        }

        .status-pulse {
            height: 8px; 
            width: 8px; 
            border-radius: 50%;
            display: inline-block; 
            position: relative;
        }
        .status-pulse.online { 
            background: #22c55e; 
        }
        .status-pulse.online::after {
            content: ''; 
            position: absolute; 
            width: 100%; 
            height: 100%;
            background: #22c55e; 
            border-radius: 50%; 
            animation: pulse-green 2s infinite;
        }
        
        @keyframes pulse-green { 
            0% { transform: scale(1); opacity: 0.8; } 
            100% { transform: scale(3); opacity: 0; } 
        }

        /* Progress bar */
        .progress-bar {
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-high { background: #ef4444; }
        .progress-medium { background: #f59e0b; }
        .progress-low { background: #10b981; }

        /* Responsive Visibility */
        .desktop-view { display: table; }
        .mobile-view { display: none; }
        
        @media (max-width: 1024px) {
            .desktop-view { display: none; }
            .mobile-view { display: block; }
        }

        /* Mobile cards */
        .mobile-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid #f1f5f9;
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-blue-100">

    <div class="flex h-screen overflow-hidden">
        
        <?php include_once('../filepath/sidebar.php'); ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Schools Registry</h1>
                        <span class="px-2 py-0.5 bg-blue-600 text-[10px] text-white font-black rounded uppercase">Live</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- NEW: Email Notification Button -->
                    <a href="send-email.php" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 shadow-lg touch-target">
                        <i class="fas fa-envelope"></i>
                        <span class="hidden sm:inline">Send Email</span>
                    </a>
                    <form method="GET" class="hidden lg:flex items-center bg-slate-100 border border-slate-200 px-3.5 py-1.5 rounded-xl group transition-all focus-within:ring-2 focus-within:ring-blue-100">
                        <i class="fas fa-search text-slate-400 text-xs"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search schools..." class="bg-transparent text-sm outline-none ml-2.5 w-48 lg:w-64 placeholder:text-slate-400 font-medium">
                        <?php if ($status): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                        <?php endif; ?>
                    </form>
                    <a href="add.php" class="bg-slate-900 hover:bg-blue-600 text-white px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 shadow-lg touch-target">
                        <i class="fas fa-plus text-[10px]"></i>
                        <span class="hidden sm:inline">Onboard School</span>
                    </a>
                </div>
            </header>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8 space-y-6">
                
                <!-- Enhanced Subscription Monitoring Dashboard -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- MRR Card - Updated to show Naira -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Monthly Revenue</p>
                            <i class="fas fa-chart-line text-blue-200"></i>
                        </div>
                        <p class="text-xl font-black text-blue-600"><?php echo formatMoney($mrr); ?><span class="text-xs font-bold text-slate-400 ml-1">MRR (₦)</span></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 mr-1"></span>
                            <?php echo formatMoney($arr); ?> ARR (₦)
                            <div class="text-[10px] text-slate-400 mt-0.5">
                                Exchange Rate: $1 = ₦<?php echo number_format($exchangeRate); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Active Schools -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Active Schools</p>
                            <i class="fas fa-heartbeat text-emerald-200"></i>
                        </div>
                        <p class="text-xl font-black <?php echo $stats['active'] >= $stats['total'] * 0.8 ? 'text-emerald-600' : ($stats['active'] >= $stats['total'] * 0.6 ? 'text-amber-600' : 'text-red-600'); ?>">
                            <?php echo $stats['active']; ?><span class="text-xs font-bold text-slate-400 ml-1">Active</span>
                        </p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-blue-500 mr-1"></span>
                            <?php echo $stats['trial']; ?> trial
                        </div>
                    </div>
                    
                    <!-- Trial Status -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Trial Status</p>
                            <i class="fas fa-clock text-amber-200"></i>
                        </div>
                        <p class="text-xl font-black text-amber-600"><?php echo $stats['in_trial']; ?><span class="text-xs font-bold text-slate-400 ml-1">In Trial</span></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-purple-500 mr-1"></span>
                            <?php echo $stats['plan_count']; ?> plan types
                        </div>
                    </div>
                    
                    <!-- Churn Risk -->
                    <div class="bg-slate-900 p-4 rounded-2xl shadow-lg">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-300 uppercase">Churn Risk</p>
                            <i class="fas fa-exclamation-triangle text-red-400"></i>
                        </div>
                        <p class="text-xl font-black text-white">
                            <?php echo round($churnRisk); ?>%
                        </p>
                        <div class="mt-1 text-xs text-slate-300">
                            <span class="inline-block w-2 h-2 rounded-full bg-red-500 mr-1"></span>
                            <?php echo $stats['past_due'] ?? 0; ?> past due
                        </div>
                    </div>
                </div>

                <!-- Schools Table -->
                <div class="bg-white rounded-2xl registry-card overflow-hidden">
                    <div class="p-4 border-b border-slate-100 bg-slate-50/30 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center rounded-xl bg-white border border-slate-200 p-1">
                                <a href="?" class="px-3 py-2 text-xs font-black uppercase <?php echo !$status ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">All</a>
                                <a href="?status=active" class="px-3 py-2 text-xs font-black uppercase <?php echo $status === 'active' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Active</a>
                                <a href="?status=trial" class="px-3 py-2 text-xs font-black uppercase <?php echo $status === 'trial' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Trial</a>
                                <a href="?status=pending" class="px-3 py-2 text-xs font-black uppercase <?php echo $status === 'pending' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Pending</a>
                            </div>
                        </div>
                        <p class="text-xs font-black text-slate-500 uppercase opacity-60">Last Sync: <?php echo date('H:i T'); ?></p>
                    </div>

                    <!-- Desktop Table View -->
                    <?php if (!empty($schools)): ?>
                    <div class="desktop-view overflow-x-auto">
                        <table class="w-full text-left min-w-[1000px]">
                            <thead>
                                <tr class="text-xs font-black text-slate-400 uppercase bg-slate-50/50 border-b border-slate-100">
                                    <th class="px-6 py-4">Institution</th>
                                    <th class="px-6 py-4">Plan & Pricing</th>
                                    <th class="px-6 py-4">Renewal</th>
                                    <th class="px-6 py-4">Revenue</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($schools as $school): 
                                    $initials = getInitials($school['name']);
                                    $avatarColor = getAvatarColor($initials);
                                    $engagementScore = min(100, (($school['admin_count'] * 10) + ($school['paid_invoices'] * 5)));
                                    $renewalStatus = getRenewalStatus($school);
                                ?>
                                <tr class="hover:bg-blue-50/30 transition-all">
                                    <!-- Institution Column -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm border <?php echo $avatarColor; ?>">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div>
                                                <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($school['name']); ?></div>
                                                <div class="text-xs text-slate-400 uppercase mt-0.5">ID: <?php echo $school['id']; ?></div>
                                                <div class="text-xs text-slate-500 mt-0.5">
                                                    <?php echo htmlspecialchars($school['admin_name'] ?? 'No Admin'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Plan & Pricing Column - Updated to show dual currency -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-1">
                                            <span class="px-2.5 py-1 rounded-lg text-xs font-black uppercase <?php echo getTierBadge($school['plan_name'] ?? 'Starter'); ?>">
                                                <?php echo $school['plan_name'] ?? 'Starter'; ?>
                                            </span>
                                            <div class="text-sm font-medium text-slate-800">
                                                <?php echo formatDualCurrency($school['price_monthly'] ?? 0, $exchangeRate); ?>
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                <?php 
                                                $storageLimit = ($school['storage_limit'] ?? 0) * 1024 * 1024;
                                                echo formatStorage($storageLimit); 
                                                ?> storage
                                            </div>
                                            <?php if ($school['trial_ends_at']): 
                                                $trialEndTimestamp = strtotime($school['trial_ends_at']);
                                                $now = time();
                                                $daysLeft = floor(($trialEndTimestamp - $now) / (60 * 60 * 24));
                                            ?>
                                            <div class="text-xs <?php echo $daysLeft <= 0 ? 'text-red-600' : ($daysLeft <= 3 ? 'text-amber-600' : 'text-blue-600'); ?>">
                                                <i class="fas fa-clock mr-1"></i>
                                                Trial <?php echo $daysLeft <= 0 ? 'ended' : 'ends'; ?> 
                                                <?php echo date('M j', $trialEndTimestamp); ?>
                                                <?php if ($daysLeft > 0): ?>
                                                    (<?php echo $daysLeft; ?> days left)
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Renewal Column -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-2">
                                            <span class="px-2.5 py-1 rounded-lg text-xs font-bold <?php echo $renewalStatus['class']; ?>">
                                                <?php echo $renewalStatus['text']; ?>
                                            </span>
                                            <?php if ($school['current_period_end']): ?>
                                            <div class="text-sm font-medium text-slate-800">
                                                <?php echo date('M j, Y', strtotime($school['current_period_end'])); ?>
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                <?php 
                                                $daysLeft = $school['days_until_renewal'] ?? 0;
                                                if ($daysLeft > 0) {
                                                    echo $daysLeft . ' days left';
                                                } elseif ($daysLeft < 0) {
                                                    echo abs($daysLeft) . ' days overdue';
                                                } else {
                                                    echo 'Expires today';
                                                }
                                                ?>
                                            </div>
                                            <?php elseif ($school['canceled_at']): ?>
                                            <div class="text-xs text-rose-600">
                                                <i class="fas fa-ban mr-1"></i>
                                                Cancelled <?php echo date('M j', strtotime($school['canceled_at'])); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Revenue Column - Updated to show Naira -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-1">
                                            <div class="text-sm font-black text-slate-900">
                                                <?php echo formatMoney(convertToNaira($school['total_revenue'] ?? 0, $exchangeRate)); ?>
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                <?php echo $school['paid_invoices']; ?> payments
                                            </div>
                                            <div class="progress-bar mt-1">
                                                <?php 
                                                $usagePercent = $engagementScore;
                                                $colorClass = $usagePercent > 80 ? 'progress-high' : ($usagePercent > 50 ? 'progress-medium' : 'progress-low');
                                                ?>
                                                <div class="progress-fill <?php echo $colorClass; ?>" style="width: <?php echo $usagePercent; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Status Column -->
                                    <td class="px-6 py-4">
                                        <?php 
                                        $schoolStatus = $school['status'] ?? 'pending';
                                        $statusText = ucfirst($schoolStatus);
                                        $statusColor = '';
                                        $statusIcon = '';
                                        
                                        switch ($schoolStatus) {
                                            case 'active':
                                                $statusColor = 'text-emerald-600';
                                                $statusIcon = '<span class="status-pulse online"></span>';
                                                $statusText = 'Operational';
                                                break;
                                            case 'trial':
                                                $statusColor = 'text-blue-600';
                                                $statusIcon = '<i class="fas fa-clock"></i>';
                                                break;
                                            case 'pending':
                                                $statusColor = 'text-amber-600';
                                                $statusIcon = '<i class="fas fa-hourglass-half"></i>';
                                                break;
                                            case 'suspended':
                                                $statusColor = 'text-red-600';
                                                $statusIcon = '<i class="fas fa-pause-circle"></i>';
                                                break;
                                            case 'cancelled':
                                                $statusColor = 'text-slate-600';
                                                $statusIcon = '<i class="fas fa-ban"></i>';
                                                break;
                                            default:
                                                $statusColor = 'text-slate-600';
                                                $statusIcon = '<i class="fas fa-question-circle"></i>';
                                        }
                                        ?>
                                        <span class="flex items-center gap-2 text-sm <?php echo $statusColor; ?>">
                                            <?php echo $statusIcon; ?> <?php echo $statusText; ?>
                                        </span>
                                        <?php if ($school['subscription_status'] && $school['subscription_status'] !== 'active'): ?>
                                        <div class="text-xs mt-1 <?php echo getSubscriptionBadge($school['subscription_status']); ?> px-2 py-1 rounded inline-block">
                                            <?php echo ucfirst($school['subscription_status']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Actions Column -->
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <a href="view.php?id=<?php echo $school['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="manage.php?id=<?php echo $school['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center" title="Manage">
                                                <i class="fas fa-cog"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $school['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="mobile-view space-y-3 p-4">
                        <?php foreach ($schools as $school): 
                            $initials = getInitials($school['name']);
                            $avatarColor = getAvatarColor($initials);
                            $schoolStatus = $school['status'] ?? 'pending';
                            $planName = $school['plan_name'] ?? 'Starter';
                            $tierClass = getTierBadge($planName);
                            $engagementScore = min(100, (($school['admin_count'] * 10) + ($school['paid_invoices'] * 5)));
                            $renewalStatus = getRenewalStatus($school);
                        ?>
                        <div class="mobile-card">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black border <?php echo $avatarColor; ?>">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($school['name']); ?></h3>
                                        <p class="text-xs text-slate-400"><?php echo htmlspecialchars($school['admin_name'] ?? 'No Admin'); ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="px-2 py-1 text-xs font-bold uppercase rounded-lg border <?php echo getStatusBadge($schoolStatus); ?>">
                                        <?php echo ucfirst($schoolStatus); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="space-y-3 mb-4">
                                <!-- Plan Info -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <span class="text-xs text-slate-500">Plan:</span>
                                        <div class="mt-1">
                                            <span class="px-2 py-1 text-xs font-bold rounded <?php echo $tierClass; ?>">
                                                <?php echo $planName; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-xs text-slate-500">Price:</span>
                                        <div class="text-sm font-bold mt-1">
                                            ₦<?php echo number_format(convertToNaira($school['price_monthly'] ?? 0, $exchangeRate)); ?>/mo
                                            <div class="text-[10px] text-slate-500">$<?php echo number_format($school['price_monthly'] ?? 0, 2); ?> USD</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Renewal Info -->
                                <div>
                                    <span class="text-xs text-slate-500">Status:</span>
                                    <div class="flex items-center justify-between mt-1">
                                        <span class="px-2 py-1 text-xs font-bold <?php echo $renewalStatus['class']; ?>">
                                            <?php echo $renewalStatus['text']; ?>
                                        </span>
                                        <?php if ($school['current_period_end']): ?>
                                        <span class="text-xs text-slate-500">
                                            <?php echo date('M j', strtotime($school['current_period_end'])); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Revenue Info -->
                                <div>
                                    <span class="text-xs text-slate-500">Revenue:</span>
                                    <div class="flex items-center justify-between mt-1">
                                        <span class="text-sm font-bold">₦<?php echo number_format(convertToNaira($school['total_revenue'] ?? 0, $exchangeRate)); ?></span>
                                        <span class="text-xs text-slate-500"><?php echo $school['paid_invoices']; ?> payments</span>
                                    </div>
                                    <div class="progress-bar mt-1">
                                        <?php 
                                        $usagePercent = $engagementScore;
                                        $colorClass = $usagePercent > 80 ? 'progress-high' : ($usagePercent > 50 ? 'progress-medium' : 'progress-low');
                                        ?>
                                        <div class="progress-fill <?php echo $colorClass; ?>" style="width: <?php echo $usagePercent; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex gap-2">
                                <a href="view.php?id=<?php echo $school['id']; ?>" class="flex-1 py-2.5 bg-blue-50 text-blue-600 font-bold rounded-xl text-xs border border-blue-100 touch-target flex items-center justify-center">
                                    <i class="fas fa-eye mr-1"></i> View
                                </a>
                                <a href="manage.php?id=<?php echo $school['id']; ?>" class="px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl touch-target flex items-center justify-center">
                                    <i class="fas fa-cog"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $school['id']; ?>" class="px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl touch-target flex items-center justify-center">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 bg-slate-50/50 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <p class="text-xs font-bold text-slate-500">
                            Showing <?php echo count($schools); ?> of <?php echo $totalSchools; ?> Schools
                            <?php if ($status): ?>
                                (Filtered by: <?php echo ucfirst($status); ?>)
                            <?php endif; ?>
                        </p>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . $status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $plan_id ? '&plan_id=' . $plan_id : ''; ?>" class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white hover:bg-slate-50 touch-target">
                                    Previous
                                </a>
                            <?php else: ?>
                                <button class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white touch-target disabled:opacity-50" disabled>Previous</button>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . $status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $plan_id ? '&plan_id=' . $plan_id : ''; ?>" class="px-4 py-2 text-xs font-bold text-blue-600 border border-blue-100 rounded-xl bg-white hover:bg-blue-50 touch-target">
                                    Load More
                                </a>
                            <?php else: ?>
                                <button class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white touch-target disabled:opacity-50" disabled>No More</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="p-12 text-center">
                        <div class="mx-auto w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-school text-slate-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-slate-700 mb-2">No Schools Found</h3>
                        <p class="text-slate-500 mb-6"><?php echo $status ? "No {$status} schools found." : "Get started by onboarding your first school."; ?></p>
                        <a href="add.php" class="inline-flex items-center gap-2 bg-slate-900 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-blue-600 transition-all">
                            <i class="fas fa-plus"></i> Onboard First School
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('active');
        }

        function mobileSidebarToggle() {
            toggleSidebar();
        }

        // Toggle mobile search
        document.getElementById('mobileSearchBtn')?.addEventListener('click', function() {
            const searchDiv = document.getElementById('mobileSearch');
            if (searchDiv) {
                searchDiv.classList.toggle('hidden');
                searchDiv.classList.toggle('flex');
                if (!searchDiv.classList.contains('hidden')) {
                    searchDiv.querySelector('input').focus();
                }
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth < 1024 && 
                sidebar && overlay &&
                !sidebar.contains(e.target) && 
                !e.target.closest('[onclick*="mobileSidebarToggle"]')) {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.remove('active');
            }
        });

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar) sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.remove('active');
                
                const searchDiv = document.getElementById('mobileSearch');
                if (searchDiv && !searchDiv.classList.contains('hidden')) {
                    searchDiv.classList.add('hidden');
                    searchDiv.classList.remove('flex');
                }
            }
        });

        // Auto-submit search form on input change
        document.querySelector('form[method="GET"] input[name="search"]')?.addEventListener('input', function(e) {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>