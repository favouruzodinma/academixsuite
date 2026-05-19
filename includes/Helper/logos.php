<?php
/**
 * Logo helpers — one source of truth for "show the school's logo" vs.
 * "show the AcademixSuite logo."
 *
 * Rules of thumb:
 *   - Tenant areas (admin/teacher/student/parent dashboards, per-school login,
 *     public school landing page) → school_logo_url($school)
 *   - Apex / discovery pages (tenant/index.php) and EVERY email → academix_logo_url()
 *
 * Both helpers accept $absolute=true when you need an https://... URL (emails
 * especially, since the recipient's mail client has no notion of "your site").
 */

if (!function_exists('academix_logo_path')) {
    /**
     * Relative path of the AcademixSuite brand mark inside the docroot.
     * The file is shipped at tenant/assets/images/logo.png.
     */
    function academix_logo_path(): string {
        return 'tenant/assets/images/logo.png';
    }
}

if (!function_exists('academix_logo_icon_path')) {
    function academix_logo_icon_path(): string {
        return 'tenant/assets/images/logo-icon.png';
    }
}

if (!function_exists('academix_absolute_url')) {
    /**
     * Resolve a relative path to an absolute URL. Uses APP_URL when defined,
     * otherwise derives from the current request (handy from CLI/cron, where
     * we still need absolute links inside emails).
     */
    function academix_absolute_url(string $relative): string {
        $relative = '/' . ltrim($relative, '/');
        if (defined('APP_URL') && APP_URL) {
            return rtrim(APP_URL, '/') . $relative;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'academixsuite.com';
        return $scheme . '://' . $host . $relative;
    }
}

if (!function_exists('academix_logo_url')) {
    /**
     * AcademixSuite brand logo URL. Use this on:
     *   - tenant/index.php (the public discovery page)
     *   - every email template (always pass $absolute=true)
     *   - the platform/super-admin login
     */
    function academix_logo_url(bool $absolute = false): string {
        $rel = '/' . academix_logo_path();
        return $absolute ? academix_absolute_url($rel) : $rel;
    }
}

if (!function_exists('school_logo_url')) {
    /**
     * Return a usable image URL for $school. Falls back to the AcademixSuite
     * logo when the school hasn't uploaded one or the file no longer exists.
     *
     * Accepts the array shape returned by `SELECT * FROM schools …`.
     */
    function school_logo_url($school, bool $absolute = false): string {
        $path = '';
        if (is_array($school)) {
            $path = (string) ($school['logo_path'] ?? '');
        } elseif (is_string($school)) {
            $path = $school;
        }
        $path = trim($path);

        // Already absolute? (e.g. cloud-hosted) — use as-is.
        if ($path !== '' && preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if ($path !== '') {
            $rootDir = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            $absFile = $rootDir . '/' . ltrim($path, '/');
            if (is_file($absFile)) {
                $rel = '/' . ltrim($path, '/');
                return $absolute ? academix_absolute_url($rel) : $rel;
            }
        }

        // Fallback — AcademixSuite logo so the layout never has a broken image.
        return academix_logo_url($absolute);
    }
}

if (!function_exists('logo_img_tag')) {
    /**
     * Convenience HTML helper. Renders an <img> with safe escaping and a
     * sensible default alt text. Use for one-liners in tenant templates.
     */
    function logo_img_tag($schoolOrUrl = null, array $attrs = []): string {
        if (is_string($schoolOrUrl)) {
            $url = $schoolOrUrl;
            $alt = $attrs['alt'] ?? 'AcademixSuite';
        } else {
            $url = school_logo_url($schoolOrUrl);
            $alt = $attrs['alt'] ?? (is_array($schoolOrUrl) && !empty($schoolOrUrl['name']) ? $schoolOrUrl['name'] . ' logo' : 'School logo');
        }
        $attrs['alt'] = $alt;
        $attrs['src'] = $url;
        $html = '<img';
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $html . '>';
    }
}
