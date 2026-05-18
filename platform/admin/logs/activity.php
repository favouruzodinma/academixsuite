<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

$superAdmin = $_SESSION['super_admin'];
$db = Database::getPlatformConnection();

$activities = [];
try {
    $stmt = $db->query("SELECT a.*, s.name as school_name FROM activity_logs a LEFT JOIN schools s ON a.school_id = s.id ORDER BY a.created_at DESC LIMIT 100");
    $activities = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Activity logs error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Activity Monitor | <?php echo APP_NAME; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        :root { --brand-primary: #2563eb; --brand-surface: #ffffff; --brand-bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-bg); color: #1e293b; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .touch-target { min-height: 44px; display: flex; align-items: center; }
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Activity Monitor</h1>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <div class="bg-white rounded-2xl border border-slate-200 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-lg font-black text-slate-800">System Activity Log</h2>
                                <p class="text-sm text-slate-400">Real-time monitoring of platform activities</p>
                            </div>
                        </div>
                        <?php if (empty($activities)): ?>
                            <div class="text-center py-16">
                                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-history text-slate-400 text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-bold text-slate-600 mb-2">No Activity Records</h3>
                                <p class="text-sm text-slate-400">Platform activities will be logged here</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-100">
                                            <th class="pb-3 pr-4">Timestamp</th>
                                            <th class="pb-3 pr-4">School</th>
                                            <th class="pb-3 pr-4">User</th>
                                            <th class="pb-3 pr-4">Action</th>
                                            <th class="pb-3">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activities as $log): ?>
                                            <tr class="border-b border-slate-50 hover:bg-slate-50 transition">
                                                <td class="py-3 pr-4 text-xs text-slate-400 whitespace-nowrap"><?php echo date('d M Y H:i', strtotime($log['created_at'])); ?></td>
                                                <td class="py-3 pr-4 font-medium"><?php echo htmlspecialchars($log['school_name'] ?? 'System'); ?></td>
                                                <td class="py-3 pr-4 text-slate-600"><?php echo htmlspecialchars($log['user'] ?? 'N/A'); ?></td>
                                                <td class="py-3 pr-4">
                                                    <span class="px-2 py-1 text-xs font-bold rounded bg-slate-100 text-slate-700"><?php echo htmlspecialchars($log['action'] ?? 'N/A'); ?></span>
                                                </td>
                                                <td class="py-3 text-slate-500"><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
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
