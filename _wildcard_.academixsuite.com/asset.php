<?php
/**
 * Static asset bridge for cPanel wildcard subdomains.
 *
 * cPanel is currently serving *.academixsuite.com from:
 * /public_html/_wildcard_.academixsuite.com
 *
 * This file safely serves public asset files from the real application root:
 * /public_html
 */

$mainRoot = realpath(getenv('ACADEMIX_MAIN_ROOT') ?: __DIR__ . '/..');
if (!$mainRoot || !is_file($mainRoot . '/includes/autoload.php')) {
    http_response_code(500);
    echo 'Wildcard bridge is not connected to the main application root.';
    exit;
}

$path = $_GET['path'] ?? '';
$path = rawurldecode((string) $path);
$path = str_replace('\\', '/', $path);
$path = ltrim($path, '/');

if ($path === '' || strpos($path, '..') !== false || preg_match('/[\x00-\x1F]/', $path)) {
    http_response_code(400);
    echo 'Invalid asset path.';
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$allowedExtensions = [
    'css', 'js', 'map', 'json',
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg',
    'woff', 'woff2', 'ttf', 'eot',
    'pdf', 'txt'
];

if (!in_array($extension, $allowedExtensions, true)) {
    http_response_code(403);
    echo 'Asset type is not allowed.';
    exit;
}

$fullPath = realpath($mainRoot . '/' . $path);
if (!$fullPath || !is_file($fullPath)) {
    http_response_code(404);
    echo 'Asset not found.';
    exit;
}

$allowedRoots = array_filter(array_map('realpath', [
    $mainRoot . '/tenant/assets',
    $mainRoot . '/assets/uploads',
    $mainRoot . '/uploads',
    $mainRoot . '/public',
    $mainRoot . '/wp-content/uploads',
]));

$isAllowed = false;
foreach ($allowedRoots as $allowedRoot) {
    if ($fullPath === $allowedRoot || strpos($fullPath, $allowedRoot . DIRECTORY_SEPARATOR) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    echo 'Asset location is not allowed.';
    exit;
}

$mimeTypes = [
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'map' => 'application/json; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'svg' => 'image/svg+xml',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=UTF-8',
];

$lastModified = filemtime($fullPath);
$etag = '"' . sha1($fullPath . '|' . filesize($fullPath) . '|' . $lastModified) . '"';

header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=604800');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('ETag: ' . $etag);
if (in_array($extension, ['eot', 'woff', 'woff2', 'ttf', 'svg'], true)) {
    header('Access-Control-Allow-Origin: *');
}

if (
    ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag ||
    strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') === $lastModified
) {
    http_response_code(304);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($fullPath);
}
exit;
