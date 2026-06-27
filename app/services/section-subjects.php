<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Section subjects are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/services');
    exit;
}

// ── Academic years ──────────────────────────────────────────────────────────
$ayStmt = $db->prepare(
    "SELECT * FROM academic_years WHERE institution_id = ? ORDER BY is_active DESC, label DESC"
);
$ayStmt->execute([$instId]);
$allYears = $ayStmt->fetchAll();

$activeYear = null;
foreach ($allYears as $ay) {
    if ($ay['is_active']) { $activeYear = $ay; break; }
}

$selectedYearId    = (int)($_GET['academic_year_id'] ?? ($activeYear['id'] ?? 0));
$selectedSectionId = (int)($_GET['section_id']       ?? 0);

// Build redirect base for PRG
$redirectBase = BASE_URL . '/app/services/section-subjects'
    . '?academic_year_id=' . $selectedYearId
    . ($selectedSectionId ? '&section_id=' . $selectedSectionId : '');

// ── Sections for the selected year ─────────────────────────────────────────
$sectionOptions = [];
if ($selectedYearId) {
    $secStmt = $db->prepare(
        "SELECT sec.id, cls.name AS class_name, dv.name AS div_name, sec.class_id
         FROM sections sec
         JOIN classes   cls ON cls.id = sec.class_id
         JOIN divisions dv  ON dv.id  = sec.division_id
         WHERE sec.institution_id = ? AND sec.academic_year_id = ? AND sec.is_active = 1
         ORDER BY cls.numeric_order, cls.name, dv.name"
    );
    $secStmt->execute([$instId, $selectedYearId]);
    $sectionOptions = $secStmt->fetchAll();
}

// ── Staff list ──────────────────────────────────────────────────────────────
$staffStmt = $db->prepare(
    "SELECT s.id, u.full_name FROM staff s JOIN users u ON u.id = s.user_id
     WHERE s.institution_id = ? AND s.is_active = 1 ORDER BY u.full_name"
);
$staffStmt->execute([$instId]);
$staffList = $staffStmt->fetchAll();

// ── Validate selected section belongs to this institution + year ────────────
$selectedSection = null;
if ($selectedSectionId && $selectedYearId) {
    foreach ($sectionOptions as $sec) {
        if ((int)$sec['id'] === $selectedSectionId) {
            $selectedSection = $sec;
            break;
        }
    }
    if (!$selectedSection) {
        $selectedSectionId = 0;
    }
}

// ── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Re-read redirect params from POST (filter selects are in GET; POST carries hidden fields)
    $postYearId    = (int)($_POST['academic_year_id'] ?? $selectedYearId);
    $postSectionId = (int)($_POST['section_id']       ?? $selectedSectionId);

    $postRedirect = BASE_URL . '/app/services/section-subjects'
        . '?academic_year_id=' . $postYearId
        . ($postSectionId ? '&section_id=' . $postSectionId : '');

    // Verify the section belongs to this institution
    $secVerify = null;
    if ($postSectionId) {
        $svStmt = $db->prepare(
            "SELECT sec.id, sec.class_id FROM sections sec
             WHERE sec.id = ? AND sec.institution_id = ?"
        );
        $svStmt->execute([$postSectionId, $instId]);
        $secVerify = $svStmt->fetch();
    }

    if ($action === 'assign' && $secVerify) {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $staffId   = (int)($_POST['staff_id']   ?? 0) ?: null;

        if (!$subjectId) {
            setFlash('error', 'Please select a subject to assign.');
            header('Location: ' . $postRedirect);
            exit;
        }

        // Verify subject belongs to this institution and is active
        $subjCheck = $db->prepare(
            "SELECT id FROM subjects WHERE id = ? AND institution_id = ? AND is_active = 1"
        );
        $subjCheck->execute([$subjectId, $instId]);
        if (!$subjCheck->fetch()) {
            setFlash('error', 'Invalid subject selected.');
            header('Location: ' . $postRedirect);
            exit;
        }

        try {
            $db->prepare(
                "INSERT INTO section_subjects (section_id, subject_id, staff_id)
                 VALUES (?,?,?)"
            )->execute([$postSectionId, $subjectId, $staffId]);
            setFlash('success', 'Subject assigned to section.');
        } catch (Exception $e) {
            setFlash('error', 'Subject is already assigned to this section.');
        }
        header('Location: ' . $postRedirect);
        exit;

    } elseif ($action === 'update' && $secVerify) {
        $ssId      = (int)($_POST['ss_id']    ?? 0);
        $staffId   = (int)($_POST['staff_id'] ?? 0) ?: null;
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        // Verify the section_subject row belongs to this section
        $db->prepare(
            "UPDATE section_subjects
             SET staff_id = ?, is_active = ?
             WHERE id = ? AND section_id = ?"
        )->execute([$staffId, $isActive, $ssId, $postSectionId]);
        setFlash('success', 'Assignment updated.');
        header('Location: ' . $postRedirect);
        exit;

    } elseif ($action === 'remove' && $secVerify) {
        $ssId = (int)($_POST['ss_id'] ?? 0);
        $db->prepare(
            "DELETE FROM section_subjects WHERE id = ? AND section_id = ?"
        )->execute([$ssId, $postSectionId]);
        setFlash('success', 'Subject removed from section.');
        header('Location: ' . $postRedirect);
        exit;
    }

    // Fallback
    header('Location: ' . $postRedirect);
    exit;
}

