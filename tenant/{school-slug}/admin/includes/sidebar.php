<?php
if (!defined('ACADEMIX_SCHOOL_ADMIN_BOOTSTRAPPED')) {
    require_once __DIR__ . '/admin-bootstrap.php';
}

$sidebarSchool = $school ?? ($GLOBALS['SCHOOL_DATA'] ?? []);
$sidebarSchoolSlug = $schoolSlug ?? ($GLOBALS['SCHOOL_SLUG'] ?? '');
$sidebarSchoolLogo = $schoolLogoUrl ?? (function_exists('school_logo_url') ? school_logo_url($sidebarSchool) : 'https://academixsuite.com/tenant/assets/images/logo.png');
$sidebarSchoolName = $sidebarSchool['name'] ?? 'School Portal';
$sidebarAdmin = $adminUser ?? [];
$sidebarAdminName = $sidebarAdmin['name'] ?? ($sidebarAdmin['user_name'] ?? ($_SESSION['school_auth']['user_name'] ?? 'Admin User'));
$sidebarAdminRole = $sidebarAdmin['role_name'] ?? ($_SESSION['school_auth']['role_name'] ?? 'Administrator');
$sidebarAvatar = $sidebarAdmin['avatar'] ?? ($sidebarAdmin['profile_photo'] ?? '');
if ($sidebarAvatar === '') {
    $sidebarAvatar = 'https://academixsuite.com/tenant/assets/images/thumbs/leave-request-img2.png';
}
$sidebarLogoutUrl = function_exists('academix_admin_logout_url') ? academix_admin_logout_url() : '../../logout.php?school_slug=' . urlencode($sidebarSchoolSlug);
?>
<aside class="sidebar">
    <button type="button" class="sidebar-close-btn">
        <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
    </button>
    <div class="">
        <div class="sidebar-logo d-flex align-items-center justify-content-between">
            <a href="index.php" class="d-flex align-items-center gap-10 text-decoration-none min-w-0">
                <img src="<?php echo htmlspecialchars($sidebarSchoolLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($sidebarSchoolName, ENT_QUOTES, 'UTF-8'); ?> logo" class="light-logo" style="max-height: 44px; width: auto; object-fit: contain;">
                <img src="<?php echo htmlspecialchars($sidebarSchoolLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($sidebarSchoolName, ENT_QUOTES, 'UTF-8'); ?> logo" class="dark-logo" style="max-height: 44px; width: auto; object-fit: contain;">
                <img src="<?php echo htmlspecialchars($sidebarSchoolLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($sidebarSchoolName, ENT_QUOTES, 'UTF-8'); ?> logo" class="logo-icon" style="max-height: 40px; width: 40px; object-fit: contain;">
                <span class="sidebar-school-name text-primary-light fw-semibold text-sm text-truncate d-none d-xl-inline-block" style="max-width: 150px;"><?php echo htmlspecialchars($sidebarSchoolName, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <button type="button" class="text-xxl d-xl-flex d-none line-height-1 sidebar-toggle text-neutral-500" aria-label="Collapse Sidebar">
                <i class="ri-contract-left-line"></i>
            </button>
        </div>
    </div>
    <!-- User Info start -->
    <div class="mx-16 py-12">
        <div class="dropdown profile-dropdown">
            <button type="button" class="profile-dropdown__button d-flex align-items-center justify-content-between p-10 w-100 overflow-hidden bg-neutral-50 radius-12" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                <span class="d-flex align-items-start gap-10">
                    <img src="<?php echo htmlspecialchars($sidebarAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="Admin profile photo" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                    <span class="profile-dropdown__contents">
                        <span class="h6 mb-0 text-md d-block text-primary-light"><?php echo htmlspecialchars($sidebarAdminName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-secondary-light text-sm mb-0 d-block"><?php echo htmlspecialchars($sidebarAdminRole, ENT_QUOTES, 'UTF-8'); ?></span>
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
                    <a href="<?php echo htmlspecialchars($sidebarLogoutUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                        <i class="ri-shut-down-line"></i>
                        Log Out
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <!-- User Info end -->
    <div class="sidebar-menu-area">
        <ul class="sidebar-menu" id="sidebar-menu">
            <li class="dropdown">
                <a href="index.php">
                    <i class="ri-home-4-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-graduation-cap-line"></i>
                    <span>Students</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="add-new-student.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Add New Student
                        </a>
                    </li>
                    <li>
                        <a href="student-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Student List
                        </a>
                    </li>
                    <!-- Student Promotion can be added if needed -->
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-user-follow-line"></i>
                    <span>Teachers</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="add-new-teacher.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Add New Teacher
                        </a>
                    </li>
                    <li>
                        <a href="teacher-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Teacher List
                        </a>
                    </li>
                    <li>
                        <a href="add-timetable.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Teacher Timetable
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-account-circle-line"></i>
                    <span>Guardian</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="add-new-guardian.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Add New Guardians
                        </a>
                    </li>
                    <li>
                        <a href="guardian-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Guardians List
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
                        <a href="class-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Class List
                        </a>
                    </li>
                    <li>
                        <a href="subject-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Subjects
                        </a>
                    </li>
                    <li>
                        <a href="section-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Sections
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
                        <a href="exam.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Exams
                        </a>
                    </li>
                    <li>
                        <a href="exam-schedule.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Exam Schedule
                        </a>
                    </li>
                    <li>
                        <a href="exam-result.php">
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
                        <a href="fees-collect.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Collect
                        </a>
                    </li>
                    <li>
                        <a href="fees-type.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Type
                        </a>
                    </li>
                    <li>
                        <a href="fees-group.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Fees Group
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
                        <a href="student-attendance.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Student Attendance
                        </a>
                    </li>
                    <li>
                        <a href="teacher-attendance.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Teacher Attendance
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-time-line"></i>
                    <span>Leaves</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="leave-types.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Leave Types
                        </a>
                    </li>
                    <li>
                        <a href="leave-requests.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Leave Requests
                        </a>
                    </li>
                </ul>
            </li>
            <li>
                <a href="certificate.php">
                    <i class="ri-award-line"></i>
                    <span>Certificates</span>
                </a>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-book-2-line"></i>
                    <span>Library</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="books-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Books List
                        </a>
                    </li>
                    <li>
                        <a href="members-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Members List
                        </a>
                    </li>
                    <li>
                        <a href="member-details.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Members Details
                        </a>
                    </li>
                    <li>
                        <a href="issue-return.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Issue Return
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-money-dollar-circle-line"></i>
                    <span>Accounts</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="income-head.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Income Head
                        </a>
                    </li>
                    <li>
                        <a href="income-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Income List
                        </a>
                    </li>
                    <li>
                        <a href="expense-head.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Expense Head
                        </a>
                    </li>
                    <li>
                        <a href="expense-list.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Expense List
                        </a>
                    </li>
                    <li>
                        <a href="transaction.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Transaction
                        </a>
                    </li>
                </ul>
            </li>
            <li class="dropdown">
    <a href="javascript:void(0)">
        <i class="ri-user-settings-line"></i>
        <span>HRM</span>
    </a>
    <ul class="sidebar-submenu">
        <li><a href="employee-list.php"><i class="ri-circle-fill circle-icon w-auto"></i> Employee List</a></li>
        <li><a href="employee-details.php"><i class="ri-circle-fill circle-icon w-auto"></i> Employee Details</a></li>
        <li><a href="add-new-employee.php"><i class="ri-circle-fill circle-icon w-auto"></i> Add New Employee</a></li>
        <li><a href="payroll.php"><i class="ri-circle-fill circle-icon w-auto"></i> Payroll</a></li>
        <li><a href="salary-grades.php"><i class="ri-circle-fill circle-icon w-auto"></i> Salary Grades</a></li>
        <li><a href="payroll-periods.php"><i class="ri-circle-fill circle-icon w-auto"></i> Payroll Periods</a></li>
        <li><a href="payroll-runs.php"><i class="ri-circle-fill circle-icon w-auto"></i> Payroll Runs</a></li>
    </ul>
</li>
            <li>
                <a href="notice-board.php">
                    <i class="ri-booklet-line"></i>
                    <span>Notice Board</span>
                </a>
            </li>
            <li>
                <a href="event.php">
                    <i class="ri-calendar-event-line"></i>
                    <span>Events</span>
                </a>
            </li>
            <li>
                <a href="message.php">
                    <i class="ri-message-2-line"></i>
                    <span>Message</span>
                </a>
            </li>
            <!-- Subscription Plan (maybe keep or remove if not needed) -->
            <li>
                <a href="subscription-plan.php">
                    <i class="ri-price-tag-3-line"></i>
                    <span>Subscription Plan</span>
                </a>
            </li>
            <li>
                <a href="role-access.php">
                    <i class="ri-macbook-line"></i>
                    <span>Role & Access</span>
                </a>
            </li>
            <li class="dropdown">
                <a href="javascript:void(0)">
                    <i class="ri-settings-3-line"></i>
                    <span>Settings</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="general.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            General
                        </a>
                    </li>
                    <li>
                        <a href="school-profile.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            School Profile
                        </a>
                    </li>
                    <li>
                        <a href="notification.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Notification
                        </a>
                    </li>
                    <li>
                        <a href="currencies.php">
                            <i class="ri-circle-fill circle-icon w-auto"></i>
                            Currencies
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</aside>

<?php
// ── Onboarding guide ─────────────────────────────────────────────────────────
$_obGuidePath = __DIR__ . '/onboarding-guide.php';
if (file_exists($_obGuidePath)) {
    require_once $_obGuidePath;  // sets $onboardingActive, $onboardingSteps, $onboardingPercent
}
// Expose to JS
$_onboardingActive  = !empty($onboardingActive);
$_onboardingStepsJs = json_encode($onboardingSteps  ?? [], JSON_HEX_TAG);
$_onboardingPercent = (int)($onboardingPercent ?? 0);
$_obFirstVisit      = !empty($GLOBALS['onboarding_first_visit']);

// ── AI Assistant – floating chat bubble ──────────────────────────────────────
// Rendered once here so it appears on every admin page without touching each
// individual page file. Communicates with ai_assistant.php via fetch().

// Build the AJAX URL relative to the current admin page depth.
// All admin pages sit at: tenant/{school-slug}/admin/*.php  (depth = 3 from root)
$_aiSlug   = $sidebarSchoolSlug ?? ($GLOBALS['SCHOOL_SLUG'] ?? '');
if (empty($_SESSION['ai_csrf_token'])) {
    $_SESSION['ai_csrf_token'] = bin2hex(random_bytes(32));
}
$_aiCsrf = $_SESSION['ai_csrf_token'];
?>

<!-- ══ AI Assistant Styles ══════════════════════════════════════════════════ -->
<style>
#academix-ai-bubble {
    position: fixed; bottom: 28px; right: 28px; z-index: 9999;
    width: 56px; height: 56px; border-radius: 50%;
    background: linear-gradient(135deg,#25A194,#1a7a70);
    box-shadow: 0 4px 18px rgba(37,161,148,.45);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; border: none; transition: transform .2s,box-shadow .2s;
}
#academix-ai-bubble:hover { transform: scale(1.1); box-shadow: 0 6px 24px rgba(37,161,148,.55); }
#academix-ai-bubble { color: #fff; }
#academix-ai-bubble svg { width:28px;height:28px; }
#academix-ai-bubble .ai-badge {
    position:absolute;top:-3px;right:-3px;background:#ef4444;
    color:#fff;font-size:10px;font-weight:700;border-radius:99px;
    padding:1px 5px;display:none;
}

#academix-ai-panel {
    position:fixed;bottom:96px;right:28px;z-index:9998;
    width:380px;max-width:calc(100vw - 40px);
    background:#fff;border-radius:18px;
    box-shadow:0 8px 40px rgba(0,0,0,.18);
    display:none;flex-direction:column;overflow:hidden;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
}
#academix-ai-panel.is-open { display:flex; }

