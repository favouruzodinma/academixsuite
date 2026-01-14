<?php
/**
 * School Announcements Management
 * Real-time database integration version
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is authenticated
if (!isset($_SESSION['school_auth'])) {
    header("Location: ../login.php");
    exit;
}

// Get school information from session
$schoolId = $_SESSION['school_auth']['school_id'];
$schoolSlug = $_SESSION['school_auth']['school_slug'];
$schoolName = $_SESSION['school_auth']['school_name'];
$databaseName = $_SESSION['school_auth']['database_name'];
$userId = $_SESSION['school_auth']['user_id'];
$userName = $_SESSION['school_auth']['user_name'];
$userType = $_SESSION['school_auth']['user_type'];

// Only allow admin access
if ($userType !== 'admin') {
    header("Location: ../{$userType}/dashboard.php");
    exit;
}

// Load database configuration
require_once __DIR__ . '/../../../includes/autoload.php';

// Initialize variables
$error = '';
$success = '';
$announcements = [];
$announcementStats = [
    'total' => 0,
    'published' => 0,
    'scheduled' => 0,
    'drafts' => 0,
    'archived' => 0,
    'high_priority' => 0,
    'medium_priority' => 0,
    'low_priority' => 0
];
$pagination = [
    'current_page' => 1,
    'total_pages' => 1,
    'total_items' => 0,
    'per_page' => 10
];

try {
    // Get school database connection
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handleAnnouncementSubmission($schoolDb, $schoolId, $userId);
    }
    
    // Handle actions
    if (isset($_GET['action'])) {
        handleAnnouncementAction($schoolDb, $schoolId, $userId);
    }
    
    // Apply filters
    $filters = getFilters();
    
    // Get announcements data
    $announcementsData = getAnnouncements($schoolDb, $schoolId, $filters);
    $announcements = $announcementsData['data'];
    $pagination = [
        'current_page' => $announcementsData['page'],
        'total_pages' => $announcementsData['total_pages'],
        'total_items' => $announcementsData['total'],
        'per_page' => $announcementsData['per_page']
    ];
    
    // Get announcement statistics
    $announcementStats = getAnnouncementStats($schoolDb, $schoolId);
    
} catch (Exception $e) {
    $error = "Database connection error: " . $e->getMessage();
}

// Get announcement categories
$categories = [
    'general' => 'General Announcement',
    'event' => 'School Event',
    'academic' => 'Academic Update',
    'safety' => 'Safety Alert',
    'holiday' => 'Holiday Notice',
    'maintenance' => 'System Maintenance'
];

// Get target audiences
$audiences = [
    'all' => 'All School',
    'students' => 'Students',
    'teachers' => 'Teachers',
    'parents' => 'Parents',
    'staff' => 'Staff'
];

// Get priorities
$priorities = [
    'high' => 'High Priority',
    'medium' => 'Medium Priority',
    'low' => 'Low Priority'
];

// Check if viewing/editing specific announcement
$viewingAnnouncement = null;
$editingAnnouncement = null;

if (isset($_GET['view'])) {
    $viewingAnnouncement = getAnnouncementById($schoolDb, $schoolId, (int)$_GET['view']);
}

if (isset($_GET['edit'])) {
    $editingAnnouncement = getAnnouncementById($schoolDb, $schoolId, (int)$_GET['edit']);
}

// Get classes for filtering
$classes = getClasses($schoolDb, $schoolId);

// Get current tab
$currentTab = isset($_GET['status']) ? $_GET['status'] : 'all';

/**
 * Get filters from URL
 */
function getFilters() {
    $filters = [];
    
    if (isset($_GET['status']) && in_array($_GET['status'], ['published', 'drafts', 'scheduled', 'archived'])) {
        $filters['status'] = $_GET['status'];
    }
    
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $filters['search'] = trim($_GET['search']);
    }
    
    if (isset($_GET['priority']) && in_array($_GET['priority'], ['high', 'medium', 'low'])) {
        $filters['priority'] = $_GET['priority'];
    }
    
    if (isset($_GET['audience']) && in_array($_GET['audience'], ['all', 'students', 'teachers', 'parents', 'staff'])) {
        $filters['target'] = $_GET['audience'];
    }
    
    if (isset($_GET['category']) && in_array($_GET['category'], array_keys([
        'general', 'event', 'academic', 'safety', 'holiday', 'maintenance'
    ]))) {
        $filters['category'] = $_GET['category'];
    }
    
    return $filters;
}

/**
 * Get announcement by ID
 */
