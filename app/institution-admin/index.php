<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$stmt->execute([authInstId()]);
$inst = $stmt->fetch();

if (!$inst) { header('Location: ' . dashboardUrl()); exit; }

$category = getInstitutionCategory($inst['institution_type'] ?? '');
$userId   = authId();
$instId   = authInstId();

// Registry-driven active items (institution section is admin-only)
$activeItems = getMenuItems('institution', $category, 'institution_admin', $userId, $instId);

// Coming-soon items (hardcoded — not in registry until built)
$comingSoon = [
    ['bi-building-fill', 'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Branch Management', 'Manage multiple branches and locations under your institution.', false],
    ['bi-safe-fill',     'linear-gradient(135deg,#0891b2,#06b6d4)', 'Document Vault',    'Store and manage institutional documents and certificates.',     false],
];

$pageTitle   = 'Institution';
$breadcrumbs = ['Home' => dashboardUrl(), 'Institution' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-building-fill"></i></div>
  <div>
    <h4>Institution</h4>
    <p>Manage your institution profile, staff and operational configuration.</p>
  </div>
</div>

<div class="row g-4">
<?php foreach ($activeItems as $item): ?>
  <?= renderMenuHubCard($item) ?>
<?php endforeach; ?>

<?php foreach ($comingSoon as [$icon, $gradient, $title, $desc, $adminOnly]): ?>
  <?= renderMenuHubCard([
      'icon'          => $icon,
      'gradient'      => $gradient,
      'label'         => $title,
      'description'   => $desc,
      'route'         => null,
      'required_role' => $adminOnly ? 'institution_admin' : 'any',
  ]) ?>
<?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