// ── Load assignment data when a section is selected ─────────────────────────
$assignments      = [];  // current section_subjects rows
$assignedSubjIds  = [];
$availableSubjects = []; // subjects eligible for this section but not yet assigned

if ($selectedSection) {
    $classId = (int)$selectedSection['class_id'];

    // Current assignments
    $assStmt = $db->prepare(
        "SELECT ss.id, ss.subject_id, ss.staff_id, ss.is_active,
                subj.name AS subject_name, subj.code AS subject_code,
                u.full_name AS staff_name
         FROM section_subjects ss
         JOIN subjects subj ON subj.id = ss.subject_id
         LEFT JOIN staff st ON st.id = ss.staff_id
         LEFT JOIN users u  ON u.id  = st.user_id
         WHERE ss.section_id = ?
         ORDER BY subj.sort_order, subj.name"
    );
    $assStmt->execute([$selectedSectionId]);
    $assignments = $assStmt->fetchAll();

    foreach ($assignments as $a) {
        $assignedSubjIds[] = (int)$a['subject_id'];
    }

    // Available subjects (active, relevant to this section's class, not yet assigned)
    if ($assignedSubjIds) {
        $ph = implode(',', array_fill(0, count($assignedSubjIds), '?'));
        $availStmt = $db->prepare(
            "SELECT id, name, code FROM subjects
             WHERE institution_id = ? AND is_active = 1
               AND (class_id IS NULL OR class_id = ?)
               AND id NOT IN ({$ph})
             ORDER BY sort_order, name"
        );
        $availStmt->execute(array_merge([$instId, $classId], $assignedSubjIds));
    } else {
        $availStmt = $db->prepare(
            "SELECT id, name, code FROM subjects
             WHERE institution_id = ? AND is_active = 1
               AND (class_id IS NULL OR class_id = ?)
             ORDER BY sort_order, name"
        );
        $availStmt->execute([$instId, $classId]);
    }
    $availableSubjects = $availStmt->fetchAll();
}

$pageTitle   = 'Assign Subjects';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Services' => BASE_URL . '/app/services', 'Assign Subjects' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-journal-plus-fill"></i></div>
  <div>
    <h4>Assign Subjects to Sections</h4>
    <p>Select a section to manage its subject list and assign teachers to each subject.</p>
  </div>
</div>

<?php if (!$allYears): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i>
  <div>
    <strong>No academic years set up.</strong><br>
    <a href="<?= h(BASE_URL . '/app/settings/academic-years') ?>" class="alert-link">
      Go to Settings → Academic Years
    </a> before assigning subjects.
  </div>
</div>
<?php else: ?>

