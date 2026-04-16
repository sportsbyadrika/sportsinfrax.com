<?php
require_once dirname(__DIR__) . '/bootstrap.php';

if (isLoggedIn()) { header('Location: ' . dashboardUrl()); exit; }

$db    = getDB();
$step  = $_GET['step'] ?? 'form';   // 'form' | 'verify' | 'done'
$error = '';

// ──────────────────────────────────────────────────────────
// POST: Step 1 → validate form and redirect to verify
// ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    verifyCsrf();

    $fields = ['institution_name', 'spoc_name', 'mobile', 'email', 'address'];
    $data   = [];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
    }

    if (!$data['institution_name']) {
        $error = 'Institution name is required.';
    } elseif (!$data['spoc_name']) {
        $error = 'SPOC / Admin name is required.';
    } elseif (!preg_match('/^[6-9]\d{9}$/', $data['mobile'])) {
        $error = 'Enter a valid 10-digit Indian mobile number.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!$data['address']) {
        $error = 'Address is required.';
    } else {
        // Check email not already a registered admin
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered on the platform.';
        }
    }

    if (!$error) {
        // Store data in session and redirect to verify step (PRG pattern)
        $_SESSION['reg_data'] = $data;
        header('Location: ' . BASE_URL . '/app/register/index.php?step=verify');
        exit;
    }

    // Validation failed – fall through to render form with $error
    $step = 'form';
}

// ──────────────────────────────────────────────────────────
// POST: Step 2 → confirm, create institution, redirect to done
// ──────────────────────────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    verifyCsrf();

    $data = $_SESSION['reg_data'] ?? null;

    if (!$data) {
        setFlash('error', 'Registration session expired. Please fill the form again.');
        header('Location: ' . BASE_URL . '/app/register/index.php');
        exit;
    }

    // Double-check email not already used (guard against back-button resubmit)
    $chk = $db->prepare("SELECT id FROM institution_registrations WHERE email = ? AND status != 'rejected' LIMIT 1");
    $chk->execute([$data['email']]);
    if ($chk->fetch()) {
        unset($_SESSION['reg_data']);
        setFlash('warning', 'This email was already registered. Please log in or use a different email.');
        header('Location: ' . BASE_URL . '/app/auth/login.php');
        exit;
    }

    // Save registration request
    $db->prepare(
        "INSERT INTO institution_registrations (institution_name, spoc_name, mobile, email, address, status)
         VALUES (?,?,?,?,?,'pending')"
    )->execute([
        $data['institution_name'],
        $data['spoc_name'],
        $data['mobile'],
        $data['email'],
        $data['address'],
    ]);
    $regId = (int)$db->lastInsertId();

    // Create institution (pending_profile)
    $db->prepare(
        "INSERT INTO institutions (registration_id, institution_name, address, status)
         VALUES (?,?,?,'pending_profile')"
    )->execute([$regId, $data['institution_name'], $data['address']]);
    $institutionId = (int)$db->lastInsertId();

    // Link back
    $db->prepare("UPDATE institution_registrations SET institution_id = ? WHERE id = ?")
       ->execute([$institutionId, $regId]);

    // Create admin user
    $password = generatePassword(10);
    $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $db->prepare(
        "INSERT INTO users (email, password, role, institution_id, full_name, mobile, is_active)
         VALUES (?,?,'institution_admin',?,?,?,1)"
    )->execute([$data['email'], $hash, $institutionId, $data['spoc_name'], $data['mobile']]);
    $userId = (int)$db->lastInsertId();

    $db->prepare("UPDATE institutions SET admin_id = ? WHERE id = ?")->execute([$userId, $institutionId]);
    $db->prepare("UPDATE institution_registrations SET status = 'converted' WHERE id = ?")->execute([$regId]);

    // Attempt to send welcome email (non-fatal)
    $mailSent = mailWelcome($data['email'], $data['spoc_name'], $data['institution_name'], $password);

    // Store result data in session for the done page
    $_SESSION['reg_done'] = [
        'email'     => $data['email'],
        'name'      => $data['institution_name'],
        'password'  => $password,          // shown on screen if email failed
        'mail_sent' => $mailSent,
    ];
    unset($_SESSION['reg_data']);

    // PRG: redirect to done step (prevents double-submit on refresh)
    header('Location: ' . BASE_URL . '/app/register/index.php?step=done');
    exit;
}

// ──────────────────────────────────────────────────────────
// GET: verify step – ensure session data exists
// ──────────────────────────────────────────────────────────
if ($step === 'verify') {
    if (empty($_SESSION['reg_data'])) {
        // Session expired or user navigated directly
        setFlash('warning', 'Your session has expired. Please fill the form again.');
        header('Location: ' . BASE_URL . '/app/register/index.php');
        exit;
    }
    $d = $_SESSION['reg_data'];
}

