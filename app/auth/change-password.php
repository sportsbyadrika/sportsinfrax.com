<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireLogin();

$pageTitle   = 'Change Password';
$breadcrumbs = ['Home' => dashboardUrl(), 'Change Password' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    $db   = getDB();
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([authId()]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, authId()]);
        setFlash('success', 'Password changed successfully!');
        header('Location: ' . dashboardUrl());
        exit;
    }
}

require_once APP_ROOT . '/includes/header.php';
?>
<div class="row justify-content-center">
  <div class="col-lg-5 col-md-7">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-key-fill text-primary"></i> Change Password
      </div>
      <div class="card-body p-4">

        <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>

          <div class="mb-3">
            <label class="form-label" for="current_password">Current Password <span class="required">*</span></label>
            <input type="password" class="form-control" id="current_password" name="current_password" required>
          </div>

          <div class="mb-3">
            <label class="form-label" for="new_password">New Password <span class="required">*</span></label>
            <input type="password" class="form-control" id="new_password" name="new_password"
                   minlength="8" required>
            <div class="form-text">Minimum 8 characters.</div>
          </div>

          <div class="mb-4">
            <label class="form-label" for="confirm_password">Confirm New Password <span class="required">*</span></label>
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i>Update Password
            </button>
            <a href="<?= h(dashboardUrl()) ?>" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
