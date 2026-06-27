<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Timetable is only available for school institutions.');
    header('Location: ' . dashboardUrl());
    exit;
}

// ── Days used in school timetable ────────────────────────────────────────────
$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];

// ── Load academic years (active first, then by label) ────────────────────────
$ayStmt = $db->prepare(
    "SELECT id, label, is_active FROM academic_years
     WHERE institution_id = ?
     ORDER BY is_active DESC, label"
);
$ayStmt->execute([$instId]);
$academicYears = $ayStmt->fetchAll();

// Default to active year
$defaultAyId = 0;
foreach ($academicYears as $ay) {
    if ((int)$ay['is_active']) { $defaultAyId = (int)$ay['id']; break; }
}

// GET params
$filterAyId      = (int)($_GET['academic_year_id'] ?? $defaultAyId);
$filterSectionId = (int)($_GET['section_id']       ?? 0);
$editMode        = isset($_GET['edit']) && $_GET['edit'] === '1';

// ── Load sections for the selected academic year ──────────────────────────────
$sectionRows = [];
if ($filterAyId) {
    $secStmt = $db->prepare(
        "SELECT sec.id, cls.name AS class_name, dv.name AS div_name, ay.label AS year_label,
                ay.id AS ay_id
         FROM sections sec
         JOIN classes cls ON cls.id = sec.class_id
         JOIN divisions dv ON dv.id = sec.division_id
         JOIN academic_years ay ON ay.id = sec.academic_year_id
         WHERE sec.institution_id = ? AND sec.academic_year_id = ? AND sec.is_active = 1
         ORDER BY cls.numeric_order, cls.name, dv.name"
    );
    $secStmt->execute([$instId, $filterAyId]);
    $sectionRows = $secStmt->fetchAll();
}

// Validate section belongs to institution + selected year
$currentSection = null;
if ($filterSectionId && $sectionRows) {
    foreach ($sectionRows as $sr) {
        if ((int)$sr['id'] === $filterSectionId) {
            $currentSection = $sr;
            break;
        }
    }
    if (!$currentSection) $filterSectionId = 0;
}

// ── POST: Save timetable ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $postSectionId = (int)($_POST['section_id'] ?? 0);
    $postAyId      = (int)($_POST['academic_year_id'] ?? 0);
    $cells         = $_POST['cell'] ?? [];  // cell[day][period_id] = ['subject_id' => x, 'staff_id' => y]

    // Validate section belongs to institution
    $secCheck = $db->prepare(
        "SELECT id FROM sections WHERE id = ? AND institution_id = ?"
    );
    $secCheck->execute([$postSectionId, $instId]);
    if (!$secCheck->fetch()) {
        setFlash('error', 'Invalid section.');
        header('Location: ' . BASE_URL . '/app/services/timetable');
        exit;
    }

    $upsert = $db->prepare(
        "INSERT INTO timetable_entries
           (institution_id, section_id, day_of_week, period_id, subject_id, staff_id)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           subject_id = VALUES(subject_id),
           staff_id   = VALUES(staff_id),
           updated_at = CURRENT_TIMESTAMP"
    );
    $delete = $db->prepare(
        "DELETE FROM timetable_entries
         WHERE section_id = ? AND day_of_week = ? AND period_id = ? AND institution_id = ?"
    );

    foreach ($cells as $dayNum => $periodMap) {
        $dayNum = (int)$dayNum;
        if ($dayNum < 1 || $dayNum > 6) continue;
        if (!is_array($periodMap)) continue;

        foreach ($periodMap as $periodId => $vals) {
            $periodId  = (int)$periodId;
            $subjectId = (int)($vals['subject_id'] ?? 0);
            $staffId   = (int)($vals['staff_id']   ?? 0);

            if ($subjectId === 0) {
                // Empty selection — remove entry if exists
                $delete->execute([$postSectionId, $dayNum, $periodId, $instId]);
            } else {
                $upsert->execute([
                    $instId,
                    $postSectionId,
                    $dayNum,
                    $periodId,
                    $subjectId,
                    $staffId > 0 ? $staffId : null,
                ]);
            }
        }
    }

    setFlash('success', 'Timetable saved successfully.');
    $qs = http_build_query(['academic_year_id' => $postAyId, 'section_id' => $postSectionId]);
    header('Location: ' . BASE_URL . '/app/services/timetable?' . $qs);
    exit;
}