<!-- ── Filter Bar ──────────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end" id="filterForm">
      <div class="col-sm-5 col-md-4">
        <label class="form-label fw-600 mb-1">Academic Year</label>
        <select class="form-select" name="academic_year_id" id="yearSelect"
                onchange="document.getElementById('filterForm').submit()">
          <option value="">Select year…</option>
          <?php foreach ($allYears as $ay): ?>
          <option value="<?= $ay['id'] ?>"
            <?= (int)$ay['id'] === $selectedYearId ? 'selected' : '' ?>>
            <?= h($ay['label']) ?><?= $ay['is_active'] ? ' (Active)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-5 col-md-4">
        <label class="form-label fw-600 mb-1">Section</label>
        <select class="form-select" name="section_id" <?= !$selectedYearId ? 'disabled' : '' ?>>
          <option value="">Select section…</option>
          <?php foreach ($sectionOptions as $sec): ?>
          <option value="<?= $sec['id'] ?>"
            <?= (int)$sec['id'] === $selectedSectionId ? 'selected' : '' ?>>
            <?= h($sec['class_name']) ?> – <?= h($sec['div_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2 col-md-2">
        <button type="submit" class="btn btn-primary w-100">
          <i class="bi bi-search me-1"></i>Load
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($selectedYearId && !$sectionOptions): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>No active sections found for this academic year.
  <a href="<?= h(BASE_URL . '/app/services/sections') ?>" class="alert-link">Create sections first.</a>
</div>
<?php endif; ?>

<?php if ($selectedSection): ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h5 class="mb-0">
    <i class="bi bi-diagram-3 me-2 text-primary"></i>
    <?= h($selectedSection['class_name']) ?> – <?= h($selectedSection['div_name']) ?>
  </h5>
  <?php
    foreach ($allYears as $ay) {
        if ((int)$ay['id'] === $selectedYearId) {
            echo '<span class="badge bg-primary bg-opacity-15 text-primary">' . h($ay['label']) . '</span>';
            break;
        }
    }
  ?>
  <span class="badge bg-secondary ms-1"><?= count($assignments) ?> subject<?= count($assignments) !== 1 ? 's' : '' ?> assigned</span>
</div>

<div class="row g-4">

  <!-- ── Left: Available Subjects ──────────────────────────────────────────── -->
  <div class="col-lg-4 col-xl-3">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-list-check me-2 text-success"></i>Available Subjects
        <span class="badge bg-secondary ms-1"><?= count($availableSubjects) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($availableSubjects): ?>
        <ul class="list-group list-group-flush" style="max-height:420px;overflow-y:auto;">
          <?php foreach ($availableSubjects as $subj): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
            <div>
              <span class="fw-600 small"><?= h($subj['name']) ?></span>
              <?php if ($subj['code']): ?>
              <span class="badge bg-light text-secondary ms-1 border"><?= h($subj['code']) ?></span>
              <?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="text-center text-muted py-4 small">
          <i class="bi bi-check2-all d-block fs-3 mb-2 text-success"></i>
          All eligible subjects are assigned.
        </div>
        <?php endif; ?>
      </div>

      <?php if ($availableSubjects): ?>
      <!-- Assign Form -->
      <div class="card-footer bg-light">
        <p class="small fw-600 mb-2"><i class="bi bi-plus-circle me-1 text-primary"></i>Assign a Subject</p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="academic_year_id" value="<?= $selectedYearId ?>">
          <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">

          <div class="mb-2">
            <select class="form-select form-select-sm" name="subject_id" required>
              <option value="">Select subject…</option>
              <?php foreach ($availableSubjects as $subj): ?>
              <option value="<?= $subj['id'] ?>">
                <?= h($subj['name']) ?><?= $subj['code'] ? ' (' . h($subj['code']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <select class="form-select form-select-sm" name="staff_id">
              <option value="">Unassigned</option>
              <?php foreach ($staffList as $sf): ?>
              <option value="<?= $sf['id'] ?>"><?= h($sf['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-plus-circle me-1"></i>Assign Subject
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Right: Assignment Grid ────────────────────────────────────────────── -->
  <div class="col-lg-8 col-xl-9">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2 text-primary"></i>Assigned Subjects</span>
        <?php if (!$availableSubjects && !$assignments): ?>
        <small class="text-muted">No subjects defined yet.
          <a href="<?= h(BASE_URL . '/app/settings/subjects') ?>">Add subjects</a> first.
        </small>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($assignments): ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="min-width:160px">Subject</th>
                <th>Code</th>
                <th style="min-width:200px">Assigned Teacher</th>
                <th>Active</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($assignments as $asgn): ?>
              <tr>
                <td class="fw-600"><?= h($asgn['subject_name']) ?></td>
                <td class="small text-muted">
                  <?= $asgn['subject_code'] ? h($asgn['subject_code']) : '—' ?>
                </td>
                <td>
                  <form method="POST" class="d-flex gap-2 align-items-center update-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="academic_year_id" value="<?= $selectedYearId ?>">
                    <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                    <input type="hidden" name="ss_id" value="<?= $asgn['id'] ?>">
                    <select class="form-select form-select-sm" name="staff_id"
                            onchange="this.closest('form').submit()">
                      <option value="">Unassigned</option>
                      <?php foreach ($staffList as $sf): ?>
                      <option value="<?= $sf['id'] ?>"
                        <?= (int)$sf['id'] === (int)$asgn['staff_id'] ? 'selected' : '' ?>>
                        <?= h($sf['full_name']) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <!-- is_active is submitted only when teacher changes; toggle handled separately below -->
                    <?php if ($asgn['is_active']): ?>
                    <input type="hidden" name="is_active" value="1">
                    <?php endif; ?>
                  </form>
                </td>
                <td>
                  <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="academic_year_id" value="<?= $selectedYearId ?>">
                    <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                    <input type="hidden" name="ss_id" value="<?= $asgn['id'] ?>">
                    <input type="hidden" name="staff_id" value="<?= (int)$asgn['staff_id'] ?>">
                    <div class="form-check form-switch mb-0">
                      <input class="form-check-input" type="checkbox" role="switch"
                             name="is_active" value="1"
                             <?= $asgn['is_active'] ? 'checked' : '' ?>
                             onchange="this.closest('form').submit()"
                             title="<?= $asgn['is_active'] ? 'Click to deactivate' : 'Click to activate' ?>">
                    </div>
                  </form>
                </td>
                <td>
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="academic_year_id" value="<?= $selectedYearId ?>">
                    <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                    <input type="hidden" name="ss_id" value="<?= $asgn['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-outline-danger btn-icon"
                            title="Remove"
                            data-bs-toggle="tooltip"
                            data-confirm="Remove '<?= h(addslashes($asgn['subject_name'])) ?>' from this section?">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-5">
          <i class="bi bi-journal-plus"></i>
          <h6>No subjects assigned yet</h6>
          <p class="small">Use the panel on the left to assign subjects to this section.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$availableSubjects && $assignments): ?>
    <!-- All subjects assigned — show inline add anyway if there are unassigned ones elsewhere -->
    <?php endif; ?>

    <!-- Quick-add form if available subjects exist but rendered below on large screens -->
    <?php if ($availableSubjects): ?>
    <div class="card mt-4 d-lg-none">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>Assign a Subject
      </div>
      <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="academic_year_id" value="<?= $selectedYearId ?>">
          <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
          <div class="col-sm-5">
            <label class="form-label small">Subject</label>
            <select class="form-select form-select-sm" name="subject_id" required>
              <option value="">Select…</option>
              <?php foreach ($availableSubjects as $subj): ?>
              <option value="<?= $subj['id'] ?>">
                <?= h($subj['name']) ?><?= $subj['code'] ? ' (' . h($subj['code']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-5">
            <label class="form-label small">Teacher</label>
            <select class="form-select form-select-sm" name="staff_id">
              <option value="">Unassigned</option>
              <?php foreach ($staffList as $sf): ?>
              <option value="<?= $sf['id'] ?>"><?= h($sf['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">
              <i class="bi bi-plus-circle me-1"></i>Assign
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /col right -->
</div><!-- /row -->

<?php elseif ($selectedYearId): ?>
<div class="alert alert-secondary d-flex align-items-center gap-3">
  <i class="bi bi-arrow-up-circle fs-4 flex-shrink-0"></i>
  <div>Select a <strong>section</strong> above to view and manage its subject assignments.</div>
</div>
<?php else: ?>
<div class="alert alert-secondary d-flex align-items-center gap-3">
  <i class="bi bi-calendar2 fs-4 flex-shrink-0"></i>
  <div>Select an <strong>academic year</strong> to get started.</div>
</div>
<?php endif; ?>

<?php endif; // has years ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
