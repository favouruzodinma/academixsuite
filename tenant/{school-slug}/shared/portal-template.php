<?php
$school = $GLOBALS['SCHOOL_DATA'] ?? [];
$auth = $GLOBALS['SCHOOL_AUTH'] ?? ($_SESSION['school_auth'] ?? []);
$role = $GLOBALS['USER_TYPE'] ?? ($auth['user_type'] ?? 'student');
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'dashboard.php';
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? ($auth['school_slug'] ?? '');
$baseUrl = $GLOBALS['BASE_URL'] ?? (function_exists('school_route_url') ? rtrim(school_route_url($schoolSlug, $role, '', false), '/') . '/' : "/tenant/{$schoolSlug}/{$role}/");
$loginUrl = function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '/tenant/login.php' . ($schoolSlug ? '?school_slug=' . urlencode($schoolSlug) : '');
$logoutUrl = function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug)
    ? '/logout.php'
    : '/tenant/logout.php' . ($schoolSlug ? '?school_slug=' . urlencode($schoolSlug) : '');

if (empty($auth)) {
    header('Location: ' . $loginUrl);
    exit;
}

$portalConfig = getPortalConfig();
$roleConfig = $portalConfig[$role] ?? $portalConfig['student'];
$pages = $roleConfig['pages'];
$canonicalPage = normalizePortalPage($currentPage, $roleConfig['aliases']);

if (!isset($pages[$canonicalPage])) {
    $canonicalPage = 'dashboard.php';
}

$page = $pages[$canonicalPage];
$schoolName = $school['name'] ?? ($auth['school_name'] ?? 'School Portal');
$portalSchoolLogo = function_exists('school_logo_url')
    ? school_logo_url($school)
    : (!empty($school['logo_path']) ? '/' . ltrim((string) $school['logo_path'], '/') : '');
$userName = $auth['user_name'] ?? 'User';
$staffRole = $auth['staff_role'] ?? '';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizePortalPage($page, array $aliases) {
    $base = basename((string)$page);
    if (isset($aliases[$base])) {
        return $aliases[$base];
    }
    if (preg_match('/\.html?$/i', $base)) {
        $base = preg_replace('/\.html?$/i', '.php', $base);
    }
    return $aliases[$base] ?? $base;
}

