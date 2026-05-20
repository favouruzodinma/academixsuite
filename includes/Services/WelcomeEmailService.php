<?php
/**
 * WelcomeEmailService
 *
 * Sends a school-branded welcome/credentials email to a newly created user
 * (student, parent/guardian, teacher, or staff).
 *
 * Uses SchoolEmailTemplate for the branded HTML shell and EmailService for
 * SMTP delivery — the same stack used by SchoolEmailSender.
 *
 * Usage:
 *   $svc = new WelcomeEmailService($school, $emailService);
 *   $ok  = $svc->send('teacher', [
 *       'name'     => 'John Doe',
 *       'email'    => 'john@example.com',
 *       'username' => 'john@example.com',   // or separate username
 *       'password' => 'abc12345',
 *   ]);
 */

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/SchoolEmailTemplate.php';

class WelcomeEmailService
{
    private array        $school;
    private EmailService $mailer;

    public function __construct(array $school, ?EmailService $emailService = null)
    {
        $this->school = $school;
        $this->mailer = $emailService ?? new EmailService();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Send a welcome email with login credentials.
     *
     * @param string $role     student | parent | guardian | teacher | staff | admin
     * @param array  $user {
     *   name:      string  Full display name
     *   email:     string  Email address (used for To: and as shown username if no separate username)
     *   username:  string  Login username (optional — defaults to email)
     *   password:  string  Plain-text password (only send on account creation)
     * }
     * @return bool  true if delivery was attempted without exception
     */
    public function send(string $role, array $user): bool
    {
        $to = trim($user['email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("WelcomeEmailService: invalid email address for {$role}: '{$to}'");
            return false;
        }

        $schoolName = $this->school['name'] ?? 'School';
        $role       = strtolower(trim($role));
        $name       = trim($user['name'] ?? 'User');
        $username   = trim($user['username'] ?? $to);
        $password   = $user['password'] ?? '';

        $portalUrl   = $this->portalLoginUrl($role);
        $roleLabel   = $this->roleLabel($role);
        $subject     = "Welcome to {$schoolName} — Your {$roleLabel} Portal Access";

        /* ── Build body HTML ── */
        $credRows = $this->credentialRows($username, $password);
        $featureList = $this->featureList($role);

        $bodyHtml = <<<HTML
<p>Dear {$name},</p>

<p>Your <strong>{$roleLabel}</strong> account at <strong>{$schoolName}</strong> has been created successfully.
You can now sign in and get started.</p>

{$featureList}

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin:20px 0;">
    <tr>
        <td style="background:#f8fafc;padding:12px 16px;font-size:12px;font-weight:700;
                   letter-spacing:.06em;text-transform:uppercase;color:#64748b;
                   border-bottom:1px solid #e2e8f0;" colspan="2">
            Your Login Credentials
        </td>
    </tr>
    {$credRows}
</table>

<p style="font-size:13px;color:#64748b;margin-top:18px;">
    <strong>Security reminder:</strong> Please change your password after your first login.
    Never share your credentials with anyone — the school will never ask for your password.
</p>
HTML;

        /* ── Render full branded email ── */
        $tpl  = new SchoolEmailTemplate($this->school);
        $html = $tpl->render($subject, $bodyHtml, [
            'eyebrow'     => $roleLabel . ' · ' . $schoolName,
            'greeting'    => '',   // greeting is already inside bodyHtml
            'cta_text'    => 'Go to ' . $roleLabel . ' Portal',
            'cta_url'     => $portalUrl,
            'footer_note' => 'Your account was created by ' . $schoolName . ' via AcademixSuite.',
        ]);

        $fromName  = $schoolName;
        $fromEmail = $this->school['email'] ?? null;
        $opts      = $fromEmail
            ? ['from_email' => $fromEmail, 'from_name' => $fromName]
            : [];

        try {
            $result = $this->mailer->sendEmail($to, $subject, $html, null, $opts);
            if (!($result['success'] ?? false)) {
                error_log("WelcomeEmailService: mailer returned failure for {$to}: " . ($result['message'] ?? 'unknown'));
            }
            return (bool) ($result['success'] ?? false);
        } catch (Throwable $e) {
            error_log("WelcomeEmailService: exception sending to {$to}: " . $e->getMessage());
            return false;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function credentialRows(string $username, string $password): string
    {
        $e    = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        $pairs = [
            'Email / Username' => $username,
        ];
        if ($password !== '') {
            $pairs['Password'] = $password;
        }

        $alt = false;
        foreach ($pairs as $label => $value) {
            $bg    = $alt ? '#ffffff' : '#f8fafc';
            $rows .= "<tr>
                <td style='padding:11px 16px;font-size:13px;font-weight:600;color:#475569;
                           background:{$bg};width:38%;border-bottom:1px solid #e2e8f0;'>"
                       . $e($label) . "</td>
                <td style='padding:11px 16px;font-size:13px;color:#0f172a;font-family:monospace;
                           background:{$bg};border-bottom:1px solid #e2e8f0;'>"
                       . $e($value) . "</td>
            </tr>";
            $alt = !$alt;
        }
        return $rows;
    }

    private function featureList(string $role): string
    {
        $lists = [
            'student' => [
                'View your timetable and upcoming lessons',
                'Check grades, scores, and teacher comments',
                'Submit and track assignments',
                'View attendance records',
                'Pay fees and download receipts',
            ],
            'parent' => [
                "View your child's academic progress and grades",
                'Track daily attendance',
                'Receive school announcements and notices',
                'Message teachers directly',
                'View and settle fee invoices',
            ],
            'guardian' => [
                "Monitor your child's academic progress",
                'Track attendance and punctuality',
                'Receive school announcements',
                'Communicate with teachers',
            ],
            'teacher' => [
                'Manage your classes and student rosters',
                'Mark attendance and enter grades',
                'Create and mark assignments',
                'Send class announcements',
                'Use the AI assistant to speed up daily work',
            ],
            'staff' => [
                'Access daily work queue and schedules',
                'View payslips and leave balances',
                'Manage assigned operations',
                'Receive internal announcements',
            ],
            'admin' => [
                'Full school management dashboard',
                'Manage students, teachers, and staff',
                'Finance, reports, and settings',
            ],
        ];

        $items = $lists[$role] ?? $lists['staff'];
        $li    = implode('', array_map(
            fn($item) => "<li style='margin-bottom:5px;color:#334155;font-size:14px;'>{$item}</li>",
            $items
        ));
        return "<p style='margin-bottom:6px;font-weight:600;color:#0f172a;'>With your portal you can:</p>
                <ul style='margin:0 0 12px 0;padding-left:20px;'>{$li}</ul>";
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'student'          => 'Student',
            'parent', 'guardian' => 'Parent / Guardian',
            'teacher'          => 'Teacher',
            'admin'            => 'Admin',
            default            => 'Staff',
        };
    }

    private function portalLoginUrl(string $role): string
    {
        $slug = $this->school['slug'] ?? '';
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://www.academixsuite.com';

        /* If the app has a role-aware login helper, use it */
        if (function_exists('school_portal_url')) {
            return school_portal_url($slug, 'login.php', true);
        }

        /* Otherwise build a direct URL */
        if ($slug) {
            return "{$base}/tenant/" . rawurlencode($slug) . "/login.php";
        }
        return "{$base}/login.php";
    }
}
