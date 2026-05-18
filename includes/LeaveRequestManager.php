<?php
/**
 * Leave Request Manager
 * Handles all database operations for leave requests
 */
class LeaveRequestManager {
    private $db;
    private $schoolId;

    public function __construct($db, $schoolId) {
        $this->db = $db;
        $this->schoolId = $schoolId;
    }

    /**
     * Get all leave requests with related user and leave type info
     * Optionally filter by user if not admin
     */
    public function getAll($userType = null, $userId = null) {
        $sql = "
            SELECT 
                lr.*,
                u.name as user_name,
                u.email as user_email,
                lt.name as leave_type_name,
                lt.is_paid,
                CONCAT(lr.start_date, ' - ', lr.end_date) as date_range,
                DATEDIFF(lr.end_date, lr.start_date) + 1 as duration
            FROM leave_requests lr
            LEFT JOIN users u ON lr.user_id = u.id AND u.school_id = lr.school_id
            LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id AND lt.school_id = lr.school_id
            WHERE lr.school_id = ?
        ";
        $params = [$this->schoolId];

        // If not admin, show only own requests
        if ($userType && $userType !== 'admin' && $userId) {
            $sql .= " AND lr.user_id = ?";
            $params[] = $userId;
        }

        $sql .= " ORDER BY lr.applied_on DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single leave request by ID
     */
    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT 
                lr.*,
                u.name as user_name,
                u.email as user_email,
                u.user_type,
                lt.name as leave_type_name,
                lt.is_paid,
                DATEDIFF(lr.end_date, lr.start_date) + 1 as duration
            FROM leave_requests lr
            LEFT JOIN users u ON lr.user_id = u.id AND u.school_id = lr.school_id
            LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id AND lt.school_id = lr.school_id
            WHERE lr.school_id = ? AND lr.id = ?
        ");
        $stmt->execute([$this->schoolId, $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update status of a leave request
     */
    public function updateStatus($id, $status, $note = null, $approvedBy = null) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                UPDATE leave_requests 
                SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = NOW()
                WHERE school_id = ? AND id = ?
            ");
            $stmt->execute([$status, $note, $approvedBy, $this->schoolId, $id]);

            $this->db->commit();
            return ['success' => true, 'message' => 'Status updated successfully.'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a leave request
     */
    public function delete($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM leave_requests WHERE school_id = ? AND id = ?");
            $stmt->execute([$this->schoolId, $id]);
            return ['success' => true, 'message' => 'Leave request deleted successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete: ' . $e->getMessage()];
        }
    }
}