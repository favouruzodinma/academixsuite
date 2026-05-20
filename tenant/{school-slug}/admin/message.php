<?php
/**
 * School Admin Dashboard - Messenger Page
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/messenger.log');

error_log("=== MESSENGER PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants if not defined
if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');
if (!defined('IS_LOCAL')) define('IS_LOCAL', true);

// Start session safely
try {
    if (session_status() === PHP_SESSION_NONE) {
        error_log("Starting session...");
        session_start([
            'cookie_lifetime' => 86400,
            'read_and_close'  => false,
        ]);
        error_log("Session started successfully");
        error_log("Session ID: " . session_id());
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'message.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

error_log("School Slug from Router: " . $schoolSlug);
error_log("User Type: " . $userType);

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info from session or GLOBALS
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Check authentication
$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if ($_SESSION['school_auth']['school_slug'] === $schoolSlug) {
        $isAuthenticated = true;
        error_log("User authenticated for school: " . $schoolSlug);
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

error_log("User ID: " . $userId . ", User Type: " . $userType);

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit;
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    error_log("Loading autoload.php from: " . $autoloadPath);

    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found");
    }

    require_once $autoloadPath;
    error_log("Autoload loaded successfully");

    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        error_log("Connecting to school database: " . $school['database_name']);
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    } else {
        error_log("WARNING: School database name is empty");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Get logged in admin user details
$adminUser = ['name' => 'Admin User', 'email' => '', 'profile_photo' => ''];
if ($schoolDb) {
    try {
        $userStmt = $schoolDb->prepare("
            SELECT u.* 
            FROM users u 
            WHERE u.id = ? AND u.school_id = ?
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
            } elseif (isset($_SESSION['school_user']['name'])) {
                $adminUser = [
                    'name' => $_SESSION['school_user']['name'],
                    'email' => $_SESSION['school_user']['email'] ?? '',
                    'profile_photo' => $_SESSION['school_user']['profile_photo'] ?? ''
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching admin user: " . $e->getMessage());
    }
}

// Initialize MessengerManager
require_once __DIR__ . '/../../../includes/MessengerManager.php';
$messenger = new MessengerManager($schoolDb, $school['id'], $userId, $userType);
$unreadCount = $messenger->getUnreadCount();

error_log("=================== MESSENGER PAGE END ===================");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Modern Education Admin Dashboard Messenger">
    <title><?php echo htmlspecialchars($school['name']); ?> | Messenger</title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
   <style>
/* Additional Messenger Styles - Fixed Header and Input Layout */
.chat-wrapper {
    display: flex;
    height: calc(100vh - 200px);
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
}

.chat-sidebar {
    width: 350px;
    border-right: 1px solid #e9ecef;
    display: flex;
    flex-direction: column;
    background: #f8f9fa;
    transition: all 0.3s ease;
}

.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #fff;
    height: 100%;
    position: relative;
    overflow: hidden;
}

/* Fixed Header */
.chat-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    transition: box-shadow 0.3s ease;
}

.chat-header.scrolled {
    box-shadow: 0 4px 8px rgba(0,0,0,0.05);
}

.chat-header-left {
    display: flex;
    align-items: center;
    gap: 15px;
    min-width: 0;
}

.back-btn {
    display: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    color: #6c757d;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s;
}

.back-btn:hover {
    background: #25A194;
    color: #fff;
    border-color: #25A194;
}

.chat-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.chat-header-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.chat-header-avatar.group {
    border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
}

.chat-header-details {
    min-width: 0;
}

.chat-header-details h6 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
    color: #2c3e50;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-header-details p {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.chat-header-actions button {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid #e9ecef;
    background: #f8f9fa;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s;
}

.chat-header-actions button:hover {
    background: #25A194;
    color: #fff;
    border-color: #25A194;
}

/* Scrollable Messages Area */
.chat-messages-area {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 20px;
    background: #f8f9fa;
    scroll-behavior: smooth;
    min-height: 0;
    height: auto;
}

/* Custom Scrollbar */
.chat-messages-area::-webkit-scrollbar {
    width: 6px;
}

.chat-messages-area::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.chat-messages-area::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.chat-messages-area::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Fixed Input Area */
.chat-input-area {
    padding: 20px;
    border-top: 1px solid #e9ecef;
    background: #fff;
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    z-index: 10;
    box-shadow: 0 -2px 4px rgba(0,0,0,0.02);
    transition: box-shadow 0.3s ease;
}

.chat-input-area.scrolled {
    box-shadow: 0 -4px 8px rgba(0,0,0,0.05);
}

.reply-preview {
    padding: 10px 12px;
    background: #f8f9fa;
    border-left: 3px solid #25A194;
    border-radius: 8px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.reply-preview-content {
    flex: 1;
    min-width: 0;
}

.reply-preview-label {
    font-size: 11px;
    color: #25A194;
    font-weight: 600;
    margin-bottom: 2px;
}

.reply-preview-text {
    font-size: 13px;
    color: #6c757d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.reply-preview-close {
    color: #6c757d;
    cursor: pointer;
    padding: 4px;
    border-radius: 50%;
    flex-shrink: 0;
}

.reply-preview-close:hover {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.file-previews {
    margin-bottom: 12px;
}

.file-preview {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 6px;
    border: 1px solid #e9ecef;
}

.file-preview img {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    flex-shrink: 0;
}

.file-preview-info {
    flex: 1;
    min-width: 0;
}

.file-preview-name {
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.file-preview-size {
    font-size: 11px;
    color: #6c757d;
}

.file-preview-remove {
    color: #dc3545;
    cursor: pointer;
    padding: 4px;
    border-radius: 50%;
    flex-shrink: 0;
}

.file-preview-remove:hover {
    background: rgba(220, 53, 69, 0.1);
}

.chat-input-wrapper {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    background: #f8f9fa;
    border-radius: 24px;
    padding: 8px 16px;
    border: 1px solid #e9ecef;
}

.chat-input-wrapper:focus-within {
    border-color: #25A194;
    box-shadow: 0 0 0 3px rgba(37, 161, 148, 0.1);
}

.chat-input {
    flex: 1;
    border: none;
    background: transparent;
    resize: none;
    max-height: 150px;
    min-height: 40px;
    padding: 10px 0;
    font-size: 14px;
    line-height: 1.5;
    overflow-y: auto;
    font-family: inherit;
}

.chat-input:focus {
    outline: none;
}

.chat-input::-webkit-scrollbar {
    width: 4px;
}

.chat-input::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.chat-input::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.input-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

.input-action-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: #6c757d;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.input-action-btn:hover {
    background: rgba(37, 161, 148, 0.1);
    color: #25A194;
}

.send-btn {
    background: #25A194;
    color: #fff;
}

.send-btn:hover:not(:disabled) {
    background: #1e8a7f;
    transform: scale(1.05);
}

.send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Chat Sidebar Styles */
.chat-search {
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
}

.navbar-search {
    position: relative;
}

.navbar-search input {
    width: 100%;
    padding: 10px 40px 10px 16px;
    border: 1px solid #e9ecef;
    border-radius: 30px;
    font-size: 14px;
    transition: all 0.3s;
}

.navbar-search input:focus {
    outline: none;
    border-color: #25A194;
    box-shadow: 0 0 0 3px rgba(37, 161, 148, 0.1);
}

.navbar-search .icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 18px;
}

.chat-users-list {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
}

.chat-users-list::-webkit-scrollbar {
    width: 4px;
}

.chat-users-list::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.chat-users-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.chat-user-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 4px;
    position: relative;
    background: #fff;
    border: 1px solid transparent;
}

