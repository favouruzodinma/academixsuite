<?php
/**
 * School Portal Login - Professional Version
 */

// Enable error reporting (only for development)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/login.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_httponly' => true,
        'cookie_secure'   => false,
    ]);
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ./login.php');
    exit;
}

// Load configuration
$autoloadPath = __DIR__ . '/../../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

// Initialize variables
$error = '';
$schoolSlug = $_GET['school_slug'] ?? '';
$school = null;

// Check for existing session
if (isset($_SESSION['school_auth']) && !empty($_SESSION['school_auth']['school_slug'])) {
    $userType = $_SESSION['school_auth']['user_type'] ?? 'admin';
    $redirectUrl = "./{$_SESSION['school_auth']['school_slug']}/{$userType}/dashboard.php";
    header("Location: {$redirectUrl}");
    exit;
}

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
    $postSchoolSlug = trim($_POST['school_slug'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? 'admin';
    
    // Validate inputs
    if (empty($postSchoolSlug) || empty($username) || empty($password) || empty($userType)) {
        $error = 'Please fill in all required fields';
    } else {
        // Get school data
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
                SELECT s.*, p.name as plan_name 
                FROM schools s 
                LEFT JOIN plans p ON s.plan_id = p.id 
                WHERE s.slug = ? AND s.status IN ('active', 'trial')
                LIMIT 1
            ");
            $stmt->execute([$postSchoolSlug]);
            $school = $stmt->fetch();
            
            if (!$school) {
                $error = 'School not found or inactive';
            } else {
                // Connect to school database
                try {
                    $schoolDb = Database::getSchoolConnection($school['database_name']);
                    
                    // Get users table structure
                    $columns = $schoolDb->query("DESCRIBE users")->fetchAll();
                    $columnNames = array_column($columns, 'Field');
                    
                    // Build WHERE clause
                    $conditions = [];
                    $params = [];
                    
                    if (in_array('email', $columnNames)) {
                        $conditions[] = "email = ?";
                        $params[] = $username;
                    }
                    
                    if (in_array('username', $columnNames)) {
                        $conditions[] = "username = ?";
                        $params[] = $username;
                    }
                    
                    if (in_array('phone', $columnNames)) {
                        $conditions[] = "phone = ?";
                        $params[] = $username;
                    }
                    
                    if (empty($conditions)) {
                        $error = 'System configuration error';
                    } else {
                        $whereClause = implode(' OR ', $conditions);
                        $query = "SELECT * FROM users WHERE ($whereClause)";
                        
                        if (in_array('school_id', $columnNames)) {
                            $query .= " AND school_id = ?";
                            $params[] = $school['id'];
                        }
                        
                        if ($userType !== 'admin' && in_array('user_type', $columnNames)) {
                            $query .= " AND user_type = ?";
                            $params[] = $userType;
                        }
                        
                        if (in_array('is_active', $columnNames)) {
                            $query .= " AND is_active = 1";
                        } elseif (in_array('status', $columnNames)) {
                            $query .= " AND status = 'active'";
                        }
                        
                        $query .= " LIMIT 1";
                        
                        $stmt = $schoolDb->prepare($query);
                        $stmt->execute($params);
                        $user = $stmt->fetch();
                        
                        if ($user) {
                            $passwordHash = $user['password'];
                            $authenticated = false;
                            
                            if (password_verify($password, $passwordHash)) {
                                $authenticated = true;
                            } elseif ($password === $passwordHash) {
                                $authenticated = true;
                            } elseif (md5($password) === $passwordHash) {
                                $authenticated = true;
                            }
                            
                            if ($authenticated) {
                                $dbUserType = $user['user_type'] ?? 'admin';
                                if ($userType !== $dbUserType) {
                                    $error = "Access denied. Your account type is: " . ucfirst($dbUserType);
                                } else {
                                    // Get user role
                                    $roleName = 'Administrator';
                                    if (in_array('user_roles', $schoolDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN, 0))) {
                                        try {
                                            $roleStmt = $schoolDb->prepare("
                                                SELECT r.name 
                                                FROM user_roles ur 
                                                JOIN roles r ON ur.role_id = r.id 
                                                WHERE ur.user_id = ? 
                                                LIMIT 1
                                            ");
                                            $roleStmt->execute([$user['id']]);
                                            $role = $roleStmt->fetch();
                                            if ($role) {
                                                $roleName = $role['name'];
                                            }
                                        } catch (Exception $e) {}
                                    }
                                    
                                    // Set session
                                    $_SESSION['school_auth'] = [
                                        'school_id' => $school['id'],
                                        'school_slug' => $school['slug'],
                                        'school_name' => $school['name'],
                                        'database_name' => $school['database_name'],
                                        'user_id' => $user['id'],
                                        'user_name' => $user['name'] ?? ($user['username'] ?? 'User'),
                                        'user_email' => $user['email'] ?? '',
                                        'user_type' => $userType,
                                        'role_name' => $roleName,
                                        'login_time' => time(),
                                        'login_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                                    ];
                                    
                                    // Update last login
                                    if (in_array('last_login_at', $columnNames)) {
                                        $updateStmt = $schoolDb->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
                                        $updateStmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
                                    }
                                    
                                    // Redirect to dashboard
                                    $redirectUrl = "./{$school['slug']}/{$userType}/dashboard.php";
                                    header("Location: {$redirectUrl}");
                                    exit;
                                }
                            } else {
                                $error = 'Invalid credentials';
                            }
                        } else {
                            $error = 'Invalid credentials';
                        }
                    }
                    
                } catch (Exception $e) {
                    $error = 'System authentication error';
                    error_log("School database error: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $error = 'System error occurred';
            error_log("Platform database error: " . $e->getMessage());
        }
    }
}

// Get schools for auto-suggest
$schools = [];
try {
    $db = Database::getPlatformConnection();
    $schools = $db->query("
        SELECT id, name, slug, logo_path 
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
    <title><?php echo isset($_POST['school_slug']) ? htmlspecialchars($_POST['school_slug']) : htmlspecialchars($schoolSlug); ?> Portal Login | <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?></title>
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

        .school-pulse {
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
        
        .user-type-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
        }
        
        .user-type-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .user-type-btn.selected {
            border-color: var(--brand-primary);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.1);
        }
    </style>
</head>
<body class="antialiased min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-md animate-fadeInUp">
        
        <div class="flex flex-col items-center mb-8">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center shadow-xl shadow-indigo-200 mb-4 relative z-10">
                <i class="fas fa-school text-white text-2xl"></i>
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-2xl school-pulse -z-10"></div>
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">School Portal</h1>
            <p class="text-slate-500 text-sm mt-1 font-medium">Secure Access Gateway</p>
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

            <div class="mb-6">
                <h2 class="text-lg font-black text-slate-800">School Authentication</h2>
                <p class="text-slate-400 text-sm">Select your school and enter credentials</p>
            </div>

            <form action="" method="POST" class="space-y-6" id="loginForm">
               
                
                <!-- User Type Selection -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 ml-1">
                        <i class="fas fa-user-tag mr-1"></i> Access Type
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" class="user-type-btn p-3 text-center border border-slate-200 rounded-xl" data-type="admin">
                            <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-user-shield text-indigo-600 text-sm"></i>
                            </div>
                            <span class="block text-xs font-medium">Administrator</span>
                            <input type="radio" name="user_type" value="admin" class="hidden" checked>
                        </button>
                        <button type="button" class="user-type-btn p-3 text-center border border-slate-200 rounded-xl" data-type="teacher">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-chalkboard-teacher text-emerald-600 text-sm"></i>
                            </div>
                            <span class="block text-xs font-medium">Teacher</span>
                            <input type="radio" name="user_type" value="teacher" class="hidden">
                        </button>
                        <button type="button" class="user-type-btn p-3 text-center border border-slate-200 rounded-xl" data-type="student">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-graduation-cap text-blue-600 text-sm"></i>
                            </div>
                            <span class="block text-xs font-medium">Student</span>
                            <input type="radio" name="user_type" value="student" class="hidden">
                        </button>
                        <button type="button" class="user-type-btn p-3 text-center border border-slate-200 rounded-xl" data-type="parent">
                            <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-user-friends text-amber-600 text-sm"></i>
                            </div>
                            <span class="block text-xs font-medium">Parent</span>
                            <input type="radio" name="user_type" value="parent" class="hidden">
                        </button>
                    </div>
                </div>
                
                <!-- Username -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1" id="usernameLabel">
                        <i class="fas fa-user-circle mr-1"></i> Credentials
                    </label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                            <i class="fas fa-envelope text-sm"></i>
                        </span>
                        <input type="text" 
                               name="username" 
                               required
                               placeholder="Enter your email address"
                               autocomplete="username"
                               class="w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>
                
                <!-- Password -->
                <div>
                    <div class="flex justify-between mb-2 px-1">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">
                            <i class="fas fa-key mr-1"></i> Security Key
                        </label>
                        <a href="./forgot-password.php" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-700 hover:underline uppercase tracking-widest transition-colors">Recovery</a>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                            <i class="fas fa-lock text-sm"></i>
                        </span>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               required 
                               placeholder="••••••••••••" 
                               class="w-full pl-12 pr-12 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm input-focus outline-none transition-all duration-300">
                        <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-indigo-600 transition-colors">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-3 p-3 bg-indigo-50/50 border border-indigo-100 rounded-lg">
                    <i class="fas fa-shield-alt text-indigo-500 text-sm"></i>
                    <p class="text-[11px] text-indigo-700 font-medium">School-level authentication. Each institution has isolated data.</p>
                </div>

                <button type="submit" 
                        class="w-full py-4 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white rounded-xl font-bold text-sm shadow-xl shadow-indigo-200/50 hover:shadow-indigo-300/50 transition-all duration-300 transform hover:-translate-y-0.5 active:scale-[0.98]">
                    <i class="fas fa-sign-in-alt mr-2"></i> Access School Portal
                </button>
            </form>
        </div>

        <div class="mt-8 flex flex-col items-center gap-6">
            <div class="flex items-center gap-6 opacity-30 grayscale">
                <i class="fas fa-school text-2xl" title="School Management"></i>
                <i class="fas fa-users text-2xl" title="Multi-tenant"></i>
                <i class="fas fa-database text-2xl" title="Isolated Data"></i>
                <i class="fas fa-shield-alt text-2xl" title="Secure Access"></i>
            </div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.3em]">
                &copy; <?php echo date('Y'); ?> <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> // Multi-tenant Platform
            </div>
        </div>
    </div>
    
    <!-- School Data for JavaScript -->
    <script id="schoolsData" type="application/json">
        <?php echo json_encode($schools); ?>
    </script>
    
    <script>
        // School suggestions functionality
        const schoolsData = JSON.parse(document.getElementById('schoolsData').textContent);
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
                    updateUsernamePlaceholder();
                });
                suggestionsContainer.appendChild(div);
            });
            
            suggestionsContainer.classList.remove('hidden');
        }
        
        // Hide suggestions when clicking outside
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
        
        // User type selection
        document.querySelectorAll('.user-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove selected class from all buttons
                document.querySelectorAll('.user-type-btn').forEach(b => {
                    b.classList.remove('selected');
                });
                
                // Add selected class to clicked button
                this.classList.add('selected');
                
                // Check the corresponding radio button
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    updateUsernamePlaceholder();
                }
            });
        });
        
        // Update username placeholder based on user type
        function updateUsernamePlaceholder() {
            const userType = document.querySelector('input[name="user_type"]:checked').value;
            const usernameLabel = document.getElementById('usernameLabel');
            const usernameInput = document.querySelector('input[name="username"]');
            
            let labelIcon = 'fas fa-envelope';
            let labelText = 'Email Address';
            let placeholder = 'Enter your email address';
            
            switch (userType) {
                case 'student':
                    labelIcon = 'fas fa-id-card';
                    labelText = 'Admission Number';
                    placeholder = 'Enter admission number or email';
                    break;
                case 'parent':
                    labelIcon = 'fas fa-phone';
                    labelText = 'Phone/Email';
                    placeholder = 'Enter phone number or email';
                    break;
                case 'teacher':
                    labelIcon = 'fas fa-user-tie';
                    labelText = 'Staff ID';
                    placeholder = 'Enter staff ID or email';
                    break;
            }
            
            usernameLabel.innerHTML = `<i class="${labelIcon} mr-1"></i> ${labelText}`;
            usernameInput.placeholder = placeholder;
        }
        
        // Select admin by default
        document.querySelector('[data-type="admin"]').click();
        
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
                this.parentElement.classList.add('ring-2', 'ring-indigo-200', 'ring-opacity-50');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('ring-2', 'ring-indigo-200', 'ring-opacity-50');
            });
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
        
        // Auto-focus school input with slight delay
        setTimeout(() => {
            schoolInput.focus();
        }, 300);
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>