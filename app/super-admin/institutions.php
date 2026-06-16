<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('super_admin');

$db = getDB();

// Filters
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q']      ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];

if ($statusFilter) {
    $where   .= ' AND i.status = ?';
    $params[] = $statusFilter;
}
if ($search) {
    $where   .= ' AND (i.institution_name LIKE ? OR u.email LIKE ? OR i.city LIKE ?)';
    $s        = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s]);
}

$total = (int)$db->prepare("SELECT COUNT(*) FROM institutions i LEFT JOIN users u ON u.id = i.admin_id WHERE $where")->execute($params) ? $db->prepare("SELECT COUNT(*) FROM institutions i LEFT JOIN users u ON u.id = i.admin_id WHERE $where")->execute($params) : 0;

// Re-run count query properly
$countStmt = $db->prepare("SELECT COUNT(*) FROM institutions i LEFT JOIN users u ON u.id = i.admin_id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT i.*, u.email AS admin_email, u.full_name AS admin_name, u.mobile AS admin_mobile
     FROM institutions i
     LEFT JOIN users u ON u.id = i.admin_id
     WHERE $where
     ORDER BY i.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$institutions = $stmt->fetchAll();

$pageTitle   = 'Institutions';
$breadcrumbs = ['Dashboard' => BASE_URL . '/app/super-admin/dashboard', 'Institutions' => ''];
$pageAction  = '<a href="' . h(BASE_URL . '/app/register') . '" class="btn btn-sm btn-outline-primary" target="_blank">
                  <i class="bi bi-plus-circle me-1"></i>Register New
                </a>';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Filter Bar -->
<div class="filter-bar">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5">
      <label class="form-label small mb-1">Search</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" class="form-control" name="q" value="<?= h($search) ?>"
               placeholder="Name, email or city…">
      </div>
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Status</label>
      <select class="form-select form-select-sm" name="status">
        <option value="">All Statuses</option>
        <option value="pending_profile"  <?= $statusFilter === 'pending_profile'  ? 'selected' : '' ?>>Pending Profile</option>
        <option value="pending_approval" <?= $statusFilter === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
        <option value="active"           <?= $statusFilter === 'active'           ? 'selected' : '' ?>>Active</option>
        <option value="suspended"        <?= $statusFilter === 'suspended'        ? 'selected' : '' ?>>Suspended</option>
      </select>
    </div>
    <div class="col-sm-auto">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="<?= h(BASE_URL . '/app/super-admin/institutions') ?>" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
    </div>
  </form>
</div>

<!-- Table Card -->
<div class="card table-card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-building me-2 text-primary"></i>All Institutions
      <span class="badge bg-secondary ms-1"><?= $total ?></span>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Institution</th>
          <th>Type</th>
          <th>Admin</th>
          <th>Registered</th>
          <th>Status</th>
          <th>Validity</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($institutions): ?>
        <?php foreach ($institutions as $i => $inst): ?>
        <tr data-table-row>
          <td class="text-muted small"><?= $offset + $i + 1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if ($inst['logo']): ?>
              <img src="<?= h(LOGO_URL . '/' . $inst['logo']) ?>" alt="Logo"
                   style="width:32px;height:32px;object-fit:contain;border-radius:4px;background:#f3f4f6;padding:2px;">
              <?php else: ?>
              <span class="avatar-circle" style="width:32px;height:32px;font-size:.75rem;border-radius:6px;background:linear-gradient(135deg,#0b5ed7,#6f42c1);">
                <?= mb_strtoupper(mb_substr($inst['institution_name'], 0, 1)) ?>
              </span>
              <?php endif; ?>
              <div>
                <div class="fw-600 small"><?= h($inst['institution_name']) ?></div>
                <?php if ($inst['city']): ?>
                <div class="text-muted" style="font-size:.72rem;"><i class="bi bi-geo-alt me-1"></i><?= h($inst['city']) ?><?= $inst['state'] ? ', ' . h($inst['state']) : '' ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="small"><?= h(institutionTypeLabel($inst['institution_type'])) ?></td>
          <td>
            <div class="small"><?= h($inst['admin_name'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:.72rem;"><?= h($inst['admin_email'] ?? '') ?></div>
          </td>
          <td class="small text-muted"><?= fmtDate($inst['created_at'], 'd M Y') ?></td>
          <td><?= institutionStatusBadge($inst['status']) ?></td>
          <td class="small">
            <?php if ($inst['valid_until']): ?>
              <?= fmtDate($inst['valid_from'], 'd M Y') ?> –
              <?= fmtDate($inst['valid_until'], 'd M Y') ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="<?= h(BASE_URL . '/app/super-admin/institution-detail?id=' . $inst['id']) ?>"
               class="btn btn-sm btn-outline-primary btn-icon" title="View Details"
               data-bs-toggle="tooltip">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="8">
          <div class="empty-state py-4">
            <i class="bi bi-building"></i>
            <h6>No institutions found</h6>
            <p class="small">Try adjusting your filters.</p>
          </div>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="text-muted small">Showing <?= min($offset + 1, $total) ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?></span>
    <?= paginate($total, $page, $perPage, BASE_URL . '/app/super-admin/institutions') ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
