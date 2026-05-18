<?php
/**
 * Shared session options for tenant-facing requests.
 */

if (!function_exists('academix_session_options')) {
    function academix_session_options(array $overrides = []) {
        $options = [
            'cookie_lifetime' => 86400,
            'cookie_httponly' => true,
            'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'cookie_samesite' => 'Lax',
        ];

        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host);

        if ($host === 'academixsuite.com' || substr($host, -strlen('.academixsuite.com')) === '.academixsuite.com') {
            $options['cookie_domain'] = '.academixsuite.com';
        }

        return array_replace($options, $overrides);
    }
}
