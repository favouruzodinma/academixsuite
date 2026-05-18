<?php
/**
 * Role Manager
 * Handles all role-related database operations
 */
class RoleManager {
    private $db;
    private $schoolId;

    public function __construct($db, $schoolId) {
        $this->db = $db;
        $this->schoolId = $schoolId;
    }

    /**
     * Get all roles for the school
     */
    public function getAll() {
        $stmt = $this->db->prepare("
            SELECT id, name, slug, description, permissions, is_system, created_at
            FROM roles
            WHERE school_id = ?
            ORDER BY is_system DESC, name
        ");
        $stmt->execute([$this->schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single role by ID
     */
    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT id, name, slug, description, permissions, is_system, created_at
            FROM roles
            WHERE school_id = ? AND id = ?
        ");
        $stmt->execute([$this->schoolId, $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new role
     */
    public function create($data) {
        // Check if role with same slug exists
        $slug = $this->createSlug($data['name']);
        $check = $this->db->prepare("SELECT COUNT(*) FROM roles WHERE school_id = ? AND slug = ?");
        $check->execute([$this->schoolId, $slug]);
        if ($check->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'A role with this name already exists.'];
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO roles (school_id, name, slug, description, permissions, is_system, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $data['name'],
                $slug,
                $data['description'] ?? null,
                $data['permissions'] ?? null
            ]);
            return ['success' => true, 'message' => 'Role created successfully.', 'id' => $this->db->lastInsertId()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create role: ' . $e->getMessage()];
        }
    }

    /**
     * Update an existing role (non-system only)
     */
    public function update($id, $data) {
        // Check if role is system
        $check = $this->db->prepare("SELECT is_system FROM roles WHERE school_id = ? AND id = ?");
        $check->execute([$this->schoolId, $id]);
        $role = $check->fetch(PDO::FETCH_ASSOC);
        if ($role && $role['is_system'] == 1) {
            return ['success' => false, 'message' => 'System roles cannot be edited.'];
        }

        // Check slug uniqueness (excluding current)
        $slug = $this->createSlug($data['name']);
        $check = $this->db->prepare("SELECT COUNT(*) FROM roles WHERE school_id = ? AND slug = ? AND id != ?");
        $check->execute([$this->schoolId, $slug, $id]);
        if ($check->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Another role with this name already exists.'];
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE roles SET
                    name = ?,
                    slug = ?,
                    description = ?,
                    permissions = ?
                WHERE school_id = ? AND id = ?
            ");
            $stmt->execute([
                $data['name'],
                $slug,
                $data['description'] ?? null,
                $data['permissions'] ?? null,
                $this->schoolId,
                $id
            ]);
            return ['success' => true, 'message' => 'Role updated successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to update role: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a role (non-system only)
     */
    public function delete($id) {
        // Check if role is system
        $check = $this->db->prepare("SELECT is_system FROM roles WHERE school_id = ? AND id = ?");
        $check->execute([$this->schoolId, $id]);
        $role = $check->fetch(PDO::FETCH_ASSOC);
        if (!$role) {
            return ['success' => false, 'message' => 'Role not found.'];
        }
        if ($role['is_system'] == 1) {
            return ['success' => false, 'message' => 'System roles cannot be deleted.'];
        }

        // Check if role is assigned to any user
        $check = $this->db->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Cannot delete role because it is assigned to users.'];
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM roles WHERE school_id = ? AND id = ?");
            $stmt->execute([$this->schoolId, $id]);
            return ['success' => true, 'message' => 'Role deleted successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete role: ' . $e->getMessage()];
        }
    }

    /**
     * Create a URL-friendly slug from name
     */
    private function createSlug($name) {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug;
    }
}