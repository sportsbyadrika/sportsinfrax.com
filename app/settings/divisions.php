<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Divisions are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/settings');
    exit;
}

$editId  = (int)($_GET['edit_id'] ?? 0);
$editDiv = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM divisions WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editDiv = $es->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE divisions SET is_active = NOT is_active WHERE id = ? AND institution_id = ?")
           ->execute([$id, $instId]);
        setFlash('success', 'Division status updated.');
        header('Location: ' . BASE_URL . '/app/settings/divisions');
        exit;

    } elseif ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);

        if (!$name) $error = 'Division name is required.';
        elseif (mb_strlen($name) > 20) $error = 'Division name must be 20 characters or fewer.';

        if (!$error) {
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare("UPDATE divisions SET name = ? WHERE id = ? AND institution_id = ?")
                       ->execute([$name, $id, $instId]);
                    setFlash('success', 'Division updated.');
                } else {
                    $db->prepare("INSERT INTO divisions (institution_id, name) VALUES (?,?)")
                       ->execute([$instId, $name]);
                    setFlash('success', "Division '{$name}' added.");
                }
            } catch (Exception $e) {
                $error = 'A division with that name already exists.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/divisions');
                exit;
            }
        }
    }
}

$divStmt = $db->prepare("SELECT * FROM divisions WHERE institution_id = ? ORDER BY name");
$divStmt->execute([$instId]);
$divList = $divStmt->fetchAll();

$pageTitle   = 'Divisions';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Settings' => BASE_URL . '/app/settings', 'Divisions' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-grid-3x2-gap-fill"></i></div>
  <div>
    <h4>Divisions</h4>
    <p>Define division labels (A, B, C, Rose, Lily …) assigned to class sections.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Add / Edit Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editDiv ? 'Edit Division' : 'Add Division' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editDiv ? 'edit' : 'add' ?>">
          <?php if ($editDiv): ?>
          <input type="hidden" name="id" value="<?= $editDiv['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Division Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="name"
                   value="<?= h($editDiv['name'] ?? $_POST['name'] ?? '') ?>"
                   placeholder="e.g. A, B, C or Rose, Lily"
                   maxlength="20" required>
            <div class="form-text">Max 20 characters.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editDiv ? 'Save Changes' : 'Add Division' ?>
            </button>
            <?php if ($editDiv): ?>
            <a href="<?= h(BASE_URL . '/app/settings/divisions') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-body small text-muted">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        Divisions are shared across all classes and academic years. Adding "A" here means every class
        can have an "A" section.
      </div>
    </div>
  </div>

  <!-- Division List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-grid me-2 text-primary"></i>Division List
        <span class="badge bg-secondary ms-1"><?= count($divList) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($divList): ?>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
          <table class="table table-sm">
            <thead class="sticky-top table-light">
              <tr><th>Division</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($divList as $div): ?>
              <tr>
                <td class="fw-600"><?= h($div['name']) ?></td>
                <td>
                  <?= $div['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= h(BASE_URL . '/app/settings/divisions?edit_id=' . $div['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                       data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $div['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $div['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $div['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $div['is_active'] ? 'Deactivate this division?' : 'Activate this division?' ?>">
                        <i class="bi <?= $div['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
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
          <i class="bi bi-grid-3x2-gap"></i>
          <h6>No divisions yet</h6>
          <p class="small">Add your first division (A, B, C, etc.) to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
