<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>SportsInfraX – Database Install</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f0f4f8;font-family:Segoe UI,sans-serif;}pre{background:#1e293b;color:#a5f3fc;padding:16px;border-radius:8px;}</style>
</head>
<body>
<div class="container py-5" style="max-width:700px;">
  <div class="text-center mb-4">
    <h2 class="fw-bold" style="color:#0b1f3a;">SportsInfraX</h2>
    <p class="text-muted">Database Installation</p>
  </div>

  <?php
  // Security: remove this file after use!
  $token = $_GET['token'] ?? '';
  if ($token !== 'INSTALL_TOKEN_CHANGE_ME') {
      echo '<div class="alert alert-danger"><strong>Unauthorized.</strong> Add ?token=INSTALL_TOKEN_CHANGE_ME to the URL. Change the token before running.</div>';
      echo '</div></body></html>';
      exit;
  }

  require_once dirname(__DIR__) . '/app/config/database.php';

  $schemaFile = __DIR__ . '/schema.sql';
  if (!file_exists($schemaFile)) {
      echo '<div class="alert alert-danger">schema.sql not found.</div>';
      echo '</div></body></html>';
      exit;
  }

  $sql = file_get_contents($schemaFile);
  $db  = getDB();

  $logs = [];

  try {
      // Split SQL by statement (crude but works for CREATE TABLE + INSERT)
      $statements = array_filter(
          array_map('trim', explode(';', $sql)),
          fn($s) => !empty($s) && !str_starts_with(ltrim($s), '--')
      );

      foreach ($statements as $stmt) {
          if (empty(trim($stmt))) continue;
          try {
              $db->exec($stmt);
              $firstLine = strtok($stmt, "\n");
              $logs[] = ['ok', substr($firstLine, 0, 80)];
          } catch (PDOException $e) {
              if (str_contains($e->getMessage(), 'Duplicate entry')) {
                  $logs[] = ['warn', 'Skipped (already exists): ' . substr($stmt, 0, 80)];
              } else {
                  $logs[] = ['err', 'Error: ' . $e->getMessage()];
              }
          }
      }

      echo '<div class="card shadow-sm mb-4"><div class="card-body">';
      echo '<h5 class="text-success fw-bold mb-3"><i class="bi bi-check-circle-fill me-2"></i>Installation Log</h5>';
      foreach ($logs as [$type, $msg]) {
          $cls = match($type) { 'ok' => 'text-success', 'warn' => 'text-warning', default => 'text-danger' };
          echo "<div class='$cls small mb-1'>$msg</div>";
      }
      echo '</div></div>';

      echo <<<HTML
<div class="card shadow-sm mb-4 border-success">
  <div class="card-body">
    <h5 class="text-success fw-bold">✅ Installation Complete!</h5>
    <p>Default Super Admin credentials:</p>
    <div style="background:#eef4ff;border-left:4px solid #0b5ed7;padding:12px;border-radius:8px;margin-bottom:12px;">
      <strong>Email:</strong> admin@sportsinfrax.com<br>
      <strong>Password:</strong> <code>password</code> (see note below)
    </div>
    <p class="text-danger small"><strong>⚠ Security Notice:</strong> The password hash in schema.sql uses a test hash.
      For a production setup, generate a real hash:<br>
      <code>php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT, ['cost' => 12]);"</code><br>
      Update the <code>users</code> table with the correct hash.</p>
    <p class="small">Also <strong>delete this install.php file</strong> after installation.</p>
    <a href="../app/auth/login.php" class="btn btn-primary">Go to Login</a>
  </div>
</div>
HTML;

  } catch (Exception $e) {
      echo '<div class="alert alert-danger"><strong>Fatal Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
  }
  ?>
</div>
</body>
</html>
