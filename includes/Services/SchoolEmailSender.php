<?php
/**
 * SchoolEmailSender
 *
 * Resolves an audience keyword (parents | teachers | staff | students | all)
 * to real email addresses in the school database, then sends a school-branded
 * email to each one via the existing EmailService.
 *
 * Usage:
 *   $sender  = new SchoolEmailSender($schoolDb, $school, $emailService);
 *   $preview = $sender->resolveRecipients('parents');  // count + sample addresses
 *   $result  = $sender->send('parents', $subject, $bodyHtml, $opts);
 */

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/SchoolEmailTemplate.php';

class SchoolEmailSender
{
    private PDO          $db;
    private array        $school;
    private EmailService $mailer;
    private int          $schoolId;

    /** Maximum recipients sent synchronously before falling back to the queue. */
    private const MAX_RECIPIENTS = 500;
    private const ALLOWED_AUDIENCES = ['parents', 'teachers', 'staff', 'students', 'all'];

    public function __construct(PDO $schoolDb, array $school, ?EmailService $emailService = null)
    {
        $this->db       = $schoolDb;
        $this->school   = $school;
        $this->schoolId = (int) ($school['id'] ?? 0);
        $this->mailer   = $emailService ?? new EmailService();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Preview how many recipients an audience would reach.
     *
     * @return array{count:int, sample:string[], audience:string}
     */
    public function resolveRecipients(string $audience): array
    {
        $audience = $this->normalizeAudience($audience);
        $emails = $this->fetchEmails($audience);
        return [
            'audience' => $audience,
            'count'    => count($emails),
            'sample'   => array_slice($emails, 0, 5),
        ];
    }

    /**
     * Build the school-branded HTML and send to all resolved recipients.
     *
     * @param string $audience  parents|teachers|staff|students|all
     * @param string $subject   Email subject line
     * @param string $bodyHtml  Email body (HTML or plain text — will be sanitised)
     * @param array  $opts      Forwarded to SchoolEmailTemplate::render()
     *
     * @return array{success:bool, sent:int, failed:int, skipped:int, queued?:int, total?:int, message:string}
     */
    public function send(string $audience, string $subject, string $bodyHtml, array $opts = []): array
    {
        $audience = $this->normalizeAudience($audience);
        if (!in_array($audience, self::ALLOWED_AUDIENCES, true)) {
            return [
                'success' => false,
                'sent'    => 0,
                'failed'  => 0,
                'skipped' => 0,
                'message' => 'Invalid audience. Use parents, teachers, staff, students, or all.',
            ];
        }

        $emails = $this->fetchEmails($audience);

        if (empty($emails)) {
            return [
                'success' => false,
                'sent'    => 0, 'failed' => 0, 'skipped' => 0,
                'message' => "No valid email addresses found for audience " . $audience .".",
            ];
        }

        $tpl  = new SchoolEmailTemplate($this->school);
        $html = $tpl->render($subject, $bodyHtml, array_merge([
            'eyebrow'    => $this->audienceLabel($audience) . ' · ' . ($this->school['name'] ?? 'School'),
            'cta_text'   => 'Open School Portal',
            'cta_url'    => $this->portalUrl(),
            'footer_note' => 'You are receiving this email from '
                . ($this->school['name'] ?? 'the school')
                . ' via AcademixSuite.',
        ], $opts));

        $fromName  = $this->school['name'] ?? 'School Admin';
        $schoolEmail = trim((string) ($this->school['email'] ?? ''));

        if (!empty($opts['queue']) || count($emails) > self::MAX_RECIPIENTS) {
            return $this->queueEmails($emails, $subject, $html, $audience);
        }

        $sent = $failed = $skipped = 0;

        foreach ($emails as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }

            $mailOpts = ['from_name' => $fromName];
            if (filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
                $mailOpts['reply_to'] = $schoolEmail;
            }

            try {
                $r = $this->mailer->sendEmail($address, $subject, $html, null, $mailOpts);
                !empty($r['success']) ? $sent++ : $failed++;
            } catch (Throwable $e) {
                error_log("SchoolEmailSender: failed to {$address}: " . $e->getMessage());
                $failed++;
            }

            // Small delay to avoid rate-limit on the SMTP server
            if (($sent + $failed) % 10 === 0) usleep(200_000);
        }

        $total = count($emails);
        return [
            'success' => $sent > 0,
            'sent'    => $sent,
            'failed'  => $failed,
            'skipped' => $skipped,
            'total'   => $total,
            'message' => "Email sent to {$sent} of {$total} recipient(s)."
                . ($failed  ? " {$failed} failed."  : '')
                . ($skipped ? " {$skipped} skipped (invalid address)." : ''),
        ];
    }

    // ── Recipient resolution ──────────────────────────────────────────────────

