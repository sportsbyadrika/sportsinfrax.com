<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT status FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || !in_array($inst['status'], ['pending_approval','active'])) {
    setFlash('error', 'Complete your institution profile first.');
    header('Location: ' . BASE_URL . '/app/institution-admin/profile');
    exit;
}

$typeLabels = [
    'holiday' => 'Holiday',
    'working' => 'Working Day',
];
$typeColors = [
    'holiday' => 'danger',
    'working' => 'success',
];
$catLabels = [
    'public_holiday'      => 'Public Holiday',
    'special_day'         => 'Special Day',
    'institution_holiday' => 'Institution Holiday',
    'event'               => 'Event',
];
$catColors = [
    'public_holiday'      => 'danger',
    'special_day'         => 'info',
    'institution_holiday' => 'primary',
    'event'               => 'warning',
];

$filterYear = (int)($_GET['year'] ?? date('Y'));
$editId     = (int)($_GET['edit_id'] ?? 0);
$holiday    = null;

if ($editId) {
    $hStmt = $db->prepare("SELECT * FROM holiday_calendar WHERE id = ? AND institution_id = ?");
    $hStmt->execute([$editId, $instId]);
    $holiday = $hStmt->fetch();
    if (!$holiday) {
        setFlash('error', 'Holiday not found.');
        header('Location: ?year=' . $filterYear);
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $holidayDate = trim($_POST['holiday_date'] ?? '');
        $name        = trim($_POST['name']         ?? '');
        $type        = $_POST['type']              ?? 'holiday';
        $category    = $_POST['category']          ?? 'institution_holiday';
        $description = trim($_POST['description']  ?? '');
        $actId       = (int)($_POST['edit_id']     ?? 0);

        if (!$holidayDate || !strtotime($holidayDate)) {
            $error = 'A valid date is required.';
        } elseif (!$name) {
            $error = 'Holiday / day name is required.';
        } elseif (!array_key_exists($type, $typeLabels)) {
            $error = 'Invalid type selected.';
        } elseif (!array_key_exists($category, $catLabels)) {
            $error = 'Invalid category selected.';
        }

        if (!$error) {
            $year = (int)date('Y', strtotime($holidayDate));
            try {
                if ($action === 'edit' && $actId) {
                    $db->prepare(
                        "UPDATE holiday_calendar
                         SET holiday_date=?, year=?, name=?, type=?, category=?, description=?
                         WHERE id=? AND institution_id=?"
                    )->execute([$holidayDate, $year, $name, $type, $category,
                                $description ?: null, $actId, $instId]);
                    setFlash('success', 'Holiday updated successfully.');
                } else {
                    $db->prepare(
                        "INSERT INTO holiday_calendar
                         (institution_id, holiday_date, year, name, type, category, description, created_by)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute([$instId, $holidayDate, $year, $name, $type, $category,
                                $description ?: null, authId()]);
                    setFlash('success', 'Holiday added successfully.');
                }
                header('Location: ?year=' . $year);
                exit;
            } catch (Exception $e) {
                $error = 'Database error. Please try again.';
            }
        }

    } elseif ($action === 'toggle') {
        $togId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE holiday_calendar SET is_active = 1-is_active WHERE id=? AND institution_id=?")
           ->execute([$togId, $instId]);
        header('Location: ?year=' . $filterYear);
        exit;

    } elseif ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM holiday_calendar WHERE id=? AND institution_id=?")
           ->execute([$delId, $instId]);
        setFlash('success', 'Holiday deleted.');
        header('Location: ?year=' . $filterYear);
        exit;
    }
}

// Available years that have entries, always include current + adjacent
$yrStmt = $db->prepare(
    "SELECT DISTINCT year FROM holiday_calendar WHERE institution_id=? ORDER BY year DESC"
);
$yrStmt->execute([$instId]);
$dbYears = array_column($yrStmt->fetchAll(), 'year');
$curYear = (int)date('Y');
$allYears = array_unique(array_merge([$curYear - 1, $curYear, $curYear + 1], array_map('intval', $dbYears)));
rsort($allYears);

// List for selected year
$listStmt = $db->prepare(
    "SELECT * FROM holiday_calendar
     WHERE institution_id=? AND year=?
     ORDER BY holiday_date ASC, name ASC"
);
$listStmt->execute([$instId, $filterYear]);
$holidays = $listStmt->fetchAll();

$pageTitle   = 'Holidays & Calendar';
$breadcrumbs = [
    'Dashboard' => BASE_URL . '/app/institution-admin/dashboard',
    'Settings'  => BASE_URL . '/app/settings',
    'Holidays & Calendar' => '',
];
require_once APP_ROOT . '/includes/header.php';