function getAnnouncementById($db, $schoolId, $id) {
    try {
        $stmt = $db->prepare("
            SELECT a.*, u.name as author_name 
            FROM announcements a 
            LEFT JOIN users u ON a.created_by = u.id 
            WHERE a.id = ? AND a.school_id = ?
        ");
        $stmt->execute([$id, $schoolId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get announcements from database
 */
function getAnnouncements($db, $schoolId, $filters = []) {
    $where = "a.school_id = :school_id";
    $params = [':school_id' => $schoolId];
    
    // Apply filters
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'published') {
            $where .= " AND a.is_published = 1";
        } elseif ($filters['status'] === 'drafts') {
            $where .= " AND a.is_published = 0";
        } elseif ($filters['status'] === 'scheduled') {
            $where .= " AND a.start_date > NOW()";
        } elseif ($filters['status'] === 'archived') {
            $where .= " AND a.end_date < NOW() AND a.end_date IS NOT NULL";
        }
    }
    
    if (!empty($filters['search'])) {
        $where .= " AND (a.title LIKE :search OR a.description LIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }
    
    if (!empty($filters['priority'])) {
        $where .= " AND a.priority = :priority";
        $params[':priority'] = $filters['priority'];
    }
    
    if (!empty($filters['target'])) {
        $where .= " AND a.target = :target";
        $params[':target'] = $filters['target'];
    }
    
    if (!empty($filters['category'])) {
        $where .= " AND a.category = :category";
        $params[':category'] = $filters['category'];
    }
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM announcements a WHERE $where";
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch()['total'];
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    $totalPages = ceil($totalCount / $perPage);
    
    // Get announcements
    $sql = "SELECT a.*, u.name as author_name 
            FROM announcements a 
            LEFT JOIN users u ON a.created_by = u.id 
            WHERE $where 
            ORDER BY 
                CASE 
                    WHEN a.priority = 'high' THEN 1
                    WHEN a.priority = 'medium' THEN 2
                    WHEN a.priority = 'low' THEN 3
                    ELSE 4
                END,
                a.created_at DESC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'data' => $announcements,
        'total' => $totalCount,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages
    ];
}

/**
 * Get announcement statistics
 */
function getAnnouncementStats($db, $schoolId) {
    $stats = [];
    
    try {
        // Total announcements
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM announcements WHERE school_id = ?");
        $stmt->execute([$schoolId]);
        $stats['total'] = $stmt->fetch()['total'];
        
        // Published announcements
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM announcements WHERE school_id = ? AND is_published = 1");
        $stmt->execute([$schoolId]);
        $stats['published'] = $stmt->fetch()['count'];
        
        // Drafts
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM announcements WHERE school_id = ? AND is_published = 0");
        $stmt->execute([$schoolId]);
        $stats['drafts'] = $stmt->fetch()['count'];
        
        // Scheduled (future start date)
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM announcements WHERE school_id = ? AND start_date > NOW()");
        $stmt->execute([$schoolId]);
        $stats['scheduled'] = $stmt->fetch()['count'];
        
        // Archived (past end date)
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM announcements WHERE school_id = ? AND end_date < NOW() AND end_date IS NOT NULL");
        $stmt->execute([$schoolId]);
        $stats['archived'] = $stmt->fetch()['count'];
        
        // Priority counts
        $stmt = $db->prepare("SELECT priority, COUNT(*) as count FROM announcements WHERE school_id = ? GROUP BY priority");
        $stmt->execute([$schoolId]);
        $priorityStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats['high_priority'] = 0;
        $stats['medium_priority'] = 0;
        $stats['low_priority'] = 0;
        
        foreach ($priorityStats as $row) {
            if ($row['priority'] === 'high') {
                $stats['high_priority'] = $row['count'];
            } elseif ($row['priority'] === 'medium') {
                $stats['medium_priority'] = $row['count'];
            } elseif ($row['priority'] === 'low') {
                $stats['low_priority'] = $row['count'];
            }
        }
        
    } catch (Exception $e) {
        // Return empty stats on error
        $stats = [
            'total' => 0,
            'published' => 0,
            'scheduled' => 0,
            'drafts' => 0,
            'archived' => 0,
            'high_priority' => 0,
            'medium_priority' => 0,
            'low_priority' => 0
        ];
    }
    
    return $stats;
}

/**
 * Handle announcement submission
 */
function handleAnnouncementSubmission($db, $schoolId, $userId) {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'create' || $action === 'update') {
            // Validate required fields
            $required = ['title', 'description', 'priority', 'target'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    $_SESSION['error'] = "Please fill in all required fields";
                    return;
                }
            }
            
            // Prepare data
            $data = [
                'school_id' => $schoolId,
                'title' => trim($_POST['title']),
                'description' => trim($_POST['description']),
                'priority' => $_POST['priority'],
                'target' => $_POST['target'],
                'created_by' => $userId,
                'category' => $_POST['category'] ?? 'general',
                'is_published' => isset($_POST['publish_now']) ? 1 : 0
            ];
            
            // Optional fields
            if (!empty($_POST['start_date'])) {
                $data['start_date'] = $_POST['start_date'];
            }
            if (!empty($_POST['end_date'])) {
                $data['end_date'] = $_POST['end_date'];
            }
            if (!empty($_POST['class_id'])) {
                $data['class_id'] = (int)$_POST['class_id'];
            }
            if (!empty($_POST['section_id'])) {
                $data['section_id'] = (int)$_POST['section_id'];
            }
            
            if ($action === 'create') {
                // Insert new announcement
                $fields = implode(', ', array_keys($data));
                $placeholders = ':' . implode(', :', array_keys($data));
                
                $sql = "INSERT INTO announcements ($fields) VALUES ($placeholders)";
                $stmt = $db->prepare($sql);
                
                if ($stmt->execute($data)) {
                    $announcementId = $db->lastInsertId();
                    $_SESSION['success'] = "Announcement created successfully!";
                    
                    // Log activity
                    logActivity($db, $schoolId, $userId, 'announcement_created', [
                        'announcement_id' => $announcementId,
                        'title' => $data['title']
                    ]);
                    
                    // Redirect to view page
                    header("Location: ?view=$announcementId");
                    exit;
                } else {
                    $_SESSION['error'] = "Failed to create announcement";
                }
            } elseif ($action === 'update') {
                // Update existing announcement
                if (empty($_POST['id'])) {
                    $_SESSION['error'] = "Announcement ID required";
                    return;
                }
                
                $data['id'] = $_POST['id'];
                $setClause = [];
                foreach ($data as $key => $value) {
                    if ($key !== 'id' && $key !== 'school_id') {
                        $setClause[] = "$key = :$key";
                    }
                }
                
                $sql = "UPDATE announcements SET " . implode(', ', $setClause) . " WHERE id = :id AND school_id = :school_id";
                $stmt = $db->prepare($sql);
                
                if ($stmt->execute($data)) {
                    $_SESSION['success'] = "Announcement updated successfully!";
                    
                    // Log activity
                    logActivity($db, $schoolId, $userId, 'announcement_updated', [
                        'announcement_id' => $data['id'],
                        'title' => $data['title']
                    ]);
                    
                    // Redirect to view page
                    header("Location: ?view={$data['id']}");
                    exit;
                } else {
                    $_SESSION['error'] = "Failed to update announcement";
                }
            }
        }
    }
}

/**
 * Handle announcement actions (delete, archive, publish, etc.)
 */
