<?php
/**
 * Notifications helper (toast + modal).
 *
 * The actual UI lives in:
 *   tenant/assets/css/notifications.css
 *   tenant/assets/js/notifications.js
 *
 * Server-side, queue flash messages with flash_set() — they survive one
 * redirect via $_SESSION['__as_flashes']. Render them with flash_render()
 * inside <head> (or at end of <body>) and they'll appear as toasts.
 *
 * For inline (non-redirect) toasts call notif_inline('success', 'Saved.').
 *
 * The path of the CSS/JS files is computed via notif_asset_base() which uses
 * the host's structure:
 *   - on a school subdomain → /assets/...
 *   - on the apex / platform → /tenant/assets/...
 */

if (!function_exists('notif_asset_base')) {
    function notif_asset_base(): string {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // Wildcard school subdomain — Apache rewrites /assets/* → /tenant/assets/*.
        if (preg_match('/^[a-z0-9-]+\.academixsuite\.com$/i', $host)
            && !preg_match('/^(www|app|admin|platform|api|mail|webmail|cpanel|whm|ftp|autodiscover)\./i', $host)) {
            return '/assets';
        }
        return '/tenant/assets';
    }
}

if (!function_exists('flash_set')) {
    /**
     * Queue a flash message. Survives one redirect.
     *
     * @param string $type    success | error | warning | info
     * @param string $message
     * @param string|null $title optional
     */
    function flash_set(string $type, string $message, ?string $title = null): void {
        if (session_status() === PHP_SESSION_NONE) {
            // We won't start one here — that's a layout concern. Just bail.
            return;
        }
        if (!isset($_SESSION['__as_flashes']) || !is_array($_SESSION['__as_flashes'])) {
            $_SESSION['__as_flashes'] = [];
        }
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        $_SESSION['__as_flashes'][] = [
            'type'    => $type,
            'message' => $message,
            'title'   => $title,
        ];
    }
}

if (!function_exists('flash_pull')) {
    function flash_pull(): array {
        if (session_status() === PHP_SESSION_NONE || empty($_SESSION['__as_flashes'])) {
            return [];
        }
        $out = $_SESSION['__as_flashes'];
        unset($_SESSION['__as_flashes']);
        return is_array($out) ? $out : [];
    }
}

if (!function_exists('flash_render')) {
    /**
     * Emit <link>/<script> tags and a one-shot bootstrap of any pending
     * flashes. Safe to call once per request near the top of <body> (or
     * just before </body>); calling it more than once is a no-op.
     */
    function flash_render(): void {
        static $emitted = false;
        if ($emitted) return;
        $emitted = true;

        $base = notif_asset_base();
        $flashes = flash_pull();
        // Render JSON for the bootstrap before the script tag loads.
        $payload = json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) $payload = '[]';

        echo '<link rel="stylesheet" href="', htmlspecialchars($base . '/css/notifications.css', ENT_QUOTES), '">', "\n";
        echo '<script>window.__AS_FLASHES__ = ', $payload, ';</script>', "\n";
        echo '<script src="', htmlspecialchars($base . '/js/notifications.js', ENT_QUOTES), '" defer></script>', "\n";
    }
}

if (!function_exists('notif_inline')) {
    /**
     * Inline (no redirect) toast. Echoes a <script> that calls Toast.<type>().
     * Use right after a successful AJAX-style POST that re-renders the page.
     */
    function notif_inline(string $type, string $message, ?string $title = null): void {
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        $payload = json_encode([
            ['type' => $type, 'message' => $message, 'title' => $title],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<script>(function(){',
                'function go(){window.__AS_FLASHES__=(window.__AS_FLASHES__||[]).concat(', $payload, ');',
                'if(window.Toast){var f=window.__AS_FLASHES__.pop();(window.Toast[f.type]||window.Toast.info)(f.message,{title:f.title});}}',
                'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}',
             '})();</script>';
    }
}