$v = fn(string $f, string $d = '') => h($holiday[$f] ?? $_POST[$f] ?? $d);
?>

<div class="row g-4 align-items-start">

  <!-- ── Left: Form ───────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-calendar-plus me-2 text-primary"></i>
        <?= $editId ? 'Edit Holiday' : 'Add Holiday / Important Day' ?>
      </div>
      <div class="card-body">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action"  value="<?= $editId ? 'edit' : 'add' ?>">
          <input type="hidden" name="edit_id" value="<?= $editId ?>">

          <div class="mb-3">
            <label class="form-label">Date <span class="required">*</span></label>
            <input type="date" class="form-control" name="holiday_date"
                   value="<?= $v('holiday_date') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Holiday / Day Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="name"
                   value="<?= $v('name') ?>" placeholder="e.g. Republic Day, Annual Sports Day"
                   maxlength="150" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Type <span class="required">*</span></label>
            <select class="form-select" name="type" required>
              <?php foreach ($typeLabels as $val => $lbl): ?>
              <option value="<?= $val ?>"
                <?= ($v('type', 'holiday') === $val) ? 'selected' : '' ?>>
                <?= $lbl ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Holiday = day off &bull; Working Day = special working day</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Category <span class="required">*</span></label>
            <select class="form-select" name="category" required>
              <?php foreach ($catLabels as $val => $lbl): ?>
              <option value="<?= $val ?>"
                <?= ($v('category', 'institution_holiday') === $val) ? 'selected' : '' ?>>
                <?= $lbl ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description"
                      rows="2" maxlength="300"
                      placeholder="Optional note or reason"><?= $v('description') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-check2 me-1"></i><?= $editId ? 'Save Changes' : 'Add' ?>
            </button>
            <?php if ($editId): ?>
            <a href="?year=<?= $filterYear ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- ── Right: List ──────────────────────────────────── -->
  <div class="col-lg-8">

    <!-- Year tabs -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <?php foreach ($allYears as $yr): ?>
      <a href="?year=<?= $yr ?>"
         class="btn btn-sm <?= $yr === $filterYear ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $yr ?>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-calendar-event me-2 text-primary"></i>
          <?= $filterYear ?> Calendar
          <span class="badge bg-secondary ms-1"><?= count($holidays) ?></span>
        </span>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Name</th>
              <th>Type</th>
              <th>Category</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($holidays): ?>
            <?php foreach ($holidays as $h): ?>
            <tr class="<?= $h['is_active'] ? '' : 'text-muted' ?>">
              <td class="small fw-600 text-nowrap">
                <?= fmtDate($h['holiday_date'], 'd M Y') ?>
                <div class="text-muted" style="font-size:.7rem;">
                  <?= date('l', strtotime($h['holiday_date'])) ?>
                </div>
              </td>
              <td>
                <div class="fw-600 small"><?= h($h['name']) ?></div>
                <?php if ($h['description']): ?>
                <div class="text-muted" style="font-size:.72rem;"><?= h($h['description']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-<?= $typeColors[$h['type']] ?>">
                  <?= $typeLabels[$h['type']] ?>
                </span>
              </td>
              <td>
                <span class="badge bg-<?= $catColors[$h['category']] ?> bg-opacity-10
                      text-<?= $catColors[$h['category']] ?>" style="white-space:normal;">
                  <?= $catLabels[$h['category']] ?>
                </span>
              </td>
              <td>
                <?= $h['is_active']
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Inactive</span>' ?>
              </td>
              <td>
                <div class="d-flex gap-1 flex-nowrap">
                  <a href="?year=<?= $filterYear ?>&edit_id=<?= $h['id'] ?>"
                     class="btn btn-sm btn-outline-primary btn-icon"
                     title="Edit" data-bs-toggle="tooltip">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id"     value="<?= $h['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-icon <?= $h['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                            title="<?= $h['is_active'] ? 'Deactivate' : 'Activate' ?>"
                            data-bs-toggle="tooltip">
                      <i class="bi <?= $h['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                    </button>
                  </form>
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     value="<?= $h['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-outline-danger btn-icon"
                            title="Delete" data-bs-toggle="tooltip"
                            data-confirm="Delete '<?= h(addslashes($h['name'])) ?>'? This cannot be undone.">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
              <td colspan="6">
                <div class="empty-state py-4">
                  <i class="bi bi-calendar-x"></i>
                  <h6>No holidays for <?= $filterYear ?></h6>
                  <p class="small">Add holidays, important days or events using the form on the left.</p>
                </div>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
