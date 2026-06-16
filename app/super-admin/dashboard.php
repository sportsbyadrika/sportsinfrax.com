<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('super_admin');

$db = getDB();

// Stats
$totalInst   = (int)$db->query("SELECT COUNT(*) FROM institutions")->fetchColumn();
$pendingInst = (int)$db->query("SELECT COUNT(*) FROM institutions WHERE status = 'pending_approval'")->fetchColumn();
$activeInst  = (int)$db->query("SELECT COUNT(*) FROM institutions WHERE status = 'active'")->fetchColumn();
$totalMem    = (int)$db->query("SELECT COUNT(*) FROM members")->fetchColumn();
$totalStaff  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'staff' AND is_active = 1")->fetchColumn();
$totalReg    = (int)$db->query("SELECT COUNT(*) FROM institution_registrations WHERE status = 'pending'")->fetchColumn();

// Recent registrations (last 5)
$recentRegs  = $db->query(
    "SELECT ir.*, i.status AS inst_status
     FROM institution_registrations ir
     LEFT JOIN institutions i ON i.registration_id = ir.id
     ORDER BY ir.created_at DESC LIMIT 8"
)->fetchAll();

// Pending approval institutions
$pendingList = $db->query(
    "SELECT i.*, u.email AS admin_email
     FROM institutions i
     LEFT JOIN users u ON u.id = i.admin_id
     WHERE i.status = 'pending_approval'
     ORDER BY i.created_at ASC LIMIT 5"
)->fetchAll();

$pageTitle = 'Super Admin Dashboard';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Stats Row -->
<div class="row g-4 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card primary">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2);">
          <i class="bi bi-building-fill"></i>
        </div>
      </div>
      <div class="stat-value"><?= $totalInst ?></div>
      <div class="stat-label mt-1">Total Institutions</div>
      <i class="bi bi-building-fill stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card warning">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2);">
          <i class="bi bi-hourglass-split"></i>
        </div>
      </div>
      <div class="stat-value"><?= $pendingInst ?></div>
      <div class="stat-label mt-1">Pending Approval</div>
      <i class="bi bi-hourglass-split stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card success">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2);">
          <i class="bi bi-check-circle-fill"></i>
        </div>
      </div>
      <div class="stat-value"><?= $activeInst ?></div>
      <div class="stat-label mt-1">Active Institutions</div>
      <i class="bi bi-check-circle-fill stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card purple">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2);">
          <i class="bi bi-people-fill"></i>
        </div>
      </div>
      <div class="stat-value"><?= $totalMem ?></div>
      <div class="stat-label mt-1">Total Members</div>
      <i class="bi bi-people-fill stat-bg"></i>
    </div>
  </div>
</div>

<div class="row g-4">

  <!-- Pending Approvals -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-building-check me-2 text-warning"></i>Pending Approvals</span>
        <a href="<?= h(BASE_URL . '/app/super-admin/institutions?status=pending_approval') ?>"
           class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($pendingList): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Institution</th>
                <th>Admin Email</th>
                <th>Registered</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingList as $inst): ?>
              <tr>
                <td>
                  <div class="fw-600 small"><?= h($inst['institution_name']) ?></div>
                  <div class="text-muted" style="font-size:.75rem;"><?= h(institutionTypeLabel($inst['institution_type'])) ?></div>
                </td>
                <td class="small text-muted"><?= h($inst['admin_email'] ?? '—') ?></td>
                <td class="small text-muted"><?= fmtDate($inst['created_at'], 'd M Y') ?></td>
                <td>
                  <a href="<?= h(BASE_URL . '/app/super-admin/institution-detail?id=' . $inst['id']) ?>"
                     class="btn btn-sm btn-primary">Review</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-check2-all"></i>
          <h6>No pending approvals</h6>
          <p class="small">All institution profiles are up to date.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Registrations -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clipboard-plus me-2 text-primary"></i>Recent Registrations</span>
        <a href="<?= h(BASE_URL . '/app/super-admin/institutions') ?>"
           class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($recentRegs): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($recentRegs as $r): ?>
          <li class="list-group-item px-4 py-3">
            <div class="d-flex align-items-start justify-content-between">
              <div>
                <div class="fw-600 small"><?= h($r['institution_name']) ?></div>
                <div class="text-muted" style="font-size:.75rem;"><?= h($r['email']) ?></div>
                <div class="text-muted" style="font-size:.72rem;"><?= fmtDate($r['created_at'], 'd M Y, h:i A') ?></div>
              </div>
              <?php
                $badge = match($r['status']) {
                    'pending'   => 'bg-warning text-dark',
                    'converted' => 'bg-success',
                    'rejected'  => 'bg-danger',
                    default     => 'bg-secondary',
                };
              ?>
              <span class="badge <?= $badge ?>"><?= h(ucfirst($r['status'])) ?></span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-inbox"></i>
          <h6>No registrations yet</h6>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /.row -->

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
