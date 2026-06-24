<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Students are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/services');
    exit;
}

$scope = getModuleScope('students');
if ($scope === 'none') {
    setFlash('error', 'You do not have permission to access the Students module.');
    header('Location: ' . dashboardUrl());
    exit;
}

$isAdmin = isInstAdmin();
$staffId = !$isAdmin ? authStaffId() : null;

// Section IDs accessible to this user
$accessibleSectionIds = [];
if ($scope === 'own_class') {
    $accessibleSectionIds = $staffId ? getTeacherSectionIds($staffId, $instId) : [];
    if (!$accessibleSectionIds) $accessibleSectionIds = [0]; // matches nothing
}

// Filters
$search       = trim($_GET['q']          ?? '');
$filterSec    = (int)($_GET['section_id'] ?? 0);
$filterStatus = $_GET['status']           ?? 'active';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// Build WHERE
$where  = 'st.institution_id = ?';
$params = [$instId];

if ($scope === 'own_class') {
    $ph     = implode(',', array_fill(0, count($accessibleSectionIds), '?'));
    $where .= " AND st.section_id IN ({$ph})";
    $params = array_merge($params, $accessibleSectionIds);
}
if ($filterSec) {
    $where .= ' AND st.section_id = ?';
    $params[] = $filterSec;
}
if ($filterStatus === 'active')   { $where .= ' AND st.is_active = 1'; }
elseif ($filterStatus === 'inactive') { $where .= ' AND st.is_active = 0'; }
if ($search) {
    $s = '%' . $search . '%';
    $where .= ' AND (st.first_name LIKE ? OR st.last_name LIKE ? OR st.admission_number LIKE ? OR st.mobile LIKE ?)';
    $params = array_merge($params, [$s, $s, $s, $s]);
}

// Sections for filter dropdown (scoped)
$secWhere  = 'sec.institution_id = ? AND sec.is_active = 1';
$secParams = [$instId];
if ($scope === 'own_class') {
    $ph        = implode(',', array_fill(0, count($accessibleSectionIds), '?'));
    $secWhere .= " AND sec.id IN ({$ph})";
    $secParams = array_merge($secParams, $accessibleSectionIds);
}
$secStmt = $db->prepare(
    "SELECT sec.id, CONCAT(cls.name, ' – ', dv.name) AS label
     FROM sections sec
     JOIN classes cls ON cls.id = sec.class_id
     JOIN divisions dv ON dv.id = sec.division_id
     WHERE {$secWhere}
     ORDER BY cls.numeric_order, cls.name, dv.name"
);
$secStmt->execute($secParams);
$sectionOptions = $secStmt->fetchAll();

$cntStmt = $db->prepare("SELECT COUNT(*) FROM students st WHERE {$where}");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$listStmt = $db->prepare(
    "SELECT st.id, st.admission_number, st.roll_number,
            st.first_name, st.last_name, st.gender,
            st.mobile, st.passport_photo, st.is_active,
            cls.name AS class_name, dv.name AS division_name
     FROM students st
     JOIN sections sec ON sec.id = st.section_id
     JOIN classes  cls ON cls.id = sec.class_id
     JOIN divisions dv ON  dv.id = sec.division_id
     WHERE {$where}
     ORDER BY cls.numeric_order, cls.name, dv.name, st.roll_number, st.first_name
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$students = $listStmt->fetchAll();

// Toggle active (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    if ($isAdmin || $scope === 'all') {
        $togId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE students SET is_active = NOT is_active WHERE id = ? AND institution_id = ?")
           ->execute([$togId, $instId]);
        setFlash('success', 'Student status updated.');
    }
    $qs = http_build_query(array_filter(['q' => $search, 'section_id' => $filterSec, 'status' => $filterStatus, 'page' => $page]));
    header('Location: ' . BASE_URL . '/app/services/students' . ($qs ? "?{$qs}" : ''));
    exit;
}

$pageTitle   = 'Students';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Services' => BASE_URL . '/app/services', 'Students' => ''];
$canAdd      = ($scope !== 'none');
if ($canAdd) {
    $pageAction = '<a href="' . h(BASE_URL . '/app/services/students-add') . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Student</a>';
}
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-mortarboard-fill"></i></div>
  <div>
    <h4>Students</h4>
    <p>View and manage student enrollment across sections.
      <?php if ($scope === 'own_class'): ?>
      <span class="badge bg-warning text-dark ms-1">Own Section Only</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- Filter bar -->
