<?php
/**
 * Messenger Manager - Handles all messaging functionality
 * Location: /includes/MessengerManager.php
 */

class MessengerManager {
    private $schoolDb;
    private $schoolId;
    private $userId;
    private $userType;
    private $uploadDir;
    private $allowedFileTypes = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz']
    ];
    private $maxFileSize = 10485760; // 10MB

    public function __construct($schoolDb, $schoolId, $userId, $userType) {
        $this->schoolDb = $schoolDb;
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->userType = $userType;
        
        // Set upload directory
        $this->uploadDir = __DIR__ . '/../uploads/messages/' . $schoolId . '/';
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
        
        // Create thumbnails directory
        $thumbDir = $this->uploadDir . 'thumbnails/';
        if (!file_exists($thumbDir)) {
            mkdir($thumbDir, 0777, true);
        }
    }

/**
 * Get all conversations for current user
 */
public function getConversations($page = 1, $limit = 20) {
    // Cast to integers to prevent string operations
    $page = (int)$page;
    $limit = (int)$limit;
    
    // Ensure valid values
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 20;
    
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT 
                c.*,
                cp.last_read_at,
                cp.is_muted,
                cp.is_archived as user_archived,
                u.id as other_user_id,
                u.name as other_user_name,
                u.profile_photo as other_user_avatar,
                u.user_type as other_user_type,
                m.message as last_message,
                m.created_at as last_message_time,
                m.sender_id as last_message_sender,
                m.message_type as last_message_type,
                (SELECT COUNT(*) FROM messages msg 
                 WHERE msg.conversation_id = c.id 
                 AND msg.created_at > cp.last_read_at 
                 AND msg.sender_id != ?) as unread_count
            FROM conversations c
            INNER JOIN conversation_participants cp ON c.id = cp.conversation_id
            INNER JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id != ?
            INNER JOIN users u ON cp2.user_id = u.id
            LEFT JOIN messages m ON c.last_message_id = m.id
            WHERE cp.user_id = ? 
            AND cp.is_deleted = 0
            ORDER BY c.last_message_at DESC
            LIMIT ? OFFSET ?";
    
    try {
        $stmt = $this->schoolDb->prepare($sql);
        
        // Cast all parameters to integers to prevent type mismatch
        $params = [
            (int)$this->userId,
            (int)$this->userId,
            (int)$this->userId,
            (int)$limit,
            (int)$offset
        ];
        
        error_log("Executing getConversations with params: " . implode(', ', $params));
        
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("getConversations found " . count($results) . " conversations");
        return $results;
        
    } catch (Exception $e) {
        error_log("Error in getConversations: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [];
    }
}
    /**
     * Get or create individual conversation with another user
     */
    public function getOrCreateConversation($otherUserId, $otherUserType) {
        // Check if conversation already exists
        $sql = "SELECT c.id 
                FROM conversations c
                INNER JOIN conversation_participants cp1 ON c.id = cp1.conversation_id
                INNER JOIN conversation_participants cp2 ON c.id = cp2.conversation_id
                WHERE cp1.user_id = ? 
                AND cp2.user_id = ?
                AND c.conversation_type = 'individual'";
        
        try {
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$this->userId, $otherUserId]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($conversation) {
                return $conversation['id'];
            }
            
            // Create new conversation
            $this->schoolDb->beginTransaction();
            
            // Insert conversation
            $sql = "INSERT INTO conversations (conversation_type, created_by, last_message_at) 
                    VALUES ('individual', ?, NOW())";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$this->userId]);
            $conversationId = $this->schoolDb->lastInsertId();
            
            // Add participants
            $sql = "INSERT INTO conversation_participants (conversation_id, user_id, user_type, last_read_at) 
                    VALUES (?, ?, ?, NOW()), (?, ?, ?, NULL)";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([
                $conversationId, $this->userId, $this->userType,
                $conversationId, $otherUserId, $otherUserType
            ]);
            
            $this->schoolDb->commit();
            return $conversationId;
            
        } catch (Exception $e) {
            $this->schoolDb->rollBack();
            error_log("Error creating conversation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a group conversation
     */
    public function createGroup($name, $memberIds) {
        if (empty($name) || count($memberIds) < 2) {
            return false;
        }
        
        // Add current user to members
        $allMembers = array_unique(array_merge([$this->userId], $memberIds));
        
        $this->schoolDb->beginTransaction();
        
        try {
            // Insert group conversation
            $sql = "INSERT INTO conversations (conversation_type, subject, created_by, last_message_at) 
                    VALUES ('group', ?, ?, NOW())";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$name, $this->userId]);
            $conversationId = $this->schoolDb->lastInsertId();
            
            // Add all participants
            $sql = "INSERT INTO conversation_participants (conversation_id, user_id, user_type, last_read_at) VALUES ";
            $values = [];
            $params = [];
            
            foreach ($allMembers as $index => $memberId) {
                // Get user type for each member
                $userStmt = $this->schoolDb->prepare("SELECT user_type FROM users WHERE id = ?");
                $userStmt->execute([$memberId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($index > 0) $sql .= ",";
                $sql .= " (?, ?, ?, ?)";
                
                $params[] = $conversationId;
                $params[] = $memberId;
                $params[] = $user['user_type'];
                $params[] = ($memberId == $this->userId) ? date('Y-m-d H:i:s') : null;
            }
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);
            
            // Add system message about group creation
            $systemMessage = "Group created by " . $this->getUserName($this->userId);
            $this->sendSystemMessage($conversationId, $systemMessage);
            
            $this->schoolDb->commit();
            return $conversationId;
            
        } catch (Exception $e) {
            $this->schoolDb->rollBack();
            error_log("Error creating group: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get group information
     */
    public function getGroupInfo($conversationId) {
        // Verify user is a member
        if (!$this->canAccessConversation($conversationId)) {
            return false;
        }
        
        try {
            // Get conversation details
            $sql = "SELECT * FROM conversations WHERE id = ? AND conversation_type = 'group'";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$conversation) {
                return false;
            }
            
            // Get all members
            $sql = "SELECT 
                        cp.user_id as id,
                        u.name,
                        u.profile_photo as avatar,
                        u.user_type as role,
                        cp.joined_at,
                        cp.last_read_at
                    FROM conversation_participants cp
                    INNER JOIN users u ON cp.user_id = u.id
                    WHERE cp.conversation_id = ?
                    ORDER BY cp.joined_at ASC";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'id' => $conversation['id'],
                'name' => $conversation['subject'],
                'avatar' => null,
                'created_by' => $conversation['created_by'],
                'created_at' => $conversation['created_at'],
                'members' => $members,
                'member_count' => count($members)
            ];
            
        } catch (Exception $e) {
            error_log("Error getting group info: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages($conversationId, $afterId = null, $beforeId = null, $limit = 50) {
        // Cast pagination/id args to ints — PHP 8 throws TypeError on
        // "string - int" so any caller passing a stringified value crashes
        // the request (see error_log: MessengerManager.php:653/630/628/47/45).
        $conversationId = (int) $conversationId;
        $afterId  = $afterId  !== null ? (int) $afterId  : null;
        $beforeId = $beforeId !== null ? (int) $beforeId : null;
        $limit    = max(1, min(200, (int) $limit));

        // Verify user has access to this conversation
        if (!$this->canAccessConversation($conversationId)) {
            return ['error' => 'Access denied'];
        }
        
        $sql = "SELECT 
                    m.*,
                    u.name as sender_name,
                    u.profile_photo as sender_avatar,
                    u.user_type as sender_type
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? 
                AND m.is_deleted = 0";
        
        $params = [$conversationId];
        
        if ($afterId) {
            $sql .= " AND m.id > ?";
            $params[] = $afterId;
        } elseif ($beforeId) {
            $sql .= " AND m.id < ?";
            $params[] = $beforeId;
        }
        
        $sql .= " ORDER BY m.created_at ASC";
        
        if (!$afterId && !$beforeId) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }
        
        try {
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark messages as delivered
            $this->markMessagesAsDelivered($conversationId);
            
            // Get attachments and reactions for each message
            foreach ($messages as &$msg) {
                $msg['attachments'] = $this->getMessageAttachments($msg['id']);
                $msg['reactions'] = $this->getMessageReactions($msg['id']);
            }
            
            return $messages;
            
        } catch (Exception $e) {
            error_log("Error in getMessages: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get attachments for a message
     */
    private function getMessageAttachments($messageId) {
        try {
            $sql = "SELECT * FROM message_attachments WHERE message_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get reactions for a message
     */
    private function getMessageReactions($messageId) {
        try {
            $sql = "SELECT mr.*, u.name as user_name 
                    FROM message_reactions mr
                    LEFT JOIN users u ON mr.user_id = u.id
                    WHERE mr.message_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Send a new message
     */
    public function sendMessage($conversationId, $message, $messageType = 'text', $replyToId = null, $files = []) {
        // Verify access
        if (!$this->canAccessConversation($conversationId)) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Check if user is blocked
        if ($this->isBlocked($conversationId)) {
            return ['success' => false, 'error' => 'You cannot send messages to this user'];
        }
        
        $this->schoolDb->beginTransaction();
        
        try {
            // Insert message
            $sql = "INSERT INTO messages (conversation_id, sender_id, sender_type, message_type, message, reply_to_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([
                $conversationId, 
                $this->userId, 
                $this->userType, 
                $messageType, 
                $message, 
                $replyToId
            ]);
            
            $messageId = $this->schoolDb->lastInsertId();
            
            // Handle file attachments
            $attachments = [];
            if (!empty($files) && isset($files['tmp_name']) && !empty($files['tmp_name'][0])) {
                $attachments = $this->uploadAttachments($messageId, $files);
            }
            
            // Update conversation last message
            $sql = "UPDATE conversations SET last_message_id = ?, last_message_at = NOW() WHERE id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId, $conversationId]);
            
            // Create message status records for all participants
            $sql = "INSERT INTO message_status (message_id, user_id, status, status_changed_at)
                    SELECT ?, user_id, 'sent', NOW()
                    FROM conversation_participants
                    WHERE conversation_id = ? AND user_id != ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId, $conversationId, $this->userId]);
            
            $this->schoolDb->commit();
            
            // Get the full message data to return
            $messageData = $this->getMessageById($messageId);
            $messageData['attachments'] = $attachments;
            
            return [
                'success' => true,
                'message' => $messageData
            ];
            
        } catch (Exception $e) {
            $this->schoolDb->rollBack();
            error_log("Error sending message: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to send message'];
        }
    }

    /**
     * Send a system message (for group events)
     */
    private function sendSystemMessage($conversationId, $message) {
        try {
            $sql = "INSERT INTO messages (conversation_id, sender_id, sender_type, message_type, message, created_at) 
                    VALUES (?, 0, 'system', 'system', ?, NOW())";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId, $message]);
            
            $messageId = $this->schoolDb->lastInsertId();
            
            $sql = "UPDATE conversations SET last_message_id = ?, last_message_at = NOW() WHERE id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId, $conversationId]);
            
            return true;
        } catch (Exception $e) {
            error_log("Error sending system message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload file attachments
     */
    private function uploadAttachments($messageId, $files) {
        $uploaded = [];
        
        // Handle single file or multiple files
        if (!is_array($files['tmp_name'])) {
            $files['tmp_name'] = [$files['tmp_name']];
            $files['name'] = [$files['name']];
            $files['size'] = [$files['size']];
            $files['type'] = [$files['type']];
            $files['error'] = [$files['error']];
        }
        
        foreach ($files['tmp_name'] as $key => $tmpName) {
            if ($files['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $fileName = $files['name'][$key];
            $fileSize = $files['size'][$key];
            $fileTmp = $tmpName;
            
            // Validate file size
            if ($fileSize > $this->maxFileSize) {
                continue;
            }
            
            // SECURITY: validate by actual content (MIME) and only ACCEPT the
            // extension if it matches the allow-list. We never trust the
            // user-supplied filename — without this, an attacker could rename
            // a payload to evil.svg / evil.php and slip past the check.
            $detectedMime = function_exists('finfo_open')
                ? (function () use ($fileTmp) {
                    $fi = finfo_open(FILEINFO_MIME_TYPE);
                    $m = $fi ? finfo_file($fi, $fileTmp) : null;
                    if ($fi) finfo_close($fi);
                    return $m ?: '';
                })()
                : (function_exists('mime_content_type') ? (mime_content_type($fileTmp) ?: '') : '');

            $mimeToExt = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'text/plain' => 'txt',
                'text/csv' => 'csv',
            ];

            if (!isset($mimeToExt[$detectedMime])) {
                continue;
            }
            $ext = $mimeToExt[$detectedMime];

            // Cross-check the resolved extension against allowedFileTypes
            // so a class-narrowing (e.g. images only in some flows) still works.
            $isValid = false;
            $fileType = 'document';
            foreach ($this->allowedFileTypes as $type => $extensions) {
                if (in_array($ext, $extensions)) {
                    $isValid = true;
                    $fileType = $type;
                    break;
                }
            }
            if (!$isValid) {
                continue;
            }

            // Generate unique filename using the MIME-derived extension
            $newFileName = bin2hex(random_bytes(10)) . '_' . time() . '.' . $ext;
            $relativePath = 'uploads/messages/' . $this->schoolId . '/' . $newFileName;
            $filePath = __DIR__ . '/../' . $relativePath;
            
            // Move file
            if (move_uploaded_file($fileTmp, $filePath)) {
                // Create thumbnail for images
                $thumbnailPath = null;
                if ($fileType === 'image' && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $thumbnailPath = $this->createThumbnail($filePath, $newFileName);
                }
                
                // Get dimensions for images
                $dimensions = null;
                if ($fileType === 'image' && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    list($width, $height) = getimagesize($filePath);
                    $dimensions = $width . 'x' . $height;
                }
                
                // Insert attachment record
                $sql = "INSERT INTO message_attachments 
                        (message_id, file_name, file_path, file_size, mime_type, file_extension, thumbnail_path, dimensions)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([
                    $messageId,
                    $fileName,
                    $relativePath,
                    $fileSize,
                    $files['type'][$key],
                    $ext,
                    $thumbnailPath,
                    $dimensions
                ]);
                
                $uploaded[] = [
                    'id' => $this->schoolDb->lastInsertId(),
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'mime_type' => $files['type'][$key],
                    'file_path' => $relativePath,
                    'thumbnail_path' => $thumbnailPath
                ];
            }
        }
        
        return $uploaded;
    }

    /**
     * Create thumbnail for image
     */
    private function createThumbnail($filePath, $fileName) {
        $thumbDir = __DIR__ . '/../uploads/messages/' . $this->schoolId . '/thumbnails/';
        $thumbPath = $thumbDir . 'thumb_' . $fileName;
        $relativeThumbPath = 'uploads/messages/' . $this->schoolId . '/thumbnails/thumb_' . $fileName;
        
        list($width, $height) = getimagesize($filePath);
        $newWidth = 200;
        $newHeight = floor($height * ($newWidth / $width));
        
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $source = imagecreatefromjpeg($filePath);
                imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagejpeg($thumb, $thumbPath, 80);
                break;
            case 'png':
                $source = imagecreatefrompng($filePath);
                imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagepng($thumb, $thumbPath, 8);
                break;
            case 'gif':
                $source = imagecreatefromgif($filePath);
                imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagegif($thumb, $thumbPath);
                break;
            default:
                return null;
        }
        
        imagedestroy($thumb);
        imagedestroy($source);
        
        return $relativeThumbPath;
    }

    /**
     * Mark messages as read
     */
    public function markAsRead($conversationId, $messageId = null) {
        $conversationId = (int) $conversationId;
        $messageId = $messageId !== null ? (int) $messageId : null;
        if (!$this->canAccessConversation($conversationId)) {
            return false;
        }
        
        try {
            // Update last_read_at for participant
            $sql = "UPDATE conversation_participants 
                    SET last_read_at = NOW() 
                    WHERE conversation_id = ? AND user_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId, $this->userId]);
            
            // Mark messages as read
            if ($messageId) {
                $sql = "UPDATE message_status 
                        SET status = 'read', status_changed_at = NOW() 
                        WHERE message_id = ? AND user_id = ? AND status != 'read'";
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([$messageId, $this->userId]);
            } else {
                $sql = "UPDATE message_status ms
                        INNER JOIN messages m ON ms.message_id = m.id
                        SET ms.status = 'read', ms.status_changed_at = NOW()
                        WHERE m.conversation_id = ? AND ms.user_id = ? AND ms.status != 'read'";
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([$conversationId, $this->userId]);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error in markAsRead: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark messages as delivered
     */
    private function markMessagesAsDelivered($conversationId) {
        $conversationId = (int) $conversationId;
        try {
            $sql = "UPDATE message_status ms
                    INNER JOIN messages m ON ms.message_id = m.id
                    SET ms.status = 'delivered', ms.status_changed_at = NOW()
                    WHERE m.conversation_id = ? AND ms.user_id = ? AND ms.status = 'sent'";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId, $this->userId]);
            
        } catch (Exception $e) {
            error_log("Error in markMessagesAsDelivered: " . $e->getMessage());
        }
    }

  /**
 * Get available users to chat with (teachers and parents only for admin)
 */
public function getAvailableUsers($search = '', $page = 1, $limit = 20) {
    // Ensure numeric values
    $page = (int)$page;
    $limit = (int)$limit;
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 20;
    
    $offset = ($page - 1) * $limit;
    
    // Admin can only chat with teachers and parents
    $allowedTypes = ['teacher', 'parent'];
    $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
    
    $sql = "SELECT 
                u.id,
                u.name,
                u.email,
                u.phone,
                u.profile_photo,
                u.user_type,
                CASE 
                    WHEN u.user_type = 'teacher' THEN COALESCE(t.employee_id, 'Teacher')
                    WHEN u.user_type = 'parent' THEN (
                        SELECT CONCAT('Parent of ', GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', s.last_name) SEPARATOR ', '))
                        FROM guardians g
                        LEFT JOIN students s ON g.student_id = s.id
                        WHERE g.user_id = u.id
                    )
                    ELSE u.user_type
                END as additional_info
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id AND u.user_type = 'teacher'
            WHERE u.school_id = ? 
            AND u.is_active = 1
            AND u.id != ?
            AND u.user_type IN ({$placeholders})";
    
    $params = [$this->schoolId, $this->userId];
    $params = array_merge($params, $allowedTypes);
    
    if (!empty($search)) {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    }
    
    $sql .= " GROUP BY u.id
              ORDER BY u.name ASC
              LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    try {
        error_log("getAvailableUsers SQL: " . $sql);
        error_log("getAvailableUsers params: " . json_encode($params));
        
        $stmt = $this->schoolDb->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("getAvailableUsers found " . count($results) . " users");
        return $results ?: [];
        
    } catch (Exception $e) {
        error_log("Error in getAvailableUsers: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [];
    }
}
    /**
     * Check if user can access conversation
     */
    private function canAccessConversation($conversationId) {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM conversation_participants 
                    WHERE conversation_id = ? AND user_id = ? AND is_deleted = 0";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId, $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Error in canAccessConversation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user is blocked by other participant
     */
    private function isBlocked($conversationId) {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM message_blocks mb
                    INNER JOIN conversation_participants cp ON mb.blocked_user_id = cp.user_id
                    WHERE cp.conversation_id = ? AND mb.user_id = ?";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$conversationId, $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Error in isBlocked: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get message by ID
     */
    private function getMessageById($messageId) {
        try {
            $sql = "SELECT 
                        m.*,
                        u.name as sender_name,
                        u.profile_photo as sender_avatar,
                        u.user_type as sender_type
                    FROM messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    WHERE m.id = ?";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error in getMessageById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user name by ID
     */
    private function getUserName($userId) {
        try {
            $sql = "SELECT name FROM users WHERE id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ? $user['name'] : 'Unknown';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Add reaction to message
     */
    public function addReaction($messageId, $reaction) {
        // Verify user can access this message's conversation
        $sql = "SELECT conversation_id FROM messages WHERE id = ?";
        $stmt = $this->schoolDb->prepare($sql);
        $stmt->execute([$messageId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$message || !$this->canAccessConversation($message['conversation_id'])) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        try {
            // Check if reaction already exists
            $sql = "SELECT id FROM message_reactions 
                    WHERE message_id = ? AND user_id = ? AND reaction = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId, $this->userId, $reaction]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Remove reaction (toggle off)
                $sql = "DELETE FROM message_reactions WHERE id = ?";
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([$existing['id']]);
                return ['success' => true, 'action' => 'removed'];
            } else {
                // Add reaction
                $sql = "INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)";
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([$messageId, $this->userId, $reaction]);
                return ['success' => true, 'action' => 'added'];
            }
            
        } catch (Exception $e) {
            error_log("Error in addReaction: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to add reaction'];
        }
    }

    /**
     * Delete a message
     */
    public function deleteMessage($messageId) {
        $sql = "SELECT conversation_id, sender_id FROM messages WHERE id = ?";
        $stmt = $this->schoolDb->prepare($sql);
        $stmt->execute([$messageId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found'];
        }
        
        // Only allow deleting own messages
        if ($message['sender_id'] != $this->userId) {
            return ['success' => false, 'error' => 'Cannot delete others messages'];
        }
        
        try {
            $sql = "UPDATE messages SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$messageId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Error in deleteMessage: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete message'];
        }
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation($conversationId) {
        if (!$this->canAccessConversation($conversationId)) {
            return false;
        }
        
        try {
            $sql = "UPDATE conversation_participants 
                    SET is_deleted = 1, left_at = NOW() 
                    WHERE conversation_id = ? AND user_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            return $stmt->execute([$conversationId, $this->userId]);
            
        } catch (Exception $e) {
            error_log("Error in deleteConversation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread count
     */
    public function getUnreadCount() {
        try {
            $sql = "SELECT COUNT(*) as count
                    FROM messages m
                    INNER JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
                    WHERE cp.user_id = ? 
                    AND m.sender_id != ?
                    AND (cp.last_read_at IS NULL OR cp.last_read_at < m.created_at)";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$this->userId, $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? (int)$result['count'] : 0;
            
        } catch (Exception $e) {
            error_log("Error in getUnreadCount: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Archive conversation
     */
    public function archiveConversation($conversationId) {
        if (!$this->canAccessConversation($conversationId)) {
            return false;
        }
        
        try {
            $sql = "UPDATE conversation_participants 
                    SET is_archived = 1 
                    WHERE conversation_id = ? AND user_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            return $stmt->execute([$conversationId, $this->userId]);
            
        } catch (Exception $e) {
            error_log("Error in archiveConversation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mute conversation
     */
    public function muteConversation($conversationId) {
        if (!$this->canAccessConversation($conversationId)) {
            return false;
        }
        
        try {
            $sql = "UPDATE conversation_participants 
                    SET is_muted = 1 
                    WHERE conversation_id = ? AND user_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            return $stmt->execute([$conversationId, $this->userId]);
            
        } catch (Exception $e) {
            error_log("Error in muteConversation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unmute conversation
     */
    public function unmuteConversation($conversationId) {
        if (!$this->canAccessConversation($conversationId)) {
            return false;
        }
        
        try {
            $sql = "UPDATE conversation_participants 
                    SET is_muted = 0 
                    WHERE conversation_id = ? AND user_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            return $stmt->execute([$conversationId, $this->userId]);
            
        } catch (Exception $e) {
            error_log("Error in unmuteConversation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Block user
     */
    public function blockUser($blockedUserId) {
        // Check if user exists and is allowed
        $sql = "SELECT user_type FROM users WHERE id = ? AND school_id = ?";
        $stmt = $this->schoolDb->prepare($sql);
        $stmt->execute([$blockedUserId, $this->schoolId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'error' => 'Invalid user'];
        }
        
        try {
            // Check if already blocked
            $sql = "SELECT id FROM message_blocks 
                    WHERE user_id = ? AND blocked_user_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$this->userId, $blockedUserId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Unblock
                $sql = "DELETE FROM message_blocks WHERE id = ?";
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([$existing['id']]);
                return ['success' => true, 'unblocked' => true];
            } else {
                // Block
                $sql = "INSERT INTO message_blocks (school_id, user_id, blocked_user_id) VALUES (?, ?, ?)";
                $stmt = $this->schoolDb->prepare($sql);
                $stmt->execute([$this->schoolId, $this->userId, $blockedUserId]);
                return ['success' => true, 'blocked' => true];
            }
            
        } catch (Exception $e) {
            error_log("Error in blockUser: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to block/unblock user'];
        }
    }

    /**
     * Save draft
     */
    public function saveDraft($recipientId, $recipientType, $message, $attachments = null) {
        try {
            // Check if draft exists
            $sql = "SELECT id FROM message_drafts 
                    WHERE user_id = ? AND recipient_id = ?";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$this->userId, $recipientId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $attachmentsJson = $attachments ? json_encode($attachments) : null;
            
            if ($existing) {
                $sql = "UPDATE message_drafts 
                        SET message = ?, attachments = ?, updated_at = NOW() 
                        WHERE id = ?";
                $stmt = $this->schoolDb->prepare($sql);
                return $stmt->execute([$message, $attachmentsJson, $existing['id']]);
            } else {
                $sql = "INSERT INTO message_drafts (school_id, user_id, recipient_id, recipient_type, message, attachments) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $this->schoolDb->prepare($sql);
                return $stmt->execute([
                    $this->schoolId, 
                    $this->userId, 
                    $recipientId, 
                    $recipientType, 
                    $message, 
                    $attachmentsJson
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Error in saveDraft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get draft
     */
    public function getDraft($recipientId) {
        try {
            $sql = "SELECT * FROM message_drafts 
                    WHERE user_id = ? AND recipient_id = ?";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute([$this->userId, $recipientId]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($draft && $draft['attachments']) {
                $draft['attachments'] = json_decode($draft['attachments'], true);
            }
            
            return $draft;
            
        } catch (Exception $e) {
            error_log("Error in getDraft: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Search messages
     */
    public function searchMessages($query, $conversationId = null) {
        try {
            $sql = "SELECT 
                        m.*,
                        c.id as conversation_id,
                        u.name as sender_name
                    FROM messages m
                    INNER JOIN conversations c ON m.conversation_id = c.id
                    INNER JOIN conversation_participants cp ON c.id = cp.conversation_id
                    LEFT JOIN users u ON m.sender_id = u.id
                    WHERE cp.user_id = ? 
                    AND m.message LIKE ?
                    AND m.is_deleted = 0";
            
            $params = [$this->userId, "%$query%"];
            
            if ($conversationId) {
                $sql .= " AND m.conversation_id = ?";
                $params[] = $conversationId;
            }
            
            $sql .= " ORDER BY m.created_at DESC LIMIT 50";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error in searchMessages: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Send typing indicator
     */
    public function sendTypingIndicator($conversationId, $isTyping) {
        // This could be implemented with a real-time system like WebSockets
        // For now, we'll just log it
        error_log("User $this->userId is " . ($isTyping ? "typing" : "not typing") . " in conversation $conversationId");
        return true;
    }
}