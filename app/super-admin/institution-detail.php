<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('super_admin');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/app/super-admin/institutions.php'); exit; }

$stmt = $db->prepare(
    "SELECT i.*, u.email AS admin_email, u.full_name AS admin_name, u.mobile AS admin_mobile,
            u.last_login AS admin_last_login, u.is_active AS admin_active
     FROM institutions i
     LEFT JOIN users u ON u.id = i.admin_id
     WHERE i.id = ?"
);
$stmt->execute([$id]);
$inst = $stmt->fetch();
if (!$inst) { setFlash('error', 'Institution not found.'); header('Location: ' . BASE_URL . '/app/super-admin/institutions.php'); exit; }

// Staff count
$staffCount = (int)$db->prepare("SELECT COUNT(*) FROM staff WHERE institution_id = ? AND is_active = 1")->execute([$id]) ? 0 : 0;
$sStmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE institution_id = ? AND is_active = 1");
$sStmt->execute([$id]);
$staffCount = (int)$sStmt->fetchColumn();

// Member count
$mStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE institution_id = ? AND is_active = 1");
$mStmt->execute([$id]);
$memberCount = (int)$mStmt->fetchColumn();

// ── POST: Approve ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    verifyCsrf();

    $validFrom  = $_POST['valid_from']  ?? '';
    $validUntil = $_POST['valid_until'] ?? '';

    if (!$validFrom || !$validUntil) {
        $approveError = 'Please provide both validity start and end dates.';
    } elseif ($validUntil <= $validFrom) {
        $approveError = 'Validity end date must be after the start date.';
    } else {
        $db->prepare(
            "UPDATE institutions
             SET status = 'active', valid_from = ?, valid_until = ?,
                 approved_at = NOW(), approved_by = ?
             WHERE id = ?"
        )->execute([$validFrom, $validUntil, authId(), $id]);

        // Notify admin via email
        $subject = APP_NAME . ' – Your Institution Has Been Approved';
        $loginUrl = BASE_URL . '/app/auth/login.php';
        $body = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;">
    <h2 style="color:#0b1f3a;">SportsInfraX</h2>
    <p>Dear <strong>{$inst['admin_name']}</strong>,</p>
    <p>Congratulations! Your institution <strong>{$inst['institution_name']}</strong> has been approved by the SportsInfraX platform administrator.</p>
    <p><strong>Validity:</strong> {$validFrom} to {$validUntil}</p>
    <p>You can now manage staff and members. <a href="{$loginUrl}">Login here</a>.</p>
    <hr><p style="font-size:12px;color:#9ca3af;">&copy; SportsInfraX</p>
  </div>
</body></html>
HTML;
        sendMail($inst['admin_email'], $subject, $body);

        setFlash('success', "Institution '{$inst['institution_name']}' approved successfully!");
        header('Location: ' . BASE_URL . '/app/super-admin/institution-detail.php?id=' . $id);
        exit;
    }
}

// ── POST: Suspend ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'suspend') {
    verifyCsrf();
    $db->prepare("UPDATE institutions SET status = 'suspended' WHERE id = ?")->execute([$id]);
    setFlash('warning', "Institution suspended.");
    header('Location: ' . BASE_URL . '/app/super-admin/institution-detail.php?id=' . $id);
    exit;
}

// ── POST: Reactivate ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reactivate') {
    verifyCsrf();
    $db->prepare("UPDATE institutions SET status = 'active' WHERE id = ?")->execute([$id]);
    setFlash('success', "Institution reactivated.");
    header('Location: ' . BASE_URL . '/app/super-admin/institution-detail.php?id=' . $id);
    exit;
}

// Reload after possible POST
$stmt->execute([$id]);
$inst = $stmt->fetch();

