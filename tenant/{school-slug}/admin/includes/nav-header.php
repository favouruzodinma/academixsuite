<div class="navbar-header shadow-1">
    <div class="row align-items-center justify-content-between">
        <div class="col-auto">
            <div class="d-flex flex-wrap align-items-center gap-4">
                <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                    <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                </button>
                <form class="navbar-search">
                    <input type="text" class="bg-transparent" name="search" placeholder="Search...">
                    <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                </form>
            </div>
        </div>
        <div class="col-auto">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                <div class="dropdown">
                    <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                        <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                        <?php if ($unreadCount > 0): ?>
                        <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                        <div class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                            <div>
                                <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                            </div>
                            <span class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo count($notifications); ?></span>
                        </div>
                        <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                            <?php if (!empty($notifications)): ?>
                                <?php foreach ($notifications as $notif): ?>
                                <a href="notification.php?id=<?php echo $notif['id']; ?>" class="px-24 py-12 d-flex align-items-start gap-3 mb-2 justify-content-between">
                                    <div class="text-black hover-bg-transparent hover-text-primary d-flex align-items-center gap-3">
                                        <span class="w-44-px h-44-px bg-success-subtle text-success-main rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                            <iconify-icon icon="bitcoin-icons:verify-outline" class="icon text-xxl"></iconify-icon>
                                        </span>
                                        <div>
                                            <h6 class="text-md fw-semibold mb-4"><?php echo htmlspecialchars($notif['title']); ?></h6>
                                            <p class="mb-0 text-sm text-secondary-light text-w-200-px"><?php echo htmlspecialchars(substr($notif['message'] ?? '', 0, 50)) . '...'; ?></p>
                                        </div>
                                    </div>
                                    <span class="text-sm text-secondary-light flex-shrink-0">
                                        <?php echo timeAgo($notif['created_at']); ?>
                                    </span>
                                </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-20">
                                    <p class="text-secondary-light">No new notifications</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-center py-12 px-16">
                            <a href="notification.php" class="text-primary-600 fw-semibold text-md hover-underline">See All Notifications</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
