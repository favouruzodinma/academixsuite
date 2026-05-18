<?php
require_once __DIR__ . '/../../includes/autoload.php';

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
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (empty($name) || empty($email)) {
        $error = 'Name and email are required.';
    } else {
        try {
            if (!empty($currentPassword)) {
                $result = $auth->loginSuperAdmin($email, $currentPassword);
                if ($result['success']) {
                    if (!empty($newPassword)) {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE platform_users SET name = ?, email = ?, password = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $hashedPassword, $superAdmin['id']]);
                        $_SESSION['super_admin']['name'] = $name;
                        $_SESSION['super_admin']['email'] = $email;
                        $message = 'Profile and password updated successfully.';
                    } else {
                        $stmt = $db->prepare("UPDATE platform_users SET name = ?, email = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $superAdmin['id']]);
                        $_SESSION['super_admin']['name'] = $name;
                        $_SESSION['super_admin']['email'] = $email;
                        $message = 'Profile updated successfully.';
                    }
                } else {
                    $error = 'Current password is incorrect.';
                }
            } else {
                $stmt = $db->prepare("UPDATE platform_users SET name = ?, email = ? WHERE id = ?");
                $stmt->execute([$name, $email, $superAdmin['id']]);
                $_SESSION['super_admin']['name'] = $name;
                $_SESSION['super_admin']['email'] = $email;
                $message = 'Profile updated successfully.';
            }
        } catch (Exception $e) {
            $error = 'Failed to update profile: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>My Profile | <?php echo APP_NAME; ?> Executive</title>
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
        <?php include_once('filepath/sidebar.php'); ?>
        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">My Profile</h1>
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
                        <div class="flex items-center gap-6 mb-8">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($superAdmin['name']); ?>&background=1e293b&color=fff&bold=true&size=128" class="w-20 h-20 rounded-2xl shadow-sm">
                            <div>
                                <h2 class="text-xl font-black text-slate-800"><?php echo htmlspecialchars($superAdmin['name']); ?></h2>
                                <p class="text-sm text-slate-400"><?php echo htmlspecialchars($superAdmin['email'] ?? ''); ?></p>
                                <span class="inline-block mt-1 px-3 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded">Super Admin</span>
                            </div>
                        </div>
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Full Name</label>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($superAdmin['name']); ?>" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Email Address</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($superAdmin['email'] ?? ''); ?>" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none" required>
                                </div>
                            </div>
                            <div class="border-t border-slate-100 pt-6">
                                <h3 class="text-sm font-black text-slate-700 mb-4">Change Password</h3>
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Current Password</label>
                                        <input type="password" name="current_password" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">New Password</label>
                                        <input type="password" name="new_password" class="input-field w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none">
                                    </div>
                                </div>
                            </div>
                            <div class="pt-4 border-t border-slate-100">
                                <button type="submit" class="px-8 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl font-bold text-sm shadow-xl shadow-blue-200/50 hover:shadow-blue-300/50 transition-all hover:-translate-y-0.5 active:scale-[0.98]">
                                    <i class="fas fa-save mr-2"></i> Update Profile
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