$pageTitle   = h($inst['institution_name']);
$breadcrumbs = [
    'Dashboard'    => BASE_URL . '/app/super-admin/dashboard.php',
    'Institutions' => BASE_URL . '/app/super-admin/institutions.php',
    $inst['institution_name'] => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">

  <!-- Left: Institution Info -->
  <div class="col-lg-8">

    <!-- Profile Card -->
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-building me-2 text-primary"></i>Institution Profile</span>
        <?= institutionStatusBadge($inst['status']) ?>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-start gap-4 flex-wrap">
          <?php if ($inst['logo']): ?>
          <img src="<?= h(LOGO_URL . '/' . $inst['logo']) ?>" alt="Logo" class="logo-preview">
          <?php else: ?>
          <div class="logo-preview d-flex align-items-center justify-content-center text-muted">
            <i class="bi bi-building" style="font-size:2rem;"></i>
          </div>
          <?php endif; ?>
          <div class="flex-grow-1">
            <h5 class="fw-bold mb-1"><?= h($inst['institution_name']) ?></h5>
            <p class="text-muted small mb-2"><?= h(institutionTypeLabel($inst['institution_type'])) ?></p>
            <?php if ($inst['website']): ?>
            <a href="<?= h($inst['website']) ?>" target="_blank" rel="noopener" class="small text-primary">
              <i class="bi bi-globe me-1"></i><?= h($inst['website']) ?>
            </a>
            <?php endif; ?>
          </div>
        </div>

        <hr>

        <div class="row g-3 small">
          <div class="col-sm-6">
            <div class="text-muted fw-600">Address</div>
            <div><?= nl2br(h($inst['address'])) ?></div>
            <?php if ($inst['city']): ?><div><?= h($inst['city']) ?>, <?= h($inst['state'] ?? '') ?> – <?= h($inst['pincode'] ?? '') ?></div><?php endif; ?>
            <div><?= h($inst['country'] ?? 'India') ?></div>
          </div>
          <div class="col-sm-6">
            <div class="mb-2">
              <div class="text-muted fw-600">Contact Email</div>
              <div><?= h($inst['contact_email'] ?? '—') ?></div>
            </div>
            <div class="mb-2">
              <div class="text-muted fw-600">Contact Phone</div>
              <div><?= h($inst['contact_phone'] ?? '—') ?></div>
            </div>
            <div>
              <div class="text-muted fw-600">Registration Number</div>
              <div><?= h($inst['reg_number'] ?? '—') ?></div>
            </div>
          </div>
          <?php if ($inst['valid_from']): ?>
          <div class="col-12">
            <div class="alert alert-success py-2 mb-0 small">
              <i class="bi bi-calendar-check me-2"></i>
              <strong>Validity:</strong> <?= fmtDate($inst['valid_from']) ?> to <?= fmtDate($inst['valid_until']) ?>
              &nbsp;·&nbsp; Approved on <?= fmtDate($inst['approved_at'], 'd M Y h:i A') ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Approval Form -->
    <?php if ($inst['status'] === 'pending_approval'): ?>
    <div class="card border-warning">
      <div class="card-header bg-warning bg-opacity-10 text-warning-emphasis">
        <i class="bi bi-shield-check me-2"></i>Approve Institution
      </div>
      <div class="card-body">
        <?php if (!empty($approveError)): ?>
        <div class="alert alert-danger py-2 small"><?= h($approveError) ?></div>
        <?php endif; ?>
        <p class="text-muted small mb-3">
          Set the validity period and approve this institution to allow them to manage members and staff.
        </p>
        <form method="POST" class="row g-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve">
          <div class="col-sm-5">
            <label class="form-label small fw-600">Valid From <span class="required">*</span></label>
            <input type="date" class="form-control form-control-sm" name="valid_from"
                   value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-sm-5">
            <label class="form-label small fw-600">Valid Until <span class="required">*</span></label>
            <input type="date" class="form-control form-control-sm" name="valid_until"
                   value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
          </div>
          <div class="col-sm-2 d-flex align-items-end">
            <button type="submit" class="btn btn-success btn-sm w-100">Approve</button>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($inst['status'] === 'active'): ?>
    <div class="card">
      <div class="card-body">
        <form method="POST" class="d-inline"
              onsubmit="return confirm('Suspend this institution? Their staff will lose access.')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="suspend">
          <button class="btn btn-sm btn-outline-danger">
            <i class="bi bi-pause-circle me-1"></i>Suspend Institution
          </button>
        </form>
      </div>
    </div>

    <?php elseif ($inst['status'] === 'suspended'): ?>
    <div class="card border-danger">
      <div class="card-body">
        <p class="text-danger small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>This institution is currently suspended.</p>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reactivate">
          <button class="btn btn-sm btn-success">
            <i class="bi bi-play-circle me-1"></i>Reactivate Institution
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Right: Admin + Stats -->
  <div class="col-lg-4">

    <!-- Admin Info -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-person-badge me-2 text-primary"></i>Institution Admin</div>
      <div class="card-body small">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="avatar-circle"><?= h(mb_strtoupper(mb_substr($inst['admin_name'] ?? 'A', 0, 1))) ?></div>
          <div>
            <div class="fw-600"><?= h($inst['admin_name'] ?? '—') ?></div>
            <div class="text-muted"><?= h($inst['admin_email'] ?? '—') ?></div>
          </div>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Mobile</span><span><?= h($inst['admin_mobile'] ?? '—') ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Last Login</span>
          <span><?= $inst['admin_last_login'] ? fmtDate($inst['admin_last_login'], 'd M Y h:i A') : 'Never' ?></span>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Account</span>
          <?= $inst['admin_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>' ?>
        </div>
      </div>
    </div>

    <!-- Counts -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Overview</div>
      <div class="card-body p-0">
        <div class="list-group list-group-flush small">
          <div class="list-group-item d-flex justify-content-between">
            <span><i class="bi bi-person-badge me-2 text-muted"></i>Staff</span>
            <strong><?= $staffCount ?></strong>
          </div>
          <div class="list-group-item d-flex justify-content-between">
            <span><i class="bi bi-people me-2 text-muted"></i>Members</span>
            <strong><?= $memberCount ?></strong>
          </div>
          <div class="list-group-item d-flex justify-content-between">
            <span><i class="bi bi-calendar-date me-2 text-muted"></i>Registered</span>
            <span class="text-muted"><?= fmtDate($inst['created_at']) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Registration document -->
    <?php if ($inst['reg_document']): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Registration Document</div>
      <div class="card-body">
        <a href="<?= h(DOC_URL . '/' . $inst['reg_document']) ?>" target="_blank" class="btn btn-sm btn-outline-primary w-100">
          <i class="bi bi-download me-2"></i>Download Document
        </a>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
