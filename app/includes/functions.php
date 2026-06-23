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

// ── Passport Photo (Cropper) ───────────────────────────────

/**
 * Decode a base64 data URI image, resize it to 413×531 px via GD, and
 * save as JPEG to $destDir. Returns the stored filename (basename only).
 */
function saveCroppedPhoto(string $dataUri, string $destDir): string
{
    if (!preg_match('#^data:image/(jpeg|png|webp|gif);base64,(.+)$#s', $dataUri, $m)) {
        throw new RuntimeException('Invalid image data URI.');
    }
    $imageData = base64_decode($m[2]);
    if ($imageData === false || strlen($imageData) === 0) {
        throw new RuntimeException('Failed to decode image data.');
    }
    if (strlen($imageData) > MAX_FILE_SIZE) {
        throw new RuntimeException('Cropped image exceeds 5 MB limit.');
    }

    $src = @imagecreatefromstring($imageData);
    if (!$src) throw new RuntimeException('Cannot process image data.');

    $dst = imagecreatetruecolor(413, 531);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, 413, 531, imagesx($src), imagesy($src));
    imagedestroy($src);

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        imagedestroy($dst);
        throw new RuntimeException('Could not create photo directory.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.jpg';
    if (!imagejpeg($dst, $destDir . '/' . $fileName, 92)) {
        imagedestroy($dst);
        throw new RuntimeException('Failed to save cropped photo.');
    }
    imagedestroy($dst);
    return $fileName;
}

// ── Attachment Store ───────────────────────────────────────

/**
 * Validate, move, and record a file upload in the attachments table.
 * Returns the new attachment row ID.
 */
function uploadAttachment(
    array $file,
    string $entityType,
    int $entityId,
    string $fileCategory,
    ?int $instId,
    bool $isSensitive = false
): int {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code ' . $file['error'] . '.');
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File size exceeds 5 MB limit.');
    }

    $allowedMime = in_array($fileCategory, ['photo', 'logo'], true) ? ALLOWED_IMAGES : ALLOWED_DOCS;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('Invalid file type.');
    }

    $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'bin';
    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $subPath    = 'attachments/' . $fileCategory;
    $destDir    = UPLOAD_ROOT . '/' . $subPath;

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $storedName)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    $db = getDB();
    $db->prepare(
        "INSERT INTO attachments
         (entity_type, entity_id, institution_id, file_category, original_name,
          stored_name, storage_path, mime_type, file_size, is_sensitive, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $entityType,
        $entityId,
        $instId,
        $fileCategory,
        $file['name'],
        $storedName,
        $subPath . '/' . $storedName,
        $mime,
        $file['size'],
        $isSensitive ? 1 : 0,
        isLoggedIn() ? authId() : null,
    ]);

    return (int)$db->lastInsertId();
}

// ── ID Masking ─────────────────────────────────────────────

/**
 * Mask an identity number for display: keep last 4 characters visible,
 * replace the rest with X. Aadhaar (12 numeric digits) formats as XXXX-XXXX-1234.
 */
function maskIdNumber(?string $value): string
{
    if ($value === null || $value === '') return '—';
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value));
    $len   = strlen($clean);
    if ($len <= 4) return $value;
    // Aadhaar: exactly 12 numeric digits
    if ($len === 12 && ctype_digit($clean)) {
        return 'XXXX-XXXX-' . substr($clean, -4);
    }
    return str_repeat('X', $len - 4) . substr($clean, -4);
}

/**
 * Returns the value to write to field_change_log — masking sensitive fields.
 */
function maskFieldForLog(string $fieldName, ?string $value): ?string
{
    if ($value === null) return null;
    if ($fieldName === 'id_number') return maskIdNumber($value);
    return $value;
}

// ── Audit Field Change Log ─────────────────────────────────

/**
 * Compare $oldData and $newData row arrays; for every tracked field that
 * changed, write one row to field_change_log with masked sensitive values.
 */