function getPortalConfig() {
    return [
        'teacher' => [
            'label' => 'Teacher Portal',
            'accent' => '#0f766e',
            'aliases' => [
                'dashboard.html' => 'dashboard.php',
                'teacher-dashboard.html' => 'dashboard.php',
                'teacher-classes.html' => 'classes.php',
                'my-classes.php' => 'classes.php',
                'teacher-attendance.html' => 'attendance.php',
                'teacher-grades.html' => 'grades.php',
                'marks.php' => 'grades.php'
            ],
            'pages' => [
                'dashboard.php' => ['title' => 'Teacher Dashboard', 'desc' => 'Daily teaching overview, class activity, and academic actions.', 'stats' => ['Active Classes', 'Pending Attendance', 'Assignments Due', 'Unread Messages'], 'sections' => ['Today Schedule', 'Class Performance', 'Quick Actions']],
                'classes.php' => ['title' => 'My Classes', 'desc' => 'Assigned classes, sections, students, and class teacher duties.', 'stats' => ['Assigned Classes', 'Students', 'Subjects', 'Class Notes'], 'sections' => ['Class List', 'Subject Allocation', 'Student Roster']],
                'attendance.php' => ['title' => 'Attendance', 'desc' => 'Mark, review, and submit student attendance for assigned classes.', 'stats' => ['Present Today', 'Absent Today', 'Late Entries', 'Pending Registers'], 'sections' => ['Mark Attendance', 'Attendance History', 'Exceptions']],
                'grades.php' => ['title' => 'Grades and Marks', 'desc' => 'Enter scores, review grade books, and publish assessment records.', 'stats' => ['Assessments', 'Unmarked Scripts', 'Published Results', 'Class Average'], 'sections' => ['Grade Book', 'Assessment Entry', 'Result Comments']],
                'timetable.php' => ['title' => 'Timetable', 'desc' => 'Teaching schedule, periods, substitutions, and room allocation.', 'stats' => ['Periods Today', 'Weekly Load', 'Free Periods', 'Substitutions'], 'sections' => ['Today Timetable', 'Weekly Schedule', 'Room Notes']],
                'assignments.php' => ['title' => 'Assignments', 'desc' => 'Create homework, manage submissions, and track marking progress.', 'stats' => ['Open Tasks', 'Submitted', 'Late', 'Marked'], 'sections' => ['Assignment Board', 'Submission Tracker', 'Feedback Queue']],
                'students.php' => ['title' => 'Students', 'desc' => 'View learner profiles, performance notes, and attendance patterns.', 'stats' => ['Students', 'At Risk', 'High Performers', 'Notes'], 'sections' => ['Student Directory', 'Performance Notes', 'Support Flags']],
                'announcements.php' => ['title' => 'Announcements', 'desc' => 'School notices, class announcements, and audience-specific updates.', 'stats' => ['Published', 'Drafts', 'Class Notices', 'Unread'], 'sections' => ['School Notices', 'Class Notices', 'Announcement Archive']],
                'calendar.php' => ['title' => 'Calendar', 'desc' => 'School calendar, class events, deadlines, meetings, and exam dates.', 'stats' => ['Events', 'Meetings', 'Deadlines', 'Exams'], 'sections' => ['Month View', 'Upcoming Events', 'Academic Calendar']],
                'messages.php' => ['title' => 'Messages', 'desc' => 'Communicate with administrators, students, and parents.', 'stats' => ['Inbox', 'Sent', 'Announcements', 'Unread'], 'sections' => ['Inbox', 'Parent Messages', 'Admin Notices']],
                'profile.php' => ['title' => 'Profile', 'desc' => 'Manage teacher profile, password, contact information, and preferences.', 'stats' => ['Profile Status', 'Last Login', 'Security', 'Notifications'], 'sections' => ['Personal Details', 'Security', 'Preferences']]
            ]
        ],
        'student' => [
            'label' => 'Student Portal',
            'accent' => '#2563eb',
            'aliases' => [
                'dashboard.html' => 'dashboard.php',
                'school-dashboard.php' => 'dashboard.php',
                'marks.php' => 'grades.php',
                'report-card.php' => 'results.php'
            ],
            'pages' => [
                'dashboard.php' => ['title' => 'Student Dashboard', 'desc' => 'Your classes, timetable, assignments, and school notices in one place.', 'stats' => ['Today Classes', 'Assignments', 'Attendance', 'Messages'], 'sections' => ['Today Overview', 'Learning Progress', 'School Notices']],
                'timetable.php' => ['title' => 'Timetable', 'desc' => 'Daily and weekly class schedule with subjects and room details.', 'stats' => ['Classes Today', 'This Week', 'Free Periods', 'Next Class'], 'sections' => ['Today Schedule', 'Weekly Timetable', 'Exam Schedule']],
                'attendance.php' => ['title' => 'Attendance', 'desc' => 'Track attendance history, punctuality, and absence records.', 'stats' => ['Attendance Rate', 'Present Days', 'Absent Days', 'Late Days'], 'sections' => ['Attendance Calendar', 'Term Summary', 'Remarks']],
                'grades.php' => ['title' => 'Grades', 'desc' => 'View scores, subject averages, teacher comments, and result trends.', 'stats' => ['Average', 'Subjects', 'Published Results', 'Teacher Comments'], 'sections' => ['Grade Book', 'Assessment Results', 'Progress Notes']],
                'results.php' => ['title' => 'Report Cards', 'desc' => 'Term reports, cumulative performance, and official result sheets.', 'stats' => ['Published Results', 'Average', 'Position', 'Comments'], 'sections' => ['Current Report', 'Result History', 'Teacher Remarks']],
                'assignments.php' => ['title' => 'Assignments', 'desc' => 'Open assignments, due dates, submissions, and feedback.', 'stats' => ['Open', 'Submitted', 'Overdue', 'Marked'], 'sections' => ['Assignment List', 'Submission History', 'Feedback']],
                'fees.php' => ['title' => 'Fees', 'desc' => 'Fee invoices, balances, receipts, and payment status.', 'stats' => ['Outstanding', 'Paid', 'Invoices', 'Receipts'], 'sections' => ['Fee Summary', 'Invoices', 'Payment History']],
                'library.php' => ['title' => 'Library', 'desc' => 'Borrowed books, reservations, due dates, and fines.', 'stats' => ['Borrowed', 'Due Soon', 'Reservations', 'Fine Balance'], 'sections' => ['Borrowed Books', 'Catalog Picks', 'Fine Notices']],
                'announcements.php' => ['title' => 'Announcements', 'desc' => 'School notices, class updates, and student announcements.', 'stats' => ['Unread', 'Class Notices', 'School Notices', 'Archived'], 'sections' => ['Latest Notices', 'Class Announcements', 'School Updates']],
                'calendar.php' => ['title' => 'Calendar', 'desc' => 'Class calendar, assignment due dates, exams, and school events.', 'stats' => ['Events', 'Assignments Due', 'Exams', 'Activities'], 'sections' => ['Today', 'This Week', 'Exam Calendar']],
                'messages.php' => ['title' => 'Messages', 'desc' => 'Messages from teachers, school administrators, and classmates.', 'stats' => ['Inbox', 'Unread', 'Announcements', 'Sent'], 'sections' => ['Inbox', 'Class Messages', 'Announcements']],
                'profile.php' => ['title' => 'Profile', 'desc' => 'Student profile, contact details, password, and account settings.', 'stats' => ['Profile Status', 'Class', 'Admission No.', 'Security'], 'sections' => ['Personal Details', 'Guardian Contacts', 'Security']]
            ]
        ],
        'parent' => [
            'label' => 'Parent Portal',
            'accent' => '#b45309',
            'aliases' => [
                'dashboard.html' => 'dashboard.php',
                'parent-dashboard.html' => 'dashboard.php',
                'parent-child.html' => 'children.php',
                'parent-attendance.html' => 'attendance.php',
                'parent-grades.html' => 'grades.php',
                'parent-schedule.html' => 'schedule.php',
                'marks.php' => 'grades.php',
                'invoices.php' => 'fees.php'
            ],
            'pages' => [
                'dashboard.php' => ['title' => 'Parent Dashboard', 'desc' => 'Child performance, attendance, fees, and school communication.', 'stats' => ['Children', 'Attendance', 'Fee Balance', 'Unread Notices'], 'sections' => ['Child Overview', 'Recent Updates', 'Parent Actions']],
                'children.php' => ['title' => 'My Children', 'desc' => 'Linked student profiles, class placement, and academic status.', 'stats' => ['Linked Children', 'Classes', 'Teachers', 'Support Notes'], 'sections' => ['Child Profiles', 'Class Teachers', 'Academic Notes']],
                'attendance.php' => ['title' => 'Attendance', 'desc' => 'Attendance history and punctuality for linked children.', 'stats' => ['Attendance Rate', 'Present', 'Absent', 'Late'], 'sections' => ['Attendance Summary', 'Absence Notes', 'Term Trends']],
                'grades.php' => ['title' => 'Grades', 'desc' => 'Published results, teacher comments, and subject progress.', 'stats' => ['Average Score', 'Subjects', 'Reports', 'Comments'], 'sections' => ['Result Summary', 'Subject Progress', 'Teacher Feedback']],
                'fees.php' => ['title' => 'Fees and Invoices', 'desc' => 'Outstanding balances, invoices, receipts, and payment reminders.', 'stats' => ['Outstanding', 'Paid This Term', 'Invoices', 'Receipts'], 'sections' => ['Fee Balance', 'Invoices', 'Payment History']],
                'schedule.php' => ['title' => 'Schedule', 'desc' => 'Class timetable, exam calendar, meetings, and school events.', 'stats' => ['Classes Today', 'Upcoming Exams', 'Events', 'Meetings'], 'sections' => ['Weekly Schedule', 'Exam Calendar', 'Parent Meetings']],
                'announcements.php' => ['title' => 'Announcements', 'desc' => 'School-wide notices, child-specific updates, and reminders.', 'stats' => ['Unread', 'School Notices', 'Class Notices', 'Reminders'], 'sections' => ['Latest Notices', 'Class Updates', 'Important Reminders']],
                'messages.php' => ['title' => 'Messages', 'desc' => 'Communicate with teachers and school administration.', 'stats' => ['Inbox', 'Teacher Messages', 'Admin Notices', 'Unread'], 'sections' => ['Inbox', 'Teacher Conversations', 'Announcements']],
                'support.php' => ['title' => 'Support', 'desc' => 'Submit questions, document requests, and support tickets.', 'stats' => ['Open Tickets', 'Resolved', 'Requests', 'Replies'], 'sections' => ['New Request', 'Ticket History', 'School Contacts']],
                'profile.php' => ['title' => 'Profile', 'desc' => 'Parent contact details, emergency information, and account security.', 'stats' => ['Profile Status', 'Linked Students', 'Security', 'Notifications'], 'sections' => ['Personal Details', 'Emergency Contacts', 'Security']]
            ]
        ],
        'staff' => [
            'label' => 'Staff Portal',
            'accent' => '#7c3aed',
            'aliases' => [
                'dashboard.html' => 'dashboard.php',
                'school-dashboard.php' => 'dashboard.php',
                'tasks.php' => 'work.php'
            ],
            'pages' => [
                'dashboard.php' => ['title' => 'Staff Dashboard', 'desc' => 'Operational tasks, approvals, attendance, and assigned workflows.', 'stats' => ['Open Tasks', 'Approvals', 'Messages', 'Today Schedule'], 'sections' => ['Work Queue', 'Operational Updates', 'Quick Actions']],
                'attendance.php' => ['title' => 'Staff Attendance', 'desc' => 'Clock-in records, attendance status, and shift history.', 'stats' => ['Present Days', 'Late Days', 'Leave Days', 'Shift Hours'], 'sections' => ['Attendance Log', 'Shift Summary', 'Exceptions']],
                'payroll.php' => ['title' => 'Payroll', 'desc' => 'Salary slips, deductions, allowances, and payment history.', 'stats' => ['Current Slip', 'Allowances', 'Deductions', 'Net Pay'], 'sections' => ['Payslips', 'Payroll Details', 'Tax and Deductions']],
                'leave.php' => ['title' => 'Leave Requests', 'desc' => 'Apply for leave, track approvals, and review leave balances.', 'stats' => ['Available Days', 'Pending', 'Approved', 'Rejected'], 'sections' => ['New Leave Request', 'Leave Balance', 'Approval History']],
                'library.php' => ['title' => 'Library Operations', 'desc' => 'Book circulation, reservations, members, and fines.', 'stats' => ['Issued Books', 'Returns Due', 'Reservations', 'Fines'], 'sections' => ['Circulation Queue', 'Catalog Tasks', 'Member Requests']],
                'inventory.php' => ['title' => 'Inventory', 'desc' => 'Stock, assets, requests, and movement records.', 'stats' => ['Items', 'Low Stock', 'Requests', 'Movements'], 'sections' => ['Inventory List', 'Stock Requests', 'Asset Movement']],
                'fees.php' => ['title' => 'Fee Operations', 'desc' => 'Fee collections, receipts, invoices, and payment follow-up.', 'stats' => ['Collections', 'Pending', 'Receipts', 'Follow-ups'], 'sections' => ['Collection Queue', 'Receipt Log', 'Outstanding Fees']],
                'messages.php' => ['title' => 'Messages', 'desc' => 'Internal messages, admin notices, and assigned conversations.', 'stats' => ['Inbox', 'Unread', 'Assigned', 'Sent'], 'sections' => ['Inbox', 'Team Messages', 'Announcements']],
                'reports.php' => ['title' => 'Reports', 'desc' => 'Operational exports, summaries, and departmental reports.', 'stats' => ['Generated', 'Scheduled', 'Pending', 'Exports'], 'sections' => ['Report Library', 'Scheduled Reports', 'Export Center']],
                'work.php' => ['title' => 'Work Queue', 'desc' => 'Assigned work, daily operational tasks, approvals, and follow-ups.', 'stats' => ['Open Tasks', 'Due Today', 'Completed', 'Escalated'], 'sections' => ['Assigned Tasks', 'Due Today', 'Completed Work']],
                'calendar.php' => ['title' => 'Calendar', 'desc' => 'Staff schedule, shifts, deadlines, and operational events.', 'stats' => ['Shifts', 'Events', 'Deadlines', 'Meetings'], 'sections' => ['Shift Calendar', 'Upcoming Events', 'Operational Deadlines']],
                'profile.php' => ['title' => 'Profile', 'desc' => 'Staff profile, role details, contact information, and security.', 'stats' => ['Profile Status', 'Role', 'Department', 'Security'], 'sections' => ['Personal Details', 'Role Information', 'Security']]
            ]
        ]
    ];
}

