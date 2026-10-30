<?php
// ============================================================================
// public/api/inventory_alerts.php — Internal REST/JSON Endpoint
// Covers: FR-18 (Low-stock + expiry alerts for dashboard/inventory), Phase 8 Sec. 50.3
// ============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/inventory_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_INVENTORY], true);

header('Content-Type: application/json; charset=utf-8');

$alerts = getInventoryAlerts($pdo, 90);
echo json_encode([
    'success' => true,
    'data'    => $alerts,
]);
