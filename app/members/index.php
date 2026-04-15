<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin','staff']);

$db     = getDB();
$instId = authInstId();

// Verify institution active
$instStmt = $db->prepare("SELECT status, institution_name FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || $inst['status'] !== 'active') {
    setFlash('error', 'Member management is only available for active institutions.');
    header('Location: ' . dashboardUrl());
    exit;
}

// Filters
$search = trim($_GET['q']       ?? '');
$sport  = trim($_GET['sport']   ?? '');
$filter = trim($_GET['filter']  ?? ''); // 'expiring', 'expired'
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = 'm.institution_id = ? AND m.is_active = 1';
$params = [$instId];

if ($search) {
    $where   .= ' AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.mobile LIKE ? OR m.member_code LIKE ? OR m.email LIKE ?)';
    $s        = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($sport) {
    $where   .= ' AND m.sport_category = ?';
    $params[] = $sport;
}
if ($filter === 'expiring') {
    $where .= ' AND ms_last.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
} elseif ($filter === 'expired') {
    $where .= ' AND ms_last.end_date < CURDATE()';
}

// Sub-query for latest membership per member
$msJoin = "LEFT JOIN memberships ms_last ON ms_last.id = (
    SELECT id FROM memberships WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1
)";

$cntStmt = $db->prepare("SELECT COUNT(*) FROM members m $msJoin WHERE $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$listStmt = $db->prepare(
    "SELECT m.*, ms_last.end_date AS ms_end, ms_last.plan_name AS ms_plan, ms_last.payment_status
     FROM members m $msJoin
     WHERE $where
     ORDER BY m.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$members = $listStmt->fetchAll();

// Sports categories for filter
$sportStmt = $db->prepare("SELECT DISTINCT sport_category FROM members WHERE institution_id = ? AND sport_category IS NOT NULL ORDER BY sport_category");
$sportStmt->execute([$instId]);
$sports = $sportStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle   = 'Members';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Members' => ''];
$pageAction  = '<a href="' . h(BASE_URL . '/app/members/add.php') . '" class="btn btn-primary btn-sm">
                  <i class="bi bi-plus-circle me-1"></i>Add Member
                </a>';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Filter Bar -->
<div class="filter-bar">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4">
      <label class="form-label small mb-1">Search</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" class="form-control" name="q" id="tableSearch"
               value="<?= h($search) ?>" placeholder="Name, mobile, code…">
      </div>
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Sport</label>
      <select class="form-select form-select-sm" name="sport">
        <option value="">All Sports</option>
        <?php foreach ($sports as $sp): ?>
        <option value="<?= h($sp) ?>" <?= $sport === $sp ? 'selected' : '' ?>><?= h($sp) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Filter</label>
      <select class="form-select form-select-sm" name="filter">
        <option value="">All Members</option>
        <option value="expiring" <?= $filter === 'expiring' ? 'selected' : '' ?>>Expiring in 30 Days</option>
        <option value="expired"  <?= $filter === 'expired'  ? 'selected' : '' ?>>Expired Memberships</option>
      </select>
    </div>
    <div class="col-sm-auto">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="<?= h(BASE_URL . '/app/members/index.php') ?>" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
    </div>
  </form>
</div>

<!-- Table -->
<div class="card table-card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-people me-2 text-primary"></i>Members
      <span class="badge bg-secondary ms-1"><?= $total ?></span>
    </span>
    <a href="<?= h(BASE_URL . '/app/members/add.php') ?>" class="btn btn-sm btn-primary d-md-none"><i class="bi bi-plus"></i></a>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Member</th>
          <th>Member Code</th>
          <th>Sport</th>
          <th>Mobile</th>
          <th>Membership</th>
          <th>Expires</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($members): ?>
        <?php foreach ($members as $i => $m): ?>
        <tr data-table-row>
          <td class="text-muted small"><?= $offset + $i + 1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if ($m['passport_photo']): ?>
              <img src="<?= h(PHOTO_URL . '/' . $m['passport_photo']) ?>" alt=""
                   style="width:32px;height:38px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;">
              <?php else: ?>
              <div class="avatar-circle" style="width:32px;height:32px;font-size:.75rem;border-radius:6px;">
                <?= mb_strtoupper(mb_substr($m['first_name'], 0, 1)) ?>
              </div>
              <?php endif; ?>
              <div>
                <a href="<?= h(BASE_URL . '/app/members/view.php?id=' . $m['id']) ?>" class="fw-600 small text-decoration-none text-dark">
                  <?= h($m['first_name'] . ' ' . $m['last_name']) ?>
                </a>
                <?php if ($m['email']): ?><div class="text-muted" style="font-size:.72rem;"><?= h($m['email']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="small font-monospace text-muted"><?= h($m['member_code']) ?></td>
          <td class="small"><?= h($m['sport_category'] ?? '—') ?></td>
          <td class="small"><?= h($m['mobile']) ?></td>
          <td>
            <?php if ($m['ms_plan']): ?>
            <span class="small"><?= h($m['ms_plan']) ?></span>
            <?php if ($m['payment_status']): ?><?= paymentStatusBadge($m['payment_status']) ?><?php endif; ?>
            <?php else: ?>
            <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($m['ms_end']): ?>
              <?= membershipStatusBadge($m['ms_end']) ?>
              <div class="text-muted" style="font-size:.72rem;"><?= fmtDate($m['ms_end']) ?></div>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <a href="<?= h(BASE_URL . '/app/members/view.php?id=' . $m['id']) ?>"
                 class="btn btn-sm btn-outline-primary btn-icon" title="View" data-bs-toggle="tooltip">
                <i class="bi bi-eye"></i>
              </a>
              <a href="<?= h(BASE_URL . '/app/members/edit.php?id=' . $m['id']) ?>"
                 class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" data-bs-toggle="tooltip">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="<?= h(BASE_URL . '/app/members/membership-add.php?member_id=' . $m['id']) ?>"
                 class="btn btn-sm btn-outline-success btn-icon" title="Add/Renew Membership" data-bs-toggle="tooltip">
                <i class="bi bi-card-checklist"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="8">
          <div class="empty-state py-4">
            <i class="bi bi-people"></i>
            <h6>No members found</h6>
            <p class="small">Try adjusting your filters or add a new member.</p>
            <a href="<?= h(BASE_URL . '/app/members/add.php') ?>" class="btn btn-primary btn-sm">Add Member</a>
          </div>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="text-muted small">Showing <?= min($offset+1,$total) ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <?= paginate($total, $page, $perPage, BASE_URL . '/app/members/index.php') ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