.chat-user-item:hover {
    background: rgba(37, 161, 148, 0.1);
    border-color: #e9ecef;
}

.chat-user-item.active {
    background: rgba(37, 161, 148, 0.15);
    border-left: 3px solid #25A194;
}

.chat-user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
    position: relative;
    flex-shrink: 0;
}

.chat-user-avatar.group {
    border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
}

.chat-user-avatar img {
    width: 100%;
    height: 100%;
    border-radius: inherit;
    object-fit: cover;
}

.online-indicator {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 10px;
    height: 10px;
    background: #28a745;
    border: 2px solid #fff;
    border-radius: 50%;
    display: none;
}

.chat-user-item.online .online-indicator {
    display: block;
}

.chat-user-info {
    flex: 1;
    min-width: 0;
}

.chat-user-name {
    font-weight: 600;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.chat-user-name span:first-child {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-user-status {
    font-size: 11px;
    color: #6c757d;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.chat-last-message {
    font-size: 12px;
    color: #6c757d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-unread-badge {
    background: #25A194;
    color: white;
    border-radius: 50%;
    min-width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
    flex-shrink: 0;
}

.chat-time {
    font-size: 11px;
    color: #6c757d;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Message Bubbles */
.date-divider {
    text-align: center;
    margin: 20px 0;
    position: relative;
}

.date-divider::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    width: 100%;
    height: 1px;
    background: #e9ecef;
    z-index: 1;
}

.date-divider span {
    background: #fff;
    padding: 4px 12px;
    border-radius: 30px;
    font-size: 12px;
    color: #6c757d;
    position: relative;
    z-index: 2;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.chat-message {
    display: flex;
    margin-bottom: 20px;
    animation: fadeIn 0.3s ease;
    position: relative;
}

.chat-message.sent {
    justify-content: flex-end;
}

.message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    margin-right: 12px;
    align-self: flex-end;
    flex-shrink: 0;
}

.chat-message.sent .message-avatar {
    display: none;
}

.chat-message-content {
    max-width: 65%;
    position: relative;
}

.message-bubble {
    padding: 10px 14px;
    border-radius: 18px;
    position: relative;
    word-wrap: break-word;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    line-height: 1.5;
    font-size: 14px;
}

.chat-message.received .message-bubble {
    background: #fff;
    border: 1px solid #e9ecef;
    border-top-left-radius: 4px;
}

.chat-message.sent .message-bubble {
    background: #25A194;
    color: white;
    border-top-right-radius: 4px;
}

.message-bubble::before {
    content: '';
    position: absolute;
    bottom: 0;
    width: 10px;
    height: 10px;
    background: inherit;
    border: inherit;
}

.chat-message.received .message-bubble::before {
    left: -5px;
    border-width: 0 0 1px 1px;
    border-style: solid;
    border-color: #e9ecef;
    background: #fff;
    transform: skewY(45deg);
}

.chat-message.sent .message-bubble::before {
    right: -5px;
    border-width: 0 1px 1px 0;
    border-style: solid;
    border-color: #25A194;
    background: #25A194;
    transform: skewY(45deg);
}

.message-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    font-size: 11px;
}

.chat-message.sent .message-meta {
    justify-content: flex-end;
}

.message-time {
    color: #6c757d;
}

.chat-message.sent .message-time {
    color: rgba(255,255,255,0.8);
}

.message-status {
    color: #6c757d;
}

.chat-message.sent .message-status {
    color: rgba(255,255,255,0.8);
}

.message-attachment {
    margin-top: 8px;
    padding: 8px;
    background: rgba(0,0,0,0.03);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.chat-message.sent .message-attachment {
    background: rgba(255,255,255,0.1);
}

.message-attachment:hover {
    background: rgba(0,0,0,0.06);
    transform: translateY(-2px);
}

.message-attachment img {
    max-width: 200px;
    max-height: 150px;
    border-radius: 8px;
}

.message-reactions {
    display: flex;
    gap: 4px;
    margin-top: 6px;
    flex-wrap: wrap;
}

.reaction {
    background: rgba(0,0,0,0.05);
    border-radius: 12px;
    padding: 2px 6px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s;
}

.chat-message.sent .reaction {
    background: rgba(255,255,255,0.2);
    color: #fff;
}

.reaction:hover {
    background: rgba(37, 161, 148, 0.2);
}

.reaction.active {
    background: #25A194;
    color: #fff;
}

.message-actions {
    position: absolute;
    top: -30px;
    right: 0;
    background: #fff;
    border-radius: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: none;
    padding: 4px;
    z-index: 10;
}

.chat-message:hover .message-actions {
    display: flex;
}

.message-action {
    padding: 4px 8px;
    cursor: pointer;
    color: #6c757d;
    border-radius: 30px;
    transition: all 0.2s;
    font-size: 16px;
}

.message-action:hover {
    background: rgba(37, 161, 148, 0.1);
    color: #25A194;
}

.message-action.delete:hover {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

/* Typing Indicator */
.typing-indicator {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 12px;
    background: #fff;
    border-radius: 20px;
    width: fit-content;
    margin-bottom: 10px;
    border: 1px solid #e9ecef;
}

.typing-indicator span {
    width: 6px;
    height: 6px;
    background: #6c757d;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}

.typing-indicator span:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-indicator span:nth-child(3) {
    animation-delay: 0.4s;
}

/* Emoji Picker */
.chat-emoji-picker {
    position: absolute;
    bottom: 100px;
    right: 20px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    padding: 12px;
    display: none;
    border: 1px solid #e9ecef;
    z-index: 1000;
}

.chat-emoji-picker.show {
    display: block;
}

.emoji-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 8px;
}

.emoji-item {
    font-size: 20px;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    text-align: center;
    transition: all 0.2s;
}

.emoji-item:hover {
    background: rgba(37, 161, 148, 0.1);
}

/* Modal Styles */
.modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e9ecef;
}

.modal-header h5 {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid #e9ecef;
}

/* User Selection Styles */
.user-select-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 4px;
    border: 1px solid transparent;
}

.user-select-item:hover {
    background: #f8f9fa;
    border-color: #e9ecef;
}

.user-select-item.selected {
    background: rgba(37, 161, 148, 0.1);
    border-color: #25A194;
}

.user-select-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
    flex-shrink: 0;
}

