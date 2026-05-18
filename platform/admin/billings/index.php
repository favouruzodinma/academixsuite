<?php
// platform/admin/billing/index.php
require_once __DIR__ . '/../../../includes/autoload.php';

// Require super admin login
$auth = new Auth();
$auth->requireLogin('super_admin');

// Get super admin data
$superAdmin = $_SESSION['super_admin'];

// Fetch data from database
$db = Database::getPlatformConnection();

// Get current dollar to naira exchange rate
$exchangeRate = 1500; // You can make this dynamic

// Get filter parameters
$status = $_GET['status'] ?? null;
$school_id = $_GET['school_id'] ?? null;
$payment_status = $_GET['payment_status'] ?? null;
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get all schools for filter dropdown
$schools = $db->query("SELECT id, name FROM schools ORDER BY name")->fetchAll();

// Build WHERE clause
$whereConditions = ["1=1"];
$params = [];

if ($status && in_array($status, ['draft', 'sent', 'paid', 'overdue', 'canceled'])) {
    $whereConditions[] = "i.status = ?";
    $params[] = $status;
}

if ($payment_status && in_array($payment_status, ['pending', 'initiated', 'processing', 'success', 'failed', 'refunded'])) {
    $whereConditions[] = "i.payment_status = ?";
    $params[] = $payment_status;
}

if ($school_id && is_numeric($school_id)) {
    $whereConditions[] = "i.school_id = ?";
    $params[] = $school_id;
}

