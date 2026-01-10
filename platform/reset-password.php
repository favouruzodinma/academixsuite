<?php
// platform/reset-password.php
session_start();
require_once __DIR__ . '/../includes/autoload.php';

// Check if already logged in
if (isset($_SESSION['super_admin'])) {
    header('Location: admin/dashboard.php');
    exit;
}

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

// If no token, show email request form
if (empty($token)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = 'Please enter your email address';
        } else {
            $auth = new Auth();
            $result = $auth->resetPassword($email, 'super_admin');
            
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    }
    
    // Show email request form
    $csrfToken = Session::generateCsrfToken('reset_request');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reset Password | <?php echo APP_NAME; ?> Platform</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    </head>
    <body class="min-h-screen flex items-center justify-center p-6 bg-gray-50">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-8">
            <div class="flex flex-col items-center mb-8">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-key text-white text-lg"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">Reset Password</h1>
                <p class="text-slate-500 text-sm mt-1">Enter your email to receive reset instructions</p>
            </div>
            
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>
                        <p class="text-red-700 font-medium text-sm"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-emerald-500"></i>
                        <p class="text-emerald-700 font-medium text-sm"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <form action="" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Email Address</label>
                    <input type="email" name="email" required placeholder="admin@yourdomain.com" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    Send Reset Instructions
                </button>
            </form>
            
            <div class="mt-6 pt-6 border-t border-slate-100 text-center">
                <a href="login.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// If token is provided, show password reset form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $error = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        try {
            $auth = new Auth();
            $result = $auth->completePasswordReset($token, $password, 'super_admin');
            
            if ($result['success']) {
                $success = 'Password reset successfully! You can now login with your new password.';
                // Redirect to login after 3 seconds
                header('Refresh: 3; URL=login.php');
            } else {
                $error = $result['message'];
            }
        } catch (Exception $e) {
            $error = 'Failed to reset password';
        }
    }
}

// Show password reset form
$csrfToken = Session::generateCsrfToken('reset_password');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?php echo APP_NAME; ?> Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="min-h-screen flex items-center justify-center p-6 bg-gray-50">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-8">
        <div class="flex flex-col items-center mb-8">
            <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center mb-4">
                <i class="fas fa-key text-white text-lg"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Set New Password</h1>
            <p class="text-slate-500 text-sm mt-1">Create a new secure password</p>
        </div>
        
        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                <div class="flex items-center gap-3">
                    <i class="fas fa-exclamation-triangle text-red-500"></i>
                    <p class="text-red-700 font-medium text-sm"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
                <div class="flex items-center gap-3">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                    <p class="text-emerald-700 font-medium text-sm"><?php echo htmlspecialchars($success); ?></p>
                </div>
                <p class="text-slate-600 text-xs mt-2">Redirecting to login page...</p>
            </div>
        <?php endif; ?>
        
        <?php if (empty($error) && empty($success)): ?>
            <form action="" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">New Password</label>
                    <input type="password" name="password" required 
                           class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Confirm Password</label>
                    <input type="password" name="confirm_password" required 
                           class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <div class="text-xs text-slate-500 p-3 bg-slate-50 rounded-lg">
                    <p><i class="fas fa-info-circle mr-2"></i>Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters long.</p>
                </div>
                
                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    Reset Password
                </button>
            </form>
        <?php endif; ?>
        
        <div class="mt-6 pt-6 border-t border-slate-100 text-center">
            <a href="login.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                <i class="fas fa-arrow-left mr-2"></i>Back to Login
            </a>
        </div>
    </div>
</body>
</html>