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

// --- Date handling ---
$rawDate     = $_GET['date'] ?? date('Y-m-d');
$attendDate  = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) && strtotime($rawDate) !== false)
               ? $rawDate
               : date('Y-m-d');
$prevDate    = date('Y-m-d', strtotime($attendDate . ' -1 day'));
$nextDate    = date('Y-m-d', strtotime($attendDate . ' +1 day'));
$isToday     = ($attendDate === date('Y-m-d'));

// --- Handle POST: save attendance ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $attData   = $_POST['att'] ?? [];
    $cntPresent = 0;
    $cntAbsent  = 0;
    $cntLeave   = 0;
    $cntHalf    = 0;

    $upsertSql = "
        INSERT INTO staff_attendance
            (institution_id, staff_id, attendance_date, from_time, to_time, status, leave_type, remarks, marked_by)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            from_time   = VALUES(from_time),
            to_time     = VALUES(to_time),
            status      = VALUES(status),
            leave_type  = VALUES(leave_type),
            remarks     = VALUES(remarks),
            marked_by   = VALUES(marked_by),
            updated_at  = CURRENT_TIMESTAMP
    ";
    $stmt = $db->prepare($upsertSql);

    foreach ($attData as $staffId => $row) {
        $staffId  = (int)$staffId;
        if (!$staffId) continue;

        $status    = in_array($row['status'] ?? '', ['present','absent','half_day','leave'])
                     ? $row['status'] : 'absent';
        $leaveType = null;
        $fromTime  = null;
        $toTime    = null;

        if ($status === 'leave') {
            $leaveType = in_array($row['leave_type'] ?? '', ['sick','casual','earned','unpaid','other'])
                         ? $row['leave_type'] : null;
            $cntLeave++;
        } elseif ($status === 'present') {
            $fromTime = !empty($row['from_time']) ? $row['from_time'] : null;
            $toTime   = !empty($row['to_time'])   ? $row['to_time']   : null;
            $cntPresent++;
        } elseif ($status === 'half_day') {
            $fromTime = !empty($row['from_time']) ? $row['from_time'] : null;
            $toTime   = !empty($row['to_time'])   ? $row['to_time']   : null;
            $cntHalf++;
        } else {
            $cntAbsent++;
        }

        $remarks = trim($row['remarks'] ?? '');
        if (strlen($remarks) > 300) $remarks = substr($remarks, 0, 300);

        $stmt->execute([
            $instId,
            $staffId,
            $attendDate,
            $fromTime,
            $toTime,
            $status,
            $leaveType,
            $remarks ?: null,
            authId(),
        ]);
    }

    $parts = [];
    if ($cntPresent) $parts[] = "{$cntPresent} present";
    if ($cntHalf)    $parts[] = "{$cntHalf} half-day";
    if ($cntLeave)   $parts[] = "{$cntLeave} on leave";
    if ($cntAbsent)  $parts[] = "{$cntAbsent} absent";
    $summary = $parts ? implode(', ', $parts) : 'No records';

    setFlash('success', "Attendance saved for " . fmtDate($attendDate) . ". " . ucfirst($summary) . ".");
    header('Location: ' . BASE_URL . '/app/services/staff-attendance?date=' . urlencode($attendDate));
    exit;
}

// --- Fetch active staff ---
$staffStmt = $db->prepare(
    "SELECT s.id, s.user_id, u.full_name, u.email, u.mobile,
            s.passport_photo, s.staff_type, s.department
     FROM staff s
     JOIN users u ON u.id = s.user_id
     WHERE s.institution_id = ? AND s.is_active = 1
     ORDER BY u.full_name"
);
$staffStmt->execute([$instId]);
$staffList = $staffStmt->fetchAll();

// --- Fetch existing attendance for the date, indexed by staff_id ---
$existingStmt = $db->prepare(
    "SELECT * FROM staff_attendance WHERE institution_id = ? AND attendance_date = ?"
);
$existingStmt->execute([$instId, $attendDate]);
$existingRaw = $existingStmt->fetchAll();
$existing    = [];
foreach ($existingRaw as $row) {
    $existing[$row['staff_id']] = $row;
}