    /**
     * Returns a de-duplicated list of email addresses for the given audience.
     */
    private function fetchEmails(string $audience): array
    {
        $audience = $this->normalizeAudience($audience);
        if (!in_array($audience, self::ALLOWED_AUDIENCES, true)) {
            return [];
        }

        $sets = [];

        if (in_array($audience, ['teachers', 'all'], true)) {
            $sets[] = $this->queryEmails(
                "SELECT DISTINCT email FROM users
                 WHERE school_id = ? AND user_type = 'teacher'
                   AND is_active = 1 AND email IS NOT NULL AND email != ''",
                [$this->schoolId]
            );
        }

        if (in_array($audience, ['staff', 'all'], true)) {
            $sets[] = $this->queryEmails(
                "SELECT DISTINCT email FROM users
                 WHERE school_id = ? AND user_type IN ('admin','staff','accountant','librarian','receptionist')
                   AND is_active = 1 AND email IS NOT NULL AND email != ''",
                [$this->schoolId]
            );
        }

        if (in_array($audience, ['parents', 'all'], true)) {
            if ($this->tableExists('guardians')) {
                $sets[] = $this->queryEmails(
                    "SELECT DISTINCT u.email
                     FROM guardians g
                     INNER JOIN users u
                        ON u.id = g.user_id AND u.school_id = g.school_id
                     WHERE g.school_id = ?
                       AND u.is_active = 1
                       AND u.email IS NOT NULL AND u.email != ''",
                    [$this->schoolId]
                );
            }

            $sets[] = $this->queryEmails(
                "SELECT DISTINCT email FROM users
                 WHERE school_id = ? AND user_type = 'parent'
                   AND is_active = 1 AND email IS NOT NULL AND email != ''",
                [$this->schoolId]
            );

            if ($this->tableExists('students') && $this->columnExists('students', 'parent_email')) {
                $sets[] = $this->queryEmails(
                    "SELECT DISTINCT parent_email AS email FROM students
                     WHERE school_id = ? AND parent_email IS NOT NULL AND parent_email != ''",
                    [$this->schoolId]
                );
            }
        }

        if (in_array($audience, ['students', 'all'], true)) {
            if ($this->tableExists('students')) {
                $sets[] = $this->queryEmails(
                    "SELECT DISTINCT u.email
                     FROM students s
                     INNER JOIN users u
                        ON u.id = s.user_id AND u.school_id = s.school_id
                     WHERE s.school_id = ?
                       AND s.status = 'active'
                       AND u.is_active = 1
                       AND u.email IS NOT NULL AND u.email != ''",
                    [$this->schoolId]
                );
            }

            $sets[] = $this->queryEmails(
                "SELECT DISTINCT email FROM users
                 WHERE school_id = ? AND user_type = 'student'
                   AND is_active = 1 AND email IS NOT NULL AND email != ''",
                [$this->schoolId]
            );
        }

        $all = array_unique(array_map('strtolower', array_merge(...$sets)));
        return array_values(array_filter($all, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    private function queryEmails(string $sql, array $params): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            error_log("SchoolEmailSender::queryEmails: " . $e->getMessage());
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $s = $this->db->prepare('SHOW TABLES LIKE ?');
            $s->execute([$table]);
            return (bool) $s->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $rows = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            return in_array($column, array_column($rows, 'Field'), true);
        } catch (Throwable $e) { return false; }
    }

    private function queueEmails(array $emails, string $subject, string $html, string $audience): array
    {
        if (!class_exists('EmailQueueManager')) {
            $queuePath = __DIR__ . '/EmailQueueManager.php';
            if (is_readable($queuePath)) {
                require_once $queuePath;
            }
        }

        if (!class_exists('EmailQueueManager')) {
            return [
                'success' => false,
                'sent'    => 0,
                'failed'  => count($emails),
                'skipped' => 0,
                'queued'  => 0,
                'total'   => count($emails),
                'message' => 'Bulk email queue is unavailable.',
            ];
        }

        $queued = $failed = $skipped = 0;
        try {
            $queue = (new EmailQueueManager())->setSchoolContext($this->schoolId);
            foreach ($emails as $address) {
                if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }

                $result = $queue->addToQueue($address, $subject, $html, null, 5, 'school_' . $audience);
                !empty($result['success']) ? $queued++ : $failed++;
            }
        } catch (Throwable $e) {
            error_log('SchoolEmailSender::queueEmails: ' . $e->getMessage());
            $failed += count($emails) - $queued - $skipped;
        }

        $total = count($emails);
        return [
            'success' => $queued > 0,
            'sent'    => 0,
            'failed'  => $failed,
            'skipped' => $skipped,
            'queued'  => $queued,
            'total'   => $total,
            'message' => "Email queued for {$queued} of {$total} recipient(s)."
                . ($failed ? " {$failed} failed." : '')
                . ($skipped ? " {$skipped} skipped (invalid address)." : ''),
        ];
    }

    private function portalUrl(string $path = ''): string
    {
        $slug = (string) ($this->school['slug'] ?? '');
        if ($slug !== '' && function_exists('school_portal_url')) {
            return school_portal_url($slug, $path, true);
        }

        if (defined('APP_URL') && $slug !== '') {
            return rtrim(APP_URL, '/') . '/tenant/' . rawurlencode($slug) . ($path !== '' ? '/' . ltrim($path, '/') : '/');
        }

        return '#';
    }

    private function normalizeAudience(string $audience): string
    {
        $audience = strtolower(trim($audience));
        return match ($audience) {
            'parent', 'guardian', 'guardians' => 'parents',
            'teacher' => 'teachers',
            'student' => 'students',
            'employee', 'employees', 'admin', 'admins' => 'staff',
            'everyone', 'school', 'community' => 'all',
            default => $audience,
        };
    }

    private function audienceLabel(string $audience): string
    {
        return match (strtolower($audience)) {
            'parents'  => 'Message to Parents',
            'teachers' => 'Message to Teachers',
            'staff'    => 'Message to Staff',
            'students' => 'Message to Students',
            default    => 'School Announcement',
        };
    }
}
