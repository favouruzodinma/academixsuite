<?php
$schoolSlug = $schoolSlug ?? $GLOBALS['SCHOOL_SLUG'] ?? '';
$school = $school ?? $GLOBALS['SCHOOL_DATA'] ?? [];
$schoolAuth = $schoolAuth ?? $GLOBALS['SCHOOL_AUTH'] ?? [];
$currentPage = $currentPage ?? $GLOBALS['CURRENT_PAGE'] ?? basename($_SERVER['PHP_SELF']);
$baseUrl = $baseUrl ?? $GLOBALS['BASE_URL'] ?? ('/tenant/' . $schoolSlug . '/admin');
?>
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-[100] lg:relative lg:translate-x-0 -translate-x-full transition-transform duration-300 flex flex-col">
    
    <!-- School Header -->
    <div class="h-20 flex items-center px-6 border-b border-gray-200">
        <div class="flex items-center gap-3">
            <?php
                // Render the actual school logo from the platform DB
                // (schools.logo_path). Falls back to the AcademixSuite mark.
                $sidebarLogoUrl = function_exists('school_logo_url') ? school_logo_url($school) : '';
            ?>
            <?php if ($sidebarLogoUrl): ?>
                <img src="<?php echo htmlspecialchars($sidebarLogoUrl); ?>"
                     alt="<?php echo htmlspecialchars(($school['name'] ?? 'School') . ' logo'); ?>"
                     class="w-10 h-10 rounded-xl object-contain bg-white border border-slate-200 p-1">
            <?php else: ?>
                <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center">
                    <i class="fas fa-school text-white text-lg"></i>
                </div>
            <?php endif; ?>
            <div>
                <span class="text-xl font-bold text-slate-900"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
                <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest mt-0.5">SCHOOL ADMIN</p>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
        <div>
            <p class="px-6 text-[11px] font-semibold text-slate-500 uppercase tracking-[0.15em] mb-3">Dashboard</p>
            <nav class="space-y-1">
                <a href="<?php echo $baseUrl . '/index.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600 <?php echo $currentPage === 'index.php' ? 'active-link' : ''; ?>">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <span>Overview</span>
                </a>
            </nav>
        </div>

        <div>
            <p class="px-6 text-[11px] font-semibold text-slate-500 uppercase tracking-[0.15em] mb-3">Student Management</p>
            <nav class="space-y-1">
                <a href="<?php echo $baseUrl . '/students.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600 <?php echo $currentPage === 'students.php' ? 'active-link' : ''; ?>">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <span>Students Directory</span>
                </a>
                <a href="<?php echo $baseUrl . '/student-attendance.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600 <?php echo in_array($currentPage, ['student-attendance.php', 'employee-attendance.php', 'teacher-attendance.php']) ? 'active-link' : ''; ?>">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <span>Attendance</span>
                </a>
                <a href="<?php echo $baseUrl . '/exam-result.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600 <?php echo in_array($currentPage, ['exam-result.php', 'exam.php', 'exam-schedule.php']) ? 'active-link' : ''; ?>">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <span>Grades & Reports</span>
                </a>
            </nav>
        </div>

        <div>
            <p class="px-6 text-[11px] font-semibold text-slate-500 uppercase tracking-[0.15em] mb-3">School Operations</p>
            <nav class="space-y-1">
                <a href="<?php echo $baseUrl . '/fees-collect.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600 <?php echo in_array($currentPage, ['fees-collect.php', 'fees-type.php', 'fees-group.php', 'fees-discount.php']) ? 'active-link' : ''; ?>">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <span>Fee Management</span>
                </a>
                <a href="<?php echo $baseUrl . '/general.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600 <?php echo $currentPage === 'general.php' ? 'active-link' : ''; ?>">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-cog"></i>
                    </div>
                    <span>School Settings</span>
                </a>
            </nav>
        </div>
    </div>

    <!-- User Profile -->
    <div class="p-6 border-t border-gray-200">
        <div class="flex items-center gap-3 p-2 group cursor-pointer hover:bg-slate-50 rounded-xl transition">
            <div class="relative">
                <?php
                $adminName = $schoolAuth['name'] ?? 'Admin User';
                $initials = substr($adminName, 0, 1) . (strpos($adminName, ' ') !== false ? substr($adminName, strpos($adminName, ' ') + 1, 1) : substr($adminName, 1, 1));
                ?>
                <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center text-white font-bold">
                    <?php echo strtoupper($initials); ?>
                </div>
                <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-green-500 border-2 border-white rounded-full"></div>
            </div>
            <div class="overflow-hidden flex-1">
                <p class="text-[13px] font-bold text-slate-900 truncate"><?php echo htmlspecialchars($adminName); ?></p>
                <p class="text-[10px] font-semibold text-blue-600 uppercase tracking-wider">School_Admin</p>
            </div>
        </div>
    </div>
</aside>
