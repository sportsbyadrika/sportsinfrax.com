<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Classes are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/settings');
    exit;
}

$editId  = (int)($_GET['edit_id'] ?? 0);
$editCls = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM classes WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editCls = $es->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE classes SET is_active = NOT is_active WHERE id = ? AND institution_id = ?")
           ->execute([$id, $instId]);
        setFlash('success', 'Class status updated.');
        header('Location: ' . BASE_URL . '/app/settings/classes');
        exit;

    } elseif ($action === 'add' || $action === 'edit') {
        $name         = trim($_POST['name']          ?? '');
        $numericOrder = (int)($_POST['numeric_order'] ?? 0);
        $id           = (int)($_POST['id']            ?? 0);

        if (!$name) $error = 'Class name is required.';

        if (!$error) {
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE classes SET name = ?, numeric_order = ? WHERE id = ? AND institution_id = ?"
                    )->execute([$name, $numericOrder, $id, $instId]);
                    setFlash('success', 'Class updated.');
                } else {
                    $db->prepare(
                        "INSERT INTO classes (institution_id, name, numeric_order) VALUES (?,?,?)"
                    )->execute([$instId, $name, $numericOrder]);
                    setFlash('success', "Class '{$name}' added.");
                }
            } catch (Exception $e) {
                $error = 'A class with that name already exists.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/classes');
                exit;
            }
        }
    }
}

$clsStmt = $db->prepare(
    "SELECT * FROM classes WHERE institution_id = ? ORDER BY numeric_order, name"
);
$clsStmt->execute([$instId]);
$classList = $clsStmt->fetchAll();

$pageTitle   = 'Classes';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Settings' => BASE_URL . '/app/settings', 'Classes' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-collection-fill"></i></div>
  <div>
    <h4>Classes</h4>
    <p>Define class or grade names used across sections. Set a sort order for correct display.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Add / Edit Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editCls ? 'Edit Class' : 'Add Class' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editCls ? 'edit' : 'add' ?>">
          <?php if ($editCls): ?>
          <input type="hidden" name="id" value="<?= $editCls['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Class Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="name"
                   value="<?= h($editCls['name'] ?? $_POST['name'] ?? '') ?>"
                   placeholder="e.g. Grade 1, Class 8, LKG" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="numeric_order"
                   value="<?= h((string)($editCls['numeric_order'] ?? $_POST['numeric_order'] ?? 0)) ?>"
                   min="0" max="99">
            <div class="form-text">Lower numbers appear first. Use 0, 1, 2 … or 10, 20, 30 …</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editCls ? 'Save Changes' : 'Add Class' ?>
            </button>
            <?php if ($editCls): ?>
            <a href="<?= h(BASE_URL . '/app/settings/classes') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Class List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-list-ol me-2 text-primary"></i>Class List
        <span class="badge bg-secondary ms-1"><?= count($classList) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($classList): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr><th>Order</th><th>Class Name</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($classList as $cls): ?>
              <tr>
                <td class="text-muted small"><?= (int)$cls['numeric_order'] ?></td>
                <td class="fw-600"><?= h($cls['name']) ?></td>
                <td>
                  <?= $cls['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= h(BASE_URL . '/app/settings/classes?edit_id=' . $cls['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                       data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $cls['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $cls['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $cls['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $cls['is_active'] ? 'Deactivate this class?' : 'Activate this class?' ?>">
                        <i class="bi <?= $cls['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
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
          <i class="bi bi-collection"></i>
          <h6>No classes yet</h6>
          <p class="small">Add your first class to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
