<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$user       = currentUser();
$role       = $user['role'];
$initials   = getInitials($user['full_name']);
$notifCount = getUnreadNotifCount($user['user_id']);

// Unread message count for Messages nav badge (HR and intern).
$unreadMsgNavCount = 0;
if (($role === 'hr' || $role === 'intern') && !empty($_SESSION['user_id'])) {
    $msgBadgeStmt = getDB()->prepare("SELECT COUNT(*) FROM message WHERE receiver_id = ? AND is_read = 0");
    $msgBadgeStmt->execute([$_SESSION['user_id']]);
    $unreadMsgNavCount = (int)$msgBadgeStmt->fetchColumn();
}

// Pending task count for intern "My Tasks" nav badge.
$pendingTaskNavCount = 0;
if (($role ?? '') === 'intern' && !empty($_SESSION['user_id'])) {
    $ptStmt = getDB()->prepare("SELECT COUNT(*) FROM task_assignment ta JOIN task t ON ta.task_id = t.task_id JOIN intern_profile ip ON ta.intern_id = ip.intern_id WHERE ip.user_id = ? AND t.task_status IN ('to_do','in_progress')");
    $ptStmt->execute([$_SESSION['user_id']]);
    $pendingTaskNavCount = (int)$ptStmt->fetchColumn();
}

// Pending-leave count for the sidebar "Leave Requests" badge (admin/manager only).
$pendingLeaveNavCount = 0;
if ($role === 'admin' || $role === 'manager') {
    $scopeDeptForBadge = managerScopeDeptId();
    if ($scopeDeptForBadge !== null) {
        $badgeStmt = getDB()->prepare("SELECT COUNT(*) FROM leave_request lr JOIN intern_profile ip ON lr.intern_id = ip.intern_id WHERE lr.approval_status = 'pending' AND ip.department_id = ?");
        $badgeStmt->execute([$scopeDeptForBadge]);
        $pendingLeaveNavCount = (int)$badgeStmt->fetchColumn();
    } else {
        $pendingLeaveNavCount = (int)getDB()->query("SELECT COUNT(*) FROM leave_request WHERE approval_status = 'pending'")->fetchColumn();
    }
}

// Build nav based on role
$nav = [];