function handleAnnouncementAction($db, $schoolId, $userId) {
    $action = $_GET['action'];
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (!$id) {
        $_SESSION['error'] = "Invalid announcement ID";
        return;
    }
    
    try {
        switch ($action) {
            case 'delete':
                // First get announcement details for logging
                $stmt = $db->prepare("SELECT title FROM announcements WHERE id = ? AND school_id = ?");
                $stmt->execute([$id, $schoolId]);
                $announcement = $stmt->fetch();
                
                // Delete the announcement
                $stmt = $db->prepare("DELETE FROM announcements WHERE id = ? AND school_id = ?");
                if ($stmt->execute([$id, $schoolId])) {
                    $_SESSION['success'] = "Announcement deleted successfully";
                    
                    // Log activity
                    logActivity($db, $schoolId, $userId, 'announcement_deleted', [
                        'announcement_id' => $id,
                        'title' => $announcement['title'] ?? ''
                    ]);
                } else {
                    $_SESSION['error'] = "Failed to delete announcement";
                }
                break;
                
            case 'publish':
                $stmt = $db->prepare("UPDATE announcements SET is_published = 1 WHERE id = ? AND school_id = ?");
                if ($stmt->execute([$id, $schoolId])) {
                    $_SESSION['success'] = "Announcement published successfully";
                    
                    // Log activity
                    logActivity($db, $schoolId, $userId, 'announcement_published', [
                        'announcement_id' => $id
                    ]);
                } else {
                    $_SESSION['error'] = "Failed to publish announcement";
                }
                break;
                
            case 'unpublish':
                $stmt = $db->prepare("UPDATE announcements SET is_published = 0 WHERE id = ? AND school_id = ?");
                if ($stmt->execute([$id, $schoolId])) {
                    $_SESSION['success'] = "Announcement unpublished successfully";
                    
                    // Log activity
                    logActivity($db, $schoolId, $userId, 'announcement_unpublished', [
                        'announcement_id' => $id
                    ]);
                } else {
                    $_SESSION['error'] = "Failed to unpublish announcement";
                }
                break;
                
            case 'archive':
                $stmt = $db->prepare("UPDATE announcements SET end_date = CURDATE() WHERE id = ? AND school_id = ?");
                if ($stmt->execute([$id, $schoolId])) {
                    $_SESSION['success'] = "Announcement archived successfully";
                    
                    // Log activity
                    logActivity($db, $schoolId, $userId, 'announcement_archived', [
                        'announcement_id' => $id
                    ]);
                } else {
                    $_SESSION['error'] = "Failed to archive announcement";
                }
                break;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    }
    
    // Redirect back to announcements page
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

/**
 * Log activity
 */
function logActivity($db, $schoolId, $userId, $action, $metadata = []) {
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs 
            (school_id, user_id, user_type, action, entity_type, entity_id, ip_address, user_agent, created_at) 
            VALUES (?, ?, 'admin', ?, 'announcement', ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $schoolId,
            $userId,
            $action,
            $metadata['announcement_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        // Silently fail logging - don't break main functionality
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Get classes for dropdown
 */
function getClasses($db, $schoolId) {
    try {
        $stmt = $db->prepare("SELECT id, name, code FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// Check for success/error messages
$successMessage = $_SESSION['success'] ?? '';
$errorMessage = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>School Announcements | <?php echo htmlspecialchars($schoolName); ?> Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --school-primary: #4f46e5;
            --school-secondary: #10b981;
            --school-surface: #ffffff;
            --school-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--school-bg); 
            color: #1e293b; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Glassmorphism effects */
        .glass-header { 
            background: rgba(255, 255, 255, 0.92); 
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.3);
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.5);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
        }

        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
        }
        
        .toast {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease forwards;
            opacity: 0;
            transform: translateX(100px);
            transition: opacity 0.3s, transform 0.3s;
        }
        
        .toast-success {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border-left: 4px solid #059669;
        }
        
        .toast-info {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
            border-left: 4px solid #3730a3;
        }
        
        .toast-warning {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
            border-left: 4px solid #d97706;
        }
        
        .toast-error {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            border-left: 4px solid #dc2626;
        }
        
        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }
        
        .toast-exit {
            animation: fadeOut 0.3s ease forwards;
        }
        
        .toast-icon {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .toast-content {
            flex: 1;
        }
        
        .toast-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        
        .toast-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Sidebar styling */
        .sidebar-link { 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
            position: relative;
        }
        
        .sidebar-link:hover { 
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.05) 0%, rgba(79, 70, 229, 0.02) 100%);
            color: var(--school-primary); 
            border-left-color: rgba(79, 70, 229, 0.3);
        }
        
        .active-link { 
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.1) 0%, rgba(79, 70, 229, 0.05) 100%);
            color: var(--school-primary); 
            border-left-color: var(--school-primary); 
            font-weight: 700;
        }
        
        .active-link::before {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--school-primary);
            border-radius: 4px 0 0 4px;
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        /* Status badges */
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
        
        .status-published {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .status-scheduled {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .status-draft {
            background-color: #f3f4f6;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }
        
        .status-archived {
            background-color: #f5f3ff;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
        }

        /* Priority badges */
        .priority-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .priority-high {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .priority-medium {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .priority-low {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        /* Announcement Cards */
        .announcement-card {
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .announcement-card:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        
        .announcement-urgent {
            border-left-color: #ef4444;
        }
        
        .announcement-important {
            border-left-color: #f59e0b;
        }
        
        .announcement-info {
            border-left-color: #4f46e5;
        }

        /* Audience Tags */
        .audience-tag {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .audience-students {
            background-color: #e0e7ff;
            color: #3730a3;
        }
        
        .audience-teachers {
            background-color: #fce7f3;
            color: #9d174d;
        }
        
        .audience-parents {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .audience-staff {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .audience-all {
            background-color: #f3f4f6;
            color: #4b5563;
        }

        /* Form Styles */
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
            resize: vertical;
            min-height: 120px;
            font-family: 'Inter', sans-serif;
        }
        
        .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: white;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
        }

        /* Action Buttons */
        .action-btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .action-btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
        }
        
        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);
        }
        
        .action-btn-secondary {
            background: white;
            color: #4f46e5;
            border: 1px solid #e2e8f0;
        }
        
        .action-btn-secondary:hover {
            background: #f8fafc;
            border-color: #4f46e5;
        }
        
        .action-btn-danger {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }
        
        .action-btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }

        /* Search and Filter */
        .search-box {
            position: relative;
            width: 100%;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        /* Filter Chips */
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-chip:hover {
            background: #e2e8f0;
        }
        
        .filter-chip.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }

        /* Pagination */
        .pagination-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .pagination-btn:hover {
            background: #f8fafc;
            border-color: #4f46e5;
            color: #4f46e5;
        }
        
        .pagination-btn.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Tab styling */
        .tab-button {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .tab-button:hover {
            color: #4f46e5;
        }
        
        .tab-button.active {
            color: #4f46e5;
            border-bottom-color: #4f46e5;
            background: linear-gradient(to top, rgba(79, 70, 229, 0.05), transparent);
        }

        /* Progress bars */
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }
        
        .progress-primary {
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
        }
        
        .progress-success {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .glass-header {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: white;
            }
            
            .toast-container {
                left: 20px;
                right: 20px;
                max-width: none;
            }
        }
    </style>
</head>
<body class="antialiased selection:bg-indigo-100 selection:text-indigo-900">

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer">
        <?php if ($successMessage): ?>
            <div class="toast toast-success" id="successToast">
                <i class="fas fa-check-circle toast-icon"></i>
                <div class="toast-content"><?php echo htmlspecialchars($successMessage); ?></div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
            <div class="toast toast-error" id="errorToast">
                <i class="fas fa-times-circle toast-icon"></i>
                <div class="toast-content"><?php echo htmlspecialchars($errorMessage); ?></div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- View Announcement Modal -->
    <?php if ($viewingAnnouncement): ?>
    <div id="viewAnnouncementModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000]">
        <div class="bg-white rounded-2xl p-8 max-w-4xl w-11/12 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-50 to-purple-50 flex items-center justify-center">
                        <i class="fas fa-bullhorn text-indigo-600"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-black text-slate-900" id="viewAnnouncementTitle">
                            <?php echo htmlspecialchars($viewingAnnouncement['title']); ?>
                        </h3>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs font-bold text-slate-500" id="viewAnnouncementDate">
                                <?php echo date('M j, Y • g:i A', strtotime($viewingAnnouncement['created_at'])); ?>
                            </span>
                            <span class="status-badge <?php echo $viewingAnnouncement['is_published'] ? 'status-published' : 'status-draft'; ?>">
                                <?php echo $viewingAnnouncement['is_published'] ? 'Published' : 'Draft'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="?edit=<?php echo $viewingAnnouncement['id']; ?>" class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-lg">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="?" class="p-2 text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </a>
                </div>
            </div>
            
            <div class="mb-6">
                <div class="flex flex-wrap gap-2 mb-4">
                    <span class="priority-badge priority-<?php echo htmlspecialchars($viewingAnnouncement['priority']); ?>">
                        <?php echo htmlspecialchars($priorities[$viewingAnnouncement['priority']] ?? ucfirst($viewingAnnouncement['priority'])); ?>
                    </span>
                    <span class="audience-tag audience-<?php echo htmlspecialchars($viewingAnnouncement['target']); ?>">
                        <?php echo htmlspecialchars($audiences[$viewingAnnouncement['target']] ?? ucfirst($viewingAnnouncement['target'])); ?>
                    </span>
                    <?php if ($viewingAnnouncement['category']): ?>
                    <span class="text-xs px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-medium">
                        <?php echo htmlspecialchars($categories[$viewingAnnouncement['category']] ?? ucfirst($viewingAnnouncement['category'])); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($viewingAnnouncement['author_name']): ?>
                    <span class="text-xs text-slate-500">
                        <i class="fas fa-user mr-1"></i> <?php echo htmlspecialchars($viewingAnnouncement['author_name']); ?>
                    </span>
                    <?php endif; ?>
                </div>
                
                <div class="prose max-w-none" id="viewAnnouncementContent">
                    <p class="text-slate-700 mb-4 whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars($viewingAnnouncement['description'])); ?></p>
                    
                    <?php if (!empty($viewingAnnouncement['start_date']) || !empty($viewingAnnouncement['end_date'])): ?>
                    <div class="mt-4 p-4 bg-slate-50 rounded-xl">
                        <p class="text-sm font-medium text-slate-700 mb-2">Schedule</p>
                        <div class="flex items-center gap-4">
                            <?php if (!empty($viewingAnnouncement['start_date'])): ?>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-calendar-alt text-slate-400"></i>
                                <span class="text-sm">From: <?php echo date('M j, Y', strtotime($viewingAnnouncement['start_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($viewingAnnouncement['end_date'])): ?>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-calendar-alt text-slate-400"></i>
                                <span class="text-sm">To: <?php echo date('M j, Y', strtotime($viewingAnnouncement['end_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex gap-3 pt-6 border-t border-slate-100">
                <a href="?" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition text-center">
                    Close
                </a>
                <?php if ($viewingAnnouncement['is_published']): ?>
                <a href="?action=unpublish&id=<?php echo $viewingAnnouncement['id']; ?>" 
                   onclick="return confirm('Unpublish this announcement?')"
                   class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                    Unpublish
                </a>
                <?php else: ?>
                <a href="?action=publish&id=<?php echo $viewingAnnouncement['id']; ?>" 
                   onclick="return confirm('Publish this announcement?')"
                   class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                    Publish Now
                </a>
                <?php endif; ?>
                <a href="?action=delete&id=<?php echo $viewingAnnouncement['id']; ?>" 
                   onclick="return confirm('Are you sure you want to delete this announcement? This action cannot be undone.')"
                   class="px-6 py-3 bg-gradient-to-r from-red-600 to-pink-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-red-200">
                    Delete
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- New/Edit Announcement Modal -->
    <?php if (isset($_GET['new']) || isset($_GET['edit'])): ?>
    <div id="newAnnouncementModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000]">
        <div class="bg-white rounded-2xl p-8 max-w-4xl w-11/12 max-h-[90vh] overflow-y-auto">
            <form action="" method="POST" id="announcementForm">
                <input type="hidden" name="action" value="<?php echo isset($editingAnnouncement) ? 'update' : 'create'; ?>">
                <?php if (isset($editingAnnouncement)): ?>
                <input type="hidden" name="id" value="<?php echo $editingAnnouncement['id']; ?>">
                <?php endif; ?>
                
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">
                        <?php echo isset($editingAnnouncement) ? 'Edit Announcement' : 'Create New Announcement'; ?>
                    </h3>
                    <a href="?" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </a>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-6">
                        <div>
                            <label class="form-label">Announcement Title *</label>
                            <input type="text" name="title" class="form-input" 
                                   placeholder="e.g., Upcoming Parent-Teacher Meetings"
                                   value="<?php echo isset($editingAnnouncement) ? htmlspecialchars($editingAnnouncement['title']) : ''; ?>"
                                   required>
                        </div>
                        
                        <div>
                            <label class="form-label">Announcement Content *</label>
                            <textarea name="description" class="form-textarea" 
                                      placeholder="Enter detailed announcement content..."
                                      rows="8" required><?php echo isset($editingAnnouncement) ? htmlspecialchars($editingAnnouncement['description']) : ''; ?></textarea>
                            <div class="flex justify-between items-center mt-2">
                                <span class="text-xs text-slate-500">Supports plain text formatting</span>
                                <span class="text-xs text-slate-500" id="charCount">0/5000 characters</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $value => $label): ?>
                                <option value="<?php echo $value; ?>" 
                                    <?php echo (isset($editingAnnouncement) && $editingAnnouncement['category'] == $value) || (!isset($editingAnnouncement) && $value == 'general') ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label">Priority Level *</label>
                            <div class="space-y-3">
                                <?php foreach ($priorities as $value => $label): ?>
                                <label class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50">
                                    <input type="radio" name="priority" value="<?php echo $value; ?>" 
                                           class="text-red-600" 
                                           <?php echo (isset($editingAnnouncement) && $editingAnnouncement['priority'] == $value) || (!isset($editingAnnouncement) && $value == 'medium') ? 'checked' : ''; ?>
                                           required>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <div class="w-3 h-3 rounded-full 
                                                <?php echo $value == 'high' ? 'bg-red-500' : ($value == 'medium' ? 'bg-amber-500' : 'bg-emerald-500'); ?>">
                                            </div>
                                            <span class="font-bold text-slate-900"><?php echo $label; ?></span>
                                        </div>
                                        <p class="text-sm text-slate-500 mt-1">
                                            <?php echo $value == 'high' ? 'Urgent announcements for immediate attention' : 
                                                   ($value == 'medium' ? 'Important but not urgent announcements' : 
                                                   'Informational updates and general news'); ?>
                                        </p>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Target Audience *</label>
                            <div class="space-y-2">
                                <?php foreach ($audiences as $value => $label): ?>
                                <label class="flex items-center gap-3 p-3 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50">
                                    <input type="radio" name="target" value="<?php echo $value; ?>" 
                                           class="rounded border-slate-300"
                                           <?php echo (isset($editingAnnouncement) && $editingAnnouncement['target'] == $value) || (!isset($editingAnnouncement) && $value == 'all') ? 'checked' : ''; ?>
                                           required>
                                    <div>
                                        <span class="text-sm font-medium text-slate-700"><?php echo $label; ?></span>
                                        <?php if ($value == 'all'): ?>
                                        <p class="text-xs text-slate-500">Students, teachers, parents, and staff</p>
                                        <?php endif; ?>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Schedule (Optional)</label>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-sm font-medium text-slate-700 mb-2 block">Start Date</label>
                                    <input type="date" name="start_date" class="form-input" 
                                           value="<?php echo isset($editingAnnouncement) && $editingAnnouncement['start_date'] ? $editingAnnouncement['start_date'] : ''; ?>">
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-slate-700 mb-2 block">End Date</label>
                                    <input type="date" name="end_date" class="form-input" 
                                           value="<?php echo isset($editingAnnouncement) && $editingAnnouncement['end_date'] ? $editingAnnouncement['end_date'] : ''; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($classes)): ?>
                        <div>
                            <label class="form-label">Specific Class (Optional)</label>
                            <select name="class_id" class="form-select">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                        <?php echo (isset($editingAnnouncement) && $editingAnnouncement['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <a href="?" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition text-center">
                        Cancel
                    </a>
                    <button type="submit" name="save_draft" class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Save as Draft
                    </button>
                    <button type="submit" name="publish_now" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                        <?php echo isset($editingAnnouncement) ? 'Update Announcement' : 'Publish Now'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <div class="flex h-screen overflow-hidden">
        
        <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-slate-200 z-[100] lg:relative lg:translate-x-0 -translate-x-full transition-transform duration-300 flex flex-col shadow-xl lg:shadow-none">
            
            <!-- School Header -->
            <div class="h-20 flex items-center px-6 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-100">
                            <i class="fas fa-school text-white text-lg"></i>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div>
                        <span class="text-xl font-black tracking-tight text-slate-900"><?php echo htmlspecialchars($schoolName); ?></span>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">SCHOOL ADMIN</p>
                    </div>
                </div>
            </div>

            <!-- School Quick Info -->
            <div class="p-6 border-b border-slate-100">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Total:</span>
                        <span class="text-sm font-black text-indigo-600"><?php echo $announcementStats['total']; ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Published:</span>
                        <span class="text-sm font-bold text-slate-900"><?php echo $announcementStats['published']; ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Drafts:</span>
                        <span class="text-sm font-bold text-slate-900"><?php echo $announcementStats['drafts']; ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">High Priority:</span>
                        <span class="text-sm font-bold text-red-600"><?php echo $announcementStats['high_priority']; ?></span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
                    <nav class="space-y-1">
                        <a href="school-dashboard.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span>Overview</span>
                        </a>
                        <a href="announcements.php" class="sidebar-link active-link flex items-center gap-3 px-6 py-3 text-sm font-semibold">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <span>Announcements</span>
                            <span class="ml-auto bg-indigo-100 text-indigo-800 text-xs font-bold px-2 py-1 rounded-full">
                                <?php echo $announcementStats['total']; ?>
                            </span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Student Management</p>
                    <nav class="space-y-1">
                        <a href="students.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <span>Students Directory</span>
                        </a>
                        <a href="attendance.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <span>Attendance</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Staff Management</p>
                    <nav class="space-y-1">
                        <a href="teachers.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <span>Teachers</span>
                        </a>
                        <a href="schedule.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <span>Timetable</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">School Operations</p>
                    <nav class="space-y-1">
                        <a href="fees.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <span>Fee Management</span>
                        </a>
                        <a href="settings.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-cog"></i>
                            </div>
                            <span>School Settings</span>
                        </a>
                    </nav>
                </div>
            </div>

            <!-- User Profile -->
            <div class="p-6 border-t border-slate-100">
                <div class="flex items-center gap-3 p-2 group cursor-pointer hover:bg-slate-50 rounded-xl transition">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center shadow-sm">
                            <i class="fas fa-user text-indigo-600"></i>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div class="overflow-hidden flex-1">
                        <p class="text-[13px] font-black text-slate-900 truncate"><?php echo htmlspecialchars($userName); ?></p>
                        <p class="text-[10px] font-black text-indigo-600 uppercase tracking-wider italic">School Admin</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <!-- Header -->
            <header class="h-20 glass-header px-6 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">School Announcements</h1>
                        <div class="hidden lg:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest">
                                <?php echo $announcementStats['total']; ?> Total Announcements
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="hidden md:flex items-center gap-2 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Drafts</p>
                            <p class="text-sm font-black text-amber-600"><?php echo $announcementStats['drafts']; ?></p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <a href="?new=1" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                            <i class="fas fa-plus"></i>
                            <span class="hidden sm:inline">New Announcement</span>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <a href="?" class="tab-button <?php echo $currentTab === 'all' ? 'active' : ''; ?>">
                            <i class="fas fa-list mr-2"></i>All Announcements
                        </a>
                        <a href="?status=published" class="tab-button <?php echo $currentTab === 'published' ? 'active' : ''; ?>">
                            <i class="fas fa-paper-plane mr-2"></i>Published
                            <span class="ml-2 bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded-full">
                                <?php echo $announcementStats['published']; ?>
                            </span>
                        </a>
                        <a href="?status=drafts" class="tab-button <?php echo $currentTab === 'drafts' ? 'active' : ''; ?>">
                            <i class="fas fa-edit mr-2"></i>Drafts
                            <span class="ml-2 bg-amber-100 text-amber-800 text-xs font-bold px-2 py-0.5 rounded-full">
                                <?php echo $announcementStats['drafts']; ?>
                            </span>
                        </a>
                        <a href="?status=scheduled" class="tab-button <?php echo $currentTab === 'scheduled' ? 'active' : ''; ?>">
                            <i class="fas fa-clock mr-2"></i>Scheduled
                            <span class="ml-2 bg-blue-100 text-blue-800 text-xs font-bold px-2 py-0.5 rounded-full">
                                <?php echo $announcementStats['scheduled']; ?>
                            </span>
                        </a>
                        <a href="?status=archived" class="tab-button <?php echo $currentTab === 'archived' ? 'active' : ''; ?>">
                            <i class="fas fa-archive mr-2"></i>Archived
                            <span class="ml-2 bg-purple-100 text-purple-800 text-xs font-bold px-2 py-0.5 rounded-full">
                                <?php echo $announcementStats['archived']; ?>
                            </span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-8 custom-scrollbar">
                <!-- Page Header & Filters -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">Manage School Announcements</h2>
                            <p class="text-slate-500 font-medium">Create, schedule, and track all school communications</p>
                        </div>
                        <div class="flex gap-3">
                            <form method="GET" class="search-box" onsubmit="return false;">
                                <input type="text" 
                                       name="search" 
                                       placeholder="Search announcements..." 
                                       class="search-input" 
                                       id="searchInput" 
                                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                                       onkeyup="if(event.key === 'Enter') filterAnnouncements()">
                                <i class="fas fa-search search-icon"></i>
                                <?php if (isset($_GET['status'])): ?>
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($_GET['status']); ?>">
                                <?php endif; ?>
                            </form>
                            <button onclick="filterAnnouncements()" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                <i class="fas fa-search"></i>
                                <span class="hidden sm:inline">Search</span>
                            </button>
                            <?php if (isset($_GET['search']) || isset($_GET['priority']) || isset($_GET['audience']) || isset($_GET['category'])): ?>
                            <a href="?" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                <i class="fas fa-times"></i>
                                <span class="hidden sm:inline">Clear Filters</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2 mt-6">
                        <a href="?" class="filter-chip <?php echo empty($filters) ? 'active' : ''; ?>">
                            <i class="fas fa-globe"></i> All (<?php echo $announcementStats['total']; ?>)
                        </a>
                        <a href="?priority=high" class="filter-chip <?php echo isset($filters['priority']) && $filters['priority'] === 'high' ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-circle"></i> High Priority (<?php echo $announcementStats['high_priority']; ?>)
                        </a>
                        <a href="?priority=medium" class="filter-chip <?php echo isset($filters['priority']) && $filters['priority'] === 'medium' ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-triangle"></i> Medium Priority (<?php echo $announcementStats['medium_priority']; ?>)
                        </a>
                        <a href="?priority=low" class="filter-chip <?php echo isset($filters['priority']) && $filters['priority'] === 'low' ? 'active' : ''; ?>">
                            <i class="fas fa-info-circle"></i> Low Priority (<?php echo $announcementStats['low_priority']; ?>)
                        </a>
                        <a href="?audience=students" class="filter-chip <?php echo isset($filters['target']) && $filters['target'] === 'students' ? 'active' : ''; ?>">
                            <i class="fas fa-user-graduate"></i> Students
                        </a>
                        <a href="?audience=teachers" class="filter-chip <?php echo isset($filters['target']) && $filters['target'] === 'teachers' ? 'active' : ''; ?>">
                            <i class="fas fa-chalkboard-teacher"></i> Teachers
                        </a>
                        <a href="?audience=parents" class="filter-chip <?php echo isset($filters['target']) && $filters['target'] === 'parents' ? 'active' : ''; ?>">
                            <i class="fas fa-user-friends"></i> Parents
                        </a>
                    </div>
                </div>

                <!-- Announcements Grid/List View -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-4">
                            <h3 class="text-lg font-black text-slate-900" id="announcementsTitle">
                                <?php 
                                if ($currentTab === 'all') {
                                    echo 'All Announcements';
                                } elseif ($currentTab === 'published') {
                                    echo 'Published Announcements';
                                } elseif ($currentTab === 'drafts') {
                                    echo 'Draft Announcements';
                                } elseif ($currentTab === 'scheduled') {
                                    echo 'Scheduled Announcements';
                                } elseif ($currentTab === 'archived') {
                                    echo 'Archived Announcements';
                                } else {
                                    echo 'Announcements';
                                }
                                ?>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-slate-500" id="announcementsCount">
                                Showing <?php echo min($pagination['current_page'] * $pagination['per_page'], $pagination['total_items']); ?> of <?php echo $pagination['total_items']; ?> announcements
                            </span>
                            <?php if ($pagination['total_pages'] > 1): ?>
                            <div class="flex items-center gap-2">
                                <?php if ($pagination['current_page'] > 1): ?>
                                <a href="?page=<?php echo $pagination['current_page'] - 1; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . $_GET['search'] : ''; ?><?php echo isset($_GET['priority']) ? '&priority=' . $_GET['priority'] : ''; ?>"
                                   class="pagination-btn">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= min($pagination['total_pages'], 5); $i++): ?>
                                    <?php if ($i == $pagination['current_page']): ?>
                                    <span class="pagination-btn active"><?php echo $i; ?></span>
                                    <?php else: ?>
                                    <a href="?page=<?php echo $i; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . $_GET['search'] : ''; ?><?php echo isset($_GET['priority']) ? '&priority=' . $_GET['priority'] : ''; ?>"
                                       class="pagination-btn"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($pagination['total_pages'] > 5): ?>
                                <span class="text-slate-400">...</span>
                                <a href="?page=<?php echo $pagination['total_pages']; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . $_GET['search'] : ''; ?><?php echo isset($_GET['priority']) ? '&priority=' . $_GET['priority'] : ''; ?>"
                                   class="pagination-btn"><?php echo $pagination['total_pages']; ?></a>
                                <?php endif; ?>
                                
                                <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                                <a href="?page=<?php echo $pagination['current_page'] + 1; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . $_GET['search'] : ''; ?><?php echo isset($_GET['priority']) ? '&priority=' . $_GET['priority'] : ''; ?>"
                                   class="pagination-btn">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Announcements Container -->
                    <div class="space-y-4" id="announcementsContainer">
                        <?php if (empty($announcements)): ?>
                        <div class="text-center py-12 animate-fadeInUp">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-bullhorn text-slate-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-bold text-slate-900 mb-2">
                                <?php echo isset($filters['search']) ? 'No announcements found' : 'No announcements yet'; ?>
                            </h3>
                            <p class="text-slate-500 mb-6">
                                <?php echo isset($filters['search']) ? 'Try a different search term' : 'Create your first announcement'; ?>
                            </p>
                            <a href="?new=1" class="action-btn action-btn-primary">
                                <i class="fas fa-plus"></i> Create Announcement
                            </a>
                        </div>
                        <?php else: ?>
                            <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-card announcement-<?php echo $announcement['priority'] === 'high' ? 'urgent' : ($announcement['priority'] === 'medium' ? 'important' : 'info'); ?> glass-card rounded-xl p-6 animate-fadeInUp">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-3">
                                            <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                                                <?php echo $priorities[$announcement['priority']] ?? ucfirst($announcement['priority']); ?>
                                            </span>
                                            <span class="<?php echo $announcement['is_published'] ? 'status-published' : 'status-draft'; ?>">
                                                <?php echo $announcement['is_published'] ? 'Published' : 'Draft'; ?>
                                            </span>
                                            <span class="audience-tag audience-<?php echo $announcement['target']; ?>">
                                                <?php echo $audiences[$announcement['target']] ?? ucfirst($announcement['target']); ?>
                                            </span>
                                            <?php if ($announcement['category']): ?>
                                            <span class="text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded-full font-medium">
                                                <?php echo htmlspecialchars($categories[$announcement['category']] ?? ucfirst($announcement['category'])); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <h4 class="text-lg font-black text-slate-900 mb-2"><?php echo htmlspecialchars($announcement['title']); ?></h4>
                                        <p class="text-sm text-slate-600 line-clamp-2 mb-4">
                                            <?php echo htmlspecialchars(substr($announcement['description'], 0, 150)); ?>
                                            <?php echo strlen($announcement['description']) > 150 ? '...' : ''; ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2 ml-4">
                                        <a href="?view=<?php echo $announcement['id']; ?>" class="p-2 text-slate-400 hover:text-slate-600">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-calendar-alt text-slate-400"></i>
                                            <span class="text-sm font-medium text-slate-900">
                                                <?php echo date('M j, Y • g:i A', strtotime($announcement['created_at'])); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($announcement['author_name'])): ?>
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-user text-slate-400"></i>
                                            <span class="text-sm text-slate-500"><?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <a href="?view=<?php echo $announcement['id']; ?>" class="action-btn action-btn-secondary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics & Insights -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Engagement Stats -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Announcement Distribution</h3>
                        <div class="space-y-4">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-slate-700">Published</span>
                                    <span class="text-sm font-bold text-slate-900">
                                        <?php echo $announcementStats['published']; ?> 
                                        (<?php echo $announcementStats['total'] > 0 ? round(($announcementStats['published'] / $announcementStats['total']) * 100, 1) : 0; ?>%)
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill progress-success" 
                                         style="width: <?php echo $announcementStats['total'] > 0 ? ($announcementStats['published'] / $announcementStats['total']) * 100 : 0; ?>%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-slate-700">Drafts</span>
                                    <span class="text-sm font-bold text-slate-900">
                                        <?php echo $announcementStats['drafts']; ?> 
                                        (<?php echo $announcementStats['total'] > 0 ? round(($announcementStats['drafts'] / $announcementStats['total']) * 100, 1) : 0; ?>%)
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill progress-warning" 
                                         style="width: <?php echo $announcementStats['total'] > 0 ? ($announcementStats['drafts'] / $announcementStats['total']) * 100 : 0; ?>%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-slate-700">High Priority</span>
                                    <span class="text-sm font-bold text-slate-900">
                                        <?php echo $announcementStats['high_priority']; ?> 
                                        (<?php echo $announcementStats['total'] > 0 ? round(($announcementStats['high_priority'] / $announcementStats['total']) * 100, 1) : 0; ?>%)
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill progress-primary" 
                                         style="width: <?php echo $announcementStats['total'] > 0 ? ($announcementStats['high_priority'] / $announcementStats['total']) * 100 : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Templates -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Quick Templates</h3>
                        <div class="space-y-3">
                            <button onclick="useTemplate('attendance')" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                        <i class="fas fa-calendar-check text-amber-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Attendance Alert</p>
                                        <p class="text-sm text-slate-500">Notify parents about attendance issues</p>
                                    </div>
                                </div>
                            </button>
                            
                            <button onclick="useTemplate('event')" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fas fa-calendar-alt text-emerald-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Event Reminder</p>
                                        <p class="text-sm text-slate-500">Remind about upcoming school events</p>
                                    </div>
                                </div>
                            </button>
                            
                            <button onclick="useTemplate('holiday')" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-umbrella-beach text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Holiday Notice</p>
                                        <p class="text-sm text-slate-500">Announce school holidays and breaks</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Quick Actions</h3>
                        <div class="space-y-4">
                            <a href="?new=1" class="flex items-center gap-3 p-4 bg-indigo-50 border border-indigo-100 rounded-xl hover:bg-indigo-100 transition">
                                <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
                                    <i class="fas fa-plus text-indigo-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-bold text-slate-900">Create New Announcement</p>
                                    <p class="text-sm text-slate-500">Broadcast important information to your school</p>
                                </div>
                                <i class="fas fa-chevron-right text-indigo-400"></i>
                            </a>
                            
                            <a href="?status=drafts" class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition">
                                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                    <i class="fas fa-edit text-amber-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-bold text-slate-900">Review Drafts</p>
                                    <p class="text-sm text-slate-500"><?php echo $announcementStats['drafts']; ?> draft<?php echo $announcementStats['drafts'] != 1 ? 's' : ''; ?> pending review</p>
                                </div>
                                <i class="fas fa-chevron-right text-slate-400"></i>
                            </a>
                            
                            <a href="?status=scheduled" class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-clock text-blue-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-bold text-slate-900">Scheduled Posts</p>
                                    <p class="text-sm text-slate-500"><?php echo $announcementStats['scheduled']; ?> announcement<?php echo $announcementStats['scheduled'] != 1 ? 's' : ''; ?> scheduled</p>
                                </div>
                                <i class="fas fa-chevron-right text-slate-400"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Announcement Tips -->
                <div class="max-w-7xl mx-auto">
                    <div class="glass-card rounded-2xl p-6 bg-gradient-to-r from-indigo-50 to-purple-50 border-indigo-100">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-xl bg-white flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-lightbulb text-indigo-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-slate-900 mb-2">Best Practices for Announcements</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex items-start gap-2">
                                        <i class="fas fa-check text-emerald-600 mt-1"></i>
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">Keep it concise</p>
                                            <p class="text-xs text-slate-600">Get straight to the point in the first paragraph</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <i class="fas fa-check text-emerald-600 mt-1"></i>
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">Use clear subject lines</p>
                                            <p class="text-xs text-slate-600">Make it easy to understand the announcement purpose</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <i class="fas fa-check text-emerald-600 mt-1"></i>
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">Schedule strategically</p>
                                            <p class="text-xs text-slate-600">Send important announcements during school hours</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <i class="fas fa-check text-emerald-600 mt-1"></i>
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">Target appropriately</p>
                                            <p class="text-xs text-slate-600">Only send to relevant audience segments</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toast Notification System
        class Toast {
            static show(message, type = 'info', duration = 5000) {
                const container = document.getElementById('toastContainer');
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                
                const icons = {
                    success: 'fa-check-circle',
                    info: 'fa-info-circle',
                    warning: 'fa-exclamation-triangle',
                    error: 'fa-times-circle'
                };
                
                toast.innerHTML = `
                    <i class="fas ${icons[type]} toast-icon"></i>
                    <div class="toast-content">${message}</div>
                    <button class="toast-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                container.appendChild(toast);
                
                setTimeout(() => {
                    toast.style.opacity = '1';
                    toast.style.transform = 'translateX(0)';
                }, 10);
                
                if (duration > 0) {
                    setTimeout(() => {
                        toast.classList.add('toast-exit');
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.remove();
                            }
                        }, 300);
                    }, duration);
                }
                
                return toast;
            }
            
            static success(message, duration = 5000) {
                return this.show(message, 'success', duration);
            }
            
            static info(message, duration = 5000) {
                return this.show(message, 'info', duration);
            }
            
            static warning(message, duration = 5000) {
                return this.show(message, 'warning', duration);
            }
            
            static error(message, duration = 5000) {
                return this.show(message, 'error', duration);
            }
        }

        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        // Filter announcements
        function filterAnnouncements() {
            const searchInput = document.getElementById('searchInput');
            const searchTerm = searchInput.value.trim();
            
            const urlParams = new URLSearchParams(window.location.search);
            
            if (searchTerm) {
                urlParams.set('search', searchTerm);
            } else {
                urlParams.delete('search');
            }
            
            // Remove page parameter when searching
            urlParams.delete('page');
            
            window.location.href = '?' + urlParams.toString();
        }

        // Clear search
        function clearSearch() {
            window.location.href = window.location.pathname;
        }

        // Use template
        function useTemplate(templateType) {
            const templates = {
                attendance: {
                    title: "Attendance Alert - Action Required",
                    content: "Dear Parents,\n\nWe would like to bring to your attention that your child has been marked absent for multiple days this week. Regular attendance is crucial for academic success.\n\nPlease ensure your child attends school regularly and inform us of any planned absences in advance.\n\nBest regards,\nSchool Administration",
                    priority: "high",
                    target: "parents",
                    category: "academic"
                },
                event: {
                    title: "Important Event Reminder",
                    content: "Dear School Community,\n\nThis is a reminder about our upcoming event. Please mark your calendars and make necessary arrangements to attend.\n\nEvent Details:\n- Date: [Event Date]\n- Time: [Event Time]\n- Location: [Event Venue]\n\nWe look forward to your participation!\n\nBest regards,\nEvent Committee",
                    priority: "medium",
                    target: "all",
                    category: "event"
                },
                holiday: {
                    title: "School Holiday Notice",
                    content: "Dear Parents and Students,\n\nPlease be informed that school will be closed for the upcoming holiday.\n\nHoliday Period: [Start Date] to [End Date]\nSchool Resumes: [Resumption Date]\n\nWe wish you a safe and enjoyable holiday!\n\nBest regards,\nSchool Administration",
                    priority: "low",
                    target: "all",
                    category: "holiday"
                }
            };
            
            const template = templates[templateType];
            if (template) {
                // Store template in localStorage and redirect to new announcement page
                localStorage.setItem('announcement_template', JSON.stringify(template));
                window.location.href = '?new=1';
            }
        }

        // Character count for announcement content
        function setupCharacterCount() {
            const contentTextarea = document.querySelector('textarea[name="description"]');
            if (contentTextarea) {
                const charCount = document.getElementById('charCount');
                
                contentTextarea.addEventListener('input', function() {
                    if (charCount) {
                        const length = this.value.length;
                        charCount.textContent = `${length}/5000 characters`;
                        if (length > 5000) {
                            charCount.classList.add('text-red-600');
                        } else {
                            charCount.classList.remove('text-red-600');
                        }
                    }
                });
                
                // Initialize count
                if (contentTextarea.value && charCount) {
                    const length = contentTextarea.value.length;
                    charCount.textContent = `${length}/5000 characters`;
                    if (length > 5000) {
                        charCount.classList.add('text-red-600');
                    }
                }
            }
        }

        // Load template from localStorage if available
        function loadTemplate() {
            if (window.location.search.includes('new=1')) {
                const templateData = localStorage.getItem('announcement_template');
                if (templateData) {
                    try {
                        const template = JSON.parse(templateData);
                        
                        // Fill form fields
                        const titleInput = document.querySelector('input[name="title"]');
                        const contentTextarea = document.querySelector('textarea[name="description"]');
                        const priorityRadio = document.querySelector(`input[name="priority"][value="${template.priority}"]`);
                        const targetRadio = document.querySelector(`input[name="target"][value="${template.target}"]`);
                        const categorySelect = document.querySelector('select[name="category"]');
                        
                        if (titleInput) titleInput.value = template.title;
                        if (contentTextarea) {
                            contentTextarea.value = template.content;
                            // Trigger input event to update character count
                            contentTextarea.dispatchEvent(new Event('input'));
                        }
                        if (priorityRadio) priorityRadio.checked = true;
                        if (targetRadio) targetRadio.checked = true;
                        if (categorySelect && template.category) {
                            categorySelect.value = template.category;
                        }
                        
                        // Clear template from localStorage
                        localStorage.removeItem('announcement_template');
                        
                        // Show success message
                        Toast.info(`"${templateType}" template loaded`);
                    } catch (e) {
                        console.error('Failed to load template:', e);
                    }
                }
            }
        }

        // Form validation
        function setupFormValidation() {
            const form = document.getElementById('announcementForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const title = form.querySelector('input[name="title"]');
                    const content = form.querySelector('textarea[name="description"]');
                    
                    if (!title.value.trim()) {
                        e.preventDefault();
                        Toast.error('Please enter an announcement title');
                        title.focus();
                        return false;
                    }
                    
                    if (!content.value.trim()) {
                        e.preventDefault();
                        Toast.error('Please enter announcement content');
                        content.focus();
                        return false;
                    }
                    
                    // Show loading state
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        submitBtn.disabled = true;
                    }
                    
                    return true;
                });
            }
        }

        // Keyboard shortcuts
        function setupKeyboardShortcuts() {
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + N for new announcement
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    window.location.href = '?new=1';
                }
                
                // Ctrl/Cmd + F for search
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
                
                // Esc to close modals
                if (e.key === 'Escape') {
                    if (window.location.search.includes('view=') || 
                        window.location.search.includes('edit=') || 
                        window.location.search.includes('new=')) {
                        window.location.href = window.location.pathname;
                    }
                }
            });
        }

        // Auto-close toasts
        function autoCloseToasts() {
            setTimeout(() => {
                document.querySelectorAll('.toast').forEach(toast => {
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.remove();
                        }
                    }, 300);
                });
            }, 5000);
        }

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Set active filter chips based on URL
            const urlParams = new URLSearchParams(window.location.search);
            const currentPriority = urlParams.get('priority');
            const currentAudience = urlParams.get('audience');
            
            // Update active states
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            
            if (currentPriority) {
                const activeChip = document.querySelector(`.filter-chip[href*="priority=${currentPriority}"]`);
                if (activeChip) activeChip.classList.add('active');
            } else if (currentAudience) {
                const activeChip = document.querySelector(`.filter-chip[href*="audience=${currentAudience}"]`);
                if (activeChip) activeChip.classList.add('active');
            } else if (!urlParams.toString()) {
                const allChip = document.querySelector('.filter-chip[href="?"]');
                if (allChip) allChip.classList.add('active');
            }
            
            // Setup functions
            setupCharacterCount();
            loadTemplate();
            setupFormValidation();
            setupKeyboardShortcuts();
            autoCloseToasts();
            
            // Focus search input if it has value
            const searchInput = document.getElementById('searchInput');
            if (searchInput && searchInput.value) {
                searchInput.focus();
                searchInput.select();
            }
            
            // Welcome message on first load
            if (!sessionStorage.getItem('announcements_welcome_shown')) {
                setTimeout(() => {
                    Toast.info('Welcome to Announcements Manager! Use Ctrl+N to create new announcements.');
                }, 1000);
                sessionStorage.setItem('announcements_welcome_shown', 'true');
            }
        });
    </script>
</body>
</html>