<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('staff');

$db     = getDB();
$instId = authInstId();

// Get institution
$instStmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();

if (!$inst || $inst['status'] !== 'active') {
    setFlash('warning', 'Your institution is not yet active. Please contact your institution admin.');
    // Show limited view
}

// Stats
$totalMembers = 0; $activeMs = 0; $expSoon = 0; $newToday = 0;
if ($instId) {
    $mStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE institution_id = ? AND is_active = 1");
    $mStmt->execute([$instId]);
    $totalMembers = (int)$mStmt->fetchColumn();

    $aStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms WHERE ms.institution_id = ? AND ms.end_date >= CURDATE()");
    $aStmt->execute([$instId]);
    $activeMs = (int)$aStmt->fetchColumn();

    $eStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms WHERE ms.institution_id = ? AND ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $eStmt->execute([$instId]);
    $expSoon = (int)$eStmt->fetchColumn();

    $nStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE institution_id = ? AND DATE(created_at) = CURDATE()");
    $nStmt->execute([$instId]);
    $newToday = (int)$nStmt->fetchColumn();
}

// Expiring soon members
$expMembers = [];
if ($instId) {
    $emStmt = $db->prepare(
        "SELECT m.first_name, m.last_name, m.mobile, m.member_code, m.id AS member_id,
                ms.end_date, ms.plan_name
         FROM memberships ms
         JOIN members m ON m.id = ms.member_id
         WHERE ms.institution_id = ?
           AND ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND m.is_active = 1
         ORDER BY ms.end_date ASC
         LIMIT 8"
    );
    $emStmt->execute([$instId]);
    $expMembers = $emStmt->fetchAll();
}

// Recent members
$recentMembers = [];
if ($instId) {
    $rmStmt = $db->prepare(
        "SELECT m.*, ms.end_date, ms.payment_status
         FROM members m
         LEFT JOIN memberships ms ON ms.id = (
             SELECT id FROM memberships WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1
         )
         WHERE m.institution_id = ? AND m.is_active = 1
         ORDER BY m.created_at DESC LIMIT 6"
    );
    $rmStmt->execute([$instId]);
    $recentMembers = $rmStmt->fetchAll();
}

$pageTitle = 'Staff Dashboard';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Welcome Bar -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h5 class="fw-bold mb-0">Welcome, <?= h(authName()) ?>!</h5>
    <p class="text-muted small mb-0"><?= h($inst['institution_name'] ?? 'Your Institution') ?></p>
  </div>
  <?php if ($inst && $inst['status'] === 'active'): ?>
  <a href="<?= h(BASE_URL . '/app/members/add.php') ?>" class="btn btn-primary">
    <i class="bi bi-plus-circle me-2"></i>Add New Member
  </a>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-4 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card primary">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)"><i class="bi bi-people-fill"></i></div>
      <div class="stat-value"><?= $totalMembers ?></div>
      <div class="stat-label mt-1">Total Members</div>
      <i class="bi bi-people-fill stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card success">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)"><i class="bi bi-card-checklist"></i></div>
      <div class="stat-value"><?= $activeMs ?></div>
      <div class="stat-label mt-1">Active Memberships</div>
      <i class="bi bi-card-checklist stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card warning">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)"><i class="bi bi-clock-history"></i></div>
      <div class="stat-value"><?= $expSoon ?></div>
      <div class="stat-label mt-1">Expiring (30 Days)</div>
      <i class="bi bi-clock-history stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card purple">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)"><i class="bi bi-person-plus-fill"></i></div>
      <div class="stat-value"><?= $newToday ?></div>
      <div class="stat-label mt-1">New Today</div>
      <i class="bi bi-person-plus-fill stat-bg"></i>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Expiring Soon -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-x me-2 text-warning"></i>Expiring Memberships</span>
        <a href="<?= h(BASE_URL . '/app/members/index.php?filter=expiring') ?>"
           class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($expMembers): ?>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Member</th><th>Plan</th><th>Expires</th></tr></thead>
            <tbody>
              <?php foreach ($expMembers as $em): ?>
              <tr>
                <td>
                  <a href="<?= h(BASE_URL . '/app/members/view.php?id=' . $em['member_id']) ?>" class="text-decoration-none">
                    <div class="fw-600 small"><?= h($em['first_name'] . ' ' . $em['last_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><?= h($em['mobile']) ?></div>
                  </a>
                </td>
                <td class="small"><?= h($em['plan_name']) ?></td>
                <td><?= membershipStatusBadge($em['end_date']) ?><div class="text-muted" style="font-size:.72rem;"><?= fmtDate($em['end_date']) ?></div></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-check2-all"></i>
          <h6>No memberships expiring soon</h6>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Members -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill me-2 text-primary"></i>Recent Members</span>
        <a href="<?= h(BASE_URL . '/app/members/index.php') ?>" class="btn btn-sm btn-outline-primary">All Members</a>
      </div>
      <div class="card-body p-0">
        <?php if ($recentMembers): ?>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Member</th><th>Code</th><th>Membership</th></tr></thead>
            <tbody>
              <?php foreach ($recentMembers as $m): ?>
              <tr>
                <td>
                  <a href="<?= h(BASE_URL . '/app/members/view.php?id=' . $m['id']) ?>" class="text-decoration-none">
                    <div class="fw-600 small"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><?= h($m['sport_category'] ?? '—') ?></div>
                  </a>
                </td>
                <td class="small text-muted"><?= h($m['member_code']) ?></td>
                <td>
                  <?php if ($m['end_date']): ?>
                    <?= membershipStatusBadge($m['end_date']) ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">—</span>
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
          <h6>No members yet</h6>
          <a href="<?= h(BASE_URL . '/app/members/add.php') ?>" class="btn btn-primary btn-sm mt-2">Add Member</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