if ($date_from) {
    $whereConditions[] = "DATE(i.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $whereConditions[] = "DATE(i.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $whereConditions[] = "(i.invoice_number LIKE ? OR i.payment_reference LIKE ? OR i.transaction_id LIKE ? OR s.name LIKE ? OR s.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Get total count
$countSql = "SELECT COUNT(*) as total FROM invoices i 
             LEFT JOIN schools s ON i.school_id = s.id 
             $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalInvoices = $countStmt->fetch()['total'];
$totalPages = ceil($totalInvoices / $perPage);

// Get invoices with school and subscription data
$sql = "
    SELECT 
        i.*,
        s.name as school_name,
        s.email as school_email,
        s.phone as school_phone,
        sub.description as subscription_desc,
        sub.status as subscription_status,
        p.name as plan_name,
        p.price_monthly,
        DATEDIFF(i.due_date, CURDATE()) as days_until_due,
        CASE 
            WHEN i.payment_status = 'success' THEN 'Paid'
            WHEN i.payment_status = 'pending' AND i.due_date < CURDATE() THEN 'Overdue'
            WHEN i.payment_status = 'pending' AND i.due_date >= CURDATE() THEN 'Pending'
            WHEN i.payment_status = 'failed' THEN 'Failed'
            WHEN i.payment_status = 'refunded' THEN 'Refunded'
            ELSE 'Pending'
        END as payment_status_text
    FROM invoices i 
    LEFT JOIN schools s ON i.school_id = s.id
    LEFT JOIN subscriptions sub ON i.subscription_id = sub.id
    LEFT JOIN plans p ON sub.plan_id = p.id
    $whereClause 
    ORDER BY i.created_at DESC 
    LIMIT ? OFFSET ?
";

$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Get billing statistics
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN i.payment_status = 'success' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN i.payment_status = 'success' THEN i.total_amount ELSE 0 END) as total_revenue,
        SUM(CASE WHEN i.payment_status = 'pending' AND i.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN i.payment_status = 'pending' AND i.due_date < CURDATE() THEN i.total_amount ELSE 0 END) as overdue_amount,
        SUM(CASE WHEN i.payment_status = 'pending' AND i.due_date >= CURDATE() THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN i.payment_status = 'pending' AND i.due_date >= CURDATE() THEN i.total_amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN i.payment_status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN i.payment_status = 'refunded' THEN 1 ELSE 0 END) as refunded,
        COUNT(DISTINCT i.school_id) as active_schools,
        AVG(i.total_amount) as avg_invoice_amount
    FROM invoices i
    WHERE i.status != 'draft'
";
$statsStmt = $db->query($statsSql);
$stats = $statsStmt->fetch();

// Get monthly revenue data
$revenueSql = "
    SELECT 
        DATE_FORMAT(COALESCE(i.paid_at, i.created_at), '%Y-%m') as month,
        COUNT(*) as invoice_count,
        SUM(i.total_amount) as total_revenue,
        SUM(CASE WHEN i.is_trial = 1 THEN i.total_amount ELSE 0 END) as trial_revenue,
        SUM(CASE WHEN i.is_trial = 0 THEN i.total_amount ELSE 0 END) as paid_revenue
    FROM invoices i
    WHERE i.payment_status = 'success' OR (i.is_trial = 1 AND i.payment_status = 'pending')
    GROUP BY DATE_FORMAT(COALESCE(i.paid_at, i.created_at), '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
";
$revenueStmt = $db->query($revenueSql);
$monthlyRevenue = $revenueStmt->fetchAll();

// Calculate revenue in Naira
$totalRevenueNaira = ($stats['total_revenue'] ?? 0) * $exchangeRate;
$overdueRevenueNaira = ($stats['overdue_amount'] ?? 0) * $exchangeRate;
$pendingRevenueNaira = ($stats['pending_amount'] ?? 0) * $exchangeRate;

// NEW: Function to convert USD to Naira
function convertToNaira($amount, $exchangeRate = 1500) {
    if (!$amount) return 0;
    return $amount * $exchangeRate;
}

// Function to get payment status badge class
function getPaymentStatusBadge($status) {
    $badges = [
        'success' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'pending' => 'bg-amber-50 text-amber-600 border-amber-100',
        'initiated' => 'bg-blue-50 text-blue-600 border-blue-100',
        'processing' => 'bg-blue-50 text-blue-600 border-blue-100',
        'failed' => 'bg-red-50 text-red-600 border-red-100',
        'refunded' => 'bg-slate-50 text-slate-600 border-slate-100',
        'Paid' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'Overdue' => 'bg-red-50 text-red-600 border-red-100',
        'Pending' => 'bg-amber-50 text-amber-600 border-amber-100'
    ];
    return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
}

// Function to get invoice status badge
function getInvoiceStatusBadge($status) {
    $badges = [
        'paid' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'sent' => 'bg-blue-50 text-blue-600 border-blue-100',
        'draft' => 'bg-slate-50 text-slate-600 border-slate-100',
        'overdue' => 'bg-red-50 text-red-600 border-red-100',
        'canceled' => 'bg-slate-50 text-slate-600 border-slate-100'
    ];
    return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
}

// Function to get payment method icon
function getPaymentMethodIcon($method) {
    $icons = [
        'card' => 'fa-credit-card',
        'bank_transfer' => 'fa-university',
        'paystack' => 'fa-credit-card',
        'stripe' => 'fa-cc-stripe',
        'flutterwave' => 'fa-money-bill-wave'
    ];
    return $icons[$method] ?? 'fa-money-bill';
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

// Function to show both USD and Naira
function formatDualCurrency($usdAmount, $exchangeRate = 1500, $usdSymbol = '$', $nairaSymbol = '₦') {
    if ($usdAmount <= 0) {
        return '<span class="text-slate-400">Free</span>';
    }
    
    $nairaAmount = convertToNaira($usdAmount, $exchangeRate);
    return '<div>
                <span class="font-bold text-slate-800">' . $nairaSymbol . number_format($nairaAmount, 0) . '</span>
                <div class="text-xs text-slate-500 mt-0.5">' . $usdSymbol . number_format($usdAmount, 2) . ' USD</div>
            </div>';
}

// Function to get days color class
function getDaysColor($days) {
    if ($days < 0) {
        return 'text-red-600';
    } elseif ($days <= 3) {
        return 'text-amber-600';
    } elseif ($days <= 7) {
        return 'text-blue-600';
    } else {
        return 'text-emerald-600';
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Billing & Invoices | <?php echo APP_NAME; ?> Executive</title>
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

        /* Scrollbar styling */
        .scrollbar-thin::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Billing & Invoices</h1>
                        <span class="px-2 py-0.5 bg-blue-600 text-[10px] text-white font-black rounded uppercase">Live</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Filter Dropdown -->
                    <div class="relative hidden lg:block">
                        <button onclick="toggleFilterDropdown()" class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-black transition-all">
                            <i class="fas fa-filter text-slate-500"></i>
                            <span>Filter</span>
                            <i class="fas fa-chevron-down text-xs text-slate-400"></i>
                        </button>
                        <div id="filterDropdown" class="hidden absolute right-0 mt-2 w-64 bg-white border border-slate-200 rounded-xl shadow-lg z-50 p-4">
                            <form method="GET" class="space-y-3">
                                <!-- School Filter -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">School</label>
                                    <select name="school_id" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-100 outline-none">
                                        <option value="">All Schools</option>
                                        <?php foreach ($schools as $school): ?>
                                            <option value="<?php echo $school['id']; ?>" <?php echo $school_id == $school['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($school['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Status Filter -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Invoice Status</label>
                                    <select name="status" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-100 outline-none">
                                        <option value="">All Status</option>
                                        <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="sent" <?php echo $status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                        <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="canceled" <?php echo $status === 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                                    </select>
                                </div>
                                
                                <!-- Payment Status Filter -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Payment Status</label>
                                    <select name="payment_status" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-100 outline-none">
                                        <option value="">All Payment Status</option>
                                        <option value="success" <?php echo $payment_status === 'success' ? 'selected' : ''; ?>>Success</option>
                                        <option value="pending" <?php echo $payment_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="failed" <?php echo $payment_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                        <option value="refunded" <?php echo $payment_status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                    </select>
                                </div>
                                
                                <!-- Date Range -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">From</label>
                                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                               class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-100 outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">To</label>
                                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                               class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-100 outline-none">
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="flex gap-2 pt-2">
                                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-black transition-all">
                                        Apply
                                    </button>
                                    <a href="?" class="px-4 py-2 border border-slate-200 rounded-lg text-xs font-black text-slate-600 hover:bg-slate-50 transition-all">
                                        Clear
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Export Button -->
                    <button onclick="exportInvoices()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 shadow-lg touch-target">
                        <i class="fas fa-file-export"></i>
                        <span class="hidden sm:inline">Export</span>
                    </button>
                    
                    <!-- Search -->
                    <form method="GET" class="hidden lg:flex items-center bg-slate-100 border border-slate-200 px-3.5 py-1.5 rounded-xl group transition-all focus-within:ring-2 focus-within:ring-blue-100">
                        <i class="fas fa-search text-slate-400 text-xs"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search invoices..." class="bg-transparent text-sm outline-none ml-2.5 w-48 lg:w-64 placeholder:text-slate-400 font-medium">
                        <?php if ($status): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                        <?php endif; ?>
                        <?php if ($payment_status): ?>
                            <input type="hidden" name="payment_status" value="<?php echo htmlspecialchars($payment_status); ?>">
                        <?php endif; ?>
                        <?php if ($school_id): ?>
                            <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">
                        <?php endif; ?>
                    </form>
                    
                    <!-- Mobile Search Button -->
                    <button onclick="toggleMobileSearch()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </header>

            <!-- Mobile Search Bar -->
            <div id="mobileSearch" class="hidden lg:hidden p-4 border-b border-slate-200 bg-slate-50">
                <form method="GET" class="flex items-center bg-white border border-slate-200 px-3.5 py-2.5 rounded-xl group transition-all focus-within:ring-2 focus-within:ring-blue-100">
                    <i class="fas fa-search text-slate-400 text-xs"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search invoices..." class="flex-1 bg-transparent text-sm outline-none ml-2.5 placeholder:text-slate-400 font-medium">
                    <button type="submit" class="text-blue-600 font-bold text-sm">Go</button>
                </form>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8 space-y-6">
                
                <!-- Billing Dashboard -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Total Revenue Card -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Total Revenue</p>
                            <i class="fas fa-chart-line text-blue-200"></i>
                        </div>
                        <p class="text-xl font-black text-blue-600"><?php echo formatMoney($totalRevenueNaira); ?></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 mr-1"></span>
                            <?php echo $stats['paid'] ?? 0; ?> paid invoices
                        </div>
                    </div>
                    
                    <!-- Outstanding Revenue -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Outstanding</p>
                            <i class="fas fa-clock text-amber-200"></i>
                        </div>
                        <p class="text-xl font-black text-amber-600"><?php echo formatMoney($pendingRevenueNaira + $overdueRevenueNaira); ?></p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-red-500 mr-1"></span>
                            <?php echo $stats['overdue'] ?? 0; ?> overdue
                        </div>
                    </div>
                    
                    <!-- Average Invoice -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-400 uppercase">Avg Invoice</p>
                            <i class="fas fa-receipt text-purple-200"></i>
                        </div>
                        <p class="text-xl font-black text-purple-600">
                            ₦<?php echo number_format(convertToNaira($stats['avg_invoice_amount'] ?? 0, $exchangeRate), 0); ?>
                        </p>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-full bg-purple-500 mr-1"></span>
                            <?php echo $stats['active_schools'] ?? 0; ?> active schools
                        </div>
                    </div>
                    
                    <!-- Collection Rate -->
                    <div class="bg-slate-900 p-4 rounded-2xl shadow-lg">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-black text-slate-300 uppercase">Collection Rate</p>
                            <i class="fas fa-percentage text-emerald-400"></i>
                        </div>
                        <?php
                        $totalInvoiced = ($stats['total_revenue'] ?? 0) + ($stats['pending_amount'] ?? 0) + ($stats['overdue_amount'] ?? 0);
                        $collectionRate = $totalInvoiced > 0 ? (($stats['total_revenue'] ?? 0) / $totalInvoiced) * 100 : 0;
                        ?>
                        <p class="text-xl font-black text-white">
                            <?php echo round($collectionRate); ?>%
                        </p>
                        <div class="mt-1 text-xs text-slate-300">
                            <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 mr-1"></span>
                            <?php echo number_format($collectionRate, 1); ?>% success rate
                        </div>
                    </div>
                </div>

                <!-- Recent Revenue Chart -->
                <div class="bg-white rounded-2xl registry-card p-4">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest">Monthly Revenue Trend</h2>
                        <span class="text-xs text-slate-500 font-bold">Last 6 months</span>
                    </div>
                    
                    <div class="overflow-x-auto scrollbar-thin">
                        <div class="min-w-full" style="min-width: 600px;">
                            <div class="grid grid-cols-6 gap-2">
                                <?php foreach (array_reverse($monthlyRevenue) as $month): 
                                    $monthName = date('M Y', strtotime($month['month'] . '-01'));
                                    $totalRevenue = convertToNaira($month['total_revenue'] ?? 0, $exchangeRate);
                                    $maxRevenue = max(array_column($monthlyRevenue, 'total_revenue')) * $exchangeRate;
                                    $heightPercent = $maxRevenue > 0 ? ($totalRevenue / $maxRevenue * 100) : 0;
                                ?>
                                <div class="flex flex-col items-center">
                                    <div class="text-xs text-slate-500 mb-2"><?php echo $monthName; ?></div>
                                    <div class="w-8 h-32 bg-slate-100 rounded-lg relative overflow-hidden">
                                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-blue-500 to-blue-300 rounded-lg" 
                                             style="height: <?php echo $heightPercent; ?>%">
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs font-bold text-slate-700">
                                        ₦<?php echo number_format($totalRevenue, 0); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoices Table -->
                <div class="bg-white rounded-2xl registry-card overflow-hidden">
                    <div class="p-4 border-b border-slate-100 bg-slate-50/30 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center rounded-xl bg-white border border-slate-200 p-1">
                                <a href="?" class="px-3 py-2 text-xs font-black uppercase <?php echo !$status ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">All</a>
                                <a href="?status=paid" class="px-3 py-2 text-xs font-black uppercase <?php echo $status === 'paid' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Paid</a>
                                <a href="?status=pending" class="px-3 py-2 text-xs font-black uppercase <?php echo $status === 'pending' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Pending</a>
                                <a href="?status=overdue" class="px-3 py-2 text-xs font-black uppercase <?php echo $status === 'overdue' ? 'bg-slate-900 text-white rounded-lg' : 'text-slate-400'; ?>">Overdue</a>
                            </div>
                        </div>
                        <p class="text-xs font-black text-slate-500 uppercase opacity-60">
                            Exchange Rate: $1 = ₦<?php echo number_format($exchangeRate); ?>
                        </p>
                    </div>

                    <!-- Desktop Table View -->
                    <?php if (!empty($invoices)): ?>
                    <div class="desktop-view overflow-x-auto">
                        <table class="w-full text-left min-w-[1200px]">
                            <thead>
                                <tr class="text-xs font-black text-slate-400 uppercase bg-slate-50/50 border-b border-slate-100">
                                    <th class="px-6 py-4">Invoice</th>
                                    <th class="px-6 py-4">School</th>
                                    <th class="px-6 py-4">Amount</th>
                                    <th class="px-6 py-4">Due Date</th>
                                    <th class="px-6 py-4">Payment Status</th>
                                    <th class="px-6 py-4">Invoice Status</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($invoices as $invoice): 
                                    $initials = getInitials($invoice['school_name']);
                                    $avatarColor = getAvatarColor($initials);
                                    $daysUntilDue = $invoice['days_until_due'] ?? 0;
                                    $isOverdue = $daysUntilDue < 0;
                                    $isTrial = $invoice['is_trial'] ?? 0;
                                ?>
                                <tr class="hover:bg-blue-50/30 transition-all <?php echo $isOverdue ? 'bg-red-50/30' : ''; ?>">
                                    <!-- Invoice Column -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-1">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-file-invoice text-slate-400 text-sm"></i>
                                                <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                                            </div>
                                            <div class="text-xs text-slate-400 uppercase">Ref: <?php echo htmlspecialchars($invoice['payment_reference'] ?: 'N/A'); ?></div>
                                            <div class="text-xs text-slate-500">
                                                <?php echo date('M j, Y', strtotime($invoice['created_at'])); ?>
                                            </div>
                                            <?php if ($invoice['description']): ?>
                                            <div class="text-xs text-slate-600 truncate max-w-xs">
                                                <?php echo htmlspecialchars($invoice['description']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- School Column -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm border <?php echo $avatarColor; ?>">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div>
                                                <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($invoice['school_name']); ?></div>
                                                <div class="text-xs text-slate-400 uppercase mt-0.5">ID: <?php echo $invoice['school_id']; ?></div>
                                                <div class="text-xs text-slate-500 mt-0.5">
                                                    <?php echo htmlspecialchars($invoice['school_email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Amount Column -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-1">
                                            <?php echo formatDualCurrency($invoice['total_amount'] ?? 0, $exchangeRate); ?>
                                            <?php if ($invoice['tax'] > 0): ?>
                                            <div class="text-xs text-slate-500">
                                                Tax: $<?php echo number_format($invoice['tax'], 2); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($isTrial): ?>
                                            <span class="inline-block px-2 py-0.5 bg-blue-50 text-blue-600 text-xs font-bold rounded border border-blue-100">
                                                Trial
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Due Date Column -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-2">
                                            <div class="text-sm font-medium text-slate-800">
                                                <?php echo date('M j, Y', strtotime($invoice['due_date'])); ?>
                                            </div>
                                            <div class="text-xs <?php echo getDaysColor($daysUntilDue); ?> font-bold">
                                                <?php 
                                                if ($isOverdue) {
                                                    echo abs($daysUntilDue) . ' days overdue';
                                                } elseif ($daysUntilDue == 0) {
                                                    echo 'Due today';
                                                } elseif ($daysUntilDue == 1) {
                                                    echo 'Due tomorrow';
                                                } else {
                                                    echo $daysUntilDue . ' days left';
                                                }
                                                ?>
                                            </div>
                                            <?php if ($invoice['paid_at']): ?>
                                            <div class="text-xs text-emerald-600">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                Paid <?php echo date('M j', strtotime($invoice['paid_at'])); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Payment Status Column -->
                                    <td class="px-6 py-4">
                                        <div class="space-y-2">
                                            <span class="px-2.5 py-1 rounded-lg text-xs font-bold uppercase <?php echo getPaymentStatusBadge($invoice['payment_status_text']); ?>">
                                                <?php echo $invoice['payment_status_text']; ?>
                                            </span>
                                            <?php if ($invoice['payment_method']): ?>
                                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                                <i class="fas <?php echo getPaymentMethodIcon($invoice['payment_method']); ?>"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_method'])); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($invoice['transaction_id']): ?>
                                            <div class="text-xs text-slate-400 truncate max-w-xs">
                                                TXN: <?php echo htmlspecialchars($invoice['transaction_id']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Invoice Status Column -->
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-bold uppercase <?php echo getInvoiceStatusBadge($invoice['status']); ?>">
                                            <?php echo ucfirst($invoice['status']); ?>
                                        </span>
                                        <?php if ($invoice['plan_name']): ?>
                                        <div class="text-xs text-slate-500 mt-1">
                                            <?php echo htmlspecialchars($invoice['plan_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Actions Column -->
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <a href="view-invoice.php?id=<?php echo $invoice['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-600 transition-all touch-target flex items-center justify-center" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($invoice['payment_status'] === 'pending'): ?>
                                            <button onclick="sendReminder(<?php echo $invoice['id']; ?>)" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-amber-600 transition-all touch-target flex items-center justify-center" title="Send Reminder">
                                                <i class="fas fa-bell"></i>
                                            </button>
                                            <?php endif; ?>
                                            <a href="edit-invoice.php?id=<?php echo $invoice['id']; ?>" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-emerald-600 transition-all touch-target flex items-center justify-center" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($invoice['payment_status'] !== 'success' && $invoice['status'] !== 'canceled'): ?>
                                            <button onclick="cancelInvoice(<?php echo $invoice['id']; ?>)" class="w-9 h-9 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-red-600 transition-all touch-target flex items-center justify-center" title="Cancel">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="mobile-view space-y-3 p-4">
                        <?php foreach ($invoices as $invoice): 
                            $initials = getInitials($invoice['school_name']);
                            $avatarColor = getAvatarColor($initials);
                            $daysUntilDue = $invoice['days_until_due'] ?? 0;
                            $isOverdue = $daysUntilDue < 0;
                            $isTrial = $invoice['is_trial'] ?? 0;
                        ?>
                        <div class="mobile-card <?php echo $isOverdue ? 'border-red-200 bg-red-50/30' : ''; ?>">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black border <?php echo $avatarColor; ?>">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($invoice['invoice_number']); ?></h3>
                                        <p class="text-xs text-slate-400"><?php echo htmlspecialchars($invoice['school_name']); ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="px-2 py-1 text-xs font-bold uppercase rounded-lg border <?php echo getPaymentStatusBadge($invoice['payment_status_text']); ?>">
                                        <?php echo $invoice['payment_status_text']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="space-y-3 mb-4">
                                <!-- Amount & Due Date -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <span class="text-xs text-slate-500">Amount:</span>
                                        <div class="text-sm font-bold mt-1">
                                            ₦<?php echo number_format(convertToNaira($invoice['total_amount'] ?? 0, $exchangeRate)); ?>
                                            <div class="text-[10px] text-slate-500">$<?php echo number_format($invoice['total_amount'] ?? 0, 2); ?></div>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-xs text-slate-500">Due Date:</span>
                                        <div class="mt-1">
                                            <div class="text-sm font-medium"><?php echo date('M j, Y', strtotime($invoice['due_date'])); ?></div>
                                            <div class="text-xs <?php echo getDaysColor($daysUntilDue); ?> font-bold">
                                                <?php 
                                                if ($isOverdue) {
                                                    echo abs($daysUntilDue) . ' days overdue';
                                                } elseif ($daysUntilDue == 0) {
                                                    echo 'Due today';
                                                } else {
                                                    echo $daysUntilDue . ' days left';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Description & Status -->
                                <div>
                                    <?php if ($invoice['description']): ?>
                                    <div class="text-xs text-slate-600 mb-2">
                                        <?php echo htmlspecialchars($invoice['description']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex items-center justify-between">
                                        <span class="px-2 py-1 text-xs font-bold uppercase rounded border <?php echo getInvoiceStatusBadge($invoice['status']); ?>">
                                            <?php echo ucfirst($invoice['status']); ?>
                                        </span>
                                        <?php if ($isTrial): ?>
                                        <span class="px-2 py-1 bg-blue-50 text-blue-600 text-xs font-bold rounded border border-blue-100">
                                            Trial
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Payment Method -->
                                <?php if ($invoice['payment_method']): ?>
                                <div class="flex items-center gap-2 text-xs text-slate-500">
                                    <i class="fas <?php echo getPaymentMethodIcon($invoice['payment_method']); ?>"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_method'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex gap-2">
                                <a href="view-invoice.php?id=<?php echo $invoice['id']; ?>" class="flex-1 py-2.5 bg-blue-50 text-blue-600 font-bold rounded-xl text-xs border border-blue-100 touch-target flex items-center justify-center">
                                    <i class="fas fa-eye mr-1"></i> View
                                </a>
                                <?php if ($invoice['payment_status'] === 'pending'): ?>
                                <button onclick="sendReminder(<?php echo $invoice['id']; ?>)" class="px-4 py-2.5 bg-amber-50 text-amber-600 rounded-xl touch-target flex items-center justify-center">
                                    <i class="fas fa-bell"></i>
                                </button>
                                <?php endif; ?>
                                <a href="edit-invoice.php?id=<?php echo $invoice['id']; ?>" class="px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl touch-target flex items-center justify-center">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 bg-slate-50/50 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <p class="text-xs font-bold text-slate-500">
                            Showing <?php echo count($invoices); ?> of <?php echo $totalInvoices; ?> Invoices
                            <?php if ($status): ?>
                                (Filtered by: <?php echo ucfirst($status); ?>)
                            <?php endif; ?>
                        </p>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . $status : ''; ?><?php echo $payment_status ? '&payment_status=' . $payment_status : ''; ?><?php echo $school_id ? '&school_id=' . $school_id : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white hover:bg-slate-50 touch-target">
                                    Previous
                                </a>
                            <?php else: ?>
                                <button class="px-4 py-2 text-xs font-bold text-slate-400 border border-slate-100 rounded-xl bg-white touch-target disabled:opacity-50" disabled>Previous</button>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . $status : ''; ?><?php echo $payment_status ? '&payment_status=' . $payment_status : ''; ?><?php echo $school_id ? '&school_id=' . $school_id : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" class="px-4 py-2 text-xs font-bold text-blue-600 border border-blue-100 rounded-xl bg-white hover:bg-blue-50 touch-target">
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
                            <i class="fas fa-file-invoice text-slate-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-slate-700 mb-2">No Invoices Found</h3>
                        <p class="text-slate-500 mb-6"><?php echo $status ? "No {$status} invoices found." : "No invoices have been generated yet."; ?></p>
                        <?php if (!$status && !$payment_status && !$school_id && !$search): ?>
                        <button onclick="generateInvoices()" class="inline-flex items-center gap-2 bg-slate-900 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-blue-600 transition-all">
                            <i class="fas fa-plus"></i> Generate Monthly Invoices
                        </button>
                        <?php endif; ?>
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

        // Toggle filter dropdown
        function toggleFilterDropdown() {
            const dropdown = document.getElementById('filterDropdown');
            dropdown.classList.toggle('hidden');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const dropdown = document.getElementById('filterDropdown');
            const button = e.target.closest('button[onclick*="toggleFilterDropdown"]');
            
            if (dropdown && !dropdown.contains(e.target) && !button) {
                dropdown.classList.add('hidden');
            }
            
            // Close sidebar on mobile
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

        // Toggle mobile search
        function toggleMobileSearch() {
            const searchDiv = document.getElementById('mobileSearch');
            if (searchDiv) {
                searchDiv.classList.toggle('hidden');
                if (!searchDiv.classList.contains('hidden')) {
                    searchDiv.querySelector('input').focus();
                }
            }
        }

        // Auto-submit search form on input change
        document.querySelector('form[method="GET"] input[name="search"]')?.addEventListener('input', function(e) {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => {
                this.form.submit();
            }, 500);
        });

        // Export invoices function
        function exportInvoices() {
            const params = new URLSearchParams(window.location.search);
            window.location.href = 'export-invoices.php?' + params.toString();
        }

        // Generate monthly invoices
        function generateInvoices() {
            if (confirm('Generate monthly invoices for all active schools?')) {
                window.location.href = 'generate-invoices.php';
            }
        }

        // Send reminder for overdue invoice
        function sendReminder(invoiceId) {
            if (confirm('Send payment reminder for this invoice?')) {
                fetch('send-reminder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ invoice_id: invoiceId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Reminder sent successfully!');
                        location.reload();
                    } else {
                        alert('Error sending reminder: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error sending reminder');
                    console.error('Error:', error);
                });
            }
        }

        // Cancel invoice
        function cancelInvoice(invoiceId) {
            if (confirm('Are you sure you want to cancel this invoice? This action cannot be undone.')) {
                fetch('cancel-invoice.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ invoice_id: invoiceId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Invoice cancelled successfully!');
                        location.reload();
                    } else {
                        alert('Error cancelling invoice: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error cancelling invoice');
                    console.error('Error:', error);
                });
            }
        }

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
                }
                
                const dropdown = document.getElementById('filterDropdown');
                if (dropdown && !dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        });
    </script>
</body>
</html>