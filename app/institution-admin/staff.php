<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// Verify institution is at least pending_approval
$stmt = $db->prepare("SELECT status, institution_name FROM institutions WHERE id = ?");
$stmt->execute([$instId]);
$inst = $stmt->fetch();
if (!$inst || !in_array($inst['status'], ['pending_approval','active'])) {
    setFlash('error', 'Complete your institution profile before managing staff.');
    header('Location: ' . BASE_URL . '/app/institution-admin/profile');
    exit;
}

$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = 's.institution_id = ?';
$params = [$instId];
if ($search) {
    $where   .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR s.staff_type LIKE ?)';
    $s        = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s]);
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM staff s JOIN users u ON u.id = s.user_id WHERE $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$listStmt = $db->prepare(
    "SELECT s.*, u.full_name, u.email, u.mobile, u.is_active AS user_active, u.last_login
     FROM staff s JOIN users u ON u.id = s.user_id
     WHERE $where
     ORDER BY s.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$staffList = $listStmt->fetchAll();

$pageTitle   = 'Staff Management';
$breadcrumbs = ['Dashboard' => BASE_URL . '/app/institution-admin/dashboard', 'Staff' => ''];
$pageAction  = '<a href="' . h(BASE_URL . '/app/institution-admin/staff-add') . '" class="btn btn-primary btn-sm">
                  <i class="bi bi-plus-circle me-1"></i>Add Staff
                </a>';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Filter -->
<div class="filter-bar">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5">
      <label class="form-label small mb-1">Search</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" class="form-control" name="q" value="<?= h($search) ?>" placeholder="Name, email or role…">
      </div>
    </div>
    <div class="col-sm-auto">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
      <a href="?" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
    </div>
  </form>
</div>

<div class="card table-card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="bi bi-person-badge me-2 text-primary"></i>Staff Members
      <span class="badge bg-secondary ms-1"><?= $total ?></span>
    </span>
    <a href="<?= h(BASE_URL . '/app/institution-admin/staff-add') ?>" class="btn btn-sm btn-primary d-md-none">
      <i class="bi bi-plus"></i>
    </a>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Staff Member</th>
          <th>Role / Type</th>
          <th>Mobile</th>
          <th>Joined</th>
          <th>Last Login</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($staffList): ?>
        <?php foreach ($staffList as $i => $s): ?>
        <tr data-table-row>
          <td class="text-muted small"><?= $offset + $i + 1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($s['passport_photo'])): ?>
              <img src="<?= h(PHOTO_URL . '/' . $s['passport_photo']) ?>"
                   alt="<?= h($s['full_name']) ?>"
                   style="width:32px;height:32px;border-radius:6px;object-fit:cover;flex-shrink:0;">
              <?php else: ?>
              <div class="avatar-circle" style="width:32px;height:32px;font-size:.75rem;border-radius:6px;">
                <?= mb_strtoupper(mb_substr($s['full_name'], 0, 1)) ?>
              </div>
              <?php endif; ?>
              <div>
                <div class="fw-600 small"><?= h($s['full_name']) ?></div>
                <div class="text-muted" style="font-size:.72rem;"><?= h($s['email']) ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="badge bg-primary bg-opacity-10 text-primary"><?= h(ucfirst($s['staff_type'])) ?></span>
            <?php if ($s['department']): ?><div class="text-muted" style="font-size:.72rem;"><?= h($s['department']) ?></div><?php endif; ?>
          </td>
          <td class="small"><?= h($s['mobile'] ?? '—') ?></td>
          <td class="small text-muted"><?= fmtDate($s['joining_date'] ?? $s['created_at'], 'd M Y') ?></td>
          <td class="small text-muted"><?= $s['last_login'] ? fmtDate($s['last_login'], 'd M Y') : 'Never' ?></td>
          <td>
            <?= $s['user_active']
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-danger">Inactive</span>' ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <a href="<?= h(BASE_URL . '/app/institution-admin/staff-permissions?staff_id=' . $s['id']) ?>"
                 class="btn btn-sm btn-outline-secondary btn-icon" title="Permissions" data-bs-toggle="tooltip">
                <i class="bi bi-shield-lock"></i>
              </a>
              <a href="<?= h(BASE_URL . '/app/institution-admin/staff-add?id=' . $s['id']) ?>"
                 class="btn btn-sm btn-outline-primary btn-icon" title="Edit" data-bs-toggle="tooltip">
                <i class="bi bi-pencil"></i>
              </a>
              <form method="POST" action="<?= h(BASE_URL . '/app/institution-admin/staff-toggle') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                <input type="hidden" name="active" value="<?= $s['user_active'] ? '0' : '1' ?>">
                <button type="submit"
                        class="btn btn-sm <?= $s['user_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                        title="<?= $s['user_active'] ? 'Deactivate' : 'Activate' ?>"
                        data-bs-toggle="tooltip"
                        data-confirm="<?= $s['user_active'] ? 'Deactivate this staff account?' : 'Activate this staff account?' ?>">
                  <i class="bi <?= $s['user_active'] ? 'bi-person-dash' : 'bi-person-check' ?>"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="8">
          <div class="empty-state py-4">
            <i class="bi bi-person-badge"></i>
            <h6>No staff members yet</h6>
            <p class="small">Add your first staff member to get started.</p>
            <a href="<?= h(BASE_URL . '/app/institution-admin/staff-add') ?>" class="btn btn-primary btn-sm">Add Staff</a>
          </div>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="card-footer d-flex justify-content-between">
    <span class="text-muted small">Showing <?= min($offset+1,$total) ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <?= paginate($total, $page, $perPage, BASE_URL . '/app/institution-admin/staff') ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
