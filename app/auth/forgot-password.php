<?php
require_once dirname(__DIR__) . '/bootstrap.php';

if (isLoggedIn()) { header('Location: ' . dashboardUrl()); exit; }

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always show success to prevent email enumeration
        if ($user) {
            $newPwd  = generatePassword(10);
            $hash    = password_hash($newPwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);

            $subject  = APP_NAME . ' – Password Reset';
            $appName  = APP_NAME;
            $loginUrl = BASE_URL . '/app/auth/login.php';
            $body = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;padding:24px;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 4px 16px rgba(0,0,0,.07);">
    <h2 style="color:#0b1f3a;">{$appName}</h2>
    <p>Hello <strong>{$user['full_name']}</strong>,</p>
    <p>A password reset was requested for your account. Your new temporary password is:</p>
    <div style="background:#eef4ff;border-left:4px solid #0b5ed7;padding:14px;border-radius:8px;margin:16px 0;">
      <code style="font-size:16px;">{$newPwd}</code>
    </div>
    <p>Please <a href="{$loginUrl}">login</a> and change your password immediately.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">
    <p style="font-size:12px;color:#9ca3af;">&copy; SportsInfraX &middot; SportsByA Tech</p>
  </div>
</body></html>
HTML;
            sendMail($email, $subject, $body);
        }
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password | <?= h(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="<?= BASE_URL ?>/app/assets/css/app.css" rel="stylesheet" />
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-card-header">
      <div class="auth-logo"><i class="bi bi-trophy-fill"></i></div>
      <h4 class="fw-bold mb-1"><?= h(APP_NAME) ?></h4>
      <p class="mb-0 opacity-75 small">Password Recovery</p>
    </div>
    <div class="auth-card-body">
      <?php if ($sent): ?>
        <div class="text-center py-2">
          <div style="font-size:3rem;color:#10b981;"><i class="bi bi-envelope-check-fill"></i></div>
          <h5 class="fw-bold mt-3">Check your email</h5>
          <p class="text-muted small">If that email is registered, we've sent a temporary password. Please check your inbox (and spam folder).</p>
          <a href="<?= h(BASE_URL . '/app/auth/login.php') ?>" class="btn btn-primary mt-2 w-100">Back to Login</a>
        </div>
      <?php else: ?>
        <h5 class="fw-bold mb-1 text-center">Forgot your password?</h5>
        <p class="text-muted text-center small mb-4">Enter your registered email and we'll send a temporary password.</p>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <div class="mb-4">
            <label class="form-label" for="email">Email Address</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" class="form-control" id="email" name="email"
                     value="<?= h($_POST['email'] ?? '') ?>" required autofocus>
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100 py-2">
            <i class="bi bi-send me-2"></i>Send Reset Password
          </button>
        </form>

        <p class="text-center text-muted small mt-4 mb-0">
          <a href="<?= h(BASE_URL . '/app/auth/login.php') ?>" class="text-primary text-decoration-none">
            ← Back to Login
          </a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
