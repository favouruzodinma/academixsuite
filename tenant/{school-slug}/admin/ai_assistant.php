<?php
/**
 * AI Assistant Endpoint
 *
 * Handles two modes:
 *
 * 1. AI conversation (default):
 *    POST messages + csrf_token → runs Groq with school tools → returns reply.
 *
 * 2. Direct send_email action (no AI involved):
 *    POST action=send_email + subject + body_html + audience + csrf_token
 *    → resolves recipients via SchoolEmailSender → sends → returns result.
 *
 * 3. Preview recipients:
 *    POST action=preview_recipients + audience + csrf_token
 *    → returns count + sample addresses (no email sent).
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/ai_assistant.log');

if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    $sessionConfig = __DIR__ . '/../../../includes/session_config.php';
    if (is_file($sessionConfig)) {
        require_once $sessionConfig;
    }
    session_start(function_exists('academix_session_options') ? academix_session_options() : []);
}

require_once __DIR__ . '/../../../includes/autoload.php';

header('Content-Type: application/json');

$jsonInput = [];
$rawInput = file_get_contents('php://input');
if (is_string($rawInput) && trim($rawInput) !== '') {
    $decodedInput = json_decode($rawInput, true);
    if (is_array($decodedInput)) {
        $jsonInput = $decodedInput;
    }
}

// ── Auth & CSRF ───────────────────────────────────────────────────────────────
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    echo json_encode(['success' => false, 'message' => 'School context missing.']); exit;
}

$school = $schoolData ?: ($_SESSION['school_info'][$schoolSlug] ?? []);
if (empty($school['id'])) {
    echo json_encode(['success' => false, 'message' => 'School profile could not be loaded. Please refresh and try again.']); exit;
}
$school['id'] = (int)$school['id'];
$school['slug'] = $school['slug'] ?? $schoolSlug;
$school['name'] = $school['name'] ?? 'School';

if (empty($_SESSION['school_auth']) || $_SESSION['school_auth']['school_slug'] !== $schoolSlug
    || ($_SESSION['school_auth']['user_type'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorised.']); exit;
}

if (!function_exists('academix_ai_validate_csrf_token')) {
    function academix_ai_validate_csrf_token(?string $token): bool {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }

        $sessionTokens = [
            $_SESSION['ai_csrf_token'] ?? null,
            $_SESSION['csrf_token'] ?? null,
            $_SESSION['admin_csrf_token'] ?? null,
        ];

        foreach ($sessionTokens as $sessionToken) {
            if (is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
                return true;
            }
        }

        if (!empty($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
            if (isset($_SESSION['csrf_tokens'][$token])) {
                $expiry = $_SESSION['csrf_tokens'][$token];
                if (is_numeric($expiry)) {
                    return (int)$expiry >= time();
                }
                return true;
            }

            foreach ($_SESSION['csrf_tokens'] as $csrfTokenData) {
                if (is_array($csrfTokenData) && isset($csrfTokenData['token'], $csrfTokenData['expiry'])) {
                    if ((int)$csrfTokenData['expiry'] >= time() && hash_equals((string)$csrfTokenData['token'], $token)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
$token = $_POST['csrf_token'] ?? ($jsonInput['csrf_token'] ?? '');
if (!academix_ai_validate_csrf_token(is_string($token) ? $token : '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']); exit;
}

// ── Bootstrap school dependencies ─────────────────────────────────────────────
$userId     = (int) ($_SESSION['school_auth']['user_id'] ?? 0);
$userType   = $_SESSION['school_auth']['user_type'] ?? 'admin';
$platformDb = Database::getPlatformConnection();
$schoolDb   = null;

try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
    }
} catch (Throwable $e) {
    error_log('AI assistant: school DB unavailable – ' . $e->getMessage());
}

require_once __DIR__ . '/../../../includes/SchoolActionManager.php';
require_once __DIR__ . '/../../../includes/GroqClient.php';
require_once __DIR__ . '/../../../includes/Services/SchoolEmailSender.php';
require_once __DIR__ . '/../../../includes/Services/WhatsAppService.php';

// Load onboarding helpers (functions only — no side-effect vars needed here)
$_ob_path = __DIR__ . '/includes/onboarding-guide.php';
if (file_exists($_ob_path) && !defined('ACADEMIX_ONBOARDING_LOADED')) {
    // Load helper functions only without triggering the detection block
    require_once $_ob_path;
}

$manager = new SchoolActionManager($platformDb, $schoolDb, $school['id'], $schoolSlug, $userId);

// Load EventManager if available
$eventManager = null;
$eventManagerPath = __DIR__ . '/../../../includes/EventManager.php';
if ($schoolDb && file_exists($eventManagerPath)) {
    require_once $eventManagerPath;
    try {
        $eventManager = new EventManager($schoolDb, $platformDb, $school['id'], $userId, $userType, $school);
    } catch (Throwable $e) {
        error_log('AI assistant: EventManager init failed – ' . $e->getMessage());
    }
}

if (!function_exists('academix_ai_table_exists')) {
    function academix_ai_table_exists(PDO $db, string $table): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $stmt = $db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("AI assistant table check failed for {$table}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('academix_ai_column_exists')) {
    function academix_ai_column_exists(PDO $db, string $table, string $column): bool {
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
}

if (!function_exists('academix_ai_code_from_name')) {
    function academix_ai_code_from_name(string $name, string $prefix = ''): string {
        $name = trim($name);
        $words = preg_split('/\s+/', preg_replace('/[^a-zA-Z0-9\s]/', ' ', $name)) ?: [];
        $code = '';

        foreach ($words as $word) {
            if ($word !== '') {
                $code .= strtoupper(substr($word, 0, 3));
            }
            if (strlen($code) >= 8) {
                break;
            }
        }

        $code = preg_replace('/[^A-Z0-9]/', '', $code) ?: strtoupper($prefix ?: 'ITEM');
        return substr($code, 0, 12);
    }
}

if (!function_exists('academix_ai_default_academic_year_id')) {
    function academix_ai_default_academic_year_id(PDO $schoolDb, int $schoolId): int {
        if (!academix_ai_table_exists($schoolDb, 'academic_years')) {
            return 0;
        }

        try {
            $stmt = $schoolDb->prepare("
                SELECT id
                FROM academic_years
                WHERE school_id = ?
                ORDER BY is_default DESC, start_date DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$schoolId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('AI assistant default academic year lookup failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('academix_ai_send_whatsapp_batch')) {
    function academix_ai_send_whatsapp_batch(
        PDO $schoolDb,
        array $school,
        string $feature,
        array $recipients,
        string $title,
        string $message,
        string $portalPath = 'login.php',
        int $referenceId = 0
    ): array {
        $schoolId = (int)($school['id'] ?? 0);
        if (!class_exists('WhatsAppService')) {
            return ['success' => false, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'WhatsApp service is unavailable.'];
        }

        if (!WhatsAppService::featureEnabled($schoolDb, $schoolId, $feature, true)) {
            return ['success' => false, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => "WhatsApp {$feature} notifications are disabled in settings."];
        }

        $service = new WhatsAppService($schoolDb, $school);
        $service->ensureTables();
        $seen = [];
        $cleanRecipients = [];

        foreach ($recipients as $recipient) {
            $phoneKey = preg_replace('/\D+/', '', (string)($recipient['phone'] ?? ''));
            if ($phoneKey === '' || isset($seen[$phoneKey])) {
                continue;
            }
            $seen[$phoneKey] = true;
            $cleanRecipients[] = $recipient;
        }

        if (!$cleanRecipients) {
            return ['success' => false, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'No WhatsApp-ready recipients were found.'];
        }

        $max = 200;
        $selected = array_slice($cleanRecipients, 0, $max);
        $skipped = max(0, count($cleanRecipients) - count($selected));
        $sent = 0;
        $failed = 0;

        foreach ($selected as $recipient) {
            $result = $service->sendDirectNotification($feature, $referenceId, $recipient, $title, $message, $portalPath);
            if (!empty($result['success'])) {
                $sent++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        $total = count($cleanRecipients);
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
}

// ── Handle direct (non-AI) actions ───────────────────────────────────────────
$directAction = $_POST['action'] ?? ($jsonInput['action'] ?? '');

if ($directAction === 'send_email') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, 'message' => 'School database unavailable.']); exit;
    }
    $subject  = trim((string)($_POST['subject']   ?? ($jsonInput['subject']   ?? '')));
    $bodyHtml = trim((string)($_POST['body_html'] ?? ($jsonInput['body_html'] ?? '')));
    $audience = trim((string)($_POST['audience']  ?? ($jsonInput['audience']  ?? 'all')));

    if ($subject === '' || $bodyHtml === '') {
        echo json_encode(['success' => false, 'message' => 'Subject and body are required.']); exit;
    }

    $sender = new SchoolEmailSender($schoolDb, $school);
    $result = $sender->send($audience, $subject, $bodyHtml);
    echo json_encode($result);
    exit;
}

if ($directAction === 'preview_recipients') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, 'message' => 'School database unavailable.']); exit;
    }
    $audience = trim((string)($_POST['audience'] ?? ($jsonInput['audience'] ?? 'all')));
    $sender   = new SchoolEmailSender($schoolDb, $school);
    $preview  = $sender->resolveRecipients($audience);
    echo json_encode(array_merge(['success' => true], $preview));
    exit;
}

// ── Onboarding: fetch current checklist status (non-AI) ──────────────────────
if ($directAction === 'onboarding_status') {
    if (!$schoolDb) {
        echo json_encode(['success' => false, 'message' => 'School database unavailable.']); exit;
    }
    $steps   = onboarding_build_steps($schoolDb, (int)$school['id'], $school);
    $done    = count(array_filter($steps, fn($s) => $s['done']));
    $total   = count($steps);
    $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
    echo json_encode([
        'success' => true,
        'steps'   => $steps,
        'done'    => $done,
        'total'   => $total,
        'percent' => $percent,
        'completed' => $done >= $total,
    ]);
    exit;
}

// ── Onboarding: mark a step done (non-AI, called by frontend on page visit) ──
if ($directAction === 'mark_onboarding_step') {
    $stepKey = trim((string)($_POST['step'] ?? ($jsonInput['step'] ?? '')));
    if ($schoolDb && $stepKey) {
        onboarding_mark_step($schoolDb, (int)$school['id'], $stepKey);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── Parse incoming messages ───────────────────────────────────────────────────
$rawMessages = $_POST['messages'] ?? ($jsonInput['messages'] ?? '');
if (is_string($rawMessages)) {
    $rawMessages = json_decode($rawMessages, true) ?? [];
}
if (!is_array($rawMessages) || empty($rawMessages)) {
    echo json_encode(['success' => false, 'message' => 'No messages provided.']); exit;
}

// ── System prompt ─────────────────────────────────────────────────────────────
$schoolName   = htmlspecialchars_decode($school['name'] ?? 'the school');
$today        = date('l, F j Y');
$isOnboarding = !empty($_POST['is_onboarding']) || !empty($jsonInput['is_onboarding']);

// Build live checklist for onboarding mode
$obStepsJson = '[]';
if ($isOnboarding && $schoolDb) {
    $obSteps    = onboarding_build_steps($schoolDb, (int)$school['id'], $school);
    $obStepsJson = json_encode($obSteps, JSON_PRETTY_PRINT);
}

$systemPrompt = $isOnboarding ? <<<PROMPT
You are a friendly onboarding assistant built into AcademixSuite, a school management platform.
You are helping the admin of "{$schoolName}" set up their school portal for the first time.
Today is {$today}.

Your mission is to guide the admin step by step through the school setup checklist below.
Work through the steps in order, but be flexible if they want to skip or come back to one.

SETUP CHECKLIST (live status):
{$obStepsJson}

HOW TO GUIDE:
1. Start by warmly welcoming the admin and showing them their progress.
2. Tell them which step to do next and WHY it matters.
3. Use the navigate_to_page tool to send them to the right page — this shows a "Take me there →" button.
4. Wait for them to confirm they've completed a step, then call mark_onboarding_step.
5. Once a step is marked done, move to the next one.
6. After all steps: celebrate, then switch to normal assistant mode.

Keep responses SHORT and encouraging. Use simple language.
If they ask a question unrelated to setup, answer briefly and steer back to the checklist.
PROMPT
: <<<PROMPT
You are an AI assistant built into AcademixSuite, a school management platform.
You are helping the admin of "{$schoolName}" manage their school efficiently.
Today is {$today}.

Your job is to help with:
- Creating announcements / notices for students, staff, or parents
- Creating school events and calendar entries
- Creating classes and subjects
- Summarising fee payment status and outstanding balances
- Sending WhatsApp messages, fee reminders, and attendance alerts
- Reporting on student enrolment and class data
- Drafting and sending email notifications to parents, teachers, staff, or students
- Answering general school management questions

When the admin asks you to create or retrieve data, use the provided tools.
Always confirm what you did after using a tool. Be concise, friendly, and professional.
If a required field is missing (e.g. a date for an event), ask for it before calling the tool.
For bulk WhatsApp actions, make sure the audience, title, and message are clear before sending.

For email requests: ALWAYS call draft_email first and wait for the admin to confirm before sending.
Never send email directly without showing the draft. The draft_email tool returns a preview card —
tell the admin to review it and click "Send" when ready.
PROMPT;

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    array_values($rawMessages)
);

// ── Tool definitions ──────────────────────────────────────────────────────────
$tools = [

    // 1. Create Announcement
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_announcement',
            'description' => 'Create and publish a school notice or announcement.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Short title of the announcement'],
                    'description' => ['type' => 'string', 'description' => 'Full body / details of the announcement'],
                    'target'      => [
                        'type'        => 'string',
                        'enum'        => ['all', 'students', 'teachers', 'parents'],
                        'description' => 'Who the announcement is for. Default: all',
                    ],
                    'start_date'  => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (optional)'],
                    'end_date'    => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (optional)'],
                ],
                'required' => ['title', 'description'],
            ],
        ],
    ],

    // 2. Create Event
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_event',
            'description' => 'Add a new event to the school calendar.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Event title'],
                    'description' => ['type' => 'string', 'description' => 'Event details or agenda'],
                    'type'        => [
                        'type'        => 'string',
                        'enum'        => ['academic', 'sports', 'cultural', 'holiday', 'exam', 'meeting', 'other'],
                        'description' => 'Category of event',
                    ],
                    'start_date'  => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                    'end_date'    => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (defaults to start_date)'],
                    'start_time'  => ['type' => 'string', 'description' => 'Start time HH:MM (optional)'],
                    'end_time'    => ['type' => 'string', 'description' => 'End time HH:MM (optional)'],
                    'venue'       => ['type' => 'string', 'description' => 'Location / venue (optional)'],
                ],
                'required' => ['title', 'start_date', 'type'],
            ],
        ],
    ],

    // 3. Fee Summary
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_fee_summary',
            'description' => 'Get a summary of fee payments — total collected, outstanding, and a breakdown by class or term.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'academic_year_id' => ['type' => 'integer', 'description' => 'Filter by academic year ID (optional)'],
                    'class_id'         => ['type' => 'integer', 'description' => 'Filter by class ID (optional)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 4. Student Report
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_student_report',
            'description' => 'Get student enrolment and class data — total students, breakdown by class, recent additions.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id' => ['type' => 'integer', 'description' => 'Filter by class ID (optional)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 5. List Classes (helper)
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_classes',
            'description' => 'Return a list of all classes with their IDs — useful before filtering other queries by class.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 6. List Academic Years (helper)
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_academic_years',
            'description' => 'Return all academic years with their IDs — useful before filtering by year.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 7. Get Onboarding Status
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_onboarding_status',
            'description' => 'Returns the live setup checklist showing which steps are done and which are pending. Use this at the start of an onboarding session.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 8. Mark Onboarding Step Complete
    [
        'type' => 'function',
        'function' => [
            'name'        => 'mark_onboarding_step',
            'description' => 'Mark a setup step as completed after the admin confirms they have finished it.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'step_key' => [
                        'type'        => 'string',
                        'enum'        => ['school_profile','academic_year','classes','subjects','teachers','students','fee_structure'],
                        'description' => 'The key of the step to mark as done.',
                    ],
                ],
                'required' => ['step_key'],
            ],
        ],
    ],

    // 9. Navigate to Page
    [
        'type' => 'function',
        'function' => [
            'name'        => 'navigate_to_page',
            'description' => 'Send the admin to a specific page in the portal. Returns a navigation card with a "Take me there" button. Use when guiding the admin to complete a setup step.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'page'        => [
                        'type'        => 'string',
                        'enum'        => [
                            'general.php', 'class-list.php', 'subject-list.php',
                            'add-new-teacher.php', 'teacher-list.php',
                            'add-new-student.php', 'student-list.php',
                            'transaction.php', 'event.php', 'notice-board.php',
                            'timetable-list.php', 'school-profile.php',
                        ],
                        'description' => 'The admin page filename to navigate to.',
                    ],
                    'label'       => ['type' => 'string', 'description' => 'Human-friendly page name, e.g. "School Settings"'],
                    'description' => ['type' => 'string', 'description' => 'One sentence explaining what to do on this page.'],
                ],
                'required' => ['page', 'label'],
            ],
        ],
    ],

    // 10. Draft Email Notification
    [
        'type' => 'function',
        'function' => [
            'name'        => 'draft_email',
            'description' => 'Draft a school email notification to be reviewed and sent by the admin. '
                           . 'Returns a structured draft with subject, HTML body, and recipient count. '
                           . 'Do NOT call send_email — the admin must confirm first.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'audience'    => [
                        'type'        => 'string',
                        'enum'        => ['all', 'parents', 'teachers', 'staff', 'students'],
                        'description' => 'Who this email is for.',
                    ],
                    'subject'     => ['type' => 'string', 'description' => 'Email subject line'],
                    'body'        => ['type' => 'string', 'description' => 'Full email body text (plain text or simple HTML)'],
                    'greeting'    => ['type' => 'string', 'description' => 'Opening salutation, e.g. "Dear Parent,"'],
                    'cta_text'    => ['type' => 'string', 'description' => 'Optional call-to-action button label'],
                    'cta_url'     => ['type' => 'string', 'description' => 'Optional call-to-action button URL'],
                ],
                'required' => ['audience', 'subject', 'body'],
            ],
        ],
    ],

    // 11. Create Class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_class',
            'description' => 'Create a new school class. If academic_year_id is not provided, the current/default academic year is used.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'             => ['type' => 'string', 'description' => 'Class name, e.g. JSS 1, Grade 4, Nursery 2'],
                    'code'             => ['type' => 'string', 'description' => 'Short unique class code. Optional; generated from name if omitted.'],
                    'description'      => ['type' => 'string', 'description' => 'Optional class description'],
                    'grade_level'      => ['type' => 'integer', 'description' => 'Optional numeric grade level'],
                    'capacity'         => ['type' => 'integer', 'description' => 'Optional student capacity'],
                    'room_number'      => ['type' => 'string', 'description' => 'Optional classroom or room number'],
                    'class_teacher_id' => ['type' => 'integer', 'description' => 'Optional user ID of class teacher'],
                    'academic_year_id' => ['type' => 'integer', 'description' => 'Optional academic year ID'],
                ],
                'required' => ['name'],
            ],
        ],
    ],

    // 12. Create Subject
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_subject',
            'description' => 'Create a new school subject.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name'         => ['type' => 'string', 'description' => 'Subject name, e.g. Mathematics'],
                    'code'         => ['type' => 'string', 'description' => 'Short unique subject code. Optional; generated from name if omitted.'],
                    'type'         => ['type' => 'string', 'enum' => ['core', 'elective'], 'description' => 'Subject type'],
                    'credit_hours' => ['type' => 'number', 'description' => 'Optional credit hours'],
                    'description'  => ['type' => 'string', 'description' => 'Optional subject description'],
                ],
                'required' => ['name'],
            ],
        ],
    ],

    // 13. Assign Subject to Class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'assign_subject_to_class',
            'description' => 'Assign an existing subject to an existing class, optionally with a teacher.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID'],
                    'subject_id' => ['type' => 'integer', 'description' => 'Subject ID'],
                    'teacher_id' => ['type' => 'integer', 'description' => 'Optional teacher user ID'],
                ],
                'required' => ['class_id', 'subject_id'],
            ],
        ],
    ],

    // 14. List Subjects
    [
        'type' => 'function',
        'function' => [
            'name'        => 'list_subjects',
            'description' => 'Return a list of active subjects with their IDs.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
    ],

    // 15. Send WhatsApp Message
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_whatsapp_message',
            'description' => 'Send a WhatsApp template message to parents, teachers, students, staff, or everyone.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'audience'   => ['type' => 'string', 'enum' => ['all', 'parents', 'teachers', 'students', 'staff'], 'description' => 'Recipient audience'],
                    'title'      => ['type' => 'string', 'description' => 'Short message title'],
                    'message'    => ['type' => 'string', 'description' => 'Full WhatsApp message body'],
                    'class_id'   => ['type' => 'integer', 'description' => 'Optional class ID filter for parents/students'],
                    'section_id' => ['type' => 'integer', 'description' => 'Optional section ID filter for parents/students'],
                ],
                'required' => ['audience', 'title', 'message'],
            ],
        ],
    ],

    // 16. Send Fee Reminders
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_fee_reminders',
            'description' => 'Send WhatsApp fee reminders to parents for unpaid or overdue invoices.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_id'    => ['type' => 'integer', 'description' => 'Optional student ID filter'],
                    'class_id'      => ['type' => 'integer', 'description' => 'Optional class ID filter'],
                    'status_filter' => ['type' => 'string', 'enum' => ['all', 'due', 'overdue'], 'description' => 'Which unpaid invoices to remind about'],
                    'message'       => ['type' => 'string', 'description' => 'Optional custom reminder message appended to invoice details'],
                    'limit'         => ['type' => 'integer', 'description' => 'Maximum reminders to send, up to 200'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 17. Send Attendance Alerts
    [
        'type' => 'function',
        'function' => [
            'name'        => 'send_attendance_alerts',
            'description' => 'Send WhatsApp attendance alerts to parents based on marked attendance records.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'date'       => ['type' => 'string', 'description' => 'Attendance date YYYY-MM-DD. Defaults to today.'],
                    'class_id'   => ['type' => 'integer', 'description' => 'Optional class ID filter'],
                    'student_id' => ['type' => 'integer', 'description' => 'Optional student ID filter'],
                    'status'     => ['type' => 'string', 'enum' => ['all', 'present', 'absent', 'late', 'half_day', 'holiday'], 'description' => 'Attendance status filter. Defaults to absent.'],
                    'message'    => ['type' => 'string', 'description' => 'Optional custom alert message appended to attendance details'],
                    'limit'      => ['type' => 'integer', 'description' => 'Maximum alerts to send, up to 200'],
                ],
                'required' => [],
            ],
        ],
    ],
];

// ── Tool executor ─────────────────────────────────────────────────────────────
$toolExecutor = function (string $toolName, array $args) use ($manager, $eventManager, $schoolDb, $school): string {

    switch ($toolName) {

        // ── create_announcement ───────────────────────────────────────────────
        case 'create_announcement': {
            $data = [
                'title'       => trim($args['title']        ?? ''),
                'description' => trim($args['description']  ?? ''),
                'target'      => $args['target']             ?? 'all',
                'start_date'  => $args['start_date']         ?? null,
                'end_date'    => $args['end_date']           ?? null,
                'class_id'    => null,
                'section_id'  => null,
            ];
            $result = $manager->createAnnouncement($data);
            return json_encode($result);
        }

        // ── create_event ──────────────────────────────────────────────────────
        case 'create_event': {
            if (!$eventManager) {
                return json_encode(['success' => false, 'message' => 'Event system not available.']);
            }
            $data = [
                'title'       => trim($args['title']       ?? ''),
                'description' => trim($args['description'] ?? ''),
                'type'        => $args['type']              ?? 'other',
                'start_date'  => $args['start_date']        ?? date('Y-m-d'),
                'end_date'    => $args['end_date']           ?? ($args['start_date'] ?? date('Y-m-d')),
                'start_time'  => $args['start_time']         ?? null,
                'end_time'    => $args['end_time']           ?? null,
                'venue'       => $args['venue']              ?? null,
                'is_public'   => 1,
            ];
            try {
                $result = $eventManager->createEvent($data, false);
                return json_encode(is_array($result)
                    ? $result
                    : ['success' => (bool)$result, 'message' => $result ? 'Event created.' : 'Failed to create event.']);
            } catch (Throwable $e) {
                return json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── get_fee_summary ───────────────────────────────────────────────────
        case 'get_fee_summary': {
            if (!$schoolDb) return json_encode(['error' => 'School database unavailable.']);

            $summary = ['total_invoiced' => 0, 'total_paid' => 0, 'total_outstanding' => 0, 'by_class' => []];

            try {
                // Try fee_payments table first (common schema)
                $tables = array_column(
                    $schoolDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0
                );

                if (in_array('fee_payments', $tables)) {
                    $paymentAmount = academix_ai_column_exists($schoolDb, 'fee_payments', 'amount_paid')
                        ? 'fp.amount_paid'
                        : (academix_ai_column_exists($schoolDb, 'fee_payments', 'amount') ? 'fp.amount' : '0');
                    $hasFeeStructureId = academix_ai_column_exists($schoolDb, 'fee_payments', 'fee_structure_id');
                    $join = $hasFeeStructureId && in_array('fee_structures', $tables, true)
                        ? 'LEFT JOIN fee_structures fs ON fs.id = fp.fee_structure_id'
                        : '';
                    $invoiceExpr = $join !== '' ? 'COALESCE(SUM(fs.amount), 0)' : '0';

                    $where = 'WHERE fp.school_id = ' . (int)$school['id'];
                    if ($join !== '' && !empty($args['academic_year_id'])) {
                        $where .= ' AND fs.academic_year_id = ' . (int)$args['academic_year_id'];
                    }
                    if ($join !== '' && !empty($args['class_id'])) {
                        $where .= ' AND fs.class_id = ' . (int)$args['class_id'];
                    }

                    $sql = "SELECT
                                {$invoiceExpr}                       AS total_invoiced,
                                COALESCE(SUM({$paymentAmount}), 0)   AS total_paid,
                                COUNT(DISTINCT fp.student_id)        AS paying_students
                            FROM fee_payments fp
                            {$join}
                            {$where}";
                    $row = $schoolDb->query($sql)->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $summary['total_invoiced']   = (float)$row['total_invoiced'];
                        $summary['total_paid']        = (float)$row['total_paid'];
                        $summary['total_outstanding'] = max(0, $summary['total_invoiced'] - $summary['total_paid']);
                        $summary['paying_students']   = (int)$row['paying_students'];
                    }
                } elseif (in_array('student_fees', $tables)) {
                    $sql = "SELECT
                                COALESCE(SUM(fee_amount), 0) AS total_invoiced,
                                COALESCE(SUM(amount_paid),  0) AS total_paid
                            FROM student_fees WHERE school_id = " . (int)$school['id'];
                    $row = $schoolDb->query($sql)->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $summary['total_invoiced']   = (float)$row['total_invoiced'];
                        $summary['total_paid']        = (float)$row['total_paid'];
                        $summary['total_outstanding'] = max(0, $summary['total_invoiced'] - $summary['total_paid']);
                    }
                }

                // Class breakdown (if class_id not already filtered)
                if (empty($args['class_id']) && in_array('classes', $tables)) {
                    $clsRows = $schoolDb->query("
                        SELECT c.name, COUNT(s.id) AS student_count
                        FROM classes c
                        LEFT JOIN students s ON s.class_id = c.id AND s.status = 'active'
                        WHERE c.school_id = " . (int)$school['id'] . "
                        GROUP BY c.id ORDER BY c.name LIMIT 10
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    $summary['class_student_counts'] = $clsRows;
                }
            } catch (Throwable $e) {
                error_log('AI fee_summary error: ' . $e->getMessage());
                return json_encode(['error' => 'Could not query fee data: ' . $e->getMessage()]);
            }

            $currency = $school['currency'] ?? 'NGN';
            $summary['currency'] = $currency;
            return json_encode($summary);
        }

        // ── get_student_report ────────────────────────────────────────────────
        case 'get_student_report': {
            if (!$schoolDb) return json_encode(['error' => 'School database unavailable.']);

            try {
                $total = (int)$schoolDb->query(
                    "SELECT COUNT(*) FROM students WHERE school_id = " . (int)$school['id'] . " AND status = 'active'"
                )->fetchColumn();

                $classFilter = !empty($args['class_id'])
                    ? ' AND s.class_id = ' . (int)$args['class_id']
                    : '';

                $byClass = $schoolDb->query("
                    SELECT c.name AS class_name, COUNT(s.id) AS student_count
                    FROM students s
                    JOIN classes c ON c.id = s.class_id
                    WHERE s.school_id = " . (int)$school['id'] . " AND s.status = 'active' {$classFilter}
                    GROUP BY c.id ORDER BY c.name LIMIT 20
                ")->fetchAll(PDO::FETCH_ASSOC);

                $recent = $schoolDb->query("
                    SELECT CONCAT(first_name,' ',last_name) AS name, created_at
                    FROM students
                    WHERE school_id = " . (int)$school['id'] . "
                    ORDER BY created_at DESC LIMIT 5
                ")->fetchAll(PDO::FETCH_ASSOC);

                return json_encode([
                    'total_active_students' => $total,
                    'by_class'              => $byClass,
                    'recently_enrolled'     => $recent,
                ]);
            } catch (Throwable $e) {
                error_log('AI student_report error: ' . $e->getMessage());
                return json_encode(['error' => $e->getMessage()]);
            }
        }

        // ── list_classes ──────────────────────────────────────────────────────
        case 'list_classes': {
            $classes = $manager->getClasses();
            return json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $classes));
        }

        // ── list_academic_years ───────────────────────────────────────────────
        case 'list_academic_years': {
            $years = $manager->getAcademicYears();
            return json_encode(array_map(fn($y) => [
                'id'   => $y['id'],
                'name' => $y['name'],
                'is_default' => (bool)($y['is_default'] ?? false),
            ], $years));
        }

        // ── create_class ─────────────────────────────────────────────────────
        case 'create_class': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }

            $name = trim((string)($args['name'] ?? ''));
            if ($name === '') {
                return json_encode(['success' => false, 'message' => 'Class name is required.']);
            }

            $academicYearId = (int)($args['academic_year_id'] ?? 0);
            if ($academicYearId <= 0) {
                $academicYearId = academix_ai_default_academic_year_id($schoolDb, (int)$school['id']);
            }
            if ($academicYearId <= 0) {
                return json_encode(['success' => false, 'message' => 'Create an academic year first, then create the class.']);
            }

            $code = trim((string)($args['code'] ?? ''));
            if ($code === '') {
                $code = academix_ai_code_from_name($name, 'CLS');
            }

            $data = [
                'name' => $name,
                'code' => strtoupper($code),
                'description' => trim((string)($args['description'] ?? '')),
                'grade_level' => isset($args['grade_level']) ? (int)$args['grade_level'] : null,
                'class_teacher_id' => !empty($args['class_teacher_id']) ? (int)$args['class_teacher_id'] : null,
                'capacity' => !empty($args['capacity']) ? max(1, (int)$args['capacity']) : 40,
                'room_number' => trim((string)($args['room_number'] ?? '')),
                'academic_year_id' => $academicYearId,
            ];

            return json_encode($manager->createClass($data));
        }

        // ── create_subject ───────────────────────────────────────────────────
        case 'create_subject': {
            $name = trim((string)($args['name'] ?? ''));
            if ($name === '') {
                return json_encode(['success' => false, 'message' => 'Subject name is required.']);
            }

            $code = trim((string)($args['code'] ?? ''));
            if ($code === '') {
                $code = academix_ai_code_from_name($name, 'SUB');
            }

            $data = [
                'name' => $name,
                'code' => strtoupper($code),
                'type' => in_array(($args['type'] ?? 'core'), ['core', 'elective'], true) ? $args['type'] : 'core',
                'credit_hours' => isset($args['credit_hours']) ? (float)$args['credit_hours'] : 1.0,
                'description' => trim((string)($args['description'] ?? '')),
            ];

            return json_encode($manager->createSubject($data));
        }

        // ── assign_subject_to_class ──────────────────────────────────────────
        case 'assign_subject_to_class': {
            $classId = (int)($args['class_id'] ?? 0);
            $subjectId = (int)($args['subject_id'] ?? 0);
            if ($classId <= 0 || $subjectId <= 0) {
                return json_encode(['success' => false, 'message' => 'Class ID and subject ID are required.']);
            }

            return json_encode($manager->assignSubjectToClass([
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'teacher_id' => !empty($args['teacher_id']) ? (int)$args['teacher_id'] : null,
            ]));
        }

        // ── list_subjects ────────────────────────────────────────────────────
        case 'list_subjects': {
            $subjects = $manager->getSubjects();
            return json_encode(array_map(fn($s) => [
                'id' => $s['id'],
                'name' => $s['name'],
                'code' => $s['code'] ?? '',
                'type' => $s['type'] ?? 'core',
            ], $subjects));
        }

        // ── send_whatsapp_message ────────────────────────────────────────────
        case 'send_whatsapp_message': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }

            $audience = strtolower(trim((string)($args['audience'] ?? 'all')));
            $title = trim((string)($args['title'] ?? 'School Notification'));
            $message = trim((string)($args['message'] ?? ''));
            if ($message === '') {
                return json_encode(['success' => false, 'message' => 'WhatsApp message body is required.']);
            }

            $allowed = ['all', 'parents', 'teachers', 'students', 'staff'];
            if (!in_array($audience, $allowed, true)) {
                $audience = 'all';
            }

            $service = new WhatsAppService($schoolDb, $school);
            $recipients = $service->resolveAnnouncementRecipients(
                in_array($audience, ['parents', 'teachers', 'students'], true) ? $audience : 'all',
                !empty($args['class_id']) ? (int)$args['class_id'] : null,
                !empty($args['section_id']) ? (int)$args['section_id'] : null,
                [$audience]
            );

            $result = academix_ai_send_whatsapp_batch($schoolDb, $school, 'announcement', $recipients, $title, $message, 'login.php');
            return json_encode($result);
        }

        // ── send_fee_reminders ───────────────────────────────────────────────
        case 'send_fee_reminders': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            if (!academix_ai_table_exists($schoolDb, 'invoices')) {
                return json_encode(['success' => false, 'message' => 'The invoices table is not available in this school database.']);
            }
            if (!WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'fees', true)) {
                return json_encode(['success' => false, 'message' => 'WhatsApp fee reminders are disabled in settings.']);
            }

            $statusFilter = strtolower(trim((string)($args['status_filter'] ?? 'all')));
            $limit = min(200, max(1, (int)($args['limit'] ?? 50)));
            $customMessage = trim((string)($args['message'] ?? ''));

            $where = [
                'i.school_id = ?',
                "i.status IN ('pending','partial','overdue')",
                'COALESCE(i.balance_amount, i.total_amount, 0) > 0',
            ];
            $params = [(int)$school['id']];

            if (!empty($args['student_id'])) {
                $where[] = 'i.student_id = ?';
                $params[] = (int)$args['student_id'];
            }
            if (!empty($args['class_id'])) {
                $where[] = 'i.class_id = ?';
                $params[] = (int)$args['class_id'];
            }
            if ($statusFilter === 'overdue') {
                $where[] = "(i.status = 'overdue' OR i.due_date < CURDATE())";
            } elseif ($statusFilter === 'due') {
                $where[] = "(i.status <> 'overdue' AND i.due_date >= CURDATE())";
            }

            try {
                $sql = "
                    SELECT
                        i.id AS invoice_id,
                        i.invoice_number,
                        i.due_date,
                        i.total_amount,
                        i.balance_amount,
                        i.status,
                        s.id AS student_id,
                        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name,
                        c.name AS class_name,
                        u.id AS guardian_user_id,
                        u.name AS guardian_name,
                        u.phone
                    FROM invoices i
                    INNER JOIN students s ON s.id = i.student_id AND s.school_id = i.school_id
                    LEFT JOIN classes c ON c.id = i.class_id AND c.school_id = i.school_id
                    INNER JOIN guardians g ON g.student_id = s.id AND g.school_id = s.school_id
                    INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id
                    WHERE " . implode(' AND ', $where) . "
                      AND u.is_active = 1
                      AND u.phone IS NOT NULL
                      AND u.phone != ''
                    ORDER BY i.due_date ASC, i.id ASC
                    LIMIT {$limit}
                ";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('AI fee reminder query failed: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch fee reminders: ' . $e->getMessage()]);
            }

            if (!$rows) {
                return json_encode(['success' => false, 'message' => 'No unpaid invoices matched the selected reminder filter.']);
            }

            $service = new WhatsAppService($schoolDb, $school);
            $sent = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $currency = $school['currency_symbol'] ?? ($school['currency'] ?? 'NGN');
                $balance = $currency . ' ' . number_format((float)($row['balance_amount'] ?? $row['total_amount'] ?? 0), 2);
                $dueDate = !empty($row['due_date']) ? date('F j, Y', strtotime($row['due_date'])) : 'the due date';
                $studentName = trim((string)($row['student_name'] ?? 'your child'));
                $title = 'Fee Payment Reminder';
                $body = "Invoice {$row['invoice_number']} for {$studentName} has an outstanding balance of {$balance}, due on {$dueDate}.";
                if ($customMessage !== '') {
                    $body .= ' ' . $customMessage;
                }

                $result = $service->sendDirectNotification(
                    'fee',
                    (int)$row['invoice_id'],
                    [
                        'user_id' => (int)$row['guardian_user_id'],
                        'name' => $row['guardian_name'] ?? 'Parent',
                        'phone' => $row['phone'] ?? '',
                        'recipient_type' => 'parent',
                    ],
                    $title,
                    $body,
                    'parent/fees.php'
                );

                if (!empty($result['success'])) {
                    $sent++;
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            return json_encode([
                'success' => $sent > 0,
                'total' => count($rows),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'message' => "Fee reminders processed for " . count($rows) . " recipient(s): {$sent} sent, {$failed} failed, {$skipped} skipped.",
            ]);
        }

        // ── send_attendance_alerts ───────────────────────────────────────────
        case 'send_attendance_alerts': {
            if (!$schoolDb) {
                return json_encode(['success' => false, 'message' => 'School database unavailable.']);
            }
            if (!academix_ai_table_exists($schoolDb, 'attendance')) {
                return json_encode(['success' => false, 'message' => 'The attendance table is not available in this school database.']);
            }
            if (!WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'attendance', true)) {
                return json_encode(['success' => false, 'message' => 'WhatsApp attendance alerts are disabled in settings.']);
            }

            $date = trim((string)($args['date'] ?? date('Y-m-d')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d', strtotime($date) ?: time());
            }
            $status = strtolower(trim((string)($args['status'] ?? 'absent')));
            $allowedStatuses = ['all', 'present', 'absent', 'late', 'half_day', 'holiday'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'absent';
            }
            $limit = min(200, max(1, (int)($args['limit'] ?? 100)));
            $customMessage = trim((string)($args['message'] ?? ''));

            $where = ['a.school_id = ?', 'a.date = ?'];
            $params = [(int)$school['id'], $date];

            if ($status !== 'all') {
                $where[] = 'a.status = ?';
                $params[] = $status;
            }
            if (!empty($args['class_id'])) {
                $where[] = 'a.class_id = ?';
                $params[] = (int)$args['class_id'];
            }
            if (!empty($args['student_id'])) {
                $where[] = 'a.student_id = ?';
                $params[] = (int)$args['student_id'];
            }

            try {
                $sql = "
                    SELECT
                        a.id AS attendance_id,
                        a.status,
                        a.date,
                        a.remark,
                        s.id AS student_id,
                        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) AS student_name,
                        c.name AS class_name,
                        u.id AS guardian_user_id,
                        u.name AS guardian_name,
                        u.phone
                    FROM attendance a
                    INNER JOIN students s ON s.id = a.student_id AND s.school_id = a.school_id
                    LEFT JOIN classes c ON c.id = a.class_id AND c.school_id = a.school_id
                    INNER JOIN guardians g ON g.student_id = s.id AND g.school_id = s.school_id
                    INNER JOIN users u ON u.id = g.user_id AND u.school_id = g.school_id
                    WHERE " . implode(' AND ', $where) . "
                      AND u.is_active = 1
                      AND u.phone IS NOT NULL
                      AND u.phone != ''
                    ORDER BY c.name, s.first_name
                    LIMIT {$limit}
                ";
                $stmt = $schoolDb->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('AI attendance alert query failed: ' . $e->getMessage());
                return json_encode(['success' => false, 'message' => 'Could not fetch attendance records: ' . $e->getMessage()]);
            }

            if (!$rows) {
                return json_encode(['success' => false, 'message' => 'No attendance records matched the selected alert filter.']);
            }

            $statusLabels = [
                'present' => 'Present',
                'absent' => 'Absent',
                'late' => 'Late',
                'half_day' => 'Half Day',
                'holiday' => 'Holiday',
            ];
            $service = new WhatsAppService($schoolDb, $school);
            $sent = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $studentName = trim((string)($row['student_name'] ?? 'your child'));
                $statusText = $statusLabels[$row['status']] ?? ucfirst((string)$row['status']);
                $formattedDate = date('F j, Y', strtotime($row['date']));
                $className = $row['class_name'] ?? 'their class';
                $title = "Attendance Alert: {$studentName}";
                $body = "{$studentName} from {$className} was marked as {$statusText} on {$formattedDate}.";
                if (!empty($row['remark'])) {
                    $body .= " Remark: {$row['remark']}.";
                }
                if ($customMessage !== '') {
                    $body .= ' ' . $customMessage;
                }

                $result = $service->sendDirectNotification(
                    'attendance',
                    (int)$row['attendance_id'],
                    [
                        'user_id' => (int)$row['guardian_user_id'],
                        'name' => $row['guardian_name'] ?? 'Parent',
                        'phone' => $row['phone'] ?? '',
                        'recipient_type' => 'parent',
                    ],
                    $title,
                    $body,
                    'parent/attendance.php'
                );

                if (!empty($result['success'])) {
                    $sent++;
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            return json_encode([
                'success' => $sent > 0,
                'total' => count($rows),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'message' => "Attendance alerts processed for " . count($rows) . " recipient(s): {$sent} sent, {$failed} failed, {$skipped} skipped.",
            ]);
        }

        // ── draft_email ───────────────────────────────────────────────────────
        // Generates the template draft and returns recipient count.
        // The actual sending happens via the direct send_email action (not AI).
        case 'draft_email': {
            $audience = $args['audience'] ?? 'all';
            $subject  = trim($args['subject'] ?? '');
            $body     = trim($args['body']    ?? '');
            $greeting = trim($args['greeting'] ?? '');
            $ctaText  = trim($args['cta_text'] ?? '');
            $ctaUrl   = trim($args['cta_url']  ?? '');

            // Count recipients so admin can see who will receive it
            $recipientCount = 0;
            if ($schoolDb) {
                $sender = new SchoolEmailSender($schoolDb, $school);
                $preview = $sender->resolveRecipients($audience);
                $recipientCount = $preview['count'];
            }

            // Return a structured draft the chat UI will render as a preview card
            return json_encode([
                '__type'          => 'email_draft',   // UI marker
                'audience'        => $audience,
                'subject'         => $subject,
                'body_html'       => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                'body_plain'      => $body,
                'greeting'        => $greeting,
                'cta_text'        => $ctaText,
                'cta_url'         => $ctaUrl,
                'recipient_count' => $recipientCount,
            ]);
        }

        // ── get_onboarding_status ─────────────────────────────────────────────
        case 'get_onboarding_status': {
            if (!$schoolDb) return json_encode(['error' => 'School database unavailable.']);
            $steps   = onboarding_build_steps($schoolDb, (int)$school['id'], $school);
            $done    = count(array_filter($steps, fn($s) => $s['done']));
            $total   = count($steps);
            $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
            return json_encode([
                'steps'   => $steps,
                'done'    => $done,
                'total'   => $total,
                'percent' => $percent,
                'message' => "{$done} of {$total} setup steps completed ({$percent}%).",
            ]);
        }

        // ── mark_onboarding_step ──────────────────────────────────────────────
        case 'mark_onboarding_step': {
            $stepKey = $args['step_key'] ?? '';
            if ($schoolDb && $stepKey) {
                onboarding_mark_step($schoolDb, (int)$school['id'], $stepKey);
                return json_encode(['success' => true, 'message' => "Step '{$stepKey}' marked as complete."]);
            }
            return json_encode(['success' => false, 'message' => 'No step key provided.']);
        }

        // ── navigate_to_page ──────────────────────────────────────────────────
        case 'navigate_to_page': {
            $page  = $args['page']        ?? 'general.php';
            $label = $args['label']       ?? 'Portal Page';
            $desc  = $args['description'] ?? '';
            $slug  = $school['slug']      ?? '';

            // Build absolute URL — works with both subdomain and path-based routing
            $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
            $url  = function_exists('school_route_url')
                ? school_route_url($slug, 'admin', $page, false)
                : "{$base}/tenant/{$slug}/admin/{$page}";

            // Icon map
            $iconMap = [
                'general.php'         => 'ri-settings-3-line',
                'school-profile.php'  => 'ri-building-2-line',
                'class-list.php'      => 'ri-school-line',
                'subject-list.php'    => 'ri-book-open-line',
                'add-new-teacher.php' => 'ri-user-star-line',
                'teacher-list.php'    => 'ri-user-star-line',
                'add-new-student.php' => 'ri-graduation-cap-line',
                'student-list.php'    => 'ri-graduation-cap-line',
                'transaction.php'     => 'ri-wallet-3-line',
                'event.php'           => 'ri-calendar-event-line',
                'notice-board.php'    => 'ri-megaphone-line',
                'timetable-list.php'  => 'ri-time-line',
            ];

            return json_encode([
                '__type'      => 'navigation',   // UI marker — renders as a nav card
                'page'        => $page,
                'url'         => $url,
                'label'       => $label,
                'description' => $desc,
                'icon'        => $iconMap[$page] ?? 'ri-arrow-right-line',
            ]);
        }

        default:
            return json_encode(['error' => "Unknown tool: {$toolName}"]);
    }
};

// ── Run Groq ──────────────────────────────────────────────────────────────────
$apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
$model  = $_ENV['GROQ_MODEL']   ?? getenv('GROQ_MODEL')   ?? 'llama-3.1-8b-instant';

if (empty($apiKey) || $apiKey === 'gsk-your-key-here') {
    echo json_encode([
        'success' => false,
        'message' => 'Groq API key not configured. Please add GROQ_API_KEY to your .env file.',
    ]);
    exit;
}

try {
    $groq = new GroqClient($apiKey, $model);
    $result = $groq->run($messages, $tools, $toolExecutor, 4, 700);

    $isError = strncmp((string)($result['reply'] ?? ''), 'AI error: ', 10) === 0;

    echo json_encode([
        'success'         => !$isError,
        'reply'           => $result['reply'],
        'message'         => $isError ? $result['reply'] : '',
        'tool_calls_made' => $result['tool_calls_made'],
    ]);
} catch (Throwable $e) {
    error_log('AI assistant fatal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . $e->getMessage()]);
}
