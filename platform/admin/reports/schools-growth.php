<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

$superAdmin = $_SESSION['super_admin'];
$db = Database::getPlatformConnection();

$totalSchools = 0;
$activeSchools = 0;
$trialSchools = 0;
$suspendedSchools = 0;
$pendingSchools = 0;
$recentSchools = [];

try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools");
    $totalSchools = $stmt->fetch()['count'];
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status = 'active'");
    $activeSchools = $stmt->fetch()['count'];
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status = 'trial'");
    $trialSchools = $stmt->fetch()['count'];
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status = 'suspended'");
    $suspendedSchools = $stmt->fetch()['count'];
    $stmt = $db->query("SELECT COUNT(*) as count FROM schools WHERE status = 'pending'");
    $pendingSchools = $stmt->fetch()['count'];

    $stmt = $db->query("SELECT s.*, p.name as plan_name FROM schools s LEFT JOIN plans p ON s.plan_id = p.id ORDER BY s.created_at DESC LIMIT 20");
    $recentSchools = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Growth report error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Growth Analytics | <?php echo APP_NAME; ?> Executive</title>
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Growth Analytics</h1>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-7xl mx-auto space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6">
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Total Schools</p>
                            <p class="text-3xl font-black text-slate-800"><?php echo $totalSchools; ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Active</p>
                            <p class="text-3xl font-black text-emerald-600"><?php echo $activeSchools; ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Trial</p>
                            <p class="text-3xl font-black text-blue-600"><?php echo $trialSchools; ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Suspended</p>
                            <p class="text-3xl font-black text-red-500"><?php echo $suspendedSchools; ?></p>
                        </div>
                        <div class="stat-card bg-white rounded-2xl p-6">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Pending</p>
                            <p class="text-3xl font-black text-amber-500"><?php echo $pendingSchools; ?></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 p-6">
                        <h3 class="text-sm font-black text-slate-700 mb-4">Recently Registered Schools</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-100">
                                        <th class="pb-3 pr-4">School</th>
                                        <th class="pb-3 pr-4">Plan</th>
                                        <th class="pb-3 pr-4">Status</th>
                                        <th class="pb-3">Registered</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentSchools)): ?>
                                        <tr><td colspan="4" class="py-8 text-center text-slate-400">No schools registered yet</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentSchools as $school): ?>
                                            <tr class="border-b border-slate-50 hover:bg-slate-50 transition">
                                                <td class="py-3 pr-4 font-medium"><?php echo htmlspecialchars($school['name']); ?></td>
                                                <td class="py-3 pr-4"><?php echo htmlspecialchars($school['plan_name'] ?? 'N/A'); ?></td>
                                                <td class="py-3 pr-4">
                                                    <span class="px-2 py-1 text-xs font-bold rounded <?php echo $school['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : ($school['status'] === 'trial' ? 'bg-blue-100 text-blue-700' : ($school['status'] === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700')); ?>">
                                                        <?php echo ucfirst($school['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 text-slate-400"><?php echo date('d M, Y', strtotime($school['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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