.user-select-info {
    flex: 1;
    min-width: 0;
}

.user-select-name {
    font-weight: 500;
    font-size: 14px;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-select-type {
    font-size: 12px;
    color: #6c757d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-select-check {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 12px;
    flex-shrink: 0;
}

.user-select-item.selected .user-select-check {
    background: #25A194;
    border-color: #25A194;
}

.selected-users {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 12px 0;
}

.selected-user-tag {
    background: rgba(37, 161, 148, 0.1);
    color: #25A194;
    padding: 4px 10px;
    border-radius: 30px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.selected-user-tag i {
    cursor: pointer;
    font-size: 14px;
}

.selected-user-tag i:hover {
    color: #dc3545;
}

.group-name-input {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 12px;
    font-size: 14px;
    width: 100%;
    transition: all 0.3s;
}

.group-name-input:focus {
    outline: none;
    border-color: #25A194;
    box-shadow: 0 0 0 3px rgba(37, 161, 148, 0.1);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
}

.empty-state i {
    font-size: 48px;
    color: #e9ecef;
    margin-bottom: 16px;
}

.empty-state h5 {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 14px;
    margin-bottom: 20px;
}

.loading-spinner {
    width: 30px;
    height: 30px;
    border: 2px solid #e9ecef;
    border-top-color: #25A194;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

/* Mobile Overlay */
.mobile-sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1040;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.mobile-sidebar-overlay.show {
    display: block;
    opacity: 1;
}

/* Animations */
@keyframes fadeIn {
    from { 
        opacity: 0; 
        transform: translateY(10px); 
    }
    to { 
        opacity: 1; 
        transform: translateY(0); 
    }
}

@keyframes typing {
    0%, 60%, 100% { 
        transform: translateY(0); 
    }
    30% { 
        transform: translateY(-6px); 
    }
}

@keyframes spin {
    to { 
        transform: rotate(360deg); 
    }
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .chat-wrapper {
        height: calc(100vh - 180px);
    }
    
    .chat-sidebar {
        position: fixed;
        left: -100%;
        top: 0;
        bottom: 0;
        width: 85%;
        max-width: 320px;
        z-index: 1050;
        transition: left 0.3s ease;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .chat-sidebar.show {
        left: 0;
    }
    
    .back-btn {
        display: flex;
    }
    
    .chat-main {
        width: 100%;
    }
    
    .chat-header {
        padding: 12px 16px;
    }
    
    .chat-header-avatar {
        width: 40px;
        height: 40px;
    }
    
    .chat-header-details h6 {
        font-size: 14px;
    }
    
    .chat-messages-area {
        padding: 15px;
    }
    
    .message-bubble {
        max-width: 85%;
        font-size: 13px;
        padding: 8px 12px;
    }
    
    .message-attachment img {
        max-width: 150px;
        max-height: 120px;
    }
    
    .chat-input-area {
        padding: 15px;
    }
    
    .input-action-btn {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }
    
    .chat-message-content {
        max-width: 80%;
    }
}

@media (max-width: 480px) {
    .chat-user-avatar {
        width: 44px;
        height: 44px;
    }
    
    .chat-user-name span:first-child {
        max-width: 120px;
    }
    
    .chat-last-message {
        max-width: 140px;
    }
    
    .message-bubble {
        max-width: 90%;
        font-size: 12px;
    }
    
    .emoji-grid {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .chat-message-content {
        max-width: 85%;
    }
}
</style>
</head>

<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="mobile-sidebar-overlay" id="mobileSidebarOverlay" onclick="closeSidebar()"></div>

    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>
    <button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
    
    <?php include_once('includes/sidebar.php') ?>

    <main class="dashboard-main">
        <div class="navbar-header shadow-1">
            <div class="row align-items-center justify-content-between">
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-4">
                        <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button" onclick="toggleMainSidebar()">
                            <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                        </button>
                        <form class="navbar-search" onsubmit="return false;">
                            <input type="text" class="bg-transparent" id="globalSearch" placeholder="Search messages...">
                            <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                        </form>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                        <div class="dropdown">
                            <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                                <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                                <?php if ($unreadCount > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                                <div class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                    <div>
                                        <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                                    </div>
                                    <span class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo $unreadCount; ?></span>
                                </div>
                                <div class="text-center py-12 px-16">
                                    <a href="message.php" class="text-primary-600 fw-semibold text-md hover-underline">View All Messages</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Messenger</h1>
                    <div class="">
                        <a href="dashboard.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light">/ Messenger</span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary-600 radius-8 d-flex align-items-center gap-2" onclick="openNewChat()">
                        <i class="ri-chat-new-line"></i>
                        <span class="d-none d-sm-inline">New Chat</span>
                    </button>
                    <button class="btn btn-outline-primary-600 radius-8 d-flex align-items-center gap-2" onclick="openCreateGroup()">
                        <i class="ri-group-line"></i>
                        <span class="d-none d-sm-inline">New Group</span>
                    </button>
                </div>
            </div>

            <div class="mt-24">
                <div class="chat-wrapper">
                    <!-- Chat Sidebar -->
                    <div class="chat-sidebar" id="chatSidebar">
                        <div class="chat-search">
                            <form class="navbar-search d-block" onsubmit="return false;">
                                <input type="text" class="bg-transparent w-100" id="searchConversations" placeholder="Search conversations...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                        </div>
                        <div class="chat-users-list" id="conversationsList">
                            <div class="empty-state">
                                <i class="ri-chat-1-line"></i>
                                <h5>No conversations yet</h5>
                                <p>Start a new chat or create a group</p>
                            </div>
                        </div>
                    </div>

                    <!-- Chat Main Area -->
                    <div class="chat-main">
                        <div class="chat-header" id="chatHeader" style="display: none;">
                            <div class="chat-header-left">
                                <button class="back-btn" onclick="toggleSidebar()" id="mobileBackBtn">
                                    <i class="ri-arrow-left-line"></i>
                                </button>
                                <div class="chat-header-info">
                                    <img src="" alt="" class="chat-header-avatar" id="chatHeaderAvatar">
                                    <div class="chat-header-details">
                                        <h6 id="chatHeaderName">Select a chat</h6>
                                        <p id="chatHeaderStatus"></p>
                                    </div>
                                </div>
                            </div>
                            <div class="chat-header-actions">
                                <button onclick="openSearchInChat()">
                                    <i class="ri-search-line"></i>
                                </button>
                                <div class="dropdown">
                                    <button data-bs-toggle="dropdown">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#" onclick="toggleMute()"><i class="ri-notification-off-line"></i> Mute notifications</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="toggleArchive()"><i class="ri-archive-line"></i> Archive chat</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="viewGroupInfo()" id="groupInfoBtn" style="display: none;"><i class="ri-group-line"></i> Group info</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="blockUser()" id="blockUserBtn"><i class="ri-user-unfollow-line"></i> Block user</a></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteChat()"><i class="ri-delete-bin-line"></i> Delete chat</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="chat-messages-area" id="messagesArea">
                            <div class="empty-state" id="noChatSelected">
                                <i class="ri-chat-3-line"></i>
                                <h5>Welcome to Messenger</h5>
                                <p>Select a conversation or start a new chat</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button class="btn btn-primary-600 radius-8" onclick="openNewChat()">
                                        <i class="ri-chat-new-line me-1"></i> New Chat
                                    </button>
                                    <button class="btn btn-outline-primary-600 radius-8" onclick="openCreateGroup()">
                                        <i class="ri-group-line me-1"></i> New Group
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="chat-input-area" id="chatInputArea" style="display: none;">
                            <div class="reply-preview" id="replyPreview" style="display: none;">
                                <div class="reply-preview-content">
                                    <div class="reply-preview-label">Replying to</div>
                                    <div class="reply-preview-text" id="replyMessageText"></div>
                                </div>
                                <i class="ri-close-line reply-preview-close" onclick="cancelReply()"></i>
                            </div>

                            <div class="file-previews" id="filePreviews"></div>

                            <div class="chat-input-wrapper">
                                <textarea class="chat-input" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                                <div class="input-actions">
                                    <button class="input-action-btn" onclick="openEmojiPicker()">
                                        <i class="ri-emotion-line"></i>
                                    </button>
                                    <button class="input-action-btn" onclick="document.getElementById('fileInput').click()">
                                        <i class="ri-attachment-2"></i>
                                    </button>
                                    <button class="input-action-btn send-btn" id="sendMessageBtn" onclick="sendMessage()" disabled>
                                        <i class="ri-send-plane-fill"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden file input -->
        <input type="file" id="fileInput" multiple style="display: none;">

        <!-- Emoji Picker -->
        <div class="chat-emoji-picker" id="emojiPicker">
            <div class="emoji-grid">
                <span class="emoji-item" onclick="addEmoji('😊')">😊</span>
                <span class="emoji-item" onclick="addEmoji('😂')">😂</span>
                <span class="emoji-item" onclick="addEmoji('❤️')">❤️</span>
                <span class="emoji-item" onclick="addEmoji('👍')">👍</span>
                <span class="emoji-item" onclick="addEmoji('🎉')">🎉</span>
                <span class="emoji-item" onclick="addEmoji('🔥')">🔥</span>
                <span class="emoji-item" onclick="addEmoji('😢')">😢</span>
                <span class="emoji-item" onclick="addEmoji('😡')">😡</span>
                <span class="emoji-item" onclick="addEmoji('🤔')">🤔</span>
                <span class="emoji-item" onclick="addEmoji('👏')">👏</span>
            </div>
        </div>
    </main>

    <!-- Modals -->
    <!-- New Chat Modal -->
    <div class="modal fade" id="newChatModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Chat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="chat-search mb-3">
                        <input type="text" id="searchUsers" placeholder="Search teachers or parents..." class="form-control">
                        <i class="ri-search-line"></i>
                    </div>
                    <div id="usersList" style="max-height: 400px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Group Modal -->
    <div class="modal fade" id="createGroupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="group-name-input mb-3" id="groupName" placeholder="Enter group name...">
                    
                    <div class="chat-search mb-3">
                        <input type="text" id="searchUsersForGroup" placeholder="Add members..." class="form-control">
                        <i class="ri-search-line"></i>
                    </div>

                    <div class="selected-users" id="selectedUsers"></div>

                    <div id="usersForGroupList" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createGroup()" id="createGroupBtn" disabled>Create Group</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Group Info Modal -->
    <div class="modal fade" id="groupInfoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Group Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <img src="" alt="" class="rounded-circle" style="width: 80px; height: 80px; object-fit: cover;" id="groupInfoAvatar">
                        <h5 class="mt-3" id="groupInfoName"></h5>
                        <p class="text-secondary" id="groupInfoMembers"></p>
                    </div>
                    <h6 class="mb-3">Members</h6>
                    <div id="groupMembersList" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search in Chat Modal -->
    <div class="modal fade" id="searchInChatModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Search in Chat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="chat-search mb-3">
                        <input type="text" id="searchInChat" placeholder="Search messages..." class="form-control">
                        <i class="ri-search-line"></i>
                    </div>
                    <div id="searchResults" style="max-height: 400px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>

    <footer class="d-footer">
        <div class="">
            <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Messenger System</p>
        </div>
    </footer>

    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
// Messenger JavaScript - Fixed Version with Auto-scroll and Fixed Layout
let currentConversationId = null;
let currentUserId = <?php echo $userId; ?>;
let currentConversationType = 'individual';
let selectedUsers = new Set();
let selectedFiles = [];
let replyToId = null;
let checkNewMessagesInterval;
let typingTimeout;
let isTyping = false;
let isLoadingConversations = false;
let isLoadingMessages = false;
let lastMessageId = 0;

// Auto-resize textarea function
function autoResizeTextarea() {
    const textarea = document.getElementById('messageInput');
    if (textarea) {
        textarea.style.height = 'auto';
        const newHeight = Math.min(textarea.scrollHeight, 150);
        textarea.style.height = newHeight + 'px';
        textarea.style.overflowY = textarea.scrollHeight > 150 ? 'scroll' : 'hidden';
    }
}

// Smooth scroll to bottom
function scrollToBottom() {
    const messagesArea = document.getElementById('messagesArea');
    if (messagesArea) {
        messagesArea.scrollTo({
            top: messagesArea.scrollHeight,
            behavior: 'smooth'
        });
    }
}

// Force scroll to bottom (instant)
function forceScrollToBottom() {
    const messagesArea = document.getElementById('messagesArea');
    if (messagesArea) {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
}

// Check if user is at bottom
function isAtBottom() {
    const messagesArea = document.getElementById('messagesArea');
    if (!messagesArea) return true;
    return messagesArea.scrollHeight - messagesArea.scrollTop <= messagesArea.clientHeight + 50;
}

// Handle scroll shadows on header and input
function handleScrollShadows() {
    const messagesArea = document.getElementById('messagesArea');
    const header = document.querySelector('.chat-header');
    const inputArea = document.querySelector('.chat-input-area');
    
    if (messagesArea && header) {
        if (messagesArea.scrollTop > 10) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        if (inputArea) {
            const scrollBottom = messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight;
            if (scrollBottom > 10) {
                inputArea.classList.add('scrolled');
            } else {
                inputArea.classList.remove('scrolled');
            }
        }
    }
}

// Toggle main sidebar
function toggleMainSidebar() {
    $('.dashboard-main').toggleClass('sidebar-collapsed');
    $('.sidebar').toggleClass('show');
}

// Toggle chat sidebar (mobile)
function toggleSidebar() {
    $('#chatSidebar').toggleClass('show');
    $('#mobileSidebarOverlay').toggleClass('show');
    
    if ($('#chatSidebar').hasClass('show')) {
        $('body').css('overflow', 'hidden');
    } else {
        $('body').css('overflow', '');
    }
}

// Close sidebar (mobile)
function closeSidebar() {
    $('#chatSidebar').removeClass('show');
    $('#mobileSidebarOverlay').removeClass('show');
    $('body').css('overflow', '');
}

$(document).ready(function() {
    console.log('Messenger initialized - User ID:', currentUserId);
    loadConversations();
    setupEventListeners();
    startPolling();
    testAjaxConnection();
    
    $(window).on('resize', function() {
        if ($(window).width() > 768) {
            closeSidebar();
        }
    });
    
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape' && $('#chatSidebar').hasClass('show')) {
            closeSidebar();
        }
    });
});

function testAjaxConnection() {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: { action: 'get_unread_count' },
        dataType: 'json',
        success: function(response) {
            console.log('AJAX connection test successful:', response);
        },
        error: function(xhr, status, error) {
            console.error('AJAX connection test failed:', error);
            console.error('Response:', xhr.responseText);
        }
    });
}

function setupEventListeners() {
    $('#searchConversations').on('input', debounce(function() {
        filterConversations($(this).val());
    }, 500));

    $('#searchUsers, #searchUsersForGroup').on('input', debounce(function() {
        const search = $(this).val();
        const target = $(this).attr('id') === 'searchUsers' ? 'users' : 'groupUsers';
        searchUsers(search, target);
    }, 500));

    $('#messageInput').on('input', function() {
        const message = $(this).val().trim();
        $('#sendMessageBtn').prop('disabled', !message && selectedFiles.length === 0);
        autoResizeTextarea();
        
        if (!isTyping && message) {
            isTyping = true;
            sendTypingIndicator(true);
        }
        
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
            if (isTyping) {
                isTyping = false;
                sendTypingIndicator(false);
            }
        }, 1000);
    });

    $('#messageInput').keydown(function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    $('#groupName').on('input', function() {
        $('#createGroupBtn').prop('disabled', selectedUsers.size < 2 || !$(this).val().trim());
    });

    $('#searchInChat').on('input', debounce(function() {
        const query = $(this).val();
        if (query.length >= 2) {
            searchMessages(query);
        }
    }, 500));

    $(document).click(function(e) {
        if (!$(e.target).closest('#emojiPicker, .input-action-btn[onclick="openEmojiPicker()"]').length) {
            $('#emojiPicker').removeClass('show');
        }
    });
    
    $('#messagesArea').on('scroll', debounce(handleScrollShadows, 10));
    $(window).on('resize', debounce(handleScrollShadows, 100));
}

function startPolling() {
    if (checkNewMessagesInterval) {
        clearInterval(checkNewMessagesInterval);
    }
    
    checkNewMessagesInterval = setInterval(() => {
        if (currentConversationId) {
            checkNewMessages();
        }
        updateUnreadCount();
    }, 5000);
}

function loadConversations() {
    if (isLoadingConversations) return;
    isLoadingConversations = true;
    
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: { action: 'get_conversations' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderConversations(response.data);
            } else {
                $('#conversationsList').html(`
                    <div class="empty-state">
                        <i class="ri-error-warning-line"></i>
                        <h5>Error loading conversations</h5>
                        <p>${response.error || 'Unknown error'}</p>
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error loading conversations:', error);
            $('#conversationsList').html(`
                <div class="empty-state">
                    <i class="ri-error-warning-line"></i>
                    <h5>Connection Error</h5>
                    <p>Failed to connect to server</p>
                </div>
            `);
        },
        complete: function() {
            isLoadingConversations = false;
        }
    });
}

function renderConversations(conversations) {
    const container = $('#conversationsList');
    container.empty();

    if (!conversations || conversations.length === 0) {
        container.html(`
            <div class="empty-state">
                <i class="ri-chat-1-line"></i>
                <h5>No conversations yet</h5>
                <p>Start a new chat or create a group</p>
            </div>
        `);
        return;
    }

    conversations.forEach(conv => {
        const isGroup = conv.conversation_type === 'group';
        const unreadBadge = conv.unread_count > 0 ? 
            `<span class="chat-unread-badge">${conv.unread_count}</span>` : '';
        
        const lastMessage = conv.last_message || 'No messages yet';
        const time = conv.last_message_time ? formatTime(conv.last_message_time) : '';
        const avatar = isGroup ? 'https://academixsuite.com/tenant/assets/images/group-avatar.png' : 
            (conv.other_user_avatar || 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png');
        
        const item = $(`
            <div class="chat-user-item ${currentConversationId === conv.id ? 'active' : ''}" 
                 data-id="${conv.id}" 
                 data-type="${conv.conversation_type}" 
                 data-user-id="${conv.other_user_id}"
                 data-name="${isGroup ? (conv.subject || 'Group') : (conv.other_user_name || 'Unknown')}"
                 data-avatar="${avatar}">
                <div class="chat-user-avatar ${isGroup ? 'group' : ''}">
                    <img src="${avatar}" onerror="this.src='https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'">
                    <span class="online-indicator"></span>
                </div>
                <div class="chat-user-info">
                    <div class="chat-user-name">
                        <span>${isGroup ? conv.subject || 'Group' : (conv.other_user_name || 'Unknown')}</span>
                        <span class="chat-time">${time}</span>
                    </div>
                    <div class="chat-user-status">
                        <i class="${isGroup ? 'ri-group-line' : 'ri-user-line'}"></i>
                        ${isGroup ? 'Group' : (conv.other_user_type || 'User')}
                    </div>
                    <div class="chat-last-message">${escapeHtml(lastMessage)}</div>
                </div>
                ${unreadBadge}
            </div>
        `);

        item.click(() => selectConversation(conv.id, conv.conversation_type));
        container.append(item);
    });
}

function filterConversations(search) {
    const term = search.toLowerCase().trim();
    $('.chat-user-item').each(function() {
        const $this = $(this);
        const name = $this.data('name') || '';
        const lastMsg = $this.find('.chat-last-message').text().toLowerCase() || '';
        $this.toggle(name.toLowerCase().includes(term) || lastMsg.includes(term));
    });
}

function selectConversation(conversationId, type = 'individual') {
    if (currentConversationId === conversationId) return;
    
    currentConversationId = conversationId;
    currentConversationType = type;
    
    $('.chat-user-item').removeClass('active');
    $(`.chat-user-item[data-id="${conversationId}"]`).addClass('active');
    
    $('#noChatSelected').hide();
    $('#chatHeader').show();
    $('#chatInputArea').show();
    
    $('.chat-header').removeClass('scrolled');
    $('.chat-input-area').removeClass('scrolled');
    
    const item = $(`.chat-user-item[data-id="${conversationId}"]`);
    const name = item.data('name') || 'Chat';
    const avatar = item.data('avatar') || 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png';
    
    $('#chatHeaderAvatar').attr('src', avatar);
    $('#chatHeaderName').text(name);
    
    if (type === 'group') {
        $('#groupInfoBtn').show();
        $('#blockUserBtn').hide();
        $('#chatHeaderStatus').text('Group');
    } else {
        $('#groupInfoBtn').hide();
        $('#blockUserBtn').show();
        $('#chatHeaderStatus').html('<span class="online-dot"></span> Online');
    }
    
    loadMessages(conversationId);
    markAsRead(conversationId);
    
    $('#messageInput').val('').trigger('input');
    autoResizeTextarea();
    
    if ($(window).width() <= 768) {
        closeSidebar();
    }
}

function loadMessages(conversationId) {
    if (isLoadingMessages) return;
    isLoadingMessages = true;
    
    $('#messagesArea').html(`
        <div class="empty-state">
            <div class="loading-spinner"></div>
            <p class="mt-3">Loading messages...</p>
        </div>
    `);

    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: {
            action: 'get_messages',
            conversation_id: conversationId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderMessages(response.data);
                if (response.data && response.data.length > 0) {
                    lastMessageId = response.data[response.data.length - 1].id;
                }
            } else {
                $('#messagesArea').html(`
                    <div class="empty-state">
                        <i class="ri-error-warning-line"></i>
                        <h5>Error loading messages</h5>
                        <p>${response.error || 'Unknown error'}</p>
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error loading messages:', error);
            $('#messagesArea').html(`
                <div class="empty-state">
                    <i class="ri-error-warning-line"></i>
                    <h5>Connection Error</h5>
                    <p>Failed to connect to server</p>
                </div>
            `);
        },
        complete: function() {
            isLoadingMessages = false;
        }
    });
}