.ai-panel-header {
    background:linear-gradient(135deg,#25A194,#1a7a70);
    padding:14px 18px;display:flex;align-items:center;gap:10px;color:#fff;
}
.ai-panel-header .ai-avatar {
    width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.ai-panel-header .ai-avatar svg { width:20px;height:20px;fill:#fff; }
.ai-panel-header .ai-title { font-size:15px;font-weight:700;line-height:1.2; }
.ai-panel-header .ai-sub   { font-size:11px;opacity:.8; }
.ai-panel-header .ai-close {
    margin-left:auto;background:none;border:none;color:#fff;
    font-size:20px;line-height:1;cursor:pointer;padding:0 2px;opacity:.8;
}
.ai-panel-header .ai-close:hover { opacity:1; }

.ai-messages {
    flex:1;overflow-y:auto;padding:16px;display:flex;
    flex-direction:column;gap:10px;max-height:340px;min-height:160px;
    background:#f8fafc;
}
.ai-msg { max-width:85%;padding:10px 14px;border-radius:14px;font-size:13.5px;line-height:1.55; }
.ai-msg.user {
    align-self:flex-end;background:linear-gradient(135deg,#25A194,#1a7a70);
    color:#fff;border-bottom-right-radius:4px;
}
.ai-msg.assistant {
    align-self:flex-start;background:#fff;color:#1e293b;
    border:1px solid #e2e8f0;border-bottom-left-radius:4px;
}
.ai-msg.assistant .ai-tool-tag {
    display:inline-block;background:#dcfce7;color:#166534;
    font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;margin-bottom:5px;
}
.ai-typing { display:flex;gap:4px;align-items:center;padding:10px 14px; }
.ai-typing span {
    width:7px;height:7px;border-radius:50%;background:#94a3b8;
    animation:ai-bounce .8s infinite; display:inline-block;
}
.ai-typing span:nth-child(2) { animation-delay:.15s; }
.ai-typing span:nth-child(3) { animation-delay:.30s; }
@keyframes ai-bounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-6px)} }

.ai-quick-actions {
    display:flex;flex-wrap:wrap;gap:6px;padding:10px 16px 4px;background:#f8fafc;
    border-top:1px solid #f1f5f9;
}
.ai-quick-actions button {
    font-size:11px;background:#fff;border:1px solid #e2e8f0;
    color:#475569;border-radius:99px;padding:4px 10px;cursor:pointer;
    transition:background .15s,border-color .15s;white-space:nowrap;
}
.ai-quick-actions button:hover { background:#25A194;border-color:#25A194;color:#fff; }

.ai-input-row {
    display:flex;gap:8px;align-items:flex-end;
    padding:12px 14px;border-top:1px solid #e2e8f0;background:#fff;
}
.ai-input-row textarea {
    flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:9px 12px;
    font-size:13.5px;resize:none;outline:none;max-height:100px;
    font-family:inherit;line-height:1.5;background:#f8fafc;
    transition:border-color .15s;
}
.ai-input-row textarea:focus { border-color:#25A194;background:#fff; }
.ai-input-row button {
    width:38px;height:38px;border-radius:50%;
    background:linear-gradient(135deg,#25A194,#1a7a70);
    border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;
    flex-shrink:0;transition:opacity .15s;
}
.ai-input-row button:disabled { opacity:.45;cursor:default; }
.ai-input-row button svg { width:18px;height:18px;fill:#fff; }
</style>

<!-- ══ AI Assistant HTML ═════════════════════════════════════════════════════ -->
<button id="academix-ai-bubble" title="AcademixAI — click to chat" aria-label="Open AI Assistant">
    <!-- Remix Icons: ri-robot-2-fill -->
    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2.05V4H17a2 2 0 0 1 2 2v2h1a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-1v2a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h1V6a2 2 0 0 1 2-2h4V2.05a1 1 0 1 1 2 0ZM9.5 11a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM10 16h4v1h-4v-1Z"/></svg>
    <span class="ai-badge" id="ai-unread-badge">1</span>
</button>

<div id="academix-ai-panel" role="dialog" aria-label="AI Assistant">
    <div class="ai-panel-header">
        <div class="ai-avatar">
            <svg viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 0 2h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1 0-2h1a7 7 0 0 1 7-7h1V5.73A2 2 0 0 1 10 4a2 2 0 0 1 2-2zm-4 9a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm8 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>
        </div>
        <div>
            <div class="ai-title">AcademixAI</div>
            <div class="ai-sub">Powered by Groq &bull; Your school assistant</div>
        </div>
        <button class="ai-close" id="ai-panel-close" aria-label="Close">&times;</button>
    </div>

    <div class="ai-messages" id="ai-messages">
        <div class="ai-msg assistant">
            👋 Hi! I'm your AI school assistant. I can help you create announcements, schedule events, check fees, and more.<br><br>What would you like to do today?
        </div>
    </div>

    <div class="ai-quick-actions" id="ai-quick-actions">
        <button data-prompt="Create a general announcement for all students">📢 New Notice</button>
        <button data-prompt="Create a new school event">📅 Add Event</button>
        <button data-prompt="Create a new class">🏫 New Class</button>
        <button data-prompt="Create a new subject">📘 New Subject</button>
        <button data-prompt="Send a WhatsApp message to parents">💬 WhatsApp</button>
        <button data-prompt="Show me the fee payment summary">💰 Fee Summary</button>
        <button data-prompt="Send fee payment reminders to parents with unpaid invoices">⏰ Fee Reminders</button>
        <button data-prompt="Send attendance alerts to parents for absent students today">✅ Attendance Alerts</button>
        <button data-prompt="Give me a student enrolment report">👥 Students</button>
    </div>

    <div class="ai-input-row">
        <textarea id="ai-input" rows="1" placeholder="Ask me anything…" aria-label="Message input"></textarea>
        <button id="ai-send" aria-label="Send">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </div>
</div>

<!-- ══ AI Assistant Script ═══════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    const ENDPOINT   = 'ai_assistant.php';
    const CSRF_TOKEN = '<?php echo htmlspecialchars($_aiCsrf, ENT_QUOTES); ?>';

    const bubble   = document.getElementById('academix-ai-bubble');
    const panel    = document.getElementById('academix-ai-panel');
    const closeBtn = document.getElementById('ai-panel-close');
    const msgBox   = document.getElementById('ai-messages');
    const inputEl  = document.getElementById('ai-input');
    const sendBtn  = document.getElementById('ai-send');
    const badge    = document.getElementById('ai-unread-badge');
    const quickAct = document.getElementById('ai-quick-actions');

    // Conversation history sent to the backend each turn
    let history = [];
    let isOpen  = false;

    // ── Panel open / close ────────────────────────────────────────────────
    function openPanel() {
        if (!panel) return;
        panel.classList.add('is-open');
        isOpen = true;
        if (badge) badge.style.display = 'none';
        if (inputEl) inputEl.focus();
    }
    function closePanel() {
        if (!panel) return;
        panel.classList.remove('is-open');
        isOpen = false;
    }

    if (bubble) {
        bubble.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            isOpen ? closePanel() : openPanel();
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            closePanel();
        });
    }

    // Close on outside click
    document.addEventListener('click', e => {
        if (!isOpen) return;
        if (panel && panel.contains(e.target)) return;
        if (bubble && bubble.contains(e.target)) return;
        closePanel();
    });

    // ── Auto-resize textarea ──────────────────────────────────────────────
    if (inputEl) {
        inputEl.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
    }

    if (sendBtn) sendBtn.addEventListener('click', sendMessage);

    // ── Quick-action buttons ──────────────────────────────────────────────
    if (quickAct) quickAct.querySelectorAll('[data-prompt]').forEach(btn => {
        btn.addEventListener('click', function () {
            inputEl.value = this.dataset.prompt;
            openPanel();
            sendMessage();
        });
    });

    // ── Core send function ────────────────────────────────────────────────
    function sendMessage() {
        if (!inputEl || !sendBtn) return;
        const text = inputEl.value.trim();
        if (!text || sendBtn.disabled) return;

        // Hide quick actions after first real message
        if (quickAct) quickAct.style.display = 'none';

        appendMsg('user', escHtml(text));
        history.push({ role: 'user', content: text });
        inputEl.value = '';
        inputEl.style.height = 'auto';

        setLoading(true);

        fetch(ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                messages:   JSON.stringify(history),
                csrf_token: CSRF_TOKEN,
            }),
        })
        .then(r => r.json())
        .then(res => {
            removeTyping();
            if (res.success) {
                // Check if any tool call returned an email draft
                const emailDraft = (res.tool_calls_made || [])
                    .map(t => { try { return JSON.parse(t.result); } catch(e) { return null; } })
                    .find(r => r && r.__type === 'email_draft');

                const toolTags = (res.tool_calls_made || [])
                    .map(t => `<span class="ai-tool-tag">⚡ ${escHtml(t.tool)}</span>`)
                    .join(' ');

                appendMsg('assistant', toolTags + (toolTags ? '<br>' : '') + mdToHtml(res.reply));

                // Render email preview card below the AI message
                if (emailDraft) appendEmailCard(emailDraft);

                // Render navigation cards (onboarding guide tool results)
                (res.tool_calls_made || [])
                    .map(t => { try { return JSON.parse(t.result); } catch(e) { return null; } })
                    .filter(r => r && r.__type === 'navigation')
                    .forEach(nav => typeof window.appendNavCard === 'function' && window.appendNavCard(nav));

                history.push({ role: 'assistant', content: res.reply });
            } else {
                appendMsg('assistant', '❌ ' + escHtml(res.message || 'Something went wrong.'));
            }
        })
        .catch(err => {
            removeTyping();
            appendMsg('assistant', '❌ Network error. Please try again.');
            console.error('AI assistant error:', err);
        })
        .finally(() => setLoading(false));
    }

    // ── DOM helpers ───────────────────────────────────────────────────────
    function appendMsg(role, html) {
        if (!msgBox) return;
        const d = document.createElement('div');
        d.className = 'ai-msg ' + role;
        d.innerHTML = html;
        msgBox.appendChild(d);
        msgBox.scrollTop = msgBox.scrollHeight;

        // Badge if panel closed
        if (!isOpen && role === 'assistant' && badge) {
            badge.style.display = 'block';
        }
    }

    function setLoading(on) {
        if (!sendBtn || !msgBox) return;
        sendBtn.disabled = on;
        if (on) {
            const t = document.createElement('div');
            t.className = 'ai-msg assistant ai-typing';
            t.id = 'ai-typing-indicator';
            t.innerHTML = '<span></span><span></span><span></span>';
            msgBox.appendChild(t);
            msgBox.scrollTop = msgBox.scrollHeight;
        }
    }
    function removeTyping() {
        const t = document.getElementById('ai-typing-indicator');
        if (t) t.remove();
    }

    // ── Minimal markdown → HTML (bold, newlines) ──────────────────────────
    function mdToHtml(text) {
        return escHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g,     '<em>$1</em>')
            .replace(/\n/g,            '<br>');
    }
    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Email draft preview card ──────────────────────────────────────────
    function appendEmailCard(draft) {
        const audienceLabels = {
            all: '👥 Everyone', parents: '👨‍👩‍👧 Parents',
            teachers: '🧑‍🏫 Teachers', staff: '🏫 Staff', students: '🎓 Students'
        };
        const label   = audienceLabels[draft.audience] || draft.audience;
        const count   = draft.recipient_count || 0;
        const cardId  = 'email-card-' + Date.now();

        const card = document.createElement('div');
        card.className = 'ai-email-card';
        card.id = cardId;
        card.innerHTML = `
            <div class="ai-email-card-header">
                <span class="ai-email-icon">✉️</span>
                <span class="ai-email-title">Email Draft</span>
                <span class="ai-email-badge">${escHtml(label)} · ${count} recipient${count !== 1 ? 's' : ''}</span>
            </div>
            <div class="ai-email-fields">
                <label>Subject</label>
                <input type="text" class="ai-email-subject" value="${escHtml(draft.subject)}" placeholder="Email subject">
                <label style="margin-top:8px">Body</label>
                <textarea class="ai-email-body" rows="5" placeholder="Email body…">${escHtml(draft.body_plain)}</textarea>
                <div class="ai-email-audience-row">
                    <label>Send to</label>
                    <select class="ai-email-audience">
                        <option value="all"      ${draft.audience==='all'      ? 'selected':''}>Everyone</option>
                        <option value="parents"  ${draft.audience==='parents'  ? 'selected':''}>Parents</option>
                        <option value="teachers" ${draft.audience==='teachers' ? 'selected':''}>Teachers</option>
                        <option value="staff"    ${draft.audience==='staff'    ? 'selected':''}>Staff</option>
                        <option value="students" ${draft.audience==='students' ? 'selected':''}>Students</option>
                    </select>
                </div>
            </div>
            <div class="ai-email-actions">
                <button class="ai-email-send-btn" data-card="${cardId}">📤 Send Email</button>
                <button class="ai-email-discard-btn" data-card="${cardId}">Discard</button>
            </div>
            <div class="ai-email-status" id="${cardId}-status" style="display:none"></div>
        `;

        msgBox.appendChild(card);
        msgBox.scrollTop = msgBox.scrollHeight;

        // Send button handler
        card.querySelector('.ai-email-send-btn').addEventListener('click', function () {
            const subject  = card.querySelector('.ai-email-subject').value.trim();
            const bodyText = card.querySelector('.ai-email-body').value.trim();
            const audience = card.querySelector('.ai-email-audience').value;
            const statusEl = document.getElementById(cardId + '-status');

            if (!subject || !bodyText) {
                statusEl.style.display = 'block';
                statusEl.className = 'ai-email-status error';
                statusEl.textContent = '⚠️ Subject and body are required.';
                return;
            }

            this.disabled = true;
            this.textContent = 'Sending…';
            statusEl.style.display = 'none';

            fetch(ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    action:     'send_email',
                    subject:    subject,
                    body_html:  bodyText.replace(/\n/g, '<br>'),
                    audience:   audience,
                    csrf_token: CSRF_TOKEN,
                }),
            })
            .then(r => r.json())
            .then(res => {
                statusEl.style.display = 'block';
                if (res.success) {
                    statusEl.className = 'ai-email-status success';
                    statusEl.textContent = '✅ ' + (res.message || 'Email sent!');
                    card.querySelector('.ai-email-actions').style.display = 'none';
                    appendMsg('assistant', '✅ Email sent: ' + escHtml(res.message || ''));
                    history.push({ role: 'assistant', content: 'Email sent: ' + (res.message || '') });
                } else {
                    statusEl.className = 'ai-email-status error';
                    statusEl.textContent = '❌ ' + (res.message || 'Failed to send.');
                    this.disabled = false;
                    this.textContent = '📤 Send Email';
                }
            })
            .catch(() => {
                statusEl.style.display = 'block';
                statusEl.className = 'ai-email-status error';
                statusEl.textContent = '❌ Network error. Try again.';
                this.disabled = false;
                this.textContent = '📤 Send Email';
            });
        });

        // Discard button
        card.querySelector('.ai-email-discard-btn').addEventListener('click', () => card.remove());
    }

    // ── Expose for onboarding integration ────────────────────────────────
    window._academixAI = { appendMsg: appendMsg, openPanel: openPanel };
})();
</script>

