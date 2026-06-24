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
    setFlash('error', 'You do not have permission to enter marks.');
    header('Location: ' . dashboardUrl());
    exit;
}

// ── Build exam select (published only for staff, all for admin) ───────────────
$examWhere  = 'e.institution_id = ?';
$examParams = [$instId];
if (!$isAdmin) {
    $examWhere .= ' AND e.is_published = 1';
}
if (!$isAdmin && $scope === 'own_class' && $staffId) {
    $teacherSecIds = getTeacherSectionIds($staffId, $instId);
    if ($teacherSecIds) {
        $ph         = implode(',', array_fill(0, count($teacherSecIds), '?'));
        $examWhere .= " AND e.section_id IN ({$ph})";
        $examParams  = array_merge($examParams, $teacherSecIds);
    } else {
        // Staff has own_class scope but is not a class teacher anywhere
        $examWhere .= ' AND 1=0';
    }
}

$examSelStmt = $db->prepare(
    "SELECT e.id, e.label, e.section_id, e.is_published,
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

// Group into optgroups: "ClassName – DivName (AcadYear)"  keyed by section label
$examOptgroups = [];
foreach ($examOptions as $ex) {
    $grpKey = trim($ex['class_name'] . ' – ' . $ex['div_name'] . ($ex['year_label'] ? ' (' . $ex['year_label'] . ')' : ''));
    $examOptgroups[$grpKey][] = $ex;
}

// ── GET filter ────────────────────────────────────────────────────────────────
$filterExamId = (int)($_GET['exam_id'] ?? 0);

// ── Data for selected exam ────────────────────────────────────────────────────
$exam        = null;
$examType    = null;
$subjects    = [];
$students    = [];
$marksIndex  = []; // [student_id][subject_id] => row

if ($filterExamId) {
    // Fetch exam (enforce institution + publish scope)
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
         WHERE e.id = ? AND e.institution_id = ?"
    );
    $exStmt->execute([$filterExamId, $instId]);
    $exam = $exStmt->fetch();

    if ($exam) {
        // Staff may only see published exams
        if (!$isAdmin && !$exam['is_published']) {
            $exam = null;
            setFlash('error', 'That exam has not been published yet.');
            header('Location: ' . BASE_URL . '/app/services/exam-marks');
            exit;
        }

        // own_class check
        if (!$isAdmin && $scope === 'own_class' && $staffId) {
            $allowed = getTeacherSectionIds($staffId, $instId);
            if (!in_array((int)$exam['sec_id'], $allowed, true)) {
                $exam = null;
                setFlash('error', 'You do not have access to that section.');
                header('Location: ' . BASE_URL . '/app/services/exam-marks');
                exit;
            }
        }

        $sectionId = (int)$exam['sec_id'];

        // Subjects for this section
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

        // Students for this section
        $stuStmt = $db->prepare(
            "SELECT id, first_name, last_name, admission_number, passport_photo
             FROM students
             WHERE institution_id = ? AND section_id = ? AND is_active = 1
             ORDER BY first_name, last_name"
        );
        $stuStmt->execute([$instId, $sectionId]);
        $students = $stuStmt->fetchAll();

        // Existing marks
        $mkStmt = $db->prepare(
            "SELECT student_id, subject_id, marks_obtained, is_absent, grade
             FROM exam_marks
             WHERE exam_id = ? AND institution_id = ?"
        );
        $mkStmt->execute([$filterExamId, $instId]);
        foreach ($mkStmt->fetchAll() as $row) {
            $marksIndex[$row['student_id']][$row['subject_id']] = $row;
        }
    }
}

