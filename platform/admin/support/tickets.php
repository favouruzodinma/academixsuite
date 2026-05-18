<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

$superAdmin = $_SESSION['super_admin'];
$db = Database::getPlatformConnection();

$tickets = [];
$pendingCount = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM support_tickets WHERE status IN ('open', 'pending')");
    $pendingCount = $stmt->fetch()['count'];

    $stmt = $db->query("SELECT t.*, s.name as school_name FROM support_tickets t LEFT JOIN schools s ON t.school_id = s.id ORDER BY t.created_at DESC LIMIT 50");
    $tickets = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Support tickets error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Support Hub | <?php echo APP_NAME; ?> Executive</title>
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Support Hub</h1>
                        <?php if ($pendingCount > 0): ?>
                            <span class="px-2 py-0.5 bg-red-500 text-[10px] text-white font-black rounded uppercase"><?php echo $pendingCount; ?> Pending</span>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <div class="bg-white rounded-2xl border border-slate-200 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-lg font-black text-slate-800">Support Tickets</h2>
                                <p class="text-sm text-slate-400">Manage support requests from schools</p>
                            </div>
                        </div>
                        <?php if (empty($tickets)): ?>
                            <div class="text-center py-16">
                                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-ticket-alt text-slate-400 text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-bold text-slate-600 mb-2">No Tickets Yet</h3>
                                <p class="text-sm text-slate-400">Support tickets from schools will appear here</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-100">
                                            <th class="pb-3 pr-4">ID</th>
                                            <th class="pb-3 pr-4">School</th>
                                            <th class="pb-3 pr-4">Subject</th>
                                            <th class="pb-3 pr-4">Status</th>
                                            <th class="pb-3 pr-4">Priority</th>
                                            <th class="pb-3">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tickets as $ticket): ?>
                                            <tr class="border-b border-slate-50 hover:bg-slate-50 transition">
                                                <td class="py-3 pr-4 font-mono text-xs">#<?php echo $ticket['id']; ?></td>
                                                <td class="py-3 pr-4 font-medium"><?php echo htmlspecialchars($ticket['school_name'] ?? 'N/A'); ?></td>
                                                <td class="py-3 pr-4"><?php echo htmlspecialchars($ticket['subject'] ?? 'No subject'); ?></td>
                                                <td class="py-3 pr-4">
                                                    <span class="px-2 py-1 text-xs font-bold rounded <?php echo $ticket['status'] === 'open' ? 'bg-yellow-100 text-yellow-700' : ($ticket['status'] === 'pending' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'); ?>">
                                                        <?php echo ucfirst($ticket['status'] ?? 'unknown'); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 pr-4"><?php echo ucfirst($ticket['priority'] ?? 'normal'); ?></td>
                                                <td class="py-3 text-slate-400"><?php echo date('d M, Y', strtotime($ticket['created_at'])); ?></td>
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