<style>
/* ── Email draft card styles ─────────────────────────────────────────── */
.ai-email-card {
    background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
    padding:14px;margin-top:6px;font-size:13px;width:100%;
}
.ai-email-card-header {
    display:flex;align-items:center;gap:6px;margin-bottom:10px;
    font-weight:700;font-size:13px;color:#0f172a;
}
.ai-email-icon { font-size:16px; }
.ai-email-title { flex:1; }
.ai-email-badge {
    background:#dcfce7;color:#166534;font-size:10px;font-weight:700;
    padding:2px 8px;border-radius:99px;
}
.ai-email-fields label {
    display:block;font-size:11px;font-weight:700;color:#475569;
    text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;
}
.ai-email-fields input[type=text],
.ai-email-fields textarea,
.ai-email-fields select {
    width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:7px 10px;
    font-size:13px;font-family:inherit;color:#0f172a;background:#f8fafc;
    box-sizing:border-box;outline:none;resize:vertical;
}
.ai-email-fields input:focus,
.ai-email-fields textarea:focus,
.ai-email-fields select:focus { border-color:#25A194;background:#fff; }
.ai-email-audience-row {
    margin-top:8px;display:flex;align-items:center;gap:8px;
}
.ai-email-audience-row label { margin:0;white-space:nowrap; }
.ai-email-audience-row select { flex:1; }
.ai-email-actions {
    display:flex;gap:8px;margin-top:10px;padding-top:10px;
    border-top:1px solid #f1f5f9;
}
.ai-email-send-btn {
    flex:1;background:linear-gradient(135deg,#25A194,#1a7a70);
    color:#fff;border:none;border-radius:8px;padding:9px 14px;
    font-size:13px;font-weight:700;cursor:pointer;transition:opacity .15s;
}
.ai-email-send-btn:disabled { opacity:.5;cursor:default; }
.ai-email-discard-btn {
    background:#fff;border:1px solid #e2e8f0;color:#64748b;
    border-radius:8px;padding:8px 12px;font-size:12px;cursor:pointer;
}
.ai-email-discard-btn:hover { border-color:#f87171;color:#b91c1c; }
.ai-email-status {
    margin-top:8px;padding:7px 10px;border-radius:8px;font-size:12px;font-weight:600;
}
.ai-email-status.success { background:#dcfce7;color:#166534; }
.ai-email-status.error   { background:#fee2e2;color:#b91c1c; }

/* ── Onboarding overlay ─────────────────────────────────────── */
#ob-overlay {
    position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;
    pointer-events:none;
}
#ob-banner {
    position:fixed;top:0;left:0;right:0;z-index:10001;
    background:linear-gradient(90deg,#25A194,#1a7a70);
    color:#fff;padding:10px 20px;
    display:flex;align-items:center;justify-content:space-between;
    gap:16px;box-shadow:0 2px 12px rgba(0,0,0,.18);
    pointer-events:auto;
    transform:translateY(-100%);
    transition:transform .35s cubic-bezier(.4,0,.2,1);
}
#ob-banner.show { transform:translateY(0); }
#ob-banner-left { display:flex;align-items:center;gap:12px;flex:1;min-width:0; }
#ob-banner-title { font-weight:700;font-size:13px;white-space:nowrap; }
#ob-progress-wrap {
    flex:1;background:rgba(255,255,255,.25);border-radius:99px;height:7px;
    overflow:hidden;min-width:80px;max-width:200px;
}
#ob-progress-bar {
    height:100%;background:#fff;border-radius:99px;
    transition:width .5s ease;
}
#ob-banner-pct { font-size:12px;font-weight:700;opacity:.9;white-space:nowrap; }
#ob-open-guide-btn {
    background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);
    color:#fff;border-radius:8px;padding:5px 14px;font-size:12px;font-weight:600;
    cursor:pointer;white-space:nowrap;transition:background .15s;
}
#ob-open-guide-btn:hover { background:rgba(255,255,255,.3); }
#ob-dismiss-btn {
    background:none;border:none;color:rgba(255,255,255,.7);font-size:18px;
    cursor:pointer;line-height:1;padding:0 4px;
}
#ob-dismiss-btn:hover { color:#fff; }

