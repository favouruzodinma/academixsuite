<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';

$csrf = academix_admin_csrf_token();
$toasts = academix_admin_take_toasts();
$tableReady = $schoolDb && academix_admin_table_exists($schoolDb, 'notifications');
$columns = $tableReady ? academix_admin_columns($schoolDb, 'notifications') : [];
$hasRead = in_array('is_read', $columns, true);
$hasReadAt = in_array('read_at', $columns, true);
$hasPriority = in_array('priority', $columns, true);
$hasType = in_array('type', $columns, true);
$hasExpires = in_array('expires_at', $columns, true);
$hasCreated = in_array('created_at', $columns, true);
$hasData = in_array('data', $columns, true);

function academix_notification_count(PDO $db, array $where, array $params): int {
    $sql = 'SELECT COUNT(*) FROM notifications WHERE ' . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function academix_notification_icon(array $notification): string {
    $type = strtolower((string)($notification['type'] ?? 'in_app'));
    $priority = strtolower((string)($notification['priority'] ?? 'normal'));
    $title = strtolower((string)($notification['title'] ?? ''));

    if ($priority === 'urgent' || $priority === 'high') {
        return 'ri-alert-line';
    }
    if (str_contains($title, 'attendance')) {
        return 'ri-calendar-check-line';
    }
    if (str_contains($title, 'fee') || str_contains($title, 'payment') || str_contains($title, 'invoice')) {
        return 'ri-money-dollar-circle-line';
    }
    if (str_contains($title, 'event')) {
        return 'ri-calendar-event-line';
    }

    return [
        'email' => 'ri-mail-line',
        'sms' => 'ri-message-2-line',
        'push' => 'ri-notification-3-line',
        'system' => 'ri-settings-3-line',
        'in_app' => 'ri-notification-line',
    ][$type] ?? 'ri-notification-line';
}

function academix_notification_link(array $notification, bool $hasData): string {
    if (!$hasData || empty($notification['data'])) {
        return '';
    }

    $data = json_decode((string)$notification['data'], true);
    if (!is_array($data)) {
        return '';
    }

    $candidate = (string)($data['url'] ?? $data['link'] ?? $data['href'] ?? '');
    if ($candidate !== '' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $candidate) && !str_contains($candidate, "\n")) {
        return $candidate;
    }

    if (!empty($data['event_id'])) {
        return 'event.php?id=' . rawurlencode((string)$data['event_id']);
    }

    return '';
}

$status = $_GET['status'] ?? 'all';
$status = in_array($status, ['all', 'unread', 'read'], true) ? $status : 'all';
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
$search = trim((string)($_GET['q'] ?? ''));
$notificationsPage = [];
$typeOptions = [];
$stats = [
    'total' => 0,
    'unread' => 0,
    'high' => 0,
    'today' => 0,
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $tableReady) {
    if (!academix_admin_validate_csrf($_POST['csrf_token'] ?? null)) {
        setToast('error', 'Security validation failed. Please refresh and try again.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if (!$hasRead) {
                throw new RuntimeException('Notification read status is not available in this database.');
            }

            $set = ['is_read = 1'];
            if ($hasReadAt) {
                $set[] = 'read_at = NOW()';
            }

            if ($action === 'mark_read') {
                $notificationId = (int)($_POST['notification_id'] ?? 0);
                if ($notificationId <= 0) {
                    throw new RuntimeException('Notification not found.');
                }
                $stmt = $schoolDb->prepare('UPDATE notifications SET ' . implode(', ', $set) . ' WHERE id = ? AND school_id = ?');
                $stmt->execute([$notificationId, (int)$school['id']]);
                setToast('success', 'Notification marked as read.');
            } elseif ($action === 'mark_all_read') {
                $stmt = $schoolDb->prepare('UPDATE notifications SET ' . implode(', ', $set) . ' WHERE school_id = ? AND is_read = 0');
                $stmt->execute([(int)$school['id']]);
                setToast('success', 'All notifications marked as read.');
            }
        } catch (Throwable $e) {
            error_log('Notifications action failed: ' . $e->getMessage());
            setToast('error', $e->getMessage());
        }
    }

    $redirect = academix_admin_safe_redirect_target($_POST['return_to'] ?? 'notifications.php');
    header('Location: ' . $redirect);
    exit;
}

