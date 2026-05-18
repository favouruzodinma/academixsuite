<?php
/**
 * ============================================================
 * CRON TASK: Process School Trial Expiry
 * ============================================================
 *
 * Suspends schools whose trial period has ended and sends a
 * professional trial-ended email to the school administrator.
 *
 * SCHEDULE: Every hour
 * CRON: 0 * * * *
 *
 * OPTIONS:
 *   --limit=N     : Process N schools (default: 100)
 *   --dry-run     : Simulate without suspending or sending email
 *
 * ============================================================
 */

function executeTask($options, $logger)
{
    $limit = isset($options['limit']) ? max(1, min(1000, (int)$options['limit'])) : 100;
    $dryRun = isset($options['dry-run']);
    $config = require __DIR__ . '/../config.php';

    $logger->info('Starting school trial expiry processing', [
        'limit' => $limit,
        'dry_run' => $dryRun
    ]);

    if (empty($config['suspension']['check_trial_expiry'])) {
        $logger->warning('School trial expiry processing is disabled in cron/config.php');
        return ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
    }

    $db = Database::getPlatformConnection();
    $schools = processSchoolTrialsFetchExpiredSchools($db, $limit, $logger);

    $logger->info('Found {count} expired trial schools', ['count' => count($schools)]);

    $processed = 0;
    $suspended = 0;
    $failed = 0;
    $emailSent = 0;
    $emailFailed = 0;
    $skipped = 0;

    foreach ($schools as $school) {
        $processed++;
        $context = [
            'school_id' => $school['id'],
            'school_name' => $school['name'] ?? 'Unknown School',
            'trial_ends_at' => $school['trial_ends_at'] ?? null
        ];

        if ($dryRun) {
            $logger->info('DRY RUN: Would suspend expired trial school and send notification', $context);
            $skipped++;
            continue;
        }

        try {
            $updated = processSchoolTrialsSuspendSchool($db, $school);
            if (!$updated) {
                $logger->warning('School was not suspended, likely changed by another process', $context);
                $skipped++;
                continue;
            }

            $suspended++;
            $recipient = processSchoolTrialsResolveRecipient($db, $school, $logger);
            $emailStatus = 'skipped_no_email';

            if ($recipient) {
                $sendResult = processSchoolTrialsSendEmail($school, $recipient, $logger);
                $emailStatus = !empty($sendResult['success']) ? 'sent' : 'failed';

                processSchoolTrialsLogEmail(
                    $db,
                    $school,
                    $recipient['email'],
                    $sendResult['subject'] ?? 'Your AcademixSuite trial has ended',
                    $emailStatus,
                    $sendResult['error'] ?? null,
                    $sendResult['message_id'] ?? null
                );

                if ($emailStatus === 'sent') {
                    $emailSent++;
                } else {
                    $emailFailed++;
                }
            } else {
                $emailFailed++;
                $logger->warning('No valid school email found for expired trial notification', $context);
            }

            processSchoolTrialsLogAudit($db, $school, $emailStatus, $logger);

            $logger->success('Expired trial school processed', array_merge($context, [
                'email_status' => $emailStatus
            ]));
        } catch (Exception $e) {
            $failed++;
            $logger->error('Failed to process expired trial school', array_merge($context, [
                'error' => $e->getMessage()
            ]));
        }
    }

    $logger->success('School trial expiry processing completed', [
        'processed' => $processed,
        'suspended' => $suspended,
        'email_sent' => $emailSent,
        'email_failed' => $emailFailed,
        'skipped' => $skipped,
        'failed' => $failed
    ]);

    return [
        'processed' => $processed,
        'succeeded' => $suspended,
        'failed' => $failed
    ];
}

