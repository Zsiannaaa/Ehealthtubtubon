<?php
// ============================================================================
// index.php — Application Root Entry Point
// Preserves index.html as the static prototype preview while routing PHP
// requests into the authenticated application or sign-in screen.
// ============================================================================
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . roleLandingUrl());
    exit();
}

header('Location: ' . appUrl('modules/auth/login.php'));
exit();