if ($tableReady) {
    try {
        $baseWhere = ['school_id = ?'];
        $baseParams = [(int)$school['id']];
        if ($hasExpires) {
            $baseWhere[] = '(expires_at IS NULL OR expires_at > NOW())';
        }

        $stats['total'] = academix_notification_count($schoolDb, $baseWhere, $baseParams);
        if ($hasRead) {
            $stats['unread'] = academix_notification_count($schoolDb, array_merge($baseWhere, ['is_read = 0']), $baseParams);
        }
        if ($hasPriority) {
            $stats['high'] = academix_notification_count($schoolDb, array_merge($baseWhere, ["priority IN ('high', 'urgent')"]), $baseParams);
        }
        if ($hasCreated) {
            $stats['today'] = academix_notification_count($schoolDb, array_merge($baseWhere, ['DATE(created_at) = CURDATE()']), $baseParams);
        }

        if ($hasType) {
            $stmt = $schoolDb->prepare('SELECT DISTINCT type FROM notifications WHERE school_id = ? AND type IS NOT NULL ORDER BY type ASC');
            $stmt->execute([(int)$school['id']]);
            $typeOptions = array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }

        $where = $baseWhere;
        $params = $baseParams;
        if ($hasRead && $status === 'unread') {
            $where[] = 'is_read = 0';
        } elseif ($hasRead && $status === 'read') {
            $where[] = 'is_read = 1';
        }
        if ($hasType && $typeFilter !== '' && $typeFilter !== 'all') {
            $where[] = 'type = ?';
            $params[] = $typeFilter;
        }
        if ($search !== '') {
            $searchParts = [];
            if (in_array('title', $columns, true)) {
                $searchParts[] = 'title LIKE ?';
                $params[] = '%' . $search . '%';
            }
            if (in_array('message', $columns, true)) {
                $searchParts[] = 'message LIKE ?';
                $params[] = '%' . $search . '%';
            }
            if (!empty($searchParts)) {
                $where[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $orderBy = $hasCreated ? 'created_at DESC, id DESC' : 'id DESC';
        $stmt = $schoolDb->prepare('SELECT * FROM notifications WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT 200');
        $stmt->execute($params);
        $notificationsPage = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Notifications page load failed: ' . $e->getMessage());
        $toasts['error'] = 'Could not load notifications from the school database.';
    }
}

$returnTo = 'notifications.php?' . http_build_query([
    'status' => $status,
    'type' => $typeFilter,
    'q' => $search,
]);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | Notifications</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/remixicon.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/style.css'); ?>">
    <style>
        .notification-stat {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #fff;
            padding: 20px;
            min-height: 118px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .04);
        }
        .notification-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #fff;
            padding: 18px;
            display: flex;
            gap: 16px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .04);
        }
        .notification-card.is-unread {
            border-color: rgba(37, 161, 148, .35);
            background: linear-gradient(90deg, rgba(37, 161, 148, .08), #fff 36%);
        }
        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 48px;
            background: #e9fbf7;
            color: #259f93;
            font-size: 24px;
        }
        .priority-pill {
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
            text-transform: capitalize;
        }
        .priority-pill.high,
        .priority-pill.urgent {
            background: #fee2e2;
            color: #b91c1c;
        }
        .notification-meta {
            color: #64748b;
            font-size: 13px;
        }
        @media (max-width: 767px) {
            .notification-card {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
<?php include_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="dashboard-main">
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <?php foreach ($toasts as $toastType => $toastMessage): ?>
            <?php if ($toastMessage !== ''): ?>
                <div class="alert alert-<?php echo $toastType === 'error' ? 'danger' : academix_admin_e($toastType); ?> alert-dismissible fade show" role="alert">
                    <?php echo academix_admin_e($toastMessage); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-bold mb-4 h4 text-primary-light">Notifications</h1>
                <p class="text-secondary-light mb-0">A school-wide feed of alerts created by attendance, events, fees, messages, and system tasks.</p>
            </div>
            <?php if ($tableReady && $hasRead && $stats['unread'] > 0): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                    <button type="submit" class="btn btn-primary-600 radius-10">
                        <i class="ri-check-double-line"></i> Mark all read
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="row gy-4 mb-24">
            <div class="col-xl-3 col-sm-6">
                <div class="notification-stat">
                    <span class="text-secondary-light text-sm">Total alerts</span>
                    <h4 class="mb-0 mt-10 text-primary-light"><?php echo number_format($stats['total']); ?></h4>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="notification-stat">
                    <span class="text-secondary-light text-sm">Unread</span>
                    <h4 class="mb-0 mt-10 text-primary-light"><?php echo number_format($stats['unread']); ?></h4>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="notification-stat">
                    <span class="text-secondary-light text-sm">High priority</span>
                    <h4 class="mb-0 mt-10 text-primary-light"><?php echo number_format($stats['high']); ?></h4>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="notification-stat">
                    <span class="text-secondary-light text-sm">Created today</span>
                    <h4 class="mb-0 mt-10 text-primary-light"><?php echo number_format($stats['today']); ?></h4>
                </div>
            </div>
        </div>

        <div class="card radius-18 border-0 mb-24">
            <div class="card-body">
                <form method="get" class="row gy-3 align-items-end">
                    <div class="col-lg-5">
                        <label class="form-label fw-semibold">Search notifications</label>
                        <input type="search" name="q" class="form-control" value="<?php echo academix_admin_e($search); ?>" placeholder="Search title or message">
                    </div>
                    <div class="col-lg-3 col-sm-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Unread</option>
                            <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Read</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-sm-6">
                        <label class="form-label fw-semibold">Type</label>
                        <select name="type" class="form-select">
                            <option value="all">All types</option>
                            <?php foreach ($typeOptions as $type): ?>
                                <option value="<?php echo academix_admin_e($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e(ucwords(str_replace('_', ' ', $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button class="btn btn-primary-600 radius-10" type="submit"><i class="ri-filter-3-line"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!$tableReady): ?>
            <div class="card radius-18 border-0">
                <div class="card-body text-center py-48">
                    <div class="w-64-px h-64-px rounded-circle bg-warning-focus d-inline-flex align-items-center justify-content-center text-warning-main text-xxl mb-16">
                        <i class="ri-database-2-line"></i>
                    </div>
                    <h5 class="text-primary-light">Notifications table not found</h5>
                    <p class="text-secondary-light mb-0">Run the school database migration so backend alerts can be stored and displayed here.</p>
                </div>
            </div>
        <?php elseif (empty($notificationsPage)): ?>
            <div class="card radius-18 border-0">
                <div class="card-body text-center py-48">
                    <div class="w-64-px h-64-px rounded-circle bg-info-focus d-inline-flex align-items-center justify-content-center text-info-main text-xxl mb-16">
                        <i class="ri-notification-off-line"></i>
                    </div>
                    <h5 class="text-primary-light">No notifications found</h5>
                    <p class="text-secondary-light mb-0">New attendance, fee, event, message, and system notifications will appear here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($notificationsPage as $notification): ?>
                    <?php
                        $isUnread = $hasRead && (int)($notification['is_read'] ?? 0) === 0;
                        $link = academix_notification_link($notification, $hasData);
                        $priority = (string)($notification['priority'] ?? 'normal');
                        $createdAt = (string)($notification['created_at'] ?? '');
                    ?>
                    <article class="notification-card <?php echo $isUnread ? 'is-unread' : ''; ?>">
                        <span class="notification-icon">
                            <i class="<?php echo academix_admin_e(academix_notification_icon($notification)); ?>"></i>
                        </span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-6">
                                <h6 class="mb-0 text-primary-light"><?php echo academix_admin_e($notification['title'] ?? 'Notification'); ?></h6>
                                <?php if ($hasPriority): ?>
                                    <span class="priority-pill <?php echo academix_admin_e(strtolower($priority)); ?>"><?php echo academix_admin_e($priority); ?></span>
                                <?php endif; ?>
                                <?php if ($hasType && !empty($notification['type'])): ?>
                                    <span class="badge bg-neutral-100 text-secondary-light"><?php echo academix_admin_e(ucwords(str_replace('_', ' ', (string)$notification['type']))); ?></span>
                                <?php endif; ?>
                                <?php if ($isUnread): ?>
                                    <span class="badge bg-success-focus text-success-main">Unread</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-secondary-light mb-10"><?php echo nl2br(academix_admin_e($notification['message'] ?? '')); ?></p>
                            <div class="d-flex flex-wrap align-items-center gap-3 notification-meta">
                                <?php if ($createdAt !== ''): ?>
                                    <span><i class="ri-time-line"></i> <?php echo academix_admin_e(timeAgo($createdAt)); ?></span>
                                    <span><?php echo academix_admin_e(date('M j, Y g:i A', strtotime($createdAt))); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($notification['delivery_status'])): ?>
                                    <span>Delivery: <?php echo academix_admin_e($notification['delivery_status']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-2">
                            <?php if ($link !== ''): ?>
                                <a href="<?php echo academix_admin_e($link); ?>" class="btn btn-sm btn-outline-primary-600 radius-8">Open</a>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?php echo (int)$notification['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                                    <button type="submit" class="btn btn-sm btn-primary-600 radius-8">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="<?php echo academix_admin_asset('js/lib/jquery-3.7.1.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/iconify-icon.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/app.js'); ?>"></script>
</body>
</html>