function renderMessages(messages) {
    const container = $('#messagesArea');
    container.empty();

    if (!messages || messages.length === 0) {
        container.html(`
            <div class="empty-state">
                <i class="ri-chat-1-line"></i>
                <h5>No messages yet</h5>
                <p>Send a message to start the conversation</p>
            </div>
        `);
        return;
    }

    let lastDate = null;
    
    messages.forEach(msg => {
        const msgDate = new Date(msg.created_at).toDateString();
        
        if (lastDate !== msgDate) {
            container.append(`
                <div class="date-divider">
                    <span>${formatDate(msg.created_at)}</span>
                </div>
            `);
            lastDate = msgDate;
        }
        
        container.append(createMessageElement(msg));
    });

    setTimeout(() => {
        forceScrollToBottom();
        handleScrollShadows();
    }, 100);
}

function createMessageElement(msg) {
    const isSent = msg.sender_id == currentUserId;
    
    let attachmentsHtml = '';
    if (msg.attachments && msg.attachments.length > 0) {
        attachmentsHtml = '<div class="message-attachments">';
        msg.attachments.forEach(att => {
            if (att.mime_type?.startsWith('image/')) {
                attachmentsHtml += `
                    <div class="message-attachment" onclick="viewAttachment('${att.file_path}')">
                        <img src="/${att.thumbnail_path || att.file_path}" alt="${att.file_name}">
                        <div class="small mt-1">${att.file_name}</div>
                    </div>
                `;
            } else {
                attachmentsHtml += `
                    <div class="message-attachment" onclick="downloadAttachment('${att.file_path}', '${att.file_name}')">
                        <i class="ri-file-line me-2"></i>
                        <span>${att.file_name}</span>
                        <span class="ms-2 small">(${formatFileSize(att.file_size)})</span>
                    </div>
                `;
            }
        });
        attachmentsHtml += '</div>';
    }

    let reactionsHtml = '';
    if (msg.reactions && msg.reactions.length > 0) {
        const reactionCounts = {};
        msg.reactions.forEach(r => {
            reactionCounts[r.reaction] = (reactionCounts[r.reaction] || 0) + 1;
        });
        
        reactionsHtml = '<div class="message-reactions">';
        for (const [reaction, count] of Object.entries(reactionCounts)) {
            const userReacted = msg.reactions.some(r => r.user_id == currentUserId && r.reaction == reaction);
            reactionsHtml += `
                <span class="reaction ${userReacted ? 'active' : ''}" onclick="addReaction(${msg.id}, '${reaction}')">
                    ${reaction} ${count}
                </span>
            `;
        }
        reactionsHtml += '</div>';
    }

    return `
        <div class="chat-message ${isSent ? 'sent' : 'received'}" data-id="${msg.id}">
            ${!isSent ? `<img src="${msg.sender_avatar || 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'}" class="message-avatar">` : ''}
            <div class="chat-message-content">
                ${!isSent && currentConversationType === 'group' ? 
                    `<small class="text-primary fw-medium d-block mb-1">${escapeHtml(msg.sender_name || 'Unknown')}</small>` : ''}
                <div class="message-bubble">
                    ${msg.reply_to_id ? '<small class="d-block text-secondary mb-1">Replying to a message</small>' : ''}
                    ${escapeHtml(msg.message || '')}
                    ${attachmentsHtml}
                    ${reactionsHtml}
                    <div class="message-actions">
                        <span class="message-action" onclick="replyToMessage(${msg.id}, '${escapeHtml(msg.message || '')}')">
                            <i class="ri-reply-line"></i>
                        </span>
                        <span class="message-action" onclick="showReactionPicker(${msg.id})">
                            <i class="ri-emotion-line"></i>
                        </span>
                        ${isSent ? `
                            <span class="message-action delete" onclick="deleteMessage(${msg.id})">
                                <i class="ri-delete-bin-line"></i>
                            </span>
                        ` : ''}
                    </div>
                </div>
                <div class="message-meta">
                    <span class="message-time">${formatTime(msg.created_at)}</span>
                    ${isSent ? `
                        <span class="message-status">
                            <i class="ri-${msg.is_read ? 'check-double-line' : (msg.is_delivered ? 'check-double-line' : 'check-line')}"></i>
                        </span>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
}