if ($role === 'admin' || $role === 'manager') {
    $nav[] = ['section' => null];
    $nav[] = ['href' => BASE_URL . '/dashboard.php', 'label' => 'Dashboard', 'icon' => 'grid'];
    $nav[] = ['href' => BASE_URL . '/admin/interns.php', 'label' => 'Interns', 'icon' => 'users'];
    $nav[] = ['href' => BASE_URL . '/admin/attendance.php', 'label' => 'Attendance', 'icon' => 'clock'];
    $nav[] = ['href' => BASE_URL . '/admin/leave.php', 'label' => 'Leave Requests', 'icon' => 'calendar', 'leaveBadge' => true];
    $nav[] = ['section' => 'TASKS'];
    $nav[] = ['href' => BASE_URL . '/admin/tasks.php', 'label' => 'All Tasks', 'icon' => 'task'];
    $nav[] = ['href' => BASE_URL . '/admin/reports.php', 'label' => 'Reports', 'icon' => 'report'];
    if ($role === 'admin') {
        $nav[] = ['section' => 'ORGANIZATION'];
        $nav[] = ['href' => BASE_URL . '/admin/departments.php',  'label' => 'Departments',  'icon' => 'building'];
        $nav[] = ['href' => BASE_URL . '/admin/hr_accounts.php',  'label' => 'HR Accounts',  'icon' => 'users'];
        $nav[] = ['href' => BASE_URL . '/admin/holidays.php',     'label' => 'Holidays',     'icon' => 'calendar'];
        $nav[] = ['section' => 'RECRUITMENT'];
        $nav[] = ['href' => BASE_URL . '/hr/jobs.php',             'label' => 'Job Postings',       'icon' => 'briefcase'];
        $nav[] = ['href' => BASE_URL . '/hr/candidate_cards.php',  'label' => 'Candidate Profiles', 'icon' => 'users'];
        $nav[] = ['href' => BASE_URL . '/hr/applications.php',     'label' => 'Applications',       'icon' => 'task'];
        $nav[] = ['href' => BASE_URL . '/hr/hiring_board.php',     'label' => 'Hiring Board',       'icon' => 'task'];
        $nav[] = ['href' => BASE_URL . '/hr/reports.php',          'label' => 'HR Reports',         'icon' => 'report'];
    }
    $nav[] = ['section' => 'ACCOUNT'];
    $nav[] = ['href' => BASE_URL . '/admin/notifications.php', 'label' => 'Notifications', 'icon' => 'bell'];
    $nav[] = ['href' => BASE_URL . '/admin/profile.php', 'label' => 'My Profile', 'icon' => 'user'];
} elseif ($role === 'hr') {
    $nav[] = ['section' => null];
    $nav[] = ['href' => BASE_URL . '/hr/dashboard.php',   'label' => 'Dashboard',    'icon' => 'grid'];
    $nav[] = ['href' => BASE_URL . '/hr/jobs.php',          'label' => 'Job Postings',      'icon' => 'briefcase'];
    $nav[] = ['href' => BASE_URL . '/hr/candidate_cards.php','label' => 'Candidate Profiles','icon' => 'users'];
    $nav[] = ['href' => BASE_URL . '/hr/applications.php', 'label' => 'Applications',      'icon' => 'task'];
    $nav[] = ['href' => BASE_URL . '/hr/hiring_board.php',  'label' => 'Hiring Board',       'icon' => 'task'];
    $nav[] = ['section' => 'ANALYTICS'];
    $nav[] = ['href' => BASE_URL . '/hr/reports.php',     'label' => 'Reports',      'icon' => 'report'];
    $nav[] = ['section' => 'ACCOUNT'];
    $nav[] = ['href' => BASE_URL . '/hr/messages.php',        'label' => 'Messages',       'icon' => 'message'];
    $nav[] = ['href' => BASE_URL . '/admin/notifications.php','label' => 'Notifications',  'icon' => 'bell'];
    $nav[] = ['href' => BASE_URL . '/admin/profile.php',      'label' => 'My Profile',     'icon' => 'user'];
} else {
    $nav[] = ['section' => null];
    $nav[] = ['href' => BASE_URL . '/intern/dashboard.php', 'label' => 'Dashboard', 'icon' => 'grid'];
    $nav[] = ['href' => BASE_URL . '/intern/attendance.php', 'label' => 'Attendance', 'icon' => 'clock'];
    $nav[] = ['href' => BASE_URL . '/intern/tasks.php', 'label' => 'My Tasks', 'icon' => 'task'];
    $nav[] = ['href' => BASE_URL . '/intern/leave.php', 'label' => 'Leave Request', 'icon' => 'calendar'];
    $nav[] = ['section' => 'ACCOUNT'];
    $nav[] = ['href' => BASE_URL . '/intern/messages.php',      'label' => 'Messages',       'icon' => 'message'];
    $nav[] = ['href' => BASE_URL . '/intern/notifications.php', 'label' => 'Notifications', 'icon' => 'bell'];
    $nav[] = ['href' => BASE_URL . '/intern/profile.php', 'label' => 'Profile', 'icon' => 'user'];
}

$currentPath  = $_SERVER['PHP_SELF'];
$currentFile  = basename($currentPath);

