<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

// Determine institution type
$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst     = $instStmt->fetch();
$isSchool = $inst && getInstitutionCategory($inst['institution_type'] ?? '') === 'school';
$entityType = $isSchool ? 'student' : 'member';

// Scope check
$isAdmin = isInstAdmin();
$scope   = $isAdmin ? 'all' : getModuleScope('attendance');
$staffId = authStaffId();

if ($scope === 'none' && !$isAdmin) {
    setFlash('error', 'You do not have permission to access the Attendance module.');
    header('Location: ' . dashboardUrl());
    exit;
}

// Load sessions
$sessStmt = $db->prepare(
    "SELECT * FROM attendance_sessions WHERE institution_id = ? AND is_active = 1 ORDER BY sort_order, label"
);
$sessStmt->execute([$instId]);
$sessions = $sessStmt->fetchAll();

// Load sections for school (scoped)
$sectionOptions = [];
$sectionOptgroups = [];
if ($isSchool) {
    if ($scope === 'own_class') {
        $accessibleIds = $staffId ? getTeacherSectionIds($staffId, $instId) : [];
        if ($accessibleIds) {
            $ph = implode(',', array_fill(0, count($accessibleIds), '?'));
            $secStmt = $db->prepare(
                "SELECT sec.id, cls.name AS class_name, dv.name AS div_name, ay.label AS year_label
                 FROM sections sec
                 JOIN classes cls ON cls.id = sec.class_id
                 JOIN divisions dv ON dv.id = sec.division_id
                 JOIN academic_years ay ON ay.id = sec.academic_year_id
                 WHERE sec.institution_id = ? AND sec.is_active = 1 AND sec.id IN ({$ph})
                 ORDER BY ay.is_active DESC, cls.numeric_order, cls.name, dv.name"
            );
            $secStmt->execute(array_merge([$instId], $accessibleIds));
            $sectionRows = $secStmt->fetchAll();
        } else {
            $sectionRows = [];
        }
    } else {
        // scope = 'all'
        $secStmt = $db->prepare(
            "SELECT sec.id, cls.name AS class_name, dv.name AS div_name, ay.label AS year_label
             FROM sections sec
             JOIN classes cls ON cls.id = sec.class_id
             JOIN divisions dv ON dv.id = sec.division_id
             JOIN academic_years ay ON ay.id = sec.academic_year_id
             WHERE sec.institution_id = ? AND sec.is_active = 1
             ORDER BY ay.is_active DESC, cls.numeric_order, cls.name, dv.name"
        );
        $secStmt->execute([$instId]);
        $sectionRows = $secStmt->fetchAll();
    }

    // Group into optgroups by year_label
    foreach ($sectionRows as $row) {
        $sectionOptgroups[$row['year_label']][] = $row;
    }
}

// GET filter params
$filterDate      = $_GET['date']       ?? date('Y-m-d');
$filterSessionId = (int)($_GET['session_id'] ?? 0);
$filterSectionId = $isSchool ? (int)($_GET['section_id'] ?? 0) : 0;

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

// Determine if we're in list state
$isListState = false;
if ($filterSessionId > 0 && $filterDate) {
    if ($isSchool) {
        $isListState = ($filterSectionId > 0);
    } else {
        $isListState = true;
    }
}