function sendMessage() {
    const message = $('#messageInput').val().trim();
    
    if (!message && selectedFiles.length === 0) return;
    
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('conversation_id', currentConversationId);
    formData.append('message', message);
    
    if (replyToId) {
        formData.append('reply_to_id', replyToId);
    }
    
    selectedFiles.forEach(file => {
        formData.append('attachments[]', file);
    });
    
    $('#sendMessageBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#messageInput').val('').trigger('input');
                autoResizeTextarea();
                
                selectedFiles = [];
                $('#filePreviews').empty();
                replyToId = null;
                $('#replyPreview').hide();
                
                if (response.message) {
                    const msgEl = createMessageElement(response.message);
                    $('#messagesArea').append(msgEl);
                    setTimeout(() => {
                        scrollToBottom();
                        handleScrollShadows();
                    }, 50);
                    lastMessageId = response.message.id;
                }
                
                updateConversationLastMessage(currentConversationId, message);
            } else {
                alert('Failed to send message: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error sending message:', error);
            alert('Network error. Please try again.');
        },
        complete: function() {
            $('#sendMessageBtn').prop('disabled', false).html('<i class="ri-send-plane-fill"></i>');
        }
    });
}

function updateConversationLastMessage(conversationId, message) {
    const convItem = $(`.chat-user-item[data-id="${conversationId}"]`);
    if (convItem.length) {
        convItem.find('.chat-last-message').text(escapeHtml(message.substring(0, 50) + (message.length > 50 ? '...' : '')));
        convItem.find('.chat-time').text('Now');
    }
}

