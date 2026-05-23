<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';

if (empty($_SESSION['ai_csrf_token'])) {
    $_SESSION['ai_csrf_token'] = bin2hex(random_bytes(32));
}
$aiCsrf = $_SESSION['ai_csrf_token'];
$schoolId = (int)($school['id'] ?? 0);

if (!function_exists('academix_ai_page_count')) {
    function academix_ai_page_count(?PDO $db, string $table, int $schoolId, string $extraWhere = '', array $extraParams = []): int {
        if (!$db || !academix_admin_table_exists($db, $table)) {
            return 0;
        }
        try {
            $where = academix_admin_has_column($db, $table, 'school_id') ? 'school_id = ?' : '1=1';
            $params = academix_admin_has_column($db, $table, 'school_id') ? [$schoolId] : [];
            if ($extraWhere !== '') {
                $where .= ' AND ' . $extraWhere;
                $params = array_merge($params, $extraParams);
            }
            $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Academix AI page count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('academix_ai_page_bytes')) {
    function academix_ai_page_bytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}

$eventExtraWhere = '';
if ($schoolDb && academix_admin_table_exists($schoolDb, 'events') && academix_admin_has_column($schoolDb, 'events', 'start_date')) {
    $eventExtraWhere = '`start_date` >= CURDATE()';
}

$pulse = [
    'students' => academix_ai_page_count($schoolDb, 'students', $schoolId),
    'teachers' => 0,
    'classes' => academix_ai_page_count($schoolDb, 'classes', $schoolId),
    'subjects' => academix_ai_page_count($schoolDb, 'subjects', $schoolId),
    'events' => academix_ai_page_count($schoolDb, 'events', $schoolId, $eventExtraWhere),
    'unread' => (int)($notificationCount ?? 0),
];
if ($schoolDb && academix_admin_table_exists($schoolDb, 'users') && academix_admin_has_column($schoolDb, 'users', 'user_type')) {
    $pulse['teachers'] = academix_ai_page_count($schoolDb, 'users', $schoolId, "user_type = 'teacher'");
}

$recentExports = [];
$exportDir = ROOT_PATH . '/assets/uploads/ai_exports/' . max(0, $schoolId);
if (is_dir($exportDir)) {
    $files = glob($exportDir . '/*.csv') ?: [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 0, 5) as $file) {
        $recentExports[] = [
            'name' => basename($file),
            'url' => '/assets/uploads/ai_exports/' . max(0, $schoolId) . '/' . basename($file),
            'size' => academix_ai_page_bytes((int)filesize($file)),
            'time' => date('M j, g:i A', filemtime($file)),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | AcademiX AI</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/remixicon.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/style.css'); ?>">
    <style>
        /* ── Hide floating bubble on this page ───────────── */
        #academix-ai-bubble,
        #academix-ai-panel { display: none !important; }

        /* ══════════════════════════════════════════════════
           DESIGN TOKENS  (light & dark)
        ══════════════════════════════════════════════════ */
        :root,
        [data-theme="light"] {
            --ai-bg:           #f1f5f9;
            --ai-surface:      #ffffff;
            --ai-surface-2:    #f8fafc;
            --ai-border:       #e2e8f0;
            --ai-text:         #0f172a;
            --ai-text-sub:     #64748b;
            --ai-text-muted:   #94a3b8;
            --ai-primary:      #7c3aed;
            --ai-primary-hov:  #6d28d9;
            --ai-primary-lt:   #ede9fe;
            --ai-accent:       #06b6d4;
            --ai-success:      #10b981;
            --ai-danger:       #ef4444;
            --ai-user-bubble:  #7c3aed;
            --ai-user-text:    #ffffff;
            --ai-bot-bubble:   #ffffff;
            --ai-bot-text:     #0f172a;
            --ai-bot-border:   #e2e8f0;
            --ai-scrollbar:    #d1d5db;
            --ai-chip-bg:      #ffffff;
            --ai-chip-text:    #475569;
            --ai-composer-bg:  #f8fafc;
            --ai-topbar-bg:    #ffffff;
            --ai-welcome-bg:   #f1f5f9;
            --ai-typing-dot:   #7c3aed;
        }

        [data-theme="dark"] {
            --ai-bg:           #0d1117;
            --ai-surface:      #161b22;
            --ai-surface-2:    #1c2128;
            --ai-border:       #30363d;
            --ai-text:         #e6edf3;
            --ai-text-sub:     #8b949e;
            --ai-text-muted:   #484f58;
            --ai-primary:      #a78bfa;
            --ai-primary-hov:  #c4b5fd;
            --ai-primary-lt:   rgba(167,139,250,.15);
            --ai-accent:       #22d3ee;
            --ai-success:      #3fb950;
            --ai-danger:       #f85149;
            --ai-user-bubble:  #1f2937;
            --ai-user-text:    #e6edf3;
            --ai-bot-bubble:   #161b22;
            --ai-bot-text:     #e6edf3;
            --ai-bot-border:   #30363d;
            --ai-scrollbar:    #30363d;
            --ai-chip-bg:      #1c2128;
            --ai-chip-text:    #c9d1d9;
            --ai-composer-bg:  #1c2128;
            --ai-topbar-bg:    #161b22;
            --ai-welcome-bg:   #0d1117;
            --ai-typing-dot:   #a78bfa;
        }

        /* ══════════════════════════════════════════════════
           LAYOUT
        ══════════════════════════════════════════════════ */
        .dashboard-main-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--ai-bg);
            transition: background .3s;
        }

        /* ── Top bar ─────────────────────────────────── */
        .ai-topbar {
            padding: 14px 24px;
            background: var(--ai-topbar-bg);
            border-bottom: 1px solid var(--ai-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            transition: background .3s, border-color .3s;
        }
        .ai-topbar h4 {
            font-weight: 700;
            color: var(--ai-text);
            margin: 0;
            font-size: 17px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ai-topbar h4 .ai-logo-orb {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--ai-primary), var(--ai-accent));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
            flex-shrink: 0;
        }
        .ai-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background: rgba(16,185,129,.12);
            color: var(--ai-success);
            font-size: 11.5px;
            font-weight: 600;
        }
        .ai-status-pill::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--ai-success);
            animation: ai-pulse 2s ease-in-out infinite;
        }
        @keyframes ai-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

        .ai-topbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 10px;
            border: 1px solid var(--ai-border);
            background: var(--ai-surface-2);
            color: var(--ai-text-sub);
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, color .2s, border-color .2s;
        }
        .ai-topbar-btn:hover {
            background: var(--ai-primary-lt);
            color: var(--ai-primary);
            border-color: var(--ai-primary);
        }
        #aiThemeToggle i { font-size: 15px; }

        /* ── Main area (stacked view: welcome ↔ chat) ── */
        .ai-main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }
        .ai-view-stack {
            flex: 1;
            position: relative;
            min-height: 0;
            overflow: hidden;
        }

        /* ── Welcome screen ──────────────────────────── */
        .ai-welcome {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 36px 24px;
            background: var(--ai-welcome-bg);
            transition: opacity .35s ease, transform .35s ease, background .3s;
            z-index: 2;
            overflow-y: auto;
        }
        .ai-welcome.hidden {
            opacity: 0;
            transform: translateY(-14px) scale(.98);
            pointer-events: none;
        }
        .ai-orb {
            width: 78px;
            height: 78px;
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--ai-primary) 0%, var(--ai-accent) 100%);
            box-shadow: 0 20px 40px rgba(124,58,237,.28);
            margin-bottom: 22px;
            position: relative;
            flex-shrink: 0;
        }
        .ai-orb-ring {
            position: absolute;
            inset: -7px;
            border-radius: 29px;
            border: 2px solid rgba(124,58,237,.18);
            animation: ai-ripple 2.6s ease-out infinite;
        }
        .ai-orb-ring:nth-child(2) { animation-delay: .9s; }
        .ai-orb i {
            color: #fff;
            font-size: 34px;
            position: relative;
            z-index: 1;
            animation: ai-float 3.2s ease-in-out infinite;
        }
        @keyframes ai-float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
        @keyframes ai-ripple { 0%{transform:scale(1);opacity:.6} 100%{transform:scale(1.4);opacity:0} }

        .ai-welcome h1 {
            font-size: 27px;
            font-weight: 800;
            color: var(--ai-text);
            margin-bottom: 8px;
            transition: color .3s;
        }
        .ai-welcome p {
            color: var(--ai-text-sub);
            max-width: 500px;
            margin: 0 auto 24px;
            font-size: 14.5px;
            line-height: 1.65;
            transition: color .3s;
        }
        .ai-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            justify-content: center;
            max-width: 620px;
        }
        .ai-chip {
            padding: 9px 18px;
            border-radius: 999px;
            border: 1px solid var(--ai-border);
            background: var(--ai-chip-bg);
            color: var(--ai-chip-text);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: border-color .2s, color .2s, background .2s, transform .15s, box-shadow .2s;
            white-space: nowrap;
        }
        .ai-chip:hover {
            border-color: var(--ai-primary);
            color: var(--ai-primary);
            background: var(--ai-primary-lt);
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(124,58,237,.14);
        }

        /* ── Chat area ───────────────────────────────── */
        .ai-chat-area {
            position: absolute;
            inset: 0;
            display: none;
            flex-direction: column;
            z-index: 1;
            background: var(--ai-bg);
            transition: background .3s;
        }
        .ai-chat-area.active { display: flex; }

        .ai-messages-wrap {
            flex: 1;
            overflow-y: auto;
            padding: 28px 24px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .ai-messages-wrap::-webkit-scrollbar { width: 5px; }
        .ai-messages-wrap::-webkit-scrollbar-track { background: transparent; }
        .ai-messages-wrap::-webkit-scrollbar-thumb {
            background: var(--ai-scrollbar);
            border-radius: 99px;
        }

        /* ── Message rows ────────────────────────────── */
        .ai-message-row {
            display: flex;
            gap: 11px;
            margin-bottom: 16px;
            max-width: 82%;
            animation: ai-slideUp .28s ease;
        }
        .ai-message-row.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        @keyframes ai-slideUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }

        .ai-avatar {
            width: 34px;
            height: 34px;
            border-radius: 11px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: #fff;
            margin-top: 3px;
        }
        .ai-avatar.bot {
            background: linear-gradient(135deg, var(--ai-primary), var(--ai-accent));
        }
        .ai-avatar.user {
            background: #374151;
            font-size: 12px;
            font-weight: 700;
        }

        .ai-bubble {
            padding: 13px 17px;
            border-radius: 16px;
            font-size: 13.5px;
            line-height: 1.68;
            word-break: break-word;
            transition: background .3s, border-color .3s, color .3s;
        }
        .ai-message-row.user .ai-bubble {
            background: var(--ai-user-bubble);
            color: var(--ai-user-text);
            border-bottom-right-radius: 4px;
        }
        .ai-message-row.bot .ai-bubble {
            background: var(--ai-bot-bubble);
            color: var(--ai-bot-text);
            border: 1px solid var(--ai-bot-border);
            border-bottom-left-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        /* Markdown rendering inside bot bubbles */
        .ai-bubble strong { color: var(--ai-primary); }
        .ai-bubble code {
            background: var(--ai-surface-2);
            border: 1px solid var(--ai-border);
            border-radius: 5px;
            padding: 1px 6px;
            font-size: 12px;
            font-family: 'Consolas', 'Courier New', monospace;
            color: var(--ai-accent);
        }
        .ai-bubble .md-h { font-weight: 700; display: block; margin: 8px 0 4px; color: var(--ai-text); font-size: 14px; }
        .ai-bubble .md-li { padding-left: 16px; position: relative; margin: 3px 0; }
        .ai-bubble .md-li::before { content: '•'; position: absolute; left: 4px; color: var(--ai-primary); }
        .ai-bubble .md-sep { border: none; border-top: 1px solid var(--ai-border); margin: 10px 0; }

        /* Tool/CSV cards */
        .ai-tool-card {
            background: var(--ai-surface);
            border: 1px solid var(--ai-border);
            border-left: 3px solid var(--ai-accent);
            border-radius: 14px;
            padding: 14px 16px;
            margin: -8px 0 18px 45px;
            max-width: calc(82% - 45px);
            align-self: flex-start;
            animation: ai-slideUp .28s ease;
            box-shadow: 0 3px 12px rgba(0,0,0,.06);
            transition: background .3s, border-color .3s;
        }
        .ai-tool-card .btn { border-radius: 9px; font-weight: 700; font-size: 12.5px; }

        /* ── Typing indicator ─────────────────────────
           ONLY shows when setBusy(true) is called — never on page load
        ──────────────────────────────────────────────── */
        .ai-typing {
            display: none;          /* hidden by default, always */
            padding: 0 24px 10px;
            flex-shrink: 0;
        }
        .ai-typing.active { display: block; }

        .ai-typing-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ai-typing-avatar {
            width: 34px;
            height: 34px;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--ai-primary), var(--ai-accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 15px;
            flex-shrink: 0;
        }
        .ai-typing-dots {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 13px 18px;
            background: var(--ai-bot-bubble);
            border: 1px solid var(--ai-bot-border);
            border-radius: 16px;
            border-bottom-left-radius: 4px;
            transition: background .3s, border-color .3s;
        }
        .ai-typing-dots span {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--ai-typing-dot);
            animation: ai-bounce 1.35s infinite ease-in-out both;
        }
        .ai-typing-dots span:nth-child(1) { animation-delay: -.32s; }
        .ai-typing-dots span:nth-child(2) { animation-delay: -.16s; }
        .ai-typing-dots span:nth-child(3) { animation-delay:   0s; }
        @keyframes ai-bounce { 0%,80%,100%{transform:scale(.55);opacity:.3} 40%{transform:scale(1);opacity:1} }

        /* ── Composer ────────────────────────────────── */
        .ai-composer {
            padding: 14px 24px 18px;
            background: var(--ai-topbar-bg);
            border-top: 1px solid var(--ai-border);
            flex-shrink: 0;
            transition: background .3s, border-color .3s;
        }
        .ai-composer-inner {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            padding: 9px 9px 9px 18px;
            border-radius: 16px;
            border: 1.5px solid var(--ai-border);
            background: var(--ai-composer-bg);
            transition: border-color .2s, box-shadow .2s, background .3s;
        }
        .ai-composer-inner:focus-within {
            border-color: var(--ai-primary);
            box-shadow: 0 0 0 4px rgba(124,58,237,.1);
            background: var(--ai-surface);
        }
        .ai-composer-inner textarea {
            flex: 1;
            border: 0;
            outline: 0;
            resize: none;
            min-height: 24px;
            max-height: 120px;
            padding: 4px 0;
            color: var(--ai-text);
            background: transparent;
            font-size: 14px;
            font-family: inherit;
            line-height: 1.55;
            transition: color .3s;
        }
        .ai-composer-inner textarea::placeholder { color: var(--ai-text-muted); }

        /* Composer icon buttons */
        .ai-composer-btn {
            width: 38px; height: 38px;
            border-radius: 11px;
            border: none;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
            transition: background .18s, color .18s, transform .12s;
        }
        .ai-mic-btn {
            background: var(--ai-primary-lt);
            color: var(--ai-primary);
        }
        .ai-mic-btn:hover { background: var(--ai-primary); color: #fff; }
        .ai-mic-btn.listening {
            background: rgba(239,68,68,.15);
            color: #ef4444;
            animation: mic-pulse 1s ease-in-out infinite;
        }
        @keyframes mic-pulse {
            0%,100%{ box-shadow: 0 0 0 0 rgba(239,68,68,.35); }
            50%    { box-shadow: 0 0 0 7px rgba(239,68,68,0); }
        }

        .ai-send-btn {
            background: var(--ai-primary);
            color: #fff;
        }
        .ai-send-btn:hover { background: var(--ai-primary-hov); transform: scale(1.05); }
        .ai-send-btn:active { transform: scale(.93); }
        .ai-send-btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }

        .ai-composer-hint {
            font-size: 11px;
            color: var(--ai-text-muted);
            text-align: center;
            margin-top: 8px;
            transition: color .3s;
        }
        .ai-composer-hint kbd {
            background: var(--ai-surface-2);
            border: 1px solid var(--ai-border);
            border-radius: 4px;
            padding: 1px 5px;
            font-size: 10.5px;
        }

        /* ── History loading skeleton ─────────────────── */
        .ai-skeleton-row {
            display: flex; gap: 11px; margin-bottom: 16px;
        }
        .ai-skeleton-avatar {
            width: 34px; height: 34px; border-radius: 11px;
            background: var(--ai-border); flex-shrink: 0;
            animation: ai-shimmer 1.4s linear infinite;
        }
        .ai-skeleton-bubble {
            height: 44px; border-radius: 14px;
            background: var(--ai-border);
            animation: ai-shimmer 1.4s linear infinite;
        }
        @keyframes ai-shimmer {
            0%   { opacity: .6; }
            50%  { opacity: .3; }
            100% { opacity: .6; }
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 768px) {
            .ai-welcome { padding: 24px 16px; }
            .ai-welcome h1 { font-size: 21px; }
            .ai-chip { font-size: 12px; padding: 8px 13px; white-space: normal; }
            .ai-message-row { max-width: 96%; }
            .ai-tool-card { max-width: 96%; margin-left: 0; }
            .ai-topbar { padding: 12px 16px; flex-wrap: wrap; gap: 8px; }
            .ai-composer { padding: 10px 14px 14px; }
            .ai-messages-wrap { padding: 16px 14px 8px; }
        }
    </style>