$pageTitle   = 'Staff Attendance';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Services'  => BASE_URL . '/app/services',
    'Staff Attendance' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-person-check-fill"></i></div>
  <div>
    <h4>Staff Attendance</h4>
    <p>Mark daily attendance for all active staff members.</p>
  </div>
</div>

<!-- Date navigation -->
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= h(BASE_URL . '/app/services/staff-attendance?date=' . $prevDate) ?>"
     class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" title="Previous Day">
    <i class="bi bi-chevron-left"></i>
  </a>
  <form method="GET" class="d-flex align-items-center gap-2">
    <input type="date" class="form-control form-control-sm" name="date"
           value="<?= h($attendDate) ?>" style="width:160px;"
           onchange="this.form.submit()">
  </form>
  <a href="<?= h(BASE_URL . '/app/services/staff-attendance?date=' . $nextDate) ?>"
     class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" title="Next Day">
    <i class="bi bi-chevron-right"></i>
  </a>
  <?php if (!$isToday): ?>
  <a href="<?= h(BASE_URL . '/app/services/staff-attendance') ?>"
     class="btn btn-outline-primary btn-sm ms-1">
    <i class="bi bi-calendar-check me-1"></i>Today
  </a>
  <?php endif; ?>
</div>

<?php if (!$staffList): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2"></i>
  No active staff found. Please add and activate staff members first.
</div>
<?php else: ?>

