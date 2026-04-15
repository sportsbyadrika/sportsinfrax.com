<?php
/**
 * SportsInfraX – Helper Functions
 */

// ── Output Sanitisation ────────────────────────────────────
function h(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── CSRF ───────────────────────────────────────────────────
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(419);
        die('<h2>CSRF token mismatch. Please go back and try again.</h2>');
    }
}

// ── Flash Messages ─────────────────────────────────────────
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function renderFlash(): string
{
    $flash = getFlash();
    if (!$flash) return '';

    $map = [
        'success' => 'success',
        'error'   => 'danger',
        'warning' => 'warning',
        'info'    => 'info',
    ];
    $cls  = $map[$flash['type']] ?? 'info';
    $icon = match($cls) {
        'success' => 'bi-check-circle-fill',
        'danger'  => 'bi-exclamation-triangle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        default   => 'bi-info-circle-fill',
    };

    return '<div class="alert alert-' . $cls . ' alert-dismissible fade show d-flex align-items-center" role="alert">'
        . '<i class="bi ' . $icon . ' me-2 flex-shrink-0"></i>'
        . '<span>' . h($flash['message']) . '</span>'
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
        . '</div>';
}

// ── Password ───────────────────────────────────────────────
function generatePassword(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
    $pwd   = '';
    $max   = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, $max)];
    }
    return $pwd;
}

// ── Code Generators ────────────────────────────────────────
function generateMemberCode(int $institutionId): string
{
    $db    = getDB();
    $year  = date('Y');
    $prefix = 'MBRI' . str_pad($institutionId, 3, '0', STR_PAD_LEFT);
    $stmt  = $db->prepare(
        "SELECT COUNT(*) FROM members WHERE institution_id = ? AND YEAR(created_at) = ?"
    );
    $stmt->execute([$institutionId, $year]);
    $seq   = (int)$stmt->fetchColumn() + 1;
    return $prefix . '/' . $year . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function generateMembershipNumber(int $institutionId): string
{
    $db    = getDB();
    $year  = date('Y');
    $stmt  = $db->prepare(
        "SELECT COUNT(*) FROM memberships WHERE institution_id = ? AND YEAR(created_at) = ?"
    );
    $stmt->execute([$institutionId, $year]);
    $seq   = (int)$stmt->fetchColumn() + 1;
    return 'MSH' . str_pad($institutionId, 3, '0', STR_PAD_LEFT) . '/' . $year . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── File Upload ────────────────────────────────────────────
/**
 * @param  array  $file        $_FILES['field']
 * @param  string $destDir     Absolute destination directory
 * @param  array  $allowedMime Allowed MIME types
 * @return string              Stored filename (relative)
 * @throws RuntimeException    On validation/storage failure
 */
function uploadFile(array $file, string $destDir, array $allowedMime = []): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        throw new RuntimeException($errors[$file['error']] ?? 'Upload error.');
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File size exceeds 5 MB limit.');
    }

    // Validate MIME via finfo (not just extension)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($allowedMime && !in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('Invalid file type. Allowed: ' . implode(', ', $allowedMime));
    }

    // Safe filename
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $dest = $destDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    return $safeName;
}

// ── Date Formatting ────────────────────────────────────────
function fmtDate(?string $date, string $format = 'd M Y'): string
{
    if (!$date) return '—';
    $dt = new DateTime($date);
    return $dt->format($format);
}

function membershipStatusBadge(string $endDate): string
{
    $today  = new DateTime();
    $end    = new DateTime($endDate);
    $diff   = (int)$today->diff($end)->format('%R%a');

    if ($diff < 0) {
        return '<span class="badge bg-danger">Expired</span>';
    } elseif ($diff <= 30) {
        return '<span class="badge bg-warning text-dark">Expiring Soon</span>';
    }
    return '<span class="badge bg-success">Active</span>';
}

// ── Pagination Helper ──────────────────────────────────────
function paginate(int $total, int $page, int $perPage, string $baseUrl): string
{
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';

    $html  = '<nav><ul class="pagination pagination-sm flex-wrap mb-0">';
    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
           . '<a class="page-link" href="' . h($baseUrl . '?page=' . ($page - 1)) . '">‹ Prev</a></li>';

    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $html  .= '<li class="page-item' . $active . '">'
                . '<a class="page-link" href="' . h($baseUrl . '?page=' . $i) . '">' . $i . '</a></li>';
    }

    $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '">'
           . '<a class="page-link" href="' . h($baseUrl . '?page=' . ($page + 1)) . '">Next ›</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// ── Mail ───────────────────────────────────────────────────
