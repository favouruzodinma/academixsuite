<?php
/**
 * Onboarding Guide — Setup State Manager
 *
 * Detects whether the current admin session is a first-time login and
 * computes a 7-step checklist by inspecting the live school database.
 *
 * Exposed variables (after require_once):
 *   $onboardingActive   bool   — true when onboarding should run
 *   $onboardingSteps    array  — each step: [key, label, done, page, icon, description]
 *   $onboardingPercent  int    — 0-100 completion percentage
 *
 * Helpers:
 *   onboarding_mark_step($schoolDb, $schoolId, $key)   — mark a step complete
 *   onboarding_get_setting($schoolDb, $schoolId, $key) — read a school_settings value
 *   onboarding_set_setting($schoolDb, $schoolId, $key, $value)
 */

if (defined('ACADEMIX_ONBOARDING_LOADED')) return;
define('ACADEMIX_ONBOARDING_LOADED', true);

// ── Helpers ───────────────────────────────────────────────────────────────────

function onboarding_table_exists(PDO $db, string $table): bool {
    try {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return false;
        return (bool) $db->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function onboarding_count(PDO $db, string $table, string $where, array $params = []): int {
    try {
        if (!onboarding_table_exists($db, $table)) return 0;
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function onboarding_get_setting(PDO $db, int $schoolId, string $key): ?string {
    try {
        if (!onboarding_table_exists($db, 'school_settings')) return null;
        $s = $db->prepare("SELECT value FROM school_settings WHERE school_id = ? AND `key` = ? LIMIT 1");
        $s->execute([$schoolId, $key]);
        $row = $s->fetchColumn();
        return $row !== false ? (string)$row : null;
    } catch (Throwable $e) { return null; }
}

function onboarding_set_setting(PDO $db, int $schoolId, string $key, string $value): void {
    try {
        if (!onboarding_table_exists($db, 'school_settings')) {
            $db->exec("CREATE TABLE IF NOT EXISTS school_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                school_id INT NOT NULL,
                `key` VARCHAR(120) NOT NULL,
                `value` TEXT,
                updated_at DATETIME DEFAULT NOW(),
                UNIQUE KEY uniq_school_key (school_id, `key`)
            )");
        }
        $db->prepare("INSERT INTO school_settings (school_id, `key`, `value`, updated_at)
                      VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()")
           ->execute([$schoolId, $key, $value]);
    } catch (Throwable $e) {
        error_log("onboarding_set_setting error: " . $e->getMessage());
    }
}

function onboarding_mark_step(PDO $db, int $schoolId, string $stepKey): void {
    $done = json_decode(onboarding_get_setting($db, $schoolId, 'onboarding_steps_done') ?? '[]', true) ?: [];
    if (!in_array($stepKey, $done, true)) {
        $done[] = $stepKey;
        onboarding_set_setting($db, $schoolId, 'onboarding_steps_done', json_encode($done));
    }
}

// ── Step definitions ──────────────────────────────────────────────────────────

/**
 * Returns the full 7-step checklist with live completion data.
 *
 * @param PDO   $db       School database connection
 * @param int   $schoolId School ID
 * @param array $school   School row from platform DB (for logo_path, etc.)
 */
function onboarding_build_steps(PDO $db, int $schoolId, array $school): array
{
    // Steps already manually marked complete
    $manuallyDone = json_decode(
        onboarding_get_setting($db, $schoolId, 'onboarding_steps_done') ?? '[]', true
    ) ?: [];

    $steps = [
        [
            'key'         => 'school_profile',
            'label'       => 'Complete School Profile',
            'icon'        => 'ri-building-2-line',
            'description' => 'Add your school logo, contact information, address, and branding colours.',
            'page'        => 'general.php',
            'auto_check'  => fn() => !empty($school['logo_path']) && !empty($school['address']),
        ],
        [
            'key'         => 'academic_year',
            'label'       => 'Set Up Academic Year',
            'icon'        => 'ri-calendar-2-line',
            'description' => 'Create the current academic year so classes, timetables, and results can be linked to it.',
            'page'        => 'general.php',
            'auto_check'  => fn() => onboarding_count($db, 'academic_years', 'school_id = ?', [$schoolId]) > 0,
        ],
        [
            'key'         => 'classes',
            'label'       => 'Create Classes & Sections',
            'icon'        => 'ri-school-line',
            'description' => 'Set up the grade levels and sections students will be assigned to.',
            'page'        => 'class-list.php',
            'auto_check'  => fn() => onboarding_count($db, 'classes', 'school_id = ?', [$schoolId]) > 0,
        ],
        [
            'key'         => 'subjects',
            'label'       => 'Add Subjects',
            'icon'        => 'ri-book-open-line',
            'description' => 'Define the subjects taught across your classes and link them to teachers.',
            'page'        => 'subject-list.php',
            'auto_check'  => fn() => onboarding_count($db, 'subjects', 'school_id = ?', [$schoolId]) > 0,
        ],
        [
            'key'         => 'teachers',
            'label'       => 'Add Teachers',
            'icon'        => 'ri-user-star-line',
            'description' => 'Create teacher accounts so they can log in and manage their classes.',
            'page'        => 'add-new-teacher.php',
            'auto_check'  => fn() => onboarding_count($db, 'users', "school_id = ? AND user_type = 'teacher' AND is_active = 1", [$schoolId]) > 0,
        ],
        [
            'key'         => 'students',
            'label'       => 'Enrol Students',
            'icon'        => 'ri-graduation-cap-line',
            'description' => 'Add your students manually or import them in bulk via CSV.',
            'page'        => 'add-new-student.php',
            'auto_check'  => fn() => onboarding_count($db, 'students', 'school_id = ?', [$schoolId]) > 0,
        ],
        [
            'key'         => 'fee_structure',
            'label'       => 'Set Up Fees',
            'icon'        => 'ri-wallet-3-line',
            'description' => 'Create fee categories and structures so you can generate invoices for students.',
            'page'        => 'transaction.php',
            'auto_check'  => fn() => onboarding_count($db, 'fee_categories', 'school_id = ?', [$schoolId]) > 0
                                  || onboarding_count($db, 'fee_structures',  'school_id = ?', [$schoolId]) > 0,
        ],
    ];

    $result = [];
    foreach ($steps as $step) {
        $autoCheck = $step['auto_check'];
        $done = in_array($step['key'], $manuallyDone, true) || $autoCheck();
        $result[] = [
            'key'         => $step['key'],
            'label'       => $step['label'],
            'icon'        => $step['icon'],
            'description' => $step['description'],
            'page'        => $step['page'],
            'done'        => $done,
        ];
    }
    return $result;
}

// ── Main detection logic ──────────────────────────────────────────────────────

$onboardingActive  = false;
$onboardingSteps   = [];
$onboardingPercent = 0;

if (!empty($schoolDb) && !empty($school)) {
    $obSchoolId = (int) ($school['id'] ?? 0);

    // Already completed? Check the flag.
    $completedFlag = onboarding_get_setting($schoolDb, $obSchoolId, 'onboarding_completed');
    if ($completedFlag === '1') {
        // Onboarding done — nothing to do
    } else {
        // Build the checklist
        $onboardingSteps   = onboarding_build_steps($schoolDb, $obSchoolId, $school);
        $doneCount         = count(array_filter($onboardingSteps, fn($s) => $s['done']));
        $totalCount        = count($onboardingSteps);
        $onboardingPercent = $totalCount > 0 ? (int) round(($doneCount / $totalCount) * 100) : 0;

        // Mark as active if any steps remain
        if ($doneCount < $totalCount) {
            $onboardingActive = true;
        } else {
            // All steps done — persist completion flag
            onboarding_set_setting($schoolDb, $obSchoolId, 'onboarding_completed', '1');
        }
    }

    // First-login detection: if the session has no prior last_login_at (set to NULL before this visit)
    // We store a one-time session flag so the bubble auto-opens only on the very first page load.
    if ($onboardingActive && !isset($_SESSION['onboarding_welcomed'])) {
        $_SESSION['onboarding_welcomed'] = true;
        $GLOBALS['onboarding_first_visit'] = true;
    }
}
