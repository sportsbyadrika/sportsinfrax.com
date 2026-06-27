<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Subjects are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/settings');
    exit;
}

// Load classes for form and display
$clsStmt = $db->prepare(
    "SELECT * FROM classes WHERE institution_id = ? AND is_active = 1 ORDER BY numeric_order, name"
);
$clsStmt->execute([$instId]);
$classes = $clsStmt->fetchAll();
$classMap = [];
foreach ($classes as $cls) {
    $classMap[$cls['id']] = $cls['name'];
}

// Edit mode
$editId   = (int)($_GET['edit_id'] ?? 0);
$editSubj = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM subjects WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editSubj = $es->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare(
            "UPDATE subjects SET is_active = NOT is_active WHERE id = ? AND institution_id = ?"
        )->execute([$id, $instId]);
        setFlash('success', 'Subject status updated.');
        header('Location: ' . BASE_URL . '/app/settings/subjects');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare(
                "DELETE FROM subjects WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Subject deleted.');
        } catch (Exception $e) {
            setFlash('error', 'Cannot delete: subject is in use.');
        }
        header('Location: ' . BASE_URL . '/app/settings/subjects');
        exit;

    } elseif ($action === 'add' || $action === 'edit') {
        $name      = trim($_POST['name']       ?? '');
        $code      = trim($_POST['code']       ?? '') ?: null;
        $classId   = (int)($_POST['class_id']  ?? 0) ?: null;
        $sortOrder = min(255, max(0, (int)($_POST['sort_order'] ?? 0)));
        $id        = (int)($_POST['id']        ?? 0);

        if (!$name)                    $error = 'Subject name is required.';
        elseif (mb_strlen($name) > 100) $error = 'Subject name must be 100 characters or fewer.';
        elseif ($code && mb_strlen($code) > 20) $error = 'Code must be 20 characters or fewer.';
        elseif ($classId && !isset($classMap[$classId])) $error = 'Invalid class selected.';

        if (!$error) {
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE subjects
                         SET name = ?, code = ?, class_id = ?, sort_order = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([$name, $code, $classId, $sortOrder, $id, $instId]);
                    setFlash('success', 'Subject updated.');
                } else {
                    $db->prepare(
                        "INSERT INTO subjects (institution_id, name, code, class_id, sort_order)
                         VALUES (?,?,?,?,?)"
                    )->execute([$instId, $name, $code, $classId, $sortOrder]);
                    setFlash('success', "Subject '{$name}' added.");
                }
            } catch (Exception $e) {
                $error = 'A subject with that name already exists for the selected class.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/subjects');
                exit;
            }
        }
    }
}

// Load subjects: NULL class_id first, then by class numeric_order, then sort_order, name
$subjStmt = $db->prepare(
    "SELECT s.*, c.name AS class_name, c.numeric_order AS class_order
     FROM subjects s
     LEFT JOIN classes c ON c.id = s.class_id
     WHERE s.institution_id = ?
     ORDER BY (s.class_id IS NOT NULL), c.numeric_order, c.name, s.sort_order, s.name"
);
$subjStmt->execute([$instId]);
$subjects = $subjStmt->fetchAll();

$pageTitle   = 'Subjects';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Settings' => BASE_URL . '/app/settings', 'Subjects' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
  <div>
    <h4>Subjects</h4>
    <p>Define the subject master list. Subjects can apply to all classes or be scoped to a specific class.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Add / Edit Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editSubj ? 'Edit Subject' : 'Add Subject' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editSubj ? 'edit' : 'add' ?>">
          <?php if ($editSubj): ?>
          <input type="hidden" name="id" value="<?= $editSubj['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Subject Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="name"
                   value="<?= h($editSubj['name'] ?? $_POST['name'] ?? '') ?>"
                   placeholder="e.g. Mathematics, Science"
                   maxlength="100" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Code</label>
            <input type="text" class="form-control" name="code"
                   value="<?= h($editSubj['code'] ?? $_POST['code'] ?? '') ?>"
                   placeholder="e.g. MTH, SCI"
                   maxlength="20">
            <div class="form-text">Optional short code for reports.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Applicable Class</label>
            <select class="form-select" name="class_id">
              <option value="">All Classes</option>
              <?php foreach ($classes as $cls): ?>
              <?php
                $selClassId = (int)($editSubj['class_id'] ?? $_POST['class_id'] ?? 0);
              ?>
              <option value="<?= $cls['id'] ?>"
                <?= $selClassId === (int)$cls['id'] ? 'selected' : '' ?>>
                <?= h($cls['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Leave as "All Classes" to make subject available to every class.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order"
                   value="<?= h((string)($editSubj['sort_order'] ?? $_POST['sort_order'] ?? 0)) ?>"
                   min="0" max="255">
            <div class="form-text">Lower numbers appear first (0–255).</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editSubj ? 'Save Changes' : 'Add Subject' ?>
            </button>
            <?php if ($editSubj): ?>
            <a href="<?= h(BASE_URL . '/app/settings/subjects') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Subject List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-journal-bookmark me-2 text-primary"></i>Subject List
        <span class="badge bg-secondary ms-1"><?= count($subjects) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($subjects):
          // Group for display
          $groups = [];
          foreach ($subjects as $subj) {
              $key = $subj['class_id'] ? ('cls_' . $subj['class_id']) : 'all';
              $groups[$key][] = $subj;
          }
        ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width:2.5rem">#</th>
                <th>Subject Name</th>
                <th>Code</th>
                <th>Class</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $rowNum = 0;
                $lastGroup = null;
                foreach ($subjects as $subj):
                    $rowNum++;
                    $groupKey = $subj['class_id'] ? ('cls_' . $subj['class_id']) : 'all';
                    if ($groupKey !== $lastGroup):
                        $lastGroup = $groupKey;
                        $groupLabel = $subj['class_id']
                            ? h($subj['class_name'])
                            : 'All Classes';
              ?>
              <tr class="table-light">
                <td colspan="6" class="small fw-semibold text-muted py-1 ps-3">
                  <i class="bi <?= $subj['class_id'] ? 'bi-collection' : 'bi-globe' ?> me-1"></i>
                  <?= $groupLabel ?>
                </td>
              </tr>
              <?php endif; ?>
              <tr>
                <td class="text-muted small"><?= $rowNum ?></td>
                <td class="fw-600"><?= h($subj['name']) ?></td>
                <td class="small text-muted">
                  <?= $subj['code'] ? h($subj['code']) : '<span class="text-muted">—</span>' ?>
                </td>
                <td>
                  <?php if ($subj['class_id']): ?>
                    <span class="badge bg-info bg-opacity-15 text-info border border-info-subtle">
                      <?= h($subj['class_name']) ?>
                    </span>
                  <?php else: ?>
                    <span class="badge bg-secondary bg-opacity-15 text-secondary">All Classes</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $subj['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= h(BASE_URL . '/app/settings/subjects?edit_id=' . $subj['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                       data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $subj['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $subj['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $subj['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $subj['is_active'] ? 'Deactivate this subject?' : 'Activate this subject?' ?>">
                        <i class="bi <?= $subj['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $subj['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete"
                              data-bs-toggle="tooltip"
                              data-confirm="Delete subject '<?= h(addslashes($subj['name'])) ?>'? This cannot be undone.">
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
          <i class="bi bi-journal-bookmark"></i>
          <h6>No subjects yet</h6>
          <p class="small">Add your first subject to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
