<?php
require_once dirname(__DIR__) . '/bootstrap.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . dashboardUrl());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            loginUser($user);
            setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
            header('Location: ' . dashboardUrl());
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | <?= h(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="<?= BASE_URL ?>/app/assets/css/app.css" rel="stylesheet" />
</head>
<body>
<div class="auth-page">
  <div class="auth-card">

    <!-- Header -->
    <div class="auth-card-header">
      <div class="auth-logo"><i class="bi bi-trophy-fill"></i></div>
      <h4 class="fw-bold mb-1"><?= h(APP_NAME) ?></h4>
      <p class="mb-0 opacity-75 small"><?= h(APP_TAGLINE) ?></p>
    </div>

    <!-- Body -->
    <div class="auth-card-body">
      <h5 class="fw-bold mb-1 text-center">Sign in to your account</h5>
      <p class="text-muted text-center small mb-4">Enter your credentials to continue</p>

      <?php if ($error): ?>
      <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
        <span class="small"><?= h($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="" novalidate>
        <?= csrfField() ?>

        <div class="mb-3">
          <label class="form-label" for="email">Email Address</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= h($_POST['email'] ?? '') ?>"
                   placeholder="you@institution.com" required autofocus>
          </div>
        </div>

        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0" for="password">Password</label>
            <a href="<?= h(BASE_URL . '/app/auth/forgot-password') ?>"
               class="small text-primary text-decoration-none">Forgot password?</a>
          </div>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password"
                   placeholder="Your password" required>
            <button class="btn btn-outline-secondary" type="button" id="togglePwd"
                    title="Show/Hide Password">
              <i class="bi bi-eye" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
          <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
        </button>
      </form>

      <hr class="my-4">

      <p class="text-center text-muted small mb-0">
        New institution?
        <a href="<?= h(BASE_URL . '/app/register') ?>" class="text-primary fw-600 text-decoration-none">
          Register here
        </a>
      </p>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.getElementById('togglePwd')?.addEventListener('click', function () {
    const inp  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
      inp.type = 'text';
      icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      inp.type = 'password';
      icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  });
</script>
</body>
</html>