function logFieldChanges(
    string $entityType,
    int $entityId,
    ?int $instId,
    array $oldData,
    array $newData
): void {
    $tracked = AUDIT_TRACKED_FIELDS[$entityType] ?? [];
    if (!$tracked) return;

    $db        = getDB();
    $changedBy = isLoggedIn() ? authId() : null;
    $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $db->prepare(
        "INSERT INTO field_change_log
         (entity_type, entity_id, institution_id, changed_by, field_name, old_value, new_value, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($tracked as $field) {
        $oldVal = isset($oldData[$field]) ? (string)$oldData[$field] : null;
        $newVal = isset($newData[$field]) ? (string)$newData[$field] : null;
        // Normalise empty string to null for comparison
        if ($oldVal === '') $oldVal = null;
        if ($newVal === '') $newVal = null;
        if ($oldVal === $newVal) continue;
        $stmt->execute([
            $entityType,
            $entityId,
            $instId,
            $changedBy,
            $field,
            maskFieldForLog($field, $oldVal),
            maskFieldForLog($field, $newVal),
            $ip,
        ]);
    }
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

/**
 * Send an HTML email.
 *
 * Priority:
 *  1. Native SMTP via stream_socket_client (no dependencies) when SMTP_HOST + SMTP_USER are set
 *  2. PHP mail() if available and SMTP not configured
 *  3. error_log fallback so the app never crashes on missing mail config
 */
function sendMail(string $to, string $subject, string $htmlBody): bool
{
    // ── Option 1: SMTP ────────────────────────────────────
    if (SMTP_HOST !== '' && SMTP_USER !== '') {
        return _smtpSend(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_SECURE,
                         MAIL_FROM, MAIL_FROM_NAME, $to, $subject, $htmlBody);
    }

    // ── Option 2: PHP mail() if available ─────────────────
    if (function_exists('mail')) {
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'X-Mailer: SportsInfraX',
        ]);
        $result = @mail($to, $subject, $htmlBody, $headers);
        if (!$result) {
            error_log("SportsInfraX mail(): failed to={$to} subject={$subject}");
        }
        return (bool)$result;
    }

    // ── Option 3: Log only (no crash) ─────────────────────
    error_log("SportsInfraX mail not sent (no driver configured). to={$to} subject={$subject}");
    return false;
}

/**
 * Native PHP SMTP mailer — no external libraries required.
 * Supports STARTTLS (port 587) and direct SSL (port 465).
 */
function _smtpSend(
    string $host, int $port, string $user, string $pass, string $secure,
    string $from, string $fromName,
    string $to, string $subject, string $htmlBody
): bool {
    $errPrefix = "SportsInfraX SMTP ({$host}:{$port})";

    // Build SSL context (allow self-signed certs common on shared hosting)
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    // For port 465 use ssl:// wrapper (implicit TLS)
    $addr = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $fp = @stream_socket_client($addr, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("{$errPrefix}: connect failed – {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, 30);

    // Read one response line (possibly multi-line)
    $read = function () use ($fp): string {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;  // "XYZ " = last line
        }
        return $resp;
    };

    // Send a command and return the server response
    $cmd = function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $ehlo = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $read();                               // greeting
    $cmd("EHLO {$ehlo}");                  // initial EHLO

    // STARTTLS upgrade for port 587
    if ($secure === 'tls') {
        $resp = $cmd('STARTTLS');
        if (strpos($resp, '220') === false) {
            error_log("{$errPrefix}: STARTTLS rejected – {$resp}");
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log("{$errPrefix}: TLS handshake failed");
            fclose($fp);
            return false;
        }
        $cmd("EHLO {$ehlo}");              // re-identify after TLS
    }

    // AUTH LOGIN
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $authResp = $cmd(base64_encode($pass));
    if (substr(trim($authResp), 0, 3) !== '235') {
        error_log("{$errPrefix}: AUTH failed – {$authResp}");
        fclose($fp);
        return false;
    }

    $cmd("MAIL FROM:<{$from}>");
    $rcpt = $cmd("RCPT TO:<{$to}>");
    if (substr(trim($rcpt), 0, 1) !== '2') {
        error_log("{$errPrefix}: RCPT TO rejected – {$rcpt}");
        fclose($fp);
        return false;
    }

    $cmd('DATA');

    // Encode subject and From header as RFC 2047 UTF-8
    $encSubject  = '=?UTF-8?B?' . base64_encode($subject)  . '?=';
    $encFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $msgId       = '<' . uniqid('si', true) . '@' . $ehlo . '>';
    $date        = date('r');

    $headers  = "Date: {$date}\r\n";
    $headers .= "Message-ID: {$msgId}\r\n";
    $headers .= "From: {$encFromName} <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$encSubject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";
    $headers .= "X-Mailer: SportsInfraX\r\n";

    // Dot-stuff body lines per RFC 5321 §4.5.2, then send as base64
    $body = chunk_split(base64_encode($htmlBody));

    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");

    $dataResp = $read();
    $cmd('QUIT');
    fclose($fp);

    if (substr(trim($dataResp), 0, 3) !== '250') {
        error_log("{$errPrefix}: DATA rejected – {$dataResp}");
        return false;
    }

    return true;
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

function mailStaffWelcome(string $to, string $name, string $institutionName, string $password, ?string $username = null): bool
{
    $subject      = APP_NAME . ' – Your Staff Account';
    $loginUrl     = BASE_URL . '/app/auth/login';
    $usernameRow  = $username
        ? "<p style=\"margin:4px 0;\"><strong>Username:</strong> <code style=\"font-size:15px;\">{$username}</code> <span style=\"font-size:11px;color:#6b7280;\">(can also login with email)</span></p>"
        : '';
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
      {$usernameRow}
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

// ── Institution Type Helpers ───────────────────────────────
// Returns all active types as full row arrays keyed by value.
// Cached per request via static variable.
function _institutionTypeRows(): array
{
    static $rows = null;
    if ($rows === null) {
        try {
            $data = getDB()->query(
                "SELECT value, label, category FROM institution_types
                  WHERE is_active = 1 ORDER BY sort_order, label"
            )->fetchAll();
            $rows = array_column($data, null, 'value');
        } catch (Exception $e) {
            $rows = [];
        }
    }
    return $rows;
}

// Returns [value => label] map for use in dropdowns.
function getInstitutionTypes(): array
{
    return array_map(fn($r) => $r['label'], _institutionTypeRows());
}

// Returns the category ('association'|'school'|'sports_club'|'general')
// for a given institution type value.
function getInstitutionCategory(string $type): string
{
    $rows = _institutionTypeRows();
    return $rows[$type]['category'] ?? 'general';
}

function institutionTypeLabel(string $type): string
{
    $rows = _institutionTypeRows();
    return $rows[$type]['label'] ?? ucwords(str_replace('_', ' ', $type));
}

// ── Menu Registry ─────────────────────────────────────────

/**
 * Returns active menu_items rows for a hub page, filtered by
 * institution category, user role, and (for staff) permissions.
 */
function getMenuItems(string $parentMenu, string $category, string $role, ?int $userId = null, ?int $instId = null): array
{
    static $cache = [];
    $key = "{$parentMenu}|{$category}|{$role}|{$userId}|{$instId}";
    if (isset($cache[$key])) return $cache[$key];

    $roleClause = ($role === 'institution_admin')
        ? "required_role IN ('institution_admin','any')"
        : "required_role IN ('staff','any')";

    try {
        $stmt = getDB()->prepare(
            "SELECT * FROM menu_items
              WHERE parent_menu = ?
                AND (applies_to_category IS NULL OR applies_to_category = ?)
                AND is_active = 1
                AND ({$roleClause})
              ORDER BY sort_order, label"
        );
        $stmt->execute([$parentMenu, $category]);
        $items = $stmt->fetchAll();
    } catch (Exception $e) {
        return $cache[$key] = [];
    }

    if ($role === 'staff' && $userId && $instId) {
        $perms = _staffPermSet($userId, $instId);
        $items = array_values(array_filter(
            $items,
            fn($item) => !$item['required_permission'] || isset($perms[$item['required_permission']])
        ));
    }

    return $cache[$key] = $items;
}

function _staffPermSet(int $userId, int $instId): array
{
    static $cache = [];
    $key = "{$userId}:{$instId}";
    if (!isset($cache[$key])) {
        try {
            $stmt = getDB()->prepare(
                "SELECT module, action, scope FROM staff_permissions WHERE user_id = ? AND institution_id = ?"
            );
            $stmt->execute([$userId, $instId]);
            $perms = [];
            foreach ($stmt->fetchAll() as $row) {
                $perms[$row['module'] . '.' . $row['action']][] = $row['scope'];
            }
            $cache[$key] = $perms;
        } catch (Exception $e) {
            $cache[$key] = [];
        }
    }
    return $cache[$key];
}

/**
 * Check if a staff user has a specific module.action permission.
 * When $scope is 'all', the user must have the 'all' scope explicitly.
 * For any other scope, having 'all' also satisfies the check.
 */
function hasStaffPermission(int $userId, int $instId, string $moduleAction, string $scope = 'all'): bool
{
    $perms = _staffPermSet($userId, $instId);
    if (!isset($perms[$moduleAction])) return false;
    $granted = $perms[$moduleAction];
    if ($scope === 'all') return in_array('all', $granted, true);
    return in_array('all', $granted, true) || in_array($scope, $granted, true);
}

/**
 * Renders a single hub-page card from a menu_items row or a
 * coming-soon descriptor array with the same keys.
 *
 * Keys used: icon, gradient, label, description, route (null = coming soon),
 *            required_role ('institution_admin' triggers Admin Only badge)
 */
function renderMenuHubCard(array $item): string
{
    $gradient  = $item['gradient']    ?? 'linear-gradient(135deg,#64748b,#94a3b8)';
    $icon      = $item['icon']        ?? 'bi-circle-fill';
    $title     = $item['label']       ?? '';
    $desc      = $item['description'] ?? '';
    $route     = $item['route']       ?? null;
    $adminOnly = ($item['required_role'] ?? 'any') === 'institution_admin';
    $disabled  = ($route === null);

    $out  = '<div class="col-sm-6 col-lg-4">';
    $out .= '<div class="card h-100 menu-card' . ($disabled ? ' disabled-card' : '') . '">';
    $out .= '<div class="card-body d-flex flex-column p-4 position-relative">';
    if ($disabled) {
        $out .= '<span class="badge bg-secondary position-absolute top-0 end-0 m-3">Coming Soon</span>';
    }
    if ($adminOnly) {
        $out .= '<span class="menu-card-role-badge">Admin Only</span>';
    }
    $out .= '<div class="menu-card-icon" style="background:' . h($gradient) . ';">';
    $out .= '<i class="bi ' . h($icon) . '"></i></div>';
    $out .= '<h5 class="fw-bold mt-3 mb-1">' . h($title) . '</h5>';
    $out .= '<p class="text-muted small flex-grow-1">' . h($desc) . '</p>';
    if ($route) {
        $out .= '<a href="' . h(BASE_URL . $route) . '" class="btn btn-primary mt-3">';
        $out .= '<i class="bi bi-arrow-right me-1"></i>Open</a>';
    } else {
        $out .= '<button class="btn btn-secondary mt-3" disabled>Coming Soon</button>';
    }
    $out .= '</div></div></div>';
    return $out;
}

// ─────────────────────────────────────────────────────────

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
