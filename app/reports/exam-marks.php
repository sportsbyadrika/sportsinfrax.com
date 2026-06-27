<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

// ── Scope check ───────────────────────────────────────────────────────────────
$isAdmin = isInstAdmin();
$scope   = $isAdmin ? 'all' : getModuleScope('attendance');
$staffId = authStaffId();

if (!$isAdmin && $scope === 'none') {
    setFlash('error', 'You do not have permission to view marks reports.');
    header('Location: ' . dashboardUrl());
    exit;
}

// ── Build exam select (published only; scope-filtered for staff) ──────────────
$examWhere  = 'e.institution_id = ? AND e.is_published = 1';
$examParams = [$instId];

if (!$isAdmin && $scope === 'own_class' && $staffId) {
    $teacherSecIds = getTeacherSectionIds($staffId, $instId);
    if ($teacherSecIds) {
        $ph         = implode(',', array_fill(0, count($teacherSecIds), '?'));
        $examWhere .= " AND e.section_id IN ({$ph})";
        $examParams  = array_merge($examParams, $teacherSecIds);
    } else {
        $examWhere .= ' AND 1=0';
    }
}

$examSelStmt = $db->prepare(
    "SELECT e.id, e.label, e.section_id,
            et.name          AS type_name,
            cls.name         AS class_name,
            dv.name          AS div_name,
            ay.label         AS year_label
     FROM exams e
     JOIN exam_types    et  ON et.id  = e.exam_type_id
     JOIN sections      sec ON sec.id = e.section_id
     JOIN classes       cls ON cls.id = sec.class_id
     JOIN divisions     dv  ON dv.id  = sec.division_id
     LEFT JOIN academic_years ay ON ay.id = e.academic_year_id
     WHERE {$examWhere}
     ORDER BY ay.is_active DESC, cls.numeric_order, cls.name, dv.name, e.label"
);
$examSelStmt->execute($examParams);
$examOptions = $examSelStmt->fetchAll();

$examOptgroups = [];
foreach ($examOptions as $ex) {
    $grpKey = trim($ex['class_name'] . ' – ' . $ex['div_name'] . ($ex['year_label'] ? ' (' . $ex['year_label'] . ')' : ''));
    $examOptgroups[$grpKey][] = $ex;
}

// ── GET filter ────────────────────────────────────────────────────────────────
$filterExamId = (int)($_GET['exam_id'] ?? 0);

// ── Report data ───────────────────────────────────────────────────────────────
$exam      = null;
$subjects  = [];
$results   = [];
$summary   = ['total' => 0, 'appeared' => 0, 'passed' => 0, 'failed' => 0];
$subjectAvg = []; // subject_id => [sum, count]