// ── POST: Save marks ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $postExamId = (int)($_POST['exam_id'] ?? 0);

    // Re-fetch exam for validation
    $exStmt2 = $db->prepare(
        "SELECT e.*, et.max_marks, et.pass_marks, et.is_grade_based,
                sec.id AS sec_id
         FROM exams e
         JOIN exam_types et ON et.id = e.exam_type_id
         JOIN sections   sec ON sec.id = e.section_id
         WHERE e.id = ? AND e.institution_id = ?"
    );
    $exStmt2->execute([$postExamId, $instId]);
    $postExam = $exStmt2->fetch();

    if (!$postExam) {
        setFlash('error', 'Invalid exam.');
        header('Location: ' . BASE_URL . '/app/services/exam-marks');
        exit;
    }
    if (!$isAdmin && !$postExam['is_published']) {
        setFlash('error', 'Exam is not published.');
        header('Location: ' . BASE_URL . '/app/services/exam-marks');
        exit;
    }

    $marksData  = $_POST['marks']  ?? [];
    $absentData = $_POST['absent'] ?? [];
    $gradeData  = $_POST['grade']  ?? [];

    $upsert = $db->prepare(
        "INSERT INTO exam_marks
           (exam_id, institution_id, student_id, subject_id,
            marks_obtained, is_absent, grade, entered_by)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           marks_obtained = VALUES(marks_obtained),
           is_absent      = VALUES(is_absent),
           grade          = VALUES(grade),
           entered_by     = VALUES(entered_by),
           updated_at     = CURRENT_TIMESTAMP"
    );

    $enteredBy  = authId();
    $savedCount = 0;

    foreach ($marksData as $stuId => $subjArr) {
        $stuId = (int)$stuId;
        if (!is_array($subjArr)) continue;

        foreach ($subjArr as $subjId => $rawMarks) {
            $subjId   = (int)$subjId;
            $isAbsent = !empty($absentData[$stuId][$subjId]) ? 1 : 0;

            if ($postExam['is_grade_based']) {
                $marksVal = null;
                $gradeVal = mb_substr(trim($gradeData[$stuId][$subjId] ?? ''), 0, 5) ?: null;
            } else {
                $gradeVal = null;
                if ($isAbsent) {
                    $marksVal = null;
                } else {
                    $rawMarks = trim($rawMarks);
                    if ($rawMarks === '' || $rawMarks === null) {
                        $marksVal = null;
                    } else {
                        $marksVal = min((float)$postExam['max_marks'], max(0, (float)$rawMarks));
                    }
                }
            }

            $upsert->execute([
                $postExamId,
                $instId,
                $stuId,
                $subjId,
                $marksVal,
                $isAbsent,
                $gradeVal,
                $enteredBy,
            ]);
            $savedCount++;
        }
    }

    setFlash('success', "Marks saved ({$savedCount} records updated).");
    header('Location: ' . BASE_URL . '/app/services/exam-marks?exam_id=' . $postExamId);
    exit;
}