function portalMetricValue($label) {
    $map = [
        'Attendance Rate' => '94%',
        'Average' => '78%',
        'Average Score' => '81%',
        'Outstanding' => 'NGN 0',
        'Fee Balance' => 'NGN 0',
        'Net Pay' => 'Ready',
        'Role' => 'Active',
        'Security' => 'Good',
        'Profile Status' => 'Complete',
        'Last Login' => 'Today'
    ];
    return $map[$label] ?? (string)random_int(2, 18);
}

$navPages = $pages;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($page['title']); ?> - <?php echo h($schoolName); ?></title>
    <?php if ($portalSchoolLogo !== ''): ?>
        <link rel="icon" type="image/png" href="<?php echo h($portalSchoolLogo); ?>">
    <?php endif; ?>
    <style>
        :root { --accent: <?php echo h($roleConfig['accent']); ?>; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f6f8fb; color: #0f172a; }
        a { color: inherit; text-decoration: none; }
        .shell { min-height: 100vh; display: grid; grid-template-columns: 280px 1fr; }
        .sidebar { background: #0f172a; color: #e2e8f0; padding: 22px 18px; }
        .brand { padding: 8px 10px 22px; border-bottom: 1px solid rgba(255,255,255,.1); margin-bottom: 18px; }
        .brand h1 { margin: 0 0 6px; font-size: 18px; line-height: 24px; }
        .brand p { margin: 0; font-size: 12px; color: #94a3b8; line-height: 18px; }
        .nav { display: flex; flex-direction: column; gap: 5px; }
        .nav a { padding: 10px 12px; border-radius: 7px; font-size: 13px; color: #cbd5e1; display: flex; justify-content: space-between; }
        .nav a.active { background: var(--accent); color: white; font-weight: 700; }
        .nav a:hover { background: rgba(255,255,255,.08); color: white; }
        .main { padding: 24px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
        .eyebrow { color: var(--accent); font-size: 12px; text-transform: uppercase; font-weight: 800; letter-spacing: .08em; }
        h2 { margin: 6px 0 8px; font-size: 28px; line-height: 34px; }
        .muted { color: #64748b; font-size: 14px; line-height: 22px; margin: 0; }
        .user { background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; min-width: 220px; }
        .user strong { display: block; font-size: 13px; }
        .user span { color: #64748b; font-size: 12px; }
        .cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .card, .panel { background: white; border: 1px solid #e2e8f0; border-radius: 8px; }
        .card { padding: 16px; }
        .card small { display: block; color: #64748b; font-size: 12px; margin-bottom: 8px; }
        .card strong { font-size: 24px; }
        .grid { display: grid; grid-template-columns: 1.2fr .8fr; gap: 16px; }
        .panel { padding: 18px; }
        .panel h3 { margin: 0 0 12px; font-size: 16px; }
        .list { display: grid; gap: 10px; }
        .row { display: flex; justify-content: space-between; align-items: center; border: 1px solid #edf2f7; border-radius: 7px; padding: 10px 12px; }
        .row span { color: #64748b; font-size: 12px; }
        .badge { color: var(--accent); background: color-mix(in srgb, var(--accent) 10%, white); border-radius: 999px; padding: 4px 9px; font-size: 11px; font-weight: 800; }
        .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .action { border: 1px solid #dbe3ef; border-radius: 7px; padding: 12px; font-size: 13px; font-weight: 700; background: #f8fafc; }
        .footer-note { margin-top: 18px; color: #64748b; font-size: 12px; }
        @media (max-width: 960px) {
            .shell { grid-template-columns: 1fr; }
            .sidebar { position: static; }
            .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
        }
        @media (max-width: 560px) {
            .main { padding: 16px; }
            .cards, .actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <?php if ($portalSchoolLogo !== ''): ?>
                    <img src="<?php echo h($portalSchoolLogo); ?>" alt="<?php echo h($schoolName); ?> logo" class="sidebar-logo" style="max-width:100%;height:auto;margin-bottom:8px;">
                <?php endif; ?>
                <h1><?php echo h($schoolName); ?></h1>
                <p><?php echo h($roleConfig['label']); ?><?php echo $staffRole ? ' - ' . h(ucfirst($staffRole)) : ''; ?></p>
            </div>
            <nav class="nav">
                <?php foreach ($navPages as $file => $navPage): ?>
                    <a class="<?php echo $file === $canonicalPage ? 'active' : ''; ?>" href="<?php echo h($baseUrl . $file); ?>">
                        <span><?php echo h($navPage['title']); ?></span>
                        <span>&rsaquo;</span>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo h($logoutUrl); ?>"><span>Logout</span><span>&rsaquo;</span></a>
            </nav>
        </aside>
        <main class="main">
            <header class="topbar">
                <div>
                    <div class="eyebrow"><?php echo h($roleConfig['label']); ?></div>
                    <h2><?php echo h($page['title']); ?></h2>
                    <p class="muted"><?php echo h($page['desc']); ?></p>
                </div>
                <div class="user">
                    <strong><?php echo h($userName); ?></strong>
                    <span><?php echo h($auth['user_email'] ?? ''); ?></span>
                </div>
            </header>

            <section class="cards">
                <?php foreach ($page['stats'] as $stat): ?>
                    <article class="card">
                        <small><?php echo h($stat); ?></small>
                        <strong><?php echo h(portalMetricValue($stat)); ?></strong>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="grid">
                <article class="panel">
                    <h3><?php echo h($page['sections'][0] ?? 'Overview'); ?></h3>
                    <div class="list">
                        <?php foreach ($page['sections'] as $index => $section): ?>
                            <div class="row">
                                <div>
                                    <strong><?php echo h($section); ?></strong><br>
                                    <span><?php echo h($page['title']); ?> module is available for school-specific data.</span>
                                </div>
                                <span class="badge"><?php echo $index === 0 ? 'Primary' : 'Ready'; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <aside class="panel">
                    <h3>Quick Actions</h3>
                    <div class="actions">
                        <a class="action" href="<?php echo h($baseUrl . 'messages.php'); ?>">Open Messages</a>
                        <a class="action" href="<?php echo h($baseUrl . 'profile.php'); ?>">Update Profile</a>
                        <a class="action" href="<?php echo h($baseUrl . 'dashboard.php'); ?>">Dashboard</a>
                        <a class="action" href="<?php echo h($logoutUrl); ?>">Sign Out</a>
                    </div>
                    <p class="footer-note">This portal page is connected to the tenant route and ready for live module data as each module is expanded.</p>
                </aside>
            </section>
        </main>
    </div>
</body>
</html>