if ($filterExamId) {
    $exStmt = $db->prepare(
        "SELECT e.*,
                et.name          AS type_name,
                et.max_marks,
                et.pass_marks,
                et.is_grade_based,
                cls.name         AS class_name,
                dv.name          AS div_name,
                ay.label         AS year_label,
                sec.id           AS sec_id
         FROM exams e
         JOIN exam_types    et  ON et.id  = e.exam_type_id
         JOIN sections      sec ON sec.id = e.section_id
         JOIN classes       cls ON cls.id = sec.class_id
         JOIN divisions     dv  ON dv.id  = sec.division_id
         LEFT JOIN academic_years ay ON ay.id = e.academic_year_id
         WHERE e.id = ? AND e.institution_id = ? AND e.is_published = 1"
    );
    $exStmt->execute([$filterExamId, $instId]);
    $exam = $exStmt->fetch();

    if ($exam) {
        // own_class enforcement for staff
        if (!$isAdmin && $scope === 'own_class' && $staffId) {
            $allowed = getTeacherSectionIds($staffId, $instId);
            if (!in_array((int)$exam['sec_id'], $allowed, true)) {
                $exam = null;
                setFlash('error', 'You do not have access to that section.');
                header('Location: ' . BASE_URL . '/app/reports/exam-marks');
                exit;
            }
        }

        $sectionId = (int)$exam['sec_id'];

        // Subjects
        $subjStmt = $db->prepare(
            "SELECT s.id, s.name, s.code
             FROM section_subjects ss
             JOIN subjects s ON s.id = ss.subject_id
             WHERE ss.section_id = ? AND ss.is_active = 1
               AND s.institution_id = ?
             ORDER BY s.name"
        );
        $subjStmt->execute([$sectionId, $instId]);
        $subjects = $subjStmt->fetchAll();

        // Students
        $stuStmt = $db->prepare(
            "SELECT id, first_name, last_name, admission_number
             FROM students
             WHERE institution_id = ? AND section_id = ? AND is_active = 1
             ORDER BY first_name, last_name"
        );
        $stuStmt->execute([$instId, $sectionId]);
        $students = $stuStmt->fetchAll();

        // Marks
        $mkStmt = $db->prepare(
            "SELECT student_id, subject_id, marks_obtained, is_absent, grade
             FROM exam_marks
             WHERE exam_id = ? AND institution_id = ?"
        );
        $mkStmt->execute([$filterExamId, $instId]);
        $rawMarks = $mkStmt->fetchAll();

        $marksIndex = [];
        foreach ($rawMarks as $row) {
            $marksIndex[$row['student_id']][$row['subject_id']] = $row;
        }

        // Initialise subject average accumulators
        foreach ($subjects as $subj) {
            $subjectAvg[$subj['id']] = ['sum' => 0.0, 'count' => 0];
        }

        $isGradeBased = (bool)$exam['is_grade_based'];
        $maxMarks     = (float)$exam['max_marks'];
        $passMarks    = (float)$exam['pass_marks'];
        $totalMaxAll  = count($subjects) * $maxMarks;

        foreach ($students as $stu) {
            $stuId    = (int)$stu['id'];
            $stuMarks = $marksIndex[$stuId] ?? [];

            $totalObtained = 0.0;
            $allAbsent     = true; // treat as did not appear if all subjects absent
            $allPassed     = true;
            $hasAnyEntry   = !empty($stuMarks);
            $subjData      = [];

            foreach ($subjects as $subj) {
                $subjId  = (int)$subj['id'];
                $entry   = $stuMarks[$subjId] ?? null;

                if (!$entry) {
                    // Not entered
                    $subjData[$subjId] = ['status' => 'not_entered'];
                    $allPassed = false;
                } elseif ($entry['is_absent']) {
                    $subjData[$subjId] = ['status' => 'absent'];
                    $allPassed = false;
                } else {
                    $allAbsent = false;
                    if ($isGradeBased) {
                        $subjData[$subjId] = ['status' => 'grade', 'value' => $entry['grade'] ?? ''];
                    } else {
                        $m = $entry['marks_obtained'] !== null ? (float)$entry['marks_obtained'] : null;
                        $subjData[$subjId] = ['status' => 'marks', 'value' => $m];
                        if ($m !== null) {
                            $totalObtained += $m;
                            $subjectAvg[$subjId]['sum']   += $m;
                            $subjectAvg[$subjId]['count'] += 1;
                            if ($m < $passMarks) $allPassed = false;
                        } else {
                            $allPassed = false;
                        }
                    }
                }
            }

            // Appeared = at least one subject not absent
            $appeared = !$allAbsent || !$hasAnyEntry ? !$allAbsent : false;
            if (!$hasAnyEntry) $appeared = false;

            $percentage = ($totalMaxAll > 0 && !$isGradeBased)
                ? round($totalObtained / $totalMaxAll * 100, 2)
                : null;

            $results[$stuId] = [
                'name'       => $stu['first_name'] . ' ' . $stu['last_name'],
                'admission'  => $stu['admission_number'] ?? '',
                'subjects'   => $subjData,
                'total'      => $isGradeBased ? null : $totalObtained,
                'total_max'  => $isGradeBased ? null : $totalMaxAll,
                'percentage' => $percentage,
                'appeared'   => $appeared,
                'passed'     => $appeared && $allPassed && $hasAnyEntry,
            ];

            $summary['total']++;
            if ($appeared)                                    $summary['appeared']++;
            if ($appeared && $allPassed && $hasAnyEntry)      $summary['passed']++;
            if ($appeared && (!$allPassed || !$hasAnyEntry))  $summary['failed']++;
        }
    }
}

