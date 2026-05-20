<?php
/**
 * WhatsAppService
 *
 * Sends school announcement notifications through the official WhatsApp
 * Business Cloud API and records each delivery attempt in the school database.
 */

class WhatsAppService
{
    private PDO $db;
    private array $school;
    private int $schoolId;
    private string $accessToken;
    private string $phoneNumberId;
    private string $graphVersion;
    private string $templateName;
    private string $templateLanguage;

    private const MAX_SYNC_RECIPIENTS = 200;
    private const DEFAULT_COUNTRY_CODE = '234';
    private const ALLOWED_AUDIENCES = ['parents', 'teachers', 'students', 'staff', 'all'];
    private const FEATURE_SETTING_KEYS = [
        'announcements' => 'whatsapp_announcements_enabled',
        'announcement' => 'whatsapp_announcements_enabled',
        'events' => 'whatsapp_events_enabled',
        'event' => 'whatsapp_events_enabled',
        'fees' => 'whatsapp_fees_enabled',
        'fee' => 'whatsapp_fees_enabled',
        'attendance' => 'whatsapp_attendance_enabled',
    ];

    public function __construct(PDO $schoolDb, array $school, array $config = [])
    {
        $this->db = $schoolDb;
        $this->school = $school;
        $this->schoolId = (int)($school['id'] ?? 0);
        $this->accessToken = trim((string)($config['access_token'] ?? $this->envValue('WHATSAPP_ACCESS_TOKEN', '')));
        $this->phoneNumberId = trim((string)($config['phone_number_id'] ?? $this->envValue('WHATSAPP_PHONE_NUMBER_ID', '')));
        $this->graphVersion = trim((string)($config['graph_version'] ?? $this->envValue('WHATSAPP_GRAPH_VERSION', 'v23.0')));
        $this->templateName = trim((string)($config['template_name'] ?? $this->envValue('WHATSAPP_ANNOUNCEMENT_TEMPLATE', 'school_announcement')));
        $this->templateLanguage = trim((string)($config['template_language'] ?? $this->envValue('WHATSAPP_TEMPLATE_LANGUAGE', 'en')));
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== '' && $this->phoneNumberId !== '';
    }

    public function configurationStatus(): string
    {
        if ($this->isConfigured()) {
            return 'WhatsApp is configured for approved template sends.';
        }

        return 'Set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in .env before sending.';
    }

    public function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `whatsapp_notifications` (
                `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `school_id` int(10) UNSIGNED NOT NULL,
                `announcement_id` int(10) UNSIGNED DEFAULT NULL,
                `feature` varchar(50) NOT NULL DEFAULT 'announcement',
                `reference_id` int(10) UNSIGNED DEFAULT NULL,
                `recipient_user_id` int(10) UNSIGNED DEFAULT NULL,
                `recipient_type` varchar(30) NOT NULL,
                `recipient_name` varchar(190) DEFAULT NULL,
                `phone` varchar(32) NOT NULL,
                `template_name` varchar(190) NOT NULL,
                `message_preview` text DEFAULT NULL,
                `status` enum('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
                `provider_message_id` varchar(190) DEFAULT NULL,
                `error_message` text DEFAULT NULL,
                `provider_response` mediumtext DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `sent_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_school_announcement` (`school_id`, `announcement_id`),
                KEY `idx_school_feature` (`school_id`, `feature`, `reference_id`),
                KEY `idx_status` (`status`),
                KEY `idx_phone` (`phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumn('whatsapp_notifications', 'feature', "`feature` varchar(50) NOT NULL DEFAULT 'announcement' AFTER `announcement_id`");
        $this->ensureColumn('whatsapp_notifications', 'reference_id', "`reference_id` int(10) UNSIGNED DEFAULT NULL AFTER `feature`");
    }

    public static function defaultFeatureSettings(bool $enabledByDefault = true): array
    {
        $default = $enabledByDefault ? '1' : '0';
        return [
            'whatsapp_enabled' => $default,
            'whatsapp_announcements_enabled' => $default,
            'whatsapp_events_enabled' => $default,
            'whatsapp_fees_enabled' => $default,
            'whatsapp_attendance_enabled' => $default,
        ];
    }

