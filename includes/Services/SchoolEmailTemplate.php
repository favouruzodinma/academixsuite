<?php
/**
 * SchoolEmailTemplate
 *
 * Generates school-branded HTML email layouts.
 * Every email shows the school's own logo, name, and primary colour —
 * not the AcademixSuite platform branding.
 *
 * Usage:
 *   $tpl = new SchoolEmailTemplate($school);          // $school = row from `schools`
 *   $html = $tpl->render($subject, $bodyHtml, $opts); // returns full HTML email
 */

class SchoolEmailTemplate
{
    private array  $school;
    private string $primaryColor;
    private string $schoolName;
    private string $logoUrl;
    private string $portalUrl;
    private string $supportEmail;

    public function __construct(array $school)
    {
        $this->school       = $school;
        $this->schoolName   = $school['name']          ?? 'School';
        $this->primaryColor = $this->safeColor($school['primary_color'] ?? '#25A194');
        $this->logoUrl      = $this->resolveLogoUrl($school['logo_path'] ?? '');
        $this->portalUrl    = defined('APP_URL')
            ? rtrim(APP_URL, '/') . '/tenant/' . urlencode($school['slug'] ?? '')
            : '#';
        $this->supportEmail = $school['email'] ?? 'admin@' . ($school['slug'] ?? 'school') . '.academixsuite.com';
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Render a complete branded HTML email.
     *
     * @param string $subject   Email subject (used in <title> and preview text)
     * @param string $bodyHtml  Inner body content (already HTML or plain text)
     * @param array  $opts {
     *   eyebrow?    string  Small label above the title (default: 'Message')
     *   cta_text?   string  Call-to-action button label
     *   cta_url?    string  Call-to-action button URL
     *   greeting?   string  Opening line (default: 'Dear {recipient_name},')
     *   footer_note? string Custom footer note
     * }
     */
    public function render(string $subject, string $bodyHtml, array $opts = []): string
    {
        $e          = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $eyebrow    = $e($opts['eyebrow']    ?? 'Message from ' . $this->schoolName);
        $title      = $e($subject);
        $greeting   = $opts['greeting']      ?? '';
        $footerNote = $e($opts['footer_note'] ?? 'This email was sent from ' . $this->schoolName . ' via AcademixSuite.');
        $year       = date('Y');

        $button = '';
        if (!empty($opts['cta_text']) && !empty($opts['cta_url'])) {
            $button = '<tr><td align="left" style="padding:8px 0 24px 0;">
                <a href="' . $e($opts['cta_url']) . '"
                   style="display:inline-block;background:' . $e($this->primaryColor) . ';color:#ffffff;
                          text-decoration:none;font-weight:700;font-size:14px;line-height:20px;
                          padding:13px 24px;border-radius:6px;">' . $e($opts['cta_text']) . '</a>
            </td></tr>';
        }

        $greetingRow = $greeting
            ? '<div style="font-size:15px;line-height:24px;color:#334155;margin-bottom:16px;">' . $e($greeting) . '</div>'
            : '';

        // Sanitise body HTML — allow safe tags only
        $safeBody = $this->sanitise($bodyHtml);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$title}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#eef2f7;margin:0;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:640px;background:#ffffff;border:1px solid #dbe3ef;
                          border-radius:10px;overflow:hidden;">

                <!-- ── Header / Logo ─────────────────────────────────────────── -->
                <tr>
                    <td style="background:{$this->primaryColor};padding:20px 32px;">
                        {$this->logoBlock()}
                        <div style="font-size:13px;line-height:20px;color:rgba(255,255,255,.75);margin-top:6px;">
                            {$e($this->schoolName)}
                        </div>
                    </td>
                </tr>

                <!-- ── Body ─────────────────────────────────────────────────── -->
                <tr>
                    <td style="padding:34px 32px 12px 32px;">
                        <div style="font-size:12px;line-height:18px;font-weight:700;letter-spacing:.08em;
                                    text-transform:uppercase;color:{$this->primaryColor};margin-bottom:10px;">
                            {$eyebrow}
                        </div>
                        <h1 style="margin:0 0 12px 0;font-size:24px;line-height:32px;
                                   color:#0f172a;font-weight:800;">{$title}</h1>
                        {$greetingRow}
                        <div style="font-size:15px;line-height:26px;color:#334155;">
                            {$safeBody}
                        </div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            {$button}
                        </table>
                    </td>
                </tr>

                <!-- ── Footer ────────────────────────────────────────────────── -->
                <tr>
                    <td style="padding:22px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                        <div style="font-size:12px;line-height:19px;color:#64748b;">{$footerNote}</div>
                        <div style="font-size:12px;line-height:19px;color:#64748b;margin-top:8px;">
                            Questions? Contact us at
                            <a href="mailto:{$e($this->supportEmail)}"
                               style="color:{$this->primaryColor};text-decoration:none;">{$e($this->supportEmail)}</a>.
                        </div>
                        <div style="font-size:11px;line-height:17px;color:#94a3b8;margin-top:12px;">
                            &copy; {$year} {$e($this->schoolName)}. Powered by AcademixSuite.
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
HTML;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function logoBlock(): string
    {
        if ($this->logoUrl) {
            return '<img src="' . htmlspecialchars($this->logoUrl, ENT_QUOTES, 'UTF-8') . '" '
                 . 'alt="' . htmlspecialchars($this->schoolName, ENT_QUOTES, 'UTF-8') . '" '
                 . 'style="height:48px;max-width:200px;width:auto;display:block;object-fit:contain;">';
        }

        // Text fallback when no logo is uploaded
        $initials = strtoupper(substr(preg_replace('/[^a-zA-Z ]/', '', $this->schoolName) ?: 'S', 0, 2));
        return '<div style="display:inline-block;background:rgba(255,255,255,.2);
                    color:#fff;font-size:22px;font-weight:800;letter-spacing:.04em;
                    padding:8px 16px;border-radius:6px;">' . htmlspecialchars($initials) . '</div>';
    }

    private function resolveLogoUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';

        if (preg_match('#^https?://#i', $path)) return $path;

        // Convert relative path to absolute public URL
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://www.academixsuite.com';
        return $base . '/' . ltrim($path, '/');
    }

    private function safeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#25A194';
    }

    private function sanitise(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div><h1><h2><h3><h4><table><tr><td><th>';
        $html    = strip_tags($html, $allowed);
        // Strip event handlers and javascript: hrefs
        $html    = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html);
        $html    = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $html);
        $html    = preg_replace('/href\s*=\s*"javascript:[^"]*"/i', 'href="#"', $html);
        return $html;
    }
}
