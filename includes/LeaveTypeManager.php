<?php
/**
 * Leave Type Manager
 * Handles all database operations for leave types
 */
class LeaveTypeManager {
    private $db;
    private $schoolId;

    public function __construct($db, $schoolId) {
        $this->db = $db;
        $this->schoolId = $schoolId;
    }

    /**
     * Get all leave types for the school
     */
    public function getAll() {
        $stmt = $this->db->prepare("
            SELECT id, name, description, max_days_per_year, applicable_to, is_paid, is_active
            FROM leave_types
            WHERE school_id = ?
            ORDER BY name
        ");
        $stmt->execute([$this->schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single leave type by ID
     */
    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT id, name, description, max_days_per_year, applicable_to, is_paid, is_active
            FROM leave_types
            WHERE school_id = ? AND id = ?
        ");
        $stmt->execute([$this->schoolId, $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new leave type
     */
    public function create($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO leave_types 
                (school_id, name, description, max_days_per_year, applicable_to, is_paid, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->schoolId,
                $data['name'],
                $data['description'] ?? null,
                !empty($data['max_days_per_year']) ? (int)$data['max_days_per_year'] : null,
                $data['applicable_to'] ?? 'all',
                isset($data['is_paid']) ? (int)$data['is_paid'] : 1,
                isset($data['is_active']) ? (int)$data['is_active'] : 1
            ]);
            return ['success' => true, 'message' => 'Leave type created successfully.', 'id' => $this->db->lastInsertId()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create leave type: ' . $e->getMessage()];
        }
    }

    /**
     * Update an existing leave type
     */
    public function update($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE leave_types SET
                    name = ?,
                    description = ?,
                    max_days_per_year = ?,
                    applicable_to = ?,
                    is_paid = ?,
                    is_active = ?
                WHERE school_id = ? AND id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                !empty($data['max_days_per_year']) ? (int)$data['max_days_per_year'] : null,
                $data['applicable_to'] ?? 'all',
                isset($data['is_paid']) ? (int)$data['is_paid'] : 1,
                isset($data['is_active']) ? (int)$data['is_active'] : 1,
                $this->schoolId,
                $id
            ]);
            return ['success' => true, 'message' => 'Leave type updated successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to update leave type: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a leave type
     */
    public function delete($id) {
        try {
            // Check if leave type is used in leave_requests (optional, to prevent orphaned records)
            $check = $this->db->prepare("SELECT COUNT(*) FROM leave_requests WHERE leave_type_id = ? AND school_id = ?");
            $check->execute([$id, $this->schoolId]);
            if ($check->fetchColumn() > 0) {
                return ['success' => false, 'message' => 'Cannot delete: this leave type is already used in leave requests.'];
            }

            $stmt = $this->db->prepare("DELETE FROM leave_types WHERE school_id = ? AND id = ?");
            $stmt->execute([$this->schoolId, $id]);
            return ['success' => true, 'message' => 'Leave type deleted successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete leave type: ' . $e->getMessage()];
        }
    }

    /**
     * Toggle active status
     */
    public function toggleStatus($id) {
        try {
            $stmt = $this->db->prepare("UPDATE leave_types SET is_active = NOT is_active WHERE school_id = ? AND id = ?");
            $stmt->execute([$this->schoolId, $id]);
            return ['success' => true, 'message' => 'Status toggled successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to toggle status: ' . $e->getMessage()];
        }
    }
}