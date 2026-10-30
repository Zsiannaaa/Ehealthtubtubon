<?php
// ============================================================================
// public/users.php — Administrator User & Role Management + Immutable Audit Logs
// Covers: FR-01 (Deactivate without deletion), FR-02/FR-04 (Admin user/role mgmt,
//         audit trail inspection), BR-11 (Least-privilege), BR-12 (Immutable audit)
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/patients_module.php';
requireRole([ROLE_ADMIN]);

$currentUserId = currentUserId();

// Handle POST Actions (Create User, Update User, Toggle Active/Deactivated)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(appUrl('public/users.php'));
    $action = inputStr($_POST, 'action');

    if ($action === 'create_user') {
        $err = createUserAccount(
            $pdo,
            inputStr($_POST, 'username'),
            (string) ($_POST['password'] ?? ''),
            inputStr($_POST, 'full_name'),
            inputInt($_POST, 'role_id'),
            $currentUserId,
            inputInt($_POST, 'patient_id') ?: null
        );
        setFlash($err ? 'error' : 'success', $err ?? 'User account created successfully and logged to audit trail.');
        header('Location: ' . appUrl('public/users.php'));
        exit();
    }

    if ($action === 'update_user') {
        $err = updateUserAccount(
            $pdo,
            inputInt($_POST, 'user_id'),
            inputStr($_POST, 'full_name'),
            inputInt($_POST, 'role_id'),
            (string) ($_POST['new_password'] ?? ''),
            $currentUserId,
            inputInt($_POST, 'patient_id') ?: null
        );
        setFlash($err ? 'error' : 'success', $err ?? 'User account updated successfully.');
        header('Location: ' . appUrl('public/users.php'));
        exit();
    }

    if ($action === 'toggle_status') {
        $err = toggleUserAccountStatus(
            $pdo,
            inputInt($_POST, 'target_user_id'),
            $currentUserId
        );
        setFlash($err ? 'error' : 'success', $err ?? 'Account status updated (BR-11: history preserved).');
        header('Location: ' . appUrl('public/users.php'));
        exit();
    }
}

$editUser = null;
if (isset($_GET['edit_id'])) {
    $editUser = getUserById($pdo, inputInt($_GET, 'edit_id'));
}

$auditActionFilter = inputStr($_GET, 'audit_action');
$auditSearch       = inputStr($_GET, 'audit_q');

$roles       = getAllRoles($pdo);
$users       = getAllUsers($pdo);
$allPatients = searchPatients($pdo, '', '', 500);
$auditLogs   = getAuditLogs($pdo, 100, $auditActionFilter, $auditSearch);