// ──────────────────────────────────────────────────────────
// GET: done step – read result from session
// ──────────────────────────────────────────────────────────
if ($step === 'done') {
    $regDone = $_SESSION['reg_done'] ?? null;
    if (!$regDone) {
        // Navigated here without completing registration
        header('Location: ' . BASE_URL . '/app/register/index.php');
        exit;
    }
    $regEmail    = $regDone['email'];
    $regName     = $regDone['name'];
    $regPassword = $regDone['password'];
    $mailSent    = $regDone['mail_sent'];
    unset($_SESSION['reg_done']); // consume once
}

$isForm   = ($step === 'form');
$isVerify = ($step === 'verify');
$isDone   = ($step === 'done');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register Institution | <?= h(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="<?= BASE_URL ?>/app/assets/css/app.css" rel="stylesheet" />
</head>
<body>

<!-- Public Navbar -->
<nav class="app-navbar navbar navbar-expand-lg">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(BASE_URL) ?>">
      <span class="nav-brand-icon"><i class="bi bi-trophy-fill"></i></span>
      <span class="nav-brand-text"><?= h(APP_NAME) ?></span>
    </a>
    <a href="<?= h(BASE_URL . '/app/auth/login.php') ?>" class="btn btn-outline-light btn-sm">Login</a>
  </div>
</nav>

<main class="app-main py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-7 col-md-9">

        <!-- Flash messages -->
        <?= renderFlash() ?>

        <!-- Step Indicator -->
        <div class="step-indicator mb-4">
          <div class="step <?= $isForm ? 'active' : ($isVerify || $isDone ? 'done' : '') ?>">
            <div class="step-circle">1</div>
            <div class="step-label">Registration</div>
          </div>
          <div class="step-line <?= $isVerify || $isDone ? 'done' : '' ?>"></div>
          <div class="step <?= $isVerify ? 'active' : ($isDone ? 'done' : '') ?>">
            <div class="step-circle">2</div>
            <div class="step-label">Verify Details</div>
          </div>
          <div class="step-line <?= $isDone ? 'done' : '' ?>"></div>
          <div class="step <?= $isDone ? 'done' : '' ?>">
            <div class="step-circle">3</div>
            <div class="step-label">Complete</div>
          </div>
        </div>

        <!-- ── STEP 1: Registration Form ────────────────── -->
        <?php if ($isForm): ?>
        <div class="card">
          <div class="card-header">
            <i class="bi bi-building-add me-2 text-primary"></i>Register Your Institution
          </div>
          <div class="card-body p-4">

            <?php if ($error): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
              <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><?= h($error) ?>
            </div>
            <?php endif; ?>

            <p class="text-muted small mb-4">
              Fill in the basic details to register your institution. After verification,
              your admin credentials will be sent to the registered email.
            </p>

            <form method="POST" action="<?= h(BASE_URL . '/app/register/index.php') ?>">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="submit">

              <div class="mb-3">
                <label class="form-label" for="institution_name">
                  Institution Name <span class="required">*</span>
                </label>
                <input type="text" class="form-control" id="institution_name" name="institution_name"
                       value="<?= h($_POST['institution_name'] ?? '') ?>"
                       placeholder="e.g. Green Valley Sports Academy" required autofocus>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label" for="spoc_name">
                    SPOC / Admin Name <span class="required">*</span>
                  </label>
                  <input type="text" class="form-control" id="spoc_name" name="spoc_name"
                         value="<?= h($_POST['spoc_name'] ?? '') ?>"
                         placeholder="Full name of the primary contact" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="mobile">
                    Mobile Number <span class="required">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text">+91</span>
                    <input type="tel" class="form-control" id="mobile" name="mobile"
                           value="<?= h($_POST['mobile'] ?? '') ?>"
                           placeholder="10-digit mobile" pattern="[6-9][0-9]{9}" maxlength="10" required>
                  </div>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label" for="email">
                  Institution Admin Email <span class="required">*</span>
                </label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       placeholder="admin@yourinstitution.com" required>
                <div class="form-text">Login credentials will be sent to this email. This will be your username.</div>
              </div>

              <div class="mb-4">
                <label class="form-label" for="address">
                  Institution Address <span class="required">*</span>
                </label>
                <textarea class="form-control" id="address" name="address" rows="3"
                          placeholder="Full address including city, state and pincode" required><?= h($_POST['address'] ?? '') ?></textarea>
              </div>

              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                  Continue to Verify <i class="bi bi-arrow-right ms-2"></i>
                </button>
                <a href="<?= h(BASE_URL . '/app/auth/login.php') ?>" class="btn btn-outline-secondary">Cancel</a>
              </div>
            </form>
          </div>
        </div>

        <!-- ── STEP 2: Verify ────────────────────────────── -->
        <?php elseif ($isVerify): ?>
        <div class="card">
          <div class="card-header">
            <i class="bi bi-check2-circle me-2 text-success"></i>Verify Your Details
          </div>
          <div class="card-body p-4">
            <p class="text-muted small mb-4">
              Please review the information below carefully. Once confirmed, your institution will be
              created and login credentials will be sent to the registered email.
            </p>

            <div class="verify-card mb-4">
              <div class="verify-row">
                <span class="verify-key">Institution Name</span>
                <span class="verify-val fw-600"><?= h($d['institution_name']) ?></span>
              </div>
              <div class="verify-row">
                <span class="verify-key">SPOC / Admin Name</span>
                <span class="verify-val"><?= h($d['spoc_name']) ?></span>
              </div>
              <div class="verify-row">
                <span class="verify-key">Mobile Number</span>
                <span class="verify-val">+91 <?= h($d['mobile']) ?></span>
              </div>
              <div class="verify-row">
                <span class="verify-key">Admin Email</span>
                <span class="verify-val"><?= h($d['email']) ?></span>
              </div>
              <div class="verify-row">
                <span class="verify-key">Address</span>
                <span class="verify-val"><?= nl2br(h($d['address'])) ?></span>
              </div>
            </div>

            <div class="alert alert-info d-flex align-items-start gap-2 py-2 small">
              <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
              <div>After confirmation, a login password will be emailed to
                <strong><?= h($d['email']) ?></strong>.
                Log in and complete your institution profile to get approved by the Super Admin.
              </div>
            </div>

            <div class="d-flex gap-2 mt-3">
              <form method="POST" action="<?= h(BASE_URL . '/app/register/index.php?step=verify') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-success px-4">
                  <i class="bi bi-check-lg me-2"></i>Confirm &amp; Create Institution
                </button>
              </form>
              <a href="<?= h(BASE_URL . '/app/register/index.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Edit Details
              </a>
            </div>
          </div>
        </div>

        <!-- ── STEP 3: Done ──────────────────────────────── -->
        <?php elseif ($isDone): ?>
        <div class="card text-center">
          <div class="card-body p-5">
            <div style="font-size:4rem;color:#10b981;"><i class="bi bi-check-circle-fill"></i></div>
            <h4 class="fw-bold mt-3">Institution Registered Successfully!</h4>
            <p class="text-muted mb-1">
              <strong><?= h($regName) ?></strong> has been registered on <?= h(APP_NAME) ?>.
            </p>

            <?php if ($mailSent): ?>
            <div class="alert alert-success d-inline-flex align-items-center gap-2 px-4 py-2 small mt-2">
              <i class="bi bi-envelope-check-fill"></i>
              Login credentials sent to <strong><?= h($regEmail) ?></strong>
            </div>
            <?php else: ?>
            <!-- Email could not be sent – show credentials on screen -->
            <div class="alert alert-warning text-start mt-3 small">
              <div class="fw-bold mb-2">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Email delivery failed. Please save these credentials now:
              </div>
              <div style="background:#fff;border-left:4px solid #0b5ed7;padding:12px;border-radius:6px;">
                <div><strong>Username (Email):</strong> <?= h($regEmail) ?></div>
                <div class="mt-1"><strong>Password:</strong>
                  <code class="fs-6 user-select-all"><?= h($regPassword) ?></code>
                </div>
              </div>
              <div class="mt-2 text-muted">
                Please change your password after first login.
              </div>
            </div>
            <?php endif; ?>

            <div class="mt-4">
              <h6 class="fw-bold text-muted mb-3">What's next?</h6>
              <div class="d-flex flex-column gap-2 text-start" style="max-width:380px;margin:0 auto;">
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-primary rounded-circle p-1" style="width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;">1</span>
                  <span class="small">Log in with your credentials</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-primary rounded-circle p-1" style="width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;">2</span>
                  <span class="small">Complete your institution profile (logo, type, registration details)</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-primary rounded-circle p-1" style="width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;">3</span>
                  <span class="small">Wait for Super Admin approval with validity date</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-success rounded-circle p-1" style="width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;"><i class="bi bi-check"></i></span>
                  <span class="small">Start managing members and staff!</span>
                </div>
              </div>
            </div>

            <a href="<?= h(BASE_URL . '/app/auth/login.php') ?>" class="btn btn-primary mt-4 px-4">
              <i class="bi bi-box-arrow-in-right me-2"></i>Proceed to Login
            </a>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</main>

<footer class="app-footer">
  <div class="container text-center">
    <span class="text-muted small">&copy; <?= date('Y') ?> <?= h(APP_NAME) ?> &middot; <?= h(APP_COMPANY) ?></span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
