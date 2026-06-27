<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Timetable periods are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/settings');
    exit;
}

$editId     = (int)($_GET['edit_id'] ?? 0);
$editPeriod = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM timetable_periods WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editPeriod = $es->fetch();
}

$error = '';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Toggle active status
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare(
                "UPDATE timetable_periods SET is_active = NOT is_active WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Period status updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/timetable-periods');
        exit;
    }

    // Delete period
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Check if period is used in any timetable entry
            $usedStmt = $db->prepare(
                "SELECT COUNT(*) FROM timetable_entries WHERE period_id = ? AND institution_id = ?"
            );
            $usedStmt->execute([$id, $instId]);
            if ((int)$usedStmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete this period — it is used in one or more timetable entries. Remove those entries first.');
            } else {
                $db->prepare(
                    "DELETE FROM timetable_periods WHERE id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                setFlash('success', 'Period deleted.');
            }
        }
        header('Location: ' . BASE_URL . '/app/settings/timetable-periods');
        exit;
    }

    // Add / Edit
    if ($action === 'add' || $action === 'edit') {
        $label      = trim($_POST['label']       ?? '');
        $startTime  = trim($_POST['start_time']  ?? '');
        $endTime    = trim($_POST['end_time']    ?? '');
        $isBreak    = isset($_POST['is_break']) ? 1 : 0;
        $sortOrder  = max(0, min(255, (int)($_POST['sort_order'] ?? 0)));
        $id         = (int)($_POST['id']         ?? 0);

        if (!$label)              $error = 'Label is required.';
        elseif (mb_strlen($label) > 50) $error = 'Label must be 50 characters or fewer.';
        elseif ($startTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime))
                                  $error = 'Start time format is invalid.';
        elseif ($endTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime))
                                  $error = 'End time format is invalid.';
        elseif ($startTime && $endTime && $endTime <= $startTime)
                                  $error = 'End time must be after start time.';

        if (!$error) {
            $startTimeVal = $startTime !== '' ? $startTime : null;
            $endTimeVal   = $endTime   !== '' ? $endTime   : null;
            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE timetable_periods
                         SET label = ?, start_time = ?, end_time = ?,
                             is_break = ?, sort_order = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([$label, $startTimeVal, $endTimeVal, $isBreak, $sortOrder, $id, $instId]);
                    setFlash('success', 'Period updated.');
                } else {
                    $db->prepare(
                        "INSERT INTO timetable_periods
                           (institution_id, label, start_time, end_time, is_break, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([$instId, $label, $startTimeVal, $endTimeVal, $isBreak, $sortOrder]);
                    setFlash('success', "Period '{$label}' added.");
                }
            } catch (Exception $e) {
                $error = 'A period with that label already exists or could not be saved.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/timetable-periods');
                exit;
            }
        }
    }
}

// ── Fetch period list ─────────────────────────────────────────────────────────
$periodStmt = $db->prepare(
    "SELECT * FROM timetable_periods WHERE institution_id = ? ORDER BY sort_order, label"
);
$periodStmt->execute([$instId]);
$periodList = $periodStmt->fetchAll();

// Helper: format time for display (HH:MM)
function fmtTime(?string $t): string {
    if (!$t) return '';
    return substr($t, 0, 5);
}

$pageTitle   = 'Timetable Periods';
$breadcrumbs = [
    'Dashboard'         => dashboardUrl(),
    'Settings'          => BASE_URL . '/app/settings',
    'Timetable Periods' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-clock-fill"></i></div>
  <div>
    <h4>Timetable Periods</h4>
    <p>Define the daily time slots used to build section timetables. Include breaks like lunch and recess.</p>
  </div>
</div>

<div class="row g-4">

  <!-- ── Add / Edit Form ─────────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editPeriod ? 'Edit Period' : 'Add Period' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editPeriod ? 'edit' : 'add' ?>">
          <?php if ($editPeriod): ?>
          <input type="hidden" name="id" value="<?= (int)$editPeriod['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Label <span class="required">*</span></label>
            <input type="text" class="form-control" name="label"
                   value="<?= h($editPeriod['label'] ?? $_POST['label'] ?? '') ?>"
                   placeholder="e.g. Period 1, Lunch, PT"
                   maxlength="50" required>
            <div class="form-text">Max 50 characters. Must be unique per institution.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Start Time</label>
            <input type="time" class="form-control" name="start_time"
                   value="<?= h(fmtTime($editPeriod['start_time'] ?? null) ?: ($_POST['start_time'] ?? '')) ?>">
            <div class="form-text">Optional. Leave blank if not applicable.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">End Time</label>
            <input type="time" class="form-control" name="end_time"
                   value="<?= h(fmtTime($editPeriod['end_time'] ?? null) ?: ($_POST['end_time'] ?? '')) ?>">
          </div>

          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_break" id="isBreakCheck"
                     value="1"
                     <?= ($editPeriod ? (int)$editPeriod['is_break'] : (int)($_POST['is_break'] ?? 0)) ? 'checked' : '' ?>>
              <label class="form-check-label" for="isBreakCheck">
                Non-teaching slot (Lunch, Recess, PT)
              </label>
            </div>
            <div class="form-text">Break slots are shown differently in the timetable grid.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order"
                   value="<?= h((string)(int)($editPeriod['sort_order'] ?? $_POST['sort_order'] ?? 0)) ?>"
                   min="0" max="255">
            <div class="form-text">Lower values appear first (0–255). Use 10, 20, 30 … to leave gaps.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editPeriod ? 'Save Changes' : 'Add Period' ?>
            </button>
            <?php if ($editPeriod): ?>
            <a href="<?= h(BASE_URL . '/app/settings/timetable-periods') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Period List ─────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-list-ol me-2 text-primary"></i>Periods
        <span class="badge bg-secondary ms-1"><?= count($periodList) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($periodList): ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:3rem">#</th>
                <th>Label</th>
                <th>Start–End Time</th>
                <th>Type</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($periodList as $i => $p): ?>
              <tr>
                <td class="text-muted small"><?= (int)$p['sort_order'] ?></td>
                <td class="fw-600"><?= h($p['label']) ?></td>
                <td class="small text-muted">
                  <?php
                    $st = fmtTime($p['start_time']);
                    $et = fmtTime($p['end_time']);
                    if ($st || $et) {
                        echo h(($st ?: '?') . ' – ' . ($et ?: '?'));
                    } else {
                        echo '<span class="text-muted">—</span>';
                    }
                  ?>
                </td>
                <td>
                  <?php if ((int)$p['is_break']): ?>
                  <span class="badge bg-secondary">Break</span>
                  <?php else: ?>
                  <span class="badge bg-primary">Teaching</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= (int)$p['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <!-- Edit -->
                    <a href="<?= h(BASE_URL . '/app/settings/timetable-periods?edit_id=' . (int)$p['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                       data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <!-- Toggle active -->
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= (int)$p['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= (int)$p['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= (int)$p['is_active'] ? 'Deactivate this period?' : 'Activate this period?' ?>">
                        <i class="bi <?= (int)$p['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <!-- Delete -->
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete"
                              data-bs-toggle="tooltip"
                              data-confirm="Delete period '<?= h($p['label']) ?>'? This cannot be undone.">
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
        <div class="empty-state py-5">
          <i class="bi bi-clock"></i>
          <h6>No periods configured</h6>
          <p class="small">Add at least one period to build the timetable.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
