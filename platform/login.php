<?php
// platform/login.php
session_start();

// Check if already logged in
if (isset($_SESSION['super_admin'])) {
    header('Location: admin/index.php');
    exit;
}

// Include configuration
require_once __DIR__ . '/../includes/autoload.php';

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!Session::validateCsrfToken($_POST['csrf_token'] ?? '', 'admin_login')) {
        $error = 'Security token invalid. Please refresh the page.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password';
        } else {
            try {
                $auth = new Auth();
                $result = $auth->loginSuperAdmin($email, $password);
                
                if ($result['success']) {
                    header('Location: ' . $result['redirect']);
                    exit;
                } else {
                    $error = $result['message'];
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'Login failed. Please try again.';
            }
        }
    }
}

// Generate CSRF token
$csrfToken = Session::generateCsrfToken('admin_login');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Super Admin Login | <?php echo APP_NAME; ?> Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg);
            background-image: radial-gradient(#cbd5e1 0.5px, transparent 0.5px);
            background-size: 24px 24px;
            color: #1e293b; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .login-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
        }

        .input-focus {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-focus:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
            transform: translateY(-1px);
        }

        .auth-shield {
            animation: pulse-blue 3s infinite;
        }

        @keyframes pulse-blue {
            0% { transform: scale(1); opacity: 0.1; }
            50% { transform: scale(1.08); opacity: 0.2; }
            100% { transform: scale(1); opacity: 0.1; }
        }

        .error-shake {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }
    </style>
</head>
<body class="antialiased min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-md animate-fadeInUp">
        
        <div class="flex flex-col items-center mb-10">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center shadow-xl shadow-blue-200 mb-4 relative z-10">
                <i class="fas fa-university text-white text-2xl"></i>
                <div class="absolute inset-0 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl auth-shield -z-10"></div>
            </div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?php echo APP_NAME; ?></h1>
            <p class="text-slate-500 text-sm mt-2 font-bold uppercase tracking-widest">Super Admin Portal</p>
        </div>

        <div class="login-glass p-10 rounded-[2rem]">
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl error-shake">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-triangle text-red-500 text-lg"></i>
                        <p class="text-red-700 font-medium text-sm"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-emerald-500 text-lg"></i>
                        <p class="text-emerald-700 font-medium text-sm"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h2 class="text-xl font-black text-slate-800">Security Clearance</h2>
                <p class="text-slate-400 text-sm">Please verify your credentials to access the platform.</p>
            </div>

            <form action="" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">Admin Email</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                            <i class="fas fa-user-shield text-sm"></i>
                        </span>
                        <input type="email" name="email" required placeholder="admin@<?php echo parse_url(APP_URL, PHP_URL_HOST); ?>" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300">
                    </div>
                </div>

                <div>
                    <div class="flex justify-between mb-2 px-1">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Password</label>
                        <a href="reset-password.php" class="text-[10px] font-bold text-blue-600 hover:text-blue-700 hover:underline uppercase tracking-widest transition-colors">Recovery</a>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                            <i class="fas fa-lock text-sm"></i>
                        </span>
                        <input type="password" id="password" name="password" required placeholder="••••••••••••" 
                               class="w-full pl-12 pr-12 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300">
                        <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-blue-600 transition-colors">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-3 p-3 bg-blue-50/50 border border-blue-100 rounded-lg">
                    <i class="fas fa-info-circle text-blue-500 text-sm"></i>
                    <p class="text-[11px] text-blue-700 font-medium">Enhanced security monitoring enabled. All activities are logged.</p>
                </div>

                <button type="submit" 
                        class="w-full py-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-xl shadow-blue-200/50 hover:shadow-blue-300/50 transition-all duration-300 transform hover:-translate-y-0.5 active:scale-[0.98]">
                    <i class="fas fa-fingerprint mr-2"></i> Authorize Access
                </button>
            </form>
        </div>

        <div class="mt-10 flex flex-col items-center gap-6">
            <div class="flex items-center gap-6 opacity-30 grayscale">
                <i class="fab fa-aws text-2xl" title="AWS Security"></i>
                <i class="fas fa-shield-halved text-2xl" title="Encrypted"></i>
                <i class="fas fa-lock text-2xl" title="256-bit SSL"></i>
                <i class="fas fa-server text-2xl" title="Secure Server"></i>
            </div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.3em]">
                &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> // Secure Instance v<?php echo APP_VERSION; ?>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = passwordInput.nextElementSibling.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Authenticating...';
            btn.disabled = true;
        });

        // Input focus effects
        document.querySelectorAll('.input-focus').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('ring-2', 'ring-blue-200', 'ring-opacity-50');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('ring-2', 'ring-blue-200', 'ring-opacity-50');
            });
        });

        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>