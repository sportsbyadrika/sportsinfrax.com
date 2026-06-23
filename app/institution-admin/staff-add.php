<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT status, institution_name, institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || !in_array($inst['status'], ['pending_approval','active'])) {
    setFlash('error', 'Institution not ready for staff management.');
    header('Location: ' . BASE_URL . '/app/institution-admin/profile');
    exit;
}
$isSchool = (getInstitutionCategory($inst['institution_type'] ?? '') === 'school');

$editId = (int)($_GET['id'] ?? 0);
$staff  = null;

if ($editId) {
    $stmt = $db->prepare(
        "SELECT s.*, u.full_name, u.email, u.username, u.mobile, u.is_active
         FROM staff s JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND s.institution_id = ?"
    );
    $stmt->execute([$editId, $instId]);
    $staff = $stmt->fetch();
    if (!$staff) {
        setFlash('error', 'Staff not found.');
        header('Location: ' . BASE_URL . '/app/institution-admin/staff');
        exit;
    }
}

// School role options
$schRoles = [];
if ($isSchool) {
    $srStmt = $db->prepare(
        "SELECT id, label FROM sch_staff_roles
         WHERE (institution_id = ? OR institution_id IS NULL) AND is_active = 1
         ORDER BY sort_order, label"
    );
    $srStmt->execute([$instId]);
    $schRoles = $srStmt->fetchAll();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName    = trim($_POST['full_name']   ?? '');
    $email       = strtolower(trim($_POST['email']    ?? ''));
    $username    = strtolower(trim($_POST['username'] ?? ''));
    $mobile      = trim($_POST['mobile']      ?? '');
    $staffType   = $isSchool ? 'other' : ($_POST['staff_type'] ?? 'other');
    $schRoleId   = $isSchool ? ((int)($_POST['sch_role_id'] ?? 0) ?: null) : null;
    $department  = trim($_POST['department']  ?? '');
    $joiningDate = $_POST['joining_date']     ?? null;

    // Validation
    if (!$fullName) {
        $error = 'Full name is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid email address is required.';
    } elseif (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        $error = 'A valid 10-digit mobile number is required.';
    } elseif ($username !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]{1,48}[a-z0-9]$/i', $username)) {
        $error = 'Username must be 3–50 characters: letters, digits, dots, hyphens, underscores only.';
    } elseif ($isSchool && !$schRoleId) {
        $error = 'Please select a staff role.';
    }

    if (!$error) {
        if ($editId) {
            if ($username !== '') {
                $uChk = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $uChk->execute([$username, $staff['user_id']]);
                if ($uChk->fetch()) $error = 'This username is already taken.';
            }
        } else {
            $eChk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $eChk->execute([$email]);
            if ($eChk->fetch()) {
                $error = 'This email is already registered on the platform.';
            } elseif ($username !== '') {
                $uChk = $db->prepare("SELECT id FROM users WHERE username = ?");
                $uChk->execute([$username]);
                if ($uChk->fetch()) $error = 'This username is already taken.';
            }
        }
    }

    // Photo handling
    $photoName   = $staff['passport_photo'] ?? null;
    $croppedData = $_POST['cropped_photo_data'] ?? '';
    if (!$error) {
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
        $db->beginTransaction();
        try {
            if ($editId) {
                $db->prepare(
                    "UPDATE users SET full_name = ?, mobile = ?, username = ? WHERE id = ?"
                )->execute([$fullName, $mobile, $username ?: null, $staff['user_id']]);

                $db->prepare(
                    "UPDATE staff SET staff_type = ?, sch_role_id = ?, department = ?,
                                     joining_date = ?, passport_photo = ?
                     WHERE id = ?"
                )->execute([$staffType, $schRoleId, $department ?: null,
                            $joiningDate ?: null, $photoName, $editId]);

                $db->commit();
                setFlash('success', 'Staff member updated successfully.');
            } else {
                $password = generatePassword(10);
                $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                $db->prepare(
                    "INSERT INTO users (email, username, password, role, institution_id, full_name, mobile, is_active)
                     VALUES (?,?,'staff',?,?,?,1)"
                )->execute([$email, $username ?: null, $hash, $instId, $fullName, $mobile]);
                $userId = (int)$db->lastInsertId();

                $db->prepare(
                    "INSERT INTO staff
                     (user_id, institution_id, staff_type, sch_role_id, department,
                      joining_date, passport_photo, is_active, created_by)
                     VALUES (?,?,?,?,?,?,?,1,?)"
                )->execute([$userId, $instId, $staffType, $schRoleId,
                            $department ?: null, $joiningDate ?: null, $photoName, authId()]);

                $db->commit();
                mailStaffWelcome($email, $fullName, $inst['institution_name'], $password, $username ?: null);
                setFlash('success', "Staff member {$fullName} added. Credentials sent to {$email}.");
            }

            header('Location: ' . BASE_URL . '/app/institution-admin/staff');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Database error. Please try again.';
        }
    }
}

