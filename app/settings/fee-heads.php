<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$editId  = (int)($_GET['edit_id'] ?? 0);
$editFh  = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM fee_heads WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editFh = $es->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Toggle active ────────────────────────────────────────────────────────
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare(
                "UPDATE fee_heads SET is_active = NOT is_active WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Fee head status updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/fee-heads');
        exit;

    // ── Delete ────────────────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->prepare(
                    "DELETE FROM fee_heads WHERE id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                setFlash('success', 'Fee head deleted.');
            } catch (Exception $e) {
                setFlash('error', 'Cannot delete: this fee head has payments recorded against it.');
            }
        }
        header('Location: ' . BASE_URL . '/app/settings/fee-heads');
        exit;

    // ── Add / Edit ────────────────────────────────────────────────────────────
    } elseif ($action === 'add' || $action === 'edit') {
        $name          = trim($_POST['name']           ?? '');
        $description   = trim($_POST['description']    ?? '');
        $frequency     = $_POST['frequency']            ?? 'monthly';
        $defaultAmount = $_POST['default_amount']       ?? '0.00';
        $sortOrder     = (int)($_POST['sort_order']     ?? 0);
        $id            = (int)($_POST['id']             ?? 0);

        $validFreqs = ['monthly', 'quarterly', 'half_yearly', 'annual', 'one_time'];

        if (!$name)                          $error = 'Name is required.';
        elseif (mb_strlen($name) > 100)      $error = 'Name must not exceed 100 characters.';
        elseif (mb_strlen($description) > 300) $error = 'Description must not exceed 300 characters.';
        elseif (!in_array($frequency, $validFreqs, true)) $error = 'Invalid frequency.';
        elseif (!is_numeric($defaultAmount) || (float)$defaultAmount < 0) $error = 'Default amount must be 0 or greater.';
        elseif ($sortOrder < 0 || $sortOrder > 255) $error = 'Sort order must be between 0 and 255.';

        if (!$error) {
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE fee_heads
                         SET name = ?, description = ?, frequency = ?,
                             default_amount = ?, sort_order = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([
                        $name, $description ?: null, $frequency,
                        number_format((float)$defaultAmount, 2, '.', ''),
                        $sortOrder, $id, $instId,
                    ]);
                    setFlash('success', "Fee head '{$name}' updated.");
                } else {
                    $db->prepare(
                        "INSERT INTO fee_heads
                             (institution_id, name, description, frequency, default_amount, sort_order)
                         VALUES (?,?,?,?,?,?)"
                    )->execute([
                        $instId, $name, $description ?: null, $frequency,
                        number_format((float)$defaultAmount, 2, '.', ''),
                        $sortOrder,
                    ]);
                    setFlash('success', "Fee head '{$name}' added.");
                }
            } catch (Exception $e) {
                $error = 'A fee head with that name already exists.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/fee-heads');
                exit;
            }
        }
    }
}

// ── Load list ─────────────────────────────────────────────────────────────────
$fhStmt = $db->prepare(
    "SELECT * FROM fee_heads WHERE institution_id = ? ORDER BY sort_order, name"
);
$fhStmt->execute([$instId]);
$feeHeads = $fhStmt->fetchAll();

// ── Frequency helpers ─────────────────────────────────────────────────────────
$freqLabels = [
    'monthly'     => 'Monthly',
    'quarterly'   => 'Quarterly',
    'half_yearly' => 'Half-Yearly',
    'annual'      => 'Annual',
    'one_time'    => 'One-Time',
];
$freqBadge = [
    'monthly'     => 'bg-primary',
    'quarterly'   => 'bg-info text-dark',
    'half_yearly' => 'bg-warning text-dark',
    'annual'      => 'bg-success',
    'one_time'    => 'bg-secondary',
];

$pageTitle   = 'Fee Heads';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Settings'  => BASE_URL . '/app/settings',
    'Fee Heads' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-tags-fill"></i></div>
  <div>
    <h4>Fee Heads</h4>
    <p>Define fee categories (tuition, transport, etc.) and their default amounts.</p>
  </div>
</div>

<div class="row g-4">

  <!-- ── Add / Edit Form ──────────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editFh ? 'Edit Fee Head' : 'Add Fee Head' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editFh ? 'edit' : 'add' ?>">
          <?php if ($editFh): ?>
          <input type="hidden" name="id" value="<?= (int)$editFh['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="name" maxlength="100"
                   value="<?= h($editFh['name'] ?? $_POST['name'] ?? '') ?>"
                   placeholder="e.g. Tuition Fee" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" maxlength="300"
                      rows="2" placeholder="Optional description"><?= h($editFh['description'] ?? $_POST['description'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Frequency <span class="required">*</span></label>
            <select class="form-select" name="frequency">
              <?php foreach ($freqLabels as $val => $label):
                $sel = ($editFh['frequency'] ?? $_POST['frequency'] ?? 'monthly') === $val ? 'selected' : '';
              ?>
              <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Default Amount (₹)</label>
            <input type="number" class="form-control" name="default_amount"
                   step="0.01" min="0"
                   value="<?= h($editFh['default_amount'] ?? $_POST['default_amount'] ?? '0.00') ?>"
                   placeholder="0.00">
          </div>

          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order"
                   min="0" max="255"
                   value="<?= h((string)($editFh['sort_order'] ?? $_POST['sort_order'] ?? 0)) ?>">
            <div class="form-text">Lower numbers appear first (0–255).</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editFh ? 'Save Changes' : 'Add Fee Head' ?>
            </button>
            <?php if ($editFh): ?>
            <a href="<?= h(BASE_URL . '/app/settings/fee-heads') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Fee Head List ─────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-tags me-2 text-primary"></i>Fee Heads
        <span class="badge bg-secondary ms-1"><?= count($feeHeads) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($feeHeads): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:2.5rem;">#</th>
                <th>Name</th>
                <th>Frequency</th>
                <th>Default Amount</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($feeHeads as $i => $fh): ?>
              <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td>
                  <div class="fw-600 small"><?= h($fh['name']) ?></div>
                  <?php if ($fh['description']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= h($fh['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= $freqBadge[$fh['frequency']] ?? 'bg-secondary' ?>">
                    <?= h($freqLabels[$fh['frequency']] ?? $fh['frequency']) ?>
                  </span>
                </td>
                <td class="fw-600 small">₹<?= number_format((float)$fh['default_amount'], 2) ?></td>
                <td>
                  <?= $fh['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= h(BASE_URL . '/app/settings/fee-heads?edit_id=' . $fh['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon"
                       title="Edit" data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$fh['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $fh['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $fh['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $fh['is_active'] ? 'Deactivate this fee head?' : 'Activate this fee head?' ?>">
                        <i class="bi <?= $fh['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$fh['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete" data-bs-toggle="tooltip"
                              data-confirm="Delete fee head '<?= h($fh['name']) ?>'? This cannot be undone.">
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
          <i class="bi bi-tags"></i>
          <h6>No fee heads yet</h6>
          <p class="small">Add your first fee head to start collecting fees.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