// Distinct action types for filter dropdown
$actionTypes = $pdo->query("SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle    = 'Users, Roles & Immutable Audit Trail';
$pageSubtitle = 'FR-01 to FR-04 • BR-11 Least-Privilege • BR-12 Append-Only Audit Logs';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <!-- Left Column: Create / Edit User Form -->
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i data-lucide="<?= $editUser ? 'user-pen' : 'user-plus' ?>" class="me-1"></i>
          <?= $editUser ? 'Edit Account: ' . e($editUser['username']) : 'Create Staff / Resident Account' ?>
        </h6>

        <form method="POST" action="<?= e(appUrl('public/users.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editUser ? 'update_user' : 'create_user' ?>">
          <?php if ($editUser): ?>
            <input type="hidden" name="user_id" value="<?= (int) $editUser['user_id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control"
                   value="<?= e($editUser['username'] ?? '') ?>"
                   <?= $editUser ? 'readonly disabled' : 'required' ?>
                   placeholder="e.g. bhw.garcia">
            <?php if (!$editUser): ?>
              <div class="form-text">Unique case-insensitive login identifier.</div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label class="form-label">Full Name / Display Title <span class="text-danger">*</span></label>
            <input type="text" name="full_name" class="form-control" required
                   value="<?= e($editUser['full_name'] ?? '') ?>"
                   placeholder="e.g. BHW Garcia">
          </div>

          <div class="mb-3">
            <label class="form-label">Assigned Role (Least-Privilege RBAC) <span class="text-danger">*</span></label>
            <select name="role_id" id="userRoleSelect" class="form-select" required onchange="toggleResidentPatientField()">
              <option value="">-- Select Role --</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['role_id'] ?>"
                        data-role-name="<?= e($r['role_name']) ?>"
                  <?= ((int) ($editUser['role_id'] ?? 0) === (int) $r['role_id']) ? 'selected' : '' ?>>
                  <?= e($r['role_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3" id="residentPatientGroup" style="display:none;">
            <label class="form-label">Linked Patient Profile (`users.patient_id` FK) <span class="text-danger">*</span></label>
            <select name="patient_id" id="residentPatientSelect" class="form-select">
              <option value="">-- Select Registered Patient (UPI) --</option>
              <?php foreach ($allPatients as $pt): ?>
                <option value="<?= (int) $pt['patient_id'] ?>"
                  <?= ((int) ($editUser['patient_id'] ?? 0) === (int) $pt['patient_id']) ? 'selected' : '' ?>>
                  <?= e($pt['upi'] . ' — ' . $pt['last_name'] . ', ' . $pt['first_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">FR-24 / BR-11: Strict 1-to-1 FK binding so Resident portal sessions can only read their own UPI record.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">
              <?= $editUser ? 'Reset Password (leave blank to keep current)' : 'Password <span class="text-danger">*</span>' ?>
            </label>
            <input type="password" name="<?= $editUser ? 'new_password' : 'password' ?>"
                   class="form-control" <?= $editUser ? '' : 'required' ?>
                   placeholder="Min. 8 characters (bcrypt hashed)">
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success flex-grow-1" style="background:#0B3B22;border-color:#0B3B22;">
              <i class="bi bi-check2-circle me-1"></i><?= $editUser ? 'Save Changes' : 'Create Account' ?>
            </button>
            <?php if ($editUser): ?>
              <a href="<?= e(appUrl('public/users.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <hr class="my-3">
        <div class="small text-muted">
          <strong>BR-11 Policy:</strong> User accounts are never deleted from the database so historical `recorded_by`, `verified_by`, `filed_by`, and `performed_by` links remain intact. Use <strong>Deactivate</strong> to revoke sign-in privileges immediately.
        </div>
      </div>
    </div>
  </div>

  <!-- Right Column: System Accounts Table -->
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <h6 class="fw-700 text-green mb-0">
            <i data-lucide="shield-check" class="me-1"></i>System Accounts (<?= count($users) ?>)
          </h6>
          <span class="badge bg-light text-dark border">5 Defined Roles • Server-Side Guarded</span>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Username</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><code><?= e($u['username']) ?></code></td>
                <td class="fw-600"><?= e($u['full_name']) ?></td>
                <td>
                  <span class="badge bg-secondary-subtle text-dark border">
                    <?= e($u['role_name']) ?>
                  </span>
                  <?php if (!empty($u['bound_upi'])): ?>
                    <div class="mt-1">
                      <span class="badge bg-info-subtle text-info-emphasis border font-monospace" style="font-size:10px;">
                        FK: <?= e($u['bound_upi']) ?>
                      </span>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($u['status'] === 'Active'): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                  <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Deactivated</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?= $u['last_login_at'] ? e(date('M d, Y h:i A', strtotime($u['last_login_at']))) : 'Never' ?>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex gap-1">
                    <a href="<?= e(appUrl('public/users.php?edit_id=' . (int) $u['user_id'])) ?>"
                       class="btn btn-sm btn-outline-primary" title="Edit user">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <?php if ((int) $u['user_id'] !== $currentUserId): ?>
                    <form method="POST" action="<?= e(appUrl('public/users.php')) ?>" class="d-inline"
                          onsubmit="return confirm('Change status for <?= e(addslashes($u['full_name'])) ?>?');">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="target_user_id" value="<?= (int) $u['user_id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $u['status'] === 'Active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                              title="<?= $u['status'] === 'Active' ? 'Deactivate Account' : 'Reactivate Account' ?>">
                        <i class="bi <?= $u['status'] === 'Active' ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
                        <?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Immutable Audit Trail Table (BR-12) -->
<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h6 class="fw-700 text-green mb-0">
          <i data-lucide="scroll-text" class="me-1"></i>Immutable Activity Audit Trail (`audit_logs` — D13)
        </h6>
        <small class="text-muted">BR-12: Append-only (`BIGINT UNSIGNED`). Zero `UPDATE` or `DELETE` operations permitted.</small>
      </div>

      <form method="GET" action="<?= e(appUrl('public/users.php')) ?>" class="d-flex gap-2 flex-wrap">
        <select name="audit_action" class="form-select form-select-sm" style="width:auto;">
          <option value="">All Action Types</option>
          <?php foreach ($actionTypes as $act): ?>
            <option value="<?= e($act) ?>" <?= ($auditActionFilter === $act) ? 'selected' : '' ?>>
              <?= e($act) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="audit_q" class="form-control form-control-sm" style="width:210px;"
               placeholder="Search description / actor…" value="<?= e($auditSearch) ?>">
        <button type="submit" class="btn btn-sm btn-outline-success">Filter</button>
        <?php if ($auditActionFilter !== '' || $auditSearch !== ''): ?>
          <a href="<?= e(appUrl('public/users.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Log ID</th>
            <th>Timestamp</th>
            <th>Actor</th>
            <th>Action Type</th>
            <th>Description</th>
            <th>Target Record</th>
            <th>Source IP</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($auditLogs)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">No matching audit log entries found.</td>
          </tr>
          <?php else: ?>
            <?php foreach ($auditLogs as $log): ?>
            <tr>
              <td><code>#<?= (int) $log['log_id'] ?></code></td>
              <td class="small text-nowrap"><?= e($log['created_at']) ?></td>
              <td class="small">
                <?php if (!empty($log['full_name'])): ?>
                  <strong><?= e($log['full_name']) ?></strong>
                  <span class="text-muted">(<?= e($log['role_name'] ?? '') ?>)</span>
                <?php else: ?>
                  <span class="text-muted">System / Unauthenticated</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-dark-subtle text-dark border" style="font-size:11px;">
                  <?= e($log['action_type']) ?>
                </span>
              </td>
              <td class="small"><?= e($log['description']) ?></td>
              <td class="small">
                <?php if (!empty($log['affected_record_type'])): ?>
                  <code><?= e($log['affected_record_type']) ?>:<?= e($log['affected_record_id'] ?? '-') ?></code>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small"><code><?= e($log['source_ip'] ?? '-') ?></code></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function toggleResidentPatientField() {
  var roleSel = document.getElementById('userRoleSelect');
  var group   = document.getElementById('residentPatientGroup');
  var patSel  = document.getElementById('residentPatientSelect');
  if (!roleSel || !group || !patSel) return;
  var opt = roleSel.options[roleSel.selectedIndex];
  var isResident = opt && opt.getAttribute('data-role-name') === '<?= e(ROLE_RESIDENT) ?>';
  group.style.display = isResident ? 'block' : 'none';
  patSel.required = !!isResident;
  if (!isResident) {
    patSel.value = '';
  }
}
document.addEventListener('DOMContentLoaded', toggleResidentPatientField);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
