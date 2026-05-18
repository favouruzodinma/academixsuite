<div class="navbar-header shadow-1">
    <div class="row align-items-center justify-content-between">
        <div class="col-auto">
            <div class="d-flex flex-wrap align-items-center gap-4">
                <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                    <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                </button>
                <form class="navbar-search" method="GET" action="">
                    <input type="text" class="bg-transparent" name="search" placeholder="Search..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                </form>
            </div>
        </div>
        <div class="col-auto">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button">
                    <iconify-icon icon="ri:sun-line" class="icon text-primary-light text-xl"></iconify-icon>
                </button>
                <div class="dropdown">
                    <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                        <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                        <?php if (isset($unreadCount) && $unreadCount > 0): ?>
                        <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                        <div class="text-center py-20">
                            <p class="text-secondary-light">No new notifications</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
