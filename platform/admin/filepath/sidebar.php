<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-slate-200 z-[100] lg:relative lg:translate-x-0 -translate-x-full transition-transform duration-300 flex flex-col shadow-xl lg:shadow-none overflow-hidden">
    
    <!-- Logo Area -->
    <div class="h-20 flex items-center px-4 md:px-6 border-b border-slate-100 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-100">
                    <i class="fas fa-university text-white text-lg"></i>
                </div>
                <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-white rounded-full"></div>
            </div>
            <div class="overflow-hidden">
                <span class="text-lg md:text-xl font-black tracking-tight text-slate-900 italic truncate"><?php echo APP_NAME; ?></span>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5 truncate">ADMIN EXECUTIVE</p>
            </div>
        </div>
        <!-- Close button for mobile -->
        <button onclick="mobileSidebarToggle()" class="lg:hidden ml-auto text-slate-400 hover:text-slate-600 p-2" aria-label="Close menu">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Navigation -->
    <div class="flex-1 overflow-y-auto py-4 md:py-6 space-y-6 md:space-y-8 custom-scrollbar hide-scrollbar">
        <div>
            <p class="px-4 md:px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
            <nav class="space-y-1">
                <a href="<?php echo admin_url('dashboard.php'); ?>" class="sidebar-link active-link flex items-center gap-3 px-4 md:px-6 py-3 text-sm font-semibold">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-chart-network"></i>
                    </div>
                    <span class="truncate">Executive Overview</span>
                </a>
            </nav>
        </div>

        <div>
            <p class="px-4 md:px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Institutional Ops</p>
            <nav class="space-y-1">
                <div class="dropdown-group" id="schools-drop">
                    <button onclick="toggleDropdown('schools-drop')" class="w-full flex items-center justify-between px-4 md:px-6 py-3 sidebar-link text-sm font-medium text-slate-600 touch-manipulation">
                        <span class="flex items-center gap-3">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-school-flag"></i>
                            </div>
                            <span class="truncate">Schools Registry</span>
                        </span>
                        <i class="fas fa-chevron-down text-[10px] chevron transition-transform opacity-50"></i>
                    </button>
                    <div class="dropdown-content bg-slate-50/30">
                        <a href="../schools/index.php" class="block pl-10 md:pl-12 py-2.5 text-xs font-semibold text-blue-600 hover:text-blue-700 transition border-r-2 border-blue-600 bg-blue-50/50 truncate">Active Directory</a>
                        <a href="../schools/add.php" class="block pl-10 md:pl-12 py-2.5 text-xs text-slate-500 hover:text-blue-600 transition truncate">Provision New Node</a>
                        <a href="../schools/view.php" class="block pl-10 md:pl-12 py-2.5 text-xs text-slate-500 hover:text-blue-600 transition truncate">Performance Audit</a>
                    </div>
                </div>
                <a href="../subscriptions/index.php" class="sidebar-link flex items-center gap-3 px-4 md:px-6 py-3 text-sm font-medium text-slate-600">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-credit-card-front"></i>
                    </div>
                    <span class="truncate">Subscription Tiers</span>
                </a>
                <a href="../plans/index.php" class="sidebar-link flex items-center gap-3 px-4 md:px-6 py-3 text-sm font-medium text-slate-600">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <span class="truncate">Pricing Architecture</span>
                </a>
            </nav>
        </div>

        <div>
            <p class="px-4 md:px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Platform Health</p>
            <nav class="space-y-1">
                <a href="../support/tickets.php" class="sidebar-link flex items-center justify-between px-4 md:px-6 py-3 text-sm font-medium text-slate-600">
                    <span class="flex items-center gap-3">
                        <div class="w-5 h-5 flex items-center justify-center">
                            <i class="fas fa-headset"></i>
                        </div>
                        <span class="truncate">Support Hub</span>
                    </span>
                    <?php if (isset($stats['pending_tickets']) && $stats['pending_tickets'] > 0): ?>
                        <span class="gradient-badge !text-[10px] !px-2 !py-0.5"><?php echo $stats['pending_tickets']; ?></span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-group" id="reports-drop">
                    <button onclick="toggleDropdown('reports-drop')" class="w-full flex items-center justify-between px-4 md:px-6 py-3 sidebar-link text-sm font-medium text-slate-600 touch-manipulation">
                        <span class="flex items-center gap-3">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span class="truncate">Analytics & Reports</span>
                        </span>
                        <i class="fas fa-chevron-down text-[10px] chevron transition-transform opacity-50"></i>
                    </button>
                    <div class="dropdown-content bg-slate-50/30">
                        <a href="../reports/revenue.php" class="block pl-10 md:pl-12 py-2.5 text-xs text-slate-500 hover:text-blue-600 transition truncate">Revenue Intelligence</a>
                        <a href="../reports/schools-growth.php" class="block pl-10 md:pl-12 py-2.5 text-xs text-slate-500 hover:text-blue-600 transition truncate">Growth Analytics</a>
                    </div>
                </div>
                <div class="dropdown-group" id="logs-drop">
                    <button onclick="toggleDropdown('logs-drop')" class="w-full flex items-center justify-between px-4 md:px-6 py-3 sidebar-link text-sm font-medium text-slate-600 touch-manipulation">
                        <span class="flex items-center gap-3">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-microchip"></i>
                            </div>
                            <span class="truncate">System Logs</span>
                        </span>
                        <i class="fas fa-chevron-down text-[10px] chevron transition-transform opacity-50"></i>
                    </button>
                    <div class="dropdown-content bg-slate-50/30">
                        <a href="../logs/activity.php" class="block pl-10 md:pl-12 py-2.5 text-xs text-slate-500 hover:text-blue-600 transition truncate">Activity Monitor</a>
                        <a href="../logs/error-logs.php" class="block pl-10 md:pl-12 py-2.5 text-xs text-slate-500 hover:text-blue-600 transition truncate">Error Diagnostics</a>
                    </div>
                </div>
                <a href="../settings/general.php" class="sidebar-link flex items-center gap-3 px-4 md:px-6 py-3 text-sm font-medium text-slate-600">
                    <div class="w-5 h-5 flex items-center justify-center">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <span class="truncate">Global Configuration</span>
                </a>
            </nav>
        </div>
    </div>

    <!-- User Profile with Logout -->
    <div class="p-4 md:p-6 border-t border-slate-100 shrink-0">
        <div class="relative">
            <div id="userProfileBtn" class="flex items-center gap-3 p-2 hover:bg-slate-50 rounded-xl transition cursor-pointer">
                <div class="relative">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($superAdmin['name']); ?>&background=1e293b&color=fff&bold=true&size=128" 
                        class="w-10 h-10 rounded-xl shadow-sm" alt="<?php echo htmlspecialchars($superAdmin['name']); ?>">
                    <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full status-pulse"></div>
                </div>
                <div class="overflow-hidden flex-1">
                    <p class="text-[13px] font-black text-slate-900 truncate"><?php echo htmlspecialchars($superAdmin['name']); ?></p>
                    <p class="text-[10px] font-black text-blue-600 uppercase tracking-wider italic truncate"><?php echo $superAdmin['role']; ?></p>
                </div>
                <div class="text-slate-400 hover:text-slate-600 transition">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
            
            <!-- User Dropdown Menu -->
            <div id="userMenu" class="absolute bottom-full left-0 right-0 mb-2 bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden transform scale-95 opacity-0 pointer-events-none transition-all duration-200 origin-bottom-right z-50">
                <div class="p-2">
                    <a href="../profile.php" class="flex items-center gap-3 px-3 py-2.5 text-sm text-slate-600 hover:text-blue-600 hover:bg-slate-50 rounded-lg transition">
                        <i class="fas fa-user-circle text-slate-400"></i>
                        <span>My Profile</span>
                    </a>
                    <a href="../settings.php" class="flex items-center gap-3 px-3 py-2.5 text-sm text-slate-600 hover:text-blue-600 hover:bg-slate-50 rounded-lg transition">
                        <i class="fas fa-cog text-slate-400"></i>
                        <span>Settings</span>
                    </a>
                    <div class="border-t border-slate-100 my-1"></div>
                    <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-sm text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Logout Button for Mobile (always visible) -->
        <a href="../logout.php" class="lg:hidden mt-4 w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-red-500 to-red-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-red-200">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<script>
