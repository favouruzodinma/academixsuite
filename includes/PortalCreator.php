<?php
// /includes/PortalCreator.php

class PortalCreator {
    public static function createSchoolPortal($schoolSlug) {
        $basePath = __DIR__ . '/../tenant/' . $schoolSlug;
        
        // Create base directory
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        
        // Define directory structure
        $directories = [
            'admin',
            'teacher', 
            'student',
            'parent',
            'assets/css',
            'assets/js',
            'assets/images',
            'includes'
        ];
        
        // Create directories
        foreach ($directories as $dir) {
            $fullPath = $basePath . '/' . $dir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }
        
        // Create portal entry files
        self::createPortalFiles($basePath, $schoolSlug);
        
        return true;
    }
    
    private static function createPortalFiles($basePath, $schoolSlug) {
        // Create index.php (school homepage)
        $indexContent = <<<PHP
<?php
/**
 * School Portal Homepage - $schoolSlug
 */
session_start();
require_once __DIR__ . '/../../includes/autoload.php';

// Get school info from database
\$db = Database::getPlatformConnection();
\$stmt = \$db->prepare("SELECT * FROM schools WHERE slug = ?");
\$stmt->execute(["$schoolSlug"]);
\$school = \$stmt->fetch();

if (!\$school) {
    header("Location: /tenant/login.php");
    exit;
}

// Set school context
\$_SESSION['current_school'] = [
    'id' => \$school['id'],
    'slug' => \$school['slug'],
    'name' => \$school['name']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(\$school['name']); ?> Portal</title>
</head>
<body>
    <h1>Welcome to <?php echo htmlspecialchars(\$school['name']); ?></h1>
    <p>School ID: <?php echo \$school['id']; ?></p>
    <p>Slug: <?php echo \$school['slug']; ?></p>
    
    <div>
        <h2>Access Portals:</h2>
        <ul>
            <li><a href="/tenant/$schoolSlug/admin/dashboard.php">Admin Portal</a></li>
            <li><a href="/tenant/$schoolSlug/teacher/dashboard.php">Teacher Portal</a></li>
            <li><a href="/tenant/$schoolSlug/student/dashboard.php">Student Portal</a></li>
            <li><a href="/tenant/$schoolSlug/parent/dashboard.php">Parent Portal</a></li>
        </ul>
    </div>
    
    <div>
        <a href="/tenant/$schoolSlug/login.php">Login to Portal</a>
    </div>
</body>
</html>
PHP;
        file_put_contents($basePath . '/index.php', $indexContent);
        
        // Create login.php for this school
        $loginContent = <<<PHP
<?php
/**
 * School-specific Login Page - $schoolSlug
 */
session_start();
require_once __DIR__ . '/../../includes/autoload.php';

// Check if school exists
\$db = Database::getPlatformConnection();
\$stmt = \$db->prepare("SELECT * FROM schools WHERE slug = ? AND status IN ('active', 'trial')");
\$stmt->execute(["$schoolSlug"]);
\$school = \$stmt->fetch();

if (!\$school) {
    header("Location: /tenant/login.php?error=School not found");
    exit;
}

// Handle login form submission
if (\$_SERVER['REQUEST_METHOD'] === 'POST') {
    \$username = \$_POST['username'] ?? '';
    \$password = \$_POST['password'] ?? '';
    \$userType = \$_POST['user_type'] ?? '';
    
    // Connect to school database
    \$schoolDb = Database::getSchoolConnection(\$school['database_name']);
    
    // Authenticate user (simplified example)
    \$stmt = \$schoolDb->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND user_type = ?");
    \$stmt->execute([\$username, \$username, \$userType]);
    \$user = \$stmt->fetch();
    
    if (\$user && password_verify(\$password, \$user['password'])) {
        // Set session data
        \$_SESSION['school_auth'] = [
            'school_id' => \$school['id'],
            'school_slug' => \$school['slug'],
            'school_name' => \$school['name'],
            'user_id' => \$user['id'],
            'user_type' => \$user['user_type'],
            'user_name' => \$user['name']
        ];
        
        // Redirect to appropriate dashboard
        header("Location: /tenant/$schoolSlug/\$userType/dashboard.php");
        exit;
    } else {
        \$error = "Invalid credentials";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - <?php echo htmlspecialchars(\$school['name']); ?></title>
</head>
<body>
    <h1>Login to <?php echo htmlspecialchars(\$school['name']); ?></h1>
    
    <?php if (isset(\$error)): ?>
        <p style="color: red;"><?php echo \$error; ?></p>
    <?php endif; ?>
    
    <form method="POST">
        <div>
            <label>Username/Email:</label>
            <input type="text" name="username" required>
        </div>
        
        <div>
            <label>Password:</label>
            <input type="password" name="password" required>
        </div>
        
        <div>
            <label>Login as:</label>
            <select name="user_type" required>
                <option value="">Select</option>
                <option value="admin">Admin</option>
                <option value="teacher">Teacher</option>
                <option value="student">Student</option>
                <option value="parent">Parent</option>
            </select>
        </div>
        
        <button type="submit">Login</button>
    </form>
    
    <p><a href="/tenant/login.php">Back to School Selection</a></p>
</body>
</html>
PHP;
        file_put_contents($basePath . '/login.php', $loginContent);
        
        // Create dashboard templates for each user type
        $userTypes = ['admin', 'teacher', 'student', 'parent'];
        foreach ($userTypes as $type) {
            $dashboardContent = <<<PHP
<?php
/**
 * $type Dashboard - $schoolSlug
 */
session_start();

// Authentication check
if (!isset(\$_SESSION['school_auth']) || \$_SESSION['school_auth']['school_slug'] !== '$schoolSlug') {
    header("Location: /tenant/$schoolSlug/login.php");
    exit;
}

if (\$_SESSION['school_auth']['user_type'] !== '$type') {
    header("Location: /tenant/$schoolSlug/" . \$_SESSION['school_auth']['user_type'] . "/dashboard.php");
    exit;
}

\$schoolName = \$_SESSION['school_auth']['school_name'];
\$userName = \$_SESSION['school_auth']['user_name'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo ucfirst(\$type); ?> Dashboard - <?php echo \$schoolName; ?></title>
</head>
<body>
    <h1>Welcome, <?php echo \$userName; ?>!</h1>
    <h2><?php echo ucfirst(\$type); ?> Dashboard - <?php echo \$schoolName; ?></h2>
    
    <div>
        <p>School Slug: <?php echo '$schoolSlug'; ?></p>
        <p>User Type: <?php echo \$type; ?></p>
    </div>
    
    <nav>
        <ul>
            <li><a href="/tenant/$schoolSlug/$type/dashboard.php">Dashboard</a></li>
            <?php if (\$type === 'admin'): ?>
                <li><a href="/tenant/$schoolSlug/admin/students.php">Students</a></li>
                <li><a href="/tenant/$schoolSlug/admin/teachers.php">Teachers</a></li>
                <li><a href="/tenant/$schoolSlug/admin/settings.php">Settings</a></li>
            <?php elseif (\$type === 'teacher'): ?>
                <li><a href="/tenant/$schoolSlug/teacher/my-classes.php">My Classes</a></li>
                <li><a href="/tenant/$schoolSlug/teacher/attendance.php">Attendance</a></li>
            <?php elseif (\$type === 'student'): ?>
                <li><a href="/tenant/$schoolSlug/student/timetable.php">Timetable</a></li>
                <li><a href="/tenant/$schoolSlug/student/grades.php">Grades</a></li>
            <?php elseif (\$type === 'parent'): ?>
                <li><a href="/tenant/$schoolSlug/parent/children.php">My Children</a></li>
                <li><a href="/tenant/$schoolSlug/parent/fees.php">Fee Status</a></li>
            <?php endif; ?>
            <li><a href="/tenant/$schoolSlug/login.php?logout=1">Logout</a></li>
        </ul>
    </nav>
</body>
</html>
PHP;
            file_put_contents($basePath . '/' . $type . '/dashboard.php', $dashboardContent);
            
            // Create sample pages for each user type
            $pages = self::getSamplePages($type);
            foreach ($pages as $page => $title) {
                $pageContent = <<<PHP
<?php
/**
 * $title - $schoolSlug
 */
session_start();

// Authentication check
if (!isset(\$_SESSION['school_auth']) || \$_SESSION['school_auth']['school_slug'] !== '$schoolSlug') {
    header("Location: /tenant/$schoolSlug/login.php");
    exit;
}

if (\$_SESSION['school_auth']['user_type'] !== '$type') {
    header("Location: /tenant/$schoolSlug/" . \$_SESSION['school_auth']['user_type'] . "/dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>$title - <?php echo \$_SESSION['school_auth']['school_name']; ?></title>
</head>
<body>
    <h1>$title</h1>
    <p>School: <?php echo \$_SESSION['school_auth']['school_name']; ?></p>
    <p>User: <?php echo \$_SESSION['school_auth']['user_name']; ?></p>
    
    <nav>
        <a href="/tenant/$schoolSlug/$type/dashboard.php">Back to Dashboard</a>
    </nav>
    
    <div>
        <p>This is the $title page for $type.</p>
        <p>Path: /tenant/$schoolSlug/$type/$page.php</p>
    </div>
</body>
</html>
PHP;
                file_put_contents($basePath . '/' . $type . '/' . $page . '.php', $pageContent);
            }
        }
    }
    
    private static function getSamplePages($userType) {
        $pages = [
            'admin' => [
                'students' => 'Manage Students',
                'teachers' => 'Manage Teachers',
                'classes' => 'Manage Classes',
                'attendance' => 'Attendance Reports',
                'fees' => 'Fee Management',
                'settings' => 'School Settings'
            ],
            'teacher' => [
                'my-classes' => 'My Classes',
                'attendance' => 'Take Attendance',
                'grades' => 'Enter Grades',
                'assignments' => 'Assignments',
                'messages' => 'Messages'
            ],
            'student' => [
                'timetable' => 'Class Timetable',
                'assignments' => 'My Assignments',
                'grades' => 'My Grades',
                'profile' => 'My Profile'
            ],
            'parent' => [
                'children' => 'My Children',
                'attendance' => 'Attendance View',
                'fees' => 'Fee Payments',
                'messages' => 'Messages'
            ]
        ];
        
        return $pages[$userType] ?? [];
    }
}
?>