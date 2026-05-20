<?php
$school = $GLOBALS['SCHOOL_DATA'] ?? [];
$auth = $GLOBALS['SCHOOL_AUTH'] ?? ($_SESSION['school_auth'] ?? ($_SESSION['school_user'] ?? []));
$portalRole = $portalRole ?? ($GLOBALS['USER_TYPE'] ?? ($auth['user_type'] ?? 'student'));
$portalRole = in_array($portalRole, ['accountant', 'librarian', 'receptionist'], true) ? 'staff' : $portalRole;
$portalPageKey = $portalPageKey ?? basename($GLOBALS['CURRENT_PAGE'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? ($auth['school_slug'] ?? ($school['slug'] ?? ''));
$baseUrl = $GLOBALS['BASE_URL'] ?? (function_exists('school_route_url') ? rtrim(school_route_url($schoolSlug, $portalRole, '', false), '/') . '/' : '');
if ($baseUrl === '') {
    $baseUrl = $schoolSlug ? '/tenant/' . rawurlencode($schoolSlug) . '/' . rawurlencode($portalRole) . '/' : './';
}
$assetUrl = $GLOBALS['ASSETS_URL'] ?? (function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug) ? '/assets/' : '/tenant/assets/');

if (!function_exists('rp_h')) {
    function rp_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rp_role_pages')) {
    function rp_role_pages() {
        return [
            'teacher' => [
                'label' => 'Teacher Portal',
                'avatar' => 'TR',
                'pages' => [
                    'dashboard.php' => ['Dashboard', 'Manage courses, students, and assignments from one place.', 'ri-home-5-line', ['Students', 'Classes', 'Assignments', 'Messages']],
                    'classes.php' => ['Classes', 'Assigned classes, subject allocation, and class rosters.', 'ri-school-line', ['Assigned', 'Students', 'Subjects', 'Notes']],
                    'students.php' => ['Students', 'Student profiles, performance notes, and support flags.', 'ri-graduation-cap-line', ['Students', 'At Risk', 'Notes', 'Parents']],
                    'attendance.php' => ['Attendance', 'Mark and review attendance for assigned classes.', 'ri-calendar-check-line', ['Present', 'Absent', 'Late', 'Pending']],
                    'assignments.php' => ['Assignments', 'Homework, submissions, marking, and feedback.', 'ri-file-list-3-line', ['Open', 'Submitted', 'Late', 'Marked']],
                    'grades.php' => ['Grades', 'Score entry, assessment records, and teacher comments.', 'ri-bar-chart-box-line', ['Assessments', 'Marked', 'Average', 'Published']],
                    'timetable.php' => ['Timetable', 'Teaching schedule, free periods, and substitutions.', 'ri-time-line', ['Today', 'Week Load', 'Free', 'Rooms']],
                    'calendar.php' => ['Calendar', 'Academic events, deadlines, meetings, and exams.', 'ri-calendar-event-line', ['Events', 'Meetings', 'Deadlines', 'Exams']],
                    'announcements.php' => ['Announcements', 'Class notices, school updates, and drafts.', 'ri-megaphone-line', ['Published', 'Drafts', 'Unread', 'Audience']],
                    'messages.php' => ['Messages', 'Parent, student, and administrator conversations.', 'ri-mail-line', ['Inbox', 'Unread', 'Sent', 'Archived']],
                    'profile.php' => ['Profile', 'Personal details, password, and notification settings.', 'ri-user-settings-line', ['Status', 'Security', 'Contact', 'Prefs']]
                ]
            ],
            'student' => [
                'label' => 'Student Portal',
                'avatar' => 'ST',
                'pages' => [
                    'dashboard.php' => ['Dashboard', 'Manage classes, assignments, results, and school updates.', 'ri-home-5-line', ['Events', 'Notifications', 'Attendance', 'Messages']],
                    'timetable.php' => ['Timetable', 'Daily and weekly lesson schedule.', 'ri-time-line', ['Today', 'Week', 'Next', 'Rooms']],
                    'attendance.php' => ['Attendance', 'Attendance history, punctuality, and term summary.', 'ri-calendar-check-line', ['Rate', 'Present', 'Absent', 'Late']],
                    'grades.php' => ['Grades', 'Scores, averages, teacher comments, and trends.', 'ri-line-chart-line', ['Average', 'Subjects', 'Results', 'Comments']],
                    'results.php' => ['Report Cards', 'Published term reports and result history.', 'ri-file-chart-line', ['Published', 'Average', 'Position', 'Remarks']],
                    'assignments.php' => ['Assignments', 'Open work, due dates, submissions, and feedback.', 'ri-task-line', ['Open', 'Submitted', 'Overdue', 'Marked']],
                    'fees.php' => ['Fees', 'Invoices, balances, receipts, and payment status.', 'ri-wallet-3-line', ['Outstanding', 'Paid', 'Invoices', 'Receipts']],
                    'library.php' => ['Library', 'Borrowed books, reservations, due dates, and fines.', 'ri-book-open-line', ['Borrowed', 'Due', 'Reserved', 'Fines']],
                    'announcements.php' => ['Announcements', 'School notices and class updates.', 'ri-megaphone-line', ['Unread', 'School', 'Class', 'Archive']],
                    'calendar.php' => ['Calendar', 'Events, assignment deadlines, and exam dates.', 'ri-calendar-event-line', ['Events', 'Due', 'Exams', 'Activities']],
                    'messages.php' => ['Messages', 'Teacher and school messages.', 'ri-mail-line', ['Inbox', 'Unread', 'Sent', 'Notices']],
                    'profile.php' => ['Profile', 'Student details, guardian contacts, and security.', 'ri-user-settings-line', ['Status', 'Class', 'Admission', 'Security']]
                ]
            ],
            'parent' => [
                'label' => 'Parent Portal',
                'avatar' => 'PA',
                'pages' => [
                    'dashboard.php' => ['Dashboard', 'Track student progress, attendance, fees, and notices.', 'ri-home-5-line', ['Children', 'Attendance', 'Fees', 'Notices']],
                    'children.php' => ['My Children', 'Linked students, class placement, and academic status.', 'ri-team-line', ['Children', 'Classes', 'Teachers', 'Notes']],
                    'attendance.php' => ['Attendance', 'Attendance and punctuality across linked children.', 'ri-calendar-check-line', ['Rate', 'Present', 'Absent', 'Late']],
                    'grades.php' => ['Grades', 'Published scores, subject progress, and teacher feedback.', 'ri-bar-chart-box-line', ['Average', 'Subjects', 'Reports', 'Comments']],
                    'fees.php' => ['Fees', 'Invoices, balances, receipts, and payment reminders.', 'ri-wallet-3-line', ['Outstanding', 'Paid', 'Invoices', 'Receipts']],
                    'schedule.php' => ['Schedule', 'Timetable, exam calendar, meetings, and events.', 'ri-calendar-schedule-line', ['Today', 'Exams', 'Events', 'Meetings']],
                    'announcements.php' => ['Announcements', 'School notices, class updates, and reminders.', 'ri-megaphone-line', ['Unread', 'School', 'Class', 'Reminders']],
                    'messages.php' => ['Messages', 'Teacher and administrator conversations.', 'ri-mail-line', ['Inbox', 'Teachers', 'Admin', 'Unread']],
                    'support.php' => ['Support', 'Requests, tickets, documents, and school contacts.', 'ri-customer-service-2-line', ['Open', 'Resolved', 'Requests', 'Replies']],
                    'profile.php' => ['Profile', 'Parent contacts, emergency details, and security.', 'ri-user-settings-line', ['Status', 'Students', 'Security', 'Alerts']]
                ]
            ],
            'staff' => [
                'label' => 'Staff Portal',
                'avatar' => 'SF',
                'pages' => [
                    'dashboard.php' => ['Dashboard', 'Operations, approvals, attendance, and messages.', 'ri-home-5-line', ['Tasks', 'Approvals', 'Messages', 'Schedule']],
                    'attendance.php' => ['Staff Attendance', 'Clock-in records, shifts, and attendance history.', 'ri-calendar-check-line', ['Present', 'Late', 'Leave', 'Hours']],
                    'payroll.php' => ['Payroll', 'Payslips, allowances, deductions, and payment history.', 'ri-money-dollar-circle-line', ['Payslip', 'Allowances', 'Deductions', 'Net Pay']],
                    'leave.php' => ['Leave Requests', 'Apply for leave and track approvals.', 'ri-flight-takeoff-line', ['Available', 'Pending', 'Approved', 'Rejected']],
                    'library.php' => ['Library Operations', 'Book circulation, reservations, and fines.', 'ri-book-open-line', ['Issued', 'Returns', 'Reserved', 'Fines']],
                    'inventory.php' => ['Inventory', 'Stock, assets, requests, and movement logs.', 'ri-archive-line', ['Items', 'Low Stock', 'Requests', 'Moves']],
                    'fees.php' => ['Fee Operations', 'Collections, receipts, invoices, and follow-up.', 'ri-wallet-3-line', ['Collected', 'Pending', 'Receipts', 'Follow-up']],
                    'messages.php' => ['Messages', 'Internal messages and assigned conversations.', 'ri-mail-line', ['Inbox', 'Unread', 'Assigned', 'Sent']],
                    'reports.php' => ['Reports', 'Operational summaries, exports, and scheduled reports.', 'ri-file-chart-line', ['Generated', 'Scheduled', 'Pending', 'Exports']],
                    'work.php' => ['Work Queue', 'Assigned work, daily tasks, and follow-ups.', 'ri-list-check-3', ['Open', 'Due Today', 'Done', 'Escalated']],
                    'calendar.php' => ['Calendar', 'Shifts, deadlines, meetings, and events.', 'ri-calendar-event-line', ['Shifts', 'Events', 'Deadlines', 'Meetings']],
                    'profile.php' => ['Profile', 'Staff details, department, role, and security.', 'ri-user-settings-line', ['Status', 'Role', 'Dept.', 'Security']]
                ]
            ]
        ];
    }
}

if (!function_exists('rp_initials')) {
    function rp_initials($name, $fallback = 'US') {
        $name = trim((string) $name);
        if ($name === '') {
            return $fallback;
        }
        $parts = preg_split('/\s+/', $name);
        $initials = '';
        foreach ($parts as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
            if (strlen($initials) >= 2) {
                break;
            }
        }
        return $initials ?: $fallback;
    }
}

if (!function_exists('rp_school_db')) {
    function rp_school_db($school, $auth) {
        if (!class_exists('Database')) {
            return null;
        }
        $databaseName = $school['database_name'] ?? ($auth['school_db'] ?? ($auth['database_name'] ?? ''));
        if (!$databaseName) {
            return null;
        }
        try {
            return Database::getSchoolConnection($databaseName);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('rp_table_count')) {
    function rp_table_count($db, $table) {
        if (!$db || !preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
            return 0;
        }
        try {
            $stmt = $db->query('SELECT COUNT(*) FROM `' . $table . '`');
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('rp_first_positive')) {
    function rp_first_positive(array $values) {
        foreach ($values as $value) {
            if ((int) $value > 0) {
                return (int) $value;
            }
        }
        return 0;
    }
}

$allRoles = rp_role_pages();
$roleConfig = $allRoles[$portalRole] ?? $allRoles['student'];
$pages = $roleConfig['pages'];
$pageConfig = $pages[$portalPageKey] ?? $pages['dashboard.php'];
$schoolName = $school['name'] ?? ($auth['school_name'] ?? 'School Portal');
$schoolLogo = function_exists('school_logo_url')
    ? school_logo_url($school)
    : (!empty($school['logo_path']) ? '/' . ltrim((string) $school['logo_path'], '/') : ($assetUrl . 'images/logo.png'));
if (trim((string) $schoolLogo) === '') {
    $schoolLogo = $assetUrl . 'images/logo.png';
}
$userName = $auth['user_name'] ?? ($auth['name'] ?? ($auth['full_name'] ?? 'Portal User'));
$userEmail = $auth['user_email'] ?? ($auth['email'] ?? '');
$roleLabel = $roleConfig['label'];
$roleInitials = rp_initials($userName, $roleConfig['avatar']);
$logoutUrl = function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug)
    ? '/logout.php'
    : '/tenant/logout.php' . ($schoolSlug ? '?school_slug=' . urlencode($schoolSlug) : '');
$schoolDb = rp_school_db($school, $auth);

$studentCount = rp_table_count($schoolDb, 'students');
$teacherCount = rp_table_count($schoolDb, 'teachers');
$guardianCount = rp_first_positive([rp_table_count($schoolDb, 'guardians'), rp_table_count($schoolDb, 'parents')]);
$classCount = rp_table_count($schoolDb, 'classes');
$assignmentCount = rp_table_count($schoolDb, 'assignments');
$messageCount = rp_first_positive([rp_table_count($schoolDb, 'messages'), rp_table_count($schoolDb, 'school_messages')]);
$noticeCount = rp_first_positive([rp_table_count($schoolDb, 'notices'), rp_table_count($schoolDb, 'announcements'), rp_table_count($schoolDb, 'notice_board')]);
$eventCount = rp_first_positive([rp_table_count($schoolDb, 'events'), rp_table_count($schoolDb, 'school_events')]);
$invoiceCount = rp_first_positive([rp_table_count($schoolDb, 'invoices'), rp_table_count($schoolDb, 'fees_invoices')]);

$profileClass = $auth['class_name'] ?? ($auth['class'] ?? ($auth['assigned_class'] ?? 'Class not assigned'));
$profileSubject = $auth['subject_name'] ?? ($auth['subject'] ?? ($auth['department'] ?? 'Academics'));
$profileRoll = $auth['roll_number'] ?? ($auth['admission_number'] ?? 'Active');

// ── Teacher-specific dashboard stats ─────────────────────────────────────────
if ($portalRole === 'teacher' && $schoolDb) {
    $teacherId = (int)($auth['user_id'] ?? 0);
    $schoolId  = (int)($school['id'] ?? 0);

    // Helper: run a COUNT query safely, return 0 on failure
    $rp_count = function(string $sql, array $params) use ($schoolDb): int {
        try {
            $s = $schoolDb->prepare($sql);
            $s->execute($params);
            return (int) $s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    if ($teacherId && $schoolId) {
        // Active classes assigned to this teacher (as class teacher OR subject teacher)
        $teacherClassCount = $rp_count(
            "SELECT COUNT(DISTINCT class_id) FROM (
                SELECT id AS class_id FROM classes
                WHERE school_id = ? AND class_teacher_id = ? AND is_active = 1
                UNION
                SELECT cs.class_id FROM class_subjects cs
                JOIN classes c ON c.id = cs.class_id AND c.school_id = ?
                WHERE cs.teacher_id = ? AND c.is_active = 1
            ) t",
            [$schoolId, $teacherId, $schoolId, $teacherId]
        );

        // Students in teacher's classes
        $teacherStudentCount = $rp_count(
            "SELECT COUNT(DISTINCT s.id) FROM students s
             JOIN classes c ON c.id = s.class_id
             WHERE c.school_id = ? AND s.status = 'active'
               AND (c.class_teacher_id = ?
                    OR EXISTS (SELECT 1 FROM class_subjects cs WHERE cs.class_id = c.id AND cs.teacher_id = ?))",
            [$schoolId, $teacherId, $teacherId]
        );

        // Assignments created by this teacher
        $teacherAssignmentCount = 0;
        foreach (['created_by', 'teacher_id'] as $col) {
            try {
                $teacherAssignmentCount = $rp_count(
                    "SELECT COUNT(*) FROM assignments WHERE school_id = ? AND {$col} = ?",
                    [$schoolId, $teacherId]
                );
                if ($teacherAssignmentCount > 0) break;
                // verify column exists by running a test query
                $schoolDb->query("SELECT `{$col}` FROM assignments LIMIT 0");
                break;
            } catch (Throwable $e) { continue; }
        }

        // Messages involving this teacher
        $teacherMessageCount = 0;
        foreach (['messages', 'school_messages'] as $tbl) {
            $c = $rp_count(
                "SELECT COUNT(*) FROM `{$tbl}` WHERE school_id = ? AND (sender_id = ? OR receiver_id = ?)",
                [$schoolId, $teacherId, $teacherId]
            );
            $teacherMessageCount += $c;
        }
        if ($teacherMessageCount === 0) {
            $teacherMessageCount = $messageCount; // graceful fallback
        }

        // Teacher's primary class name & subject
        try {
            $stmt = $schoolDb->prepare(
                "SELECT c.name FROM classes c
                 WHERE c.school_id = ? AND c.class_teacher_id = ? AND c.is_active = 1
                 ORDER BY c.name LIMIT 1"
            );
            $stmt->execute([$schoolId, $teacherId]);
            $row = $stmt->fetchColumn();
            if ($row) $profileClass = $row;
        } catch (Throwable $e) {}

        if ($profileClass === 'Class not assigned') {
            // Try through class_subjects
            try {
                $stmt = $schoolDb->prepare(
                    "SELECT c.name FROM class_subjects cs
                     JOIN classes c ON c.id = cs.class_id AND c.school_id = ?
                     WHERE cs.teacher_id = ? AND c.is_active = 1
                     ORDER BY c.name LIMIT 1"
                );
                $stmt->execute([$schoolId, $teacherId]);
                $row = $stmt->fetchColumn();
                if ($row) $profileClass = $row;
            } catch (Throwable $e) {}
        }

        try {
            $stmt = $schoolDb->prepare(
                "SELECT sub.name FROM class_subjects cs
                 JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE cs.teacher_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$teacherId]);
            $row = $stmt->fetchColumn();
            if ($row) $profileSubject = $row;
        } catch (Throwable $e) {}

        // Real attendance data for teacher's classes
        try {
            $stmt = $schoolDb->prepare(
                "SELECT
                    SUM(CASE WHEN a.status = 'present'  THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN a.status = 'absent'   THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN a.status = 'late'     THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN a.status IN ('half_day','halfday') THEN 1 ELSE 0 END) AS half_day
                 FROM attendance a
                 JOIN classes c ON c.id = a.class_id
                 WHERE a.school_id = ?
                   AND (c.class_teacher_id = ?
                        OR EXISTS (SELECT 1 FROM class_subjects cs WHERE cs.class_id = c.id AND cs.teacher_id = ?))"
            );
            $stmt->execute([$schoolId, $teacherId, $teacherId]);
            $att = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($att && ((int)$att['present'] + (int)$att['absent'] + (int)$att['late'] + (int)$att['half_day']) > 0) {
                $attendanceRows = [
                    ['Present',  (int)$att['present'],  'green'],
                    ['Half Day', (int)$att['half_day'], 'orange'],
                    ['Late',     (int)$att['late'],     'teal'],
                    ['Absent',   (int)$att['absent'],   'purple'],
                ];
            }
        } catch (Throwable $e) {}

        // Override tiles with teacher-specific values
        $studentCount    = $teacherStudentCount;
        $classCount      = $teacherClassCount;
        $assignmentCount = $teacherAssignmentCount;
        $messageCount    = $teacherMessageCount;
    }
}

$teacherTiles = [
    ['Total Students', number_format($studentCount), 'ri-group-line', 'purple'],
    ['Active Classes', number_format($classCount), 'ri-school-line', 'green'],
    ['Assignments', number_format($assignmentCount), 'ri-task-line', 'blue'],
    ['Messages', number_format($messageCount), 'ri-mail-line', 'rose']
];
$studentTiles = [
    ['Events', number_format($eventCount), 'ri-calendar-event-line', 'purple'],
    ['Notifications', number_format($noticeCount), 'ri-notification-3-line', 'green'],
    ['Attendance', '90%', 'ri-calendar-check-line', 'blue']
];
$parentTiles = [
    ['Due Fees', $invoiceCount > 0 ? number_format($invoiceCount) . ' invoice(s)' : 'NGN 0', 'ri-wallet-3-line', 'orange'],
    ['Children', number_format(max(1, $studentCount ? min($studentCount, 4) : 1)), 'ri-team-line', 'blue'],
    ['Messages', number_format($messageCount), 'ri-mail-line', 'purple'],
    ['Notices', number_format($noticeCount), 'ri-megaphone-line', 'green']
];
$attendanceRows = [
    ['Present', 200, 'green'],
    ['Half Day', 300, 'orange'],
    ['Late', 172, 'teal'],
    ['Absent', 500, 'purple']
];
$noticeRows = [
    ['Admin', 'Term updates and classroom reminders are available for review.', '25 Jan 2026'],
    ['Kathryn Murphy', 'Please check the latest timetable and assignment updates.', '27 Jan 2026'],
    ['Academic Office', 'Assessment schedules and report timelines have been published.', '31 Jan 2026']
];
$eventRows = [
    ['09:00 - 09:45 AM', 'Morning Lesson Review', 'Lead by Academic Office'],
    ['11:15 - 12:00 PM', 'Student Progress Check', 'Lead by Class Teacher'],
    ['01:00 - 01:40 PM', 'Parent Communication Window', 'Lead by Admin']
];
$classRows = [
    ['English', '09:30 - 09:45 AM', 'Completed'],
    ['Physics', '09:50 - 10:35 AM', 'In progress'],
    ['Chemistry', '11:00 - 11:45 AM', 'In progress'],
    ['Accounting', '12:00 - 12:45 PM', 'Pending']
];
$examRows = [
    ['AD52365', 'Class Test', 'English', 'A', '95%', '4.2', 'Pass'],
    ['AD52365', 'First Semester', 'Chemistry', 'A', '80%', '3.2', 'Pass'],
    ['AD52365', 'Class Test', 'Mathematics', 'B', '70%', '3.8', 'Pass'],
    ['AD52365', 'Class Test', 'Accounting', 'C', '60%', '3.1', 'Pass'],
    ['AD52365', 'Mock Exam', 'English', 'F', '30%', '2.5', 'Fail']
];
$calendarMonth = date('M Y');
$calendarFirstDay = (int) date('w', strtotime(date('Y-m-01')));
$calendarDaysInMonth = (int) date('t');
$metricValues = ['Rate' => '94%', 'Average' => '81%', 'Outstanding' => 'NGN 0', 'Net Pay' => 'Ready', 'Security' => 'Good', 'Status' => 'Complete', 'Admission' => 'Active', 'Dept.' => 'Office'];
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo rp_h($pageConfig[0]); ?> | <?php echo rp_h($schoolName); ?></title>
    <link rel="icon" type="image/png" href="<?php echo rp_h($schoolLogo); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/remixicon.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/bootstrap.min.css">
    <style>
        :root {
            --portal-bg: #edf1f6;
            --portal-surface: #ffffff;
            --portal-border: #e5e9f0;
            --portal-text: #1f2933;
            --portal-muted: #6b7280;
            --portal-primary: #25a194;
            --portal-primary-soft: #e6f6f3;
            --portal-purple: #7455df;
            --portal-blue: #4b6fff;
            --portal-orange: #f47c3c;
            --portal-green: #47bf49;
            --portal-rose: #ef6f6c;
            --portal-shadow: 0 16px 40px rgba(26, 39, 61, .06);
        }
        * { box-sizing: border-box; }
        body.edudash-page {
            margin: 0;
            min-height: 100vh;
            background: var(--portal-bg);
            color: var(--portal-text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; }
        .portal-shell { min-height: 100vh; }
        .portal-sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 280px;
            background: var(--portal-surface);
            border-right: 1px solid var(--portal-border);
            z-index: 30;
            display: flex;
            flex-direction: column;
            transition: transform .2s ease;
        }
        .portal-brand {
            height: 84px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--portal-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .portal-brand-link { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .portal-brand-logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #edf0f4;
            padding: 4px;
            flex: 0 0 auto;
        }
        .portal-brand-name {
            font-size: 18px;
            line-height: 1.2;
            font-weight: 800;
            color: #20242a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        .portal-icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 0;
            background: #f3f5f8;
            color: #66717e;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
        }
        .portal-profile-card {
            margin: 18px;
            padding: 14px;
            border-radius: 8px;
            background: #f0f3f7;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .portal-avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fed7aa, #fbbf24);
            color: #7c2d12;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex: 0 0 auto;
        }
        .portal-profile-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .portal-profile-name, .portal-profile-role {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .portal-profile-name { color: #1f2937; font-weight: 700; line-height: 1.25; }
        .portal-profile-role { color: var(--portal-muted); font-size: 13px; margin-top: 3px; }
        .portal-menu {
            padding: 12px 18px 24px;
            overflow-y: auto;
            flex: 1;
        }
        .portal-menu::-webkit-scrollbar { width: 4px; }
        .portal-menu::-webkit-scrollbar-thumb { background: #d1d8e2; border-radius: 999px; }
        .portal-menu-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 4px; }
        .portal-menu-link {
            min-height: 46px;
            padding: 11px 12px;
            border-radius: 8px;
            color: #64707d;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            transition: background .16s ease, color .16s ease;
            font-weight: 600;
        }
        .portal-menu-link > span { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .portal-menu-link i:first-child { font-size: 21px; color: #7a8693; flex: 0 0 auto; }
        .portal-menu-link strong {
            font-size: 15px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .portal-menu-link:hover, .portal-menu-link.active {
            background: #edf1f5;
            color: #1f2937;
        }
        .portal-menu-link.active i:first-child { color: var(--portal-primary); }
        .portal-main {
            min-height: 100vh;
            margin-left: 280px;
            display: flex;
            flex-direction: column;
        }
        .portal-topbar {
            height: 84px;
            background: var(--portal-surface);
            border-bottom: 1px solid var(--portal-border);
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .portal-search {
            width: min(100%, 460px);
            height: 48px;
            border: 1px solid #dce2ea;
            border-radius: 8px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 16px;
        }
        .portal-search i { color: #677281; font-size: 20px; }
        .portal-search input {
            border: 0;
            outline: 0;
            width: 100%;
            color: #1f2937;
            background: transparent;
        }
        .portal-top-actions { display: flex; align-items: center; gap: 12px; margin-left: auto; }
        .portal-flag {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(#eff6ff 0 28%, #dc2626 28% 44%, #fff 44% 56%, #dc2626 56% 72%, #eff6ff 72%);
            border: 4px solid #f2f4f7;
        }
        .portal-notify { position: relative; }
        .portal-notify::after {
            content: "";
            position: absolute;
            top: 6px;
            right: 6px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #df3030;
        }
        .portal-content {
            padding: 24px 28px 34px;
            flex: 1;
        }
        .portal-page-title { margin-bottom: 24px; }
        .portal-page-title h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 800;
            color: #20242a;
        }
        .portal-page-title p {
            margin: 8px 0 0;
            color: #536173;
            font-size: 16px;
        }
        .portal-grid { display: grid; gap: 24px; }
        .portal-grid.teacher {
            grid-template-columns: minmax(0, 1.42fr) minmax(320px, .98fr);
        }
        .portal-grid.student {
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.1fr) minmax(310px, .92fr);
        }
        .portal-grid.parent {
            grid-template-columns: minmax(0, 2fr) minmax(340px, .8fr);
        }
        .portal-card {
            background: var(--portal-surface);
            border: 1px solid var(--portal-border);
            border-radius: 8px;
            box-shadow: var(--portal-shadow);
            overflow: hidden;
        }
        .portal-card-header {
            min-height: 58px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--portal-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .portal-card-header h2 {
            margin: 0;
            font-size: 20px;
            line-height: 1.2;
            color: #20242a;
            font-weight: 800;
        }
        .portal-card-body { padding: 18px; }
        .portal-select {
            border: 1px solid #dbe2eb;
            background: #fff;
            color: #566272;
            border-radius: 8px;
            padding: 9px 34px 9px 12px;
            min-height: 42px;
        }
        .profile-hero {
            min-height: 270px;
            border-radius: 8px;
            color: #fff;
            background:
                radial-gradient(circle at -10% 90%, rgba(255,255,255,.18), transparent 28%),
                radial-gradient(circle at 110% 8%, rgba(255,255,255,.16), transparent 34%),
                linear-gradient(135deg, #6948d9, #8b65e6);
            padding: 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 12px;
            box-shadow: var(--portal-shadow);
        }
        .profile-hero .portal-avatar {
            width: 96px;
            height: 96px;
            border: 8px solid rgba(255,255,255,.92);
            background: #fff;
            color: #4b35a8;
            font-size: 28px;
        }
        .profile-hero h2 {
            margin: 8px 0 0;
            font-size: 26px;
            line-height: 1.15;
            color: #fff;
            font-weight: 800;
        }
        .profile-hero p { margin: 0; color: rgba(255,255,255,.92); font-weight: 600; }
        .portal-btn {
            min-height: 44px;
            padding: 10px 18px;
            border-radius: 8px;
            border: 0;
            background: rgba(255,255,255,.14);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 700;
        }
        .portal-btn.primary { background: var(--portal-primary); color: #fff; }
        .metric-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .student-profile-grid { display: grid; grid-template-columns: minmax(240px, .88fr) minmax(240px, 1fr); gap: 16px; }
        .metric-card {
            min-height: 142px;
            border-radius: 8px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 12px;
            border: 1px solid transparent;
        }
        .metric-card.orange { background: #fff5ed; }
        .metric-card.blue { background: #eef3ff; }
        .metric-card.purple { background: #f3e5fa; }
        .metric-card.green { background: #e9f9ee; }
        .metric-card.rose { background: #fde8e8; }
        .metric-card.teal { background: #e7f4f3; }
        .metric-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            background: rgba(255,255,255,.55);
        }
        .metric-card.orange .metric-icon { color: var(--portal-orange); }
        .metric-card.blue .metric-icon { color: var(--portal-blue); }
        .metric-card.purple .metric-icon { color: var(--portal-purple); }
        .metric-card.green .metric-icon { color: var(--portal-green); }
        .metric-card.rose .metric-icon { color: var(--portal-rose); }
        .metric-card.teal .metric-icon { color: var(--portal-primary); }
        .metric-card span { color: #687486; font-weight: 700; }
        .metric-card strong { color: #1f2933; font-size: 30px; line-height: 1; font-weight: 900; }
        .horizontal-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .attendance-list { display: grid; gap: 20px; padding: 24px 34px; }
        .attendance-item { display: flex; align-items: center; gap: 16px; }
        .attendance-dot {
            width: 18px;
            height: 18px;
            border: 6px solid var(--portal-primary);
            border-radius: 50%;
            flex: 0 0 auto;
        }
        .attendance-dot.orange { border-color: var(--portal-orange); }
        .attendance-dot.green { border-color: #3f9c63; }
        .attendance-dot.purple { border-color: var(--portal-purple); }
        .attendance-dot.teal { border-color: var(--portal-primary); }
        .attendance-item strong { display: block; font-size: 28px; line-height: 1; }
        .attendance-item span { color: var(--portal-muted); font-weight: 600; }
        .list-panel { display: grid; gap: 16px; max-height: 320px; overflow-y: auto; padding-right: 4px; }
        .list-panel::-webkit-scrollbar { width: 4px; }
        .list-panel::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .notice-row, .event-row, .class-row, .kid-row {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 1px solid #eef1f5;
        }
        .notice-row:last-child, .event-row:last-child, .class-row:last-child, .kid-row:last-child { border-bottom: 0; padding-bottom: 0; }
        .small-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e8efff;
            color: #4b6fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex: 0 0 auto;
        }
        .list-title { margin: 0 0 4px; font-weight: 800; color: #232a33; }
        .list-text { margin: 0; color: #6b7280; line-height: 1.55; }
        .list-date { margin-top: 8px; color: #8a94a3; font-size: 13px; }
        .event-row { align-items: center; }
        .event-time {
            min-width: 128px;
            padding-left: 12px;
            border-left: 4px solid var(--portal-purple);
            font-weight: 800;
            color: #2d3340;
        }
        .event-row:nth-child(2) .event-time { border-color: var(--portal-orange); }
        .event-row:nth-child(3) .event-time { border-color: var(--portal-primary); }
        .view-btn {
            margin-left: auto;
            border: 0;
            border-radius: 8px;
            min-width: 72px;
            min-height: 42px;
            background: #f1f3f6;
            color: #6b7280;
            font-weight: 800;
        }
        .class-row { align-items: center; }
        .class-row-main { flex: 1; min-width: 0; }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 86px;
            padding: 7px 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #2fa94f;
            background: #e2fae9;
            font-weight: 800;
        }
        .status-pill.warn { color: #f47c3c; background: #fff0e8; }
        .status-pill.fail { color: #df3030; background: #fde8e8; }
        .calendar-strip {
            height: 40px;
            border-radius: 999px;
            background: var(--portal-primary-soft);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            margin-bottom: 22px;
            font-weight: 800;
            color: #6b7280;
        }
        .calendar-arrow {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 0;
            background: #fff;
            color: var(--portal-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            text-align: center;
            color: #2b3340;
            font-weight: 700;
        }
        .calendar-grid span {
            min-height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .calendar-grid .muted { color: #9aa4b2; }
        .calendar-grid .today { color: #fff; background: var(--portal-primary); }
        .table-card { overflow-x: auto; }
        .portal-table { width: 100%; border-collapse: collapse; min-width: 720px; }
        .portal-table th, .portal-table td {
            padding: 15px 18px;
            border-bottom: 1px solid #eef1f5;
            color: #5f6978;
            vertical-align: middle;
        }
        .portal-table th {
            background: #edf1f5;
            color: #26303b;
            font-weight: 800;
        }
        .portal-table td:first-child { color: var(--portal-primary); font-weight: 800; }
        .workspace-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(310px, .34fr);
            gap: 24px;
        }
        .workspace-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .workspace-actions { display: grid; gap: 12px; }
        .action-link {
            min-height: 46px;
            border: 1px solid #dfe5ed;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 14px;
            color: #475569;
            font-weight: 800;
        }
        .min-w-0 { min-width: 0; }
        .two-column-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .student-metric-stack { gap: 16px; }
        .student-attendance-metrics {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin: 28px 0 0;
        }
        .student-attendance-metrics .attendance-item { align-items: flex-start; }
        .student-attendance-metrics .attendance-dot { margin-top: 7px; }
        .student-bottom-grid {
            grid-template-columns: minmax(0, 2fr) minmax(320px, .8fr);
            margin-top: 24px;
        }
        .parent-main-pair { grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); }
        .stat-summary {
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            color: #2d3340;
            font-weight: 800;
            flex-wrap: wrap;
        }
        .stat-summary .orange { color: var(--portal-orange); }
        .stat-summary .teal { color: var(--portal-primary); }
        .metric-trend { color: #3f9c63; font-weight: 800; }
        .kids-list { max-height: none; }
        .kid-avatar {
            width: 76px;
            height: 76px;
            background: #e5f7dc;
            color: #2f6f3d;
            font-size: 22px;
        }
        .text-primary-strong { color: var(--portal-primary); font-weight: 800; }
        .workspace-view-link { color: var(--portal-primary); font-weight: 800; }
        .mobile-menu-btn { display: none; }
        .portal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            z-index: 25;
            opacity: 0;
            visibility: hidden;
            transition: opacity .2s ease;
        }
        .portal-overlay.show { opacity: 1; visibility: visible; }
        @media (max-width: 1400px) {
            .portal-grid.teacher, .portal-grid.student, .portal-grid.parent, .workspace-layout { grid-template-columns: 1fr; }
            .horizontal-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 991px) {
            .portal-sidebar { transform: translateX(-100%); }
            .portal-sidebar.open { transform: translateX(0); }
            .portal-main { margin-left: 0; }
            .mobile-menu-btn { display: inline-flex; }
            .portal-topbar { padding: 0 18px; }
            .portal-content { padding: 22px 18px 30px; }
            .portal-search { max-width: 100%; }
            .portal-brand-name { max-width: 180px; }
        }
        @media (max-width: 720px) {
            .portal-topbar { height: auto; min-height: 84px; flex-wrap: wrap; padding-block: 14px; }
            .portal-top-actions { width: 100%; justify-content: flex-end; }
            .student-profile-grid, .metric-grid, .horizontal-metrics, .workspace-stats { grid-template-columns: 1fr; }
            .portal-page-title h1 { font-size: 24px; }
            .portal-page-title p { font-size: 14px; }
            .metric-card { min-height: 126px; }
            .event-row, .class-row { align-items: flex-start; flex-direction: column; }
            .view-btn { margin-left: 0; width: 100%; }
            .two-column-grid, .student-bottom-grid, .parent-main-pair, .student-attendance-metrics { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="edudash-page">
<div class="portal-overlay" data-portal-overlay></div>
<div class="portal-shell">
    <aside class="portal-sidebar" data-portal-sidebar>
        <div class="portal-brand">
            <a class="portal-brand-link" href="<?php echo rp_h($baseUrl . 'dashboard.php'); ?>">
                <img class="portal-brand-logo" src="<?php echo rp_h($schoolLogo); ?>" alt="<?php echo rp_h($schoolName); ?> logo">
                <span class="portal-brand-name"><?php echo rp_h($schoolName); ?></span>
            </a>
            <button type="button" class="portal-icon-btn" data-sidebar-close aria-label="Close menu">
                <i class="ri-contract-left-line"></i>
            </button>
        </div>

        <div class="portal-profile-card">
            <div class="portal-profile-main">
                <span class="portal-avatar"><?php echo rp_h($roleInitials); ?></span>
                <span class="min-w-0">
                    <span class="portal-profile-name"><?php echo rp_h($userName); ?></span>
                    <span class="portal-profile-role"><?php echo rp_h($roleLabel); ?></span>
                </span>
            </div>
            <i class="ri-arrow-right-s-line" aria-hidden="true"></i>
        </div>

        <nav class="portal-menu" aria-label="<?php echo rp_h($roleLabel); ?> navigation">
            <ul class="portal-menu-list">
                <?php foreach ($pages as $file => $item): ?>
                    <li>
                        <a class="portal-menu-link <?php echo $file === $portalPageKey ? 'active' : ''; ?>" href="<?php echo rp_h($baseUrl . $file); ?>">
                            <span>
                                <i class="<?php echo rp_h($item[2]); ?>"></i>
                                <strong><?php echo rp_h($item[0]); ?></strong>
                            </span>
                            <i class="ri-arrow-right-s-line"></i>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li>
                    <a class="portal-menu-link" href="<?php echo rp_h($logoutUrl); ?>">
                        <span>
                            <i class="ri-shut-down-line"></i>
                            <strong>Logout</strong>
                        </span>
                        <i class="ri-arrow-right-s-line"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <main class="portal-main">
        <header class="portal-topbar">
            <button type="button" class="portal-icon-btn mobile-menu-btn" data-mobile-menu aria-label="Open menu">
                <i class="ri-menu-line"></i>
            </button>
            <form class="portal-search" action="javascript:void(0)">
                <i class="ri-search-line"></i>
                <input type="search" placeholder="Search <?php echo rp_h(strtolower($pageConfig[0])); ?>">
            </form>
            <div class="portal-top-actions">
                <button type="button" class="portal-icon-btn" aria-label="Display settings"><i class="ri-sun-line"></i></button>
                <span class="portal-flag" aria-hidden="true"></span>
                <button type="button" class="portal-icon-btn portal-notify" aria-label="Notifications"><i class="ri-notification-3-line"></i></button>
            </div>
        </header>

        <section class="portal-content">
            <div class="portal-page-title">
                <h1><?php echo rp_h($pageConfig[0]); ?></h1>
                <p><?php echo rp_h($roleConfig['label']); ?> -> <?php echo rp_h($pageConfig[1]); ?></p>
            </div>

            <?php if ($portalPageKey === 'dashboard.php' && $portalRole === 'teacher'): ?>
                <div class="portal-grid teacher">
                    <div class="portal-grid">
                        <div class="metric-grid">
                            <article class="profile-hero">
                                <span class="portal-avatar"><?php echo rp_h($roleInitials); ?></span>
                                <h2><?php echo rp_h($userName); ?></h2>
                                <p>Class: <?php echo rp_h($profileClass); ?></p>
                                <p><?php echo rp_h($profileSubject); ?></p>
                                <a class="portal-btn" href="<?php echo rp_h($baseUrl . 'profile.php'); ?>"><i class="ri-user-settings-line"></i> Edit Profile</a>
                            </article>
                            <div class="metric-grid">
                                <?php foreach ($teacherTiles as $tile): ?>
                                    <article class="metric-card <?php echo rp_h($tile[3]); ?>">
                                        <span class="metric-icon"><i class="<?php echo rp_h($tile[2]); ?>"></i></span>
                                        <span><?php echo rp_h($tile[0]); ?></span>
                                        <strong><?php echo rp_h($tile[1]); ?></strong>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="portal-grid two-column-grid">
                            <article class="portal-card">
                                <div class="portal-card-header"><h2>Notice Board</h2></div>
                                <div class="portal-card-body">
                                    <div class="list-panel">
                                        <?php foreach ($noticeRows as $row): ?>
                                            <div class="notice-row">
                                                <span class="small-avatar"><?php echo rp_h(rp_initials($row[0])); ?></span>
                                                <div>
                                                    <p class="list-title"><?php echo rp_h($row[0]); ?></p>
                                                    <p class="list-text"><?php echo rp_h($row[1]); ?></p>
                                                    <div class="list-date"><?php echo rp_h($row[2]); ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </article>
                            <article class="portal-card">
                                <div class="portal-card-header"><h2>Upcoming Events</h2></div>
                                <div class="portal-card-body">
                                    <div class="list-panel">
                                        <?php foreach ($eventRows as $row): ?>
                                            <div class="event-row">
                                                <div class="event-time"><?php echo rp_h($row[0]); ?></div>
                                                <div>
                                                    <p class="list-title"><?php echo rp_h($row[1]); ?></p>
                                                    <p class="list-text"><?php echo rp_h($row[2]); ?></p>
                                                </div>
                                                <button class="view-btn" type="button">View</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </div>

                    <div class="portal-grid">
                        <article class="portal-card">
                            <div class="portal-card-header">
                                <h2>Attendance</h2>
                                <select class="portal-select" aria-label="Attendance period"><option>Yearly</option><option>Monthly</option></select>
                            </div>
                            <div class="attendance-list">
                                <?php foreach ($attendanceRows as $row): ?>
                                    <div class="attendance-item">
                                        <span class="attendance-dot <?php echo rp_h($row[2]); ?>"></span>
                                        <div><strong><?php echo rp_h($row[1]); ?></strong><span><?php echo rp_h($row[0]); ?></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                        <article class="portal-card">
                            <div class="portal-card-header"><h2>Calendar</h2></div>
                            <div class="portal-card-body">
                                <div class="calendar-strip">
                                    <button class="calendar-arrow" type="button" aria-label="Previous month"><i class="ri-arrow-left-s-line"></i></button>
                                    <?php echo rp_h($calendarMonth); ?>
                                    <button class="calendar-arrow" type="button" aria-label="Next month"><i class="ri-arrow-right-s-line"></i></button>
                                </div>
                                <div class="calendar-grid">
                                    <?php foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $day): ?><strong><?php echo rp_h($day); ?></strong><?php endforeach; ?>
                                    <?php for ($i = 0; $i < $calendarFirstDay; $i++): ?><span></span><?php endfor; ?>
                                    <?php for ($day = 1; $day <= min($calendarDaysInMonth, 28); $day++): ?><span class="<?php echo $day === (int) date('j') ? 'today' : ''; ?>"><?php echo $day; ?></span><?php endfor; ?>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>

            <?php elseif ($portalPageKey === 'dashboard.php' && $portalRole === 'student'): ?>
                <div class="portal-grid student">
                    <div class="student-profile-grid">
                        <article class="profile-hero">
                            <span class="portal-avatar"><?php echo rp_h($roleInitials); ?></span>
                            <h2><?php echo rp_h($userName); ?></h2>
                            <p><?php echo rp_h($profileClass); ?></p>
                            <p>Roll No: <?php echo rp_h($profileRoll); ?></p>
                            <a class="portal-btn" href="<?php echo rp_h($baseUrl . 'profile.php'); ?>"><i class="ri-user-settings-line"></i> Edit Profile</a>
                        </article>
                        <div class="portal-grid student-metric-stack">
                            <?php foreach ($studentTiles as $tile): ?>
                                <article class="metric-card <?php echo rp_h($tile[3]); ?>">
                                    <span class="metric-icon"><i class="<?php echo rp_h($tile[2]); ?>"></i></span>
                                    <span><?php echo rp_h($tile[0]); ?></span>
                                    <strong><?php echo rp_h($tile[1]); ?></strong>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <article class="portal-card">
                        <div class="portal-card-header">
                            <h2>Attendance</h2>
                            <select class="portal-select" aria-label="Attendance period"><option>Yearly</option><option>Monthly</option></select>
                        </div>
                        <div class="portal-card-body">
                            <div class="horizontal-metrics student-attendance-metrics">
                                <?php foreach ($attendanceRows as $row): ?>
                                    <div class="attendance-item">
                                        <span class="attendance-dot <?php echo rp_h($row[2]); ?>"></span>
                                        <div><strong><?php echo rp_h($row[1]); ?></strong><span><?php echo rp_h($row[0]); ?></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>

                    <article class="portal-card">
                        <div class="portal-card-header"><h2>Today's Class</h2></div>
                        <div class="portal-card-body">
                            <div class="list-panel">
                                <?php foreach ($classRows as $row): ?>
                                    <div class="class-row">
                                        <div class="class-row-main">
                                            <p class="list-title"><?php echo rp_h($row[0]); ?></p>
                                            <p class="list-text"><i class="ri-time-line"></i> <?php echo rp_h($row[1]); ?></p>
                                        </div>
                                        <span class="status-pill <?php echo $row[2] === 'Completed' ? '' : 'warn'; ?>"><?php echo rp_h($row[2]); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="portal-grid student-bottom-grid">
                    <article class="portal-card table-card">
                        <div class="portal-card-header"><h2>Exam Results</h2></div>
                        <table class="portal-table">
                            <thead><tr><th>ID</th><th>Exam Name</th><th>Subject</th><th>Grade</th><th>Marks%</th><th>CGPA</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($examRows as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $index => $cell): ?>
                                            <td><?php if ($index === 6): ?><span class="status-pill <?php echo $cell === 'Fail' ? 'fail' : ''; ?>"><?php echo rp_h($cell); ?></span><?php else: ?><?php echo rp_h($cell); ?><?php endif; ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </article>
                    <article class="portal-card">
                        <div class="portal-card-header"><h2>Calendar</h2></div>
                        <div class="portal-card-body">
                            <div class="calendar-strip">
                                <button class="calendar-arrow" type="button" aria-label="Previous month"><i class="ri-arrow-left-s-line"></i></button>
                                <?php echo rp_h($calendarMonth); ?>
                                <button class="calendar-arrow" type="button" aria-label="Next month"><i class="ri-arrow-right-s-line"></i></button>
                            </div>
                            <div class="calendar-grid">
                                <?php foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $day): ?><strong><?php echo rp_h($day); ?></strong><?php endforeach; ?>
                                <?php for ($i = 0; $i < $calendarFirstDay; $i++): ?><span></span><?php endfor; ?>
                                <?php for ($day = 1; $day <= min($calendarDaysInMonth, 28); $day++): ?><span class="<?php echo $day === (int) date('j') ? 'today' : ''; ?>"><?php echo $day; ?></span><?php endfor; ?>
                            </div>
                        </div>
                    </article>
                </div>

            <?php elseif ($portalPageKey === 'dashboard.php' && $portalRole === 'parent'): ?>
                <div class="horizontal-metrics">
                    <?php foreach ($parentTiles as $tile): ?>
                        <article class="metric-card <?php echo rp_h($tile[3]); ?>">
                            <span class="metric-icon"><i class="<?php echo rp_h($tile[2]); ?>"></i></span>
                            <span><?php echo rp_h($tile[0]); ?></span>
                            <strong><?php echo rp_h($tile[1]); ?></strong>
                            <small class="metric-trend">10% up this month</small>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="portal-grid parent">
                    <div class="portal-grid">
                        <article class="portal-card">
                            <div class="portal-card-header">
                                <h2>Statistic</h2>
                                <select class="portal-select" aria-label="Statistic period"><option>Yearly</option><option>Monthly</option></select>
                            </div>
                            <div class="portal-card-body stat-summary">
                                <span class="orange">Avg. Attendance: 200</span>
                                <span class="teal">Avg. Exam Score: 500</span>
                            </div>
                        </article>

                        <div class="portal-grid parent-main-pair">
                            <article class="portal-card">
                                <div class="portal-card-header"><h2>Leave Status</h2><select class="portal-select" aria-label="Leave period"><option>Yearly</option></select></div>
                                <div class="portal-card-body">
                                    <div class="list-panel">
                                        <?php foreach ([['Emergency Leave', 'Pending'], ['Medical Leave', 'Accepted'], ['School Trip', 'Pending'], ['Medical Leave', 'Accepted']] as $row): ?>
                                            <div class="class-row">
                                                <div class="class-row-main">
                                                    <p class="list-title"><?php echo rp_h($row[0]); ?></p>
                                                    <p class="list-text"><i class="ri-calendar-line"></i> Date: 10/10/26</p>
                                                </div>
                                                <span class="status-pill <?php echo $row[1] === 'Accepted' ? '' : 'warn'; ?>"><?php echo rp_h($row[1]); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </article>
                            <article class="portal-card">
                                <div class="portal-card-header"><h2>Notice Board</h2></div>
                                <div class="portal-card-body">
                                    <div class="list-panel">
                                        <?php foreach ($noticeRows as $row): ?>
                                            <div class="notice-row">
                                                <span class="small-avatar"><?php echo rp_h(rp_initials($row[0])); ?></span>
                                                <div>
                                                    <p class="list-title"><?php echo rp_h($row[0]); ?></p>
                                                    <p class="list-text"><?php echo rp_h($row[1]); ?></p>
                                                    <div class="list-date"><?php echo rp_h($row[2]); ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <article class="portal-card table-card">
                            <div class="portal-card-header"><h2>Exam Results</h2></div>
                            <table class="portal-table">
                                <thead><tr><th>ID</th><th>Exam Name</th><th>Subject</th><th>Grade</th><th>Marks%</th><th>CGPA</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($examRows as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $index => $cell): ?>
                                                <td><?php if ($index === 6): ?><span class="status-pill <?php echo $cell === 'Fail' ? 'fail' : ''; ?>"><?php echo rp_h($cell); ?></span><?php else: ?><?php echo rp_h($cell); ?><?php endif; ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </article>
                    </div>

                    <div class="portal-grid">
                        <article class="portal-card">
                            <div class="portal-card-header"><h2>My Kids</h2></div>
                            <div class="portal-card-body">
                                <div class="list-panel kids-list">
                                    <?php foreach ([1, 2] as $kid): ?>
                                        <div class="kid-row">
                                            <span class="portal-avatar kid-avatar">ST</span>
                                            <div>
                                                <p class="list-title">Linked Student <?php echo $kid; ?></p>
                                                <p class="list-text">Admission No: <strong class="text-primary-strong">AD1256589</strong></p>
                                                <p class="list-text">Class: <?php echo rp_h($profileClass); ?></p>
                                                <p class="list-text">Academic Year: 2025/2026</p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </article>
                        <article class="portal-card">
                            <div class="portal-card-header"><h2>Upcoming Events</h2></div>
                            <div class="portal-card-body">
                                <div class="list-panel">
                                    <?php foreach ($eventRows as $row): ?>
                                        <div class="event-row">
                                            <div class="event-time"><?php echo rp_h($row[0]); ?></div>
                                            <div>
                                                <p class="list-title"><?php echo rp_h($row[1]); ?></p>
                                                <p class="list-text"><?php echo rp_h($row[2]); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>

            <?php else: ?>
                <div class="workspace-stats">
                    <?php foreach ($pageConfig[3] as $index => $label): ?>
                        <?php $tones = ['purple', 'green', 'blue', 'orange']; ?>
                        <article class="metric-card <?php echo rp_h($tones[$index % count($tones)]); ?>">
                            <span class="metric-icon"><i class="<?php echo rp_h($pageConfig[2]); ?>"></i></span>
                            <span><?php echo rp_h($label); ?></span>
                            <strong><?php echo rp_h($metricValues[$label] ?? (string) (($index + 2) * 4)); ?></strong>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="workspace-layout">
                    <article class="portal-card table-card">
                        <div class="portal-card-header">
                            <h2><?php echo rp_h($pageConfig[0]); ?> Workspace</h2>
                            <span class="status-pill">Ready</span>
                        </div>
                        <table class="portal-table">
                            <thead><tr><th>Item</th><th>Status</th><th>Owner</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach (['Overview', 'Pending Review', 'Recent Activity', 'Next Step'] as $index => $row): ?>
                                    <tr>
                                        <td><?php echo rp_h($pageConfig[0] . ' ' . $row); ?></td>
                                        <td><span class="status-pill <?php echo $index === 1 ? 'warn' : ''; ?>"><?php echo $index === 0 ? 'Active' : 'Open'; ?></span></td>
                                        <td><?php echo rp_h($roleLabel); ?></td>
                                        <td><a class="workspace-view-link" href="<?php echo rp_h($baseUrl . $portalPageKey); ?>">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </article>

                    <aside class="portal-card">
                        <div class="portal-card-header"><h2>Quick Actions</h2></div>
                        <div class="portal-card-body">
                            <div class="workspace-actions">
                                <a class="action-link" href="<?php echo rp_h($baseUrl . 'dashboard.php'); ?>"><span><i class="ri-home-5-line"></i> Dashboard</span><i class="ri-arrow-right-s-line"></i></a>
                                <?php if (isset($pages['calendar.php'])): ?><a class="action-link" href="<?php echo rp_h($baseUrl . 'calendar.php'); ?>"><span><i class="ri-calendar-event-line"></i> Calendar</span><i class="ri-arrow-right-s-line"></i></a><?php endif; ?>
                                <?php if (isset($pages['messages.php'])): ?><a class="action-link" href="<?php echo rp_h($baseUrl . 'messages.php'); ?>"><span><i class="ri-mail-line"></i> Messages</span><i class="ri-arrow-right-s-line"></i></a><?php endif; ?>
                                <a class="action-link" href="<?php echo rp_h($baseUrl . 'profile.php'); ?>"><span><i class="ri-user-settings-line"></i> Profile</span><i class="ri-arrow-right-s-line"></i></a>
                            </div>
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<script src="<?php echo rp_h($assetUrl); ?>js/lib/jquery-3.7.1.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/lib/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';
    var sidebar = document.querySelector('[data-portal-sidebar]');
    var overlay = document.querySelector('[data-portal-overlay]');
    var openBtn = document.querySelector('[data-mobile-menu]');
    var closeBtn = document.querySelector('[data-sidebar-close]');
    function openMenu() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('open');
        overlay.classList.add('show');
    }
    function closeMenu() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    }
    if (openBtn) openBtn.addEventListener('click', openMenu);
    if (closeBtn) closeBtn.addEventListener('click', closeMenu);
    if (overlay) overlay.addEventListener('click', closeMenu);
})();
</script>

<?php if ($portalRole === 'teacher'): ?>
<?php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$ai_csrf = $_SESSION['csrf_token'];
$ai_endpoint = rtrim($baseUrl, '/') . '/ai_teacher.php';
?>
<style>
    #teacher-ai-bubble {
        position: fixed;
        right: 28px;
        bottom: 28px;
        z-index: 100;
        font-family: inherit;
    }
    #teacher-ai-toggle {
        width: 58px;
        height: 58px;
        border-radius: 50%;
        border: 0;
        background: var(--portal-primary);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 16px 34px rgba(37, 161, 148, .35);
        cursor: pointer;
    }
    #teacher-ai-panel {
        position: absolute;
        right: 0;
        bottom: 72px;
        width: 380px;
        max-width: calc(100vw - 32px);
        background: #fff;
        border: 1px solid var(--portal-border);
        border-radius: 8px;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .18);
        overflow: hidden;
        display: none;
    }
    #teacher-ai-panel.open { display: block; }
    .ai-panel-header {
        padding: 16px 18px;
        background: linear-gradient(135deg, #25a194, #1f857b);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .ai-panel-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #fff; }
    .ai-panel-header p { margin: 3px 0 0; font-size: 12px; opacity: .84; }
    .ai-panel-close { border: 0; background: transparent; color: #fff; font-size: 24px; line-height: 1; cursor: pointer; }
    .ai-quick-actions { padding: 12px; display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid var(--portal-border); }
    .ai-quick-btn {
        border: 1px solid #d2ebe7;
        background: #eefaf8;
        color: #14665e;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
    }
    .ai-messages { min-height: 170px; max-height: 310px; overflow-y: auto; padding: 14px; display: grid; gap: 10px; }
    .ai-msg { max-width: 88%; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; color: #1f2937; background: #f2f5f8; }
    .ai-msg.user { justify-self: end; background: var(--portal-primary); color: #fff; }
    .ai-msg.err { background: #fde8e8; color: #991b1b; }
    .ai-email-card {
        border: 1px solid var(--portal-border);
        border-radius: 8px;
        background: #fff;
        overflow: hidden;
        display: grid;
        gap: 0;
    }
    .ai-email-card__header {
        background: #f7fafc;
        border-bottom: 1px solid var(--portal-border);
        padding: 10px 12px;
        color: #1f2937;
        font-size: 13px;
        font-weight: 800;
    }
    .ai-email-fields { padding: 12px; display: grid; gap: 10px; }
    .ai-email-fields label {
        display: block;
        margin-bottom: 5px;
        color: #64748b;
        font-size: 12px;
        font-weight: 800;
    }
    .ai-email-fields input,
    .ai-email-fields textarea,
    .ai-email-fields select {
        width: 100%;
        border: 1px solid #dbe2eb;
        border-radius: 8px;
        padding: 9px 10px;
        outline: 0;
        color: #1f2937;
        background: #fff;
    }
    .ai-email-fields textarea { min-height: 110px; resize: vertical; }
    .ai-email-actions {
        padding: 0 12px 12px;
        display: flex;
        gap: 10px;
    }
    .ai-email-actions button {
        min-height: 40px;
        border: 0;
        border-radius: 8px;
        padding: 0 14px;
        font-weight: 800;
        cursor: pointer;
    }
    .ai-email-send { background: var(--portal-primary); color: #fff; flex: 1; }
    .ai-email-discard { background: #f1f5f9; color: #64748b; }
    .ai-email-status { padding: 0 12px 12px; color: #64748b; font-size: 12px; font-weight: 700; }
    .ai-email-status.ok { color: #047857; }
    .ai-email-status.fail { color: #b91c1c; }
    .ai-input-row { padding: 12px; border-top: 1px solid var(--portal-border); display: flex; gap: 10px; }
    .ai-input-row textarea {
        flex: 1;
        border: 1px solid #dbe2eb;
        border-radius: 8px;
        padding: 10px 12px;
        resize: none;
        min-height: 42px;
        max-height: 110px;
        outline: 0;
    }
    .ai-send-btn {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        border: 0;
        background: var(--portal-primary);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }
</style>
<div id="teacher-ai-bubble">
    <div id="teacher-ai-panel" role="dialog" aria-label="Teacher AI Assistant">
        <div class="ai-panel-header">
            <div>
                <h3>Teacher Assistant</h3>
                <p><?php echo rp_h($schoolName); ?></p>
            </div>
            <button class="ai-panel-close" id="teacher-ai-close" type="button" aria-label="Close">&times;</button>
        </div>
        <div class="ai-quick-actions">
            <button class="ai-quick-btn" type="button" data-prompt="Help me create a lesson plan. Ask for the class, subject, and topic first."><i class="ri-file-list-3-line"></i> Lesson Plan</button>
            <button class="ai-quick-btn" type="button" data-prompt="Help me create an assignment. Ask for the class, subject, topic, and due date."><i class="ri-task-line"></i> Assignment</button>
            <button class="ai-quick-btn" type="button" data-prompt="Help me draft a professional parent update email."><i class="ri-mail-line"></i> Parent Email</button>
        </div>
        <div class="ai-messages" id="teacher-ai-messages">
            <div class="ai-msg">Hi <?php echo rp_h($userName); ?>. I can help with lesson plans, assignments, exam questions, student remarks, and parent emails.</div>
        </div>
        <div class="ai-input-row">
            <textarea id="teacher-ai-input" rows="1" placeholder="Ask anything..." aria-label="Message to AI"></textarea>
            <button class="ai-send-btn" id="teacher-ai-send" type="button" aria-label="Send"><i class="ri-send-plane-fill"></i></button>
        </div>
    </div>
    <button id="teacher-ai-toggle" type="button" aria-label="Open Teacher AI Assistant"><i class="ri-robot-2-line"></i></button>
</div>
<script>
(function () {
    'use strict';
    var endpoint = <?php echo json_encode($ai_endpoint); ?>;
    var csrf = <?php echo json_encode($ai_csrf); ?>;
    var toggle = document.getElementById('teacher-ai-toggle');
    var panel = document.getElementById('teacher-ai-panel');
    var closeBtn = document.getElementById('teacher-ai-close');
    var messagesEl = document.getElementById('teacher-ai-messages');
    var inputEl = document.getElementById('teacher-ai-input');
    var sendBtn = document.getElementById('teacher-ai-send');
    var history = [];
    var busy = false;

    function escHtml(text) {
        return String(text).replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }
    function escAttr(text) {
        return escHtml(text).replace(/`/g, '&#096;');
    }
    function stripTags(text) {
        return String(text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    }
    function extractEmailDraft(toolCallsMade) {
        var calls = Array.isArray(toolCallsMade) ? toolCallsMade : [];
        for (var index = 0; index < calls.length; index++) {
            var result = calls[index].result || calls[index];
            if (result && result.__type === 'email_draft') {
                return result;
            }
            if (typeof result === 'string') {
                try {
                    var parsed = JSON.parse(result);
                    if (parsed && parsed.__type === 'email_draft') {
                        return parsed;
                    }
                } catch (error) {}
            }
        }
        return null;
    }
    function appendMsg(role, html) {
        var div = document.createElement('div');
        div.className = 'ai-msg ' + role;
        div.innerHTML = html;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return div;
    }
    function setBusy(state) {
        busy = state;
        inputEl.disabled = state;
        sendBtn.disabled = state;
    }
    function previewRecipients(audience, statusEl) {
        var formData = new FormData();
        formData.append('action', 'preview_recipients');
        formData.append('csrf_token', csrf);
        formData.append('audience', audience);
        fetch(endpoint, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.success) {
                statusEl.className = 'ai-email-status ok';
                statusEl.textContent = data.count + ' recipient(s) will receive this email.';
            }
        })
        .catch(function () {});
    }
    function appendEmailCard(draft) {
        var card = document.createElement('div');
        card.className = 'ai-email-card';
        var audience = draft.audience || 'parents';
        var body = draft.body_plain || stripTags(draft.body_html || '');
        card.innerHTML =
            '<div class="ai-email-card__header"><i class="ri-mail-send-line"></i> Review Email Draft</div>' +
            '<div class="ai-email-fields">' +
                '<div><label>Audience</label><select class="ai-email-audience">' +
                    '<option value="parents"' + (audience === 'parents' ? ' selected' : '') + '>Parents</option>' +
                    '<option value="teachers"' + (audience === 'teachers' ? ' selected' : '') + '>Teachers</option>' +
                    '<option value="staff"' + (audience === 'staff' ? ' selected' : '') + '>Staff</option>' +
                    '<option value="students"' + (audience === 'students' ? ' selected' : '') + '>Students</option>' +
                    '<option value="all"' + (audience === 'all' ? ' selected' : '') + '>Everyone</option>' +
                '</select></div>' +
                '<div><label>Subject</label><input class="ai-email-subject" type="text" value="' + escAttr(draft.subject || '') + '"></div>' +
                '<div><label>Message</label><textarea class="ai-email-body">' + escHtml(body) + '</textarea></div>' +
            '</div>' +
            '<div class="ai-email-actions">' +
                '<button type="button" class="ai-email-send">Send Email</button>' +
                '<button type="button" class="ai-email-discard">Discard</button>' +
            '</div>' +
            '<div class="ai-email-status"></div>';

        var audienceEl = card.querySelector('.ai-email-audience');
        var subjectEl = card.querySelector('.ai-email-subject');
        var bodyEl = card.querySelector('.ai-email-body');
        var sendEmailBtn = card.querySelector('.ai-email-send');
        var statusEl = card.querySelector('.ai-email-status');

        card.querySelector('.ai-email-discard').addEventListener('click', function () {
            card.remove();
        });
        audienceEl.addEventListener('change', function () {
            previewRecipients(audienceEl.value, statusEl);
        });
        sendEmailBtn.addEventListener('click', function () {
            var subject = subjectEl.value.trim();
            var bodyText = bodyEl.value.trim();
            if (!subject || !bodyText) {
                statusEl.className = 'ai-email-status fail';
                statusEl.textContent = 'Subject and message are required.';
                return;
            }
            sendEmailBtn.disabled = true;
            sendEmailBtn.textContent = 'Sending...';
            statusEl.className = 'ai-email-status';
            statusEl.textContent = '';

            var formData = new FormData();
            formData.append('action', 'send_email');
            formData.append('csrf_token', csrf);
            formData.append('audience', audienceEl.value);
            formData.append('subject', subject);
            formData.append('body_html', bodyText.replace(/\n/g, '<br>'));

            fetch(endpoint, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                statusEl.className = 'ai-email-status ' + (data && data.success ? 'ok' : 'fail');
                statusEl.textContent = (data && data.message) || 'Email request completed.';
                if (data && data.success) {
                    sendEmailBtn.textContent = 'Sent';
                    appendMsg('', escHtml(data.message || 'Email sent successfully.'));
                    return;
                }
                sendEmailBtn.disabled = false;
                sendEmailBtn.textContent = 'Send Email';
            })
            .catch(function (error) {
                statusEl.className = 'ai-email-status fail';
                statusEl.textContent = 'Network error: ' + error.message;
                sendEmailBtn.disabled = false;
                sendEmailBtn.textContent = 'Send Email';
            });
        });

        if (draft.recipient_count !== undefined) {
            statusEl.className = 'ai-email-status ok';
            statusEl.textContent = draft.recipient_count + ' recipient(s) found.';
        } else {
            previewRecipients(audience, statusEl);
        }
        messagesEl.appendChild(card);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
    function send(text) {
        if (!text || busy) return;
        appendMsg('user', escHtml(text));
        history.push({ role: 'user', content: text });
        setBusy(true);
        var typing = appendMsg('', 'Thinking...');
        fetch(endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'chat', csrf_token: csrf, messages: history })
        })
        .then(function (res) {
            var contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return res.text().then(function (txt) {
                    throw new Error('Server returned an unexpected response. Please refresh and try again.');
                });
            }
            return res.json();
        })
        .then(function (data) {
            typing.remove();
            if (!data || !data.success) {
                appendMsg('err', escHtml((data && data.message) || 'The assistant could not respond right now.'));
                return;
            }
            var draft = extractEmailDraft(data.tool_calls_made);
            if (draft) {
                appendEmailCard(draft);
            }
            if (data.reply) {
                appendMsg('', data.reply);
            } else if (!draft) {
                appendMsg('', 'Done.');
            }
            history.push({ role: 'assistant', content: data.reply || '' });
        })
        .catch(function (err) {
            typing.remove();
            appendMsg('err', escHtml(err.message || 'Could not reach the assistant. Please try again.'));
        })
        .finally(function () { setBusy(false); });
    }
    toggle.addEventListener('click', function () { panel.classList.toggle('open'); });
    closeBtn.addEventListener('click', function () { panel.classList.remove('open'); });
    sendBtn.addEventListener('click', function () {
        var text = inputEl.value.trim();
        inputEl.value = '';
        send(text);
    });
    inputEl.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendBtn.click();
        }
    });
    document.querySelectorAll('.ai-quick-btn').forEach(function (button) {
        button.addEventListener('click', function () { send(button.dataset.prompt || ''); });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