<form method="POST">
  <?= csrfField() ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>
        <i class="bi bi-person-check me-2 text-primary"></i>
        Staff Attendance &mdash; <?= h(fmtDate($attendDate)) ?>
        <span class="badge bg-secondary ms-1"><?= count($staffList) ?> staff</span>
      </span>
      <div class="d-flex gap-2 align-items-center">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="markAllPresent">
          <i class="bi bi-check-all me-1"></i>Quick Mark All Present
        </button>
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-save me-1"></i>Save Attendance
        </button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th style="width:44px"></th>
            <th>Staff Name</th>
            <th style="width:160px">Status</th>
            <th class="time-col" style="width:280px">From / To Time</th>
            <th class="leave-col" style="width:160px">Leave Type</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffList as $s):
            $sid   = $s['id'];
            $rec   = $existing[$sid] ?? null;
            $status    = $rec ? $rec['status']     : 'absent';
            $fromTime  = $rec ? ($rec['from_time']  ?? '09:00') : '09:00';
            $toTime    = $rec ? ($rec['to_time']    ?? '17:00') : '17:00';
            $leaveType = $rec ? ($rec['leave_type'] ?? '')      : '';
            $remarks   = $rec ? ($rec['remarks']    ?? '')      : '';
            // Normalise null times
            if (!$fromTime) $fromTime = '09:00';
            if (!$toTime)   $toTime   = '17:00';

            $initial = mb_strtoupper(mb_substr($s['full_name'], 0, 1));
          ?>
          <tr>
            <!-- Photo -->
            <td>
              <?php if (!empty($s['passport_photo'])): ?>
              <img src="<?= h(PHOTO_URL . '/' . $s['passport_photo']) ?>"
                   alt="<?= h($s['full_name']) ?>"
                   style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
              <?php else: ?>
              <div class="avatar-circle"
                   style="width:32px;height:32px;font-size:.75rem;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                <?= h($initial) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Name + meta -->
            <td>
              <div class="fw-semibold small"><?= h($s['full_name']) ?></div>
              <div class="text-muted" style="font-size:.72rem;">
                <?= h($s['email'] ?? '') ?>
                <?php if (!empty($s['staff_type'])): ?>
                <span class="badge bg-primary bg-opacity-10 text-primary ms-1"><?= h($s['staff_type']) ?></span>
                <?php endif; ?>
                <?php if (!empty($s['department'])): ?>
                <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?= h($s['department']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Status select -->
            <td>
              <select name="att[<?= $sid ?>][status]"
                      class="form-select form-select-sm status-sel att-status-<?= $sid ?>"
                      data-sid="<?= $sid ?>"
                      onchange="updateRow(<?= $sid ?>)">
                <option value="present"  class="text-success"  <?= $status === 'present'  ? 'selected' : '' ?>>Present</option>
                <option value="absent"   class="text-danger"   <?= $status === 'absent'   ? 'selected' : '' ?>>Absent</option>
                <option value="half_day" class="text-warning"  <?= $status === 'half_day' ? 'selected' : '' ?>>Half Day</option>
                <option value="leave"    class="text-primary"  <?= $status === 'leave'    ? 'selected' : '' ?>>Leave</option>
              </select>
            </td>

            <!-- From / To time (hidden when leave) -->
            <td>
              <div id="times_<?= $sid ?>" class="d-flex gap-1 align-items-center">
                <input type="time" class="form-control form-control-sm"
                       name="att[<?= $sid ?>][from_time]"
                       value="<?= h($fromTime) ?>" style="width:110px;">
                <span class="text-muted small">–</span>
                <input type="time" class="form-control form-control-sm"
                       name="att[<?= $sid ?>][to_time]"
                       value="<?= h($toTime) ?>" style="width:110px;">
              </div>
            </td>

            <!-- Leave type (shown when leave) -->
            <td>
              <div id="leave_<?= $sid ?>" class="d-none">
                <select name="att[<?= $sid ?>][leave_type]" class="form-select form-select-sm">
                  <option value="">-- Select --</option>
                  <option value="sick"     <?= $leaveType === 'sick'     ? 'selected' : '' ?>>Sick</option>
                  <option value="casual"   <?= $leaveType === 'casual'   ? 'selected' : '' ?>>Casual</option>
                  <option value="earned"   <?= $leaveType === 'earned'   ? 'selected' : '' ?>>Earned</option>
                  <option value="unpaid"   <?= $leaveType === 'unpaid'   ? 'selected' : '' ?>>Unpaid</option>
                  <option value="other"    <?= $leaveType === 'other'    ? 'selected' : '' ?>>Other</option>
                </select>
              </div>
            </td>

            <!-- Remarks -->
            <td>
              <input type="text" class="form-control form-control-sm"
                     name="att[<?= $sid ?>][remarks]"
                     value="<?= h($remarks) ?>"
                     placeholder="Optional remarks" maxlength="300">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="markAllPresentFooter">
        <i class="bi bi-check-all me-1"></i>Quick Mark All Present
      </button>
      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-save me-1"></i>Save Attendance
      </button>
    </div>
  </div>
</form>

<?php endif; ?>

<script>
function updateRow(sid) {
  const sel = document.querySelector(`select[name="att[${sid}][status]"]`);
  const timesEl = document.getElementById(`times_${sid}`);
  const leaveEl = document.getElementById(`leave_${sid}`);
  if (!sel || !timesEl || !leaveEl) return;
  if (sel.value === 'leave') {
    timesEl.classList.add('d-none');
    leaveEl.classList.remove('d-none');
  } else {
    timesEl.classList.remove('d-none');
    leaveEl.classList.add('d-none');
  }
}

// Initialise rows on page load
document.querySelectorAll('.status-sel').forEach(s => updateRow(parseInt(s.dataset.sid)));

function markAllPresent() {
  document.querySelectorAll('.status-sel').forEach(s => {
    s.value = 'present';
    updateRow(parseInt(s.dataset.sid));
  });
}

const markBtn = document.getElementById('markAllPresent');
if (markBtn) markBtn.addEventListener('click', markAllPresent);

const markBtnFooter = document.getElementById('markAllPresentFooter');
if (markBtnFooter) markBtnFooter.addEventListener('click', markAllPresent);

// Colour-code the status select on change for visual feedback
document.querySelectorAll('.status-sel').forEach(sel => {
  function applyColour() {
    sel.classList.remove('border-success','border-danger','border-warning','border-primary','text-success','text-danger','text-warning','text-primary');
    const map = { present: 'success', absent: 'danger', half_day: 'warning', leave: 'primary' };
    const cls = map[sel.value] || 'secondary';
    sel.classList.add('border-' + cls);
  }
  applyColour();
  sel.addEventListener('change', applyColour);
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
