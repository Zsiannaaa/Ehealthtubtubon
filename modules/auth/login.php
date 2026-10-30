<?php
// ============================================================================
// modules/auth/login.php — Screen S1: Staff & Resident Sign-In
// Enforces FR-01 (Username + Password hash verification, session regeneration,
// deactivated account block) and BR-11 (Least-privilege role landing).
// Preserves the exact Tubtubon eHealth split-panel design from index.html.
// ============================================================================
require_once __DIR__ . '/../../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . roleLandingUrl());
    exit();
}

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'deactivated') {
    $error = 'Your account has been deactivated by the System Administrator (BR-11).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(appUrl('modules/auth/login.php'));

    $username = inputStr($_POST, 'username');
    $password = (string) ($_POST['password'] ?? '');

    $authResult = authenticateUser($pdo, $username, $password);
    if ($authResult['success']) {
        establishUserSession($authResult['user']);
        header('Location: ' . roleLandingUrl($authResult['user']['role_name']));
        exit();
    }

    $error = $authResult['error'] ?? 'Authentication failed.';
}

$baseUrl = appBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Tubtubon eHealth</title>
  <link rel="icon" type="image/png" href="<?= e($baseUrl) ?>/image/logo.png">
  <link rel="apple-touch-icon" href="<?= e($baseUrl) ?>/image/logo.png">
  <meta name="theme-color" content="#0B3B22">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <link rel="stylesheet" href="<?= e($baseUrl) ?>/css/style.css">
</head>
<body>

<div id="login-page">
  <div class="login-split">
    <!-- LEFT : Sign-In Form -->
    <div class="login-left">
      <div class="login-box">
        <div class="mb-2">
          <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size:11px;font-weight:700;">
            SYNTHETIC DEMO ENVIRONMENT • RA 10173 COMPLIANT
          </span>
        </div>
        <h1 class="login-h1">Sign in</h1>
        <p class="login-sub">Use your authorized staff or resident account to access the Barangay Tubtubon Health Center system.</p>

        <?php if ($error !== ''): ?>
        <div class="login-error mb-3" id="login-error">
          <i data-lucide="triangle-alert"></i><span><?= e($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(appUrl('modules/auth/login.php')) ?>" id="login-form" novalidate>
          <?= csrfField() ?>
          <div class="mb-3">
            <label for="login-username" class="form-label">Username</label>
            <input type="text" name="username" id="login-username" class="form-control input-lg"
                   placeholder="e.g. nurse.reyes" value="<?= e($_POST['username'] ?? '') ?>"
                   autocomplete="username" required autofocus>
          </div>
          <div class="mb-4">
            <label for="login-password" class="form-label">Password</label>
            <input type="password" name="password" id="login-password" class="form-control input-lg"
                   placeholder="Enter your password" autocomplete="current-password" required>
            <div class="form-text">Verified against bcrypt/Argon2 password hash (<strong>FR-01</strong>).</div>
          </div>

          <button type="submit" class="btn-login">
            <i data-lucide="log-in" class="me-1"></i>Sign In
          </button>
        </form>

        <details class="demo-details mt-3" open>
          <summary><i data-lucide="key-round" class="me-1"></i>Demo Accounts (Tap any row to auto-fill)</summary>
          <div class="demo-body">
            <div>
              <table>
                <tr class="demo-fill" data-user="admin" data-pass="Tubtubon2026!" style="cursor:pointer" title="Click to fill">
                  <td><strong>Administrator</strong><br><code class="small">admin</code></td>
                  <td>Users/Roles, Audit Logs, Backups, Reports (Oversight)</td>
                </tr>
                <tr class="demo-fill" data-user="nurse.reyes" data-pass="Tubtubon2026!" style="cursor:pointer" title="Click to fill">
                  <td><strong>Nurse / Midwife</strong><br><code class="small">nurse.reyes</code></td>
                  <td>Patients, 6-Program Records (+Verify), Docs, SMS, OCR Verify</td>
                </tr>
                <tr class="demo-fill" data-user="bhw.lim" data-pass="Tubtubon2026!" style="cursor:pointer" title="Click to fill">
                  <td><strong>BHW</strong><br><code class="small">bhw.lim</code></td>
                  <td>Patient Registration (UPI), Record Encoding, Schedule Assist</td>
                </tr>
                <tr class="demo-fill" data-user="inventory.cruz" data-pass="Tubtubon2026!" style="cursor:pointer" title="Click to fill">
                  <td><strong>Inventory Staff</strong><br><code class="small">inventory.cruz</code></td>
                  <td>Catalog, Stock-In, FEFO Stock-Out, Audited Disposal</td>
                </tr>
                <tr class="demo-fill" data-user="resident.juana" data-pass="Tubtubon2026!" style="cursor:pointer" title="Click to fill">
                  <td><strong>Resident (Deferred)</strong><br><code class="small">resident.juana</code></td>
                  <td>Strictly own record &amp; schedule (<code>TUB-2026-00001</code>)</td>
                </tr>
                <tr class="demo-fill" data-user="bhw.inactive" data-pass="Tubtubon2026!" style="cursor:pointer" title="Click to test BR-11">
                  <td><strong class="text-danger">Deactivated Demo</strong><br><code class="small">bhw.inactive</code></td>
                  <td>Tests <strong>BR-11</strong>: Sign-in blocked while history is kept</td>
                </tr>
              </table>
            </div>
          </div>
        </details>

        <p class="login-footnote mt-3">Not paperless — but searchable &amp; traceable.</p>
      </div>
    </div>

    <!-- RIGHT : Brand Panel -->
    <div class="login-right d-none d-lg-flex">
      <div>
        <div class="panel-brand">
          <div class="brand-tile"><img src="<?= e($baseUrl) ?>/image/logo.png" alt="Tubtubon eHealth logo"></div>
          <div class="brand-text">
            <span class="brand-name">Tubtubon eHealth</span>
            <span class="brand-sub" style="color:rgba(255,255,255,.55)">BARANGAY TUBTUBON HEALTH CENTER</span>
          </div>
        </div>

        <h2 class="panel-hero">Every patient record.<br>One organized system.</h2>
        <p class="panel-copy">Tubtubon eHealth is the health information and service system of Barangay Tubtubon Health Center, Sibulan, Negros Oriental — one organized place for patient records, services, documents, supplies, and schedules, built to work alongside the paper workflows the clinic relies on every day.</p>
      </div>

      <svg class="ecg-wrap ecg-line" viewBox="0 0 1200 90" fill="none" preserveAspectRatio="none" height="90">
        <polyline points="0,45 140,45 170,45 185,20 200,70 215,10 230,80 245,38 260,45 420,45 450,45 465,25 480,65 495,15 510,75 525,40 540,45 720,45 750,45 765,22 780,68 795,12 810,78 825,36 840,45 1030,45 1060,45 1075,26 1090,62 1105,18 1120,72 1135,42 1150,45 1200,45" stroke="#ffffff" stroke-opacity=".85" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
  </div>
</div>

<script>
  if (window.lucide) {
    lucide.createIcons();
  }
  document.querySelectorAll('.demo-fill').forEach(function(row) {
    row.addEventListener('click', function() {
      document.getElementById('login-username').value = this.getAttribute('data-user') || '';
      document.getElementById('login-password').value = this.getAttribute('data-pass') || '';
    });
  });
</script>
</body>
</html>
