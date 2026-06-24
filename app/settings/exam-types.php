<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// ── Edit mode ─────────────────────────────────────────────────────────────────
$editId = (int)($_GET['edit_id'] ?? 0);
$editEt = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM exam_types WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editEt = $es->fetch();
}

$error = '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Toggle active
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare(
                "UPDATE exam_types SET is_active = NOT is_active WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Exam type status updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/exam-types');
        exit;

    // Delete
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->prepare(
                    "DELETE FROM exam_types WHERE id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                setFlash('success', 'Exam type deleted.');
            } catch (Exception $e) {
                setFlash('error', 'Cannot delete: this exam type has exams linked to it.');
            }
        }
        header('Location: ' . BASE_URL . '/app/settings/exam-types');
        exit;

    // Add / Edit
    } elseif ($action === 'add' || $action === 'edit') {
        $name         = trim($_POST['name']          ?? '');
        $maxMarks     = $_POST['max_marks']           ?? '100.00';
        $passMarks    = $_POST['pass_marks']          ?? '35.00';
        $isGradeBased = !empty($_POST['is_grade_based']) ? 1 : 0;
        $sortOrder    = min(255, max(0, (int)($_POST['sort_order'] ?? 0)));
        $id           = (int)($_POST['id']            ?? 0);

        if (!$name)                      $error = 'Exam type name is required.';
        elseif (mb_strlen($name) > 100)  $error = 'Name must be 100 characters or fewer.';
        elseif (!$isGradeBased) {
            if (!is_numeric($maxMarks) || (float)$maxMarks <= 0)
                $error = 'Max marks must be a positive number.';
            elseif (!is_numeric($passMarks) || (float)$passMarks < 0)
                $error = 'Pass marks must be 0 or greater.';
            elseif ((float)$passMarks > (float)$maxMarks)
                $error = 'Pass marks cannot exceed max marks.';
        }

        if (!$error) {
            $maxMarksVal  = $isGradeBased ? 100.00 : (float)$maxMarks;
            $passMarksVal = $isGradeBased ? 0.00   : (float)$passMarks;

            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE exam_types
                         SET name = ?, max_marks = ?, pass_marks = ?, is_grade_based = ?, sort_order = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([
                        $name,
                        number_format($maxMarksVal,  2, '.', ''),
                        number_format($passMarksVal, 2, '.', ''),
                        $isGradeBased, $sortOrder,
                        $id, $instId,
                    ]);
                    setFlash('success', "Exam type '{$name}' updated.");
                } else {
                    $db->prepare(
                        "INSERT INTO exam_types
                           (institution_id, name, max_marks, pass_marks, is_grade_based, sort_order)
                         VALUES (?,?,?,?,?,?)"
                    )->execute([
                        $instId, $name,
                        number_format($maxMarksVal,  2, '.', ''),
                        number_format($passMarksVal, 2, '.', ''),
                        $isGradeBased, $sortOrder,
                    ]);
                    setFlash('success', "Exam type '{$name}' added.");
                }
            } catch (Exception $e) {
                $error = 'An exam type with that name already exists.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/exam-types');
                exit;
            }
        }
    }
}

// ── Load list ─────────────────────────────────────────────────────────────────
$listStmt = $db->prepare(
    "SELECT * FROM exam_types WHERE institution_id = ?
     ORDER BY sort_order, name"
);
$listStmt->execute([$instId]);
$examTypes = $listStmt->fetchAll();

// Repopulate form on validation failure
$fv = [
    'name'          => $editEt['name']          ?? ($_POST['name']          ?? ''),
    'max_marks'     => $editEt['max_marks']      ?? ($_POST['max_marks']     ?? '100.00'),
    'pass_marks'    => $editEt['pass_marks']     ?? ($_POST['pass_marks']    ?? '35.00'),
    'is_grade_based'=> $editEt['is_grade_based'] ?? ($_POST['is_grade_based'] ?? ''),
    'sort_order'    => $editEt['sort_order']     ?? ($_POST['sort_order']    ?? '0'),
];
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // After a failed POST, use the submitted values, not the edit record
    $fv = [
        'name'           => $_POST['name']          ?? '',
        'max_marks'      => $_POST['max_marks']      ?? '100.00',
        'pass_marks'     => $_POST['pass_marks']     ?? '35.00',
        'is_grade_based' => $_POST['is_grade_based'] ?? '',
        'sort_order'     => $_POST['sort_order']     ?? '0',
    ];
}