// ── Load data for display when section is selected ────────────────────────────
$periods    = [];
$entries    = [];
$grid       = [];          // $grid[$day][$period_id] = entry row
$subjects   = [];          // subjects assigned to this section
$allStaff   = [];          // all active staff for the institution
$sectionSubjectsMap = [];  // subject_id => [staff_id, ...]

if ($filterSectionId && $currentSection) {
    // Active periods, ordered
    $pStmt = $db->prepare(
        "SELECT * FROM timetable_periods
         WHERE institution_id = ? AND is_active = 1
         ORDER BY sort_order, label"
    );
    $pStmt->execute([$instId]);
    $periods = $pStmt->fetchAll();

    // Timetable entries for this section
    $eStmt = $db->prepare(
        "SELECT te.*, s.name AS subject_name, u.full_name AS staff_name
         FROM timetable_entries te
         LEFT JOIN subjects s  ON s.id  = te.subject_id AND s.institution_id = te.institution_id
         LEFT JOIN staff st    ON st.id = te.staff_id   AND st.institution_id = te.institution_id
         LEFT JOIN users u     ON u.id  = st.user_id
         WHERE te.section_id = ? AND te.institution_id = ?"
    );
    $eStmt->execute([$filterSectionId, $instId]);
    foreach ($eStmt->fetchAll() as $e) {
        $grid[(int)$e['day_of_week']][(int)$e['period_id']] = $e;
    }

    // Section subjects (subjects assigned to this section)
    $ssStmt = $db->prepare(
        "SELECT ss.subject_id, ss.staff_id, s.name AS subject_name, s.code AS subject_code,
                u.full_name AS staff_name
         FROM section_subjects ss
         JOIN subjects s ON s.id = ss.subject_id
         LEFT JOIN staff st ON st.id = ss.staff_id AND st.institution_id = ?
         LEFT JOIN users u  ON u.id  = st.user_id
         WHERE ss.section_id = ?
         ORDER BY s.name"
    );
    $ssStmt->execute([$instId, $filterSectionId]);
    foreach ($ssStmt->fetchAll() as $row) {
        $subjects[(int)$row['subject_id']] = $row;
        // Map subject => staff_id(s) for JS filtering
        $sectionSubjectsMap[(int)$row['subject_id']][] = (int)$row['staff_id'];
    }

    // All active staff (for edit mode selects)
    $stStmt = $db->prepare(
        "SELECT st.id, u.full_name
         FROM staff st
         JOIN users u ON u.id = st.user_id
         WHERE st.institution_id = ? AND st.is_active = 1
         ORDER BY u.full_name"
    );
    $stStmt->execute([$instId]);
    $allStaff = $stStmt->fetchAll();
}