$isEdit      = (bool)$editId;
$pageTitle   = $isEdit ? 'Edit Staff Member' : 'Add Staff Member';
$breadcrumbs = [
    'Dashboard' => BASE_URL . '/app/institution-admin/dashboard',
    'Staff'     => BASE_URL . '/app/institution-admin/staff',
    $pageTitle  => '',
];
$useCropper = true;
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-9">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-person-badge me-2 text-primary"></i><?= h($pageTitle) ?>
        <?php if ($isSchool): ?>
        <span class="badge bg-info text-dark ms-2" style="font-size:.72rem;">School Institution</span>
        <?php endif; ?>
      </div>
      <div class="card-body p-4">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>

          <div class="row g-4">

            <!-- Left: Photo -->
            <div class="col-md-3 d-flex flex-column align-items-center gap-2">
              <label class="form-label w-100 text-center">Passport Photo</label>
              <div style="width:96px;height:120px;border-radius:8px;overflow:hidden;
                          background:#e5e7eb;position:relative;">
                <?php if (!empty($staff['passport_photo'])): ?>
                <img id="photoPreview"
                     src="<?= h(PHOTO_URL . '/' . $staff['passport_photo']) ?>"
                     alt="Photo" style="width:100%;height:100%;object-fit:cover;display:block;">
                <div id="photoPlaceholder" style="display:none;"></div>
                <?php else: ?>
                <img id="photoPreview" src="" alt="Photo Preview"
                     style="width:100%;height:100%;object-fit:cover;display:none;">
                <div id="photoPlaceholder"
                     style="width:100%;height:100%;display:flex;align-items:center;
                            justify-content:center;color:#9ca3af;">
                  <i class="bi bi-person-fill" style="font-size:2.5rem;"></i>
                </div>
                <?php endif; ?>
              </div>
              <label class="btn btn-outline-primary btn-sm w-100" for="photoInput">
                <i class="bi bi-camera me-1"></i><?= $isEdit ? 'Change Photo' : 'Add Photo' ?>
              </label>
              <input type="file" id="photoInput" name="passport_photo"
                     accept="image/jpeg,image/png,image/webp" class="d-none">
              <div class="text-muted text-center" style="font-size:.72rem;">
                JPG / PNG / WebP<br>Max 5 MB
              </div>
            </div>

            <!-- Right: Fields -->
            <div class="col-md-9">
              <div class="row g-3">

                <div class="col-md-6">
                  <label class="form-label">Full Name <span class="required">*</span></label>
                  <input type="text" class="form-control" name="full_name"
                         value="<?= h($staff['full_name'] ?? $_POST['full_name'] ?? '') ?>" required>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Mobile <span class="required">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text">+91</span>
                    <input type="tel" class="form-control" name="mobile"
                           value="<?= h($staff['mobile'] ?? $_POST['mobile'] ?? '') ?>"
                           pattern="[6-9][0-9]{9}" maxlength="10" required>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Email <span class="required">*</span></label>
                  <input type="email" class="form-control" name="email"
                         value="<?= h($staff['email'] ?? $_POST['email'] ?? '') ?>"
                         <?= $isEdit ? 'disabled' : 'required' ?>>
                  <div class="form-text">
                    <?= $isEdit ? 'Email cannot be changed.' : 'Login credentials will be sent here.' ?>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Username
                    <span class="text-muted" style="font-size:.78rem;">
                      (<?= $isSchool ? 'recommended' : 'optional' ?>)
                    </span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-at"></i></span>
                    <input type="text" class="form-control" name="username"
                           value="<?= h($staff['username'] ?? $_POST['username'] ?? '') ?>"
                           placeholder="e.g. john.teacher"
                           autocomplete="off">
                  </div>
                  <div class="form-text">3–50 chars · letters, digits, dots, hyphens, underscores.</div>
                </div>

                <?php if ($isSchool): ?>
                <div class="col-md-6">
                  <label class="form-label">Staff Role <span class="required">*</span></label>
                  <select class="form-select" name="sch_role_id" required>
                    <option value="">Select role…</option>
                    <?php foreach ($schRoles as $sr): ?>
                    <option value="<?= $sr['id'] ?>"
                      <?= ((int)($staff['sch_role_id'] ?? $_POST['sch_role_id'] ?? 0)) === (int)$sr['id'] ? 'selected' : '' ?>>
                      <?= h($sr['label']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php else: ?>
                <div class="col-md-6">
                  <label class="form-label">Staff Type <span class="required">*</span></label>
                  <select class="form-select" name="staff_type">
                    <?php
                    $types = ['manager'=>'Manager','coach'=>'Coach','trainer'=>'Trainer',
                              'receptionist'=>'Receptionist','accounts'=>'Accounts',
                              'operations'=>'Operations','other'=>'Other'];
                    foreach ($types as $val => $lbl): ?>
                    <option value="<?= h($val) ?>"
                      <?= ($staff['staff_type'] ?? $_POST['staff_type'] ?? '') === $val ? 'selected' : '' ?>>
                      <?= h($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>

                <div class="col-md-6">
                  <label class="form-label">Department</label>
                  <input type="text" class="form-control" name="department"
                         value="<?= h($staff['department'] ?? $_POST['department'] ?? '') ?>"
                         placeholder="e.g. Science, Accounts, Front Office">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Joining Date</label>
                  <input type="date" class="form-control" name="joining_date"
                         value="<?= h($staff['joining_date'] ?? $_POST['joining_date'] ?? date('Y-m-d')) ?>">
                </div>

              </div>
            </div>
          </div>

          <?php include APP_ROOT . '/includes/photo_cropper.php'; ?>

          <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i>
              <?= $isEdit ? 'Save Changes' : 'Add Staff & Send Login' ?>
            </button>
            <a href="<?= h(BASE_URL . '/app/institution-admin/staff') ?>"
               class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
