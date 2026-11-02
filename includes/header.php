<?php

if (function_exists('pusamsSecurityHeaders')) pusamsSecurityHeaders();
elseif (function_exists('applySecurityHeaders')) applySecurityHeaders();
if (function_exists('autoSyncTick')) autoSyncTick();
elseif (function_exists('autoSync')) autoSync();
if (file_exists(__DIR__.'/cluster_context.php')) require_once __DIR__.'/cluster_context.php';
if (file_exists(__DIR__.'/cascade_helper.php')) require_once __DIR__.'/cascade_helper.php';
$notifCount = isLoggedIn() ? unreadNotificationCount((int)($_SESSION['user_id'] ?? 0)) : 0;
$appName    = getSetting('app_name', defined('APP_NAME') ? APP_NAME : 'PUSAMS');
$instName   = getSetting('institution_name', defined('INSTITUTION_NAME') ? INSTITUTION_NAME : 'Paktia University');
$rtl        = isRtl();
$dir        = $rtl ? 'rtl' : 'ltr';
$lang       = $GLOBALS['currentLang'] ?? 'ps';
$isDark     = !empty($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === '1';
$darkClass  = $isDark ? 'dark-mode' : '';
$roleId     = (int)($_SESSION['role_id'] ?? 0);
$pageSlug   = preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'), PATHINFO_FILENAME));
$pageClass  = $pageSlug !== '' ? 'page-' . strtolower($pageSlug) : 'page-app';

/* FIX #2 (v23.1) — د سایدبار/تاپبار د انځور cache-buster.
 * د $_SESSION['photo_v'] (د اپلوډ پر وخت تازه کیږي) او د فایل mtime دواړه
 * د cache busting لپاره کاروو. داسې browser به سمدستي تازه انځور وباسي.
 */
$__hdrPhotoFile = $_SESSION['photo'] ?? 'default.png';
// Sync photo from DB if session value is stale or file is missing
$__uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__).'/uploads/');
if ($__hdrPhotoFile !== 'default.png' && !is_file($__uploadDir . $__hdrPhotoFile)) {
    // File not found on disk – refresh from DB
    try {
        $__dbRefresh = getDB()->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
        $__dbRefresh->execute([(int)($_SESSION['user_id'] ?? 0)]);
        $__dbPhoto = $__dbRefresh->fetchColumn();
        if ($__dbPhoto && is_file($__uploadDir . $__dbPhoto)) {
            $_SESSION['photo']   = $__dbPhoto;
            $_SESSION['photo_v'] = (int)@filemtime($__uploadDir . $__dbPhoto);
            $__hdrPhotoFile      = $__dbPhoto;
        } else {
            $__hdrPhotoFile = 'default.png';
        }
    } catch (Throwable $__e) { $__hdrPhotoFile = 'default.png'; }
}
$__hdrPhotoVer  = $_SESSION['photo_v'] ?? '';
if ($__hdrPhotoVer === '' && $__hdrPhotoFile !== 'default.png') {
    $__hdrPhotoPath = $__uploadDir . $__hdrPhotoFile;
    $__hdrPhotoVer  = is_file($__hdrPhotoPath) ? (int)@filemtime($__hdrPhotoPath) : '';
}
$__hdrPhotoQS = $__hdrPhotoVer !== '' ? ('?v='.urlencode((string)$__hdrPhotoVer)) : '';

function headerRoleName(int $roleId): string { return roleDisplayName($roleId); }

function renderCategorisedMenu(int $roleId): void {
    
    $curRaw  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    
    $normalize = static function (string $p): string {
        if ($p === '') return '';
        
        $p = preg_replace('#/index\.php$#', '/', $p);
        return $p;
    };
    $cur = $normalize($curRaw);
    $tree = roleMenuTree($roleId);
    foreach ($tree as $category => $items) {
        echo '<div class="sidebar-category">'.h($category).'</div>';
        foreach ($items as $item) {
            $targetPath = $normalize(parse_url($item['url'], PHP_URL_PATH) ?: '');
            
            $active = ($targetPath !== '' && $cur === $targetPath) ? 'active' : '';
            echo '<a href="'.h($item['url']).'" class="sidebar-item '.$active.'"><i class="fas '.h($item['icon']).'"></i><span>'.h($item['label']).'</span></a>';
        }
    }
}