// Make functions globally available
window.toggleSidebar = function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar && overlay) {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
        document.body.style.overflow = sidebar.classList.contains('-translate-x-full') ? 'auto' : 'hidden';
    }
}

window.mobileSidebarToggle = function() {
    window.toggleSidebar();
}

window.toggleDropdown = function(id) {
    const dropdown = document.getElementById(id);
    if (dropdown) {
        dropdown.classList.toggle('dropdown-open');
        
        // Close other dropdowns
        document.querySelectorAll('.dropdown-group').forEach(group => {
            if (group.id !== id && group.classList.contains('dropdown-open')) {
                group.classList.remove('dropdown-open');
            }
        });
        
        // Close user menu if open
        closeUserMenu();
    }
}

// User menu functionality
let userMenuTimeout;
const userProfileBtn = document.getElementById('userProfileBtn');
const userMenu = document.getElementById('userMenu');

function showUserMenu() {
    if (userMenu) {
        userMenu.classList.remove('scale-95', 'opacity-0', 'pointer-events-none');
        userMenu.classList.add('scale-100', 'opacity-100', 'pointer-events-auto');
        
        // Close other dropdowns
        document.querySelectorAll('.dropdown-group').forEach(group => {
            group.classList.remove('dropdown-open');
        });
    }
}

function closeUserMenu() {
    if (userMenu) {
        userMenu.classList.remove('scale-100', 'opacity-100', 'pointer-events-auto');
        userMenu.classList.add('scale-95', 'opacity-0', 'pointer-events-none');
    }
}

