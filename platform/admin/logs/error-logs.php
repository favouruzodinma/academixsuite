<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

$superAdmin = $_SESSION['super_admin'];

$logFiles = [];
$logDir = __DIR__ . '/../../../logs';
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log');
    foreach ($files as $file) {
        $logFiles[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'modified' => date('Y-m-d H:i:s', filemtime($file)),
            'path' => $file
        ];
    }
    rsort($logFiles);
}

$currentLog = null;
$currentLogName = $_GET['log'] ?? '';
if ($currentLogName) {
    $logPath = $logDir . '/' . basename($currentLogName);
    if (file_exists($logPath)) {
        $currentLog = file_get_contents($logPath);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Error Diagnostics | <?php echo APP_NAME; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        :root { --brand-primary: #2563eb; --brand-surface: #ffffff; --brand-bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-bg); color: #1e293b; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .touch-target { min-height: 44px; display: flex; align-items: center; }
        .log-entry { font-family: 'SF Mono', 'Monaco', monospace; font-size: 12px; line-height: 1.6; }
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Error Diagnostics</h1>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <div class="lg:col-span-1">
                            <div class="bg-white rounded-2xl border border-slate-200 p-4">
                                <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider mb-3">Log Files</h3>
                                <?php if (empty($logFiles)): ?>
                                    <p class="text-sm text-slate-400">No log files found</p>
                                <?php else: ?>
                                    <div class="space-y-1">
                                        <?php foreach ($logFiles as $file): ?>
                                            <a href="?log=<?php echo urlencode($file['name']); ?>" class="block px-3 py-2 rounded-lg text-sm <?php echo $currentLogName === $file['name'] ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:bg-slate-50'; ?> transition">
                                                <div class="flex items-center justify-between">
                                                    <span class="truncate"><?php echo htmlspecialchars($file['name']); ?></span>
                                                    <span class="text-[10px] text-slate-400"><?php echo round($file['size'] / 1024, 1); ?> KB</span>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="lg:col-span-3">
                            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                                <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider mb-4">
                                    <?php echo $currentLogName ? htmlspecialchars($currentLogName) : 'Select a log file'; ?>
                                </h3>
                                <?php if ($currentLog): ?>
                                    <pre class="log-entry bg-slate-900 text-green-400 rounded-xl p-4 overflow-x-auto max-h-[70vh] overflow-y-auto"><?php echo htmlspecialchars($currentLog); ?></pre>
                                <?php else: ?>
                                    <div class="text-center py-16">
                                        <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                            <i class="fas fa-file-code text-slate-400 text-3xl"></i>
                                        </div>
                                        <h3 class="text-lg font-bold text-slate-600 mb-2">Select a Log File</h3>
                                        <p class="text-sm text-slate-400">Choose a log file from the sidebar to view its contents</p>
                                    </div>
                                <?php endif; ?>
                            </div>
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