// ── POST: Save attendance ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $postSessionId  = (int)($_POST['session_id']      ?? 0);
    $postDate       = $_POST['attendance_date']        ?? '';
    $postSectionId  = (int)($_POST['section_id']       ?? 0);
    $attData        = $_POST['att']                    ?? [];

    if (!$postSessionId || !$postDate || !is_array($attData)) {
        setFlash('error', 'Invalid submission. Please try again.');
        header('Location: ' . BASE_URL . '/app/services/attendance');
        exit;
    }

    // Validate session belongs to institution
    $sessCheck = $db->prepare("SELECT id FROM attendance_sessions WHERE id = ? AND institution_id = ?");
    $sessCheck->execute([$postSessionId, $instId]);
    if (!$sessCheck->fetch()) {
        setFlash('error', 'Invalid session.');
        header('Location: ' . BASE_URL . '/app/services/attendance');
        exit;
    }

    $upsertStmt = $db->prepare(
        "INSERT INTO member_attendance
           (institution_id, entity_type, entity_id, session_id, attendance_date,
            status, leave_type, remarks, marked_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           status     = VALUES(status),
           leave_type = VALUES(leave_type),
           remarks    = VALUES(remarks),
           marked_by  = VALUES(marked_by),
           updated_at = CURRENT_TIMESTAMP"
    );

    $counts   = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0];
    $markedBy = authId();

    foreach ($attData as $entityId => $row) {
        $entityId  = (int)$entityId;
        $status    = $row['status']     ?? '';
        $leaveType = $row['leave_type'] ?? null;
        $remarks   = trim($row['remarks'] ?? '');

        $validStatuses = ['present', 'absent', 'late', 'leave'];
        if (!in_array($status, $validStatuses, true)) continue;

        // Only store leave_type when status = leave
        $leaveTypeVal = ($status === 'leave' && in_array($leaveType, ['sick', 'casual', 'other'], true))
            ? $leaveType
            : null;
        $remarksVal   = $remarks !== '' ? mb_substr($remarks, 0, 300) : null;

        $upsertStmt->execute([
            $instId,
            $entityType,
            $entityId,
            $postSessionId,
            $postDate,
            $status,
            $leaveTypeVal,
            $remarksVal,
            $markedBy,
        ]);

        $counts[$status]++;
    }

    $summaryParts = [];
    if ($counts['present']) $summaryParts[] = $counts['present'] . ' Present';
    if ($counts['absent'])  $summaryParts[] = $counts['absent']  . ' Absent';
    if ($counts['late'])    $summaryParts[] = $counts['late']    . ' Late';
    if ($counts['leave'])   $summaryParts[] = $counts['leave']   . ' On Leave';

    $summary = $summaryParts ? implode(', ', $summaryParts) : 'No records';
    setFlash('success', 'Attendance saved: ' . $summary . '.');

    $qs = http_build_query(array_filter([
        'date'       => $postDate,
        'session_id' => $postSessionId,
        'section_id' => $isSchool ? $postSectionId : null,
    ]));
    header('Location: ' . BASE_URL . '/app/services/attendance' . ($qs ? '?' . $qs : ''));
    exit;
}

// ── Load entities and existing attendance when in list state ──────────────────
$entities          = [];
$existingAttendance = [];

if ($isListState) {
    // Verify session belongs to institution
    $sessOk = false;
    foreach ($sessions as $s) {
        if ((int)$s['id'] === $filterSessionId) { $sessOk = true; break; }
    }

    if ($sessOk) {
        if ($isSchool) {
            $entStmt = $db->prepare(
                "SELECT id, first_name, last_name, passport_photo, admission_number
                 FROM students
                 WHERE institution_id = ? AND section_id = ? AND is_active = 1
                 ORDER BY first_name, last_name"
            );
            $entStmt->execute([$instId, $filterSectionId]);
        } else {
            $entStmt = $db->prepare(
                "SELECT id, first_name, last_name, passport_photo, member_code
                 FROM members
                 WHERE institution_id = ? AND is_active = 1
                 ORDER BY first_name, last_name"
            );
            $entStmt->execute([$instId]);
        }
        $entities = $entStmt->fetchAll();

        // Load existing attendance
        $attStmt = $db->prepare(
            "SELECT entity_id, status, leave_type, remarks
             FROM member_attendance
             WHERE institution_id = ? AND session_id = ? AND attendance_date = ? AND entity_type = ?"
        );
        $attStmt->execute([$instId, $filterSessionId, $filterDate, $entityType]);
        foreach ($attStmt->fetchAll() as $row) {
            $existingAttendance[$row['entity_id']] = $row;
        }
    }
}

// Summary counts for existing records
$summaryCount = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0];
foreach ($existingAttendance as $rec) {
    $summaryCount[$rec['status']] = ($summaryCount[$rec['status']] ?? 0) + 1;
}
$hasExisting = array_sum($summaryCount) > 0;