function handleFileSelect(files) {
    const previews = $('#filePreviews');
    previews.empty();
    
    for (let file of files) {
        if (file.size > 10 * 1024 * 1024) {
            alert('File too large. Max size: 10MB');
            continue;
        }
        
        selectedFiles.push(file);
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previews.append(`
                <div class="file-preview" data-filename="${file.name}">
                    ${file.type.startsWith('image/') ? 
                        `<img src="${e.target.result}">` : 
                        `<i class="ri-file-line" style="font-size: 24px;"></i>`}
                    <div class="file-preview-info">
                        <div class="file-preview-name">${file.name}</div>
                        <div class="file-preview-size">${formatFileSize(file.size)}</div>
                    </div>
                    <i class="ri-close-line file-preview-remove" onclick="removeFile('${file.name}')"></i>
                </div>
            `);
        };
        reader.readAsDataURL(file);
    }
    
    $('#sendMessageBtn').prop('disabled', !$('#messageInput').val().trim() && selectedFiles.length === 0);
}

function removeFile(fileName) {
    selectedFiles = selectedFiles.filter(f => f.name !== fileName);
    $(`.file-preview[data-filename="${fileName}"]`).fadeOut(300, function() {
        $(this).remove();
    });
    $('#sendMessageBtn').prop('disabled', !$('#messageInput').val().trim() && selectedFiles.length === 0);
}