    public static function getFeatureSettings(PDO $db, int $schoolId, bool $enabledByDefault = true): array
    {
        $settings = self::defaultFeatureSettings($enabledByDefault);
        self::ensureSettingsTable($db);

        try {
            $keys = array_keys($settings);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ? AND `key` IN ({$placeholders})");
            $stmt->execute(array_merge([$schoolId], $keys));

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[(string)$row['key']] = (string)$row['value'];
            }
        } catch (Throwable $e) {
            error_log('WhatsAppService::getFeatureSettings: ' . $e->getMessage());
        }

        return $settings;
    }

    public static function saveFeatureSettings(PDO $db, int $schoolId, array $settings, int $campusId = 1): void
    {
        self::ensureSettingsTable($db);
        $defaults = self::defaultFeatureSettings(false);
        $normalized = [];

        foreach ($defaults as $key => $default) {
            $normalized[$key] = !empty($settings[$key]) ? '1' : '0';
        }

        foreach ($normalized as $key => $value) {
            self::upsertSetting($db, $schoolId, $key, $value, $campusId);
        }
    }

    public static function featureEnabled(PDO $db, int $schoolId, string $feature, bool $default = true): bool
    {
        $feature = strtolower(trim($feature));
        $settingKey = self::FEATURE_SETTING_KEYS[$feature] ?? null;
        if (!$settingKey) {
            return false;
        }

        $settings = self::getFeatureSettings($db, $schoolId, $default);
        return self::isTruthy($settings['whatsapp_enabled'] ?? $default)
            && self::isTruthy($settings[$settingKey] ?? $default);
    }

    /**
     * @return array{success:bool,total:int,sent:int,failed:int,skipped:int,message:string}
     */
    public function sendAnnouncement(
        int $announcementId,
        string $title,
        string $description,
        string $target = 'all',
        ?int $classId = null,
        ?int $sectionId = null,
        array $audiences = []
    ): array {
        $this->ensureTables();
        $recipients = $this->resolveAnnouncementRecipients($target, $classId, $sectionId, $audiences);

        if (empty($recipients)) {
            return [
                'success' => false,
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'message' => 'No WhatsApp-ready phone numbers were found for this notice audience.',
            ];
        }

        $messagePreview = $this->limitText($title . ' - ' . $this->plainText($description), 500);
        $recipients = array_map(function (array $recipient) use ($announcementId, $messagePreview): array {
            $recipient['feature'] = 'announcement';
            $recipient['reference_id'] = $announcementId;
            $recipient['template_name'] = $this->templateName;
            $recipient['message_preview'] = $messagePreview;
            return $recipient;
        }, $recipients);

        if (!$this->isConfigured()) {
            foreach ($recipients as $recipient) {
                $this->logAttempt($announcementId, $recipient, 'skipped', null, 'WhatsApp API credentials are not configured.');
            }

            return [
                'success' => false,
                'total' => count($recipients),
                'sent' => 0,
                'failed' => 0,
                'skipped' => count($recipients),
                'message' => 'WhatsApp was skipped because the API credentials are not configured.',
            ];
        }

        if (!function_exists('curl_init')) {
            foreach ($recipients as $recipient) {
                $this->logAttempt($announcementId, $recipient, 'failed', null, 'PHP cURL extension is not enabled.');
            }

            return [
                'success' => false,
                'total' => count($recipients),
                'sent' => 0,
                'failed' => count($recipients),
                'skipped' => 0,
                'message' => 'WhatsApp failed because the PHP cURL extension is not enabled.',
            ];
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $limitedRecipients = array_slice($recipients, 0, self::MAX_SYNC_RECIPIENTS);
        $skipped += max(0, count($recipients) - count($limitedRecipients));

        foreach ($limitedRecipients as $recipient) {
            try {
                $response = $this->sendTemplateMessage($recipient, $title, $description);
                if (!empty($response['success'])) {
                    $sent++;
                    $this->logAttempt($announcementId, $recipient, 'sent', $response['raw'] ?? null, null, $response['message_id'] ?? null);
                } else {
                    $failed++;
                    $this->logAttempt($announcementId, $recipient, 'failed', $response['raw'] ?? null, $response['error'] ?? 'Unknown WhatsApp API error.');
                }
            } catch (Throwable $e) {
                $failed++;
                $this->logAttempt($announcementId, $recipient, 'failed', null, $e->getMessage());
                error_log('WhatsAppService::sendAnnouncement: ' . $e->getMessage());
            }

            if (($sent + $failed) % 20 === 0) {
                usleep(200000);
            }
        }

        foreach (array_slice($recipients, self::MAX_SYNC_RECIPIENTS) as $recipient) {
            $this->logAttempt($announcementId, $recipient, 'skipped', null, 'Recipient skipped because the synchronous send limit was reached.');
        }

        $total = count($recipients);
        return [
            'success' => $sent > 0,
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'message' => "WhatsApp sent to {$sent} of {$total} recipient(s)."
                . ($failed ? " {$failed} failed." : '')
                . ($skipped ? " {$skipped} skipped." : ''),
        ];
    }

    /**
     * Send a single WhatsApp template notification to a known recipient.
     *
     * @return array{success:bool,status:string,message:string}
     */
    public function sendDirectNotification(
        string $feature,
        int $referenceId,
        array $recipient,
        string $title,
        string $description,
        string $portalPath = 'login.php'
    ): array {
        $this->ensureTables();
        $phone = $this->normalizePhone((string)($recipient['phone'] ?? ''));
        $templateName = $this->templateForFeature($feature);
        $messagePreview = $this->limitText($title . ' - ' . $this->plainText($description), 500);

        $recipient = [
            'user_id' => isset($recipient['user_id']) ? (int)$recipient['user_id'] : null,
            'name' => trim((string)($recipient['name'] ?? '')),
            'phone' => $phone ?? '',
            'recipient_type' => trim((string)($recipient['recipient_type'] ?? 'parent')),
            'feature' => strtolower(trim($feature)) ?: 'notification',
            'reference_id' => $referenceId ?: null,
            'template_name' => $templateName,
            'message_preview' => $messagePreview,
            'portal_url' => $recipient['portal_url'] ?? $this->portalUrl($portalPath),
        ];

        if (!$phone) {
            $this->logAttempt(0, $recipient, 'skipped', null, 'Recipient phone number is missing or invalid.');
            return ['success' => false, 'status' => 'skipped', 'message' => 'Recipient phone number is missing or invalid.'];
        }

        if (!$this->isConfigured()) {
            $this->logAttempt(0, $recipient, 'skipped', null, 'WhatsApp API credentials are not configured.');
            return ['success' => false, 'status' => 'skipped', 'message' => 'WhatsApp API credentials are not configured.'];
        }

        if (!function_exists('curl_init')) {
            $this->logAttempt(0, $recipient, 'failed', null, 'PHP cURL extension is not enabled.');
            return ['success' => false, 'status' => 'failed', 'message' => 'PHP cURL extension is not enabled.'];
        }

        try {
            $response = $this->sendTemplateMessage($recipient, $title, $description, $templateName, $portalPath);
            if (!empty($response['success'])) {
                $this->logAttempt(0, $recipient, 'sent', $response['raw'] ?? null, null, $response['message_id'] ?? null);
                return ['success' => true, 'status' => 'sent', 'message' => 'WhatsApp notification sent.'];
            }

            $error = $response['error'] ?? 'Unknown WhatsApp API error.';
            $this->logAttempt(0, $recipient, 'failed', $response['raw'] ?? null, $error);
            return ['success' => false, 'status' => 'failed', 'message' => $error];
        } catch (Throwable $e) {
            $this->logAttempt(0, $recipient, 'failed', null, $e->getMessage());
            error_log('WhatsAppService::sendDirectNotification: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<int,array{user_id:int|null,name:string,phone:string,recipient_type:string}>
     */
    public function resolveAnnouncementRecipients(
        string $target = 'all',
        ?int $classId = null,
        ?int $sectionId = null,
        array $audiences = []
    ): array {
        $target = strtolower(trim($target));
        $audiences = $this->normalizeAudiences($audiences, $target);
        $sets = [];

        if (in_array('parents', $audiences, true)) {
            $sets[] = $this->fetchParentRecipients($classId, $sectionId, in_array($target, ['class', 'section'], true));
        }

        if (in_array('teachers', $audiences, true)) {
            $sets[] = $this->fetchUserRecipients(['teacher'], 'teacher');
        }

        if (in_array('students', $audiences, true)) {
            $sets[] = $this->fetchStudentRecipients($classId, $sectionId, in_array($target, ['class', 'section'], true));
        }

        if (in_array('staff', $audiences, true)) {
            $sets[] = $this->fetchUserRecipients(['admin', 'staff', 'accountant', 'librarian', 'receptionist'], 'staff');
        }

        return $this->dedupeRecipients(array_merge(...$sets));
    }

    private function sendTemplateMessage(
        array $recipient,
        string $title,
        string $description,
        ?string $templateName = null,
        string $portalPath = 'login.php'
    ): array
    {
        $templateName = $templateName ?: $this->templateName;
        $portalUrl = (string)($recipient['portal_url'] ?? $this->portalUrl($portalPath));
        $parameters = [
            ['type' => 'text', 'text' => $this->limitText($recipient['name'] ?: 'there', 80)],
            ['type' => 'text', 'text' => $this->limitText((string)($this->school['name'] ?? 'Your school'), 80)],
            ['type' => 'text', 'text' => $this->limitText($title, 120)],
            ['type' => 'text', 'text' => $this->limitText($this->plainText($description), 600)],
            ['type' => 'text', 'text' => $this->limitText($portalUrl, 180)],
        ];

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient['phone'],
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $this->templateLanguage],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => $parameters,
                    ],
                ],
            ],
        ];

        $url = 'https://graph.facebook.com/' . rawurlencode($this->graphVersion) . '/'
            . rawurlencode($this->phoneNumberId) . '/messages';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'raw' => null, 'error' => $curlError ?: 'Unable to contact WhatsApp API.'];
        }

        $decoded = json_decode((string)$raw, true);
        $messageId = $decoded['messages'][0]['id'] ?? null;

        if ($status >= 200 && $status < 300 && $messageId) {
            return ['success' => true, 'raw' => $raw, 'message_id' => $messageId];
        }

        $apiError = $decoded['error']['message'] ?? "WhatsApp API returned HTTP {$status}.";
        return ['success' => false, 'raw' => $raw, 'error' => $apiError];
    }

    private function fetchParentRecipients(?int $classId, ?int $sectionId, bool $applyFilters): array
    {
        $sets = [];

        if ($this->tableExists('guardians') && $this->tableExists('users')) {
            $where = [
                'g.school_id = ?',
                "u.is_active = 1",
                "u.phone IS NOT NULL",
                "u.phone != ''",
            ];
            $params = [$this->schoolId];

            if ($applyFilters && $this->tableExists('students')) {
                if ($classId) {
                    $where[] = 's.class_id = ?';
                    $params[] = $classId;
                }
                if ($sectionId) {
                    $where[] = 's.section_id = ?';
                    $params[] = $sectionId;
                }
            }

            $joinStudents = $this->tableExists('students')
                ? 'LEFT JOIN students s ON s.id = g.student_id AND s.school_id = g.school_id'
                : '';

            $sets[] = $this->queryRecipients(
                "SELECT DISTINCT u.id AS user_id, u.name, u.phone, 'parent' AS recipient_type
                 FROM guardians g
                 INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id
                 {$joinStudents}
                 WHERE " . implode(' AND ', $where),
                $params
            );
        }

        if (!$applyFilters) {
            $sets[] = $this->queryRecipients(
                "SELECT DISTINCT id AS user_id, name, phone, 'parent' AS recipient_type
                 FROM users
                 WHERE school_id = ? AND user_type = 'parent'
                   AND is_active = 1 AND phone IS NOT NULL AND phone != ''",
                [$this->schoolId]
            );
        }

        return array_merge(...$sets);
    }

    private function fetchStudentRecipients(?int $classId, ?int $sectionId, bool $applyFilters): array
    {
        if (!$this->tableExists('students')) {
            return $this->fetchUserRecipients(['student'], 'student');
        }

        $where = [
            's.school_id = ?',
            "s.status = 'active'",
            'u.is_active = 1',
            "u.phone IS NOT NULL",
            "u.phone != ''",
        ];
        $params = [$this->schoolId];

        if ($applyFilters) {
            if ($classId) {
                $where[] = 's.class_id = ?';
                $params[] = $classId;
            }
            if ($sectionId) {
                $where[] = 's.section_id = ?';
                $params[] = $sectionId;
            }
        }

        return $this->queryRecipients(
            "SELECT DISTINCT u.id AS user_id, u.name, u.phone, 'student' AS recipient_type
             FROM students s
             INNER JOIN users u ON u.id = s.user_id AND u.school_id = s.school_id
             WHERE " . implode(' AND ', $where),
            $params
        );
    }

    private function fetchUserRecipients(array $userTypes, string $recipientType): array
    {
        $userTypes = array_values(array_filter($userTypes, fn($type) => preg_match('/^[a-z_]+$/', $type)));
        if (empty($userTypes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userTypes), '?'));
        return $this->queryRecipients(
            "SELECT DISTINCT id AS user_id, name, phone, ? AS recipient_type
             FROM users
             WHERE school_id = ? AND user_type IN ({$placeholders})
               AND is_active = 1 AND phone IS NOT NULL AND phone != ''",
            array_merge([$recipientType, $this->schoolId], $userTypes)
        );
    }

    private function queryRecipients(string $sql, array $params): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('WhatsAppService::queryRecipients: ' . $e->getMessage());
            return [];
        }
    }

    private function dedupeRecipients(array $recipients): array
    {
        $unique = [];
        foreach ($recipients as $recipient) {
            $phone = $this->normalizePhone((string)($recipient['phone'] ?? ''));
            if ($phone === null || isset($unique[$phone])) {
                continue;
            }

            $unique[$phone] = [
                'user_id' => isset($recipient['user_id']) ? (int)$recipient['user_id'] : null,
                'name' => trim((string)($recipient['name'] ?? '')),
                'phone' => $phone,
                'recipient_type' => trim((string)($recipient['recipient_type'] ?? 'parent')),
            ];
        }

        return array_values($unique);
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($digits === '') {
            return null;
        }

        if (strncmp($digits, '00', 2) === 0) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && strncmp($digits, '0', 1) === 0) {
            $digits = self::DEFAULT_COUNTRY_CODE . substr($digits, 1);
        } elseif (strlen($digits) === 10 && preg_match('/^[789]/', $digits)) {
            $digits = self::DEFAULT_COUNTRY_CODE . $digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    private function normalizeAudiences(array $audiences, string $target): array
    {
        if (empty($audiences)) {
            $audiences = match ($target) {
                'teachers' => ['teachers'],
                'students' => ['students'],
                'parents' => ['parents'],
                default => ['parents', 'teachers'],
            };
        }

        $normalized = [];
        foreach ($audiences as $audience) {
            $audience = strtolower(trim((string)$audience));
            $audience = match ($audience) {
                'parent', 'guardian', 'guardians' => 'parents',
                'teacher' => 'teachers',
                'student' => 'students',
                'staffs', 'employees', 'employee' => 'staff',
                'everyone', 'school' => 'all',
                default => $audience,
            };

            if ($audience === 'all') {
                $normalized = ['parents', 'teachers', 'students', 'staff'];
                break;
            }

            if (in_array($audience, self::ALLOWED_AUDIENCES, true)) {
                $normalized[] = $audience;
            }
        }

        return array_values(array_unique($normalized ?: ['parents', 'teachers']));
    }

    private function logAttempt(
        int $announcementId,
        array $recipient,
        string $status,
        ?string $providerResponse = null,
        ?string $errorMessage = null,
        ?string $providerMessageId = null
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO whatsapp_notifications (
                    school_id, announcement_id, feature, reference_id, recipient_user_id, recipient_type,
                    recipient_name, phone, template_name, message_preview, status,
                    provider_message_id, error_message, provider_response, sent_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $this->schoolId,
                $announcementId ?: null,
                $recipient['feature'] ?? 'announcement',
                $recipient['reference_id'] ?? ($announcementId ?: null),
                $recipient['user_id'] ?? null,
                $recipient['recipient_type'] ?? 'parent',
                $recipient['name'] ?? null,
                $recipient['phone'] ?? '',
                $recipient['template_name'] ?? $this->templateName,
                $recipient['message_preview'] ?? ($this->school['name'] ?? 'School announcement'),
                $status,
                $providerMessageId,
                $errorMessage,
                $providerResponse,
                $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (Throwable $e) {
            error_log('WhatsAppService::logAttempt: ' . $e->getMessage());
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($table, $column)) {
            try {
                $safeTable = str_replace('`', '', $table);
                $this->db->exec("ALTER TABLE `{$safeTable}` ADD COLUMN {$definition}");
            } catch (Throwable $e) {
                error_log("WhatsAppService::ensureColumn {$table}.{$column}: " . $e->getMessage());
            }
        }
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function templateForFeature(string $feature): string
    {
        $feature = strtolower(trim($feature));
        return match ($feature) {
            'event', 'events' => trim((string)$this->envValue('WHATSAPP_EVENT_TEMPLATE', 'school_event_notice')),
            'fee', 'fees', 'invoice' => trim((string)$this->envValue('WHATSAPP_FEE_TEMPLATE', 'fee_payment_reminder')),
            'attendance' => trim((string)$this->envValue('WHATSAPP_ATTENDANCE_TEMPLATE', 'attendance_alert')),
            default => $this->templateName,
        };
    }

    private function portalUrl(string $path = ''): string
    {
        $slug = (string)($this->school['slug'] ?? '');
        if ($slug !== '' && function_exists('school_portal_url')) {
            return school_portal_url($slug, $path, true);
        }

        if (defined('APP_URL') && $slug !== '') {
            return rtrim(APP_URL, '/') . '/tenant/' . rawurlencode($slug) . '/' . ltrim($path, '/');
        }

        return '#';
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8');
        return preg_replace('/\s+/', ' ', trim($value)) ?: 'New school announcement.';
    }

    private function limitText(string $value, int $limit): string
    {
        $value = trim($value);
        if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            return mb_substr($value, 0, $limit - 3) . '...';
        }

        return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
    }

    private function envValue(string $key, $default = null)
    {
        if (function_exists('env')) {
            return env($key, $default);
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        return $_SERVER[$key] ?? $default;
    }

    private static function ensureSettingsTable(PDO $db): void
    {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `settings` (
                    `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `school_id` int(10) UNSIGNED NOT NULL,
                    `campus_id` int(10) UNSIGNED DEFAULT NULL,
                    `key` varchar(190) NOT NULL,
                    `value` text DEFAULT NULL,
                    `type` varchar(50) NOT NULL DEFAULT 'string',
                    `category` varchar(100) NOT NULL DEFAULT 'general',
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_setting` (`school_id`, `key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('WhatsAppService::ensureSettingsTable: ' . $e->getMessage());
        }
    }

    private static function upsertSetting(PDO $db, int $schoolId, string $key, string $value, int $campusId): void
    {
        try {
            $stmt = $db->prepare('SELECT id FROM settings WHERE school_id = ? AND `key` = ? LIMIT 1');
            $stmt->execute([$schoolId, $key]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                $sql = self::staticColumnExists($db, 'settings', 'updated_at')
                    ? 'UPDATE settings SET `value` = ?, updated_at = NOW() WHERE id = ?'
                    : 'UPDATE settings SET `value` = ? WHERE id = ?';
                $db->prepare($sql)->execute([$value, $existingId]);
                return;
            }

            $columns = ['school_id', '`key`', '`value`'];
            $placeholders = ['?', '?', '?'];
            $params = [$schoolId, $key, $value];

            if (self::staticColumnExists($db, 'settings', 'campus_id')) {
                $columns[] = 'campus_id';
                $placeholders[] = '?';
                $params[] = $campusId;
            }
            if (self::staticColumnExists($db, 'settings', 'type')) {
                $columns[] = '`type`';
                $placeholders[] = '?';
                $params[] = 'boolean';
            }
            if (self::staticColumnExists($db, 'settings', 'category')) {
                $columns[] = 'category';
                $placeholders[] = '?';
                $params[] = 'whatsapp';
            }
            if (self::staticColumnExists($db, 'settings', 'created_at')) {
                $columns[] = 'created_at';
                $placeholders[] = 'NOW()';
            }
            if (self::staticColumnExists($db, 'settings', 'updated_at')) {
                $columns[] = 'updated_at';
                $placeholders[] = 'NOW()';
            }

            $sql = 'INSERT INTO settings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $db->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            error_log('WhatsAppService::upsertSetting: ' . $e->getMessage());
        }
    }

    private static function staticColumnExists(PDO $db, string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
