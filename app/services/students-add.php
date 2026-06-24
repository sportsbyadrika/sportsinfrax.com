<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || getInstitutionCategory($inst['institution_type'] ?? '') !== 'school') {
    setFlash('error', 'Students are only available for school institutions.');
    header('Location: ' . BASE_URL . '/app/services');
    exit;
}

$scope = getModuleScope('students');
if ($scope === 'none') {
    setFlash('error', 'You do not have permission to manage students.');
    header('Location: ' . dashboardUrl());
    exit;
}

$isAdmin = isInstAdmin();
$staffId = !$isAdmin ? authStaffId() : null;

$editId  = (int)($_GET['id'] ?? 0);
$student = null;
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND institution_id = ?");
    $stmt->execute([$editId, $instId]);
    $student = $stmt->fetch();
    if (!$student) {
        setFlash('error', 'Student not found.');
        header('Location: ' . BASE_URL . '/app/services/students');
        exit;
    }
    // Scope check: own_class staff can only edit their own sections
    if ($scope === 'own_class') {
        $myIds = $staffId ? getTeacherSectionIds($staffId, $instId) : [];
        if (!in_array((int)$student['section_id'], $myIds, true)) {
            setFlash('error', 'You can only edit students in your assigned sections.');
            header('Location: ' . BASE_URL . '/app/services/students');
            exit;
        }
    }
}

// Load accessible sections for the dropdown
$secWhere  = 'sec.institution_id = ? AND sec.is_active = 1';
$secParams = [$instId];
if ($scope === 'own_class') {
    $myIds = $staffId ? getTeacherSectionIds($staffId, $instId) : [0];
    if (!$myIds) $myIds = [0];
    $ph        = implode(',', array_fill(0, count($myIds), '?'));
    $secWhere .= " AND sec.id IN ({$ph})";
    $secParams = array_merge($secParams, $myIds);
}
$secStmt = $db->prepare(
    "SELECT sec.id, cls.name AS class_name, dv.name AS division_name,
            ay.label AS year_label
     FROM sections sec
     JOIN classes cls ON cls.id = sec.class_id
     JOIN divisions dv ON dv.id = sec.division_id
     JOIN academic_years ay ON ay.id = sec.academic_year_id
     WHERE {$secWhere}
     ORDER BY ay.is_active DESC, ay.label DESC, cls.numeric_order, cls.name, dv.name"
);
$secStmt->execute($secParams);
$sections = $secStmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $sectionId      = (int)($_POST['section_id']     ?? 0);
    $admissionNo    = trim($_POST['admission_number'] ?? '');
    $rollNo         = (int)($_POST['roll_number']     ?? 0) ?: null;
    $firstName      = trim($_POST['first_name']       ?? '');
    $lastName       = trim($_POST['last_name']        ?? '');
    $dob            = $_POST['date_of_birth']          ?? null;
    $gender         = $_POST['gender']                 ?? null;
    $bloodGroup     = $_POST['blood_group']            ?? null;
    $fatherName     = trim($_POST['father_name']       ?? '');
    $motherName     = trim($_POST['mother_name']       ?? '');
    $guardianName   = trim($_POST['guardian_name']     ?? '');
    $guardianRel    = trim($_POST['guardian_relation'] ?? '');
    $mobile         = trim($_POST['mobile']            ?? '');
    $altMobile      = trim($_POST['alternate_mobile']  ?? '');
    $email          = strtolower(trim($_POST['email'] ?? ''));
    $address        = trim($_POST['address']           ?? '');
    $city           = trim($_POST['city']              ?? '');
    $state          = trim($_POST['state']             ?? '');
    $pincode        = trim($_POST['pincode']           ?? '');
    $prevSchool     = trim($_POST['previous_school']   ?? '');
    $admissionDate  = $_POST['admission_date']         ?? null;

    // Validation
    if (!$sectionId)   $error = 'Please select a section.';
    elseif (!$firstName) $error = 'First name is required.';
    elseif (!$lastName)  $error = 'Last name is required.';
    elseif (!$admissionNo) $error = 'Admission number is required.';

    // Scope check on selected section
    if (!$error && $scope === 'own_class') {
        $myIds = $staffId ? getTeacherSectionIds($staffId, $instId) : [];
        if (!in_array($sectionId, $myIds, true)) {
            $error = 'You can only add students to your assigned sections.';
        }
    }

    // Photo
    $photoName = $student['passport_photo'] ?? null;
    if (!$error) {
        $croppedData = $_POST['cropped_photo_data'] ?? '';
        if ($croppedData !== '' && str_starts_with($croppedData, 'data:image/')) {
            try {
                $photoName = saveCroppedPhoto($croppedData, PHOTO_DIR);
            } catch (RuntimeException $e) {
                $error = 'Photo save failed: ' . $e->getMessage();
            }
        } elseif (!empty($_FILES['passport_photo']['name'])) {
            try {
                $photoName = uploadFile($_FILES['passport_photo'], PHOTO_DIR, ALLOWED_IMAGES);
            } catch (RuntimeException $e) {
                $error = 'Photo upload failed: ' . $e->getMessage();
            }
        }
    }

    if (!$error) {
        $fields = [
            'section_id'       => $sectionId,
            'admission_number' => $admissionNo,
            'roll_number'      => $rollNo,
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'date_of_birth'    => $dob ?: null,
            'gender'           => $gender ?: null,
            'blood_group'      => $bloodGroup ?: null,
            'passport_photo'   => $photoName,
            'father_name'      => $fatherName ?: null,
            'mother_name'      => $motherName ?: null,
            'guardian_name'    => $guardianName ?: null,
            'guardian_relation'=> $guardianRel ?: null,
            'mobile'           => $mobile ?: null,
            'alternate_mobile' => $altMobile ?: null,
            'email'            => $email ?: null,
            'address'          => $address ?: null,
            'city'             => $city ?: null,
            'state'            => $state ?: null,
            'pincode'          => $pincode ?: null,
            'previous_school'  => $prevSchool ?: null,
            'admission_date'   => $admissionDate ?: null,
        ];

        try {
            if ($editId) {
                $setClauses = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
                $db->prepare(
                    "UPDATE students SET {$setClauses} WHERE id = ? AND institution_id = ?"
                )->execute([...array_values($fields), $editId, $instId]);
                setFlash('success', 'Student updated successfully.');
            } else {
                $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
                $phs  = implode(', ', array_fill(0, count($fields), '?'));
                $db->prepare(
                    "INSERT INTO students (institution_id, {$cols}, created_by)
                     VALUES (?, {$phs}, ?)"
                )->execute([$instId, ...array_values($fields), authId()]);
                setFlash('success', 'Student added successfully.');
            }
            header('Location: ' . BASE_URL . '/app/services/students');
            exit;
        } catch (Exception $e) {
            $error = str_contains($e->getMessage(), 'uq_student_admission')
                ? 'This admission number already exists for your institution.'
                : 'Database error. Please try again.';
        }
    }
}

