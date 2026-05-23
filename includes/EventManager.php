<?php
/**
 * Event Manager Class
 * Handles all event-related operations including sending email notifications
 * 
 * @package AcademixSuite
 * @version 2.0
 */

require_once __DIR__ . '/Services/WhatsAppService.php';

class EventManager {
    private $schoolDb;
    private $platformDb;
    private $schoolId;
    private $userId;
    private $userType;
    private $schoolData;

    /**
     * Constructor
     * @param PDO $schoolDb School database connection
     * @param PDO $platformDb Platform database connection
     * @param int $schoolId School ID
     * @param int $userId Current user ID
     * @param string $userType Current user type
     * @param array $schoolData School information
     */
    public function __construct($schoolDb, $platformDb, $schoolId, $userId, $userType, $schoolData) {
        $this->schoolDb = $schoolDb;
        $this->platformDb = $platformDb;
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->userType = $userType;
        $this->schoolData = $schoolData;
        
        error_log("EventManager initialized for school ID: " . $schoolId);
    }

    /**
     * Event types and their color codes
     */
    private $eventColors = [
        'holiday' => '#dc3545',      // Red
        'exam' => '#fd7e14',          // Orange
        'meeting' => '#0d6efd',       // Blue
        'celebration' => '#198754',   // Green
        'sports' => '#6f42c1',         // Purple
        'other' => '#6c757d'           // Gray
    ];