$('#fileInput').change(function(e) {
    handleFileSelect(e.target.files);
});

function replyToMessage(messageId, messageText) {
    replyToId = messageId;
    $('#replyMessageText').text(messageText.substring(0, 50) + (messageText.length > 50 ? '...' : ''));
    $('#replyPreview').show();
    $('#messageInput').focus();
}

function cancelReply() {
    replyToId = null;
    $('#replyPreview').hide();
}

function addReaction(messageId, reaction) {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'add_reaction',
            message_id: messageId,
            reaction: reaction
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                loadMessages(currentConversationId);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error adding reaction:', error);
        }
    });
}

function showReactionPicker(messageId) {
    const reactions = ['👍', '❤️', '😂', '😮', '😢', '😡'];
    const picker = $(`
        <div class="bg-white p-2 rounded shadow" style="position: absolute; z-index: 1000;">
            ${reactions.map(r => `<span style="font-size: 20px; cursor: pointer; margin: 0 4px;" onclick="addReaction(${messageId}, '${r}')">${r}</span>`).join('')}
        </div>
    `);
    
    const msg = $(`.chat-message[data-id="${messageId}"]`);
    msg.append(picker);
    setTimeout(() => picker.remove(), 3000);
}

function deleteMessage(messageId) {
    if (!confirm('Are you sure you want to delete this message?')) return;
    
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'delete_message',
            message_id: messageId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $(`.chat-message[data-id="${messageId}"]`).fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                alert('Failed to delete message: ' + response.error);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error deleting message:', error);
        }
    });
}

function openNewChat() {
    $('#newChatModal').modal('show');
    $('#usersList').html(`
        <div class="empty-state">
            <div class="loading-spinner"></div>
            <p class="mt-3">Loading users...</p>
        </div>
    `);
    setTimeout(() => {
        searchUsers('', 'users');
    }, 100);
}