function navIcon($name) {
    $icons = [
        'grid'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'clock'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'task'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'report'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18.7 8a3 3 0 1 0-5.3 2.7L9 17"/><path d="M7 12l3 3 7-7"/></svg>',
        'user'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'building'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="1"/><line x1="9" y1="6" x2="9" y2="6.01"/><line x1="15" y1="6" x2="15" y2="6.01"/><line x1="9" y1="10" x2="9" y2="10.01"/><line x1="15" y1="10" x2="15" y2="10.01"/><line x1="9" y1="14" x2="9" y2="14.01"/><line x1="15" y1="14" x2="15" y2="14.01"/><line x1="9" y1="18" x2="15" y2="18"/></svg>',
        'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="12.01"/></svg>',
        'bell'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'message'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    ];
    return $icons[$name] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'TeamInternGrub') ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=7">
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar"><script>(function(){var s=document.getElementById('sidebar');if(s&&window.innerWidth>900&&localStorage.getItem('sidebarCollapsed')==='1'){s.classList.add('collapsed','sb-no-trans');requestAnimationFrame(function(){requestAnimationFrame(function(){s.classList.remove('sb-no-trans');});});}})()</script>
    <div class="sidebar-logo" style="position:relative;">
        <img class="sidebar-logo-full" src="<?= BASE_URL ?>/assets/logo.webp" alt="InternGrub">
        <img class="sidebar-logo-mini" src="<?= BASE_URL ?>/assets/mini-logo.png" alt="iG">
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($nav as $item): ?>
            <?php if (array_key_exists('section', $item)): ?>
                <?php if ($item['section']): ?>
                    <div class="nav-section-label"><?= htmlspecialchars($item['section']) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['href']) ?>"
                   class="nav-item <?php
                        $navFile   = basename(parse_url($item['href'], PHP_URL_PATH));
                        $exactMatch = ($currentPath === parse_url($item['href'], PHP_URL_PATH));
                        // Keep nav item active on child/detail pages
                        $parentMatch = ($navFile === 'candidate_cards.php' && $currentFile === 'candidate_profile.php')
                                    || ($navFile === 'interns.php' && $currentFile === 'intern_profile.php')
                                    || ($navFile === 'messages.php' && $currentFile === 'messages.php');
                        echo ($exactMatch || $parentMatch) ? 'active' : '';
                   ?>"
                   title="<?= htmlspecialchars($item['label']) ?>">
                    <?= navIcon($item['icon']) ?>
                    <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
                    <?php if (!empty($item['leaveBadge']) && $pendingLeaveNavCount > 0): ?>
                        <span class="nav-badge-leave"><?= $pendingLeaveNavCount ?></span>
                    <?php elseif ($item['icon'] === 'bell' && $notifCount > 0): ?>
                        <span class="nav-badge-notif"><?= $notifCount ?></span>
                    <?php elseif ($item['icon'] === 'task' && $pendingTaskNavCount > 0 && ($role ?? '') === 'intern'): ?>
                        <span class="nav-badge-notif"><?= $pendingTaskNavCount ?></span>
                    <?php elseif ($item['icon'] === 'message' && $unreadMsgNavCount > 0): ?>
                        <span class="nav-badge-notif"><?= $unreadMsgNavCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-logout-wrap">
        <a href="<?= BASE_URL ?>/logout.php" class="nav-item-logout" title="Logout">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span class="nav-label">Logout</span>
        </a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <!-- TOPBAR -->
    <header class="topbar">
        <div class="flex items-center gap-3">
            <button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle sidebar">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div>
                <h1 class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                <?php if (!empty($pageSubtitle)): ?><div class="topbar-subtitle"><?= htmlspecialchars($pageSubtitle) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="topbar-actions">
            <?php if ($role !== 'hr'): ?>
            <div class="search-wrap" style="position:relative;" id="globalSearchWrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="globalSearchInput" class="search-input" placeholder="Search interns, tasks…" autocomplete="off">
                <div id="globalSearchDropdown" style="display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1.5px solid var(--border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.10);z-index:9999;overflow:hidden;min-width:320px;"></div>
            </div>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?>
                <button type="button" class="btn btn-primary" onclick="openModal('addInternModal')" style="box-shadow:0 4px 12px rgba(123,94,167,.25);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> New Intern</button>
            <?php endif; ?>
            <?php if (!empty($pageHeaderBtn)) echo $pageHeaderBtn; ?>
            <a href="<?= $role === 'intern' ? BASE_URL . '/intern/notifications.php' : BASE_URL . '/admin/notifications.php' ?>" class="bell-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($notifCount > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
            <?php
            $avatarColor = $role === 'intern' ? '#DB2777' : ($role === 'hr' ? '#0F766E' : '#7B5EA7');
            $profileUrl  = $role === 'intern' ? BASE_URL . '/intern/profile.php' : BASE_URL . '/admin/profile.php';
            ?>
            <a href="<?= $profileUrl ?>">
                <div class="avatar" style="<?= avatarStyle($avatarColor, 38, 13) ?>"><?= htmlspecialchars($initials) ?></div>
            </a>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="page-content">