function processSchoolTrialsFetchExpiredSchools(PDO $db, int $limit, $logger): array
{
    $planJoin = '';
    $planSelect = 'NULL AS plan_name';
    if (processSchoolTrialsTableExists($db, 'plans')) {
        $planJoin = 'LEFT JOIN plans p ON p.id = s.plan_id';
        $planSelect = 'p.name AS plan_name';
    }

    $subscriptionGuard = '';
    if (processSchoolTrialsTableExists($db, 'subscriptions')) {
        $subscriptionGuard = "
            AND NOT EXISTS (
                SELECT 1
                FROM subscriptions sub
                WHERE sub.school_id = s.id
                AND sub.status = 'active'
                AND (sub.current_period_end IS NULL OR sub.current_period_end >= NOW())
            )
        ";
    } else {
        $logger->warning('subscriptions table not found; expired trial processing will only use schools.status and schools.trial_ends_at');
    }

    $stmt = $db->prepare("
        SELECT s.*, {$planSelect}
        FROM schools s
        {$planJoin}
        WHERE s.status = 'trial'
        AND s.trial_ends_at IS NOT NULL
        AND s.trial_ends_at <= NOW()
        {$subscriptionGuard}
        ORDER BY s.trial_ends_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function processSchoolTrialsSuspendSchool(PDO $db, array $school): bool
{
    $sets = ["status = 'suspended'"];
    if (processSchoolTrialsColumnExists($db, 'schools', 'suspended_at')) {
        $sets[] = 'suspended_at = NOW()';
    }
    if (processSchoolTrialsColumnExists($db, 'schools', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    $stmt = $db->prepare('
        UPDATE schools
        SET ' . implode(', ', $sets) . '
        WHERE id = ?
        AND status = ?
    ');
    $stmt->execute([$school['id'], 'trial']);

    return $stmt->rowCount() > 0;
}

function processSchoolTrialsResolveRecipient(PDO $db, array $school, $logger): ?array
{
    $candidates = [
        [
            'email' => $school['admin_email'] ?? null,
            'name' => $school['admin_name'] ?? null
        ],
        [
            'email' => $school['email'] ?? null,
            'name' => $school['admin_name'] ?? ($school['name'] ?? null)
        ]
    ];

    foreach ($candidates as $candidate) {
        if (!empty($candidate['email']) && filter_var($candidate['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $candidate['email'],
                'name' => $candidate['name'] ?: 'Administrator'
            ];
        }
    }

    if (!processSchoolTrialsTableExists($db, 'school_admins')) {
        return null;
    }

    try {
        $stmt = $db->prepare("
            SELECT email
            FROM school_admins
            WHERE school_id = ?
            AND email IS NOT NULL
            AND email != ''
            AND (is_active = 1 OR is_active IS NULL)
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$school['id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $admin['email'],
                'name' => 'Administrator'
            ];
        }
    } catch (Exception $e) {
        $logger->warning('Could not read school_admins fallback email', [
            'school_id' => $school['id'],
            'error' => $e->getMessage()
        ]);
    }

    return null;
}

function processSchoolTrialsSendEmail(array $school, array $recipient, $logger): array
{
    $template = new EmailTemplate();
    $emailService = new EmailService();

    $schoolName = $school['name'] ?? 'Your School';
    $subject = 'Your AcademixSuite trial has ended - ' . $schoolName;
    $portalUrl = processSchoolTrialsPortalUrl($school);
    $billingUrl = processSchoolTrialsBillingUrl($school, $portalUrl);

    $html = $template->getTemplate('trial_expired', [
        'admin_name' => $recipient['name'] ?? 'Administrator',
        'school_name' => $schoolName,
        'plan_name' => $school['plan_name'] ?? 'Starter',
        'trial_ends_at' => $school['trial_ends_at'] ?? date('Y-m-d H:i:s'),
        'portal_url' => $portalUrl,
        'billing_url' => $billingUrl
    ]);

    $result = $emailService->sendEmail(
        $recipient['email'],
        $subject,
        $html,
        trim(strip_tags($html))
    );

    if (!empty($result['success'])) {
        $logger->info('Trial ended email sent', [
            'school_id' => $school['id'],
            'to' => $recipient['email'],
            'method' => $result['method'] ?? 'unknown'
        ]);
    } else {
        $logger->error('Trial ended email failed', [
            'school_id' => $school['id'],
            'to' => $recipient['email'],
            'error' => $result['error'] ?? 'Unknown email error'
        ]);
    }

    return [
        'success' => !empty($result['success']),
        'subject' => $subject,
        'message_id' => $result['message_id'] ?? null,
        'error' => $result['error'] ?? null
    ];
}

function processSchoolTrialsPortalUrl(array $school): string
{
    $slug = $school['slug'] ?? ($school['subdomain'] ?? '');

    if ($slug && function_exists('school_login_url')) {
        return school_login_url($slug, true);
    }

    $baseUrl = function_exists('app_url')
        ? app_url('', true)
        : (defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost');

    return $slug
        ? rtrim($baseUrl, '/') . '/tenant/' . rawurlencode($slug) . '/login.php'
        : rtrim($baseUrl, '/') . '/tenant/login.php';
}

function processSchoolTrialsBillingUrl(array $school, string $portalUrl): string
{
    $slug = $school['slug'] ?? ($school['subdomain'] ?? '');

    if ($slug && function_exists('school_route_url')) {
        return school_route_url($slug, 'admin/subscription-plan.php', true);
    }

    if ($slug) {
        $baseUrl = function_exists('app_url')
            ? app_url('', true)
            : (defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost');

        return rtrim($baseUrl, '/') . '/tenant/' . rawurlencode($slug) . '/admin/subscription-plan.php';
    }

    return $portalUrl;
}

function processSchoolTrialsLogAudit(PDO $db, array $school, string $emailStatus, $logger): void
{
    if (!processSchoolTrialsTableExists($db, 'platform_audit_logs')) {
        return;
    }

    try {
        $description = "School '{$school['name']}' was automatically suspended because its trial ended on "
            . date('F j, Y g:i A', strtotime($school['trial_ends_at']))
            . ". Trial-ended email status: {$emailStatus}.";

        $stmt = $db->prepare("
            INSERT INTO platform_audit_logs (school_id, event, description, user_type, created_at)
            VALUES (?, 'school_trial_expired', ?, 'system', NOW())
        ");
        $stmt->execute([$school['id'], $description]);
    } catch (Exception $e) {
        $logger->warning('Failed to write trial expiry audit log', [
            'school_id' => $school['id'],
            'error' => $e->getMessage()
        ]);
    }
}

function processSchoolTrialsLogEmail(PDO $db, array $school, string $to, string $subject, string $status, ?string $error, ?string $messageId): void
{
    if (!processSchoolTrialsTableExists($db, 'email_logs')) {
        return;
    }

    $columns = processSchoolTrialsTableColumns($db, 'email_logs');

    try {
        if (in_array('to_email', $columns, true)) {
            $stmt = $db->prepare("
                INSERT INTO email_logs (school_id, to_email, subject, template, status, message_id, error_message, created_at)
                VALUES (?, ?, ?, 'trial_expired', ?, ?, ?, NOW())
            ");
            $stmt->execute([$school['id'], $to, $subject, $status, $messageId, $error]);
            return;
        }

        if (in_array('to', $columns, true)) {
            $stmt = $db->prepare("
                INSERT INTO email_logs (school_id, `to`, subject, status, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$school['id'], $to, $subject, $status, $error]);
        }
    } catch (Exception $e) {
        error_log('Failed to write trial expiry email log: ' . $e->getMessage());
    }
}

function processSchoolTrialsTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function processSchoolTrialsColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetchColumn();
}

function processSchoolTrialsTableColumns(PDO $db, string $table): array
{
    $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = $column['Field'];
    }

    return $columns;
}
