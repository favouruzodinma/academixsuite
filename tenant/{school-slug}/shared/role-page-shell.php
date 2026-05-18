<?php
$school = $GLOBALS['SCHOOL_DATA'] ?? [];
$auth = $GLOBALS['SCHOOL_AUTH'] ?? ($_SESSION['school_auth'] ?? ($_SESSION['school_user'] ?? []));
$portalRole = $portalRole ?? ($GLOBALS['USER_TYPE'] ?? ($auth['user_type'] ?? 'student'));
$portalRole = in_array($portalRole, ['accountant', 'librarian', 'receptionist'], true) ? 'staff' : $portalRole;
$portalPageKey = $portalPageKey ?? basename($GLOBALS['CURRENT_PAGE'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? ($auth['school_slug'] ?? '');
$baseUrl = $GLOBALS['BASE_URL'] ?? (function_exists('school_route_url') ? rtrim(school_route_url($schoolSlug, $portalRole, '', false), '/') . '/' : '');
$assetUrl = $GLOBALS['ASSETS_URL'] ?? (function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug) ? '/assets/' : '/tenant/assets/');

if (!function_exists('rp_h')) {
    function rp_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rp_role_pages')) {
    function rp_role_pages() {
        return [
            'teacher' => [
                'label' => 'Teacher Portal',
                'avatar' => 'TR',
                'pages' => [
                    'dashboard.php' => ['Dashboard', 'Daily classes, academic work, attendance, and messages.', 'ri-dashboard-3-line', ['Classes', 'Attendance', 'Assignments', 'Messages']],
                    'classes.php' => ['My Classes', 'Assigned classes, subject allocation, and class rosters.', 'ri-school-line', ['Assigned', 'Students', 'Subjects', 'Notes']],
                    'attendance.php' => ['Attendance', 'Mark and review attendance for assigned classes.', 'ri-calendar-check-line', ['Present', 'Absent', 'Late', 'Pending']],
                    'grades.php' => ['Grades', 'Score entry, assessment records, and teacher comments.', 'ri-bar-chart-box-line', ['Assessments', 'Marked', 'Average', 'Published']],
                    'timetable.php' => ['Timetable', 'Teaching schedule, free periods, and substitutions.', 'ri-time-line', ['Today', 'Week Load', 'Free', 'Rooms']],
                    'assignments.php' => ['Assignments', 'Homework, submissions, marking, and feedback.', 'ri-file-list-3-line', ['Open', 'Submitted', 'Late', 'Marked']],
                    'students.php' => ['Students', 'Student profiles, performance notes, and support flags.', 'ri-group-line', ['Students', 'At Risk', 'Notes', 'Parents']],
                    'announcements.php' => ['Announcements', 'Class notices, school updates, and drafts.', 'ri-megaphone-line', ['Published', 'Drafts', 'Unread', 'Audience']],
                    'calendar.php' => ['Calendar', 'Academic events, deadlines, meetings, and exams.', 'ri-calendar-event-line', ['Events', 'Meetings', 'Deadlines', 'Exams']],
                    'messages.php' => ['Messages', 'Parent, student, and administrator conversations.', 'ri-mail-line', ['Inbox', 'Unread', 'Sent', 'Archived']],
                    'profile.php' => ['Profile', 'Personal details, password, and notification settings.', 'ri-user-settings-line', ['Status', 'Security', 'Contact', 'Prefs']]
                ]
            ],
            'student' => [
                'label' => 'Student Portal',
                'avatar' => 'ST',
                'pages' => [
                    'dashboard.php' => ['Dashboard', 'Classes, assignments, grades, attendance, and notices.', 'ri-dashboard-3-line', ['Classes', 'Assignments', 'Attendance', 'Messages']],
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
                    'dashboard.php' => ['Dashboard', 'Children, attendance, fees, grades, and school updates.', 'ri-dashboard-3-line', ['Children', 'Attendance', 'Fees', 'Notices']],
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
                    'dashboard.php' => ['Dashboard', 'Operations, approvals, attendance, and messages.', 'ri-dashboard-3-line', ['Tasks', 'Approvals', 'Messages', 'Schedule']],
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

$allRoles = rp_role_pages();
$roleConfig = $allRoles[$portalRole] ?? $allRoles['student'];
$pages = $roleConfig['pages'];
$pageConfig = $pages[$portalPageKey] ?? $pages['dashboard.php'];
$schoolName = $school['name'] ?? ($auth['school_name'] ?? 'School Portal');
$userName = $auth['user_name'] ?? ($auth['name'] ?? 'Portal User');
$userEmail = $auth['user_email'] ?? ($auth['email'] ?? '');
$roleLabel = $roleConfig['label'];
$logoutUrl = function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug)
    ? '/logout.php'
    : '/tenant/logout.php' . ($schoolSlug ? '?school_slug=' . urlencode($schoolSlug) : '');
$metricValues = ['Rate' => '94%', 'Average' => '81%', 'Outstanding' => 'NGN 0', 'Net Pay' => 'Ready', 'Security' => 'Good', 'Status' => 'Complete', 'Admission' => 'Active', 'Dept.' => 'Office'];
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo rp_h($pageConfig[0]); ?> | <?php echo rp_h($schoolName); ?></title>
    <link rel="icon" type="image/png" href="<?php echo rp_h($assetUrl); ?>images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/remixicon.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/apexcharts.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/dataTables.min.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/full-calendar.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/lib/calendar.css">
    <link rel="stylesheet" href="<?php echo rp_h($assetUrl); ?>css/style.css">
    <style>
        .sidebar-menu a.active-page { background: var(--primary-50); color: var(--primary-600); font-weight: 700; }
        .portal-empty-state { min-height: 180px; border: 1px dashed var(--neutral-300); }
        .portal-stat-icon { width: 44px; height: 44px; }
    </style>
</head>
<body>
<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<aside class="sidebar">
    <button type="button" class="sidebar-close-btn"><iconify-icon icon="radix-icons:cross-2"></iconify-icon></button>
    <div class="sidebar-logo d-flex align-items-center justify-content-between">
        <a href="<?php echo rp_h($baseUrl . 'dashboard.php'); ?>">
            <img src="<?php echo rp_h($assetUrl); ?>images/logo.png" alt="site logo" class="light-logo">
            <img src="<?php echo rp_h($assetUrl); ?>images/logo-light.png" alt="site logo" class="dark-logo">
            <img src="<?php echo rp_h($assetUrl); ?>images/logo-icon.png" alt="site logo" class="logo-icon">
        </a>
        <button type="button" class="text-xxl d-xl-flex d-none line-height-1 sidebar-toggle text-neutral-500" aria-label="Collapse Sidebar">
            <i class="ri-contract-left-line"></i>
        </button>
    </div>

    <div class="mx-16 py-12">
        <div class="profile-dropdown__button d-flex align-items-center justify-content-between p-10 w-100 overflow-hidden bg-neutral-50 radius-12">
            <span class="d-flex align-items-start gap-10">
                <span class="w-40-px h-40-px rounded-circle bg-primary-100 text-primary-600 d-flex align-items-center justify-content-center fw-bold"><?php echo rp_h($roleConfig['avatar']); ?></span>
                <span class="profile-dropdown__contents">
                    <span class="h6 mb-0 text-md d-block text-primary-light"><?php echo rp_h($userName); ?></span>
                    <span class="text-secondary-light text-sm mb-0 d-block"><?php echo rp_h($roleLabel); ?></span>
                </span>
            </span>
        </div>
    </div>

    <div class="sidebar-menu-area">
        <ul class="sidebar-menu" id="sidebar-menu">
            <?php foreach ($pages as $file => $item): ?>
                <li>
                    <a class="<?php echo $file === $portalPageKey ? 'active-page' : ''; ?>" href="<?php echo rp_h($baseUrl . $file); ?>">
                        <i class="<?php echo rp_h($item[2]); ?>"></i>
                        <span><?php echo rp_h($item[0]); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li>
                <a href="<?php echo rp_h($logoutUrl); ?>">
                    <i class="ri-shut-down-line"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</aside>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search <?php echo rp_h($pageConfig[0]); ?>">
                        <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                    </form>
                </div>
            </div>
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark and Light Mode Button"></button>
                    <span class="text-sm text-secondary-light d-none d-md-inline"><?php echo rp_h($userEmail); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h6 class="fw-semibold mb-0"><?php echo rp_h($pageConfig[0]); ?></h6>
                <p class="text-neutral-600 mt-4 mb-0"><?php echo rp_h($roleLabel); ?> -> <?php echo rp_h($pageConfig[1]); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo rp_h($baseUrl . 'messages.php'); ?>" class="btn btn-sm btn-primary-600 radius-8 d-inline-flex align-items-center gap-2"><i class="ri-mail-line"></i> Messages</a>
                <a href="<?php echo rp_h($baseUrl . 'profile.php'); ?>" class="btn btn-sm btn-outline-primary-600 radius-8 d-inline-flex align-items-center gap-2"><i class="ri-user-settings-line"></i> Profile</a>
            </div>
        </div>

        <div class="row gy-4 mb-24">
            <?php foreach ($pageConfig[3] as $index => $label): ?>
                <div class="col-xxl-3 col-sm-6">
                    <div class="card p-20 radius-12 border">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <div>
                                <span class="text-secondary-light text-sm"><?php echo rp_h($label); ?></span>
                                <h6 class="mb-0 mt-6"><?php echo rp_h($metricValues[$label] ?? (string)(($index + 2) * 4)); ?></h6>
                            </div>
                            <span class="portal-stat-icon rounded-circle bg-primary-50 text-primary-600 d-flex justify-content-center align-items-center text-xl">
                                <i class="<?php echo rp_h($pageConfig[2]); ?>"></i>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row gy-4">
            <div class="col-xxl-8">
                <div class="card h-100 radius-12 border">
                    <div class="card-header d-flex align-items-center justify-content-between border-bottom">
                        <h6 class="mb-0"><?php echo rp_h($pageConfig[0]); ?> Workspace</h6>
                        <span class="badge text-sm fw-semibold bg-success-100 text-success-600 radius-8 px-12 py-6">Ready</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table bordered-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Status</th>
                                        <th>Owner</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (['Overview', 'Pending Review', 'Recent Activity', 'Next Step'] as $index => $row): ?>
                                        <tr>
                                            <td><?php echo rp_h($pageConfig[0] . ' ' . $row); ?></td>
                                            <td><span class="badge bg-primary-50 text-primary-600 radius-8 px-12 py-6"><?php echo $index === 0 ? 'Active' : 'Open'; ?></span></td>
                                            <td><?php echo rp_h($roleLabel); ?></td>
                                            <td><a href="javascript:void(0)" class="text-primary-600 fw-semibold">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xxl-4">
                <div class="card h-100 radius-12 border">
                    <div class="card-header border-bottom">
                        <h6 class="mb-0">Quick Actions</h6>
                    </div>
                    <div class="card-body d-grid gap-3">
                        <a href="<?php echo rp_h($baseUrl . 'dashboard.php'); ?>" class="btn btn-outline-primary-600 radius-8 d-flex align-items-center justify-content-center gap-2"><i class="ri-dashboard-3-line"></i> Dashboard</a>
                        <a href="<?php echo rp_h($baseUrl . 'calendar.php'); ?>" class="btn btn-outline-primary-600 radius-8 d-flex align-items-center justify-content-center gap-2"><i class="ri-calendar-event-line"></i> Calendar</a>
                        <a href="<?php echo rp_h($baseUrl . 'messages.php'); ?>" class="btn btn-outline-primary-600 radius-8 d-flex align-items-center justify-content-center gap-2"><i class="ri-mail-line"></i> Messages</a>
                        <div class="portal-empty-state radius-12 d-flex flex-column align-items-center justify-content-center text-center p-24">
                            <i class="<?php echo rp_h($pageConfig[2]); ?> text-primary-600 text-3xl mb-12"></i>
                            <p class="mb-0 text-secondary-light"><?php echo rp_h($pageConfig[0]); ?> is ready for live school data.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="<?php echo rp_h($assetUrl); ?>js/lib/jquery-3.7.1.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/lib/bootstrap.bundle.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/lib/apexcharts.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/lib/iconify-icon.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/lib/dataTables.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/lib/jquery-ui.min.js"></script>
<script src="<?php echo rp_h($assetUrl); ?>js/app.js"></script>
</body>
</html>