// ── Page ─────────────────────────────────────────────────────────────────────
$pageTitle   = 'Enter Marks';
$breadcrumbs = [
    'Dashboard'   => dashboardUrl(),
    'Services'    => BASE_URL . '/app/services',
    'Enter Marks' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-pencil-square"></i></div>
  <div>
    <h4>Enter Marks</h4>
    <p>Record marks or grades for each student and subject in the selected exam.
      <?php if (!$isAdmin && $scope === 'own_class'): ?>
      <span class="badge bg-warning text-dark ms-1">Own Section Only</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<div class="card mb-4">
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
                <?php if (!$ex['is_published']): ?> [Draft]<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          <?php else: ?>
            <option disabled>No exams available</option>
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

<!-- ── Exam info strip ─────────────────────────────────────────────────────── -->
<div class="alert alert-info d-flex flex-wrap align-items-center gap-3 py-2 mb-3">
  <div>
    <strong><?= h($exam['label']) ?></strong>
    <span class="text-muted mx-1">·</span>
    <?= h($exam['type_name']) ?>
    <span class="text-muted mx-1">·</span>
    <?= h($exam['class_name'] . ' – ' . $exam['div_name']) ?>
    <?php if ($exam['year_label']): ?>
    <span class="text-muted mx-1">·</span><?= h($exam['year_label']) ?>
    <?php endif; ?>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <?php if ($exam['is_grade_based']): ?>
    <span class="badge bg-info text-dark">Grade-based</span>
    <?php else: ?>
    <span class="badge bg-secondary">Max: <?= h(number_format((float)$exam['max_marks'], 2)) ?></span>
    <span class="badge bg-secondary">Pass: <?= h(number_format((float)$exam['pass_marks'], 2)) ?></span>
    <?php endif; ?>
    <?= $exam['is_published']
        ? '<span class="badge bg-success">Published</span>'
        : '<span class="badge bg-warning text-dark">Draft</span>' ?>
  </div>
</div>

<?php if (!$subjects): ?>
<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
  <div>No subjects are assigned to this section. Please configure section subjects first.</div>
</div>

<?php elseif (!$students): ?>
<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
  <div>No active students found in this section.</div>
</div>

<?php else: ?>

<form method="POST" id="marksForm">
  <?= csrfField() ?>
  <input type="hidden" name="exam_id" value="<?= $filterExamId ?>">

  <div class="card table-card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <i class="bi bi-table me-2 text-primary"></i>
        <strong><?= h($exam['label']) ?></strong>
        <span class="badge bg-secondary ms-2"><?= count($students) ?> students</span>
        <span class="badge bg-info text-dark ms-1"><?= count($subjects) ?> subjects</span>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0" id="marksTable">
        <thead class="table-light">
          <tr>
            <th class="sticky-col" style="min-width:180px;">Student</th>
            <?php foreach ($subjects as $subj): ?>
            <th class="text-center" style="min-width:<?= $exam['is_grade_based'] ? '80px' : '110px' ?>;">
              <div class="fw-semibold small"><?= h($subj['name']) ?></div>
              <?php if ($subj['code']): ?>
              <div class="text-muted" style="font-size:.7rem;"><?= h($subj['code']) ?></div>
              <?php endif; ?>
              <?php if (!$exam['is_grade_based']): ?>
              <div class="text-muted" style="font-size:.68rem;">
                /<?= h(number_format((float)$exam['max_marks'], 0)) ?>
              </div>
              <?php endif; ?>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $stu): ?>
          <?php $stuId = (int)$stu['id']; ?>
          <tr>
            <!-- Student cell -->
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if (!empty($stu['passport_photo'])): ?>
                <img src="<?= h(PHOTO_URL . '/' . $stu['passport_photo']) ?>"
                     alt=""
                     style="width:32px;height:32px;border-radius:6px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                <div class="avatar-circle"
                     style="width:32px;height:32px;font-size:.75rem;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <?= h(mb_strtoupper(mb_substr($stu['first_name'], 0, 1))) ?>
                </div>
                <?php endif; ?>
                <div>
                  <div class="fw-600 small"><?= h($stu['first_name'] . ' ' . $stu['last_name']) ?></div>
                  <?php if ($stu['admission_number']): ?>
                  <div class="text-muted" style="font-size:.7rem;"><?= h($stu['admission_number']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- Per-subject input cells -->
            <?php foreach ($subjects as $subj): ?>
            <?php
              $subjId   = (int)$subj['id'];
              $existing = $marksIndex[$stuId][$subjId] ?? null;
              $isAbsent = $existing ? (int)$existing['is_absent'] : 0;
              $marksVal = $existing ? $existing['marks_obtained'] : null;
              $gradeVal = $existing ? $existing['grade'] : null;
            ?>
            <td class="text-center p-1">
              <?php if ($exam['is_grade_based']): ?>
              <!-- Grade input -->
              <input type="text"
                     class="form-control form-control-sm text-center grade-input"
                     name="grade[<?= $stuId ?>][<?= $subjId ?>]"
                     value="<?= h($gradeVal ?? '') ?>"
                     maxlength="5"
                     placeholder="—"
                     style="width:60px;margin:auto;">
              <input type="hidden"
                     name="marks[<?= $stuId ?>][<?= $subjId ?>]"
                     value="">
              <?php else: ?>
              <!-- Numeric marks + absent checkbox -->
              <input type="number"
                     class="form-control form-control-sm text-center marks-input"
                     name="marks[<?= $stuId ?>][<?= $subjId ?>]"
                     id="marks-<?= $stuId ?>-<?= $subjId ?>"
                     value="<?= $isAbsent ? '' : h((string)($marksVal ?? '')) ?>"
                     min="0" max="<?= h((string)$exam['max_marks']) ?>"
                     step="0.01"
                     placeholder="—"
                     style="width:70px;margin:auto;"
                     data-pass="<?= h((string)$exam['pass_marks']) ?>"
                     data-max="<?= h((string)$exam['max_marks']) ?>"
                     <?= $isAbsent ? 'disabled' : '' ?>>
              <div class="mt-1">
                <label class="d-flex align-items-center justify-content-center gap-1"
                       style="font-size:.72rem;cursor:pointer;">
                  <input type="checkbox"
                         class="form-check-input m-0 absent-cb"
                         name="absent[<?= $stuId ?>][<?= $subjId ?>]"
                         value="1"
                         data-target="marks-<?= $stuId ?>-<?= $subjId ?>"
                         <?= $isAbsent ? 'checked' : '' ?>>
                  Ab
                </label>
              </div>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div><!-- /.table-responsive -->

    <div class="card-footer text-end">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-1"></i>Save Marks
      </button>
    </div>
  </div>
</form>

<?php endif; // subjects + students ?>
<?php elseif ($filterExamId && !$exam): ?>
<div class="alert alert-warning">Exam not found or you do not have access.</div>
<?php endif; ?>

<script>
(function () {
    // ── Absent checkbox: disable/clear marks input ────────────────────────────
    document.addEventListener('change', function (e) {
        var cb = e.target;
        if (!cb.classList.contains('absent-cb')) return;
        var targetId = cb.getAttribute('data-target');
        var input    = targetId ? document.getElementById(targetId) : null;
        if (!input) return;
        if (cb.checked) {
            input.value    = '';
            input.disabled = true;
            input.classList.remove('marks-green', 'marks-red');
        } else {
            input.disabled = false;
        }
    });

    // ── Color marks input green/red vs pass_marks ─────────────────────────────
    function colorMarks(input) {
        var val      = parseFloat(input.value);
        var passVal  = parseFloat(input.getAttribute('data-pass') || '0');
        var maxVal   = parseFloat(input.getAttribute('data-max')  || '100');
        input.classList.remove('marks-green', 'marks-red');
        if (input.disabled || input.value === '') return;
        if (!isNaN(val)) {
            if (val >= passVal) {
                input.style.borderColor  = '#198754';
                input.style.color        = '#198754';
            } else {
                input.style.borderColor  = '#dc3545';
                input.style.color        = '#dc3545';
            }
        } else {
            input.style.borderColor = '';
            input.style.color       = '';
        }
    }

    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('marks-input')) {
            colorMarks(e.target);
        }
    });

    // Color on page load
    document.querySelectorAll('.marks-input').forEach(function (inp) {
        colorMarks(inp);
    });
}());
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
