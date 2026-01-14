<?php
namespace AcademixSuite\Shared\ParentPortal;

class ParentAuth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function login($email, $password) {
        // Find parent by email across all schools
        $parent = $this->db->selectOne(
            "SELECT p.*, s.id as school_id, s.name as school_name 
            FROM parents p
            JOIN schools s ON p.school_id = s.id
            WHERE p.email = ? AND p.is_active = 1 AND s.status = 'active'",
            [$email]
        );
        
        if (!$parent) {
            throw new \Exception('Parent not found');
        }
        
        // Verify password
        if (!password_verify($password, $parent->password)) {
            throw new \Exception('Invalid password');
        }
        
        // Get children
        $children = $this->getChildren($parent->id, $parent->school_id);
        
        // Create session
        $_SESSION['parent'] = [
            'id' => $parent->id,
            'email' => $parent->email,
            'name' => $parent->name,
            'school_id' => $parent->school_id,
            'school_name' => $parent->school_name,
            'children' => $children,
            'logged_in' => true
        ];
        
        return $_SESSION['parent'];
    }
    
    public function getChildren($parentId, $schoolId) {
        return $this->db->selectAll(
            "SELECT s.*, c.name as class_name 
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.parent_id = ? AND s.school_id = ? AND s.status = 'active'
            ORDER BY s.class_id, s.first_name",
            [$parentId, $schoolId]
        );
    }
    
    public function requestAccess($parentEmail, $studentId, $schoolId) {
        $service = new AcademixSuite\Services\ParentPortalService();
        
        try {
            $token = $service->grantAccess($parentEmail, $studentId, $schoolId);
            
            return [
                'success' => true,
                'message' => 'Access granted. Check your email for login details.',
                'token' => $token
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}