<?php
/**
 * SportsInfraX – Secure Attachment Gate
 * Serves files from attachments table that have is_sensitive = 1.
 * Requires valid session and matching institution_id.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Not found.'); }

$db   = getDB();
$role = authRole();

$stmt = $db->prepare("SELECT * FROM attachments WHERE id = ? AND is_sensitive = 1");
$stmt->execute([$id]);
$att = $stmt->fetch();

if (!$att) { http_response_code(404); exit('File not found.'); }

// super_admin sees all; others must belong to the same institution
if ($role !== 'super_admin') {
    $instId = authInstId();
    if (!$instId || (int)$att['institution_id'] !== $instId) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$fullPath = UPLOAD_ROOT . '/' . $att['storage_path'];
if (!file_exists($fullPath)) { http_response_code(404); exit('File missing on disk.'); }

$mime = $att['mime_type'] ?: 'application/octet-stream';
$name = $att['original_name'] ?: $att['stored_name'];

header('Content-Type: '        . $mime);
header('Content-Length: '      . filesize($fullPath));
header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
