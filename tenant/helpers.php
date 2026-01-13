<?php
/**
 * Helper functions for school portal
 */

// Generate URL for school pages
function school_url($path = '', $includeSchoolSlug = true, $includeUserType = true) {
    $schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
    $userType = $GLOBALS['USER_TYPE'] ?? 'admin';
    
    if (!$includeSchoolSlug || empty($schoolSlug)) {
        return "#";
    }
    
    $url = "/academixsuite/tenant/{$schoolSlug}";
    
    if ($includeUserType && !empty($userType)) {
        $url .= "/{$userType}";
    }
    
    $path = ltrim($path, '/');
    if (!empty($path)) {
        $url .= "/{$path}";
    }
    
    return $url;
}

// Get current school info
function current_school() {
    return $GLOBALS['SCHOOL_DATA'] ?? [];
}

// Get current user info
function current_user() {
    return $GLOBALS['SCHOOL_AUTH'] ?? [];
}

// Check if user has specific role/type
function is_user_type($type) {
    return ($GLOBALS['USER_TYPE'] ?? '') === $type;
}

// Redirect within school portal
function school_redirect($path) {
    $url = school_url($path);
    if ($url !== '#') {
        header("Location: {$url}");
        exit;
    }
}

// Add query parameters to URL
function add_query_params($url, $params = []) {
    if (empty($params)) {
        return $url;
    }
    
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . http_build_query($params);
}

// Generate navigation link with active class
function nav_link($path, $label, $icon = '', $exact = false) {
    $currentPage = $GLOBALS['CURRENT_PAGE'] ?? '';
    $currentPath = $GLOBALS['CURRENT_PATH'] ?? '';
    
    $fullPath = $currentPath . $currentPage;
    $isActive = false;
    
    if ($exact) {
        $isActive = ($path === $fullPath || $path === $currentPage);
    } else {
        $isActive = strpos($fullPath, $path) === 0;
    }
    
    $activeClass = $isActive ? 'active-link' : '';
    $url = school_url($path);
    
    $iconHtml = $icon ? "<i class='{$icon}'></i> " : '';
    
    return "<a href='{$url}' class='sidebar-link {$activeClass}'>{$iconHtml}{$label}</a>";
}

// Check if school is on trial
function is_trial_school() {
    $school = current_school();
    return ($school['status'] ?? '') === 'trial';
}

// Get days left in trial
function trial_days_left() {
    $school = current_school();
    if (!is_trial_school() || empty($school['trial_ends_at'])) {
        return null;
    }
    
    $endDate = new DateTime($school['trial_ends_at']);
    $now = new DateTime();
    $interval = $now->diff($endDate);
    
    return $interval->days;
}

// Get breadcrumb navigation
function breadcrumbs() {
    $schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
    $userType = $GLOBALS['USER_TYPE'] ?? '';
    $currentPage = $GLOBALS['CURRENT_PAGE'] ?? '';
    $currentPath = $GLOBALS['CURRENT_PATH'] ?? '';
    
    $crumbs = [
        [
            'title' => 'Dashboard',
            'url' => school_url('dashboard.php'),
            'active' => ($currentPage === 'dashboard.php' && empty($currentPath))
        ]
    ];
    
    // Add path segments
    $segments = explode('/', trim($currentPath, '/'));
    $accumulatedPath = '';
    
    foreach ($segments as $segment) {
        if (!empty($segment)) {
            $accumulatedPath .= $segment . '/';
            $crumbs[] = [
                'title' => ucfirst(str_replace('-', ' ', $segment)),
                'url' => school_url($accumulatedPath),
                'active' => false
            ];
        }
    }
    
    // Add current page
    if ($currentPage !== 'dashboard.php') {
        $pageTitle = ucfirst(str_replace(['-', '.php'], [' ', ''], $currentPage));
        $crumbs[] = [
            'title' => $pageTitle,
            'url' => school_url($currentPath . $currentPage),
            'active' => true
        ];
    } else {
        $crumbs[0]['active'] = true;
    }
    
    return $crumbs;
}
?>