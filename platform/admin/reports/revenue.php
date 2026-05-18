<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

$superAdmin = $_SESSION['super_admin'];
$db = Database::getPlatformConnection();

$exchange_rate = 1500;
$monthlyRevenue = 0;
$annualRevenue = 0;
$totalSchools = 0;
$revenueByPlan = [];

try {
    $stmt = $db->query("SELECT SUM(p.price_monthly) as monthly FROM schools s JOIN plans p ON s.plan_id = p.id WHERE s.status IN ('active', 'trial')");
    $monthlyRevenue = $stmt->fetch()['monthly'] ?? 0;
    $annualRevenue = $monthlyRevenue * 12;

    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status IN ('active', 'trial')");
    $totalSchools = $stmt->fetch()['count'];

    $stmt = $db->query("SELECT p.name, COUNT(s.id) as schools, SUM(p.price_monthly) as revenue FROM plans p LEFT JOIN schools s ON s.plan_id = p.id AND s.status IN ('active', 'trial') GROUP BY p.id, p.name ORDER BY revenue DESC");
    $revenueByPlan = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Revenue report error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Revenue Intelligence | <?php echo APP_NAME; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        :root { --brand-primary: #2563eb; --brand-surface: #ffffff; --brand-bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-bg); color: #1e293b; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .stat-card { border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Revenue Intelligence</h1>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-7xl mx-auto space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Monthly Revenue (USD)</p>
                            <p class="text-3xl font-black text-slate-800">$<?php echo number_format($monthlyRevenue, 2); ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Annual Revenue (USD)</p>
                            <p class="text-3xl font-black text-slate-800">$<?php echo number_format($annualRevenue, 2); ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Monthly Revenue (NGN)</p>
                            <p class="text-3xl font-black text-slate-800">₦<?php echo number_format($monthlyRevenue * $exchange_rate, 2); ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Active Schools</p>
                            <p class="text-3xl font-black text-slate-800"><?php echo $totalSchools; ?></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-6">
                        <h3 class="text-sm font-black text-slate-700 mb-4">Revenue by Plan</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-100">
                                        <th class="pb-3 pr-4">Plan</th>
                                        <th class="pb-3 pr-4">Schools</th>
                                        <th class="pb-3 pr-4">Monthly Revenue</th>
                                        <th class="pb-3">Annual Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($revenueByPlan as $plan): ?>
                                        <tr class="border-b border-slate-50 hover:bg-slate-50 transition">
                                            <td class="py-3 pr-4 font-medium"><?php echo htmlspecialchars($plan['name']); ?></td>
                                            <td class="py-3 pr-4"><?php echo $plan['schools']; ?></td>
                                            <td class="py-3 pr-4">$<?php echo number_format($plan['revenue'], 2); ?></td>
                                            <td class="py-3">$<?php echo number_format($plan['revenue'] * 12, 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
        if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar) sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
