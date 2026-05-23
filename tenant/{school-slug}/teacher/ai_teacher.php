<?php
/**
 * Teacher AI Assistant Endpoint
 *
 * Authenticated for teachers only. Provides tools for:
 *   - Fetching the teacher's own classes and students (DB reads)
 *   - Attendance and grade summaries (DB reads)
 *   - Creating class announcements (DB write)
 *   - Drafting parent emails (preview card, then send via SchoolEmailSender)
 *
 * Generative tasks (lesson plans, assignment questions, exam papers,
 * student remarks) are handled directly by DeepSeek in its reply —
 * no tool call needed, just great system-prompt guidance.
 *
 * POST body:
 *   messages   – JSON-encoded conversation array
 *   csrf_token – required
 *   action     – optional: 'send_email' or 'preview_recipients' (direct, non-AI)
 */

// Buffer ALL output so no stray PHP notice/warning can corrupt the JSON response
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
$aiLogDir = __DIR__ . '/../../../logs';
if (!is_dir($aiLogDir)) {
    @mkdir($aiLogDir, 0755, true);
}
ini_set('error_log', $aiLogDir . '/ai_teacher.log');

require_once __DIR__ . '/../../../includes/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    session_start(function_exists('academix_session_options') ? academix_session_options() : []);
}

ob_end_clean(); // discard any accidental output before JSON
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$jsonInput = json_decode((string) file_get_contents('php://input'), true);
$requestInput = $_POST;
if (is_array($jsonInput)) {
    $requestInput = array_replace_recursive($requestInput, $jsonInput);
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$auth       = $_SESSION['school_auth'] ?? [];
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? ($auth['school_slug'] ?? '');
$school     = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($school) && !empty($auth['school_id'])) {
    // Minimal school context from session when GLOBALS not populated by router
    $school = [
        'id'            => $auth['school_id'],
        'slug'          => $auth['school_slug']    ?? $schoolSlug,
        'name'          => $auth['school_name']    ?? 'School',
        'database_name' => $auth['database_name']  ?? '',
        'email'         => '',
        'logo_path'     => '',
        'primary_color' => '#25A194',
    ];

    // Enrich from platform DB if possible
    try {
        $pDb  = Database::getPlatformConnection();
        $stmt = $pDb->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
        $stmt->execute([$school['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $school = array_merge($school, $row);
    } catch (Throwable $e) { /* non-fatal */ }
}

if (empty($auth) || ($auth['user_type'] ?? '') !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Teacher authentication required.']); exit;
}

$schoolSlug = $school['slug'] ?? $schoolSlug;
if (empty($schoolSlug) || (($auth['school_slug'] ?? '') !== $schoolSlug)) {
    echo json_encode(['success' => false, 'message' => 'Invalid school context.']); exit;
}

// CSRF
if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($t) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$t);
    }
}
$token = $requestInput['csrf_token'] ?? '';
if (!validateCsrfToken($token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']); exit;
}

// ── DB connections ────────────────────────────────────────────────────────────
$userId   = (int) ($auth['user_id'] ?? 0);
$schoolId = (int) ($school['id']    ?? 0);
$schoolDb = null;

try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
    }
} catch (Throwable $e) {
    error_log('ai_teacher: school DB unavailable – ' . $e->getMessage());
}

require_once __DIR__ . '/../../../includes/DeepSeekClient.php';
require_once __DIR__ . '/../../../includes/Services/SchoolEmailSender.php';

// ── Direct (non-AI) actions ───────────────────────────────────────────────────
$directAction = $requestInput['action'] ?? '';

if ($directAction === 'send_email') {
    if (!$schoolDb) { echo json_encode(['success'=>false,'message'=>'School database unavailable.']); exit; }
    $subject  = trim($requestInput['subject']   ?? '');
    $bodyHtml = trim($requestInput['body_html'] ?? '');
    $audience = trim($requestInput['audience']  ?? 'parents');
    if ($subject==='' || $bodyHtml==='') { echo json_encode(['success'=>false,'message'=>'Subject and body required.']); exit; }
    $sender = new SchoolEmailSender($schoolDb, $school);
    echo json_encode($sender->send($audience, $subject, $bodyHtml));
    exit;
}

if ($directAction === 'preview_recipients') {
    if (!$schoolDb) { echo json_encode(['success'=>false,'message'=>'School database unavailable.']); exit; }
    $audience = trim($requestInput['audience'] ?? 'parents');
    $sender   = new SchoolEmailSender($schoolDb, $school);
    $preview  = $sender->resolveRecipients($audience);
    echo json_encode(array_merge(['success'=>true], $preview));
    exit;
}

