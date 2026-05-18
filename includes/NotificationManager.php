<?php
/**
 * Notification Manager Class
 * Handles all notification-related operations for a school.
 * 
 * @package AcademixSuite
 * @version 1.0
 */

class NotificationManager {
    private $db;
    private $schoolId;
    private $userId;
    private $userType;
    private $schoolData;

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $schoolId School ID
     * @param int $userId Current user ID
     * @param string $userType Current user type
     * @param array $schoolData School information
     */
    public function __construct($db, $schoolId, $userId, $userType, $schoolData) {
        $this->db = $db;
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->userType = $userType;
        $this->schoolData = $schoolData;
    }

    /**
     * Get recent notifications for the current user
     * @param int $limit Maximum number of notifications to return
     * @param bool $includeRead Whether to include already read notifications
     * @return array List of notifications
     */
    public function getNotifications($limit = 10, $includeRead = true) {
        try {
            $sql = "SELECT * FROM notifications 
                    WHERE school_id = ? AND user_id = ?
                    AND (expires_at IS NULL OR expires_at > NOW())";
            
            if (!$includeRead) {
                $sql .= " AND is_read = 0";
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->schoolId, $this->userId, $limit]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add icon based on type/priority for frontend
            foreach ($notifications as &$notif) {
                $notif['icon'] = $this->getIconForNotification($notif);
            }
            
            return $notifications;
        } catch (Exception $e) {
            error_log("NotificationManager::getNotifications error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count unread notifications for the current user
     * @return int
     */
    public function getUnreadCount() {
        try {
            $sql = "SELECT COUNT(*) as count FROM notifications 
                    WHERE school_id = ? AND user_id = ? AND is_read = 0
                    AND (expires_at IS NULL OR expires_at > NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->schoolId, $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['count'] : 0;
        } catch (Exception $e) {
            error_log("NotificationManager::getUnreadCount error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark a notification as read
     * @param int $notificationId
     * @return bool
     */
    public function markAsRead($notificationId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications SET is_read = 1, read_at = NOW()
                WHERE id = ? AND school_id = ? AND user_id = ?
            ");
            return $stmt->execute([$notificationId, $this->schoolId, $this->userId]);
        } catch (Exception $e) {
            error_log("NotificationManager::markAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all notifications as read for the current user
     * @return bool
     */
    public function markAllAsRead() {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications SET is_read = 1, read_at = NOW()
                WHERE school_id = ? AND user_id = ? AND is_read = 0
            ");
            return $stmt->execute([$this->schoolId, $this->userId]);
        } catch (Exception $e) {
            error_log("NotificationManager::markAllAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new notification
     * @param array $data Associative array with keys: user_id, type, title, message, priority, scheduled_for, expires_at
     * @return int|false The new notification ID or false on failure
     */
    public function createNotification($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (
                    school_id, user_id, type, title, message, data, priority,
                    is_read, is_sent, delivery_status, scheduled_for, expires_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, 'sent', ?, ?, NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['user_id'],
                $data['type'] ?? 'in_app',
                $data['title'],
                $data['message'],
                $data['data'] ?? null,
                $data['priority'] ?? 'normal',
                $data['scheduled_for'] ?? null,
                $data['expires_at'] ?? null
            ]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("NotificationManager::createNotification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete expired notifications (cleanup)
     * @return int Number of deleted rows
     */
    public function deleteExpired() {
        try {
            $stmt = $this->db->prepare("DELETE FROM notifications WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("NotificationManager::deleteExpired error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Helper: map notification to an icon class for frontend
     * @param array $notification
     * @return string
     */
    private function getIconForNotification($notification) {
        $type = $notification['type'] ?? 'in_app';
        $priority = $notification['priority'] ?? 'normal';
        
        $iconMap = [
            'email' => 'mail-line',
            'sms' => 'message-line',
            'push' => 'notification-line',
            'in_app' => 'notification-line',
            'system' => 'computer-line'
        ];
        
        // Override based on priority if needed
        if ($priority === 'urgent') {
            return 'alert-line';
        } elseif ($priority === 'high') {
            return 'error-warning-line';
        }
        
        return $iconMap[$type] ?? 'notification-line';
    }
}