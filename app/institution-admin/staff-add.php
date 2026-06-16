<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// Verify institution status
$instStmt = $db->prepare("SELECT status, institution_name FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || !in_array($inst['status'], ['pending_approval','active'])) {
    setFlash('error', 'Institution not ready for staff management.');
    header('Location: ' . BASE_URL . '/app/institution-admin/profile');
    exit;
}

$editId = (int)($_GET['id'] ?? 0);
$staff  = null;

if ($editId) {
    $stmt = $db->prepare(
        "SELECT s.*, u.full_name, u.email, u.mobile, u.is_active
         FROM staff s JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND s.institution_id = ?"
    );
    $stmt->execute([$editId, $instId]);
    $staff = $stmt->fetch();
    if (!$staff) { setFlash('error', 'Staff not found.'); header('Location: ' . BASE_URL . '/app/institution-admin/staff'); exit; }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName   = trim($_POST['full_name']   ?? '');
    $email      = trim($_POST['email']       ?? '');
    $mobile     = trim($_POST['mobile']      ?? '');
    $staffType  = $_POST['staff_type']       ?? 'other';
    $department = trim($_POST['department']  ?? '');
    $joiningDate= $_POST['joining_date']     ?? null;

    if (!$fullName)  $error = 'Full name is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Valid email is required.';
    elseif (!preg_match('/^[6-9]\d{9}$/', $mobile))     $error = 'Valid 10-digit mobile is required.';

    if (!$error) {
        if ($editId) {
            // Update user info
            $db->prepare("UPDATE users SET full_name = ?, mobile = ? WHERE id = ?")
               ->execute([$fullName, $mobile, $staff['user_id']]);
            // Update staff info
            $db->prepare("UPDATE staff SET staff_type = ?, department = ?, joining_date = ? WHERE id = ?")
               ->execute([$staffType, $department ?: null, $joiningDate ?: null, $editId]);
            setFlash('success', 'Staff member updated.');
        } else {
            // Check email not taken
            $check = $db->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'This email is already registered on the platform.';
            } else {
                $password = generatePassword(10);
                $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                $db->prepare("INSERT INTO users (email, password, role, institution_id, full_name, mobile, is_active)
                              VALUES (?,?,'staff',?,?,?,1)")
                   ->execute([$email, $hash, $instId, $fullName, $mobile]);
                $userId = (int)$db->lastInsertId();

                $db->prepare("INSERT INTO staff (user_id, institution_id, staff_type, department, joining_date, created_by)
                              VALUES (?,?,?,?,?,?)")
                   ->execute([$userId, $instId, $staffType, $department ?: null, $joiningDate ?: null, authId()]);

                mailStaffWelcome($email, $fullName, $inst['institution_name'], $password);
                setFlash('success', "Staff member $fullName added. Login credentials sent to $email.");
            }
        }

        if (!$error) {
            header('Location: ' . BASE_URL . '/app/institution-admin/staff');
            exit;
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
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-person-badge me-2 text-primary"></i><?= h($pageTitle) ?>
      </div>
      <div class="card-body p-4">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>

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
              <?php if ($isEdit): ?>
              <div class="form-text">Email cannot be changed.</div>
              <?php else: ?>
              <div class="form-text">Login credentials will be sent to this email.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Staff Type <span class="required">*</span></label>
              <select class="form-select" name="staff_type">
                <?php
                $types = ['manager'=>'Manager','coach'=>'Coach','trainer'=>'Trainer','receptionist'=>'Receptionist',
                          'accounts'=>'Accounts','operations'=>'Operations','other'=>'Other'];
                foreach ($types as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= ($staff['staff_type'] ?? $_POST['staff_type'] ?? '') === $val ? 'selected' : '' ?>>
                  <?= h($label) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Department</label>
              <input type="text" class="form-control" name="department"
                     value="<?= h($staff['department'] ?? $_POST['department'] ?? '') ?>"
                     placeholder="e.g. Training, Front Office">
            </div>
            <div class="col-md-6">
              <label class="form-label">Joining Date</label>
              <input type="date" class="form-control" name="joining_date"
                     value="<?= h($staff['joining_date'] ?? $_POST['joining_date'] ?? date('Y-m-d')) ?>">
            </div>
          </div>

          <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Update Staff' : 'Add Staff & Send Login' ?>
            </button>
            <a href="<?= h(BASE_URL . '/app/institution-admin/staff') ?>" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
