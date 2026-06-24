<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// ── Load exam types (active only, for select) ──────────────────────────────
$etStmt = $db->prepare(
    "SELECT * FROM exam_types WHERE institution_id = ? AND is_active = 1
     ORDER BY sort_order, name"
);
$etStmt->execute([$instId]);
$examTypes = $etStmt->fetchAll();
$examTypeMap = [];
foreach ($examTypes as $et) {
    $examTypeMap[$et['id']] = $et;
}

// ── Load academic years (active year first, then others) ───────────────────
$ayStmt = $db->prepare(
    "SELECT * FROM academic_years WHERE institution_id = ?
     ORDER BY is_active DESC, label DESC"
);
$ayStmt->execute([$instId]);
$academicYears = $ayStmt->fetchAll();
$ayMap = [];
foreach ($academicYears as $ay) {
    $ayMap[$ay['id']] = $ay;
}

// ── Load sections grouped by academic year ─────────────────────────────────
$secStmt = $db->prepare(
    "SELECT sec.id,
            cls.name  AS class_name,
            cls.numeric_order,
            dv.name   AS div_name,
            ay.label  AS year_label,
            ay.is_active AS year_active
     FROM sections sec
     JOIN classes       cls ON cls.id = sec.class_id
     JOIN divisions     dv  ON dv.id  = sec.division_id
     JOIN academic_years ay ON ay.id  = sec.academic_year_id
     WHERE sec.institution_id = ? AND sec.is_active = 1
     ORDER BY ay.is_active DESC, cls.numeric_order, cls.name, dv.name"
);
$secStmt->execute([$instId]);
$sectionRows = $secStmt->fetchAll();

// Build section map and optgroup structure
$sectionMap      = [];
$sectionOptgroups = [];
$sectionJsData   = [];
foreach ($sectionRows as $sec) {
    $sectionMap[$sec['id']] = $sec;
    $sectionOptgroups[$sec['year_label']][] = $sec;
    $sectionJsData[$sec['id']] = [
        'label'      => $sec['class_name'] . ' ' . $sec['div_name'],
        'year_label' => $sec['year_label'],
    ];
}

// ── Edit mode ─────────────────────────────────────────────────────────────────
$editId   = (int)($_GET['edit_id'] ?? 0);
$editExam = null;
if ($editId) {
    $ee = $db->prepare("SELECT * FROM exams WHERE id = ? AND institution_id = ?");
    $ee->execute([$editId, $instId]);
    $editExam = $ee->fetch();
}

$error = '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Publish / Unpublish toggle
    if ($action === 'toggle_publish') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare(
                "UPDATE exams SET is_published = NOT is_published WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Exam publish status updated.');
        }
        header('Location: ' . BASE_URL . '/app/services/exams');
        exit;

    // Delete
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->beginTransaction();
                $db->prepare(
                    "DELETE FROM exam_marks WHERE exam_id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                $db->prepare(
                    "DELETE FROM exams WHERE id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                $db->commit();
                setFlash('success', 'Exam and all associated marks deleted.');
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('error', 'Could not delete exam. Please try again.');
            }
        }
        header('Location: ' . BASE_URL . '/app/services/exams');
        exit;

    // Add / Edit
    } elseif ($action === 'add' || $action === 'edit') {
        $examTypeId     = (int)($_POST['exam_type_id']     ?? 0);
        $academicYearId = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $sectionId      = (int)($_POST['section_id']       ?? 0);
        $label          = trim($_POST['label']              ?? '');
        $startDate      = trim($_POST['start_date']         ?? '') ?: null;
        $endDate        = trim($_POST['end_date']           ?? '') ?: null;
        $id             = (int)($_POST['id']                ?? 0);

        if (!$examTypeId || !isset($examTypeMap[$examTypeId]))
            $error = 'Please select a valid exam type.';
        elseif (!$sectionId || !isset($sectionMap[$sectionId]))
            $error = 'Please select a valid section.';
        elseif (!$label)
            $error = 'Exam label is required.';
        elseif (mb_strlen($label) > 200)
            $error = 'Label must be 200 characters or fewer.';
        elseif ($startDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate))
            $error = 'Invalid start date.';
        elseif ($endDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))
            $error = 'Invalid end date.';
        elseif ($startDate && $endDate && $endDate < $startDate)
            $error = 'End date cannot be before start date.';

        if (!$error) {
            $userId = authId();
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE exams
                         SET exam_type_id = ?, academic_year_id = ?, section_id = ?,
                             label = ?, start_date = ?, end_date = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([
                        $examTypeId, $academicYearId, $sectionId,
                        $label, $startDate, $endDate,
                        $id, $instId,
                    ]);
                    setFlash('success', "Exam '{$label}' updated.");
                } else {
                    $db->prepare(
                        "INSERT INTO exams
                           (institution_id, academic_year_id, exam_type_id, section_id,
                            label, start_date, end_date, created_by)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute([
                        $instId, $academicYearId, $examTypeId, $sectionId,
                        $label, $startDate, $endDate, $userId,
                    ]);
                    setFlash('success', "Exam '{$label}' created.");
                }
            } catch (Exception $e) {
                $error = 'Could not save exam. Please try again.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/services/exams');
                exit;
            }
        }
    }
}