function toggleUserMenu() {
    if (userMenu.classList.contains('scale-95')) {
        showUserMenu();
    } else {
        closeUserMenu();
    }
}

if (userProfileBtn && userMenu) {
    // Toggle user menu on click
    userProfileBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        toggleUserMenu();
    });
    
    // Keep menu open when hovering over it (desktop only)
    userMenu.addEventListener('mouseenter', function() {
        if (window.innerWidth >= 1024) {
            clearTimeout(userMenuTimeout);
        }
    });
    
    userMenu.addEventListener('mouseleave', function() {
        if (window.innerWidth >= 1024) {
            userMenuTimeout = setTimeout(closeUserMenu, 300);
        }
    });
    
    // Close user menu when clicking outside
    document.addEventListener('click', function(event) {
        if (userMenu && !userMenu.contains(event.target) && !userProfileBtn.contains(event.target)) {
            closeUserMenu();
        }
    });
    
    // Close user menu on mobile when clicking a link
    userMenu.addEventListener('click', function(e) {
        if (e.target.tagName === 'A') {
            closeUserMenu();
            
            // Also close sidebar on mobile
            if (window.innerWidth < 1024) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && overlay) {
                    sidebar.classList.add('-translate-x-full');
                    overlay.classList.add('hidden');
                    document.body.style.overflow = 'auto';
                }
            }
        }
    });
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (window.innerWidth < 1024 && sidebar && overlay) {
        const isClickInsideSidebar = sidebar.contains(e.target);
        const isClickOnToggle = e.target.closest('[onclick*="mobileSidebarToggle"]');
        const isClickOnHamburger = e.target.closest('.fa-bars-staggered');
        
        if (!isClickInsideSidebar && !isClickOnToggle && !isClickOnHamburger) {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    }
});

// Handle escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        closeUserMenu();
        
        if (sidebar && window.innerWidth < 1024) {
            sidebar.classList.add('-translate-x-full');
            if (overlay) overlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        // Close all dropdowns
        document.querySelectorAll('.dropdown-group').forEach(group => {
            group.classList.remove('dropdown-open');
        });
    }
});

// Auto-close sidebar on mobile when clicking a link
document.querySelectorAll('.sidebar-link, .dropdown-content a').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth < 1024) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }
    });
});

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    // Add touch manipulation class to all interactive elements
    document.querySelectorAll('button, a[href], input, select, textarea').forEach(el => {
        el.classList.add('touch-manipulation');
    });
});
</script>

<style>
/* Sidebar styling */
.sidebar-link {
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border-left: 3px solid transparent;
    position: relative;
    min-height: 44px;
    display: flex;
    align-items: center;
}

.sidebar-link:hover {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.05) 0%, rgba(37, 99, 235, 0.02) 100%);
    color: var(--brand-primary);
    border-left-color: rgba(37, 99, 235, 0.3);
}

.active-link {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.05) 100%);
    color: var(--brand-primary);
    border-left-color: var(--brand-primary);
    font-weight: 700;
}

.active-link::before {
    content: '';
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 60%;
    background: var(--brand-primary);
    border-radius: 4px 0 0 4px;
}

.dropdown-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.dropdown-open .dropdown-content {
    max-height: 300px;
}

.dropdown-open .chevron {
    transform: rotate(180deg);
}

.dropdown-group button {
    min-height: 44px;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

/* Gradient badges */
.gradient-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    letter-spacing: 0.05em;
    flex-shrink: 0;
}

/* Pulse animation for live status */
@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    }
}

.status-pulse {
    animation: pulse 2s infinite;
}

/* Mobile optimizations */
@media (max-width: 768px) {
    #sidebar {
        width: 280px;
        max-width: 85vw;
        z-index: 1000;
    }
    
    #sidebarOverlay {
        z-index: 999;
    }
    
    .dropdown-content a {
        min-height: 44px;
        display: flex;
        align-items: center;
    }
}

/* Desktop hover effects */
@media (min-width: 1024px) {
    #userProfileBtn:hover {
        background-color: #f8fafc;
    }
    
    /* Show user menu on profile hover */
    #userProfileBtn:hover #userMenu,
    #userMenu:hover {
        transform: scale(1);
        opacity: 1;
        pointer-events: auto;
    }
    
    /* Ensure user menu stays above everything */
    #userMenu {
        z-index: 1001;
    }
}

/* Custom scrollbar for desktop */
@media (min-width: 768px) {
    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }
}

/* Fix for overflow on mobile */
@media (max-width: 1024px) {
    body.sidebar-open {
        overflow: hidden;
    }
}

/* User menu styling */
#userMenu {
    z-index: 1001;
}

#userMenu a {
    min-height: 44px;
    display: flex;
    align-items: center;
    text-decoration: none;
}

#userMenu a:hover {
    text-decoration: none;
}
</style>