function renderNotifications(int $userId, int $limit = 10): void {
    if (!$userId) return;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        $items = $stmt->fetchAll();
        if (!$items) { echo '<div class="p-3 text-muted text-center small">'.t('no_records').'</div>'; return; }
        foreach ($items as $n) {
            $read = $n['is_read'] ? '' : 'notif-unread';
            $icon = ['info'=>'fa-info-circle text-info','warning'=>'fa-exclamation-triangle text-warning','success'=>'fa-check-circle text-success','danger'=>'fa-exclamation-circle text-danger'][$n['type']] ?? 'fa-bell';
            $href = normalizeAppLink((string)($n['link'] ?? '')) ?: '#';
            echo '<a href="'.h($href).'" class="dropdown-item notif-item '.$read.'"><i class="fas '.$icon.' me-2"></i><div><div class="notif-title">'.h($n['title']).'</div><div class="notif-time text-muted small">'.h(date('d M, H:i', strtotime($n['created_at']))).'</div></div></a>';
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>" dir="<?= $dir ?>">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <meta name="theme-color" content="#1a73e8"/>
  <meta name="mobile-web-app-capable" content="yes"/>
  <title><?= h($pageTitle ?? $appName) ?> | <?= h($appName) ?></title>
  <link rel="manifest" href="<?= BASE_URL ?>/manifest.json"/>
  <link rel="icon" href="<?= BASE_URL ?>/assets/images/favicon.png"/>
  <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/images/pusams-logo-192.png"/>
  
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/<?= $rtl ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css' ?>"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app-theme.css"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/responsive.css"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-modern.css"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-polish.css"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-ultra.css"/>
  <?php if ($isDark): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dark.css"/><?php endif; ?>
  <!-- CLEAN OVERRIDE — د Titleونو bold کول او د اوږدو پیغامونو پاکول. -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-clean.css?v=1"/>
  <!-- ELITE DESIGN LAYER — د ټول سیستم وروستی پروفیشنل ډیزین (له لوګین پرته). -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-elite.css?v=1"/>
  <!-- LAYOUT FIX (FIX 17) — د sidebar/topbar/main-wrapper د لیږوټ نهایي اصلاح. باید تل وروستی لوډ شي. -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-layout-fix.css?v=17"/>
  <!-- QR Attendance additive stylesheet (safe to remove; scoped selectors only) -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pusams-qr.css?v=2"/>
</head>
<body class="<?= trim($darkClass.' dashboard-mono '.$pageClass.' lang-'.$lang) ?>" data-active-lang="<?= h($lang) ?>" data-bs-theme="<?= $isDark ? 'dark' : 'light' ?>">
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<nav class="sidebar" id="mainSidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <img src="<?= BASE_URL ?>/assets/images/pusams-logo.png" alt="PUSAMS" class="sidebar-brand-logo" onerror="this.style.display='none'"/>
      <span class="sidebar-brand-text">
        <strong><?= h($appName) ?></strong>
        <small class="d-block text-muted" style="font-size:.7rem"><?= h($instName) ?></small>
      </span>
    </div>
    <button class="sidebar-close d-md-none" onclick="closeSidebar()"><i class="fas fa-times"></i></button>
  </div>
  <div class="sidebar-user">
    <img src="<?= BASE_URL ?>/uploads/<?= h($__hdrPhotoFile) ?><?= h($__hdrPhotoQS) ?>" onerror="this.src='<?= BASE_URL ?>/assets/images/default.png'" alt="Avatar" class="sidebar-avatar"/>
    <div>
      <div class="sidebar-username"><?= h($_SESSION['full_name'] ?? '') ?></div>
      <span class="badge bg-primary sidebar-role"><?= h(headerRoleName($roleId)) ?></span>
      <div class="small text-muted mt-1"><?= h(userFacultyName((int)($_SESSION['faculty_id'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="sidebar-nav"><?php renderCategorisedMenu($roleId); ?></div>
</nav>
<div class="main-wrapper" id="mainWrapper">
<header class="topbar">
  <button class="topbar-toggle d-md-none" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
  <div class="topbar-title d-none d-md-block"><?= h($pageTitle ?? '') ?></div>
  <div class="topbar-actions">
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-globe"></i> <?= strtoupper($lang) ?></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="?lang=ps">پښتو</a></li>
        <li><a class="dropdown-item" href="?lang=fa">دری</a></li>
        <li><a class="dropdown-item" href="?lang=en">English</a></li>
      </ul>
    </div>
    <button class="btn btn-sm btn-outline-secondary" onclick="toggleDarkMode()" title="<?= t('dark_mode') ?>"><i class="fas <?= $isDark ? 'fa-sun' : 'fa-moon' ?>"></i></button>
    <span class="badge bg-success d-none d-md-inline-flex align-items-center pusams-live-pill" id="pusamsLivePill" title="Live">
      <span class="pusams-live-blink me-1"></span> Live
      <small class="ms-1 opacity-75" id="pusamsLiveStamp"></small>
    </span>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown"><i class="fas fa-bell"></i><?php if ($notifCount > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge"><?= $notifCount ?></span><?php endif; ?></button>
      <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom"><strong><?= t('notifications') ?></strong><a href="<?= BASE_URL ?>/notifications/mark_read.php" class="small text-muted"><?= t('mark_all_read') ?></a></div>
        <div id="notifList" class="notif-list"><?php renderNotifications((int)($_SESSION['user_id'] ?? 0), 10); ?></div>
        <div class="p-2 border-top text-center"><a href="<?= BASE_URL ?>/notifications/" class="small"><?= t('view') ?></a></div>
      </div>
    </div>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><img src="<?= BASE_URL ?>/uploads/<?= h($__hdrPhotoFile) ?><?= h($__hdrPhotoQS) ?>" onerror="this.src='<?= BASE_URL ?>/assets/images/default.png'" alt="" width="24" height="24" class="rounded-circle"/></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/profile/"><i class="fas fa-user me-2"></i><?= t('profile') ?></a></li>
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/profile/password.php"><i class="fas fa-key me-2"></i><?= t('change_password') ?></a></li>
        <li><hr class="dropdown-divider"/></li>
        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="fas fa-sign-out-alt me-2"></i><?= t('logout') ?></a></li>
      </ul>
    </div>
  </div>
</header>
<div class="container-fluid px-4 pt-3" id="flashContainer">
<?php foreach (flashGet() as $flash): ?>
  <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert"><?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
</div>
<main class="page-content container-fluid px-4 pb-5">
