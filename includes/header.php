<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// includes/header.php — Shared Authenticated App Shell Header & Topbar
// Enforces requireLogin(), bfcache reload protection, synthetic demo watermark,
// and live patient quick-search in topbar (FR-06).
// ============================================================================

require_once __DIR__ . '/auth.php';
requireLogin();

$baseUrl      = appBaseUrl();
$pageTitle    = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? 'Barangay Tubtubon Health Center Information & Service System';
$currentRole  = getUserRole();
$fullName     = $_SESSION['full_name'] ?? 'Staff User';

// Count today's scheduled appointments for notification bell
$todayApptCount = 0;
try {
    $bellStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE scheduled_date = CURDATE() AND status = 'Scheduled'");
    $bellStmt->execute();
    $todayApptCount = (int) $bellStmt->fetchColumn();
} catch (Throwable $e) {
    $todayApptCount = 0;
}

$roleColors = [
    ROLE_ADMIN     => '#0E4D2B',
    ROLE_NURSE     => '#2456A6',
    ROLE_BHW       => '#1B7A4D',
    ROLE_INVENTORY => '#8A5A00',
    ROLE_RESIDENT  => '#20c997',
];
$roleBadgeColor = $roleColors[$currentRole] ?? '#0E4D2B';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> — Tubtubon eHealth</title>
  <link rel="icon" type="image/png" href="<?= e($baseUrl) ?>/image/logo.png">
  <link rel="apple-touch-icon" href="<?= e($baseUrl) ?>/image/logo.png">
  <meta name="theme-color" content="#0B3B22">
  <meta name="csrf-token" content="<?= e(csrfToken()) ?>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="<?= e($baseUrl) ?>/css/style.css">
  <style>
    .synthetic-watermark-bar {
      background: #fff8e6;
      border-bottom: 1px solid #f5dca2;
      color: #6b4c00;
      font-size: 12px;
      font-weight: 600;
      padding: 6px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
    }
    .suggest-dropdown {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      right: 0;
      min-width: 320px;
      background: #fff;
      border: 1px solid rgba(0,0,0,.12);
      border-radius: 10px;
      box-shadow: 0 12px 28px rgba(0,0,0,.12);
      z-index: 1050;
      max-height: 300px;
      overflow-y: auto;
    }
    .suggest-item {
      display: block;
      padding: 8px 12px;
      text-decoration: none;
      color: #1f2937;
      border-bottom: 1px solid #f1f5f9;
      font-size: 13px;
    }
    .suggest-item:hover {
      background: #f0fdf4;
      color: #0B3B22;
    }
    @media print {
      .app-sidebar, .topbar, .synthetic-watermark-bar, .no-print {
        display: none !important;
      }
      .app-main, .content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
      }
    }
  </style>
</head>
<body>
<script>
  // Prevent back-forward cache (bfcache) from restoring protected pages after logout (FR-01)
  window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
      window.location.reload();
    }
  });
  window.APP_BASE_URL = <?= json_encode($baseUrl) ?>;
  window.CSRF_TOKEN   = <?= json_encode(csrfToken()) ?>;
</script>