function searchUsers(search, target = 'users') {
    const container = target === 'users' ? '#usersList' : '#usersForGroupList';
    $(container).html(`
        <div class="empty-state">
            <div class="loading-spinner"></div>
            <p class="mt-3">Searching...</p>
        </div>
    `);

    search = search || '';

    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: {
            action: 'get_users',
            search: search
        },
        dataType: 'json',
        timeout: 10000,
        success: function(response) {
            if (response.success) {
                renderUsers(response.data, target);
            } else {
                $(container).html(`
                    <div class="empty-state">
                        <i class="ri-error-warning-line"></i>
                        <p>Error: ${response.error || 'Unknown error'}</p>
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error loading users:', error);
            $(container).html(`
                <div class="empty-state">
                    <i class="ri-error-warning-line"></i>
                    <p>Connection error. Please try again.</p>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="searchUsers('${search}', '${target}')">
                        <i class="ri-refresh-line"></i> Retry
                    </button>
                </div>
            `);
        }
    });
}

function renderUsers(users, target) {
    const container = target === 'users' ? '#usersList' : '#usersForGroupList';
    $(container).empty();

    if (!users || users.length === 0) {
        $(container).html(`
            <div class="empty-state">
                <i class="ri-user-search-line"></i>
                <p>No users found</p>
                <p class="small text-muted">Try a different search term</p>
            </div>
        `);
        return;
    }

    users.forEach(user => {
        const userName = user.name || 'Unknown User';
        const userType = user.user_type || 'user';
        const userAvatar = user.profile_photo || 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png';
        const additionalInfo = user.additional_info || '';
        
        const item = $(`
            <div class="user-select-item" data-id="${user.id}" data-type="${userType}">
                <img src="${userAvatar}" 
                     class="user-select-avatar" 
                     onerror="this.src='https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'">
                <div class="user-select-info">
                    <div class="user-select-name">${escapeHtml(userName)}</div>
                    <div class="user-select-type">${escapeHtml(userType)} ${escapeHtml(additionalInfo)}</div>
                </div>
                ${target === 'users' ? '' : '<div class="user-select-check"><i class="ri-check-line"></i></div>'}
            </div>
        `);

        if (target === 'users') {
            item.click(() => {
                startConversation(user.id, userType);
                $('#newChatModal').modal('hide');
            });
        } else {
            item.click(() => toggleUserSelection(user.id, userName));
        }

        $(container).append(item);
    });
}

function startConversation(userId, userType) {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'start_conversation',
            user_id: userId,
            user_type: userType
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                selectConversation(response.conversation_id, 'individual');
                loadConversations();
            } else {
                alert('Failed to start conversation: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error starting conversation:', error);
            alert('Network error. Please try again.');
        }
    });
}

function openCreateGroup() {
    selectedUsers.clear();
    $('#selectedUsers').empty();
    $('#groupName').val('');
    $('#createGroupBtn').prop('disabled', true);
    $('#createGroupModal').modal('show');
    searchUsers('', 'groupUsers');
}

function toggleUserSelection(userId, userName) {
    if (selectedUsers.has(userId)) {
        selectedUsers.delete(userId);
        $(`.selected-user-tag[data-id="${userId}"]`).remove();
        $(`.user-select-item[data-id="${userId}"]`).removeClass('selected');
    } else {
        selectedUsers.add(userId);
        $(`.user-select-item[data-id="${userId}"]`).addClass('selected');
        $('#selectedUsers').append(`
            <span class="selected-user-tag" data-id="${userId}">
                ${escapeHtml(userName)}
                <i class="ri-close-line" onclick="toggleUserSelection(${userId}, '${escapeHtml(userName)}')"></i>
            </span>
        `);
    }
    
    $('#createGroupBtn').prop('disabled', selectedUsers.size < 2 || !$('#groupName').val().trim());
}

function createGroup() {
    const name = $('#groupName').val().trim();
    const members = Array.from(selectedUsers);
    
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'create_group',
            name: name,
            members: JSON.stringify(members)
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#createGroupModal').modal('hide');
                selectConversation(response.conversation_id, 'group');
                loadConversations();
            } else {
                alert('Failed to create group: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error creating group:', error);
            alert('Network error. Please try again.');
        }
    });
}

function viewGroupInfo() {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: {
            action: 'get_group_info',
            conversation_id: currentConversationId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#groupInfoAvatar').attr('src', response.data.avatar || 'https://academixsuite.com/tenant/assets/images/group-avatar.png');
                $('#groupInfoName').text(response.data.name);
                $('#groupInfoMembers').text(`${response.data.members.length} members`);
                
                const list = $('#groupMembersList');
                list.empty();
                
                response.data.members.forEach(member => {
                    list.append(`
                        <div class="user-select-item">
                            <img src="${member.avatar || 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'}" 
                                 class="user-select-avatar" onerror="this.src='https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'">
                            <div class="user-select-info">
                                <div class="user-select-name">${escapeHtml(member.name)}</div>
                                <div class="user-select-type">${member.role}</div>
                            </div>
                            ${member.id == currentUserId ? '<span class="badge bg-primary">You</span>' : ''}
                        </div>
                    `);
                });
                
                $('#groupInfoModal').modal('show');
            } else {
                alert('Failed to load group info: ' + response.error);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading group info:', error);
        }
    });
}

function openSearchInChat() {
    $('#searchInChat').val('');
    $('#searchResults').empty();
    $('#searchInChatModal').modal('show');
}

function searchMessages(query) {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: {
            action: 'search_messages',
            query: query,
            conversation_id: currentConversationId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderSearchResults(response.data);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error searching messages:', error);
        }
    });
}

function renderSearchResults(results) {
    const container = $('#searchResults');
    container.empty();
    
    if (!results || results.length === 0) {
        container.html('<div class="empty-state"><p>No messages found</p></div>');
        return;
    }
    
    results.forEach(msg => {
        container.append(`
            <div class="user-select-item" onclick="jumpToMessage(${msg.id})">
                <div class="user-select-info">
                    <div class="user-select-name">${escapeHtml(msg.sender_name)}</div>
                    <div class="user-select-type">${formatTime(msg.created_at)}</div>
                    <div class="small mt-1">${escapeHtml(msg.message.substring(0, 100))}${msg.message.length > 100 ? '...' : ''}</div>
                </div>
            </div>
        `);
    });
}

function jumpToMessage(messageId) {
    const msgElement = $(`.chat-message[data-id="${messageId}"]`);
    if (msgElement.length) {
        $('#searchInChatModal').modal('hide');
        msgElement.css('background', 'rgba(37, 161, 148, 0.1)');
        setTimeout(() => msgElement.css('background', ''), 2000);
        msgElement[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function sendTypingIndicator(isTyping) {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'typing_indicator',
            conversation_id: currentConversationId,
            is_typing: isTyping
        },
        dataType: 'json'
    });
}

function checkNewMessages() {
    if (!currentConversationId) return;
    
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: {
            action: 'get_messages',
            conversation_id: currentConversationId,
            after_id: lastMessageId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data && response.data.length > 0) {
                const wasAtBottom = isAtBottom();
                
                response.data.forEach(msg => {
                    const msgEl = createMessageElement(msg);
                    $('#messagesArea').append(msgEl);
                    if (msg.id > lastMessageId) {
                        lastMessageId = msg.id;
                    }
                });
                
                if (wasAtBottom) {
                    setTimeout(() => {
                        scrollToBottom();
                    }, 50);
                }
                
                handleScrollShadows();
                markAsRead(currentConversationId);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error checking new messages:', error);
        }
    });
}

function markAsRead(conversationId) {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'mark_read',
            conversation_id: conversationId
        },
        dataType: 'json'
    });
}

function updateUnreadCount() {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'GET',
        data: {
            action: 'get_unread_count'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const count = response.count || 0;
                if (count > 0) {
                    $('.has-indicator span').show();
                    $('.has-indicator + .dropdown-menu span:first').text(count);
                } else {
                    $('.has-indicator span').hide();
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('Error updating unread count:', error);
        }
    });
}

function openEmojiPicker() {
    $('#emojiPicker').toggleClass('show');
}

function addEmoji(emoji) {
    const input = $('#messageInput');
    input.val(input.val() + emoji).trigger('input');
    $('#emojiPicker').removeClass('show');
}

function toggleMute() {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'mute_conversation',
            conversation_id: currentConversationId
        },
        dataType: 'json',
        success: function(response) {
            alert('Notifications muted');
        },
        error: function(xhr, status, error) {
            console.error('Error muting conversation:', error);
        }
    });
}

function toggleArchive() {
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'archive_conversation',
            conversation_id: currentConversationId
        },
        dataType: 'json',
        success: function(response) {
            $('#chatHeader').hide();
            $('#chatInputArea').hide();
            $('#noChatSelected').show();
            currentConversationId = null;
            loadConversations();
        },
        error: function(xhr, status, error) {
            console.error('Error archiving conversation:', error);
        }
    });
}

function blockUser() {
    const userId = $(`.chat-user-item.active`).data('user-id');
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'block_user',
            user_id: userId
        },
        dataType: 'json',
        success: function(response) {
            alert(response.unblocked ? 'User unblocked' : 'User blocked');
        },
        error: function(xhr, status, error) {
            console.error('Error blocking user:', error);
        }
    });
}

function deleteChat() {
    if (!confirm('Are you sure you want to delete this chat?')) return;
    
    $.ajax({
        url: 'ajax/messenger.php',
        type: 'POST',
        data: {
            action: 'delete_conversation',
            conversation_id: currentConversationId
        },
        dataType: 'json',
        success: function(response) {
            $('#chatHeader').hide();
            $('#chatInputArea').hide();
            $('#noChatSelected').show();
            currentConversationId = null;
            loadConversations();
        },
        error: function(xhr, status, error) {
            console.error('Error deleting chat:', error);
        }
    });
}

function formatTime(timestamp) {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    const now = new Date();
    
    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function formatDate(timestamp) {
    const date = new Date(timestamp);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    
    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    } else if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }
    return date.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function viewAttachment(path) {
    window.open('/' + path, '_blank');
}

function downloadAttachment(path, filename) {
    const a = document.createElement('a');
    a.href = '/' + path;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

$(window).on('beforeunload', function() {
    if (checkNewMessagesInterval) {
        clearInterval(checkNewMessagesInterval);
    }
});
</script>
</body>
</html>