/* Navigation card rendered inside the chat */
.ai-nav-card {
    background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;
    padding:12px 14px;display:flex;align-items:center;gap:12px;
    margin:4px 0;max-width:100%;
}
.ai-nav-card .anc-icon {
    width:36px;height:36px;border-radius:50%;
    background:linear-gradient(135deg,#25A194,#1a7a70);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-size:16px;flex-shrink:0;
}
.ai-nav-card .anc-body { flex:1;min-width:0; }
.ai-nav-card .anc-label { font-weight:700;font-size:13px;color:#065f46; }
.ai-nav-card .anc-desc  { font-size:12px;color:#047857;margin-top:2px;line-height:1.4; }
.ai-nav-card .anc-btn {
    background:linear-gradient(135deg,#25A194,#1a7a70);
    color:#fff;border:none;border-radius:8px;
    padding:7px 14px;font-size:12px;font-weight:700;
    cursor:pointer;white-space:nowrap;flex-shrink:0;
    text-decoration:none;display:inline-block;
}
.ai-nav-card .anc-btn:hover { opacity:.9; }
</style>

<?php if ($_onboardingActive): ?>
<!-- ══ Onboarding Banner ══════════════════════════════════════════════════════ -->
<div id="ob-banner" role="status" aria-live="polite">
    <div id="ob-banner-left">
        <span id="ob-banner-title">🚀 School Setup</span>
        <div id="ob-progress-wrap" title="<?= $_onboardingPercent ?>% complete">
            <div id="ob-progress-bar" style="width:<?= $_onboardingPercent ?>%"></div>
        </div>
        <span id="ob-banner-pct"><?= $_onboardingPercent ?>%</span>
    </div>
    <button id="ob-open-guide-btn">Open Setup Guide</button>
    <button id="ob-dismiss-btn" aria-label="Dismiss banner">&times;</button>
</div>

<script>
(function () {
    'use strict';

    /* ── Onboarding config (PHP → JS) ─────────────────────────────────────── */
    const OB_ACTIVE      = <?= json_encode($_onboardingActive) ?>;
    const OB_PERCENT     = <?= $_onboardingPercent ?>;
    const OB_FIRST_VISIT = <?= json_encode($_obFirstVisit) ?>;
    const AI_ENDPOINT    = 'ai_assistant.php';
    const AI_CSRF        = <?= json_encode($_aiCsrf) ?>;

    /* ── Helpers — delegate to main IIFE's exposed functions ─────────────── */
    function _appendMsg(role, html) {
        // Map onboarding role names to the main IIFE's role names
        const roleMap = { bot: 'assistant', error: 'assistant' };
        const mappedRole = roleMap[role] || role;
        if (window._academixAI) {
            window._academixAI.appendMsg(mappedRole, html);
        }
    }

    function _openPanel() {
        if (window._academixAI && typeof window._academixAI.openPanel === 'function') {
            window._academixAI.openPanel();
        } else {
            // Direct DOM fallback in case the main IIFE hasn't initialised yet
            var panel = document.getElementById('academix-ai-panel');
            if (panel && !panel.classList.contains('is-open')) {
                panel.classList.add('is-open');
            }
        }
    }

    function _appendTyping() {
        const msgEl = document.getElementById('ai-messages');
        if (!msgEl) return null;
        const el = document.createElement('div');
        el.className = 'ai-msg assistant ai-typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        msgEl.appendChild(el);
        msgEl.scrollTop = msgEl.scrollHeight;
        return el;
    }

    /* ── Show banner on load if onboarding active ─────────────────────────── */
    if (OB_ACTIVE) {
        const banner = document.getElementById('ob-banner');
        if (banner) {
            setTimeout(() => banner.classList.add('show'), 400);

            document.getElementById('ob-dismiss-btn')?.addEventListener('click', () => {
                banner.style.transform = 'translateY(-100%)';
            });

            document.getElementById('ob-open-guide-btn')?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                _openPanel();
                // Always send a fresh guide prompt, even if messages already exist
                setTimeout(() => {
                    sendOnboardingMessage(
                        [],
                        "Hi! Can you show me the school setup checklist and tell me what still needs to be done?"
                    );
                }, 150);
            });
        }
    }

    /* ── startOnboardingGuide — exposed globally so banner btn can call it ── */
    window.startOnboardingGuide = function () {
        const msgEl = document.getElementById('ai-messages');
        if (!msgEl) return;

        // Don't re-trigger if the user has already sent messages
        if (msgEl.querySelectorAll('.ai-msg.user').length > 0) return;

        sendOnboardingMessage(
            [],
            "Hello! I've just set up my school. Can you walk me through what I need to do first?"
        );
    };

    /* ── Core onboarding fetch ────────────────────────────────────────────── */
    function sendOnboardingMessage(history, userText) {
        _appendMsg('user', escHtmlOb(userText));

        const fd = new FormData();
        fd.append('csrf_token',    AI_CSRF);
        fd.append('is_onboarding', '1');
        fd.append('messages', JSON.stringify([
            ...history,
            { role: 'user', content: userText }
        ]));

        const typingEl = _appendTyping();

        fetch(AI_ENDPOINT, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (typingEl) typingEl.remove();
            if (!data.success) {
                _appendMsg('error', '⚠ ' + escHtmlOb(data.message || 'Error'));
                return;
            }
            // Render navigation cards from tool calls
            (data.tool_calls_made || []).forEach(tc => {
                let result = null;
                try { result = typeof tc.result === 'string' ? JSON.parse(tc.result) : tc.result; }
                catch(e) {}
                if (result && result.__type === 'navigation') appendNavCard(result);
            });
            if (data.reply) {
                _appendMsg('bot', escHtmlOb(data.reply).replace(/\n/g, '<br>'));
            }
            // Refresh progress banner after each onboarding exchange
            window.refreshOnboardingBanner?.();
        })
        .catch(err => {
            if (typingEl) typingEl.remove();
            _appendMsg('error', '⚠ Network error: ' + escHtmlOb(err.message || 'unknown'));
        });
    }

    /* ── Navigation card renderer ─────────────────────────────────────────── */
    function appendNavCard(nav) {
        const msgEl = document.getElementById('ai-messages');
        if (!msgEl) return;

        const card = document.createElement('div');
        card.className = 'ai-nav-card';
        card.innerHTML =
            '<div class="anc-icon"><i class="' + escHtmlOb(nav.icon || 'ri-arrow-right-line') + '"></i></div>' +
            '<div class="anc-body">' +
                '<div class="anc-label">' + escHtmlOb(nav.label || '') + '</div>' +
                (nav.description ? '<div class="anc-desc">' + escHtmlOb(nav.description) + '</div>' : '') +
            '</div>' +
            '<a href="' + escHtmlOb(nav.url || '#') + '" class="anc-btn">Take me there →</a>';

        msgEl.appendChild(card);
        msgEl.scrollTop = msgEl.scrollHeight;
    }

    /* expose so the main AI sendMessage() can also render nav cards */
    window.appendNavCard = appendNavCard;

    /* ── Auto-open panel + start guide on very first admin login ──────────── */
    if (OB_ACTIVE && OB_FIRST_VISIT) {
        setTimeout(() => {
            _openPanel();
            window.startOnboardingGuide();
        }, 900);
    }

    /* ── Progress bar refresh (called after guide responses + step marks) ─── */
    window.refreshOnboardingBanner = function () {
        const fd = new FormData();
        fd.append('action',     'onboarding_status');
        fd.append('csrf_token', AI_CSRF);
        fetch(AI_ENDPOINT, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const bar    = document.getElementById('ob-progress-bar');
            const pct    = document.getElementById('ob-banner-pct');
            const banner = document.getElementById('ob-banner');
            if (bar) bar.style.width = data.percent + '%';
            if (pct) pct.textContent  = data.percent + '%';
            if (data.completed && banner) banner.style.transform = 'translateY(-100%)';
        })
        .catch(() => {});
    };

    /* ── Escape helper (local copy — no dependency on main IIFE) ──────────── */
    function escHtmlOb(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
<?php endif; // OB_ACTIVE ?>