$memberLabel = memberLabel(false); // 'Students' or 'Members'
$pageTitle   = 'Mark Attendance';
$breadcrumbs = [
    'Dashboard'   => dashboardUrl(),
    'Services'    => BASE_URL . '/app/services',
    'Mark Attendance' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-calendar-check-fill"></i></div>
  <div>
    <h4>Mark Attendance</h4>
    <p>Record daily attendance for <?= h(strtolower($memberLabel)) ?> by session and date.
      <?php if ($scope === 'own_class'): ?>
      <span class="badge bg-warning text-dark ms-1">Own Section Only</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if (!$sessions): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i>
  <div>
    <strong>No attendance sessions configured.</strong><br>
    <a href="<?= h(BASE_URL . '/app/settings') ?>" class="alert-link">
      Go to Settings
    </a> to add sessions (e.g. Morning, Afternoon) before marking attendance.
  </div>
</div>
<?php else: ?>

<!-- ── Filter Panel ──────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-funnel me-2 text-primary"></i>Filter
  </div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-sm-6 col-md-3">
        <label class="form-label small mb-1">Date <span class="text-danger">*</span></label>
        <input type="date" class="form-control form-control-sm" name="date"
               value="<?= h($filterDate) ?>" required>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small mb-1">Session <span class="text-danger">*</span></label>
        <select class="form-select form-select-sm" name="session_id" required>
          <option value="">Select session…</option>
          <?php foreach ($sessions as $sess): ?>
          <option value="<?= (int)$sess['id'] ?>"
            <?= (int)$sess['id'] === $filterSessionId ? 'selected' : '' ?>>
            <?= h($sess['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($isSchool): ?>
      <div class="col-sm-6 col-md-4">
        <label class="form-label small mb-1">Section <span class="text-danger">*</span></label>
        <select class="form-select form-select-sm" name="section_id" required>
          <option value="">Select section…</option>
          <?php foreach ($sectionOptgroups as $yearLabel => $secRows): ?>
          <optgroup label="<?= h($yearLabel) ?>">
            <?php foreach ($secRows as $sec): ?>
            <option value="<?= (int)$sec['id'] ?>"
              <?= (int)$sec['id'] === $filterSectionId ? 'selected' : '' ?>>
              <?= h($sec['class_name'] . ' – ' . $sec['div_name']) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="col-sm-6 col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-search me-1"></i>Load
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Attendance Table (list state) ────────────────────────────────────── -->
<?php if ($isListState): ?>

<?php
// Find session label for display
$sessionLabel = '';
foreach ($sessions as $s) {
    if ((int)$s['id'] === $filterSessionId) { $sessionLabel = $s['label']; break; }
}
// Section label for display
$sectionLabel = '';
if ($isSchool) {
    foreach ($sectionOptgroups as $yearLabel => $secRows) {
        foreach ($secRows as $sec) {
            if ((int)$sec['id'] === $filterSectionId) {
                $sectionLabel = $sec['class_name'] . ' – ' . $sec['div_name'] . ' (' . $yearLabel . ')';
                break 2;
            }
        }
    }
}
?>

<?php if (!$entities): ?>
<div class="alert alert-info d-flex align-items-center gap-3">
  <i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i>
  <div>
    <strong>No <?= h(strtolower($memberLabel)) ?> found</strong>
    <?= $isSchool ? ' in this section.' : ' in this institution.' ?>
  </div>
</div>
<?php else: ?>

<?php if ($hasExisting): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2">
  <i class="bi bi-bar-chart-fill me-1"></i>
  <strong>Current Summary:</strong>
  <span class="ms-2">
    <span class="badge bg-success"><?= $summaryCount['present'] ?> Present</span>
    <span class="badge bg-danger ms-1"><?= $summaryCount['absent'] ?> Absent</span>
    <span class="badge bg-warning text-dark ms-1"><?= $summaryCount['late'] ?> Late</span>
    <span class="badge bg-info text-dark ms-1"><?= $summaryCount['leave'] ?> Leave</span>
  </span>
</div>
<?php endif; ?>

<form method="POST" id="attendanceForm">
  <?= csrfField() ?>
  <input type="hidden" name="session_id"      value="<?= $filterSessionId ?>">
  <input type="hidden" name="attendance_date" value="<?= h($filterDate) ?>">
  <?php if ($isSchool): ?>
  <input type="hidden" name="section_id"      value="<?= $filterSectionId ?>">
  <?php endif; ?>

  <div class="card table-card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <i class="bi bi-calendar-check me-2 text-primary"></i>
        <strong><?= h(fmtDate($filterDate)) ?></strong>
        <span class="text-muted mx-1">·</span>
        <?= h($sessionLabel) ?>
        <?php if ($sectionLabel): ?>
        <span class="text-muted mx-1">·</span>
        <?= h($sectionLabel) ?>
        <?php endif; ?>
        <span class="badge bg-secondary ms-2"><?= count($entities) ?> <?= h(strtolower($memberLabel)) ?></span>
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-success" id="markAllPresent">
          <i class="bi bi-check-all me-1"></i>All Present
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="markAllAbsent">
          <i class="bi bi-x-circle me-1"></i>All Absent
        </button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th style="width:3rem">#</th>
            <th style="width:3.5rem">Photo</th>
            <th>Name</th>
            <th class="text-center" style="width:5rem">
              <span class="badge bg-success">P</span>
            </th>
            <th class="text-center" style="width:5rem">
              <span class="badge bg-danger">A</span>
            </th>
            <th class="text-center" style="width:5rem">
              <span class="badge bg-warning text-dark">La</span>
            </th>
            <th class="text-center" style="width:5rem">
              <span class="badge bg-info text-dark">L</span>
            </th>
            <th style="min-width:200px">Leave Type / Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entities as $i => $ent): ?>
          <?php
              $eid       = (int)$ent['id'];
              $existing  = $existingAttendance[$eid] ?? null;
              $curStatus = $existing ? $existing['status']     : '';
              $curLeave  = $existing ? ($existing['leave_type'] ?? '') : '';
              $curRemark = $existing ? ($existing['remarks']   ?? '') : '';
              $subLabel  = $isSchool
                  ? ($ent['admission_number'] ?? '')
                  : ($ent['member_code']      ?? '');
          ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <?php if (!empty($ent['passport_photo'])): ?>
              <img src="<?= h(PHOTO_URL . '/' . $ent['passport_photo']) ?>"
                   alt=""
                   style="width:36px;height:36px;border-radius:6px;object-fit:cover;">
              <?php else: ?>
              <div class="avatar-circle"
                   style="width:36px;height:36px;font-size:.78rem;border-radius:6px;display:flex;align-items:center;justify-content:center;">
                <?= h(mb_strtoupper(mb_substr($ent['first_name'], 0, 1))) ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-600 small"><?= h($ent['first_name'] . ' ' . $ent['last_name']) ?></div>
              <?php if ($subLabel): ?>
              <div class="text-muted" style="font-size:.72rem;"><?= h($subLabel) ?></div>
              <?php endif; ?>
              <?php if ($existing): ?>
              <?php
                  $badgeMap = [
                      'present' => 'bg-success',
                      'absent'  => 'bg-danger',
                      'late'    => 'bg-warning text-dark',
                      'leave'   => 'bg-info text-dark',
                  ];
                  $badgeCls = $badgeMap[$existing['status']] ?? 'bg-secondary';
                  $badgeLbl = ['present' => 'P', 'absent' => 'A', 'late' => 'La', 'leave' => 'L'];
              ?>
              <span class="badge <?= $badgeCls ?>" style="font-size:.65rem;">
                <?= $badgeLbl[$existing['status']] ?? h($existing['status']) ?>
              </span>
              <?php endif; ?>
            </td>
            <!-- Present -->
            <td class="text-center">
              <input class="form-check-input att-radio" type="radio"
                     name="att[<?= $eid ?>][status]"
                     value="present"
                     data-id="<?= $eid ?>"
                     <?= $curStatus === 'present' ? 'checked' : '' ?>>
            </td>
            <!-- Absent -->
            <td class="text-center">
              <input class="form-check-input att-radio" type="radio"
                     name="att[<?= $eid ?>][status]"
                     value="absent"
                     data-id="<?= $eid ?>"
                     <?= $curStatus === 'absent' ? 'checked' : '' ?>>
            </td>
            <!-- Late -->
            <td class="text-center">
              <input class="form-check-input att-radio" type="radio"
                     name="att[<?= $eid ?>][status]"
                     value="late"
                     data-id="<?= $eid ?>"
                     <?= $curStatus === 'late' ? 'checked' : '' ?>>
            </td>
            <!-- Leave -->
            <td class="text-center">
              <input class="form-check-input att-radio" type="radio"
                     name="att[<?= $eid ?>][status]"
                     value="leave"
                     data-id="<?= $eid ?>"
                     <?= $curStatus === 'leave' ? 'checked' : '' ?>>
            </td>
            <!-- Leave type + Remarks -->
            <td>
              <div class="d-flex gap-2 align-items-center">
                <div id="leave-wrap-<?= $eid ?>"
                     style="flex-shrink:0;<?= $curStatus !== 'leave' ? 'display:none;' : '' ?>">
                  <select class="form-select form-select-sm"
                          name="att[<?= $eid ?>][leave_type]"
                          id="leave-type-<?= $eid ?>"
                          style="width:100px;">
                    <option value="">Type…</option>
                    <option value="sick"   <?= $curLeave === 'sick'    ? 'selected' : '' ?>>Sick</option>
                    <option value="casual" <?= $curLeave === 'casual'  ? 'selected' : '' ?>>Casual</option>
                    <option value="other"  <?= $curLeave === 'other'   ? 'selected' : '' ?>>Other</option>
                  </select>
                </div>
                <input type="text" class="form-control form-control-sm"
                       name="att[<?= $eid ?>][remarks]"
                       value="<?= h($curRemark) ?>"
                       maxlength="300"
                       placeholder="Remarks (optional)">
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
      <?php if ($hasExisting): ?>
      <span class="text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        <?= $summaryCount['present'] ?> Present &middot;
        <?= $summaryCount['absent']  ?> Absent &middot;
        <?= $summaryCount['late']    ?> Late &middot;
        <?= $summaryCount['leave']   ?> Leave
      </span>
      <?php else: ?>
      <span class="text-muted small">No attendance saved yet for this session &amp; date.</span>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-1"></i>Save Attendance
      </button>
    </div>
  </div>
</form>

<?php endif; // entities ?>
<?php endif; // isListState ?>

<?php endif; // sessions exist ?>

<script>
(function () {
    // ── Leave type show/hide ──────────────────────────────────────────────────
    function updateLeaveWrap(radio) {
        var id   = radio.getAttribute('data-id');
        var wrap = document.getElementById('leave-wrap-' + id);
        if (!wrap) return;
        wrap.style.display = (radio.value === 'leave' && radio.checked) ? '' : 'none';
    }

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.classList.contains('att-radio') && el.type === 'radio') {
            // Hide all wraps for this row first (there is only one per row but be safe)
            var id   = el.getAttribute('data-id');
            var wrap = document.getElementById('leave-wrap-' + id);
            if (wrap) wrap.style.display = (el.value === 'leave') ? '' : 'none';
        }
    });

    // On page load: ensure leave wraps match current radio state
    document.querySelectorAll('.att-radio[value="leave"]').forEach(function (r) {
        if (r.checked) {
            var wrap = document.getElementById('leave-wrap-' + r.getAttribute('data-id'));
            if (wrap) wrap.style.display = '';
        }
    });

    // ── Mark All Present ─────────────────────────────────────────────────────
    var btnPresent = document.getElementById('markAllPresent');
    if (btnPresent) {
        btnPresent.addEventListener('click', function () {
            document.querySelectorAll('.att-radio[value="present"]').forEach(function (r) {
                r.checked = true;
                var wrap = document.getElementById('leave-wrap-' + r.getAttribute('data-id'));
                if (wrap) wrap.style.display = 'none';
            });
        });
    }

    // ── Mark All Absent ──────────────────────────────────────────────────────
    var btnAbsent = document.getElementById('markAllAbsent');
    if (btnAbsent) {
        btnAbsent.addEventListener('click', function () {
            document.querySelectorAll('.att-radio[value="absent"]').forEach(function (r) {
                r.checked = true;
                var wrap = document.getElementById('leave-wrap-' + r.getAttribute('data-id'));
                if (wrap) wrap.style.display = 'none';
            });
        });
    }
}());
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
