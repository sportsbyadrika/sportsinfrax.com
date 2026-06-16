<?php
/**
 * SportsInfraX – Application Configuration
 */

// ── Application ────────────────────────────────────────────
define('APP_NAME',    'SportsInfraX');
define('APP_TAGLINE', 'Digital OS for Sports Institutions');
define('APP_COMPANY', 'SportsByA Tech (OPC) Private Limited');
define('APP_EMAIL',   'info@sportsinfrax.com');

// Base URL – auto-detected; override with APP_BASE_URL env var if needed
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Derive web root up to "sportsinfrax.com" portion
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base   = '';
    if (preg_match('#^(.*?/sportsinfrax\.com)#', $script, $m)) {
        $base = $m[1];
    }
    define('BASE_URL', $scheme . '://' . $host . $base);
}

// ── Filesystem Paths ───────────────────────────────────────
define('APP_ROOT',     realpath(dirname(__DIR__))); // /path/to/sportsinfrax.com/app
define('UPLOAD_ROOT',  APP_ROOT . '/uploads');
define('LOGO_DIR',     UPLOAD_ROOT . '/logos');
define('PHOTO_DIR',    UPLOAD_ROOT . '/photos');
define('PAYMENT_DIR',  UPLOAD_ROOT . '/payments');
define('DOC_DIR',      UPLOAD_ROOT . '/documents');

define('LOGO_URL',     BASE_URL . '/app/uploads/logos');
define('PHOTO_URL',    BASE_URL . '/app/uploads/photos');
define('PAYMENT_URL',  BASE_URL . '/app/uploads/payments');
define('DOC_URL',      BASE_URL . '/app/uploads/documents');

// ── Upload Limits & Allowed Types ─────────────────────────
define('MAX_FILE_SIZE',   5 * 1024 * 1024); // 5 MB
define('ALLOWED_IMAGES',  ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_DOCS',    ['application/pdf', 'image/jpeg', 'image/png']);

// ── Session ────────────────────────────────────────────────
define('SESSION_LIFETIME', 7200); // 2 hours

// ── Mail Configuration ─────────────────────────────────────
// Load local SMTP credentials if the file exists (not committed to git).
// Edit app/config/mail.local.php on the server with your real SMTP details.
$_localMail = __DIR__ . '/mail.local.php';
if (file_exists($_localMail)) { require_once $_localMail; }
unset($_localMail);

define('MAIL_FROM',      getenv('MAIL_FROM')      ?: APP_EMAIL);
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: APP_NAME);

define('SMTP_HOST',   getenv('SMTP_HOST')   ?: '');          // e.g. mail.sportsinfrax.com
define('SMTP_PORT',   (int)(getenv('SMTP_PORT')   ?: 587));
define('SMTP_USER',   getenv('SMTP_USER')   ?: '');          // e.g. noreply@sportsinfrax.com
define('SMTP_PASS',   getenv('SMTP_PASS')   ?: '');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');       // 'tls' | 'ssl' | ''

// ── Timezone ───────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');
