<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

$superAdmin = $_SESSION['super_admin'];
$db = Database::getPlatformConnection();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $appName = trim($_POST['app_name'] ?? APP_NAME);
        $supportEmail = trim($_POST['support_email'] ?? SUPPORT_EMAIL);
        $supportPhone = trim($_POST['support_phone'] ?? SUPPORT_PHONE);
        $exchangeRate = floatval($_POST['exchange_rate'] ?? 1500);

        $stmt = $db->prepare("UPDATE platform_settings SET setting_value = CASE setting_key 
            WHEN 'app_name' THEN ? WHEN 'support_email' THEN ? WHEN 'support_phone' THEN ? WHEN 'exchange_rate' THEN ? END
            WHERE setting_key IN ('app_name', 'support_email', 'support_phone', 'exchange_rate')");
        $stmt->execute([$appName, $supportEmail, $supportPhone, $exchangeRate]);

        $message = 'Settings updated successfully.';
    } catch (Exception $e) {
        $error = 'Failed to update settings: ' . $e->getMessage();
    }
}

$settings = [];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM platform_settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log("Settings load error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Global Configuration | <?php echo APP_NAME; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        :root { --brand-primary: #2563eb; --brand-surface: #ffffff; --brand-bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-bg); color: #1e293b; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .touch-target { min-height: 44px; display: flex; align-items: center; }
        .input-field { transition: all 0.3s ease; }
        .input-field:focus { border-color: var(--brand-primary); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15); transform: translateY(-1px); }
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
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Global Configuration</h1>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-3xl mx-auto">
                    <?php if ($message): ?>
                        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3">
                            <i class="fas fa-check-circle text-emerald-500"></i>
                            <p class="text-emerald-700 font-medium text-sm"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
                            <i class="fas fa-exclamation-triangle text-red-500"></i>
                            <p class="text-red-700 font-medium text-sm"><?php echo htmlspecialchars($error); ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="bg-white rounded-2xl border border-slate-200 p-6 lg:p-8">
                        <div class="mb-8">
                            <h2 class="text-lg font-black text-slate-800">Platform Settings</h2>
                            <p class="text-sm text-slate-400">Configure global platform parameters</p>
                        </div>
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Application Name</label>
                                    <input type="text" name="app_name" value="<?php echo htmlspecialchars($settings['app_name'] ?? APP_NAME); ?>" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Support Email</label>
                                    <input type="email" name="support_email" value="<?php echo htmlspecialchars($settings['support_email'] ?? SUPPORT_EMAIL); ?>" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Support Phone</label>
                                    <input type="text" name="support_phone" value="<?php echo htmlspecialchars($settings['support_phone'] ?? SUPPORT_PHONE); ?>" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Exchange Rate (USD to NGN)</label>
                                    <input type="number" step="0.01" name="exchange_rate" value="<?php echo htmlspecialchars($settings['exchange_rate'] ?? '1500'); ?>" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none">
                                </div>
                            </div>
                            <div class="pt-4 border-t border-slate-100">
                                <button type="submit" class="px-8 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl font-bold text-sm shadow-xl shadow-blue-200/50 hover:shadow-blue-300/50 transition-all hover:-translate-y-0.5 active:scale-[0.98]">
                                    <i class="fas fa-save mr-2"></i> Save Settings
                                </button>
                            </div>
                        </form>
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
