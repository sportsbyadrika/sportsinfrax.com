<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Sections are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/services');
    exit;
}

$isAdmin = isInstAdmin();

// Academic years for filter
$ayStmt = $db->prepare(
    "SELECT * FROM academic_years WHERE institution_id = ? ORDER BY start_date DESC"
);
$ayStmt->execute([$instId]);
$allYears = $ayStmt->fetchAll();

// Determine selected year
$activeYear = null;
foreach ($allYears as $ay) {
    if ($ay['is_active']) { $activeYear = $ay; break; }
}
$selectedYearId = (int)($_GET['year_id'] ?? ($activeYear['id'] ?? 0));

// Classes and divisions for the add form
$clsStmt = $db->prepare(
    "SELECT * FROM classes WHERE institution_id = ? AND is_active = 1 ORDER BY numeric_order, name"
);
$clsStmt->execute([$instId]);
$classes = $clsStmt->fetchAll();

$divStmt = $db->prepare(
    "SELECT * FROM divisions WHERE institution_id = ? AND is_active = 1 ORDER BY name"
);
$divStmt->execute([$instId]);
$divisions = $divStmt->fetchAll();

// Staff list for class teacher dropdown
$staffStmt = $db->prepare(
    "SELECT s.id, u.full_name FROM staff s JOIN users u ON u.id = s.user_id
     WHERE s.institution_id = ? AND s.is_active = 1 ORDER BY u.full_name"
);
$staffStmt->execute([$instId]);
$staffList = $staffStmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle' && $isAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE sections SET is_active = NOT is_active WHERE id = ? AND institution_id = ?")
           ->execute([$id, $instId]);
        setFlash('success', 'Section status updated.');
        header('Location: ' . BASE_URL . '/app/services/sections?year_id=' . $selectedYearId);
        exit;

    } elseif ($action === 'assign_teacher' && $isAdmin) {
        $id        = (int)($_POST['id']               ?? 0);
        $teacherId = (int)($_POST['class_teacher_id'] ?? 0) ?: null;
        $db->prepare(
            "UPDATE sections SET class_teacher_id = ? WHERE id = ? AND institution_id = ?"
        )->execute([$teacherId, $id, $instId]);
        setFlash('success', 'Class teacher updated.');
        header('Location: ' . BASE_URL . '/app/services/sections?year_id=' . $selectedYearId);
        exit;

    } elseif ($action === 'add' && $isAdmin) {
        $yearId    = (int)($_POST['academic_year_id']  ?? 0);
        $classId   = (int)($_POST['class_id']          ?? 0);
        $divId     = (int)($_POST['division_id']       ?? 0);
        $teacherId = (int)($_POST['class_teacher_id']  ?? 0) ?: null;
        $capacity  = (int)($_POST['capacity']          ?? 0) ?: null;

        if (!$yearId || !$classId || !$divId) {
            $error = 'Academic year, class and division are all required.';
        } else {
            try {
                $db->prepare(
                    "INSERT INTO sections
                     (institution_id, academic_year_id, class_id, division_id,
                      class_teacher_id, capacity, created_by)
                     VALUES (?,?,?,?,?,?,?)"
                )->execute([$instId, $yearId, $classId, $divId, $teacherId, $capacity, authId()]);
                setFlash('success', 'Section created successfully.');
            } catch (Exception $e) {
                $error = 'This class–division combination already exists for the selected year.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/services/sections?year_id=' . $yearId);
                exit;
            }
        }
    }
}

// Load sections for selected year
$sections = [];
if ($selectedYearId) {
    $secStmt = $db->prepare(
        "SELECT sec.*,
                cls.name  AS class_name, cls.numeric_order,
                div.name  AS division_name,
                u.full_name AS teacher_name
         FROM sections sec
         JOIN classes   cls ON cls.id = sec.class_id
         JOIN divisions div ON div.id = sec.division_id
         LEFT JOIN staff  st ON st.id = sec.class_teacher_id
         LEFT JOIN users   u ON  u.id = st.user_id
         WHERE sec.institution_id = ? AND sec.academic_year_id = ?
         ORDER BY cls.numeric_order, cls.name, div.name"
    );
    $secStmt->execute([$instId, $selectedYearId]);
    $sections = $secStmt->fetchAll();
}

$selectedYear = null;
foreach ($allYears as $ay) {
    if ($ay['id'] === $selectedYearId) { $selectedYear = $ay; break; }
}

$pageTitle   = 'Sections';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Services' => BASE_URL . '/app/services', 'Sections' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-diagram-3-fill"></i></div>
  <div>
    <h4>Sections</h4>
    <p>Manage class–division assignments per academic year. Assign class teachers and capacities.</p>
  </div>
</div>

<?php if (!$allYears): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i>
  <div>
    <strong>No academic years set up.</strong><br>
    <a href="<?= h(BASE_URL . '/app/settings/academic-years') ?>" class="alert-link">
      Go to Settings → Academic Years
    </a> to add your first year before creating sections.
  </div>
</div>
<?php elseif (!$classes || !$divisions): ?>
<div class="alert alert-info d-flex align-items-center gap-3">
  <i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i>
  <div>
    <strong>Classes or divisions missing.</strong><br>
    Please set up
    <a href="<?= h(BASE_URL . '/app/settings/classes') ?>">Classes</a>
    and <a href="<?= h(BASE_URL . '/app/settings/divisions') ?>">Divisions</a>
    in Settings before creating sections.
  </div>