$pageTitle   = 'Timetable';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Services'  => BASE_URL . '/app/services',
    'Timetable' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-grid-3x3-gap-fill"></i></div>
  <div>
    <h4>Timetable</h4>
    <p>View and manage the weekly timetable for any section.</p>
  </div>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-funnel me-2 text-primary"></i>Filter
  </div>
  <div class="card-body">
    <form method="GET" id="filterForm" class="row g-3 align-items-end">
      <!-- Academic Year -->
      <div class="col-sm-6 col-md-4">
        <label class="form-label small mb-1">Academic Year</label>
        <select class="form-select form-select-sm" name="academic_year_id" id="aySelect">
          <option value="">Select year…</option>
          <?php foreach ($academicYears as $ay): ?>
          <option value="<?= (int)$ay['id'] ?>"
            <?= (int)$ay['id'] === $filterAyId ? 'selected' : '' ?>>
            <?= h($ay['label']) ?><?= (int)$ay['is_active'] ? ' (Active)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Section (filtered by year via JS) -->
      <div class="col-sm-6 col-md-5">
        <label class="form-label small mb-1">Section</label>
        <select class="form-select form-select-sm" name="section_id" id="sectionSelect">
          <option value="">Select section…</option>
          <?php foreach ($sectionRows as $sec): ?>
          <option value="<?= (int)$sec['id'] ?>"
                  data-ay="<?= (int)$sec['ay_id'] ?>"
            <?= (int)$sec['id'] === $filterSectionId ? 'selected' : '' ?>>
            <?= h($sec['class_name'] . ' – ' . $sec['div_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-grid me-1"></i>Load
        </button>
      </div>
    </form>
  </div>
</div>

<?php if (!$filterSectionId || !$currentSection): ?>
<!-- ── Prompt card ──────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-grid-3x3-gap fs-1 d-block mb-3 opacity-50"></i>
    <h6>Select a section to view its timetable.</h6>
    <p class="small mb-0">Choose an academic year and section above, then click Load.</p>
  </div>
</div>

<?php else: ?>

<?php
// ── Section title for display ──────────────────────────────────────────────
$sectionTitle = h($currentSection['class_name'] . ' – ' . $currentSection['div_name'])
              . ' <span class="text-muted fw-normal">(' . h($currentSection['year_label']) . ')</span>';
?>

<?php if (!$periods): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i>
  <div>
    <strong>No timetable periods configured.</strong><br>
    <a href="<?= h(BASE_URL . '/app/settings/timetable-periods') ?>" class="alert-link">
      Go to Settings → Timetable Periods
    </a> to add periods before building a timetable.
  </div>
</div>

<?php elseif ($editMode): ?>
<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- EDIT MODE                                                               -->
<!-- ════════════════════════════════════════════════════════════════════════ -->

<?php
// Build JSON map of subject => [staff_ids] for JS filtering
$ssJson = json_encode(
    array_map(fn($ids) => array_values(array_filter($ids)), $sectionSubjectsMap),
    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE
);
?>

<form method="POST" id="timetableForm">
  <?= csrfField() ?>
  <input type="hidden" name="section_id" value="<?= (int)$filterSectionId ?>">
  <input type="hidden" name="academic_year_id" value="<?= (int)$filterAyId ?>">

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <i class="bi bi-pencil-square me-2 text-primary"></i>
        Edit Timetable — <?= $sectionTitle ?>
      </div>
      <a href="<?= h(BASE_URL . '/app/services/timetable?academic_year_id=' . $filterAyId . '&section_id=' . $filterSectionId) ?>"
         class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x-lg me-1"></i>Cancel
      </a>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle mb-0" id="ttGrid">
        <thead class="table-light">
          <tr>
            <th class="tt-period-col" style="min-width:130px;position:sticky;left:0;z-index:2;background:#f8f9fa;">
              Period
            </th>
            <?php foreach ($days as $dayNum => $dayName): ?>
            <th class="text-center" style="min-width:170px;"><?= h($dayName) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periods as $period): ?>
          <?php $pid = (int)$period['id']; ?>
          <tr>
            <!-- Period label column -->
            <td class="tt-period-col small fw-600"
                style="position:sticky;left:0;z-index:1;background:#fff;">
              <?= h($period['label']) ?>
              <?php
                $st = substr($period['start_time'] ?? '', 0, 5);
                $et = substr($period['end_time']   ?? '', 0, 5);
                if ($st || $et):
              ?>
              <div class="text-muted" style="font-size:.7rem;">
                <?= h(($st ?: '?') . '–' . ($et ?: '?')) ?>
              </div>
              <?php endif; ?>
            </td>

            <?php foreach ($days as $dayNum => $dayName): ?>
            <?php
              $existing  = $grid[$dayNum][$pid] ?? null;
              $curSubId  = $existing ? (int)$existing['subject_id'] : 0;
              $curStfId  = $existing ? (int)$existing['staff_id']   : 0;
              $cellKey   = "cell[{$dayNum}][{$pid}]";
            ?>
            <td class="p-1 <?= (int)$period['is_break'] ? 'table-secondary' : '' ?>">
              <?php if ((int)$period['is_break']): ?>
              <!-- Break slot: no editing, just show label -->
              <input type="hidden" name="<?= $cellKey ?>[subject_id]" value="0">
              <input type="hidden" name="<?= $cellKey ?>[staff_id]"   value="0">
              <div class="text-center text-muted small">
                <span class="badge bg-secondary">Break</span>
              </div>
              <?php else: ?>
              <!-- Subject select -->
              <select class="form-select form-select-sm mb-1 subject-select"
                      name="<?= $cellKey ?>[subject_id]"
                      data-day="<?= $dayNum ?>"
                      data-period="<?= $pid ?>"
                      style="font-size:.75rem;">
                <option value="0">— Free —</option>
                <?php foreach ($subjects as $subj): ?>
                <option value="<?= (int)$subj['subject_id'] ?>"
                        data-staff="<?= (int)$subj['staff_id'] ?>"
                  <?= (int)$subj['subject_id'] === $curSubId ? 'selected' : '' ?>>
                  <?= h($subj['subject_name']) ?><?= $subj['subject_code'] ? ' (' . h($subj['subject_code']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
                <?php if (!$subjects): ?>
                <?php foreach ($db->query("SELECT s.id, s.name, s.code FROM subjects s WHERE s.institution_id = {$instId} ORDER BY s.name")->fetchAll() as $subj): ?>
                <option value="<?= (int)$subj['id'] ?>"
                  <?= (int)$subj['id'] === $curSubId ? 'selected' : '' ?>>
                  <?= h($subj['name']) ?><?= $subj['code'] ? ' (' . h($subj['code']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
                <?php endif; ?>
              </select>
              <!-- Staff select -->
              <select class="form-select form-select-sm staff-select"
                      name="<?= $cellKey ?>[staff_id]"
                      data-day="<?= $dayNum ?>"
                      data-period="<?= $pid ?>"
                      style="font-size:.75rem;">
                <option value="0">— Teacher —</option>
                <?php foreach ($allStaff as $stf): ?>
                <option value="<?= (int)$stf['id'] ?>"
                  <?= (int)$stf['id'] === $curStfId ? 'selected' : '' ?>>
                  <?= h($stf['full_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer d-flex justify-content-end gap-2">
      <a href="<?= h(BASE_URL . '/app/services/timetable?academic_year_id=' . $filterAyId . '&section_id=' . $filterSectionId) ?>"
         class="btn btn-outline-secondary">
        <i class="bi bi-x-lg me-1"></i>Cancel
      </a>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-1"></i>Save Timetable
      </button>
    </div>
  </div>
</form>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- VIEW MODE                                                               -->
<!-- ════════════════════════════════════════════════════════════════════════ -->

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <i class="bi bi-grid me-2 text-primary"></i>
      Timetable — <?= $sectionTitle ?>
    </div>
    <a href="<?= h(BASE_URL . '/app/services/timetable?academic_year_id=' . $filterAyId . '&section_id=' . $filterSectionId . '&edit=1') ?>"
       class="btn btn-sm btn-outline-primary">
      <i class="bi bi-pencil me-1"></i>Edit Timetable
    </a>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="min-width:130px;position:sticky;left:0;z-index:2;background:#f8f9fa;">Period</th>
          <?php foreach ($days as $dayNum => $dayName): ?>
          <th class="text-center" style="min-width:130px;"><?= h($dayName) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($periods as $period): ?>
        <?php $pid = (int)$period['id']; ?>
        <tr>
          <!-- Period label -->
          <td class="small fw-600" style="position:sticky;left:0;z-index:1;background:#fff;">
            <?= h($period['label']) ?>
            <?php
              $st = substr($period['start_time'] ?? '', 0, 5);
              $et = substr($period['end_time']   ?? '', 0, 5);
              if ($st || $et):
            ?>
            <div class="text-muted" style="font-size:.7rem;">
              <?= h(($st ?: '?') . '–' . ($et ?: '?')) ?>
            </div>
            <?php endif; ?>
          </td>

          <?php foreach ($days as $dayNum => $dayName): ?>
          <?php $entry = $grid[$dayNum][$pid] ?? null; ?>
          <td class="text-center <?= (int)$period['is_break'] ? 'table-secondary' : '' ?>">
            <?php if ((int)$period['is_break']): ?>
              <span class="badge bg-secondary">Break</span>
            <?php elseif ($entry && $entry['subject_id']): ?>
              <div class="small fw-600"><?= h($entry['subject_name'] ?? '') ?></div>
              <?php if ($entry['staff_name']): ?>
              <div class="text-muted" style="font-size:.7rem;"><?= h($entry['staff_name']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // view vs edit mode ?>
<?php endif; // section selected ?>

<script>
(function () {
    // ── Auto-submit filter form when academic year changes ────────────────────
    var aySelect = document.getElementById('aySelect');
    if (aySelect) {
        aySelect.addEventListener('change', function () {
            // Clear section, then submit
            var secSel = document.getElementById('sectionSelect');
            if (secSel) secSel.value = '';
            document.getElementById('filterForm').submit();
        });
    }

    // ── Section dropdown: filter by selected academic year ────────────────────
    // (useful when JS-reloading without full page load; also hides irrelevant options)
    var secSelect = document.getElementById('sectionSelect');
    if (secSelect && aySelect) {
        function filterSections() {
            var ayId = aySelect.value;
            var opts = secSelect.querySelectorAll('option[data-ay]');
            opts.forEach(function (o) {
                o.hidden = ayId ? (o.getAttribute('data-ay') !== ayId) : false;
            });
        }
        filterSections();
        aySelect.addEventListener('change', filterSections);
    }

    // ── Edit grid: filter teacher select based on chosen subject ─────────────
    // section_subjects map: { subject_id: [staff_id, ...] }
    var ssMap = <?= $ssJson ?? '{}' ?>;

    function filterTeachers(subjectSelect) {
        var day    = subjectSelect.getAttribute('data-day');
        var period = subjectSelect.getAttribute('data-period');
        var subId  = parseInt(subjectSelect.value, 10);

        // Find the paired staff select
        var staffSelects = document.querySelectorAll(
            '.staff-select[data-day="' + day + '"][data-period="' + period + '"]'
        );
        staffSelects.forEach(function (staffSel) {
            var allowedIds = (ssMap[subId] && ssMap[subId].length > 0) ? ssMap[subId] : null;
            var opts = staffSel.querySelectorAll('option');

            opts.forEach(function (o) {
                var sid = parseInt(o.value, 10);
                if (sid === 0) { o.hidden = false; return; } // keep "— Teacher —"
                o.hidden = allowedIds ? (allowedIds.indexOf(sid) === -1) : false;
            });

            // If the currently selected value is now hidden, reset to the
            // subject's primary teacher (first in allowedIds) or blank
            var curVal = parseInt(staffSel.value, 10);
            if (curVal !== 0 && allowedIds && allowedIds.indexOf(curVal) === -1) {
                staffSel.value = allowedIds[0] ? String(allowedIds[0]) : '0';
            }
            // Auto-select when there's exactly one allowed teacher and subject chosen
            if (subId !== 0 && allowedIds && allowedIds.length === 1) {
                staffSel.value = String(allowedIds[0]);
            }
        });
    }

    // Attach listeners + run on page load for pre-populated cells
    document.querySelectorAll('.subject-select').forEach(function (sel) {
        sel.addEventListener('change', function () { filterTeachers(sel); });
        filterTeachers(sel); // initialise on load
    });
}());
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