// ── Parse messages ────────────────────────────────────────────────────────────
$rawMessages = $requestInput['messages'] ?? ($requestInput['history'] ?? []);
if (is_string($rawMessages)) {
    $rawMessages = json_decode($rawMessages, true) ?? [];
}
if (!is_array($rawMessages) || empty($rawMessages)) {
    echo json_encode(['success'=>false,'message'=>'No messages provided.']); exit;
}

// ── System prompt ─────────────────────────────────────────────────────────────
$schoolName   = htmlspecialchars_decode($school['name'] ?? 'the school');
$teacherName  = htmlspecialchars_decode($auth['user_name'] ?? 'Teacher');
$today        = date('l, F j Y');

$systemPrompt = <<<PROMPT
You are an AI teaching assistant inside AcademixSuite for {$schoolName}.
You are helping {$teacherName}, a teacher at this school. Today is {$today}.

You can help with:

1. LESSON PLANNING — When asked, generate a well-structured lesson plan using this format:
   **Lesson Plan: [Topic]**
   - Subject | Class | Duration
   - Learning Objectives (3-5 bullet points)
   - Starter Activity (5 min)
   - Main Teaching (broken into steps)
   - Class Activity / Group Work
   - Assessment / Check for Understanding
   - Homework / Follow-up
   - Resources needed

2. ASSIGNMENT & HOMEWORK CREATION — Generate questions with clear instructions.
   Include: objective, instructions, questions (numbered), total marks.
   Mark clearly if questions are theory, MCQ, or practical.

3. EXAM QUESTION PAPERS — Format as a professional exam paper:
   School name, subject, class, date, time allowed, total marks.
   Section A (Objectives), Section B (Theory), Section C (Essay) as appropriate.
   Include a marking guide at the end.

4. STUDENT REPORT REMARKS — Write encouraging, specific remarks for report cards.
   Keep them under 40 words. Vary the tone — avoid repeating the same phrases.
   Always end with an encouraging note for improvement.

5. PARENT COMMUNICATION — Draft professional, warm emails to parents.
   Use the draft_parent_email tool so the teacher can review before sending.

6. CLASS DATA — Use provided tools to look up your classes, students, attendance,
   and grades. Always check real data before making claims about students.

When generating documents (lesson plans, exams, assignments), format with clear
headers and spacing so the teacher can copy-paste directly. Be concise and practical.
PROMPT;

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    array_values($rawMessages)
);

// ── DB helper ─────────────────────────────────────────────────────────────────
$safeQuery = function (string $sql, array $params = []) use ($schoolDb): array {
    if (!$schoolDb) return [];
    try {
        $stmt = $schoolDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('ai_teacher query error: ' . $e->getMessage());
        return [];
    }
};

// ── Tool definitions ──────────────────────────────────────────────────────────
$tools = [

    // 1. My classes
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_my_classes',
            'description' => 'List all classes this teacher is assigned to (as class teacher or subject teacher). Returns class name, subject, and student count.',
            'parameters'  => ['type' => 'object', 'properties' => [], 'required' => []],
        ],
    ],

    // 2. Students in a class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_class_students',
            'description' => 'List all active students in a specific class.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id' => ['type' => 'integer', 'description' => 'Class ID from get_my_classes'],
                    'class_name' => ['type' => 'string', 'description' => 'Class name (used if class_id unknown)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 3. Attendance summary
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_attendance_summary',
            'description' => 'Get attendance stats for a class — present/absent/late counts and rate for recent days.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id' => ['type' => 'integer', 'description' => 'Class ID'],
                    'days'     => ['type' => 'integer', 'description' => 'How many recent school days to include (default: 30)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 4. Student grades in a class
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_class_grades',
            'description' => 'Get grade/score summaries for students in one of the teacher\'s classes.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'integer', 'description' => 'Class ID'],
                    'subject_id' => ['type' => 'integer', 'description' => 'Filter by subject ID (optional)'],
                ],
                'required' => [],
            ],
        ],
    ],

    // 5. Create a class announcement
    [
        'type' => 'function',
        'function' => [
            'name'        => 'create_class_announcement',
            'description' => 'Post an announcement targeted to a specific class (or all students).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Announcement title'],
                    'description' => ['type' => 'string', 'description' => 'Announcement body'],
                    'class_id'    => ['type' => 'integer', 'description' => 'Target class ID (omit for all students)'],
                ],
                'required' => ['title', 'description'],
            ],
        ],
    ],

    // 6. Draft parent email (preview card)
    [
        'type' => 'function',
        'function' => [
            'name'        => 'draft_parent_email',
            'description' => 'Draft a professional email to a student\'s parent or guardian. Returns a preview card for the teacher to review and send.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'student_name' => ['type' => 'string', 'description' => 'Student\'s full name'],
                    'subject'      => ['type' => 'string', 'description' => 'Email subject line'],
                    'body'         => ['type' => 'string', 'description' => 'Email body (professional, warm tone)'],
                    'greeting'     => ['type' => 'string', 'description' => 'Opening greeting e.g. "Dear Parent/Guardian,"'],
                    'audience'     => [
                        'type'    => 'string',
                        'enum'    => ['parents', 'all'],
                        'description' => 'Send to: parents (default) or all',
                    ],
                ],
                'required' => ['student_name', 'subject', 'body'],
            ],
        ],
    ],
];

