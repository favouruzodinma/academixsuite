<?php
// platform/admin/dashboard.php
require_once __DIR__ . '/../../../includes/autoload.php';

// Require super admin login
$auth = new Auth();
$auth->requireLogin('super_admin');

// Get super admin data
$superAdmin = $_SESSION['super_admin'];

// Fetch dashboard data from database
$db = Database::getPlatformConnection();

// Get filter parameters
$status = $_GET['status'] ?? null;
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$whereConditions = [];
$params = [];

if ($status && in_array($status, ['active', 'trial', 'pending', 'suspended', 'cancelled'])) {
    $whereConditions[] = "s.status = ?";
    $params[] = $status;
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
$countSql = "SELECT COUNT(*) as total FROM schools s $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalSchools = $countStmt->fetch()['total'];
$totalPages = ceil($totalSchools / $perPage);

// Get schools with pagination - FIXED: removed references to school database tables
$sql = "
    SELECT 
        s.*,
        p.name as plan_name,
        p.price_monthly,
        p.student_limit,
        p.teacher_limit,
        (SELECT COUNT(*) FROM school_admins sa WHERE sa.school_id = s.id) as admin_count,
        (SELECT COUNT(*) FROM invoices i WHERE i.school_id = s.id AND i.status = 'paid') as paid_invoices
    FROM schools s 
    LEFT JOIN plans p ON s.plan_id = p.id
    $whereClause 
    ORDER BY s.created_at DESC 
    LIMIT ? OFFSET ?
";

$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$schools = $stmt->fetchAll();

// Get statistics from platform database only
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status IN ('active', 'trial') THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status IN ('active', 'trial') AND trial_ends_at IS NOT NULL AND trial_ends_at > NOW() THEN 1 ELSE 0 END) as in_trial
    FROM schools
";
$statsStmt = $db->query($statsSql);
$stats = $statsStmt->fetch();

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

// Function to get tier badge class - based on plan name
function getTierBadge($planName) {
    $planName = strtolower($planName);
    if (strpos($planName, 'enterprise') !== false) {
        return 'bg-slate-900 text-white';
    } elseif (strpos($planName, 'growth') !== false) {
        return 'bg-purple-600 text-white';
    } elseif (strpos($planName, 'pro') !== false) {
        return 'border border-slate-200 text-slate-600';
    } elseif (strpos($planName, 'starter') !== false) {
        return 'bg-slate-100 text-slate-700';
    } else {
        return 'bg-slate-100 text-slate-700';
    }
}

