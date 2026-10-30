<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// includes/sidebar.php — Role-Aware Sidebar Navigation (Phase 8 Sec. 53.1)
//
// NOTE: Hiding menu links here is strictly for UX clarity.
// Every protected PHP page and /api endpoint enforces server-side RBAC
// via requireRole() at the top of the file (FR-02, BR-11).
// ============================================================================

$currentRole   = getUserRole();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

$navItems = [
    [
        'file'  => 'dashboard.php',
        'icon'  => 'layout-dashboard',
        'label' => 'Dashboard & Overview',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW, ROLE_INVENTORY],
    ],
    [
        'file'  => 'patients.php',
        'icon'  => 'users',
        'label' => 'Patient Registry (UPI)',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW],
        'badge' => ($currentRole === ROLE_ADMIN) ? 'View' : null,
    ],
    [
        'file'  => 'service_records.php',
        'icon'  => 'clipboard-plus',
        'label' => 'Service Records (6 Prog)',
        'roles' => [ROLE_NURSE, ROLE_BHW],
    ],
    [
        'file'  => 'documents.php',
        'icon'  => 'folder-open',
        'label' => 'Document Registry (4-State)',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW],
        'badge' => ($currentRole === ROLE_ADMIN) ? 'View' : null,
    ],
    [
        'file'  => 'templates.php',
        'icon'  => 'printer',
        'label' => 'Printable Blank Templates',
        'roles' => [ROLE_NURSE, ROLE_BHW],
    ],
    [
        'file'  => 'inventory.php',
        'icon'  => 'boxes',
        'label' => 'Inventory & FEFO Alerts',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_INVENTORY],
        'badge' => ($currentRole !== ROLE_INVENTORY) ? 'Alerts' : null,
    ],
    [
        'file'  => 'stock_moves.php',
        'icon'  => 'arrow-right-left',
        'label' => 'Stock Ledger & Disposal',
        'roles' => [ROLE_ADMIN, ROLE_INVENTORY],
        'badge' => ($currentRole === ROLE_ADMIN) ? 'View' : null,
    ],
    [
        'file'  => 'scheduling.php',
        'icon'  => 'calendar',
        'label' => 'Scheduling Calendar',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW],
    ],
    [
        'file'  => 'sms.php',
        'icon'  => 'messages-square',
        'label' => 'SMS Reminders & Logs',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW],
        'badge' => ($currentRole === ROLE_ADMIN) ? 'Logs' : null,
    ],
    [
        'file'  => 'ocr.php',
        'icon'  => 'scan-text',
        'label' => 'OCR Digitization (HITL)',
        'roles' => [ROLE_NURSE, ROLE_BHW],
        'badge' => 'Opt',
    ],
    [
        'file'  => 'reports.php',
        'icon'  => 'chart-column',
        'label' => 'Reports & Backup',
        'roles' => [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW, ROLE_INVENTORY],
    ],
    [
        'file'  => 'users.php',
        'icon'  => 'user-cog',
        'label' => 'Users, Roles & Audit',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'file'  => 'resident_portal.php',
        'icon'  => 'heart-pulse',
        'label' => 'My Health Portal',
        'roles' => [ROLE_RESIDENT],
    ],
];

foreach ($navItems as $item):
    if (!in_array($currentRole, $item['roles'], true)) {
        continue;
    }
    $isActive = ($currentScript === $item['file']) ? 'active' : '';
    $href     = appUrl('public/' . $item['file']);
?>
<a href="<?= e($href) ?>" class="side-link <?= e($isActive) ?>">
  <i data-lucide="<?= e($item['icon']) ?>"></i>
  <span class="flex-grow-1"><?= e($item['label']) ?></span>
  <?php if (!empty($item['badge'])): ?>
    <span class="badge rounded-pill bg-secondary-subtle text-light border-0" style="font-size:10px;background:rgba(255,255,255,.15)!important;">
      <?= e($item['badge']) ?>
    </span>
  <?php endif; ?>
</a>
<?php endforeach; ?>
