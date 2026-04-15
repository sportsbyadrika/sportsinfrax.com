<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$stmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$stmt->execute([$instId]);
$inst = $stmt->fetch();

if (!$inst) { setFlash('error', 'Institution not found.'); logoutUser(); header('Location: ' . BASE_URL . '/app/auth/login.php'); exit; }

// Stats
$staffCount  = (int)$db->prepare("SELECT COUNT(*) FROM staff WHERE institution_id = ? AND is_active = 1")->execute([$instId]) ? 0 : 0;
$scStmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE institution_id = ? AND is_active = 1");
$scStmt->execute([$instId]);
$staffCount = (int)$scStmt->fetchColumn();

$mcStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE institution_id = ? AND is_active = 1");
$mcStmt->execute([$instId]);
$memberCount = (int)$mcStmt->fetchColumn();

$activeMship = 0;
$expiringSoon = 0;
if ($inst['status'] === 'active') {
    $msStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms JOIN members m ON m.id = ms.member_id
                             WHERE ms.institution_id = ? AND ms.end_date >= CURDATE()");
    $msStmt->execute([$instId]);
    $activeMship = (int)$msStmt->fetchColumn();

    $esStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms JOIN members m ON m.id = ms.member_id
                             WHERE ms.institution_id = ? AND ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $esStmt->execute([$instId]);
    $expiringSoon = (int)$esStmt->fetchColumn();
}

// Recent members
$recentMembers = [];
if ($inst['status'] === 'active') {
    $rmStmt = $db->prepare("SELECT m.*, ms.end_date, ms.payment_status
                             FROM members m
                             LEFT JOIN memberships ms ON ms.id = (SELECT id FROM memberships WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1)
                             WHERE m.institution_id = ? AND m.is_active = 1
                             ORDER BY m.created_at DESC LIMIT 5");
    $rmStmt->execute([$instId]);
    $recentMembers = $rmStmt->fetchAll();
}

$pageTitle = 'Dashboard';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Pending Profile Warning -->
<?php if ($inst['status'] === 'pending_profile'): ?>
<div class="approval-banner mb-4">
  <i class="bi bi-building-exclamation"></i>
  <div>
    <h6 class="fw-bold mb-1">Complete Your Institution Profile</h6>
    <p class="mb-2 small opacity-90">Please complete your institution profile (logo, type, registration details) to submit for Super Admin approval.</p>
    <a href="<?= h(BASE_URL . '/app/institution-admin/profile.php') ?>" class="btn btn-light btn-sm">
      Complete Profile →
    </a>
  </div>
</div>

<?php elseif ($inst['status'] === 'pending_approval'): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-4">
  <i class="bi bi-hourglass-split fs-4 flex-shrink-0"></i>
  <div>
    <strong>Awaiting Super Admin Approval</strong><br>
    <span class="small">Your profile has been submitted. The platform administrator will review and approve your institution shortly.</span>
  </div>
</div>

<?php elseif ($inst['status'] === 'suspended'): ?>
<div class="alert alert-danger d-flex align-items-center gap-3 mb-4">
  <i class="bi bi-slash-circle fs-4 flex-shrink-0"></i>
  <div>
    <strong>Institution Suspended</strong><br>
    <span class="small">Your institution has been suspended. Please contact the platform administrator.</span>
  </div>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-4 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card primary">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)"><i class="bi bi-people-fill"></i></div>
      </div>
      <div class="stat-value"><?= $memberCount ?></div>
      <div class="stat-label mt-1">Total Members</div>
      <i class="bi bi-people-fill stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card success">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)"><i class="bi bi-card-checklist"></i></div>
      </div>
      <div class="stat-value"><?= $activeMship ?></div>
      <div class="stat-label mt-1">Active Memberships</div>
      <i class="bi bi-card-checklist stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card warning">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)"><i class="bi bi-calendar-x"></i></div>
      </div>
      <div class="stat-value"><?= $expiringSoon ?></div>
      <div class="stat-label mt-1">Expiring in 30 Days</div>
      <i class="bi bi-calendar-x stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card purple">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)"><i class="bi bi-person-badge-fill"></i></div>
      </div>
      <div class="stat-value"><?= $staffCount ?></div>
      <div class="stat-label mt-1">Staff Members</div>
      <i class="bi bi-person-badge-fill stat-bg"></i>
    </div>
  </div>
