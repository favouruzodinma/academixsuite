<?php
if (!isset($adminUser)) {
    $adminUser = $_SESSION['school_user'] ?? $GLOBALS['SCHOOL_AUTH'] ?? ['name' => 'Admin', 'role_name' => 'Administrator'];
}
if (!isset($schoolSlug)) {
    $schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
}
if (!isset($school) || !is_array($school)) {
    $school = $GLOBALS['SCHOOL_DATA'] ?? ($_SESSION['school_info'][$schoolSlug] ?? []);
}
if (!isset($currentPage)) {
    $currentPage = $GLOBALS['CURRENT_PAGE'] ?? basename($_SERVER['PHP_SELF']);
}

if (!function_exists('academix_sidebar_asset_url')) {
    function academix_sidebar_asset_url($path, $fallback = '') {
        $path = trim((string) $path);
        if ($path === '') {
            $path = $fallback;
        }
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('academix_sidebar_user_name')) {
    function academix_sidebar_user_name($user) {
        $name = trim((string) ($user['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) ($user['full_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim(trim((string) ($user['first_name'] ?? '')) . ' ' . trim((string) ($user['last_name'] ?? '')));
        return $name !== '' ? $name : 'Admin User';
    }
}

$schoolName = trim((string) ($school['name'] ?? 'School Portal'));
// Prefer the centralized helper (school logo from DB → AcademixSuite logo fallback).
$schoolLogo = function_exists('school_logo_url')
    ? school_logo_url($school)
    : academix_sidebar_asset_url($school['logo_path'] ?? '', '/tenant/assets/images/logo-icon.png');
$adminName = academix_sidebar_user_name($adminUser);
$adminRole = trim((string) ($adminUser['role_name'] ?? $adminUser['role'] ?? 'Administrator'));
$adminAvatar = academix_sidebar_asset_url(
    $adminUser['avatar'] ?? ($adminUser['profile_photo'] ?? ''),
    '/tenant/assets/images/thumbs/leave-request-img2.png'
);
?>
<style>
    .sidebar-logo .school-brand-link {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .sidebar-logo img.school-brand-logo,
    .sidebar-logo img.school-brand-logo-icon {
        width: 44px;
        height: 44px;
        min-width: 44px;
        object-fit: contain;
        border-radius: 12px;
        background: #fff;
        border: 1px solid var(--neutral-200);
        padding: 4px;
    }
    .sidebar-logo .school-brand-name {
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--text-primary-light);
        font-weight: 700;
        line-height: 1.2;
    }
    .sidebar.active .sidebar-logo .school-brand-name {
        display: none;
    }
    .sidebar.active:hover .sidebar-logo .school-brand-name {
        display: inline-block;
    }
</style>
<aside class="sidebar">
    <button type="button" class="sidebar-close-btn">
        <i class="ri-close-line"></i>
    </button>
    <div class="">
        <div class="sidebar-logo d-flex align-items-center justify-content-between">
            <a href="index.php" class="school-brand-link">
                <img src="<?php echo htmlspecialchars($schoolLogo); ?>" alt="<?php echo htmlspecialchars($schoolName); ?> logo" class="light-logo school-brand-logo">
                <img src="<?php echo htmlspecialchars($schoolLogo); ?>" alt="<?php echo htmlspecialchars($schoolName); ?> logo" class="dark-logo school-brand-logo">
                <img src="<?php echo htmlspecialchars($schoolLogo); ?>" alt="<?php echo htmlspecialchars($schoolName); ?> logo" class="logo-icon school-brand-logo-icon">
                <span class="school-brand-name"><?php echo htmlspecialchars($schoolName); ?></span>
            </a>
            <button type="button" class="text-xxl d-xl-flex d-none line-height-1 sidebar-toggle text-neutral-500" aria-label="Collapse Sidebar">
                <i class="ri-contract-left-line"></i>
            </button>
        </div>
    </div>
    <div class="mx-16 py-12">
        <div class="dropdown profile-dropdown">
            <button type="button" class="profile-dropdown__button d-flex align-items-center justify-content-between p-10 w-100 overflow-hidden bg-neutral-50 radius-12" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                <span class="d-flex align-items-start gap-10">
                    <img src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="<?php echo htmlspecialchars($adminName); ?>" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                    <span class="profile-dropdown__contents">
                        <span class="h6 mb-0 text-md d-block text-primary-light"><?php echo htmlspecialchars($adminName); ?></span>
                        <span class="text-secondary-light text-sm mb-0 d-block"><?php echo htmlspecialchars($adminRole); ?></span>
                    </span>
                </span>
                <span class="profile-dropdown__icon pe-8 text-xl d-flex line-height-1">
                    <i class="ri-arrow-right-s-line"></i>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                <li>
                    <a href="profile.php" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                        <i class="ri-user-3-line"></i>
                        My Profile
                    </a>
                </li>
                <li>
                    <a href="general.php" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                        <i class="ri-settings-3-line"></i>
                        Settings
                    </a>
                </li>
                <li>
                    <a href="../../logout.php?school_slug=<?php echo urlencode($schoolSlug); ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                        <i class="ri-shut-down-line"></i>
                        Log Out
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <div class="sidebar-menu-area">
        <ul class="sidebar-menu" id="sidebar-menu">
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-home-4-line"></i>
                    <span>Dashboard</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="index.php" <?php echo $currentPage === 'index.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            School
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-graduation-cap-line"></i>
                    <span>Students</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="add-new-student.php" <?php echo $currentPage === 'add-new-student.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Add New Student
                        </a>
                    </li>
                    <li>
                        <a href="student-list.php" <?php echo in_array($currentPage, ['student-list.php', 'students.php']) ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Student List
                        </a>
                    </li>
                    <li>
                        <a href="student-category.php" <?php echo $currentPage === 'student-category.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Student Categories
                        </a>
                    </li>
                    <li>
                        <a href="suspended-student.php" <?php echo $currentPage === 'suspended-student.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Suspended Students
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-user-follow-line"></i>
                    <span>Teachers</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="add-new-teacher.php" <?php echo $currentPage === 'add-new-teacher.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Add New Teacher
                        </a>
                    </li>
                    <li>
                        <a href="teacher-list.php" <?php echo $currentPage === 'teacher-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Teacher List
                        </a>
                    </li>
                    <li>
                        <a href="teacher-attendance.php" <?php echo $currentPage === 'teacher-attendance.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Attendance
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-user-heart-line"></i>
                    <span>Guardians</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="add-new-guardian.php" <?php echo $currentPage === 'add-new-guardian.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Add New Guardian
                        </a>
                    </li>
                    <li>
                        <a href="guardian-list.php" <?php echo $currentPage === 'guardian-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Guardian List
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-list-view"></i>
                    <span>Classes</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="class-list.php" <?php echo $currentPage === 'class-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Class List
                        </a>
                    </li>
                    <li>
                        <a href="class-room-list.php" <?php echo $currentPage === 'class-room-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Class Rooms
                        </a>
                    </li>
                    <li>
                        <a href="section-list.php" <?php echo $currentPage === 'section-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Sections
                        </a>
                    </li>
                    <li>
                        <a href="subject-list.php" <?php echo $currentPage === 'subject-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Subjects
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-file-edit-line"></i>
                    <span>Examinations</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="exam.php" <?php echo $currentPage === 'exam.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Exams
                        </a>
                    </li>
                    <li>
                        <a href="exam-schedule.php" <?php echo $currentPage === 'exam-schedule.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Exam Schedule
                        </a>
                    </li>
                    <li>
                        <a href="exam-result.php" <?php echo $currentPage === 'exam-result.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Exam Results
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-money-dollar-circle-line"></i>
                    <span>Fees Collection</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="fees-collect.php" <?php echo $currentPage === 'fees-collect.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Collect
                        </a>
                    </li>
                    <li>
                        <a href="fees-type.php" <?php echo $currentPage === 'fees-type.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Type
                        </a>
                    </li>
                    <li>
                        <a href="fees-group.php" <?php echo $currentPage === 'fees-group.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Group
                        </a>
                    </li>
                    <li>
                        <a href="fees-discount.php" <?php echo $currentPage === 'fees-discount.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Discount
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-calendar-check-line"></i>
                    <span>Attendance</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="student-attendance.php" <?php echo $currentPage === 'student-attendance.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Student Attendance
                        </a>
                    </li>
                    <li>
                        <a href="employee-attendance.php" <?php echo $currentPage === 'employee-attendance.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Employee Attendance
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-book-2-line"></i>
                    <span>Library</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="books-list.php" <?php echo $currentPage === 'books-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Books List
                        </a>
                    </li>
                    <li>
                        <a href="issue-return.php" <?php echo $currentPage === 'issue-return.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Issue & Return
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-bank-line"></i>
                    <span>Accounts</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="income-head.php" <?php echo $currentPage === 'income-head.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Income Head
                        </a>
                    </li>
                    <li>
                        <a href="income-list.php" <?php echo $currentPage === 'income-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Income List
                        </a>
                    </li>
                    <li>
                        <a href="expense-head.php" <?php echo $currentPage === 'expense-head.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Expense Head
                        </a>
                    </li>
                    <li>
                        <a href="expense-list.php" <?php echo $currentPage === 'expense-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Expense List
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-team-line"></i>
                    <span>Human Resource</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="department.php" <?php echo $currentPage === 'department.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Departments
                        </a>
                    </li>
                    <li>
                        <a href="designation.php" <?php echo $currentPage === 'designation.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Designations
                        </a>
                    </li>
                    <li>
                        <a href="employee-list.php" <?php echo $currentPage === 'employee-list.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Employee List
                        </a>
                    </li>
                </ul>
            </li>
            <li>
                <a href="notice-board.php" <?php echo $currentPage === 'notice-board.php' ? 'class="active"' : ''; ?>>
                    <i class="ri-booklet-line"></i>
                    <span>Notice Board</span>
                </a>
            </li>
            <li>
                <a href="event.php" <?php echo $currentPage === 'event.php' ? 'class="active"' : ''; ?>>
                    <i class="ri-calendar-event-line"></i>
                    <span>Events</span>
                </a>
            </li>
            <li>
                <a href="message.php" <?php echo $currentPage === 'message.php' ? 'class="active"' : ''; ?>>
                    <i class="ri-message-2-line"></i>
                    <span>Messages</span>
                </a>
            </li>
            <li>
                <a href="notification.php" <?php echo $currentPage === 'notification.php' ? 'class="active"' : ''; ?>>
                    <i class="ri-notification-3-line"></i>
                    <span>Notifications</span>
                </a>
            </li>
            <li>
                <a href="crud.php" <?php echo $currentPage === 'crud.php' ? 'class="active"' : ''; ?>>
                    <i class="ri-database-2-line"></i>
                    <span>Data Manager</span>
                </a>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-settings-3-line"></i>
                    <span>Settings</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="general.php" <?php echo $currentPage === 'general.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            General Settings
                        </a>
                    </li>
                    <li>
                        <a href="role-access.php" <?php echo $currentPage === 'role-access.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Role & Access
                        </a>
                    </li>
                    <li>
                        <a href="currencies.php" <?php echo $currentPage === 'currencies.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Currencies
                        </a>
                    </li>
                    <li>
                        <a href="languages.php" <?php echo $currentPage === 'languages.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Languages
                        </a>
                    </li>
                    <li>
                        <a href="subscription-plan.php" <?php echo $currentPage === 'subscription-plan.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Subscription Plan
                        </a>
                    </li>
                    <li>
                        <a href="certificate.php" <?php echo $currentPage === 'certificate.php' ? 'class="active"' : ''; ?>>
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Certificate
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</aside>