<div id="app-shell" class="app-shell">

  <!-- Desktop Sidebar -->
  <aside class="app-sidebar d-none d-lg-flex">
    <div class="sidebar-inner">
      <div class="brand-row">
        <div class="brand-tile"><img src="<?= e($baseUrl) ?>/image/logo.png" alt="Tubtubon eHealth logo"></div>
        <div class="brand-text">
          <span class="brand-name">Tubtubon eHealth</span>
          <span class="brand-sub">Tubtubon Health Center</span>
        </div>
      </div>
      <div class="side-scroll" id="sidebar-nav">
        <?php require __DIR__ . '/sidebar.php'; ?>
      </div>
      <div class="logout-wrap">
        <a href="<?= e(appUrl('modules/auth/logout.php')) ?>" class="side-link logout-btn text-decoration-none">
          <i data-lucide="log-out"></i><span>Logout</span>
        </a>
      </div>
    </div>
  </aside>

  <!-- Mobile Sidebar Offcanvas -->
  <div class="offcanvas offcanvas-start bg-dark" tabindex="-1" id="mobileSidebar" style="width:252px;background:#17181c!important">
    <div class="offcanvas-body p-0">
      <div class="sidebar-inner">
        <div class="brand-row">
          <div class="brand-tile"><img src="<?= e($baseUrl) ?>/image/logo.png" alt="Tubtubon eHealth logo"></div>
          <div class="brand-text">
            <span class="brand-name">Tubtubon eHealth</span>
            <span class="brand-sub">Tubtubon Health Center</span>
          </div>
        </div>
        <div class="side-scroll" id="mobileSidebarNav">
          <?php require __DIR__ . '/sidebar.php'; ?>
        </div>
        <div class="logout-wrap">
          <a href="<?= e(appUrl('modules/auth/logout.php')) ?>" class="side-link logout-btn text-decoration-none">
            <i data-lucide="log-out"></i><span>Logout</span>
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="app-main">

    <!-- Synthetic Demo Watermark Banner (Phase 8 Sec. 55 & NFR-LEG-01) -->
    <div class="synthetic-watermark-bar no-print">
      <div>
        <i class="bi bi-shield-lock-fill me-1"></i>
        <strong>SYNTHETIC DEMO DATA WATERMARK:</strong> All patient records, UPIs, and scans shown in this prototype are fictional (RA 10173 compliant).
      </div>
      <div class="d-none d-md-block">
        Dual-Track Hybrid Mode Active • Paper Fallback Supported (<strong>BR-13</strong>)
      </div>
    </div>

    <!-- TOPBAR -->
    <header class="topbar no-print">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-light-ghost burger-btn d-none d-lg-inline-flex" type="button" id="sidebarCollapseBtn" title="Collapse / expand sidebar">
          <i data-lucide="menu"></i>
        </button>
        <button class="btn btn-light-ghost d-lg-none burger-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-label="Menu">
          <i data-lucide="menu"></i>
        </button>
        <div>
          <h1 class="page-title" id="pageTitle"><?= e($pageTitle) ?></h1>
          <p class="page-subtitle" id="pageSubtitle"><?= e($pageSubtitle) ?></p>
        </div>
      </div>

      <div class="topbar-right">
        <?php if (in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true)): ?>
        <div class="search-pill d-none d-md-flex position-relative">
          <i data-lucide="search"></i>
          <input type="search" id="topbar-search" placeholder="Quick UPI / patient lookup (FR-06)…" aria-label="Search patients" autocomplete="off">
          <div id="topbar-suggest-box" class="suggest-dropdown d-none"></div>
        </div>
        <?php endif; ?>

        <?php if (in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true)): ?>
        <a href="<?= e(appUrl('public/scheduling.php')) ?>" class="icon-btn position-relative text-decoration-none" title="Today's scheduled appointments">
          <i data-lucide="bell"></i>
          <?php if ($todayApptCount > 0): ?>
          <span class="bell-badge"><?= (int) $todayApptCount ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="dropdown">
          <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="d-flex align-items-center gap-2">
              <span class="badge rounded-pill" style="background:<?= e($roleBadgeColor) ?>;font-size:11px;">
                <?= e($currentRole) ?>
              </span>
              <span class="fw-600 small d-none d-sm-inline"><?= e($fullName) ?></span>
            </span>
            <i data-lucide="chevron-down" class="caret"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end user-menu shadow-sm">
            <li class="px-3 py-2 border-bottom">
              <div class="fw-bold small"><?= e($fullName) ?></div>
              <div class="text-muted" style="font-size:11px;">Role: <?= e($currentRole) ?></div>
            </li>
            <?php if ($currentRole === ROLE_ADMIN): ?>
            <li>
              <a class="dropdown-item small py-2" href="<?= e(appUrl('public/users.php')) ?>">
                <i class="bi bi-people me-2"></i>Manage Users &amp; Audit Logs
              </a>
            </li>
            <?php endif; ?>
            <li>
              <a class="dropdown-item small py-2 text-danger" href="<?= e(appUrl('modules/auth/logout.php')) ?>">
                <i class="bi bi-box-arrow-right me-2"></i>Sign Out
              </a>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="content" id="main-content">

      <?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
      <div class="alert alert-danger d-flex align-items-center justify-content-between shadow-sm mb-4" role="alert">
        <div>
          <i class="bi bi-shield-exclamation me-2"></i>
          <strong>Least-Privilege Access Denied (BR-11):</strong> Your current role (<code><?= e($currentRole) ?></code>) is not authorized for that page or action. This event was recorded in the immutable audit log.
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['success'])): ?>
      <div class="alert alert-success d-flex align-items-center justify-content-between shadow-sm mb-4" role="alert">
        <div>
          <i class="bi bi-check-circle-fill me-2"></i><?= e($_SESSION['success']) ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['success']); endif; ?>

      <?php if (!empty($_SESSION['error'])): ?>
      <div class="alert alert-danger d-flex align-items-center justify-content-between shadow-sm mb-4" role="alert">
        <div>
          <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($_SESSION['error']) ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['error']); endif; ?>