</head>
<body>
<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<?php include_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="dashboard-main">
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
            <div class="ai-topbar">
                <div class="d-flex align-items-center gap-3">
                    <h4 class="mb-0">
                        <span class="ai-logo-orb"><i class="ri-sparkling-2-fill"></i></span>
                        AcademiX AI
                    </h4>
                    <span class="ai-status-pill">Online</span>
                    <?php if ($pulse['students'] || $pulse['teachers']): ?>
                    <span class="text-secondary-light text-sm d-none d-md-inline" style="color:var(--ai-text-muted);font-size:12px;">
                        <?php if ($pulse['students']): ?><i class="ri-user-3-line"></i> <?php echo (int)$pulse['students']; ?> students&nbsp;&nbsp;<?php endif; ?>
                        <?php if ($pulse['teachers']): ?><i class="ri-team-line"></i> <?php echo (int)$pulse['teachers']; ?> teachers<?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($recentExports): ?>
                    <div class="dropdown">
                        <button class="ai-topbar-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="ri-file-excel-2-line"></i> Exports
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="max-width:320px;background:var(--ai-surface);border-color:var(--ai-border);">
                            <?php foreach ($recentExports as $export): ?>
                            <li><a class="dropdown-item" href="<?php echo academix_admin_e($export['url']); ?>" download
                                   style="color:var(--ai-text);font-size:13px;">
                                <i class="ri-download-cloud-2-line me-2" style="color:var(--ai-accent);"></i>
                                <?php echo academix_admin_e($export['name']); ?>
                                <small class="d-block" style="color:var(--ai-text-sub);">
                                    <?php echo academix_admin_e($export['size']); ?> &middot; <?php echo academix_admin_e($export['time']); ?>
                                </small>
                            </a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <button class="ai-topbar-btn" id="aiThemeToggle" title="Toggle light / dark">
                        <i class="ri-moon-line"></i>
                    </button>
                    <button class="ai-topbar-btn" id="aiClearMemory" title="Clear conversation">
                        <i class="ri-delete-bin-6-line"></i> Clear
                    </button>
                </div>
            </div>

            <div class="ai-main-area">
                <div class="ai-view-stack">
                    <div class="ai-welcome" id="aiWelcome">
                        <div class="ai-orb">
                            <div class="ai-orb-ring"></div>
                            <div class="ai-orb-ring"></div>
                            <i class="ri-sparkling-2-fill"></i>
                        </div>
                        <h1>How can I help you today?</h1>
                        <p>Ask me to inspect the school, create records, generate reports, send messages, or build downloadable CSV files.</p>
                        <div class="ai-chips">
                            <button type="button" class="ai-chip" data-prompt="What needs attention in the school today?">School pulse</button>
                            <button type="button" class="ai-chip" data-prompt="Create a CSV of all students">Student CSV</button>
                            <button type="button" class="ai-chip" data-prompt="Create a CSV of unpaid fee balances">Unpaid fees</button>
                            <button type="button" class="ai-chip" data-prompt="Create a CSV of today's attendance">Attendance CSV</button>
                            <button type="button" class="ai-chip" data-prompt="Draft a WhatsApp message to parents about an important school update">WhatsApp parents</button>
                        </div>
                    </div>

                    <div class="ai-chat-area" id="aiChatArea">
                        <div class="ai-messages-wrap" id="aiMessages"></div>
                        <!-- Typing indicator lives INSIDE ai-chat-area.
                             When ai-chat-area is display:none (before any message is sent),
                             this entire subtree is invisible regardless of its own display value.
                             The inline style="display:none" is a second, independent guarantee
                             that overrides any stylesheet rule that could fight with the CSS class. -->
                        <div class="ai-typing" id="aiTyping" style="display:none">
                            <div class="ai-typing-row">
                                <div class="ai-typing-avatar"><i class="ri-sparkling-2-fill"></i></div>
                                <div class="ai-typing-dots">
                                    <span></span><span></span><span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ai-composer">
                <div class="ai-composer-inner">
                    <textarea id="aiPageInput" rows="1"
                        placeholder="Ask anything — attendance, fees, reports, CSV exports, announcements…"
                        style="min-height:24px;"></textarea>
                    <button type="button" class="ai-composer-btn ai-mic-btn" id="aiMicBtn"
                            title="Voice input — click to speak" aria-label="Voice input">
                        <i class="ri-mic-line"></i>
                    </button>
                    <button type="button" class="ai-composer-btn ai-send-btn" id="aiPageSend"
                            aria-label="Send message" title="Send (Enter)" disabled>
                        <i class="ri-arrow-up-line"></i>
                    </button>
                </div>
                <div class="ai-composer-hint">
                    <kbd>Enter</kbd> send &nbsp;·&nbsp; <kbd>Shift+Enter</kbd> new line
                    &nbsp;·&nbsp; <i class="ri-mic-line" style="color:var(--ai-primary);"></i> voice input
                </div>
            </div>
    </div>
