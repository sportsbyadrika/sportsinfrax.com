<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT status FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || !in_array($inst['status'], ['pending_approval', 'active'])) {
    setFlash('error', 'Complete your institution profile first.');
    header('Location: ' . BASE_URL . '/app/institution-admin/profile');
    exit;
}

$editId  = (int)($_GET['edit_id'] ?? 0);
$editSes = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editSes = $es->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'init_defaults') {
        $db->prepare(
            "INSERT IGNORE INTO attendance_sessions (institution_id, label, sort_order, is_active)
             VALUES (?, 'Morning', 0, 1), (?, 'Evening', 1, 1)"
        )->execute([$instId, $instId]);
        setFlash('success', 'Default sessions (Morning &amp; Evening) created.');
        header('Location: ' . BASE_URL . '/app/settings/attendance-sessions');
        exit;

    } elseif ($action === 'add' || $action === 'edit') {
        $label     = trim($_POST['label']      ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $id        = (int)($_POST['id']        ?? 0);

        if ($label === '') {
            $error = 'Label is required.';
        } elseif (strlen($label) > 50) {
            $error = 'Label must be 50 characters or fewer.';
        }

        if (!$error) {
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE attendance_sessions SET label = ?, sort_order = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([$label, $sortOrder, $id, $instId]);
                    setFlash('success', 'Session updated.');
                } else {
                    $db->prepare(
                        "INSERT INTO attendance_sessions (institution_id, label, sort_order, is_active)
                         VALUES (?, ?, ?, 1)"
                    )->execute([$instId, $label, $sortOrder]);
                    setFlash('success', "Session '{$label}' added.");
                }
            } catch (Exception $e) {
                $error = 'That label already exists or could not be saved.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/attendance-sessions');
                exit;
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare(
                "UPDATE attendance_sessions SET is_active = NOT is_active
                 WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Session status updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/attendance-sessions');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->prepare(
                    "DELETE FROM attendance_sessions WHERE id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                setFlash('success', 'Session deleted.');
            } catch (Exception $e) {
                $code = $e->getCode();
                if ($code == 23000 || $code == '23000') {
                    setFlash('error', 'Cannot delete: attendance records exist for this session.');
                } else {
                    setFlash('error', 'Could not delete the session.');
                }
            }
        }
        header('Location: ' . BASE_URL . '/app/settings/attendance-sessions');
        exit;
    }
}

$sesStmt = $db->prepare(
    "SELECT * FROM attendance_sessions WHERE institution_id = ? ORDER BY sort_order, label"
);
$sesStmt->execute([$instId]);
$sessions  = $sesStmt->fetchAll();
$noSessions = count($sessions) === 0;

$pageTitle   = 'Attendance Sessions';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Settings'  => BASE_URL . '/app/settings',
    'Attendance Sessions' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-clock-history"></i></div>
  <div>
    <h4>Attendance Sessions</h4>
    <p>Configure session slots (Morning, Evening, custom batches) used for attendance marking.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Add / Edit Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-<?= $editSes ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
        <?= $editSes ? 'Edit Session' : 'Add Session' ?>
      </div>
      <div class="card-body">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($noSessions && !$editSes): ?>
        <!-- No sessions yet — offer quick defaults -->
        <div class="alert alert-info py-2 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          No sessions have been set up yet. You can create the default Morning &amp; Evening sessions in one click.
        </div>
        <form method="POST" class="mb-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="init_defaults">
          <button type="submit" class="btn btn-outline-primary w-100">
            <i class="bi bi-lightning-charge me-1"></i>Create Default Sessions (Morning &amp; Evening)
          </button>
        </form>
        <hr class="my-3">
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editSes ? 'edit' : 'add' ?>">
          <?php if ($editSes): ?>
          <input type="hidden" name="id" value="<?= $editSes['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Label <span class="required">*</span></label>
            <input type="text" class="form-control" name="label" maxlength="50"
                   value="<?= h($editSes['label'] ?? $_POST['label'] ?? '') ?>"
                   placeholder="e.g. Morning, Evening, Afternoon" required>
            <div class="form-text">Unique name for this session (max 50 chars).</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order" min="0" max="255"
                   value="<?= h((string)($editSes['sort_order'] ?? $_POST['sort_order'] ?? '0')) ?>">
            <div class="form-text">Lower numbers appear first.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editSes ? 'Save Changes' : 'Add Session' ?>
            </button>
            <?php if ($editSes): ?>
            <a href="<?= h(BASE_URL . '/app/settings/attendance-sessions') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- Session List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-list-ul me-2 text-primary"></i>Sessions
        <span class="badge bg-secondary ms-1"><?= count($sessions) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($sessions): ?>
        <div class="table-responsive">
          <table class="table mb-0">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Label</th>
                <th style="width:70px">Sort</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $i => $ses): ?>
              <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td class="fw-semibold"><?= h($ses['label']) ?></td>
                <td class="text-muted small"><?= (int)$ses['sort_order'] ?></td>
                <td>
                  <?php if ($ses['is_active']): ?>
                  <span class="badge bg-success">Active</span>
                  <?php else: ?>
                  <span class="badge bg-secondary">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <a href="<?= h(BASE_URL . '/app/settings/attendance-sessions?edit_id=' . $ses['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon"
                       title="Edit" data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $ses['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $ses['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $ses['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $ses['is_active'] ? 'Deactivate session \'' . h($ses['label']) . '\'?' : 'Activate session \'' . h($ses['label']) . '\'?' ?>">
                        <i class="bi <?= $ses['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                      </button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $ses['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete" data-bs-toggle="tooltip"
                              data-confirm="Delete session '<?= h($ses['label']) ?>'? This cannot be undone.">
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
          <i class="bi bi-clock-history"></i>
          <h6>No sessions yet</h6>
          <p class="small">Add a session or use the "Create Default Sessions" shortcut on the left.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
