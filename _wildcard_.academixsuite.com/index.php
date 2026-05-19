<?php
/**
 * Wildcard school subdomain bridge for cPanel.
 *
 * This file lets cPanel keep *.academixsuite.com in the separate
 * /public_html/_wildcard_.academixsuite.com document root while the actual
 * AcademixSuite application stays in /public_html.
 */

define('ACADEMIX_WILDCARD_BRIDGE', true);
define('ACADEMIX_WILDCARD_DOCROOT', __DIR__);

$mainRoot = realpath(getenv('ACADEMIX_MAIN_ROOT') ?: __DIR__ . '/..');
if (!$mainRoot || !is_file($mainRoot . '/includes/autoload.php')) {
    http_response_code(500);
    echo 'Wildcard bridge is not connected to the main application root.';
    exit;
}

define('ACADEMIX_MAIN_ROOT', $mainRoot);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $mainRoot);
}

chdir($mainRoot);

function academix_bridge_base_host() {
    $configured = getenv('ACADEMIX_BASE_HOST');
    if ($configured) {
        return strtolower(preg_replace('/:\d+$/', '', $configured));
    }

    return 'academixsuite.com';
}

function academix_bridge_reserved_subdomains() {
    return [
        'www',
        'app',
        'admin',
        'platform',
        'api',
        'mail',
        'webmail',
        'cpanel',
        'whm',
        'ftp',
        'autodiscover',
        '_wildcard_'
    ];
}

function academix_bridge_school_slug() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    $baseHost = academix_bridge_base_host();
    $suffix = '.' . $baseHost;

    if ($host === '' || $host === $baseHost || $host === 'www.' . $baseHost) {
        return null;
    }

    if (substr($host, -strlen($suffix)) !== $suffix) {
        return null;
    }

    $subdomain = substr($host, 0, -strlen($suffix));
    if ($subdomain === '' || strpos($subdomain, '.') !== false) {
        return null;
    }

    if (in_array($subdomain, academix_bridge_reserved_subdomains(), true)) {
        return null;
    }

    return preg_match('/^[a-z0-9-]+$/', $subdomain) ? $subdomain : null;
}

function academix_bridge_require($relativePath) {
    $relativePath = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
    $target = ACADEMIX_MAIN_ROOT . '/' . $relativePath;

    if (!is_file($target)) {
        http_response_code(404);
        echo 'Requested application file was not found.';
        exit;
    }

    require $target;
    exit;
}

function academix_bridge_route_role_page($schoolSlug, $userType, $page = 'dashboard.php') {
    $_GET['school_slug'] = $schoolSlug;
    $_GET['user_type'] = $userType;
    $_GET['page'] = $page ?: 'dashboard.php';
    academix_bridge_require('tenant/router.php');
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = trim(strtok($requestUri, '?') ?: '/', '/');
$schoolSlug = academix_bridge_school_slug();

if (!$schoolSlug) {
    http_response_code(404);
    echo 'School subdomain was not recognized.';
    exit;
}

if ($requestPath === '' || $requestPath === 'index.php') {
    $_GET['slug'] = $schoolSlug;
    academix_bridge_require('tenant/school_profile.php');
}

if ($requestPath === 'login' || $requestPath === 'login.php') {
    $_GET['school_slug'] = $schoolSlug;
    academix_bridge_require('tenant/login.php');
}

if ($requestPath === 'logout' || $requestPath === 'logout.php') {
    $_GET['school_slug'] = $schoolSlug;
    academix_bridge_require('tenant/logout.php');
}

if ($requestPath === 'forgot-password' || $requestPath === 'forgot-password.php') {
    $_GET['school_slug'] = $schoolSlug;
    academix_bridge_require('tenant/forgot-password.php');
}

if (preg_match('#^api/([a-zA-Z0-9_\-/]+\.php)$#', $requestPath, $matches)) {
    $_GET['school_slug'] = $schoolSlug;
    academix_bridge_require('tenant/api/' . $matches[1]);
}

if (preg_match('#^(admin|admin2|teacher|student|parent|staff)(?:/(.*))?$#', $requestPath, $matches)) {
    $page = $matches[2] ?? 'dashboard.php';
    $page = trim($page, '/');
    if ($page === '') {
        $page = 'dashboard.php';
    }
    if (pathinfo($page, PATHINFO_EXTENSION) === '') {
        $page .= '.php';
    }

    academix_bridge_route_role_page($schoolSlug, $matches[1], $page);
}

if (preg_match('#^tenant/(login|logout|forgot-password)(?:\.php)?$#', $requestPath, $matches)) {
    $_GET['school_slug'] = $schoolSlug;
    $file = $matches[1] === 'forgot-password' ? 'forgot-password.php' : $matches[1] . '.php';
    academix_bridge_require('tenant/' . $file);
}

if ($requestPath === 'tenant/school_profile.php') {
    $_GET['slug'] = $_GET['slug'] ?? $schoolSlug;
    academix_bridge_require('tenant/school_profile.php');
}

if (preg_match('#^tenant/([a-zA-Z0-9_-]+)/(admin|admin2|teacher|student|parent|staff)(?:/(.*))?$#', $requestPath, $matches)) {
    $page = $matches[3] ?? 'dashboard.php';
    if (pathinfo($page, PATHINFO_EXTENSION) === '') {
        $page .= '.php';
    }
    academix_bridge_route_role_page($matches[1], $matches[2], $page);
}

http_response_code(404);
echo 'Page not found.';
exit;
