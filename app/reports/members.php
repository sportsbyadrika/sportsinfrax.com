<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);
require_once APP_ROOT . '/includes/report.php';

$db     = getDB();
$instId = authInstId();

// Institution info for report headers
$instStmt = $db->prepare("SELECT institution_name, logo FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();

// Output mode: 'print', 'xlsx', or '' (on-screen)
$output = $_GET['output'] ?? '';

// ── Filters ────────────────────────────────────────────────
$fStatus   = $_GET['status']    ?? 'active';
$fSport    = trim($_GET['sport']    ?? '');
$fGender   = $_GET['gender']    ?? '';
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo   = $_GET['date_to']   ?? '';

// Sport categories for filter dropdown
$sportsStmt = $db->prepare(
    "SELECT DISTINCT sport_category FROM members
     WHERE institution_id = ? AND sport_category IS NOT NULL
     ORDER BY sport_category"
);
$sportsStmt->execute([$instId]);
$sports = $sportsStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Dataset ────────────────────────────────────────────────
$where  = ['m.institution_id = ?'];
$params = [$instId];

if ($fStatus === 'active')   { $where[] = 'm.is_active = 1'; }
if ($fStatus === 'inactive') { $where[] = 'm.is_active = 0'; }
if ($fSport !== '')          { $where[] = 'm.sport_category = ?'; $params[] = $fSport; }
if ($fGender !== '')         { $where[] = 'm.gender = ?';          $params[] = $fGender; }
if ($fDateFrom !== '')       { $where[] = 'DATE(m.created_at) >= ?'; $params[] = $fDateFrom; }
if ($fDateTo !== '')         { $where[] = 'DATE(m.created_at) <= ?'; $params[] = $fDateTo; }

$stmt = $db->prepare(
    "SELECT m.member_code, m.first_name, m.last_name, m.gender, m.date_of_birth,
            m.mobile, m.email, m.sport_category, m.is_active, m.created_at,
            ms.plan_name, ms.end_date, ms.payment_status
     FROM members m
     LEFT JOIN memberships ms ON ms.id = (
         SELECT id FROM memberships WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1
     )
     WHERE " . implode(' AND ', $where) . "
     ORDER BY m.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Column definitions ─────────────────────────────────────
$cols = [
    ['key' => 'member_code',    'label' => 'Code',       'width' => 14],
    ['key' => 'first_name',     'label' => 'First Name', 'width' => 18],
    ['key' => 'last_name',      'label' => 'Last Name',  'width' => 18],
    ['key' => 'gender',         'label' => 'Gender',     'width' => 10,
     'format' => fn($v, $r) => ucfirst($v)],
    ['key' => 'date_of_birth',  'label' => 'DOB',        'width' => 14, 'format' => 'date'],
    ['key' => 'mobile',         'label' => 'Mobile',     'width' => 15],
    ['key' => 'email',          'label' => 'Email',      'width' => 28],
    ['key' => 'sport_category', 'label' => 'Sport',      'width' => 18],
    ['key' => 'is_active',      'label' => 'Status',     'width' => 10,
     'format' => fn($v, $r) => $v ? 'Active' : 'Inactive'],
    ['key' => 'plan_name',      'label' => 'Plan',       'width' => 22],
    ['key' => 'end_date',       'label' => 'Expires',    'width' => 14, 'format' => 'date'],
    ['key' => 'payment_status', 'label' => 'Payment',    'width' => 12,
     'format' => fn($v, $r) => $v ? ucfirst($v) : ''],
    ['key' => 'created_at',     'label' => 'Joined',     'width' => 16, 'format' => 'date'],
];

// Human-readable filter summary for report headers
$filterLabels = array_filter([
    'Status'      => $fStatus ? ucfirst($fStatus) : 'All',
    'Sport'       => $fSport ?: null,
    'Gender'      => $fGender ? ucfirst($fGender) : null,
    'Joined From' => $fDateFrom ? date('d M Y', strtotime($fDateFrom)) : null,
    'Joined To'   => $fDateTo   ? date('d M Y', strtotime($fDateTo))   : null,
]);

// ── Export outputs ─────────────────────────────────────────
if ($output === 'xlsx') {
    Report::xlsx($rows, $cols, 'Member Report', $filterLabels, $inst);
}
if ($output === 'print') {
    Report::printPage($rows, $cols, 'Member Report', $filterLabels, $inst);
}

// ── On-screen view ─────────────────────────────────────────
$pageTitle   = memberLabel(false) . ' Report';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Reports'   => BASE_URL . '/app/reports',
    memberLabel() => '',
];
require_once APP_ROOT . '/includes/header.php';

// Build export URL preserving current filters
$exportBase = BASE_URL . '/app/reports/members?' . http_build_query(array_filter([
    'status'    => $fStatus,
    'sport'     => $fSport,
    'gender'    => $fGender,
    'date_from' => $fDateFrom,
    'date_to'   => $fDateTo,
]));
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-people-fill"></i></div>
  <div>
    <h4><?= memberLabel(false) ?> Report</h4>
    <p>Filter and export member records for your institution.</p>
  </div>
</div>

<!-- Filter Form -->
<div class="card mb-4">
  <div class="card-header"><i class="bi bi-funnel me-2 text-primary"></i>Filters</div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-3 col-6">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="all"      <?= $fStatus === 'all'      ? 'selected':'' ?>>All Members</option>
          <option value="active"   <?= $fStatus === 'active'   ? 'selected':'' ?>>Active Only</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected':'' ?>>Inactive Only</option>
        </select>
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label">Sport Category</label>
        <select class="form-select" name="sport">
          <option value="">All Sports</option>
          <?php foreach ($sports as $s): ?>
          <option value="<?= h($s) ?>" <?= $fSport === $s ? 'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label">Gender</label>
        <select class="form-select" name="gender">
          <option value="">Any</option>
          <option value="male"   <?= $fGender === 'male'   ? 'selected':'' ?>>Male</option>
          <option value="female" <?= $fGender === 'female' ? 'selected':'' ?>>Female</option>
          <option value="other"  <?= $fGender === 'other'  ? 'selected':'' ?>>Other</option>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label">Joined From</label>
        <input type="date" class="form-control" name="date_from" value="<?= h($fDateFrom) ?>">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label">Joined To</label>
        <input type="date" class="form-control" name="date_to" value="<?= h($fDateTo) ?>">
      </div>
      <div class="col-12 d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Apply Filters
        </button>
        <a href="<?= h(BASE_URL . '/app/reports/members') ?>" class="btn btn-outline-secondary">
          Reset
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Results -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>
      <i class="bi bi-table me-2 text-primary"></i>
      <strong><?= count($rows) ?></strong> member<?= count($rows) !== 1 ? 's' : '' ?> found
    </span>
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= h($exportBase . '&output=print') ?>" target="_blank"
         class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-printer me-1"></i>Print
      </a>
      <a href="<?= h($exportBase . '&output=xlsx') ?>"
         class="btn btn-sm btn-success">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
      </a>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <?= Report::table($rows, $cols) ?>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
