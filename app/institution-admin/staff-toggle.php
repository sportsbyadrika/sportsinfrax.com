<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');
verifyCsrf();

$db      = getDB();
$instId  = authInstId();
$staffId = (int)($_POST['staff_id'] ?? 0);
$userId  = (int)($_POST['user_id']  ?? 0);
$active  = (int)($_POST['active']   ?? 0);

// Verify staff belongs to this institution
$stmt = $db->prepare("SELECT id FROM staff WHERE id = ? AND institution_id = ?");
$stmt->execute([$staffId, $instId]);
if (!$stmt->fetch()) {
    setFlash('error', 'Unauthorized.');
} else {
    $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$active, $userId]);
    setFlash('success', 'Staff account ' . ($active ? 'activated' : 'deactivated') . '.');
}

header('Location: ' . BASE_URL . '/app/institution-admin/staff');
exit;
