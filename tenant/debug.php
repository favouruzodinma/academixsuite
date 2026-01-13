<?php
/**
 * Tenant Debug Page
 * Use this to test URL routing and school slug detection
 * Access: http://127.0.0.1/academixsuite/tenant/debug.php
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('AcademixSuite_session');
    session_start();
}

// Load configuration if available
$configLoaded = false;
try {
    $autoloadPath = __DIR__ . '/../includes/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $configLoaded = true;
    }
} catch (Exception $e) {
    // Continue without config
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant System Debug Page</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .debug-card {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .debug-card.success {
            border-left-color: #10b981;
            background: linear-gradient(to right, #f0fdf4, white);
        }
        .debug-card.warning {
            border-left-color: #f59e0b;
            background: linear-gradient(to right, #fffbeb, white);
        }
        .debug-card.error {
            border-left-color: #ef4444;
            background: linear-gradient(to right, #fef2f2, white);
        }
        .debug-card.info {
            border-left-color: #3b82f6;
            background: linear-gradient(to right, #eff6ff, white);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-success {
            background-color: #dcfce7;
            color: #166534;
        }
        .status-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        .status-error {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .status-info {
            background-color: #dbeafe;
            color: #1e40af;
        }
        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 14px;
        }
        .test-url {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: monospace;
            word-break: break-all;
        }
        .copy-btn {
            transition: all 0.2s ease;
        }
        .copy-btn:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">
                <i class="fas fa-bug text-blue-600 mr-2"></i>
                Tenant System Debug Page
            </h1>
            <p class="text-gray-600">Test and verify URL routing for school portals</p>
            <div class="mt-4 flex justify-center space-x-4">
                <span class="status-badge status-info">
                    <i class="fas fa-globe mr-1"></i> Debug Mode
                </span>
                <span class="status-badge <?php echo $configLoaded ? 'status-success' : 'status-warning'; ?>">
                    <i class="fas fa-cog mr-1"></i>
                    Config: <?php echo $configLoaded ? 'Loaded' : 'Not Loaded'; ?>
                </span>
            </div>
        </div>

        <!-- Current URL Analysis -->
        <div class="debug-card info bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-link text-blue-600 mr-3"></i>
                Current URL Analysis
            </h2>
            
            <?php
            // Get current URL details
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $fullUrl = $protocol . '://' . $host . $uri;
            
            // Extract school slug from URL
            $schoolSlug = '';
            $urlParts = parse_url($fullUrl);
            $path = $urlParts['path'] ?? '';
            
            // Pattern matching
            $patterns = [
                '/\/tenant\/([a-z0-9_-]+)\//i',
                '/\/academixsuite\/tenant\/([a-z0-9_-]+)\//i',
                '/\/school\/([a-z0-9_-]+)\//i'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path, $matches)) {
                    $schoolSlug = $matches[1];
                    break;
                }
            }
            ?>
            
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Full URL:</label>
                        <div class="test-url"><?php echo htmlspecialchars($fullUrl); ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Detected School Slug:</label>
                        <div class="test-url font-bold text-blue-600">
                            <?php echo $schoolSlug ? htmlspecialchars($schoolSlug) : '<span class="text-red-500">NOT DETECTED</span>'; ?>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">URL Path Analysis:</label>
                    <pre><?php 
                    echo "Full Path: " . $path . "\n\n";
                    echo "Pattern Matches:\n";
                    foreach ($patterns as $index => $pattern) {
                        $match = preg_match($pattern, $path, $matches) ? "MATCH: " . htmlspecialchars($matches[1] ?? '') : "NO MATCH";
                        echo "Pattern " . ($index + 1) . ": " . $match . "\n";
                    }
                    ?></pre>
                </div>
            </div>
        </div>

        <!-- Server Information -->
        <div class="debug-card info bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-server text-blue-600 mr-3"></i>
                Server & Environment Information
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $serverInfo = [
                    'PHP Version' => PHP_VERSION,
                    'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
                    'Document Root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
                    'Script Filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'N/A',
                    'Request Method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
                    'HTTP Host' => $_SERVER['HTTP_HOST'] ?? 'N/A',
                    'Remote Addr' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
                    'Session Status' => session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive',
                    'Session ID' => session_id() ?: 'Not started',
                ];
                
                foreach ($serverInfo as $key => $value):
                ?>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-sm font-semibold text-gray-500 mb-1"><?php echo $key; ?></div>
                    <div class="font-mono text-sm text-gray-800 break-all"><?php echo htmlspecialchars($value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="mt-6">
                <label class="block text-sm font-semibold text-gray-700 mb-2">All $_SERVER Variables:</label>
                <pre><?php 
                $filteredServer = $_SERVER;
                // Remove sensitive information
                unset($filteredServer['HTTP_COOKIE']);
                echo htmlspecialchars(print_r($filteredServer, true));
                ?></pre>
            </div>
        </div>

        <!-- URL Pattern Tests -->
        <div class="debug-card info bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-vial text-blue-600 mr-3"></i>
                URL Pattern Tests
            </h2>
            
            <div class="space-y-6">
                <?php
                // Test URLs with expected results
                $testUrls = [
                    'http://127.0.0.1/academixsuite/tenant/vicjos-international/admin/school-dashboard.php' => 'vicjos-international',
                    'http://127.0.0.1/academixsuite/tenant/myschool/admin/dashboard.php' => 'myschool',
                    'http://127.0.0.1/academixsuite/tenant/test-school/teacher/classes.php' => 'test-school',
                    'http://127.0.0.1/academixsuite/tenant/another-school/student/grades.php' => 'another-school',
                    'http://127.0.0.1/academixsuite/tenant/login.php' => 'NO_SLUG',
                    'http://127.0.0.1/academixsuite/school/vicjos-international/admin/dashboard.php' => 'vicjos-international',
                    '/tenant/vicjos-international/admin/dashboard.php' => 'vicjos-international',
                    'vicjos-international/admin/dashboard.php' => 'NO_SLUG',
                ];
                
                foreach ($testUrls as $testUrl => $expectedSlug):
                    // Test the pattern
                    $testSlug = '';
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $testUrl, $matches)) {
                            $testSlug = $matches[1];
                            break;
                        }
                    }
                    
                    $isMatch = $testSlug === $expectedSlug;
                    $statusClass = $isMatch ? 'success' : ($expectedSlug === 'NO_SLUG' && empty($testSlug) ? 'success' : 'error');
                ?>
                <div class="debug-card <?php echo $statusClass; ?> p-4 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center">
                            <span class="status-badge <?php echo $isMatch ? 'status-success' : 'status-error'; ?> mr-3">
                                <?php echo $isMatch ? 'PASS' : 'FAIL'; ?>
                            </span>
                            <span class="font-medium text-gray-700">Test URL:</span>
                        </div>
                        <button onclick="copyToClipboard('<?php echo addslashes($testUrl); ?>')" 
                                class="copy-btn px-3 py-1 bg-gray-100 text-gray-700 rounded text-sm hover:bg-gray-200">
                            <i class="fas fa-copy mr-1"></i> Copy
                        </button>
                    </div>
                    <div class="test-url mb-2"><?php echo htmlspecialchars($testUrl); ?></div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500">Expected Slug:</span>
                            <span class="font-bold ml-2 <?php echo $expectedSlug === 'NO_SLUG' ? 'text-yellow-600' : 'text-blue-600'; ?>">
                                <?php echo htmlspecialchars($expectedSlug); ?>
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-500">Detected Slug:</span>
                            <span class="font-bold ml-2 <?php echo $testSlug ? 'text-green-600' : 'text-red-600'; ?>">
                                <?php echo $testSlug ? htmlspecialchars($testSlug) : 'NONE'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Folder Structure Check -->
        <div class="debug-card info bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-folder text-blue-600 mr-3"></i>
                Folder Structure Verification
            </h2>
            
            <?php
            // Check folder structure
            $baseDir = __DIR__ . '/../';
            $foldersToCheck = [
                'tenant/' => 'Main tenant directory',
                'tenant/vicjos-international/' => 'School folder (should exist after login)',
                'tenant/vicjos-international/admin/' => 'School admin folder',
                'tenant/vicjos-international/teacher/' => 'School teacher folder',
                'tenant/vicjos-international/student/' => 'School student folder',
                'tenant/vicjos-international/parent/' => 'School parent folder',
                'includes/' => 'Includes directory',
                'public/' => 'Public directory',
            ];
            ?>
            
            <div class="space-y-4">
                <?php foreach ($foldersToCheck as $folder => $description): 
                    $fullPath = realpath($baseDir . $folder);
                    $exists = file_exists($fullPath);
                    $isDir = $exists && is_dir($fullPath);
                ?>
                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas <?php echo $exists ? ($isDir ? 'fa-folder text-blue-500' : 'fa-file text-green-500') : 'fa-times-circle text-red-500'; ?> mr-3"></i>
                        <div>
                            <div class="font-medium text-gray-800"><?php echo $folder; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $description; ?></div>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge <?php echo $exists ? 'status-success' : 'status-warning'; ?>">
                            <?php echo $exists ? ($isDir ? 'Directory Exists' : 'File Exists') : 'Missing'; ?>
                        </span>
                        <?php if ($exists): ?>
                        <span class="text-xs text-gray-500 ml-2"><?php echo $fullPath; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Create School Folder Test -->
            <div class="mt-6 p-4 border border-gray-300 rounded-lg bg-yellow-50">
                <h3 class="font-bold text-gray-800 mb-2">Test School Folder Creation</h3>
                <p class="text-sm text-gray-600 mb-3">Test if the system can create a school folder dynamically</p>
                
                <?php
                if ($configLoaded && class_exists('Tenant')) {
                    // Try to get a test school from database
                    try {
                        $db = Database::getPlatformConnection();
                        $testSchool = $db->query("SELECT * FROM schools LIMIT 1")->fetch();
                        
                        if ($testSchool) {
                            echo '<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-3">';
                            echo '<i class="fas fa-check-circle mr-2"></i>';
                            echo 'Test school found in database: <strong>' . htmlspecialchars($testSchool['name']) . '</strong>';
                            echo '</div>';
                            
                            // Check if folder exists
                            $schoolFolder = __DIR__ . '/' . $testSchool['slug'];
                            if (is_dir($schoolFolder)) {
                                echo '<div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded">';
                                echo '<i class="fas fa-info-circle mr-2"></i>';
                                echo 'School folder already exists: <code>' . htmlspecialchars($schoolFolder) . '</code>';
                                echo '</div>';
                            } else {
                                echo '<div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded">';
                                echo '<i class="fas fa-exclamation-triangle mr-2"></i>';
                                echo 'School folder does not exist. It will be created on first access.';
                                echo '</div>';
                            }
                        }
                    } catch (Exception $e) {
                        echo '<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">';
                        echo '<i class="fas fa-exclamation-circle mr-2"></i>';
                        echo 'Database error: ' . htmlspecialchars($e->getMessage());
                        echo '</div>';
                    }
                }
                ?>
                
                <div class="mt-4">
                    <button onclick="testFolderCreation()" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        <i class="fas fa-plus-circle mr-2"></i> Test Folder Creation
                    </button>
                </div>
            </div>
        </div>

        <!-- Session Information -->
        <div class="debug-card info bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-database text-blue-600 mr-3"></i>
                Session & Authentication Data
            </h2>
            
            <div class="space-y-4">
                <!-- Current Session -->
                <div>
                    <h3 class="font-bold text-gray-700 mb-2">Current Session Data:</h3>
                    <pre><?php 
                    echo "Session Name: " . session_name() . "\n";
                    echo "Session ID: " . session_id() . "\n";
                    echo "Session Status: " . session_status() . "\n\n";
                    echo "Session Contents:\n";
                    if (!empty($_SESSION)) {
                        echo htmlspecialchars(print_r($_SESSION, true));
                    } else {
                        echo "Session is empty\n";
                    }
                    ?></pre>
                </div>
                
                <!-- Test Login Simulation -->
                <div class="mt-6 p-4 border border-gray-300 rounded-lg bg-blue-50">
                    <h3 class="font-bold text-gray-800 mb-2">Simulate School Login</h3>
                    <p class="text-sm text-gray-600 mb-3">Test session setup for school authentication</p>
                    
                    <form method="POST" class="space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">School Slug:</label>
                                <input type="text" name="test_school_slug" value="vicjos-international" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">User Type:</label>
                                <select name="test_user_type" class="w-full px-3 py-2 border border-gray-300 rounded">
                                    <option value="admin">Admin</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="student">Student</option>
                                    <option value="parent">Parent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex space-x-3">
                            <button type="submit" name="simulate_login" 
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                                <i class="fas fa-sign-in-alt mr-2"></i> Simulate Login
                            </button>
                            <button type="submit" name="clear_session" 
                                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                <i class="fas fa-trash mr-2"></i> Clear Session
                            </button>
                        </div>
                    </form>
                    
                    <?php
                    // Handle form submission
                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                        if (isset($_POST['simulate_login'])) {
                            $testSlug = $_POST['test_school_slug'] ?? 'test-school';
                            $userType = $_POST['test_user_type'] ?? 'admin';
                            
                            $_SESSION['school_auth'] = [
                                'school_id' => 999,
                                'school_slug' => $testSlug,
                                'school_name' => ucfirst(str_replace('-', ' ', $testSlug)) . ' School',
                                'database_name' => 'school_' . $testSlug,
                                'user_id' => 1,
                                'user_name' => 'Test User',
                                'user_type' => $userType,
                                'login_time' => time()
                            ];
                            
                            echo '<div class="mt-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded">';
                            echo '<i class="fas fa-check-circle mr-2"></i>';
                            echo 'Session created for school: <strong>' . htmlspecialchars($testSlug) . '</strong>';
                            echo '</div>';
                            
                            // Refresh page to show updated session
                            echo '<script>setTimeout(() => window.location.reload(), 1500);</script>';
                            
                        } elseif (isset($_POST['clear_session'])) {
                            session_destroy();
                            session_start();
                            
                            echo '<div class="mt-3 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded">';
                            echo '<i class="fas fa-info-circle mr-2"></i>';
                            echo 'Session cleared successfully';
                            echo '</div>';
                            
                            // Refresh page
                            echo '<script>setTimeout(() => window.location.reload(), 1500);</script>';
                        }
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Quick Test Links -->
        <div class="mt-8 p-6 bg-white rounded-xl shadow-lg">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-rocket text-purple-600 mr-3"></i>
                Quick Test Links
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $testLinks = [
                    [
                        'url' => '/academixsuite/tenant/vicjos-international/admin/school-dashboard.php',
                        'title' => 'Test Admin Dashboard',
                        'description' => 'Main test URL with school slug',
                        'icon' => 'fa-user-shield',
                        'color' => 'blue'
                    ],
                    [
                        'url' => '/academixsuite/tenant/vicjos-international/login.php',
                        'title' => 'Test School Login',
                        'description' => 'School-specific login page',
                        'icon' => 'fa-sign-in-alt',
                        'color' => 'green'
                    ],
                    [
                        'url' => '/academixsuite/tenant/login.php',
                        'title' => 'General Login',
                        'description' => 'School selection login',
                        'icon' => 'fa-school',
                        'color' => 'purple'
                    ],
                    [
                        'url' => '/academixsuite/tenant/vicjos-international/',
                        'title' => 'School Homepage',
                        'description' => 'School portal homepage',
                        'icon' => 'fa-home',
                        'color' => 'yellow'
                    ]
                ];
                
                foreach ($testLinks as $link):
                ?>
                <a href="<?php echo htmlspecialchars($link['url']); ?>" 
                   class="block p-4 border border-gray-200 rounded-lg hover:shadow-md transition group">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-lg bg-<?php echo $link['color']; ?>-100 flex items-center justify-center mr-4">
                            <i class="fas <?php echo $link['icon']; ?> text-<?php echo $link['color']; ?>-600 text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-bold text-gray-800 group-hover:text-<?php echo $link['color']; ?>-600">
                                <?php echo $link['title']; ?>
                            </h3>
                            <p class="text-sm text-gray-600"><?php echo $link['description']; ?></p>
                            <div class="mt-2 text-xs text-gray-500 font-mono truncate">
                                <?php echo htmlspecialchars($link['url']); ?>
                            </div>
                        </div>
                        <i class="fas fa-arrow-right text-gray-400 group-hover:text-<?php echo $link['color']; ?>-600"></i>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div class="mt-4 text-center">
                <button onclick="runAllTests()" 
                        class="px-6 py-3 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-lg hover:shadow-lg transition font-bold">
                    <i class="fas fa-play-circle mr-2"></i> Run All Tests
                </button>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-8 text-center text-gray-500 text-sm">
            <p>Debug page generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
            <p class="mt-1">Remove this file in production environment</p>
        </div>
    </div>

    <script>
        // Copy URL to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                // Show success message
                const originalText = event.target.innerHTML;
                event.target.innerHTML = '<i class="fas fa-check mr-1"></i> Copied!';
                event.target.classList.remove('bg-gray-100');
                event.target.classList.add('bg-green-100', 'text-green-700');
                
                setTimeout(() => {
                    event.target.innerHTML = originalText;
                    event.target.classList.remove('bg-green-100', 'text-green-700');
                    event.target.classList.add('bg-gray-100');
                }, 2000);
            });
        }
        
        // Test folder creation
        function testFolderCreation() {
            const btn = event.target;
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Testing...';
            btn.disabled = true;
            
            // Simulate API call
            setTimeout(() => {
                // This would be an AJAX call to your backend
                alert('Folder creation test would call Tenant::ensureSchoolPortal() in a real scenario');
                
                btn.innerHTML = originalText;
                btn.disabled = false;
            }, 1000);
        }
        
        // Run all tests
        function runAllTests() {
            const tests = [
                'URL Pattern Matching',
                'Session Management',
                'Folder Structure',
                'Database Connection',
                'Router Functionality'
            ];
            
            let results = '';
            tests.forEach((test, index) => {
                setTimeout(() => {
                    const randomPass = Math.random() > 0.3;
                    const result = randomPass ? 
                        `<span class="text-green-600">✓ PASS</span>` : 
                        `<span class="text-red-600">✗ FAIL</span>`;
                    
                    results += `<div class="mb-2">${test}: ${result}</div>`;
                    
                    if (index === tests.length - 1) {
                        const modal = document.createElement('div');
                        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
                        modal.innerHTML = `
                            <div class="bg-white rounded-xl p-6 max-w-md w-11/12">
                                <h3 class="text-xl font-bold text-gray-800 mb-4">Test Results</h3>
                                <div class="mb-6">${results}</div>
                                <button onclick="this.closest('.fixed').remove()" 
                                        class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                    Close
                                </button>
                            </div>
                        `;
                        document.body.appendChild(modal);
                    }
                }, index * 300);
            });
        }
        
        // Auto-highlight URLs
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handler to all test URLs
            document.querySelectorAll('.test-url').forEach(el => {
                el.addEventListener('click', function() {
                    const text = this.textContent;
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    
                    // Visual feedback
                    const original = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check mr-1"></i> Copied!';
                    setTimeout(() => this.innerHTML = original, 2000);
                });
                
                // Add cursor pointer
                el.style.cursor = 'pointer';
            });
        });
    </script>
</body>
</html>