$pageTitle   = 'Marks Report';
$breadcrumbs = [
    'Dashboard'    => dashboardUrl(),
    'Reports'      => BASE_URL . '/app/reports',
    'Marks Report' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4 d-print-none">
  <div class="section-icon"><i class="bi bi-file-earmark-bar-graph-fill"></i></div>
  <div>
    <h4>Marks Report</h4>
    <p>Subject-wise marks report for a selected exam.</p>
  </div>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<div class="card mb-4 d-print-none">
  <div class="card-header">
    <i class="bi bi-funnel me-2 text-primary"></i>Select Exam
  </div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-sm-9 col-md-10">
        <label class="form-label small mb-1">Exam <span class="text-danger">*</span></label>
        <select class="form-select form-select-sm" name="exam_id" required>
          <option value="">Select exam…</option>
          <?php if ($examOptgroups): ?>
            <?php foreach ($examOptgroups as $grpLabel => $grpExams): ?>
            <optgroup label="<?= h($grpLabel) ?>">
              <?php foreach ($grpExams as $ex): ?>
              <option value="<?= (int)$ex['id'] ?>"
                <?= (int)$ex['id'] === $filterExamId ? 'selected' : '' ?>>
                <?= h($ex['label']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          <?php else: ?>
          <option disabled>No published exams found</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-sm-3 col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-search me-1"></i>Load
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($filterExamId && $exam): ?>

<!-- ── Report card ────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>
      <strong><?= h($exam['label']) ?></strong>
      <span class="text-muted mx-1">·</span>
      <?= h($exam['class_name'] . ' – ' . $exam['div_name']) ?>
      <?php if ($exam['year_label']): ?>
      <span class="text-muted mx-1">·</span><?= h($exam['year_label']) ?>
      <?php endif; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary d-print-none"
            onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>

  <!-- Summary row -->
  <div class="card-body border-bottom pb-3">
    <div class="row g-3">
      <div class="col-6 col-sm-3">
        <div class="text-center p-2 rounded bg-light">
          <div class="fw-bold fs-4"><?= $summary['total'] ?></div>
          <div class="small text-muted">Total Students</div>
        </div>
      </div>
      <div class="col-6 col-sm-3">
        <div class="text-center p-2 rounded bg-light">
          <div class="fw-bold fs-4"><?= $summary['appeared'] ?></div>
          <div class="small text-muted">Appeared</div>
        </div>
      </div>
      <div class="col-6 col-sm-3">
        <div class="text-center p-2 rounded" style="background:#d1e7dd;">
          <div class="fw-bold fs-4 text-success"><?= $summary['passed'] ?></div>
          <div class="small text-success">Passed</div>
        </div>
      </div>
      <div class="col-6 col-sm-3">
        <div class="text-center p-2 rounded" style="background:#f8d7da;">
          <div class="fw-bold fs-4 text-danger"><?= $summary['failed'] ?></div>
          <div class="small text-danger">Failed</div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$results): ?>
  <div class="card-body">
    <div class="empty-state py-4">
      <i class="bi bi-table"></i>
      <h6>No marks data</h6>
      <p class="small">No students or marks found for this exam.</p>
    </div>
  </div>
  <?php else: ?>

  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0" id="reportTable">
      <thead class="table-light">
        <tr>
          <th style="width:2rem">#</th>
          <th style="min-width:160px;">Student Name</th>
          <th style="min-width:90px;">Adm. No.</th>
          <?php foreach ($subjects as $subj): ?>
          <th class="text-center" style="min-width:<?= $exam['is_grade_based'] ? '70px' : '90px' ?>;">
            <div class="small fw-semibold"><?= h($subj['name']) ?></div>
            <?php if ($subj['code']): ?>
            <div class="text-muted" style="font-size:.68rem;"><?= h($subj['code']) ?></div>
            <?php endif; ?>
            <?php if (!$exam['is_grade_based']): ?>
            <div class="text-muted" style="font-size:.65rem;">
              /<?= h(number_format((float)$exam['max_marks'], 0)) ?>
            </div>
            <?php endif; ?>
          </th>
          <?php endforeach; ?>
          <?php if (!$exam['is_grade_based']): ?>
          <th class="text-center" style="min-width:70px;">
            Total<br>
            <span class="text-muted" style="font-size:.68rem;">
              /<?= h(number_format((float)(count($subjects) * (float)$exam['max_marks']), 0)) ?>
            </span>
          </th>
          <th class="text-center" style="min-width:70px;">%</th>
          <?php endif; ?>
          <th class="text-center" style="min-width:70px;">Result</th>
        </tr>
      </thead>
      <tbody>
        <?php $rowNum = 0; ?>
        <?php foreach ($results as $stuId => $row): ?>
        <?php $rowNum++; ?>
        <tr>
          <td class="text-muted small"><?= $rowNum ?></td>
          <td class="fw-600 small"><?= h($row['name']) ?></td>
          <td class="small text-muted"><?= h($row['admission']) ?></td>

          <?php foreach ($subjects as $subj): ?>
          <?php
            $subjId  = (int)$subj['id'];
            $cell    = $row['subjects'][$subjId] ?? ['status' => 'not_entered'];
            $status  = $cell['status'];
            $passM   = (float)$exam['pass_marks'];
          ?>
          <td class="text-center small">
            <?php if ($status === 'absent'): ?>
              <span class="badge bg-secondary">Ab</span>
            <?php elseif ($status === 'not_entered'): ?>
              <span class="text-muted">—</span>
            <?php elseif ($status === 'grade'): ?>
              <span class="fw-semibold"><?= h($cell['value']) ?></span>
            <?php else: /* marks */ ?>
              <?php
                $m   = $cell['value'];
                $cls = '';
                if ($m !== null) {
                    $cls = ($m >= $passM) ? 'text-success fw-semibold' : 'text-danger fw-semibold';
                }
              ?>
              <span class="<?= $cls ?>">
                <?= $m !== null ? h(number_format($m, 2)) : '<span class="text-muted">—</span>' ?>
              </span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>

          <?php if (!$exam['is_grade_based']): ?>
          <td class="text-center small fw-semibold">
            <?= $row['total'] !== null ? h(number_format($row['total'], 2)) : '—' ?>
          </td>
          <td class="text-center small">
            <?= $row['percentage'] !== null
                ? h(number_format($row['percentage'], 1)) . '%'
                : '—' ?>
          </td>
          <?php endif; ?>

          <td class="text-center">
            <?php if (!$row['appeared']): ?>
              <span class="badge bg-secondary">Absent</span>
            <?php elseif ($row['passed']): ?>
              <span class="badge bg-success">Pass</span>
            <?php else: ?>
              <span class="badge bg-danger">Fail</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>

      <!-- Footer: per-subject average -->
      <?php if (!$exam['is_grade_based']): ?>
      <tfoot class="table-light fw-semibold">
        <tr>
          <td colspan="3" class="text-end small text-muted">Class Average →</td>
          <?php foreach ($subjects as $subj): ?>
          <?php
            $acc = $subjectAvg[$subj['id']] ?? ['sum' => 0, 'count' => 0];
            $avg = $acc['count'] > 0 ? round($acc['sum'] / $acc['count'], 2) : null;
            $passM = (float)$exam['pass_marks'];
            $avgCls = '';
            if ($avg !== null) {
                $avgCls = ($avg >= $passM) ? 'text-success' : 'text-danger';
            }
          ?>
          <td class="text-center small <?= $avgCls ?>">
            <?= $avg !== null ? h(number_format($avg, 2)) : '—' ?>
          </td>
          <?php endforeach; ?>
          <td colspan="3"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php endif; // results ?>
</div><!-- /.card -->

<?php elseif ($filterExamId && !$exam): ?>
<div class="alert alert-warning d-print-none">Exam not found or not yet published.</div>
<?php endif; ?>

<style>
@media print {
  @page { size: landscape; margin: 1cm; }
  body  { font-size: 8pt !important; }

  /* Hide non-report chrome */
  .app-footer,
  .app-main > .container-fluid > .page-header,
  .section-header-strip,
  .d-print-none,
  nav.navbar,
  #navbarSide,
  .breadcrumb {
    display: none !important;
  }

  /* Expand the report to full width */
  .app-main { padding: 0 !important; }
  .container-fluid { padding: 0 !important; }
  .card { border: none !important; box-shadow: none !important; }
  .card-header { background: #f0f0f0 !important; }

  table { font-size: 7.5pt !important; page-break-inside: auto; }
  thead { display: table-header-group; }
  tr    { page-break-inside: avoid; }
}
</style>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
