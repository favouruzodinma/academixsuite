<?php
/**
 * Forgot Password - School Portal
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/password_reset.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_httponly' => true,
        'cookie_secure'   => false,
    ]);
}

// Load configuration
$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

// Initialize variables
$error = '';
$success = '';
$schoolSlug = $_GET['school_slug'] ?? '';
$school = null;

// Get school information if slug provided
if (!empty($schoolSlug)) {
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("
            SELECT s.*, p.name as plan_name 
            FROM schools s 
            LEFT JOIN plans p ON s.plan_id = p.id 
            WHERE s.slug = ? AND s.status IN ('active', 'trial')
            LIMIT 1
        ");
        
        if ($stmt) {
            $stmt->execute([$schoolSlug]);
            $school = $stmt->fetch();
        }
    } catch (Exception $e) {
        error_log("Platform database error: " . $e->getMessage());
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'request';
    
    if ($action === 'request') {
        // Request password reset
        $email = trim($_POST['email'] ?? '');
        $schoolSlugPost = trim($_POST['school_slug'] ?? '');
        $userType = trim($_POST['user_type'] ?? '');
        
        if (empty($email) || empty($schoolSlugPost) || empty($userType)) {
            $error = 'Please fill in all required fields';
        } else {
            try {
                // Verify school exists
                $platformDb = Database::getPlatformConnection();
                $stmt = $platformDb->prepare("
                    SELECT * FROM schools 
                    WHERE slug = ? AND status IN ('active', 'trial')
                    LIMIT 1
                ");
                $stmt->execute([$schoolSlugPost]);
                $school = $stmt->fetch();
                
                if (!$school) {
                    $error = 'School not found or inactive';
                } else {
                    // Connect to school database
                    $schoolDb = Database::getSchoolConnection($school['database_name']);
                    
                    // Check if user exists
                    $query = "SELECT * FROM users WHERE email = ?";
                    $params = [$email];
                    
                    // Add user type condition if not admin
                    if ($userType !== 'admin') {
                        $query .= " AND user_type = ?";
                        $params[] = $userType;
                    }
                    
                    $query .= " AND is_active = 1 LIMIT 1";
                    
                    $stmt = $schoolDb->prepare($query);
                    $stmt->execute($params);
                    $user = $stmt->fetch();
                    
                    if ($user) {
                        // Generate reset token
                        $token = bin2hex(random_bytes(32));
                        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                        
                        // Create or update password reset record
                        try {
                            // Check if password_resets table exists
                            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'password_resets'")->fetch();
                            
                            if ($tableCheck) {
                                // Delete existing tokens for this user
                                $deleteStmt = $schoolDb->prepare("DELETE FROM password_resets WHERE email = ?");
                                $deleteStmt->execute([$email]);
                                
                                // Insert new token
                                $insertStmt = $schoolDb->prepare("
                                    INSERT INTO password_resets (email, token, user_type, school_id, expires_at, created_at) 
                                    VALUES (?, ?, ?, ?, ?, NOW())
                                ");
                                $insertStmt->execute([$email, $token, $userType, $school['id'], $expires_at]);
                            } else {
                                // Create table if doesn't exist
                                $schoolDb->exec("
                                    CREATE TABLE IF NOT EXISTS password_resets (
                                        id INT AUTO_INCREMENT PRIMARY KEY,
                                        email VARCHAR(255) NOT NULL,
                                        token VARCHAR(255) NOT NULL,
                                        user_type VARCHAR(50) NOT NULL,
                                        school_id INT NOT NULL,
                                        expires_at DATETIME NOT NULL,
                                        created_at DATETIME NOT NULL,
                                        used_at DATETIME NULL,
                                        INDEX idx_email (email),
                                        INDEX idx_token (token)
                                    )
                                ");
                                
                                // Insert token
                                $insertStmt = $schoolDb->prepare("
                                    INSERT INTO password_resets (email, token, user_type, school_id, expires_at, created_at) 
                                    VALUES (?, ?, ?, ?, ?, NOW())
                                ");
                                $insertStmt->execute([$email, $token, $userType, $school['id'], $expires_at]);
                            }
                            
                            // Send reset email
                            $resetLink = "https://{$_SERVER['HTTP_HOST']}/academixsuite/tenant/forgot-password.php?token={$token}&school_slug={$schoolSlugPost}";
                            
                            // In production, you would send an actual email
                            // For now, store in session for display
                            $_SESSION['reset_token_demo'] = $token;
                            $_SESSION['reset_email'] = $email;
                            $_SESSION['reset_expires'] = $expires_at;
                            
                            $success = 'Password reset instructions have been sent to your email.';
                            error_log("Password reset requested for: {$email} in school: {$schoolSlugPost}");
                            
                        } catch (Exception $e) {
                            error_log("Token creation error: " . $e->getMessage());
                            $error = 'Could not process reset request. Please try again.';
                        }
                    } else {
                        $error = 'No active account found with this email address';
                    }
                }
            } catch (Exception $e) {
                error_log("Password reset error: " . $e->getMessage());
                $error = 'System error occurred. Please try again later.';
            }
        }
    } elseif ($action === 'reset') {
        // Reset password
        $token = $_POST['token'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $schoolSlugPost = $_POST['school_slug'] ?? '';
        
        if (empty($token) || empty($newPassword) || empty($confirmPassword) || empty($schoolSlugPost)) {
            $error = 'All fields are required';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long';
        } else {
            try {
                // Verify school exists
                $platformDb = Database::getPlatformConnection();
                $stmt = $platformDb->prepare("SELECT * FROM schools WHERE slug = ? LIMIT 1");
                $stmt->execute([$schoolSlugPost]);
                $school = $stmt->fetch();
                
                if (!$school) {
                    $error = 'Invalid school';
                } else {
                    // Connect to school database
                    $schoolDb = Database::getSchoolConnection($school['database_name']);
                    
                    // Check if password_resets table exists
                    $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'password_resets'")->fetch();
                    
                    if (!$tableCheck) {
                        $error = 'Invalid or expired reset token';
                    } else {
                        // Verify token
                        $stmt = $schoolDb->prepare("
                            SELECT * FROM password_resets 
                            WHERE token = ? AND school_id = ? AND used_at IS NULL AND expires_at > NOW()
                            LIMIT 1
                        ");
                        $stmt->execute([$token, $school['id']]);
                        $resetRecord = $stmt->fetch();
                        
                        if (!$resetRecord) {
                            $error = 'Invalid or expired reset token';
                        } else {
                            // Update user password
                            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                            
                            $updateStmt = $schoolDb->prepare("
                                UPDATE users 
                                SET password = ?, updated_at = NOW() 
                                WHERE email = ? AND user_type = ?
                            ");
                            $updateStmt->execute([$hashedPassword, $resetRecord['email'], $resetRecord['user_type']]);
                            
                            // Mark token as used
                            $markStmt = $schoolDb->prepare("
                                UPDATE password_resets 
                                SET used_at = NOW() 
                                WHERE id = ?
                            ");
                            $markStmt->execute([$resetRecord['id']]);
                            
                            $success = 'Password has been reset successfully. You can now login with your new password.';
                            error_log("Password reset completed for: {$resetRecord['email']} in school: {$schoolSlugPost}");
                            
                            // Clear demo token if set
                            if (isset($_SESSION['reset_token_demo'])) {
                                unset($_SESSION['reset_token_demo'], $_SESSION['reset_email'], $_SESSION['reset_expires']);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Password reset processing error: " . $e->getMessage());
                $error = 'Could not reset password. Please try again.';
            }
        }
    }
}

// Check if token is provided in URL
$token = $_GET['token'] ?? '';
$showResetForm = !empty($token);

// Get schools for auto-suggest
$schools = [];
try {
    $db = Database::getPlatformConnection();
    $schools = $db->query("
        SELECT id, name, slug 
        FROM schools 
        WHERE status IN ('active', 'trial') 
        ORDER BY name
    ")->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching schools: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo $showResetForm ? 'Reset Password' : 'Forgot Password'; ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --brand-primary: #4f46e5;
            --brand-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
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
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
            transform: translateY(-1px);
        }

        .key-pulse {
            animation: pulse-indigo 3s infinite;
        }

        @keyframes pulse-indigo {
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

        .suggestions-container {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            margin-top: 0.25rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 50;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .suggestion-item:hover {
            background-color: #f3f4f6;
        }
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        
        .strength-weak {
            background: #ef4444;
            width: 25%;
        }
        
        .strength-fair {
            background: #f59e0b;
            width: 50%;
        }
        
        .strength-good {
            background: #10b981;
            width: 75%;
        }
        
        .strength-strong {
            background: #059669;
            width: 100%;
        }
        
        .progress-bar {
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--brand-primary);
            transition: width 0.3s ease;
        }
    </style>
</head>
<body class="antialiased min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-md animate-fadeInUp">
        
        <div class="flex flex-col items-center mb-8">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center shadow-xl shadow-indigo-200 mb-4 relative z-10">
                <i class="fas fa-<?php echo $showResetForm ? 'key' : 'unlock-alt'; ?> text-white text-2xl"></i>
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-2xl key-pulse -z-10"></div>
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">
                <?php echo $showResetForm ? 'Reset Your Password' : 'Forgot Password'; ?>
            </h1>
            <p class="text-slate-500 text-sm mt-1 font-medium">
                <?php echo $showResetForm ? 'Set a new secure password' : 'Recover your school portal access'; ?>
            </p>
        </div>

        <div class="login-glass p-8 rounded-[2rem]">
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
                    <?php if (!$showResetForm): ?>
                        <div class="mt-3 text-xs text-emerald-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            If you don't receive an email within 5 minutes, check your spam folder.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($showResetForm && empty($success)): ?>
                <!-- Reset Password Form -->
                <form action="" method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="school_slug" value="<?php echo htmlspecialchars($schoolSlug); ?>">
                    
                    <div class="mb-6">
                        <h2 class="text-lg font-black text-slate-800">Set New Password</h2>
                        <p class="text-slate-400 text-sm">Create a strong, secure password for your account</p>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">
                            <i class="fas fa-lock mr-1"></i> New Password
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                                <i class="fas fa-key text-sm"></i>
                            </span>
                            <input type="password" 
                                   id="new_password" 
                                   name="new_password" 
                                   required 
                                   placeholder="Enter new password (min. 8 characters)" 
                                   class="w-full pl-12 pr-12 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300"
                                   minlength="8">
                            <button type="button" onclick="togglePassword('new_password')" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-indigo-600 transition-colors">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <div class="progress-bar mb-2">
                                <div id="passwordStrength" class="progress-fill" style="width: 0%"></div>
                            </div>
                            <div id="passwordCriteria" class="text-xs text-slate-500 space-y-1 hidden">
                                <div class="flex items-center" id="criteria-length"><i class="fas fa-circle text-[6px] mr-2"></i> At least 8 characters</div>
                                <div class="flex items-center" id="criteria-uppercase"><i class="fas fa-circle text-[6px] mr-2"></i> One uppercase letter</div>
                                <div class="flex items-center" id="criteria-lowercase"><i class="fas fa-circle text-[6px] mr-2"></i> One lowercase letter</div>
                                <div class="flex items-center" id="criteria-number"><i class="fas fa-circle text-[6px] mr-2"></i> One number</div>
                                <div class="flex items-center" id="criteria-special"><i class="fas fa-circle text-[6px] mr-2"></i> One special character</div>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">
                            <i class="fas fa-lock mr-1"></i> Confirm Password
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                                <i class="fas fa-key text-sm"></i>
                            </span>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   required 
                                   placeholder="Confirm your new password" 
                                   class="w-full pl-12 pr-12 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300"
                                   minlength="8">
                            <button type="button" onclick="togglePassword('confirm_password')" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-indigo-600 transition-colors">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" class="text-xs mt-2 hidden">
                            <i class="fas fa-check-circle text-emerald-500 mr-1"></i>
                            <span class="text-emerald-600">Passwords match</span>
                        </div>
                        <div id="passwordMismatch" class="text-xs mt-2 hidden">
                            <i class="fas fa-times-circle text-red-500 mr-1"></i>
                            <span class="text-red-600">Passwords do not match</span>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-3 p-3 bg-indigo-50/50 border border-indigo-100 rounded-lg">
                        <i class="fas fa-shield-alt text-indigo-500 text-sm"></i>
                        <p class="text-[11px] text-indigo-700 font-medium">Strong passwords protect your account from unauthorized access.</p>
                    </div>

                    <button type="submit" 
                            id="resetBtn"
                            class="w-full py-4 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white rounded-xl font-bold text-sm shadow-xl shadow-indigo-200/50 hover:shadow-indigo-300/50 transition-all duration-300 transform hover:-translate-y-0.5 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-key mr-2"></i> Reset Password
                    </button>
                    
                    <div class="text-center pt-4 border-t border-slate-200">
                        <a href="/academixsuite/tenant/login.php<?php echo $schoolSlug ? '?school_slug=' . urlencode($schoolSlug) : ''; ?>" 
                           class="text-sm text-indigo-600 hover:text-indigo-800 inline-flex items-center">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Login
                        </a>
                    </div>
                </form>
            <?php elseif (empty($success)): ?>
                <!-- Forgot Password Request Form -->
                <form action="" method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="request">
                    
                    <div class="mb-6">
                        <h2 class="text-lg font-black text-slate-800">Recover Account</h2>
                        <p class="text-slate-400 text-sm">Enter your school details to receive reset instructions</p>
                    </div>
                    
                    <!-- School Search -->
                    <div class="relative">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">
                            <i class="fas fa-school mr-1"></i> School Identification
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                                <i class="fas fa-search text-sm"></i>
                            </span>
                            <input type="text" 
                                   name="school_slug" 
                                   id="schoolInput"
                                   required
                                   placeholder="Search for your school..."
                                   autocomplete="off"
                                   class="w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300"
                                   value="<?php echo isset($_POST['school_slug']) ? htmlspecialchars($_POST['school_slug']) : htmlspecialchars($schoolSlug); ?>">
                        </div>
                        <div id="schoolSuggestions" class="suggestions-container hidden"></div>
                    </div>
                    
                    <!-- User Type -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">
                            <i class="fas fa-user-tag mr-1"></i> Account Type
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <?php 
                            $userTypes = [
                                'admin' => ['icon' => 'user-shield', 'label' => 'Administrator'],
                                'teacher' => ['icon' => 'chalkboard-teacher', 'label' => 'Teacher'],
                                'student' => ['icon' => 'graduation-cap', 'label' => 'Student'],
                                'parent' => ['icon' => 'user-friends', 'label' => 'Parent']
                            ];
                            foreach ($userTypes as $type => $info): 
                            ?>
                            <label class="user-type-btn p-3 text-center border border-slate-200 rounded-xl cursor-pointer transition-all duration-300 hover:border-indigo-300">
                                <div class="w-8 h-8 rounded-lg <?php echo $type === 'admin' ? 'bg-indigo-100' : ($type === 'teacher' ? 'bg-emerald-100' : ($type === 'student' ? 'bg-blue-100' : 'bg-amber-100')); ?> flex items-center justify-center mx-auto mb-2">
                                    <i class="fas fa-<?php echo $info['icon']; ?> <?php echo $type === 'admin' ? 'text-indigo-600' : ($type === 'teacher' ? 'text-emerald-600' : ($type === 'student' ? 'text-blue-600' : 'text-amber-600')); ?> text-sm"></i>
                                </div>
                                <span class="block text-xs font-medium"><?php echo $info['label']; ?></span>
                                <input type="radio" name="user_type" value="<?php echo $type; ?>" class="hidden" <?php echo isset($_POST['user_type']) && $_POST['user_type'] === $type ? 'checked' : ($type === 'admin' ? 'checked' : ''); ?>>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">
                            <i class="fas fa-envelope mr-1"></i> Account Email
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                                <i class="fas fa-at text-sm"></i>
                            </span>
                            <input type="email" 
                                   name="email" 
                                   required
                                   placeholder="Enter your registered email address"
                                   autocomplete="email"
                                   class="w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300"
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                    
                    <?php if (isset($_SESSION['reset_token_demo'])): ?>
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl">
                        <h4 class="text-sm font-bold text-blue-800 mb-2 flex items-center">
                            <i class="fas fa-envelope-open-text mr-2"></i> Demo Reset Token
                        </h4>
                        <p class="text-xs text-blue-600 mb-1">For testing purposes:</p>
                        <div class="bg-white p-3 rounded-lg border border-blue-100">
                            <p class="text-xs font-mono text-blue-800 break-all">Token: <?php echo $_SESSION['reset_token_demo']; ?></p>
                            <p class="text-xs text-blue-600 mt-1">Expires: <?php echo $_SESSION['reset_expires']; ?></p>
                            <p class="text-xs text-blue-600">Email: <?php echo $_SESSION['reset_email']; ?></p>
                        </div>
                        <p class="text-xs text-blue-600 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            In production, this would be sent via email.
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center gap-3 p-3 bg-indigo-50/50 border border-indigo-100 rounded-lg">
                        <i class="fas fa-envelope text-indigo-500 text-sm"></i>
                        <p class="text-[11px] text-indigo-700 font-medium">Reset instructions will be sent to your email. Check spam folder if not received.</p>
                    </div>

                    <button type="submit" 
                            class="w-full py-4 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white rounded-xl font-bold text-sm shadow-xl shadow-indigo-200/50 hover:shadow-indigo-300/50 transition-all duration-300 transform hover:-translate-y-0.5 active:scale-[0.98]">
                        <i class="fas fa-paper-plane mr-2"></i> Send Reset Instructions
                    </button>
                    
                    <div class="text-center pt-4 border-t border-slate-200">
                        <a href="/academixsuite/tenant/login.php<?php echo $schoolSlug ? '?school_slug=' . urlencode($schoolSlug) : ''; ?>" 
                           class="text-sm text-indigo-600 hover:text-indigo-800 inline-flex items-center">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Login
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="mt-8 flex flex-col items-center gap-6">
            <div class="flex items-center gap-6 opacity-30 grayscale">
                <i class="fas fa-envelope-shield text-2xl" title="Secure Email"></i>
                <i class="fas fa-clock text-2xl" title="1-hour Expiry"></i>
                <i class="fas fa-lock text-2xl" title="Encrypted"></i>
                <i class="fas fa-shield-check text-2xl" title="Verified"></i>
            </div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.3em]">
                &copy; <?php echo date('Y'); ?> <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> // Secure Password Recovery
            </div>
        </div>
    </div>
    
    <!-- School Data for JavaScript -->
    <script id="schoolsData" type="application/json">
        <?php echo json_encode($schools); ?>
    </script>
    
    <script>
        // School suggestions functionality
        const schoolsData = JSON.parse(document.getElementById('schoolsData').textContent) || [];
        const schoolInput = document.getElementById('schoolInput');
        const suggestionsContainer = document.getElementById('schoolSuggestions');
        
        // Filter schools based on input
        function filterSchools(query) {
            if (!query.trim()) return [];
            
            const lowerQuery = query.toLowerCase();
            return schoolsData.filter(school => 
                school.name.toLowerCase().includes(lowerQuery) || 
                school.slug.toLowerCase().includes(lowerQuery)
            ).slice(0, 5);
        }
        
        // Show suggestions
        function showSuggestions(schools) {
            if (!suggestionsContainer) return;
            
            suggestionsContainer.innerHTML = '';
            
            if (schools.length === 0) {
                suggestionsContainer.innerHTML = `
                    <div class="suggestion-item text-center text-gray-500 text-sm">
                        <i class="fas fa-search mr-2"></i>No schools found
                    </div>
                `;
                suggestionsContainer.classList.remove('hidden');
                return;
            }
            
            schools.forEach(school => {
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.innerHTML = `
                    <div class="font-medium text-slate-900 text-sm">${school.name}</div>
                    <div class="text-xs text-slate-500">ID: ${school.slug}</div>
                `;
                div.addEventListener('click', () => {
                    schoolInput.value = school.slug;
                    suggestionsContainer.classList.add('hidden');
                });
                suggestionsContainer.appendChild(div);
            });
            
            suggestionsContainer.classList.remove('hidden');
        }
        
        // Hide suggestions when clicking outside
        if (schoolInput && suggestionsContainer) {
            document.addEventListener('click', (e) => {
                if (!schoolInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
                    suggestionsContainer.classList.add('hidden');
                }
            });
            
            // Handle school input
            schoolInput.addEventListener('input', (e) => {
                const query = e.target.value.trim();
                
                if (query.length < 2) {
                    suggestionsContainer.classList.add('hidden');
                    return;
                }
                
                const filteredSchools = filterSchools(query);
                showSuggestions(filteredSchools);
            });
            
            schoolInput.addEventListener('focus', () => {
                const query = schoolInput.value.trim();
                if (query.length >= 2) {
                    const filteredSchools = filterSchools(query);
                    showSuggestions(filteredSchools);
                }
            });
            
            // Keyboard navigation for suggestions
            schoolInput.addEventListener('keydown', (e) => {
                const suggestions = suggestionsContainer.querySelectorAll('.suggestion-item');
                const currentFocus = suggestionsContainer.querySelector('.suggestion-item.hover');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!currentFocus) {
                        suggestions[0]?.classList.add('hover');
                    } else {
                        const index = Array.from(suggestions).indexOf(currentFocus);
                        currentFocus.classList.remove('hover');
                        suggestions[(index + 1) % suggestions.length]?.classList.add('hover');
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!currentFocus) {
                        suggestions[suggestions.length - 1]?.classList.add('hover');
                    } else {
                        const index = Array.from(suggestions).indexOf(currentFocus);
                        currentFocus.classList.remove('hover');
                        suggestions[(index - 1 + suggestions.length) % suggestions.length]?.classList.add('hover');
                    }
                } else if (e.key === 'Enter' && currentFocus) {
                    e.preventDefault();
                    currentFocus.click();
                }
            });
        }
        
        // User type selection
        document.querySelectorAll('.user-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    // Remove selected from all
                    document.querySelectorAll('.user-type-btn').forEach(b => {
                        b.classList.remove('selected');
                    });
                    // Add to clicked
                    this.classList.add('selected');
                }
            });
        });
        
        // Initialize user type buttons
        document.querySelectorAll('.user-type-btn').forEach(btn => {
            const radio = btn.querySelector('input[type="radio"]');
            if (radio && radio.checked) {
                btn.classList.add('selected');
            }
        });
        
        // Toggle password visibility
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
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
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            const criteria = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
            
            // Calculate strength (0-4)
            Object.values(criteria).forEach(met => {
                if (met) strength++;
            });
            
            // Update visual indicators
            Object.keys(criteria).forEach(key => {
                const element = document.getElementById(`criteria-${key}`);
                if (element) {
                    const icon = element.querySelector('i');
                    if (criteria[key]) {
                        icon.className = 'fas fa-check-circle text-emerald-500 text-xs mr-2';
                    } else {
                        icon.className = 'fas fa-circle text-[6px] text-slate-300 mr-2';
                    }
                }
            });
            
            // Update progress bar
            const progressBar = document.getElementById('passwordStrength');
            if (progressBar) {
                const width = (strength / 5) * 100;
                progressBar.style.width = width + '%';
                
                // Update color based on strength
                progressBar.className = 'progress-fill';
                if (strength <= 1) {
                    progressBar.classList.add('bg-red-500');
                } else if (strength <= 2) {
                    progressBar.classList.add('bg-amber-500');
                } else if (strength <= 3) {
                    progressBar.classList.add('bg-blue-500');
                } else {
                    progressBar.classList.add('bg-emerald-500');
                }
            }
            
            return strength;
        }
        
        // Password match checker
        function checkPasswordMatch() {
            const password = document.getElementById('new_password')?.value || '';
            const confirm = document.getElementById('confirm_password')?.value || '';
            const matchElement = document.getElementById('passwordMatch');
            const mismatchElement = document.getElementById('passwordMismatch');
            
            if (!matchElement || !mismatchElement) return;
            
            if (confirm.length === 0) {
                matchElement.classList.add('hidden');
                mismatchElement.classList.add('hidden');
                return;
            }
            
            if (password === confirm && password.length >= 8) {
                matchElement.classList.remove('hidden');
                mismatchElement.classList.add('hidden');
            } else {
                matchElement.classList.add('hidden');
                mismatchElement.classList.remove('hidden');
            }
        }
        
        // Password validation for reset form
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('resetBtn');
        const criteriaElement = document.getElementById('passwordCriteria');
        
        if (newPasswordInput && confirmPasswordInput && resetBtn) {
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                const strength = checkPasswordStrength(password);
                
                // Show/hide criteria
                if (criteriaElement) {
                    if (password.length > 0) {
                        criteriaElement.classList.remove('hidden');
                    } else {
                        criteriaElement.classList.add('hidden');
                    }
                }
                
                checkPasswordMatch();
                updateResetButton();
            });
            
            confirmPasswordInput.addEventListener('input', function() {
                checkPasswordMatch();
                updateResetButton();
            });
            
            function updateResetButton() {
                const password = newPasswordInput.value;
                const confirm = confirmPasswordInput.value;
                const strength = checkPasswordStrength(password);
                
                if (password.length >= 8 && 
                    password === confirm && 
                    strength >= 3) {
                    resetBtn.disabled = false;
                } else {
                    resetBtn.disabled = true;
                }
            }
        }
        
        // Input focus effects
        document.querySelectorAll('.input-focus').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('ring-2', 'ring-indigo-200', 'ring-opacity-50');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('ring-2', 'ring-indigo-200', 'ring-opacity-50');
            });
        });
        
        // Form submission loading
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const btn = this.querySelector('button[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                    btn.disabled = true;
                }
            });
        });
        
        // Auto-focus school input if exists
        if (schoolInput) {
            setTimeout(() => {
                schoolInput.focus();
            }, 300);
        } else if (newPasswordInput) {
            setTimeout(() => {
                newPasswordInput.focus();
            }, 300);
        }
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>