<?php
/**
 * School Configuration - Dynamically loaded
 */

// This file is included by all portal pages
// It sets up the school context

function getCurrentSchool() {
    // Get school from session or URL
    $schoolSlug = '';
    
    // Try URL first
    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('/\/school\/([a-z0-9_-]+)\//', $currentUrl, $matches)) {
        $schoolSlug = $matches[1];
    }
    
    // Fallback to session
    if (empty($schoolSlug)) {
        $schoolSlug = $_SESSION['current_school']['slug'] ?? '';
    }
    
    if (empty($schoolSlug)) {
        return null;
    }
    
    // Load from database
    require_once __DIR__ . '/../../../includes/autoload.php';
    
    try {
        $platformDb = Database::getPlatformConnection();
        $stmt = $platformDb->prepare("SELECT * FROM schools WHERE slug = ?");
        $stmt->execute([$schoolSlug]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Config error: " . $e->getMessage());
        return null;
    }
}

// School constants
$currentSchool = getCurrentSchool();
if ($currentSchool) {
    define('SCHOOL_ID', $currentSchool['id']);
    define('SCHOOL_SLUG', $currentSchool['slug']);
    define('SCHOOL_NAME', $currentSchool['name']);
    define('SCHOOL_DB_NAME', $currentSchool['database_name'] ?? '');
} else {
    // Default values (will be redirected anyway)
    define('SCHOOL_ID', 0);
    define('SCHOOL_SLUG', '');
    define('SCHOOL_NAME', 'Unknown School');
    define('SCHOOL_DB_NAME', '');
}
?>