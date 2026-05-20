<?php
/**
 * AJAX Handler for Messenger
 * Location: /tenant/{school-slug}/admin/ajax/messenger.php
 */

// Enable logging without leaking PHP warnings into JSON responses.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log("===== AJAX Request Started =====");

if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    $sessionConfig = __DIR__ . '/../../../../includes/session_config.php';
    if (is_file($sessionConfig)) {
        require_once $sessionConfig;
        session_start(academix_session_options());
    } else {
        session_start();
    }
}

// Correct paths for includes
require_once __DIR__ . '/../../../../includes/autoload.php';

// Initialize response
$response = ['success' => false, 'error' => 'Invalid request'];
header('Content-Type: application/json');

// Log the request
error_log("Action: " . ($_GET['action'] ?? $_POST['action'] ?? 'none'));
error_log("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
error_log("POST data: " . print_r($_POST, true));
error_log("GET data: " . print_r($_GET, true));
if (!empty($_FILES)) {
    error_log("FILES data: " . print_r($_FILES, true));
}

// Check authentication
$schoolAuth = $_SESSION['school_auth'] ?? [];
if (!is_array($schoolAuth) || empty($schoolAuth['school_slug'])) {
    $response['error'] = 'Not authenticated';
    error_log("Authentication failed - no school_auth or school_info in session");
    echo json_encode($response);
    exit;
}

// Get school and user info
$schoolSlug = $schoolAuth['school_slug'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';
$schoolInfo = $_SESSION['school_info'][$schoolSlug] ?? [
    'id' => $schoolAuth['school_id'] ?? 0,
    'database_name' => $schoolAuth['database_name'] ?? '',
];

if (!in_array($userType, ['admin', 'teacher', 'student', 'parent', 'staff', 'accountant', 'librarian', 'receptionist'], true)) {
    $response['error'] = 'Unauthorized';
    echo json_encode($response);
    exit;
}

error_log("User: $userId, Type: $userType, School: " . ($schoolInfo['database_name'] ?? 'unknown'));

// Connect to school database
try {
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    $schoolDb = Database::getSchoolConnection($schoolInfo['database_name']);
    error_log("Database connection successful");
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $response['error'] = 'Database connection failed';
    echo json_encode($response);
    exit;
}

// Initialize MessengerManager
require_once __DIR__ . '/../../../../includes/MessengerManager.php';
$messenger = new MessengerManager($schoolDb, $schoolInfo['id'], $userId, $userType);

// Get action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    
    case 'get_conversations':
    // Get and validate page parameter
    $page = isset($_GET['page']) ? $_GET['page'] : 1;
    
    // Log what we received
    error_log("get_conversations - raw page value: " . var_export($page, true));
    error_log("get_conversations - page type: " . gettype($page));
    
    // Ensure it's an integer
    if (!is_numeric($page)) {
        error_log("get_conversations - page is not numeric, resetting to 1");
        $page = 1;
    } else {
        $page = (int)$page;
    }
    
    if ($page < 1) $page = 1;
    
    error_log("get_conversations - final page value: " . $page . " (type: " . gettype($page) . ")");
    
    $conversations = $messenger->getConversations($page);
    $response = ['success' => true, 'data' => $conversations];
    error_log("get_conversations returned " . count($conversations) . " conversations");
    break;
        
    case 'get_messages':
        $conversationId = $_GET['conversation_id'] ?? 0;
        $afterId = $_GET['after_id'] ?? null;
        $beforeId = $_GET['before_id'] ?? null;
        
        if (!$conversationId) {
            $response = ['success' => false, 'error' => 'Conversation ID required'];
            break;
        }
        
        $messages = $messenger->getMessages($conversationId, $afterId, $beforeId);
        
        if (isset($messages['error'])) {
            $response = ['success' => false, 'error' => $messages['error']];
        } else {
            $response = ['success' => true, 'data' => $messages];
        }
        error_log("get_messages returned " . (is_array($messages) ? count($messages) : 0) . " messages");
        break;
        
    case 'get_users':
        $search = $_GET['search'] ?? '';
        $page = $_GET['page'] ?? 1;
        $users = $messenger->getAvailableUsers($search, $page);
        $response = ['success' => true, 'data' => $users];
        error_log("get_users returned " . count($users) . " users");
        break;
        
    case 'start_conversation':
        $otherUserId = $_POST['user_id'] ?? 0;
        $otherUserType = $_POST['user_type'] ?? '';
        
        error_log("Starting conversation with user $otherUserId of type $otherUserType");
        
        if (!$otherUserId || !$otherUserType) {
            $response = ['success' => false, 'error' => 'Invalid user'];
            break;
        }
        
        $conversationId = $messenger->getOrCreateConversation($otherUserId, $otherUserType);
        
        if ($conversationId) {
            $messages = $messenger->getMessages($conversationId);
            $response = ['success' => true, 'conversation_id' => $conversationId, 'messages' => $messages];
            error_log("Conversation created/retrieved with ID: $conversationId");
        } else {
            $response = ['success' => false, 'error' => 'Failed to start conversation'];
        }
        break;
        
    case 'create_group':
        $name = $_POST['name'] ?? '';
        $members = $_POST['members'] ?? [];
        
        if (is_string($members)) {
            $members = json_decode($members, true);
        }
        
        error_log("Creating group: $name with members: " . implode(',', $members));
        
        if (empty($name) || empty($members) || count($members) < 2) {
            $response = ['success' => false, 'error' => 'Invalid group data'];
            break;
        }
        
        $conversationId = $messenger->createGroup($name, $members);
        
        if ($conversationId) {
            $messages = $messenger->getMessages($conversationId);
            $response = ['success' => true, 'conversation_id' => $conversationId, 'messages' => $messages];
            error_log("Group created with ID: $conversationId");
        } else {
            $response = ['success' => false, 'error' => 'Failed to create group'];
        }
        break;
        
    case 'get_group_info':
        $conversationId = $_GET['conversation_id'] ?? 0;
        $info = $messenger->getGroupInfo($conversationId);
        
        if ($info) {
            $response = ['success' => true, 'data' => $info];
        } else {
            $response = ['success' => false, 'error' => 'Failed to get group info'];
        }
        break;
        
    case 'send_message':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $message = $_POST['message'] ?? '';
        $replyToId = $_POST['reply_to_id'] ?? null;
        $messageType = $_POST['message_type'] ?? 'text';
        
        // Handle file uploads
        $files = [];
        if (!empty($_FILES['attachments'])) {
            $files = $_FILES['attachments'];
            error_log("Files uploaded: " . count($files['name']));
        }
        
        if (empty($message) && empty($files)) {
            $response = ['success' => false, 'error' => 'Message cannot be empty'];
            break;
        }
        
        $result = $messenger->sendMessage($conversationId, $message, $messageType, $replyToId, $files);
        $response = $result;
        break;
        
    case 'mark_read':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $messageId = $_POST['message_id'] ?? null;
        $result = $messenger->markAsRead($conversationId, $messageId);
        $response = ['success' => $result];
        break;
        
    case 'add_reaction':
        $messageId = $_POST['message_id'] ?? 0;
        $reaction = $_POST['reaction'] ?? '';
        $result = $messenger->addReaction($messageId, $reaction);
        $response = $result;
        break;
        
    case 'delete_message':
        $messageId = $_POST['message_id'] ?? 0;
        $result = $messenger->deleteMessage($messageId);
        $response = $result;
        break;
        
    case 'delete_conversation':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $result = $messenger->deleteConversation($conversationId);
        $response = ['success' => $result];
        break;
        
    case 'get_unread_count':
        $count = $messenger->getUnreadCount();
        $response = ['success' => true, 'count' => $count];
        break;
        
    case 'archive_conversation':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $result = $messenger->archiveConversation($conversationId);
        $response = ['success' => $result];
        break;
        
    case 'mute_conversation':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $result = $messenger->muteConversation($conversationId);
        $response = ['success' => $result];
        break;
        
    case 'unmute_conversation':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $result = $messenger->unmuteConversation($conversationId);
        $response = ['success' => $result];
        break;
        
    case 'block_user':
        $blockedUserId = $_POST['user_id'] ?? 0;
        $result = $messenger->blockUser($blockedUserId);
        $response = $result;
        break;
        
    case 'save_draft':
        $recipientId = $_POST['recipient_id'] ?? 0;
        $recipientType = $_POST['recipient_type'] ?? '';
        $message = $_POST['message'] ?? '';
        $result = $messenger->saveDraft($recipientId, $recipientType, $message);
        $response = ['success' => $result];
        break;
        
    case 'get_draft':
        $recipientId = $_GET['recipient_id'] ?? 0;
        $draft = $messenger->getDraft($recipientId);
        $response = ['success' => true, 'data' => $draft];
        break;
        
    case 'search_messages':
        $query = $_GET['query'] ?? '';
        $conversationId = $_GET['conversation_id'] ?? null;
        $results = $messenger->searchMessages($query, $conversationId);
        $response = ['success' => true, 'data' => $results];
        break;
        
    case 'typing_indicator':
        $conversationId = $_POST['conversation_id'] ?? 0;
        $isTyping = $_POST['is_typing'] ?? false;
        $result = $messenger->sendTypingIndicator($conversationId, $isTyping);
        $response = ['success' => $result];
        break;
        
    default:
        $response = ['success' => false, 'error' => 'Unknown action: ' . $action];
        error_log("Unknown action: $action");
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
error_log("Response sent: " . json_encode($response));
error_log("===== AJAX Request Ended =====");