<div class="filter-bar mb-4">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4">
      <label class="form-label small mb-1">Search</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" class="form-control" name="q"
               value="<?= h($search) ?>" placeholder="Name, admission no., mobile…">
      </div>
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Section</label>
      <select class="form-select form-select-sm" name="section_id">
        <option value="">All sections</option>
        <?php foreach ($sectionOptions as $so): ?>
        <option value="<?= $so['id'] ?>" <?= $filterSec === (int)$so['id'] ? 'selected' : '' ?>>
          <?= h($so['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Status</label>
      <select class="form-select form-select-sm" name="status">
        <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        <option value="all"      <?= $filterStatus === 'all'      ? 'selected' : '' ?>>All</option>
      </select>
    </div>
    <div class="col-sm-auto">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
      <a href="?" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
    </div>
  </form>
</div>

<div class="card table-card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-mortarboard me-2 text-primary"></i>Students
      <span class="badge bg-secondary ms-1"><?= $total ?></span>
    </span>
    <?php if ($canAdd): ?>
    <a href="<?= h(BASE_URL . '/app/services/students-add') ?>"
       class="btn btn-sm btn-primary d-md-none"><i class="bi bi-plus"></i></a>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Section</th>
          <th>Roll No</th>
          <th>Mobile</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($students): ?>
        <?php foreach ($students as $i => $st): ?>
        <tr>
          <td class="text-muted small"><?= $offset + $i + 1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($st['passport_photo'])): ?>
              <img src="<?= h(PHOTO_URL . '/' . $st['passport_photo']) ?>"
                   alt="" style="width:32px;height:32px;border-radius:6px;object-fit:cover;flex-shrink:0;">
              <?php else: ?>
              <div class="avatar-circle" style="width:32px;height:32px;font-size:.75rem;border-radius:6px;">
                <?= mb_strtoupper(mb_substr($st['first_name'], 0, 1)) ?>
              </div>
              <?php endif; ?>
              <div>
                <div class="fw-600 small"><?= h($st['first_name'] . ' ' . $st['last_name']) ?></div>
                <div class="text-muted" style="font-size:.72rem;"><?= h($st['admission_number']) ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="badge bg-primary bg-opacity-10 text-primary fw-600">
              <?= h($st['class_name']) ?>
            </span>
            <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1">
              <?= h($st['division_name']) ?>
            </span>
          </td>
          <td class="small text-muted"><?= $st['roll_number'] ?: '—' ?></td>
          <td class="small"><?= h($st['mobile'] ?? '—') ?></td>
          <td>
            <?= $st['is_active']
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-danger">Inactive</span>' ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <a href="<?= h(BASE_URL . '/app/services/students-add?id=' . $st['id']) ?>"
                 class="btn btn-sm btn-outline-primary btn-icon" title="Edit" data-bs-toggle="tooltip">
                <i class="bi bi-pencil"></i>
              </a>
              <?php if ($isAdmin || $scope === 'all'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $st['id'] ?>">
                <button type="submit"
                        class="btn btn-sm <?= $st['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                        title="<?= $st['is_active'] ? 'Deactivate' : 'Activate' ?>"
                        data-bs-toggle="tooltip"
                        data-confirm="<?= $st['is_active'] ? 'Deactivate this student?' : 'Activate this student?' ?>">
                  <i class="bi <?= $st['is_active'] ? 'bi-person-dash' : 'bi-person-check' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="7">
          <div class="empty-state py-4">
            <i class="bi bi-mortarboard"></i>
            <h6>No students found</h6>
            <?php if ($canAdd && !$search && !$filterSec): ?>
            <p class="small">Use the Add Student button to enroll your first student.</p>
            <a href="<?= h(BASE_URL . '/app/services/students-add') ?>" class="btn btn-primary btn-sm">
              <i class="bi bi-plus-circle me-1"></i>Add Student
            </a>
            <?php endif; ?>
          </div>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="text-muted small">
      Showing <?= min($offset+1,$total) ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?>
    </span>
    <?= paginate($total, $page, $perPage, BASE_URL . '/app/services/students') ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