$pageTitle   = 'Exam Types';
$breadcrumbs = [
    'Dashboard'  => dashboardUrl(),
    'Settings'   => BASE_URL . '/app/settings',
    'Exam Types' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-patch-check-fill"></i></div>
  <div>
    <h4>Exam Types</h4>
    <p>Define exam type categories such as Unit Test, Mid-Term, and Final Exam with scoring rules.</p>
  </div>
</div>

<div class="row g-4">

  <!-- ── Add / Edit Form ──────────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editEt ? 'Edit Exam Type' : 'Add Exam Type' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="examTypeForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editEt ? 'edit' : 'add' ?>">
          <?php if ($editEt): ?>
          <input type="hidden" name="id" value="<?= (int)$editEt['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name"
                   value="<?= h($fv['name']) ?>"
                   placeholder="e.g. Unit Test, Mid-Term, Final Exam"
                   maxlength="100" required>
          </div>

          <!-- Grade-based checkbox (controls numeric fields) -->
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_grade_based"
                     id="isGradeBased" value="1"
                     <?= $fv['is_grade_based'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="isGradeBased">
                Grade-based <span class="text-muted small">(no numeric marks)</span>
              </label>
            </div>
            <div class="form-text">When checked, students receive letter grades (A+, B, etc.) instead of numbers.</div>
          </div>

          <!-- Numeric fields – hidden when grade-based -->
          <div id="numericFields">
            <div class="mb-3">
              <label class="form-label">Max Marks</label>
              <input type="number" class="form-control" name="max_marks"
                     id="maxMarks"
                     value="<?= h((string)$fv['max_marks']) ?>"
                     min="0.01" step="0.01" placeholder="100.00">
              <div class="form-text">Total marks for this exam type.</div>
            </div>
            <div class="mb-3">
              <label class="form-label">Pass Marks</label>
              <input type="number" class="form-control" name="pass_marks"
                     id="passMarks"
                     value="<?= h((string)$fv['pass_marks']) ?>"
                     min="0" step="0.01" placeholder="35.00">
              <div class="form-text">Minimum marks required to pass.</div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order"
                   value="<?= h((string)$fv['sort_order']) ?>"
                   min="0" max="255">
            <div class="form-text">Lower numbers appear first (0–255).</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editEt ? 'Save Changes' : 'Add Exam Type' ?>
            </button>
            <?php if ($editEt): ?>
            <a href="<?= h(BASE_URL . '/app/settings/exam-types') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Exam Type List ────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-patch-check me-2 text-primary"></i>Exam Type List
        <span class="badge bg-secondary ms-1"><?= count($examTypes) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($examTypes): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width:2.5rem">#</th>
                <th>Name</th>
                <th class="text-end">Max Marks</th>
                <th class="text-end">Pass Marks</th>
                <th class="text-center">Type</th>
                <th class="text-center">Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($examTypes as $i => $et): ?>
              <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td class="fw-600"><?= h($et['name']) ?></td>
                <td class="text-end small">
                  <?= $et['is_grade_based']
                      ? '<span class="text-muted">—</span>'
                      : h(number_format((float)$et['max_marks'], 2)) ?>
                </td>
                <td class="text-end small">
                  <?= $et['is_grade_based']
                      ? '<span class="text-muted">—</span>'
                      : h(number_format((float)$et['pass_marks'], 2)) ?>
                </td>
                <td class="text-center">
                  <?php if ($et['is_grade_based']): ?>
                  <span class="badge bg-info bg-opacity-15 text-info border border-info-subtle">
                    <i class="bi bi-alphabet-uppercase me-1"></i>Grade
                  </span>
                  <?php else: ?>
                  <span class="badge bg-secondary bg-opacity-15 text-secondary border border-secondary-subtle">
                    <i class="bi bi-123 me-1"></i>Numeric
                  </span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?= $et['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= h(BASE_URL . '/app/settings/exam-types?edit_id=' . $et['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon"
                       title="Edit" data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$et['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $et['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $et['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $et['is_active']
                                  ? 'Deactivate this exam type?'
                                  : 'Activate this exam type?' ?>">
                        <i class="bi <?= $et['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$et['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete" data-bs-toggle="tooltip"
                              data-confirm="Delete exam type '<?= h(addslashes($et['name'])) ?>'? This cannot be undone.">
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
          <i class="bi bi-patch-check"></i>
          <h6>No exam types yet</h6>
          <p class="small">Add your first exam type to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /.row -->

<script>
(function () {
    var checkbox     = document.getElementById('isGradeBased');
    var numericBlock = document.getElementById('numericFields');
    var maxInput     = document.getElementById('maxMarks');
    var passInput    = document.getElementById('passMarks');

    function toggleNumeric() {
        var graded = checkbox.checked;
        numericBlock.style.display = graded ? 'none' : '';
        if (maxInput)  maxInput.required  = !graded;
        if (passInput) passInput.required = !graded;
    }

    if (checkbox) {
        checkbox.addEventListener('change', toggleNumeric);
        toggleNumeric(); // run on page load
    }
}());
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