function sendMail(string $to, string $subject, string $htmlBody): bool
{
    $from    = MAIL_FROM;
    $fromName = MAIL_FROM_NAME;
    $headers  = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: SportsInfraX',
    ]);

    $result = @mail($to, $subject, $htmlBody, $headers);
    if (!$result) {
        error_log("Mail failed: to={$to}, subject={$subject}");
    }
    return $result;
}

function mailWelcome(string $to, string $name, string $institutionName, string $password): bool
{
    $subject = APP_NAME . ' – Your Institution Admin Account';
    $loginUrl = BASE_URL . '/app/auth/login.php';
    $body = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;background:#f8fbff;padding:24px;">
  <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 16px rgba(0,0,0,.07);">
    <div style="text-align:center;margin-bottom:24px;">
      <h2 style="color:#0b1f3a;margin:0;">SportsInfraX</h2>
      <p style="color:#6b7280;font-size:13px;margin:4px 0 0;">Digital OS for Sports Institutions</p>
    </div>
    <p>Hello <strong>{$name}</strong>,</p>
    <p>Your institution <strong>{$institutionName}</strong> has been successfully registered on <strong>SportsInfraX</strong>.</p>
    <p>Your Institution Admin credentials are:</p>
    <div style="background:#eef4ff;border-left:4px solid #0b5ed7;padding:16px;border-radius:8px;margin:16px 0;">
      <p style="margin:4px 0;"><strong>Email:</strong> {$to}</p>
      <p style="margin:4px 0;"><strong>Password:</strong> <code style="font-size:15px;">{$password}</code></p>
    </div>
    <p>Please log in and complete your institution profile to get approved by the platform administrator.</p>
    <div style="text-align:center;margin:28px 0;">
      <a href="{$loginUrl}" style="background:#0b5ed7;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;">Login Now</a>
    </div>
    <p style="font-size:12px;color:#9ca3af;">For security, please change your password after your first login. If you did not register for this platform, please ignore this email.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="font-size:12px;color:#9ca3af;text-align:center;">&copy; SportsInfraX &middot; SportsByA Tech (OPC) Private Limited</p>
  </div>
</body></html>
HTML;
    return sendMail($to, $subject, $body);
}

function mailStaffWelcome(string $to, string $name, string $institutionName, string $password): bool
{
    $subject = APP_NAME . ' – Your Staff Account';
    $loginUrl = BASE_URL . '/app/auth/login.php';
    $body = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;background:#f8fbff;padding:24px;">
  <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 16px rgba(0,0,0,.07);">
    <div style="text-align:center;margin-bottom:24px;">
      <h2 style="color:#0b1f3a;margin:0;">SportsInfraX</h2>
    </div>
    <p>Hello <strong>{$name}</strong>,</p>
    <p>A staff account has been created for you at <strong>{$institutionName}</strong> on SportsInfraX.</p>
    <div style="background:#eef4ff;border-left:4px solid #0b5ed7;padding:16px;border-radius:8px;margin:16px 0;">
      <p style="margin:4px 0;"><strong>Email:</strong> {$to}</p>
      <p style="margin:4px 0;"><strong>Password:</strong> <code style="font-size:15px;">{$password}</code></p>
    </div>
    <div style="text-align:center;margin:28px 0;">
      <a href="{$loginUrl}" style="background:#0b5ed7;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;">Login Now</a>
    </div>
    <p style="font-size:12px;color:#9ca3af;">Please change your password after your first login.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="font-size:12px;color:#9ca3af;text-align:center;">&copy; SportsInfraX &middot; SportsByA Tech (OPC) Private Limited</p>
  </div>
</body></html>
HTML;
    return sendMail($to, $subject, $body);
}

// ── Institution Type Labels ────────────────────────────────
function institutionTypeLabel(string $type): string
{
    $labels = [
        'academy'        => 'Sports Academy',
        'club'           => 'Sports Club',
        'stadium'        => 'Stadium',
        'complex'        => 'Sports Complex',
        'gym'            => 'Gym / Fitness Centre',
        'turf'           => 'Turf / Ground',
        'swimming_pool'  => 'Swimming Pool',
        'training_centre'=> 'Training Centre',
        'association'    => 'Sports Association',
        'other'          => 'Other',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function institutionStatusBadge(string $status): string
{
    $map = [
        'pending_profile'  => ['warning', 'Pending Profile'],
        'pending_approval' => ['info',    'Pending Approval'],
        'active'           => ['success', 'Active'],
        'suspended'        => ['danger',  'Suspended'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', ucfirst($status)];
    return '<span class="badge bg-' . $cls . '">' . $label . '</span>';
}

function paymentStatusBadge(string $status): string
{
    $map = [
        'pending' => 'warning text-dark',
        'partial' => 'info text-dark',
        'paid'    => 'success',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . ucfirst($status) . '</span>';
}
