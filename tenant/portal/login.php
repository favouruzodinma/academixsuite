<?php
/**
 * School-specific Login Page
 * Accessed via: /tenant/myschool/login.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('tenant_session');
    session_start();
}

// Get school slug from query string (passed by .htaccess)
$schoolSlug = $_GET['school_slug'] ?? '';

error_log("=== SCHOOL LOGIN ===");
error_log("School Slug: " . $schoolSlug);

if (empty($schoolSlug)) {
    error_log("ERROR: No school slug provided");
    header('Location: ../../login.php');
    exit;
}

// Load configuration
require_once __DIR__ . '/../../../includes/autoload.php';

// Get school information
$school = null;
try {
    $platformDb = Database::getPlatformConnection();
    $stmt = $platformDb->prepare("
        SELECT s.*, p.name as plan_name 
        FROM schools s 
        LEFT JOIN plans p ON s.plan_id = p.id 
        WHERE s.slug = ? AND s.status IN ('active', 'trial')
    ");
    $stmt->execute([$schoolSlug]);
    $school = $stmt->fetch();
    
    if (!$school) {
        error_log("ERROR: School not found: " . $schoolSlug);
        header('Location: ../../login.php?error=School not found');
        exit;
    }
    
    // Set school context
    $_SESSION['current_school'] = $school;
    
} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage());
    header('Location: ../../login.php?error=System error');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? '';
    
    // Connect to school database
    try {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        // Authenticate user
        $stmt = $schoolDb->prepare("
            SELECT u.*, ur.role_id, r.permissions 
            FROM users u 
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE (u.email = ? OR u.username = ?) 
            AND u.is_active = 1
            AND u.school_id = ?
        ");
        $stmt->execute([$username, $username, $school['id']]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Check user type
            if ($user['user_type'] !== $userType) {
                $error = 'Invalid user type selected';
            } else {
                // Set session data
                $_SESSION['school_auth'] = [
                    'school_id' => $school['id'],
                    'school_slug' => $school['slug'],
                    'school_name' => $school['name'],
                    'database_name' => $school['database_name'],
                    'user_id' => $user['id'],
                    'user_name' => $user['name'],
                    'user_email' => $user['email'],
                    'user_type' => $user['user_type'],
                    'permissions' => json_decode($user['permissions'] ?? '[]', true),
                    'login_time' => time()
                ];
                
                // Update last login
                $updateStmt = $schoolDb->prepare("
                    UPDATE users 
                    SET last_login_at = NOW(), last_login_ip = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
                
                // Redirect to dashboard
                $redirectUrl = "../{$school['slug']}/{$user['user_type']}/school-dashboard.php";
                error_log("Login successful, redirecting to: " . $redirectUrl);
                header("Location: " . $redirectUrl);
                exit;
            }
        } else {
            $error = 'Invalid username or password';
        }
    } catch (Exception $e) {
        $error = 'Login failed. Please try again.';
        error_log("Login error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($school['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full p-6">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-gray-800">
                <?php echo htmlspecialchars($school['name']); ?>
            </h1>
            <p class="text-gray-600">School Portal Login</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-white rounded-xl shadow-lg p-8">
            <form method="POST" action="">
                <input type="hidden" name="school_slug" value="<?php echo htmlspecialchars($schoolSlug); ?>">
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">I am a:</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50">
                            <input type="radio" name="user_type" value="admin" class="mr-3" required>
                            <i class="fas fa-user-shield text-blue-600 mr-2"></i>
                            <span class="font-medium">Admin</span>
                        </label>
                        <label class="flex items-center p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-green-50">
                            <input type="radio" name="user_type" value="teacher" class="mr-3" required>
                            <i class="fas fa-chalkboard-teacher text-green-600 mr-2"></i>
                            <span class="font-medium">Teacher</span>
                        </label>
                        <label class="flex items-center p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-purple-50">
                            <input type="radio" name="user_type" value="student" class="mr-3" required>
                            <i class="fas fa-graduation-cap text-purple-600 mr-2"></i>
                            <span class="font-medium">Student</span>
                        </label>
                        <label class="flex items-center p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-yellow-50">
                            <input type="radio" name="user_type" value="parent" class="mr-3" required>
                            <i class="fas fa-user-friends text-yellow-600 mr-2"></i>
                            <span class="font-medium">Parent</span>
                        </label>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Email or Username</label>
                    <input type="text" name="username" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                           placeholder="Enter your email or username">
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
                    <input type="password" name="password" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                           placeholder="Enter your password">
                </div>
                
                <button type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition">
                    <i class="fas fa-sign-in-alt mr-2"></i> Login to Portal
                </button>
                
                <div class="text-center mt-4">
                    <a href="/tenant/login.php" class="text-sm text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-1"></i> Back to School Selection
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>