// Function to get initials from school name
function getInitials($name) {
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

// Calculate health index (simplified - can be improved)
$healthIndex = $stats['total'] > 0 
    ? round(($stats['operational'] / $stats['total']) * 100, 1) 
    : 100;
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

        /* Responsive Visibility - Fixed */
        .desktop-view { display: table; }
        .mobile-view { display: none; }
        
        @media (max-width: 1024px) {
            .desktop-view { display: none; }
            .mobile-view { display: block; }
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

        /* Improved mobile table cards */
        .mobile-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid #f1f5f9;
        }

        /* Ensure table is responsive on desktop */
        @media (min-width: 1025px) {
            .desktop-view table {
                width: 100%;
                table-layout: auto;
            }
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-blue-100">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden" onclick="mobileSidebarToggle()"></div>

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
                    <button id="mobileSearchBtn" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-search"></i>
                    </button>
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
                        <span class="sm:hidden">Add</span>
                    </a>
                </div>
            </header>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8 space-y-6">
                
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">System Nodes</p>
                            <i class="fas fa-server text-blue-200"></i>
                        </div>
                        <p class="text-xl font-black"><?php echo $stats['total']; ?><span class="text-xs font-bold text-slate-400 ml-1">Institutions</span></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 mr-1"></span>
                            <?php echo $stats['operational']; ?> operational
                        </div>
                    </div>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Health Index</p>
                            <i class="fas fa-heartbeat text-emerald-200"></i>
                        </div>
                        <p class="text-xl font-black <?php echo $healthIndex >= 90 ? 'text-emerald-600' : ($healthIndex >= 80 ? 'text-amber-600' : 'text-red-600'); ?>"><?php echo $healthIndex; ?>%<span class="text-xs font-bold text-slate-400 ml-1">
                            <?php 
                            if ($healthIndex >= 90) echo 'Optimal';
                            elseif ($healthIndex >= 80) echo 'Good';
                            elseif ($healthIndex >= 70) echo 'Fair';
                            else echo 'Poor';
                            ?>
                        </span></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-blue-500 mr-1"></span>
                            <?php echo $stats['in_trial']; ?> in trial
                        </div>
                    </div>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Active Nodes</p>
                            <i class="fas fa-wifi text-indigo-200"></i>
                        </div>
                        <p class="text-xl font-black"><?php echo $stats['operational']; ?><span class="text-xs font-bold text-slate-400 ml-1">Operational</span></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-amber-500 mr-1"></span>
                            <?php echo $stats['active']; ?> active
                        </div>
                    </div>
                    <div class="bg-slate-900 p-4 rounded-2xl shadow-lg">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-300 uppercase">Network Load</p>
                            <i class="fas fa-bolt text-amber-400"></i>
                        </div>
                        <p class="text-xl font-black text-white uppercase">
                            <?php 
                            $load = $stats['total'] > 50 ? 'High' : ($stats['total'] > 20 ? 'Medium' : 'Low');
                            echo $load;
                            ?>
                        </p>
                        <div class="mt-1 text-xs text-slate-300">
                            <span class="inline-block w-2 h-2 rounded-full bg-amber-400 mr-1"></span>
                            <?php echo $stats['total']; ?> total nodes
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl registry-card overflow-hidden">
                    <div class="p-4 border-b border-slate-100 bg-slate-50/30 mobile-stack mobile-space-y-4">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center rounded-xl bg-white border border-slate-200 p-1 flex-1">
                                <a href="?" class="flex-1 px-3 py-2 text-xs font-black uppercase text-center <?php echo !$status ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">All</a>
                                <a href="?status=active" class="flex-1 px-3 py-2 text-xs font-black uppercase text-center <?php echo $status === 'active' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Active</a>
                                <a href="?status=trial" class="flex-1 px-3 py-2 text-xs font-black uppercase text-center <?php echo $status === 'trial' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Trial</a>
                                <a href="?status=pending" class="flex-1 px-3 py-2 text-xs font-black uppercase text-center <?php echo $status === 'pending' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Pending</a>
                            </div>
                            <button class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-400 touch-target">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                        <p class="text-xs font-black text-slate-500 uppercase opacity-60 text-center sm:text-left">Last Sync: <?php echo date('H:i T'); ?></p>
                    </div>

                    <!-- Desktop Table View (Visible on desktop) -->
                    <?php if (!empty($schools)): ?>
                    <div class="desktop-view overflow-x-auto">
                        <table class="w-full text-left min-w-[800px]">
                            <thead>
                                <tr class="text-xs font-black text-slate-400 uppercase bg-slate-50/50 border-b border-slate-100">
                                    <th class="px-6 py-4">Institution</th>
                                    <th class="px-6 py-4">Admin Contact</th>
                                    <th class="px-6 py-4 text-center">Engagement</th>
                                    <th class="px-6 py-4">Tier</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($schools as $school): 
                                    $initials = getInitials($school['name']);
                                    $avatarColor = getAvatarColor($initials);
                                    // Estimate engagement based on admin count and paid invoices
                                    $engagementScore = min(100, (($school['admin_count'] * 10) + ($school['paid_invoices'] * 5)));
                                ?>
                                <tr class="hover:bg-blue-50/30 transition-all">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm border <?php echo $avatarColor; ?>">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div>
                                                <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($school['name']); ?></div>
                                                <div class="text-xs text-slate-400 uppercase mt-0.5">NX-NOD-<?php echo strtoupper(substr($school['slug'] ?? 'SCH', 0, 6)); ?> • <?php echo strtoupper($school['city'] ?? 'N/A'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-slate-800">
                                            <?php 
                                            if (!empty($school['admin_name'])) {
                                                echo htmlspecialchars($school['admin_name']);
                                            } elseif ($school['admin_count'] > 0) {
                                                echo $school['admin_count'] . " admin(s)";
                                            } else {
                                                echo 'No admin';
                                            }
                                            ?>
                                        </div>
                                        <div class="text-xs text-slate-400 mt-0.5">
                                            <?php 
                                            if (!empty($school['admin_email'])) {
                                                echo htmlspecialchars($school['admin_email']);
                                            } elseif (!empty($school['email'])) {
                                                echo htmlspecialchars($school['email']);
                                            } else {
                                                echo 'No email';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-center">
                                            <div class="text-sm font-black"><?php echo $school['paid_invoices']; ?> <span class="text-xs font-normal text-slate-400 ml-1">Paid</span></div>
                                            <div class="w-20 h-1 bg-slate-100 rounded-full mx-auto mt-2 overflow-hidden">
                                                <?php
                                                $color = $engagementScore > 80 ? 'bg-emerald-600' : ($engagementScore > 50 ? 'bg-blue-600' : 'bg-blue-300');
                                                ?>
                                                <div class="h-full rounded-full <?php echo $color; ?>" style="width: <?php echo $engagementScore; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php 
                                        $planName = $school['plan_name'] ?? 'Starter';
                                        $tierClass = getTierBadge($planName);
                                        ?>
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-black uppercase <?php echo $tierClass; ?>">
                                            <?php echo $planName; ?>
                                        </span>
                                    </td>
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
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <a href="edit.php?id=<?php echo $school['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="manage.php?id=<?php echo $school['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center">
                                                <i class="fas fa-cog"></i>
                                            </a>
                                            <div class="relative">
                                                <button class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center" onclick="toggleDropdown('dropdown-<?php echo $school['id']; ?>')">
                                                    <i class="fas fa-ellipsis-h"></i>
                                                </button>
                                                <div id="dropdown-<?php echo $school['id']; ?>" class="absolute right-0 mt-1 w-40 bg-white border border-slate-200 rounded-xl shadow-lg z-10 hidden">
                                                    <a href="view.php?id=<?php echo $school['id']; ?>" class="block px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">View Details</a>
                                                    <?php if (!empty($school['slug'])): ?>
                                                    <a href="/school/<?php echo $school['slug']; ?>" target="_blank" class="block px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Visit School</a>
                                                    <?php endif; ?>
                                                    <hr class="border-slate-100">
                                                    <a href="backup.php?id=<?php echo $school['id']; ?>" class="block px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Backup</a>
                                                    <?php if ($schoolStatus === 'active'): ?>
                                                        <a href="suspend.php?id=<?php echo $school['id']; ?>" class="block px-4 py-2 text-sm text-amber-600 hover:bg-amber-50">Suspend</a>
                                                    <?php elseif ($schoolStatus === 'suspended'): ?>
                                                        <a href="activate.php?id=<?php echo $school['id']; ?>" class="block px-4 py-2 text-sm text-emerald-600 hover:bg-emerald-50">Activate</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View (Visible on mobile) -->
                    <div class="mobile-view space-y-3 p-4">
                        <?php foreach ($schools as $school): 
                            $initials = getInitials($school['name']);
                            $avatarColor = getAvatarColor($initials);
                            $schoolStatus = $school['status'] ?? 'pending';
                            $planName = $school['plan_name'] ?? 'Starter';
                            $tierClass = getTierBadge($planName);
                            $engagementScore = min(100, (($school['admin_count'] * 10) + ($school['paid_invoices'] * 5)));
                        ?>
                        <div class="mobile-card">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black border <?php echo $avatarColor; ?>">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($school['name']); ?></h3>
                                        <p class="text-xs text-slate-400">NX-NOD-<?php echo strtoupper(substr($school['slug'] ?? 'SCH', 0, 6)); ?> • <?php echo htmlspecialchars($school['city'] ?? 'N/A'); ?></p>
                                    </div>
                                </div>
                                <span class="px-2 py-1 text-xs font-bold uppercase rounded-lg border <?php echo getStatusBadge($schoolStatus); ?>">
                                    <?php echo ucfirst($schoolStatus); ?>
                                </span>
                            </div>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-slate-500">Admin:</span>
                                    <span class="text-sm font-medium">
                                        <?php 
                                        if (!empty($school['admin_name'])) {
                                            echo htmlspecialchars($school['admin_name']);
                                        } elseif ($school['admin_count'] > 0) {
                                            echo $school['admin_count'] . " admin(s)";
                                        } else {
                                            echo 'No admin';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-slate-500">Email:</span>
                                    <span class="text-xs font-medium text-blue-600 truncate ml-2">
                                        <?php 
                                        if (!empty($school['admin_email'])) {
                                            echo htmlspecialchars($school['admin_email']);
                                        } elseif (!empty($school['email'])) {
                                            echo htmlspecialchars($school['email']);
                                        } else {
                                            echo 'No email';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-slate-500">Tier:</span>
                                    <span class="px-2 py-0.5 text-xs font-bold rounded <?php echo $tierClass; ?>">
                                        <?php echo $planName; ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-slate-500">Engagement:</span>
                                    <span class="text-sm font-bold"><?php echo $school['paid_invoices']; ?> Paid</span>
                                </div>
                                <div class="pt-2">
                                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                                        <span>Score:</span>
                                        <span><?php echo round($engagementScore); ?>%</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full <?php echo $engagementScore > 80 ? 'bg-emerald-600' : ($engagementScore > 50 ? 'bg-blue-600' : 'bg-blue-300'); ?>" style="width: <?php echo $engagementScore; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex gap-2">
                                <a href="manage.php?id=<?php echo $school['id']; ?>" class="flex-1 py-2.5 bg-blue-50 text-blue-600 font-bold rounded-xl text-xs border border-blue-100 touch-target flex items-center justify-center">
                                    <i class="fas fa-cog mr-1"></i> Manage
                                </a>
                                <a href="edit.php?id=<?php echo $school['id']; ?>" class="px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl touch-target flex items-center justify-center">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="view.php?id=<?php echo $school['id']; ?>" class="px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl touch-target flex items-center justify-center">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="p-4 bg-slate-50/50 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <p class="text-xs font-bold text-slate-500">
                            Showing <?php echo count($schools); ?> of <?php echo $totalSchools; ?> Schools
                            <?php if ($status): ?>
                                (Filtered by: <?php echo ucfirst($status); ?>)
                            <?php endif; ?>
                        </p>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . $status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white hover:bg-slate-50 touch-target">
                                    Previous
                                </a>
                            <?php else: ?>
                                <button class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white touch-target disabled:opacity-50" disabled>Previous</button>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . $status : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="px-4 py-2 text-xs font-bold text-blue-600 border border-blue-100 rounded-xl bg-white hover:bg-blue-50 touch-target">
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

        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            dropdown.classList.toggle('hidden');
            dropdown.classList.toggle('block');
            
            // Close other dropdowns
            document.querySelectorAll('.relative > div').forEach(other => {
                if (other.id !== id && other.classList.contains('block')) {
                    other.classList.remove('block');
                    other.classList.add('hidden');
                }
            });
        }

        function mobileSidebarToggle() {
            toggleSidebar();
        }

        // Toggle mobile search
        document.getElementById('mobileSearchBtn')?.addEventListener('click', function() {
            const searchDiv = document.getElementById('mobileSearch');
            searchDiv.classList.toggle('hidden');
            searchDiv.classList.toggle('flex');
            searchDiv.classList.toggle('absolute');
            searchDiv.classList.toggle('top-16');
            searchDiv.classList.toggle('left-4');
            searchDiv.classList.toggle('right-4');
            searchDiv.classList.toggle('z-50');
            if (!searchDiv.classList.contains('hidden')) {
                searchDiv.querySelector('input').focus();
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth < 1024 && 
                !sidebar.contains(e.target) && 
                !e.target.closest('[onclick*="mobileSidebarToggle"]')) {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.remove('active');
            }
            
            // Close dropdowns when clicking outside
            if (!e.target.closest('.relative')) {
                document.querySelectorAll('.relative > div').forEach(dropdown => {
                    if (dropdown.classList.contains('block')) {
                        dropdown.classList.remove('block');
                        dropdown.classList.add('hidden');
                    }
                });
            }
        });

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.getElementById('sidebar').classList.add('-translate-x-full');
                document.getElementById('sidebarOverlay').classList.remove('active');
                const searchDiv = document.getElementById('mobileSearch');
                if (searchDiv && !searchDiv.classList.contains('hidden')) {
                    searchDiv.classList.add('hidden');
                    searchDiv.classList.remove('flex', 'absolute', 'top-16', 'left-4', 'right-4', 'z-50');
                }
                
                // Close all dropdowns
                document.querySelectorAll('.relative > div').forEach(dropdown => {
                    if (dropdown.classList.contains('block')) {
                        dropdown.classList.remove('block');
                        dropdown.classList.add('hidden');
                    }
                });
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