// ── Tool executor ─────────────────────────────────────────────────────────────
$toolExecutor = function (string $toolName, array $args)
    use ($schoolDb, $school, $schoolId, $userId, $safeQuery): string
{
    switch ($toolName) {

        // ── get_my_classes ────────────────────────────────────────────────────
        case 'get_my_classes': {
            // Classes where this user is the class teacher
            $asClassTeacher = $safeQuery(
                "SELECT c.id, c.name, c.grade_level,
                        (SELECT COUNT(*) FROM students s
                         WHERE s.class_id = c.id AND s.status = 'active') AS student_count
                 FROM classes c
                 WHERE c.school_id = ? AND c.class_teacher_id = ? AND c.is_active = 1
                 ORDER BY c.name",
                [$schoolId, $userId]
            );

            // Classes/subjects via class_subjects assignment
            $asSubjectTeacher = $safeQuery(
                "SELECT DISTINCT c.id, c.name, c.grade_level, sub.name AS subject_name,
                        (SELECT COUNT(*) FROM students s
                         WHERE s.class_id = c.id AND s.status = 'active') AS student_count
                 FROM class_subjects cs
                 JOIN classes c   ON c.id = cs.class_id AND c.school_id = ?
                 JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE cs.teacher_id = ? AND c.is_active = 1
                 ORDER BY c.name",
                [$schoolId, $userId]
            );

            $result = ['class_teacher_of' => $asClassTeacher, 'subject_teacher_of' => $asSubjectTeacher];
            if (empty($asClassTeacher) && empty($asSubjectTeacher)) {
                $result['note'] = 'No classes found. Classes may not yet be assigned in the system.';
            }
            return json_encode($result);
        }

        // ── get_class_students ────────────────────────────────────────────────
        case 'get_class_students': {
            $where  = 's.school_id = ? AND s.status = \'active\'';
            $params = [$schoolId];

            if (!empty($args['class_id'])) {
                $where   .= ' AND s.class_id = ?';
                $params[] = (int) $args['class_id'];
            } elseif (!empty($args['class_name'])) {
                $where   .= ' AND c.name LIKE ?';
                $params[] = '%' . $args['class_name'] . '%';
            }

            $students = $safeQuery(
                "SELECT s.id, CONCAT(s.first_name, ' ', s.last_name) AS name,
                        s.admission_number, s.gender, c.name AS class_name
                 FROM students s
                 LEFT JOIN classes c ON c.id = s.class_id
                 WHERE {$where}
                 ORDER BY s.last_name, s.first_name LIMIT 80",
                $params
            );
            return json_encode(['students' => $students, 'count' => count($students)]);
        }

        // ── get_attendance_summary ────────────────────────────────────────────
        case 'get_attendance_summary': {
            $days    = max(1, min(90, (int)($args['days'] ?? 30)));
            $classId = !empty($args['class_id']) ? (int)$args['class_id'] : null;

            $where   = 'a.school_id = ? AND a.date >= CURDATE() - INTERVAL ? DAY';
            $params  = [$schoolId, $days];
            if ($classId) { $where .= ' AND a.class_id = ?'; $params[] = $classId; }

            $rows = $safeQuery(
                "SELECT a.status, COUNT(*) AS cnt
                 FROM attendance a
                 WHERE {$where}
                 GROUP BY a.status",
                $params
            );

            $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'days' => $days];
            foreach ($rows as $r) $summary[strtolower($r['status'])] = (int)$r['cnt'];
            $total = array_sum(array_filter($summary, 'is_int'));
            $summary['attendance_rate'] = $total > 0
                ? round($summary['present'] / $total * 100, 1) . '%'
                : 'N/A';

            // Top absentees
            $absentees = $safeQuery(
                "SELECT CONCAT(s.first_name,' ',s.last_name) AS name, COUNT(*) AS absences
                 FROM attendance a
                 JOIN students s ON s.id = a.student_id
                 WHERE {$where} AND a.status = 'absent'
                 GROUP BY a.student_id ORDER BY absences DESC LIMIT 5",
                $params
            );
            $summary['top_absentees'] = $absentees;

            return json_encode($summary);
        }

        // ── get_class_grades ─────────────────────────────────────────────────
        case 'get_class_grades': {
            $classId   = !empty($args['class_id'])   ? (int)$args['class_id']   : null;
            $subjectId = !empty($args['subject_id']) ? (int)$args['subject_id'] : null;

            // Try exam_results table first, fall back to grades
            $table = $safeQuery("SHOW TABLES LIKE 'exam_results'");
            $useTable = !empty($table) ? 'exam_results' : 'grades';

            $where   = 'r.school_id = ?';
            $params  = [$schoolId];
            if ($classId)   { $where .= ' AND s.class_id = ?';   $params[] = $classId; }
            if ($subjectId) { $where .= ' AND r.subject_id = ?'; $params[] = $subjectId; }

            $rows = $safeQuery(
                "SELECT CONCAT(s.first_name,' ',s.last_name) AS student,
                        sub.name AS subject,
                        COALESCE(r.score, r.marks_obtained) AS score,
                        COALESCE(r.max_score, r.total_marks, 100) AS max_score
                 FROM {$useTable} r
                 JOIN students s  ON s.id = r.student_id
                 LEFT JOIN subjects sub ON sub.id = r.subject_id
                 WHERE {$where}
                 ORDER BY s.last_name LIMIT 60",
                $params
            );

            if (empty($rows)) {
                return json_encode(['note' => 'No grade data found for these filters.']);
            }

            $scores = array_column($rows, 'score');
            $avg    = count($scores) ? round(array_sum($scores) / count($scores), 1) : 0;
            return json_encode(['grades' => $rows, 'class_average' => $avg, 'count' => count($rows)]);
        }

        // ── create_class_announcement ─────────────────────────────────────────
        case 'create_class_announcement': {
            if (!$schoolDb) return json_encode(['success'=>false,'message'=>'Database unavailable.']);

            $title    = trim($args['title']       ?? '');
            $desc     = trim($args['description'] ?? '');
            $classId  = !empty($args['class_id']) ? (int)$args['class_id'] : null;

            if (!$title || !$desc) return json_encode(['success'=>false,'message'=>'Title and description required.']);

            try {
                $cols = array_column(
                    $schoolDb->query("SHOW COLUMNS FROM `announcements`")->fetchAll(PDO::FETCH_ASSOC),
                    'Field'
                );
                $data  = ['school_id'=>$schoolId,'title'=>$title,'description'=>$desc,'target'=>'students'];
                if ($classId && in_array('class_id', $cols)) $data['class_id'] = $classId;
                if (in_array('created_by', $cols))           $data['created_by'] = $userId;
                if (in_array('created_at', $cols))           $data['created_at'] = date('Y-m-d H:i:s');

                $fields = array_keys($data);
                $sql    = 'INSERT INTO announcements (`' . implode('`,`', $fields) . '`) VALUES ('
                        . implode(',', array_fill(0, count($fields), '?')) . ')';
                $schoolDb->prepare($sql)->execute(array_values($data));
                return json_encode(['success'=>true,'message'=>'Announcement posted successfully.']);
            } catch (Throwable $e) {
                error_log('create_class_announcement: ' . $e->getMessage());
                return json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
        }

        // ── draft_parent_email ────────────────────────────────────────────────
        case 'draft_parent_email': {
            $audience = $args['audience'] ?? 'parents';
            $recipientCount = 0;
            if ($schoolDb) {
                $sender = new SchoolEmailSender($schoolDb, $school);
                $recipientCount = $sender->resolveRecipients($audience)['count'];
            }

            return json_encode([
                '__type'          => 'email_draft',
                'audience'        => $audience,
                'subject'         => $args['subject']      ?? '',
                'body_html'       => nl2br(htmlspecialchars($args['body'] ?? '', ENT_QUOTES, 'UTF-8')),
                'body_plain'      => $args['body']         ?? '',
                'greeting'        => $args['greeting']     ?? 'Dear Parent/Guardian,',
                'recipient_count' => $recipientCount,
                'student_context' => $args['student_name'] ?? '',
            ]);
        }

        default:
            return json_encode(['error' => "Unknown tool: {$toolName}"]);
    }
};

// ── Run DeepSeek ───────────────────────────────────────────────────────────────
$apiKey = $_ENV['DEEPSEEK_API_KEY'] ?? getenv('DEEPSEEK_API_KEY') ?? '';
$model  = $_ENV['DEEPSEEK_MODEL']   ?? getenv('DEEPSEEK_MODEL')   ?? 'deepseek-v4-flash';

if (empty($apiKey) || $apiKey === 'sk-your-key-here') {
    echo json_encode(['success'=>false,'message'=>'DeepSeek API key not configured in .env.']); exit;
}

try {
    $deepseek = new DeepSeekClient($apiKey, $model);
    $result = $deepseek->run($messages, $tools, $toolExecutor);

    echo json_encode([
        'success'         => true,
        'reply'           => $result['reply'],
        'tool_calls_made' => $result['tool_calls_made'],
    ]);
} catch (Throwable $e) {
    error_log('ai_teacher fatal: ' . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'AI error: ' . $e->getMessage()]);
}