    /**
     * Get all events for calendar display
     * @param string $startDate Optional start date filter
     * @param string $endDate Optional end date filter
     * @return array
     */
    public function getEvents($startDate = null, $endDate = null) {
        try {
            $sql = "
                SELECT 
                    e.*,
                    u.name as created_by_name,
                    CASE 
                        WHEN e.end_date < CURDATE() THEN 'past'
                        WHEN e.start_date > CURDATE() THEN 'upcoming'
                        ELSE 'ongoing'
                    END as status,
                    DATEDIFF(e.end_date, e.start_date) + 1 as duration_days
                FROM events e
                LEFT JOIN users u ON e.created_by = u.id
                WHERE e.school_id = ?
            ";
            
            $params = [$this->schoolId];
            
            if ($startDate) {
                $sql .= " AND e.start_date >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate) {
                $sql .= " AND e.end_date <= ?";
                $params[] = $endDate;
            }
            
            $sql .= " ORDER BY e.start_date DESC";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get single event by ID
     * @param int $eventId
     * @return array|false
     */
    public function getEventById($eventId) {
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT 
                    e.*,
                    u.name as created_by_name,
                    u.email as created_by_email,
                    CASE 
                        WHEN e.end_date < CURDATE() THEN 'past'
                        WHEN e.start_date > CURDATE() THEN 'upcoming'
                        ELSE 'ongoing'
                    END as status
                FROM events e
                LEFT JOIN users u ON e.created_by = u.id
                WHERE e.id = ? AND e.school_id = ?
            ");
            $stmt->execute([$eventId, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting event: " . $e->getMessage());
            return false;
        }
    }

    


    /**
     * Create new event
     * @param array $data Event data
     * @param bool $sendNotification Whether to send email notifications
     * @return array [success, message, event_id]
     */
    public function createEvent($data, $sendNotification = true) {
        try {
            // Validate required fields
            if (empty($data['title']) || empty($data['start_date']) || empty($data['type'])) {
                throw new Exception("Title, start date, and event type are required");
            }

            // Validate dates
            $startDate = $data['start_date'];
            $endDate = $data['end_date'] ?? $startDate;
            
            if (strtotime($endDate) < strtotime($startDate)) {
                throw new Exception("End date cannot be before start date");
            }

            $duplicate = $this->findDuplicateEvent($data);
            if ($duplicate) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'This event is already on the calendar, so no duplicate was created.',
                    'event_id' => (int)$duplicate['id'],
                ];
            }

            // Check if transaction is already active
            $inTransaction = $this->schoolDb->inTransaction();
            
            if (!$inTransaction) {
                $this->schoolDb->beginTransaction();
            }

            $columns = [
                'school_id' => $this->schoolId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'venue' => $data['venue'] ?? null,
                'is_public' => isset($data['is_public']) ? (int)$data['is_public'] : 1,
                'created_by' => $this->userId,
            ];

            if ($this->columnExists('events', 'campus_id')) {
                $columns = ['school_id' => $this->schoolId, 'campus_id' => $this->defaultCampusId()] + array_slice($columns, 1, null, true);
            }

            $fieldSql = array_map(fn($field) => "`{$field}`", array_keys($columns));
            $placeholders = array_fill(0, count($columns), '?');
            $params = array_values($columns);

            if ($this->columnExists('events', 'created_at')) {
                $fieldSql[] = '`created_at`';
                $placeholders[] = 'NOW()';
            }

            $stmt = $this->schoolDb->prepare(
                'INSERT INTO events (' . implode(', ', $fieldSql) . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);

            $eventId = $this->schoolDb->lastInsertId();

            // Create audit log
            $this->createAuditLog([
                'action' => 'create',
                'entity_type' => 'events',
                'entity_id' => $eventId,
                'new_values' => json_encode([
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'start_date' => $startDate
                ])
            ]);

            // Send email notifications if requested
            if ($sendNotification) {
                $this->sendEventNotifications($eventId, 'created');
            }

            if ($this->shouldSendWhatsApp($data)) {
                $this->sendEventWhatsAppNotifications($eventId, 'created');
            }

            if (!$inTransaction) {
                $this->schoolDb->commit();
            }

            return [
                'success' => true,
                'message' => 'Event created successfully!',
                'event_id' => $eventId
            ];

        } catch (Exception $e) {
            if (isset($inTransaction) && !$inTransaction && $this->schoolDb->inTransaction()) {
                $this->schoolDb->rollBack();
            }
            error_log("Error creating event: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create event: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update existing event
     * @param int $eventId
     * @param array $data Event data
     * @param bool $sendNotification Whether to send email notifications
     * @return array [success, message]
     */
    public function updateEvent($eventId, $data, $sendNotification = true) {
        try {
            if (empty($eventId)) {
                throw new Exception("Event ID is required");
            }

            // Get old data for audit log
            $oldData = $this->getEventById($eventId);
            if (!$oldData) {
                throw new Exception("Event not found");
            }

            // Validate dates if provided
            if (!empty($data['start_date']) && !empty($data['end_date'])) {
                if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
                    throw new Exception("End date cannot be before start date");
                }
            }

            $duplicate = $this->findDuplicateEvent($data, (int)$eventId);
            if ($duplicate) {
                return [
                    'success' => false,
                    'duplicate' => true,
                    'message' => 'Another event already has this title, date, time, and type.',
                    'event_id' => (int)$duplicate['id'],
                ];
            }

            $inTransaction = $this->schoolDb->inTransaction();
            
            if (!$inTransaction) {
                $this->schoolDb->beginTransaction();
            }

            // Build update query dynamically
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['title', 'description', 'type', 'start_date', 'end_date', 
                             'start_time', 'end_time', 'venue', 'is_public'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "`$field` = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updateFields)) {
                throw new Exception("No fields to update");
            }
            
            $params[] = $eventId;
            $params[] = $this->schoolId;
            
            if ($this->columnExists('events', 'updated_at')) {
                $updateFields[] = "`updated_at` = NOW()";
            }

            $sql = "UPDATE events SET " . implode(', ', $updateFields) . " WHERE id = ? AND school_id = ?";
            
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);

            // Create audit log
            $this->createAuditLog([
                'action' => 'update',
                'entity_type' => 'events',
                'entity_id' => $eventId,
                'old_values' => json_encode($oldData),
                'new_values' => json_encode($data)
            ]);

            // Send email notifications if requested
            if ($sendNotification) {
                $this->sendEventNotifications($eventId, 'updated');
            }

            if ($this->shouldSendWhatsApp($data)) {
                $this->sendEventWhatsAppNotifications($eventId, 'updated');
            }

            if (!$inTransaction) {
                $this->schoolDb->commit();
            }

            return [
                'success' => true,
                'message' => 'Event updated successfully!'
            ];

        } catch (Exception $e) {
            if (isset($inTransaction) && !$inTransaction && $this->schoolDb->inTransaction()) {
                $this->schoolDb->rollBack();
            }
            error_log("Error updating event: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update event: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Find an existing event that represents the same calendar item.
     */
    private function findDuplicateEvent(array $data, ?int $excludeId = null): ?array {
        try {
            $title = trim((string)($data['title'] ?? ''));
            $startDate = trim((string)($data['start_date'] ?? ''));
            $endDate = trim((string)($data['end_date'] ?? $startDate));
            if ($endDate === '') {
                $endDate = $startDate;
            }
            $startTime = trim((string)($data['start_time'] ?? ''));
            $type = trim((string)($data['type'] ?? 'other'));

            if ($title === '' || $startDate === '') {
                return null;
            }

            $sql = "
                SELECT id, title, start_date, end_date, start_time, type
                FROM events
                WHERE school_id = ?
                  AND LOWER(TRIM(title)) = LOWER(TRIM(?))
                  AND start_date = ?
                  AND COALESCE(end_date, start_date) = ?
                  AND COALESCE(start_time, '') = ?
                  AND type = ?
            ";
            $params = [$this->schoolId, $title, $startDate, $endDate, $startTime, $type];

            if ($excludeId !== null && $excludeId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }

            $sql .= " LIMIT 1";
            $stmt = $this->schoolDb->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (Exception $e) {
            error_log("Event duplicate check failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete event
     * @param int $eventId
     * @param bool $sendNotification Whether to send email notifications
     * @return array [success, message]
     */
    public function deleteEvent($eventId, $sendNotification = true) {
        try {
            if (empty($eventId)) {
                throw new Exception("Event ID is required");
            }

            // Get event data for audit log and notification
            $eventData = $this->getEventById($eventId);
            if (!$eventData) {
                throw new Exception("Event not found");
            }

            $inTransaction = $this->schoolDb->inTransaction();
            
            if (!$inTransaction) {
                $this->schoolDb->beginTransaction();
            }

            // Delete the event
            $stmt = $this->schoolDb->prepare("DELETE FROM events WHERE id = ? AND school_id = ?");
            $stmt->execute([$eventId, $this->schoolId]);

            // Create audit log
            $this->createAuditLog([
                'action' => 'delete',
                'entity_type' => 'events',
                'entity_id' => $eventId,
                'old_values' => json_encode($eventData)
            ]);

            // Send email notifications if requested
            if ($sendNotification) {
                $this->sendEventNotifications($eventId, 'deleted', $eventData);
            }

            if ($this->shouldSendWhatsApp()) {
                $this->sendEventWhatsAppNotifications($eventId, 'deleted', $eventData);
            }

            if (!$inTransaction) {
                $this->schoolDb->commit();
            }

            return [
                'success' => true,
                'message' => 'Event deleted successfully!'
            ];

        } catch (Exception $e) {
            if (isset($inTransaction) && !$inTransaction && $this->schoolDb->inTransaction()) {
                $this->schoolDb->rollBack();
            }
            error_log("Error deleting event: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete event: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get events for FullCalendar
     * @return array
     */
    public function getCalendarEvents() {
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT 
                    id,
                    title,
                    description,
                    type,
                    start_date as start,
                    end_date as end,
                    start_time,
                    end_time,
                    venue,
                    is_public,
                    created_by
                FROM events
                WHERE school_id = ?
                ORDER BY start_date
            ");
            $stmt->execute([$this->schoolId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format for FullCalendar
            $calendarEvents = [];
            foreach ($events as $event) {
                $calendarEvents[] = [
                    'id' => $event['id'],
                    'title' => $event['title'],
                    'start' => $event['start'],
                    'end' => $event['end'],
                    'color' => $this->eventColors[$event['type']] ?? $this->eventColors['other'],
                    'textColor' => '#ffffff',
                    'description' => $event['description'],
                    'venue' => $event['venue'],
                    'type' => $event['type']
                ];
            }
            
            return $calendarEvents;
            
        } catch (Exception $e) {
            error_log("Error getting calendar events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get upcoming events
     * @param int $limit
     * @return array
     */
    public function getUpcomingEvents($limit = 10) {
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT 
                    e.*,
                    u.name as created_by_name,
                    DATEDIFF(e.start_date, CURDATE()) as days_until
                FROM events e
                LEFT JOIN users u ON e.created_by = u.id
                WHERE e.school_id = ? 
                    AND e.start_date >= CURDATE()
                ORDER BY e.start_date ASC
                LIMIT ?
            ");
            $stmt->execute([$this->schoolId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting upcoming events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get events by date range
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getEventsByDateRange($startDate, $endDate) {
        try {
            $stmt = $this->schoolDb->prepare("
                SELECT * FROM events
                WHERE school_id = ?
                    AND (
                        (start_date BETWEEN ? AND ?)
                        OR (end_date BETWEEN ? AND ?)
                        OR (start_date <= ? AND end_date >= ?)
                    )
                ORDER BY start_date
            ");
            $stmt->execute([
                $this->schoolId,
                $startDate, $endDate,
                $startDate, $endDate,
                $startDate, $endDate
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting events by date range: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Send email notifications about event
     * @param int $eventId
     * @param string $action (created, updated, deleted)
     * @param array $eventData Optional event data (for delete)
     * @return bool
     */
    private function sendEventNotifications($eventId, $action, $eventData = null) {
        try {
            // Get event details
            if ($action === 'deleted' && $eventData) {
                $event = $eventData;
            } else {
                $event = $this->getEventById($eventId);
            }
            
            if (!$event) {
                error_log("Event not found for notification: " . $eventId);
                return false;
            }

            // Get all users to notify (excluding the creator)
            $userStmt = $this->schoolDb->prepare("
                SELECT id, name, email FROM users 
                WHERE school_id = ? AND is_active = 1 AND id != ?
            ");
            $userStmt->execute([$this->schoolId, $this->userId]);
            $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare email subject based on action
            $actionText = [
                'created' => 'New Event Created',
                'updated' => 'Event Updated',
                'deleted' => 'Event Cancelled'
            ];

            $subjectPrefix = $actionText[$action] ?? 'Event Notification';

            // Get school settings for email
            $schoolName = $this->schoolData['name'] ?? 'School';
            $schoolEmail = $this->schoolData['email'] ?? 'noreply@academixsuite.com';

            // Format event date
            $eventDate = date('l, F j, Y', strtotime($event['start_date']));
            if ($event['start_date'] != $event['end_date']) {
                $eventDate .= ' - ' . date('l, F j, Y', strtotime($event['end_date']));
            }

            // Build email body
            $body = $this->buildEmailBody($event, $action, $eventDate);

            // Send emails (in production, you might want to queue these)
            $sentCount = 0;
            foreach ($users as $user) {
                if (!empty($user['email'])) {
                    $emailSent = $this->sendEmail(
                        $user['email'],
                        $user['name'],
                        "[$schoolName] $subjectPrefix: " . $event['title'],
                        $body
                    );
                    if ($emailSent) {
                        $sentCount++;
                    }
                }
            }

            // Create notification records in database
            $this->createNotifications($event, $action, $users);

            error_log("Sent $sentCount email notifications for event ID: $eventId");
            return true;

        } catch (Exception $e) {
            error_log("Error sending event notifications: " . $e->getMessage());
            return false;
        }
    }

    private function sendEventWhatsAppNotifications($eventId, $action, $eventData = null) {
        try {
            if (!class_exists('WhatsAppService') || !WhatsAppService::featureEnabled($this->schoolDb, (int)$this->schoolId, 'events', true)) {
                return false;
            }

            $event = ($action === 'deleted' && $eventData) ? $eventData : $this->getEventById($eventId);
            if (!$event) {
                return false;
            }

            $service = new WhatsAppService($this->schoolDb, array_merge($this->schoolData ?: [], ['id' => $this->schoolId]));
            $recipients = $service->resolveAnnouncementRecipients('all', null, null, ['parents', 'teachers']);
            if (empty($recipients)) {
                return false;
            }

            $actionLabels = [
                'created' => 'New School Event',
                'updated' => 'School Event Updated',
                'deleted' => 'School Event Cancelled',
            ];

            $dateText = $this->formatEventDate($event);
            $details = trim(($event['description'] ?? '') ?: 'Please check your school portal for event details.');
            $venue = trim((string)($event['venue'] ?? ''));
            if ($venue !== '') {
                $details .= ' Venue: ' . $venue . '.';
            }
            $details .= ' Date: ' . $dateText . '.';

            $title = ($actionLabels[$action] ?? 'School Event') . ': ' . ($event['title'] ?? 'Event');
            $path = 'admin/event.php?id=' . (int)($event['id'] ?? $eventId);
            $sent = 0;
            $failed = 0;

            foreach (array_slice($recipients, 0, 200) as $recipient) {
                $result = $service->sendDirectNotification('event', (int)$eventId, $recipient, $title, $details, $path);
                if (!empty($result['success'])) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            error_log("Event WhatsApp notifications for event {$eventId}: {$sent} sent, {$failed} not sent");
            return $sent > 0;
        } catch (Throwable $e) {
            error_log('Event WhatsApp notification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build email body for event notification
     */
    private function buildEmailBody($event, $action, $eventDate) {
        $actionMessages = [
            'created' => 'A new event has been scheduled',
            'updated' => 'An event has been updated',
            'deleted' => 'An event has been cancelled'
        ];

        $actionColor = [
            'created' => '#28a745',
            'updated' => '#ffc107',
            'deleted' => '#dc3545'
        ];

        $actionMessage = $actionMessages[$action] ?? 'Event notification';
        $color = $actionColor[$action] ?? '#17a2b8';

        $venueText = !empty($event['venue']) ? "<p><strong>Venue:</strong> " . htmlspecialchars($event['venue']) . "</p>" : "";
        $timeText = (!empty($event['start_time']) && !empty($event['end_time'])) 
            ? "<p><strong>Time:</strong> " . date('g:i A', strtotime($event['start_time'])) . " - " . date('g:i A', strtotime($event['end_time'])) . "</p>" 
            : "";

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: $color; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .event-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid $color; }
                .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #6c757d; }
                .button { display: inline-block; background: $color; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                h1 { margin: 0; font-size: 24px; }
                h2 { color: $color; margin-top: 0; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 8px; }
                .label { font-weight: bold; width: 100px; color: #495057; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . htmlspecialchars($event['title']) . "</h1>
                    <p style='margin: 10px 0 0; font-size: 16px;'>$actionMessage</p>
                </div>
                <div class='content'>
                    <div class='event-details'>
                        <h2>Event Details</h2>
                        <table>
                            <tr>
                                <td class='label'>Date:</td>
                                <td><strong>$eventDate</strong></td>
                            </tr>
                            $timeText
                            $venueText
                            <tr>
                                <td class='label'>Type:</td>
                                <td><span style='background: $color; color: white; padding: 3px 10px; border-radius: 15px; font-size: 12px;'>" . ucfirst($event['type']) . "</span></td>
                            </tr>
                            <tr>
                                <td class='label'>Description:</td>
                                <td>" . nl2br(htmlspecialchars($event['description'] ?? 'No description provided')) . "</td>
                            </tr>
                        </table>
                    </div>
                    
                    <p style='text-align: center;'>
                        <a href='" . (function_exists('school_portal_url') ? school_portal_url($this->schoolData['slug'], "admin/event.php?id={$event['id']}", true) : ((defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://academixsuite.com') . "/tenant/{$this->schoolData['slug']}/admin/event.php?id={$event['id']}")) . "' class='button'>View Event Details</a>
                    </p>
                    
                    <p style='margin-top: 20px;'>
                        <strong>Important:</strong> Please mark your calendar and make necessary arrangements. 
                        For any questions or concerns, please contact the school administration.
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from " . htmlspecialchars($this->schoolData['name'] ?? 'AcademixSuite') . ".</p>
                    <p>&copy; " . date('Y') . " " . htmlspecialchars($this->schoolData['name'] ?? 'AcademixSuite') . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Create in-app notifications
     */
    private function createNotifications($event, $action, $users) {
        try {
            $actionMessages = [
                'created' => 'New event scheduled',
                'updated' => 'Event updated',
                'deleted' => 'Event cancelled'
            ];

            $actionMessage = $actionMessages[$action] ?? 'Event notification';
            $icon = $action == 'created' ? 'ri-calendar-check-line' : ($action == 'updated' ? 'ri-calendar-event-line' : 'ri-calendar-close-line');

            foreach ($users as $user) {
                $stmt = $this->schoolDb->prepare("
                    INSERT INTO notifications (
                        school_id, user_id, type, title, message, data, priority, created_at
                    ) VALUES (?, ?, 'in_app', ?, ?, ?, 'normal', NOW())
                ");

                $stmt->execute([
                    $this->schoolId,
                    $user['id'],
                    $actionMessage,
                    $event['title'] . ' - ' . date('M d, Y', strtotime($event['start_date'])),
                    json_encode([
                        'event_id' => $event['id'],
                        'action' => $action,
                        'icon' => $icon
                    ])
                ]);
            }
        } catch (Exception $e) {
            error_log("Error creating notifications: " . $e->getMessage());
        }
    }

    /**
     * Send individual email
     */
    private function sendEmail($to, $toName, $subject, $body) {
        try {
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: ' . ($this->schoolData['name'] ?? 'AcademixSuite') . ' <' . ($this->schoolData['email'] ?? 'noreply@academixsuite.com') . '>' . "\r\n";
            $headers .= 'Reply-To: ' . ($this->schoolData['email'] ?? 'noreply@academixsuite.com') . "\r\n";
            
            return mail($to, $subject, $body, $headers);
        } catch (Exception $e) {
            error_log("Error sending email to $to: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create audit log entry
     */
    private function createAuditLog($data) {
        try {
            $stmt = $this->schoolDb->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, old_values, new_values, ip_address, user_agent, url, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $data['action'],
                $data['entity_type'],
                $data['entity_id'],
                $data['old_values'] ?? null,
                $data['new_values'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null
            ]);

        } catch (Exception $e) {
            error_log("Error creating audit log: " . $e->getMessage());
        }
    }

    private function shouldSendWhatsApp(array $data = []): bool {
        if (array_key_exists('send_whatsapp', $data)) {
            return !empty($data['send_whatsapp']);
        }

        return class_exists('WhatsAppService')
            && WhatsAppService::featureEnabled($this->schoolDb, (int)$this->schoolId, 'events', true);
    }

    private function formatEventDate(array $event): string {
        $startDate = !empty($event['start_date']) ? date('l, F j, Y', strtotime($event['start_date'])) : 'the scheduled date';
        $endDate = !empty($event['end_date']) ? date('l, F j, Y', strtotime($event['end_date'])) : $startDate;
        $dateText = $startDate === $endDate ? $startDate : "{$startDate} to {$endDate}";

        if (!empty($event['start_time'])) {
            $dateText .= ' at ' . date('g:i A', strtotime($event['start_time']));
            if (!empty($event['end_time'])) {
                $dateText .= ' - ' . date('g:i A', strtotime($event['end_time']));
            }
        }

        return $dateText;
    }

    private function columnExists(string $table, string $column): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $stmt = $this->schoolDb->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function defaultCampusId(): int {
        return max(1, (int)($this->schoolData['campus_id'] ?? $this->schoolData['default_campus_id'] ?? 1));
    }

    /**
     * Get event statistics
     * @return array
     */
    public function getEventStats() {
        try {
            $stats = [];
            
            // Total events
            $stmt = $this->schoolDb->prepare("
                SELECT COUNT(*) as count FROM events WHERE school_id = ?
            ");
            $stmt->execute([$this->schoolId]);
            $stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // Upcoming events
            $stmt = $this->schoolDb->prepare("
                SELECT COUNT(*) as count FROM events 
                WHERE school_id = ? AND start_date >= CURDATE()
            ");
            $stmt->execute([$this->schoolId]);
            $stats['upcoming'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // Ongoing events
            $stmt = $this->schoolDb->prepare("
                SELECT COUNT(*) as count FROM events 
                WHERE school_id = ? 
                    AND start_date <= CURDATE() 
                    AND end_date >= CURDATE()
            ");
            $stmt->execute([$this->schoolId]);
            $stats['ongoing'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // Past events
            $stmt = $this->schoolDb->prepare("
                SELECT COUNT(*) as count FROM events 
                WHERE school_id = ? AND end_date < CURDATE()
            ");
            $stmt->execute([$this->schoolId]);
            $stats['past'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // Events by type
            $stmt = $this->schoolDb->prepare("
                SELECT type, COUNT(*) as count 
                FROM events 
                WHERE school_id = ? 
                GROUP BY type
            ");
            $stmt->execute([$this->schoolId]);
            $stats['by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Error getting event stats: " . $e->getMessage());
            return [];
        }
    }
}