</div>
<?php else: ?>

<!-- Year Filter -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <label class="fw-600 mb-0">Academic Year:</label>
  <div class="d-flex gap-2 flex-wrap">
    <?php foreach ($allYears as $ay): ?>
    <a href="<?= h(BASE_URL . '/app/services/sections?year_id=' . $ay['id']) ?>"
       class="btn btn-sm <?= $ay['id'] === $selectedYearId ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= h($ay['label']) ?>
      <?php if ($ay['is_active']): ?>
      <span class="badge bg-success ms-1" style="font-size:.6rem;">Active</span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="row g-4">

  <!-- Add Section Form (admin only) -->
  <?php if ($isAdmin): ?>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Section</div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add">

          <div class="mb-3">
            <label class="form-label">Academic Year <span class="required">*</span></label>
            <select class="form-select" name="academic_year_id" required>
              <option value="">Select year…</option>
              <?php foreach ($allYears as $ay): ?>
              <option value="<?= $ay['id'] ?>"
                <?= $ay['id'] === $selectedYearId ? 'selected' : '' ?>>
                <?= h($ay['label']) ?><?= $ay['is_active'] ? ' (Active)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Class <span class="required">*</span></label>
            <select class="form-select" name="class_id" required>
              <option value="">Select class…</option>
              <?php foreach ($classes as $cls): ?>
              <option value="<?= $cls['id'] ?>"><?= h($cls['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Division <span class="required">*</span></label>
            <select class="form-select" name="division_id" required>
              <option value="">Select division…</option>
              <?php foreach ($divisions as $div): ?>
              <option value="<?= $div['id'] ?>"><?= h($div['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Class Teacher</label>
            <select class="form-select" name="class_teacher_id">
              <option value="">Assign later…</option>
              <?php foreach ($staffList as $sf): ?>
              <option value="<?= $sf['id'] ?>"><?= h($sf['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Capacity</label>
            <input type="number" class="form-control" name="capacity"
                   value="<?= h($_POST['capacity'] ?? '') ?>"
                   min="1" max="200" placeholder="e.g. 40">
            <div class="form-text">Leave blank if no limit.</div>
          </div>

          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-plus-circle me-1"></i>Create Section
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Section List -->
  <div class="<?= $isAdmin ? 'col-lg-8' : 'col-12' ?>">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-diagram-3 me-2 text-primary"></i>
          Sections
          <?php if ($selectedYear): ?>
          – <?= h($selectedYear['label']) ?>
          <?php endif; ?>
          <span class="badge bg-secondary ms-1"><?= count($sections) ?></span>
        </span>
      </div>
      <div class="card-body p-0">
        <?php if ($sections): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Class</th>
                <th>Division</th>
                <th>Class Teacher</th>
                <th>Capacity</th>
                <th>Status</th>
                <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sections as $sec): ?>
              <tr>
                <td class="fw-600"><?= h($sec['class_name']) ?></td>
                <td>
                  <span class="badge bg-primary bg-opacity-10 text-primary fw-600">
                    <?= h($sec['division_name']) ?>
                  </span>
                </td>
                <td class="small">
                  <?php if ($sec['teacher_name']): ?>
                    <?= h($sec['teacher_name']) ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?= $sec['capacity'] ? h($sec['capacity']) : '—' ?>
                </td>
                <td>
                  <?= $sec['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <?php if ($isAdmin): ?>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <!-- Assign Teacher -->
                    <button type="button"
                            class="btn btn-sm btn-outline-primary btn-icon"
                            title="Assign Teacher"
                            data-bs-toggle="tooltip"
                            data-bs-toggle2="modal"
                            data-bs-target="#teacherModal"
                            onclick="openTeacherModal(<?= $sec['id'] ?>, <?= (int)$sec['class_teacher_id'] ?>)">
                      <i class="bi bi-person-plus"></i>
                    </button>
                    <!-- Toggle Active -->
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $sec['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $sec['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $sec['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $sec['is_active'] ? 'Deactivate this section?' : 'Activate this section?' ?>">
                        <i class="bi <?= $sec['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                  </div>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php elseif ($selectedYearId): ?>
        <div class="empty-state py-4">
          <i class="bi bi-diagram-3"></i>
          <h6>No sections for this year</h6>
          <?php if ($isAdmin): ?>
          <p class="small">Use the form on the left to create class–division sections.</p>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-calendar2"></i>
          <h6>Select an academic year above</h6>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php if ($isAdmin): ?>
<!-- Assign Teacher Modal -->
<div class="modal fade" id="teacherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2 text-primary"></i>Assign Class Teacher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="teacherForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="assign_teacher">
        <input type="hidden" name="id" id="teacherSectionId" value="">
        <div class="modal-body">
          <label class="form-label">Class Teacher</label>
          <select class="form-select" name="class_teacher_id" id="teacherSelect">
            <option value="">— No teacher assigned —</option>
            <?php foreach ($staffList as $sf): ?>
            <option value="<?= $sf['id'] ?>"><?= h($sf['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openTeacherModal(sectionId, currentTeacherId) {
  document.getElementById('teacherSectionId').value = sectionId;
  const sel = document.getElementById('teacherSelect');
  sel.value = currentTeacherId || '';
  new bootstrap.Modal(document.getElementById('teacherModal')).show();
}
</script>
<?php endif; ?>

<?php endif; // has years + classes + divisions ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
