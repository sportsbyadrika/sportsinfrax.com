<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Academic years are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/settings');
    exit;
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editAy = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM academic_years WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editAy = $es->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'activate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->beginTransaction();
            $db->prepare("UPDATE academic_years SET is_active = 0 WHERE institution_id = ?")
               ->execute([$instId]);
            $db->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ? AND institution_id = ?")
               ->execute([$id, $instId]);
            $db->commit();
            setFlash('success', 'Active academic year updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/academic-years');
        exit;

    } elseif ($action === 'add' || $action === 'edit') {
        $label     = trim($_POST['label']      ?? '');
        $startDate = $_POST['start_date']      ?? '';
        $endDate   = $_POST['end_date']        ?? '';
        $id        = (int)($_POST['id']        ?? 0);

        if (!$label)     $error = 'Label is required.';
        elseif (!$startDate) $error = 'Start date is required.';
        elseif (!$endDate)   $error = 'End date is required.';
        elseif ($endDate <= $startDate) $error = 'End date must be after start date.';

        if (!$error) {
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE academic_years SET label = ?, start_date = ?, end_date = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([$label, $startDate, $endDate, $id, $instId]);
                    setFlash('success', 'Academic year updated.');
                } else {
                    $db->prepare(
                        "INSERT INTO academic_years (institution_id, label, start_date, end_date, created_by)
                         VALUES (?,?,?,?,?)"
                    )->execute([$instId, $label, $startDate, $endDate, authId()]);
                    setFlash('success', "Academic year '{$label}' added.");
                }
            } catch (Exception $e) {
                $error = 'That label already exists or could not be saved.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/academic-years');
                exit;
            }
        }
    }
}

$years = $db->prepare(
    "SELECT * FROM academic_years WHERE institution_id = ? ORDER BY start_date DESC"
);
$years->execute([$instId]);
$yearList = $years->fetchAll();

$pageTitle   = 'Academic Years';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Settings' => BASE_URL . '/app/settings', 'Academic Years' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-calendar2-range-fill"></i></div>
  <div>
    <h4>Academic Years</h4>
    <p>Manage academic sessions. Only one year can be active at a time.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Add / Edit Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editAy ? 'Edit Academic Year' : 'Add Academic Year' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editAy ? 'edit' : 'add' ?>">
          <?php if ($editAy): ?>
          <input type="hidden" name="id" value="<?= $editAy['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Label <span class="required">*</span></label>
            <input type="text" class="form-control" name="label"
                   value="<?= h($editAy['label'] ?? $_POST['label'] ?? '') ?>"
                   placeholder="e.g. 2025-26" required>
            <div class="form-text">Short identifier used across the system.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Start Date <span class="required">*</span></label>
            <input type="date" class="form-control" name="start_date"
                   value="<?= h($editAy['start_date'] ?? $_POST['start_date'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">End Date <span class="required">*</span></label>
            <input type="date" class="form-control" name="end_date"
                   value="<?= h($editAy['end_date'] ?? $_POST['end_date'] ?? '') ?>" required>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editAy ? 'Save Changes' : 'Add Year' ?>
            </button>
            <?php if ($editAy): ?>
            <a href="<?= h(BASE_URL . '/app/settings/academic-years') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Year List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-list-ul me-2 text-primary"></i>Academic Years
        <span class="badge bg-secondary ms-1"><?= count($yearList) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($yearList): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Label</th>
                <th>Period</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($yearList as $ay): ?>
              <tr>
                <td class="fw-600"><?= h($ay['label']) ?></td>
                <td class="small text-muted">
                  <?= fmtDate($ay['start_date'], 'd M Y') ?> – <?= fmtDate($ay['end_date'], 'd M Y') ?>
                </td>
                <td>
                  <?php if ($ay['is_active']): ?>
                  <span class="badge bg-success">Active</span>
                  <?php else: ?>
                  <span class="badge bg-secondary">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <?php if (!$ay['is_active']): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="id" value="<?= $ay['id'] ?>">
                      <button type="submit" class="btn btn-xs btn-outline-success"
                              style="font-size:.75rem;padding:2px 10px;"
                              data-confirm="Set '<?= h($ay['label']) ?>' as the active year?">
                        Set Active
                      </button>
                    </form>
                    <?php endif; ?>
                    <a href="<?= h(BASE_URL . '/app/settings/academic-years?edit_id=' . $ay['id']) ?>"
                       class="btn btn-xs btn-outline-primary btn-icon" title="Edit"
                       style="font-size:.75rem;padding:2px 8px;">
                      <i class="bi bi-pencil"></i>
                    </a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-calendar2-range"></i>
          <h6>No academic years yet</h6>
          <p class="small">Add your first academic year to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