</main>

<script src="<?php echo academix_admin_asset('js/lib/jquery-3.7.1.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/iconify-icon.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/app.js'); ?>"></script>
<script>
(function () {
    'use strict';

    /* ─── Config ─────────────────────────────────────── */
    const ENDPOINT   = 'ai_assistant.php';
    let   csrfToken  = <?php echo json_encode($aiCsrf); ?>;

    /* ─── DOM refs ───────────────────────────────────── */
    const htmlEl     = document.documentElement;
    const welcomeEl  = document.getElementById('aiWelcome');
    const chatArea   = document.getElementById('aiChatArea');
    const messagesEl = document.getElementById('aiMessages');
    const inputEl    = document.getElementById('aiPageInput');
    const sendBtn    = document.getElementById('aiPageSend');
    const clearBtn   = document.getElementById('aiClearMemory');
    const typingEl   = document.getElementById('aiTyping');
    const micBtn     = document.getElementById('aiMicBtn');
    const themeBtn   = document.getElementById('aiThemeToggle');

    /* ─── State ──────────────────────────────────────── */
    let hasHistory  = false;
    let isBusy      = false;
    let isListening = false;
    let speechRec   = null;

    /* ═════════════════════════════════════════════════
       DARK / LIGHT THEME TOGGLE
       Reads the html[data-theme] that the sidebar already sets,
       and toggles between "dark" and "light".
    ═════════════════════════════════════════════════ */
    function applyTheme(theme) {
        htmlEl.setAttribute('data-theme', theme);
        localStorage.setItem('ai_theme', theme);
        if (themeBtn) {
            themeBtn.innerHTML = theme === 'dark'
                ? '<i class="ri-sun-line"></i>'
                : '<i class="ri-moon-line"></i>';
            themeBtn.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        }
    }
    /* initialise from localStorage or default to dark (match sidebar) */
    applyTheme(localStorage.getItem('ai_theme') || 'dark');

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            applyTheme(htmlEl.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
    }

    /* ═════════════════════════════════════════════════
       VOICE INPUT — Web Speech API
    ═════════════════════════════════════════════════ */
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognition) {
        speechRec               = new SpeechRecognition();
        speechRec.continuous     = false;
        speechRec.interimResults = true;
        speechRec.lang           = 'en-US';

        speechRec.onresult = function (e) {
            const transcript = Array.from(e.results).map(r => r[0].transcript).join('');
            inputEl.value    = transcript;
            /* auto-resize */
            inputEl.style.height = 'auto';
            inputEl.style.height = Math.min(inputEl.scrollHeight, 130) + 'px';
            sendBtn.disabled = transcript.trim() === '';
        };
        speechRec.onend = function () {
            isListening = false;
            micBtn.classList.remove('listening');
            micBtn.innerHTML = '<i class="ri-mic-line"></i>';
            micBtn.title     = 'Voice input — click to speak';
        };
        speechRec.onerror = function () {
            isListening = false;
            micBtn.classList.remove('listening');
            micBtn.innerHTML = '<i class="ri-mic-line"></i>';
        };

        micBtn.addEventListener('click', function () {
            if (isListening) {
                speechRec.stop();
            } else {
                isListening = true;
                micBtn.classList.add('listening');
                micBtn.innerHTML = '<i class="ri-stop-circle-line"></i>';
                micBtn.title     = 'Listening… click to stop';
                try { speechRec.start(); } catch (err) { /* already started */ }
            }
        });
    } else {
        /* browser doesn't support speech — hide mic button */
        if (micBtn) micBtn.style.display = 'none';
    }

    /* ═════════════════════════════════════════════════
       UTILITIES
    ═════════════════════════════════════════════════ */
    function escHtml(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
        }[c]));
    }

    /* Minimal Markdown → HTML renderer for bot messages */
    function renderMarkdown(text) {
        return escHtml(text)
            /* headings */
            .replace(/^### (.+)$/gm, '<span class="md-h" style="font-size:13px;">$1</span>')
            .replace(/^## (.+)$/gm,  '<span class="md-h">$1</span>')
            .replace(/^# (.+)$/gm,   '<span class="md-h" style="font-size:15px;">$1</span>')
            /* bold */
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            /* italic */
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            /* inline code */
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            /* horizontal rule */
            .replace(/^---$/gm, '<hr class="md-sep">')
            /* bullet lists */
            .replace(/^[-•*] (.+)$/gm, '<div class="md-li">$1</div>')
            /* numbered lists */
            .replace(/^\d+\. (.+)$/gm, '<div class="md-li">$1</div>')
            /* line breaks */
            .replace(/\n\n/g, '<br><br>')
            .replace(/\n/g,   '<br>');
    }

    function scrollToBottom() {
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function showChat() {
        if (!hasHistory) {
            welcomeEl.classList.add('hidden');
            chatArea.classList.add('active');
            hasHistory = true;
        }
    }

    /* ═════════════════════════════════════════════════
       MESSAGE RENDERING
    ═════════════════════════════════════════════════ */
    function appendMessage(role, content) {
        showChat();
        const row    = document.createElement('div');
        row.className = 'ai-message-row ' + (role === 'user' ? 'user' : 'bot');

        const av   = document.createElement('div');
        av.className = 'ai-avatar ' + (role === 'user' ? 'user' : 'bot');
        av.innerHTML = role === 'user'
            ? '<i class="ri-user-line"></i>'
            : '<i class="ri-sparkling-2-fill"></i>';

        const bub  = document.createElement('div');
        bub.className = 'ai-bubble';
        bub.innerHTML = role === 'user'
            ? escHtml(content || '').replace(/\n/g, '<br>')
            : renderMarkdown(content || '');

        row.appendChild(av);
        row.appendChild(bub);
        messagesEl.appendChild(row);
        scrollToBottom();
    }

    /* ─── CSV / Tool cards ───────────────────────── */
    function parseToolResults(toolCalls) {
        return (toolCalls || []).map(function (c) {
            try { return typeof c.result === 'string' ? JSON.parse(c.result) : c.result; }
            catch { return null; }
        }).filter(Boolean);
    }

    function appendCsvCard(data) {
        if (!data || data.__type !== 'csv_export') return;
        showChat();
        const ok   = !!data.success;
        const card = document.createElement('div');
        card.className = 'ai-tool-card';
        card.innerHTML =
            '<div class="d-flex align-items-start justify-content-between gap-3">'
          +   '<div>'
          +     '<div style="font-weight:700;color:var(--ai-text);margin-bottom:3px;">'
          +       (ok ? '<i class="ri-file-excel-2-line" style="color:var(--ai-success);margin-right:5px;"></i>CSV ready'
                      : '<i class="ri-error-warning-line" style="color:var(--ai-danger);margin-right:5px;"></i>CSV failed')
          +     '</div>'
          +     '<div style="font-size:13px;color:var(--ai-text-sub);">' + escHtml(data.message || '') + '</div>'
          +     (ok ? '<div style="font-size:12px;color:var(--ai-text-muted);margin-top:4px;">'
                    + Number(data.rows || 0) + ' rows &middot; '
                    + escHtml((data.report_type || 'export').replace(/_/g,' ')) + '</div>' : '')
          +   '</div>'
          + '</div>'
          + (ok && data.url
              ? '<a class="btn mt-2" href="' + escHtml(data.url) + '" download '
              +   'style="background:var(--ai-success);color:#fff;border-radius:9px;font-size:12.5px;font-weight:700;padding:7px 14px;">'
              +   '<i class="ri-download-cloud-2-line me-1"></i>Download CSV</a>'
              : '');
        messagesEl.appendChild(card);
        scrollToBottom();
    }

    function appendPdfCard(data) {
        if (!data || data.__type !== 'pdf_export') return;
        showChat();
        const ok   = !!data.success;
        const card = document.createElement('div');
        card.className = 'ai-tool-card';
        card.innerHTML =
            '<div class="d-flex align-items-start justify-content-between gap-3">'
          +   '<div>'
          +     '<div style="font-weight:700;color:var(--ai-text);margin-bottom:3px;">'
          +       (ok
                    ? '<i class="ri-file-pdf-2-line" style="color:#e53e3e;margin-right:5px;"></i>PDF Report Ready'
                    : '<i class="ri-error-warning-line" style="color:var(--ai-danger);margin-right:5px;"></i>PDF Failed')
          +     '</div>'
          +     '<div style="font-size:13px;color:var(--ai-text-sub);">' + escHtml(data.message || '') + '</div>'
          +     (ok
                    ? '<div style="font-size:12px;color:var(--ai-text-muted);margin-top:4px;">'
                    + '<i class="ri-table-line" style="margin-right:3px;"></i>'
                    + escHtml(data.title || (data.report_type || 'report').replace(/_/g,' '))
                    + (data.rows != null ? ' &middot; ' + data.rows + ' records' : '')
                    + '</div>' : '')
          +   '</div>'
          + '</div>'
          + (ok && data.url
              ? '<div class="d-flex gap-2 mt-2 flex-wrap">'
              + '<a class="btn" href="' + escHtml(data.url) + '" target="_blank" rel="noopener" '
              +   'style="background:#e53e3e;color:#fff;border-radius:9px;font-size:12.5px;font-weight:700;padding:7px 14px;">'
              +   '<i class="ri-file-pdf-2-line me-1"></i>Open &amp; Save as PDF</a>'
              + '<a class="btn" href="' + escHtml(data.url) + '" download '
              +   'style="background:var(--ai-surface);color:var(--ai-text);border:1.5px solid var(--ai-border);border-radius:9px;font-size:12.5px;font-weight:700;padding:7px 14px;">'
              +   '<i class="ri-download-cloud-2-line me-1"></i>Download</a>'
              + '</div>'
              : '');
        messagesEl.appendChild(card);
        scrollToBottom();
    }

    function appendTimetableCard(data) {
        if (!data || data.__type !== 'timetable_pdf') return;
        showChat();
        const ok   = !!data.success;
        const card = document.createElement('div');
        card.className = 'ai-tool-card';
        card.innerHTML =
            '<div class="d-flex align-items-start justify-content-between gap-3">'
          +   '<div>'
          +     '<div style="font-weight:700;color:var(--ai-text);margin-bottom:3px;">'
          +       (ok
                    ? '<i class="ri-calendar-schedule-line" style="color:var(--ai-primary);margin-right:5px;"></i>Timetable Ready'
                    : '<i class="ri-error-warning-line" style="color:var(--ai-danger);margin-right:5px;"></i>Timetable Failed')
          +     '</div>'
          +     '<div style="font-size:13px;color:var(--ai-text-sub);">' + escHtml(data.message || '') + '</div>'
          +     (ok && (data.class_name || data.class)
                    ? '<div style="font-size:12px;color:var(--ai-text-muted);margin-top:4px;">'
                    + '<i class="ri-group-line" style="margin-right:3px;"></i>' + escHtml(data.class_name || data.class)
                    + (data.periods ? ' &middot; ' + data.periods + ' periods' : '')
                    + (data.days    ? ' across ' + data.days + ' days' : '')
                    + '</div>' : '')
          +   '</div>'
          + '</div>'
          + (ok && data.url
              ? '<div class="d-flex gap-2 mt-2 flex-wrap">'
              + '<a class="btn" href="' + escHtml(data.url) + '" target="_blank" rel="noopener" '
              +   'style="background:var(--ai-primary);color:#fff;border-radius:9px;font-size:12.5px;font-weight:700;padding:7px 14px;">'
              +   '<i class="ri-eye-line me-1"></i>Open &amp; Print Timetable</a>'
              + '<a class="btn" href="' + escHtml(data.url) + '" download '
              +   'style="background:var(--ai-surface);color:var(--ai-text);border:1.5px solid var(--ai-border);border-radius:9px;font-size:12.5px;font-weight:700;padding:7px 14px;">'
              +   '<i class="ri-download-cloud-2-line me-1"></i>Download</a>'
              + '</div>'
              : '');
        messagesEl.appendChild(card);
        scrollToBottom();
    }

    function renderToolCards(toolCalls) {
        parseToolResults(toolCalls).forEach(function (r) {
            if (r.__type === 'csv_export')     { appendCsvCard(r);       return; }
            if (r.__type === 'pdf_export')     { appendPdfCard(r);       return; }
            if (r.__type === 'timetable_pdf')  { appendTimetableCard(r); return; }
            if (r.__type === 'school_intelligence') {
                const sig = (r.signals || []).map(s => s.title + ': ' + s.detail).join('\n');
                if (sig) appendMessage('assistant', sig);
            }
        });
    }

    /* ═════════════════════════════════════════════════
       BUSY STATE
       setBusy(true)  → show typing indicator + disable input
       setBusy(false) → hide typing indicator + re-enable input
       NOTE: loadHistory() intentionally does NOT call setBusy —
             it uses a skeleton loader instead, keeping the typing
             dots hidden until the user actually sends a message.
    ═════════════════════════════════════════════════ */
    function setBusy(busy) {
        isBusy            = busy;
        sendBtn.disabled  = busy;
        inputEl.disabled  = busy;
        sendBtn.innerHTML = busy
            ? '<i class="ri-loader-4-line ri-spin"></i>'
            : '<i class="ri-arrow-up-line"></i>';

        /* Use style.display directly — inline styles beat every stylesheet rule,
           so app.js or bootstrap.css can never accidentally un-hide the dots. */
        typingEl.style.display = busy ? 'block' : 'none';
    }

    /* ─── Skeleton loader (used only during loadHistory) ── */
    function showSkeleton(n) {
        messagesEl.innerHTML = '';
        for (let i = 0; i < n; i++) {
            const s = document.createElement('div');
            s.className = 'ai-skeleton-row';
            s.innerHTML = '<div class="ai-skeleton-avatar"></div>'
                        + '<div class="ai-skeleton-bubble" style="width:'+(55+Math.random()*30)+'%"></div>';
            messagesEl.appendChild(s);
        }
    }
    function clearSkeleton() { messagesEl.innerHTML = ''; }

    /* ═════════════════════════════════════════════════
       HTTP HELPER
    ═════════════════════════════════════════════════ */
    function refreshToken(res) {
        if (res && typeof res.csrf_token === 'string' && res.csrf_token.length > 10) {
            csrfToken = res.csrf_token;
        }
    }

    function postForm(payload) {
        payload.csrf_token = csrfToken;
        return fetch(ENDPOINT, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body:    new URLSearchParams(payload),
        }).then(r => r.json());
    }

    /* ═════════════════════════════════════════════════
       LOAD HISTORY — NO typing indicator, uses skeleton
    ═════════════════════════════════════════════════ */
    function loadHistory() {
        showSkeleton(3);
        postForm({ action: 'history', limit: 80 })
            .then(function (res) {
                refreshToken(res);
                clearSkeleton();
                const rows = Array.isArray(res.messages) ? res.messages : [];
                if (!rows.length) return;   /* stay on welcome screen */
                showChat();
                rows.forEach(function (row) {
                    appendMessage(row.role === 'user' ? 'user' : 'assistant', row.content || '');
                    if (row.metadata && row.metadata.tool_calls_made) {
                        renderToolCards(row.metadata.tool_calls_made);
                    }
                });
            })
            .catch(function () { clearSkeleton(); });   /* silent fail — show welcome */
    }

    /* ═════════════════════════════════════════════════
       SEND MESSAGE
    ═════════════════════════════════════════════════ */
    function sendMessage(textOverride) {
        const text = (textOverride || inputEl.value || '').trim();
        if (!text || isBusy) return;

        appendMessage('user', text);
        inputEl.value        = '';
        inputEl.style.height = 'auto';
        sendBtn.disabled     = true;
        setBusy(true);   /* ← THIS is the only place typing dots appear */

        postForm({ message: text })
            .then(function (res) {
                refreshToken(res);
                appendMessage('assistant', res.reply || res.message || 'No response returned.');
                renderToolCards(res.tool_calls_made || []);
            })
            .catch(function () {
                appendMessage('assistant', '⚠️ Network error — please check your connection and try again.');
            })
            .finally(function () { setBusy(false); });
    }

    /* ═════════════════════════════════════════════════
       EVENT LISTENERS
    ═════════════════════════════════════════════════ */
    /* Chip / quick-prompt buttons */
    document.querySelectorAll('[data-prompt]').forEach(function (btn) {
        btn.addEventListener('click', function () { sendMessage(btn.dataset.prompt || ''); });
    });

    /* Send button */
    sendBtn.addEventListener('click', function () { sendMessage(); });

    /* Auto-resize textarea + enable/disable send */
    inputEl.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 130) + 'px';
        sendBtn.disabled  = this.value.trim() === '' || isBusy;
    });

    /* Enter to send, Shift+Enter for newline */
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    /* Clear conversation */
    clearBtn.addEventListener('click', function () {
        if (!confirm('Clear this AI conversation?')) return;
        postForm({ action: 'clear_history' }).then(function (res) {
            refreshToken(res);
            messagesEl.innerHTML = '';
            chatArea.classList.remove('active');
            welcomeEl.classList.remove('hidden');
            hasHistory = false;
        });
    });

    /* ─── Boot ───────────────────────────────────── */
    loadHistory();

})();
</script>
</body>
</html>