$isEdit    = (bool)$editId;
$pageTitle = $isEdit ? 'Edit Student' : 'Add Student';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Students'  => BASE_URL . '/app/services/students',
    $pageTitle  => '',
];
$useCropper = true;
require_once APP_ROOT . '/includes/header.php';

$v = fn(string $field) => h($student[$field] ?? $_POST[$field] ?? '');
?>

<div class="row justify-content-center">
  <div class="col-lg-10">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-mortarboard me-2 text-primary"></i><?= h($pageTitle) ?>
      </div>
      <div class="card-body p-4">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>

          <!-- ── Section 1: Enrollment ─────────────────────── -->
          <h6 class="fw-700 text-primary border-bottom pb-2 mb-3">Enrollment Details</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Section <span class="required">*</span></label>
              <select class="form-select" name="section_id" required>
                <option value="">Select section…</option>
                <?php
                $lastYear = null;
                foreach ($sections as $sec):
                  if ($sec['year_label'] !== $lastYear):
                    if ($lastYear !== null) echo '</optgroup>';
                    echo '<optgroup label="' . h($sec['year_label']) . '">';
                    $lastYear = $sec['year_label'];
                  endif;
                  $selId = (int)($student['section_id'] ?? $_POST['section_id'] ?? 0);
                ?>
                <option value="<?= $sec['id'] ?>" <?= $selId === (int)$sec['id'] ? 'selected' : '' ?>>
                  <?= h($sec['class_name'] . ' – ' . $sec['division_name']) ?>
                </option>
                <?php endforeach; ?>
                <?php if ($lastYear !== null) echo '</optgroup>'; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Admission Number <span class="required">*</span></label>
              <input type="text" class="form-control" name="admission_number"
                     value="<?= $v('admission_number') ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Roll Number</label>
              <input type="number" class="form-control" name="roll_number"
                     value="<?= $v('roll_number') ?>" min="1" max="9999">
            </div>
            <div class="col-md-2">
              <label class="form-label">Admission Date</label>
              <input type="date" class="form-control" name="admission_date"
                     value="<?= $v('admission_date') ?>">
            </div>
          </div>

          <!-- ── Section 2: Personal ──────────────────────── -->
          <h6 class="fw-700 text-primary border-bottom pb-2 mb-3">Personal Details</h6>
          <div class="row g-3 mb-4">
            <!-- Photo -->
            <div class="col-md-2 d-flex flex-column align-items-center gap-2">
              <label class="form-label w-100 text-center">Photo</label>
              <div style="width:80px;height:100px;border-radius:8px;overflow:hidden;background:#e5e7eb;position:relative;">
                <?php if (!empty($student['passport_photo'])): ?>
                <img id="photoPreview" src="<?= h(PHOTO_URL . '/' . $student['passport_photo']) ?>"
                     alt="" style="width:100%;height:100%;object-fit:cover;display:block;">
                <div id="photoPlaceholder" style="display:none;"></div>
                <?php else: ?>
                <img id="photoPreview" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                <div id="photoPlaceholder"
                     style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9ca3af;">
                  <i class="bi bi-person-fill" style="font-size:2rem;"></i>
                </div>
                <?php endif; ?>
              </div>
              <label class="btn btn-outline-primary btn-sm w-100" for="photoInput">
                <i class="bi bi-camera me-1"></i>Photo
              </label>
              <input type="file" id="photoInput" name="passport_photo"
                     accept="image/jpeg,image/png,image/webp" class="d-none">
              <div class="text-muted text-center" style="font-size:.7rem;">JPG/PNG · 5MB max</div>
            </div>
            <div class="col-md-10">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">First Name <span class="required">*</span></label>
                  <input type="text" class="form-control" name="first_name"
                         value="<?= $v('first_name') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Last Name <span class="required">*</span></label>
                  <input type="text" class="form-control" name="last_name"
                         value="<?= $v('last_name') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Date of Birth</label>
                  <input type="date" class="form-control" name="date_of_birth"
                         value="<?= $v('date_of_birth') ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Gender</label>
                  <select class="form-select" name="gender">
                    <option value="">Select…</option>
                    <?php foreach (['male'=>'Male','female'=>'Female','other'=>'Other'] as $val=>$lbl): ?>
                    <option value="<?= $val ?>" <?= ($student['gender'] ?? $_POST['gender'] ?? '') === $val ? 'selected' : '' ?>>
                      <?= $lbl ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Blood Group</label>
                  <select class="form-select" name="blood_group">
                    <option value="">Select…</option>
                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                    <option value="<?= $bg ?>" <?= ($student['blood_group'] ?? $_POST['blood_group'] ?? '') === $bg ? 'selected' : '' ?>>
                      <?= $bg ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Previous School</label>
                  <input type="text" class="form-control" name="previous_school"
                         value="<?= $v('previous_school') ?>" placeholder="Name of previous school">
                </div>
              </div>
            </div>
          </div>

          <!-- ── Section 3: Parent / Guardian ─────────────── -->
          <h6 class="fw-700 text-primary border-bottom pb-2 mb-3">Parent / Guardian Details</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Father's Name</label>
              <input type="text" class="form-control" name="father_name" value="<?= $v('father_name') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Mother's Name</label>
              <input type="text" class="form-control" name="mother_name" value="<?= $v('mother_name') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Guardian Name</label>
              <input type="text" class="form-control" name="guardian_name" value="<?= $v('guardian_name') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Guardian Relation</label>
              <input type="text" class="form-control" name="guardian_relation"
                     value="<?= $v('guardian_relation') ?>" placeholder="e.g. Uncle, Grandparent">
            </div>
            <div class="col-md-3">
              <label class="form-label">Mobile</label>
              <div class="input-group">
                <span class="input-group-text">+91</span>
                <input type="tel" class="form-control" name="mobile"
                       value="<?= $v('mobile') ?>" maxlength="10" pattern="[6-9][0-9]{9}">
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Alternate Mobile</label>
              <div class="input-group">
                <span class="input-group-text">+91</span>
                <input type="tel" class="form-control" name="alternate_mobile"
                       value="<?= $v('alternate_mobile') ?>" maxlength="10">
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" value="<?= $v('email') ?>">
            </div>
          </div>

          <!-- ── Section 4: Address ────────────────────────── -->
          <h6 class="fw-700 text-primary border-bottom pb-2 mb-3">Address</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">Address</label>
              <textarea class="form-control" name="address" rows="2"><?= $v('address') ?></textarea>
            </div>
            <div class="col-md-6">
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">City</label>
                  <input type="text" class="form-control" name="city" value="<?= $v('city') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">State</label>
                  <input type="text" class="form-control" name="state" value="<?= $v('state') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Pincode</label>
                  <input type="text" class="form-control" name="pincode"
                         value="<?= $v('pincode') ?>" maxlength="6">
                </div>
              </div>
            </div>
          </div>

          <?php include APP_ROOT . '/includes/photo_cropper.php'; ?>

          <div class="d-flex gap-2 mt-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i>
              <?= $isEdit ? 'Save Changes' : 'Add Student' ?>
            </button>
            <a href="<?= h(BASE_URL . '/app/services/students') ?>"
               class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>

      </div>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