// ── Load exam list ────────────────────────────────────────────────────────────
$listStmt = $db->prepare(
    "SELECT e.*,
            et.name           AS type_name,
            et.is_grade_based,
            cls.name          AS class_name,
            dv.name           AS div_name,
            ay.label          AS year_label
     FROM exams e
     JOIN exam_types    et  ON et.id  = e.exam_type_id
     JOIN sections      sec ON sec.id = e.section_id
     JOIN classes       cls ON cls.id = sec.class_id
     JOIN divisions     dv  ON dv.id  = sec.division_id
     LEFT JOIN academic_years ay ON ay.id = e.academic_year_id
     WHERE e.institution_id = ?
     ORDER BY e.created_at DESC"
);
$listStmt->execute([$instId]);
$exams = $listStmt->fetchAll();

// Repopulate form values
$fv = $editExam ? $editExam : [];
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fv = $_POST;
}

$pageTitle   = 'Exams';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Services'  => BASE_URL . '/app/services',
    'Exams'     => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-journal-richtext"></i></div>
  <div>
    <h4>Exams</h4>
    <p>Create and manage exam instances for each section and academic year.</p>
  </div>
</div>

<div class="row g-4">

  <!-- ── Add / Edit Form ──────────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editExam ? 'Edit Exam' : 'Add Exam' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (!$examTypes): ?>
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>
          No active exam types found.
          <a href="<?= h(BASE_URL . '/app/settings/exam-types') ?>">Add exam types</a> first.
        </div>
        <?php elseif (!$sectionRows): ?>
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>
          No sections found. Please set up sections first.
        </div>
        <?php else: ?>

        <form method="POST" id="examForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editExam ? 'edit' : 'add' ?>">
          <?php if ($editExam): ?>
          <input type="hidden" name="id" value="<?= (int)$editExam['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Exam Type <span class="text-danger">*</span></label>
            <select class="form-select" name="exam_type_id" id="selExamType" required>
              <option value="">Select type…</option>
              <?php foreach ($examTypes as $et): ?>
              <option value="<?= (int)$et['id'] ?>"
                      data-name="<?= h($et['name']) ?>"
                <?= (int)($fv['exam_type_id'] ?? 0) === (int)$et['id'] ? 'selected' : '' ?>>
                <?= h($et['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Academic Year</label>
            <select class="form-select" name="academic_year_id">
              <option value="">— Not specified —</option>
              <?php foreach ($academicYears as $ay): ?>
              <option value="<?= (int)$ay['id'] ?>"
                <?= (int)($fv['academic_year_id'] ?? 0) === (int)$ay['id'] ? 'selected' : '' ?>>
                <?= h($ay['label']) ?><?= $ay['is_active'] ? ' (Active)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Section <span class="text-danger">*</span></label>
            <select class="form-select" name="section_id" id="selSection" required>
              <option value="">Select section…</option>
              <?php foreach ($sectionOptgroups as $yearLabel => $secRows): ?>
              <optgroup label="<?= h($yearLabel) ?>">
                <?php foreach ($secRows as $sec): ?>
                <option value="<?= (int)$sec['id'] ?>"
                        data-label="<?= h($sec['class_name'] . ' ' . $sec['div_name']) ?>"
                  <?= (int)($fv['section_id'] ?? 0) === (int)$sec['id'] ? 'selected' : '' ?>>
                  <?= h($sec['class_name'] . ' – ' . $sec['div_name']) ?>
                </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Label <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="label" id="examLabel"
                   value="<?= h($fv['label'] ?? '') ?>"
                   placeholder="e.g. Term 1 Exam – Class 8A"
                   maxlength="200" required>
            <div class="form-text">Auto-populated when you select a type and section.</div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label">Start Date</label>
              <input type="date" class="form-control" name="start_date"
                     value="<?= h($fv['start_date'] ?? '') ?>">
            </div>
            <div class="col">
              <label class="form-label">End Date</label>
              <input type="date" class="form-control" name="end_date"
                     value="<?= h($fv['end_date'] ?? '') ?>">
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editExam ? 'Save Changes' : 'Create Exam' ?>
            </button>
            <?php if ($editExam): ?>
            <a href="<?= h(BASE_URL . '/app/services/exams') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Exam List ─────────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-journal-richtext me-2 text-primary"></i>Exam List
        <span class="badge bg-secondary ms-1"><?= count($exams) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($exams): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width:2.5rem">#</th>
                <th>Label</th>
                <th>Exam Type</th>
                <th>Section</th>
                <th>Dates</th>
                <th class="text-center">Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($exams as $i => $ex): ?>
              <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td>
                  <div class="fw-600 small"><?= h($ex['label']) ?></div>
                  <?php if ($ex['year_label']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= h($ex['year_label']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-primary bg-opacity-15 text-primary border border-primary-subtle">
                    <?= h($ex['type_name']) ?>
                  </span>
                  <?php if ($ex['is_grade_based']): ?>
                  <span class="badge bg-info bg-opacity-15 text-info ms-1 small">Grade</span>
                  <?php endif; ?>
                </td>
                <td class="small"><?= h($ex['class_name'] . ' – ' . $ex['div_name']) ?></td>
                <td class="small text-muted">
                  <?php if ($ex['start_date'] || $ex['end_date']): ?>
                    <?= $ex['start_date'] ? h(fmtDate($ex['start_date'])) : '?' ?>
                    <?php if ($ex['end_date']): ?>
                    <span class="mx-1">→</span><?= h(fmtDate($ex['end_date'])) ?>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?= $ex['is_published']
                      ? '<span class="badge bg-success"><i class="bi bi-eye me-1"></i>Published</span>'
                      : '<span class="badge bg-secondary">Draft</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= h(BASE_URL . '/app/services/exams?edit_id=' . $ex['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon"
                       title="Edit" data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <a href="<?= h(BASE_URL . '/app/services/exam-marks?exam_id=' . $ex['id']) ?>"
                       class="btn btn-sm btn-outline-info btn-icon"
                       title="Enter Marks" data-bs-toggle="tooltip">
                      <i class="bi bi-input-cursor-text"></i>
                    </a>
                    <!-- Publish toggle -->
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle_publish">
                      <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $ex['is_published'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $ex['is_published'] ? 'Unpublish' : 'Publish' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $ex['is_published']
                                  ? 'Unpublish this exam? Staff will no longer see it.'
                                  : 'Publish this exam? Staff will be able to enter marks.' ?>">
                        <i class="bi <?= $ex['is_published'] ? 'bi-eye-slash' : 'bi-send-check' ?>"></i>
                      </button>
                    </form>
                    <!-- Delete -->
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete" data-bs-toggle="tooltip"
                              data-confirm="Delete exam '<?= h(addslashes($ex['label'])) ?>'? This will delete all marks for this exam and cannot be undone.">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-journal-richtext"></i>
          <h6>No exams yet</h6>
          <p class="small">Create your first exam to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /.row -->

<script>
(function () {
    var selType    = document.getElementById('selExamType');
    var selSection = document.getElementById('selSection');
    var labelInput = document.getElementById('examLabel');

    function buildLabel() {
        if (!selType || !selSection || !labelInput) return;
        var typeOpt    = selType.options[selType.selectedIndex];
        var sectionOpt = selSection.options[selSection.selectedIndex];
        if (!typeOpt || !sectionOpt || !typeOpt.value || !sectionOpt.value) return;

        var typeName    = typeOpt.getAttribute('data-name')  || typeOpt.text;
        var sectionName = sectionOpt.getAttribute('data-label') || sectionOpt.text;
        labelInput.value = typeName + ' – ' + sectionName;
    }

    if (selType)    selType.addEventListener('change',    buildLabel);
    if (selSection) selSection.addEventListener('change', buildLabel);
}());
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