</div>

<div class="row g-4">

  <!-- Institution Info Card -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building me-2 text-primary"></i>Institution</span>
        <a href="<?= h(BASE_URL . '/app/institution-admin/profile.php') ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-pencil me-1"></i>Edit
        </a>
      </div>
      <div class="card-body small">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if ($inst['logo']): ?>
          <img src="<?= h(LOGO_URL . '/' . $inst['logo']) ?>" alt="Logo" style="width:56px;height:56px;object-fit:contain;border-radius:8px;background:#f3f4f6;padding:4px;">
          <?php else: ?>
          <div style="width:56px;height:56px;border-radius:8px;background:linear-gradient(135deg,#0b5ed7,#6f42c1);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;">
            <?= mb_strtoupper(mb_substr($inst['institution_name'], 0, 1)) ?>
          </div>
          <?php endif; ?>
          <div>
            <div class="fw-bold"><?= h($inst['institution_name']) ?></div>
            <div class="text-muted"><?= h(institutionTypeLabel($inst['institution_type'])) ?></div>
          </div>
        </div>
        <?php if ($inst['city']): ?>
        <div class="mb-1"><i class="bi bi-geo-alt me-2 text-muted"></i><?= h($inst['city']) ?>, <?= h($inst['state'] ?? '') ?></div>
        <?php endif; ?>
        <?php if ($inst['contact_phone']): ?>
        <div class="mb-1"><i class="bi bi-telephone me-2 text-muted"></i><?= h($inst['contact_phone']) ?></div>
        <?php endif; ?>
        <?php if ($inst['valid_until']): ?>
        <div class="mt-2 pt-2 border-top">
          <small class="text-muted">Valid until:</small>
          <span class="ms-1 fw-600"><?= fmtDate($inst['valid_until']) ?></span>
          <?= membershipStatusBadge($inst['valid_until']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Members -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2 text-primary"></i>Recent Members</span>
        <?php if ($inst['status'] === 'active'): ?>
        <div class="d-flex gap-2">
          <a href="<?= h(BASE_URL . '/app/members/add.php') ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-plus me-1"></i>Add Member
          </a>
          <a href="<?= h(BASE_URL . '/app/members/index.php') ?>" class="btn btn-sm btn-outline-primary">All</a>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($recentMembers): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Member</th>
                <th>Code</th>
                <th>Sport</th>
                <th>Membership</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentMembers as $m): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php if ($m['passport_photo']): ?>
                    <img src="<?= h(PHOTO_URL . '/' . $m['passport_photo']) ?>" alt=""
                         style="width:30px;height:36px;object-fit:cover;border-radius:4px;">
                    <?php else: ?>
                    <div class="avatar-circle" style="width:30px;height:30px;font-size:.72rem;border-radius:6px;">
                      <?= mb_strtoupper(mb_substr($m['first_name'], 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                      <div class="fw-600 small"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></div>
                      <div class="text-muted" style="font-size:.72rem;"><?= h($m['mobile']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="small text-muted"><?= h($m['member_code']) ?></td>
                <td class="small"><?= h($m['sport_category'] ?? '—') ?></td>
                <td>
                  <?php if ($m['end_date']): ?>
                    <?= membershipStatusBadge($m['end_date']) ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">No membership</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-people"></i>
          <h6><?= $inst['status'] === 'active' ? 'No members yet' : 'Awaiting activation' ?></h6>
          <p class="small"><?= $inst['status'] === 'active' ? 'Start adding members to your institution.' : 'Complete your profile to get approved.' ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
