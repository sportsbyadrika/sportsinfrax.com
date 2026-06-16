<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db   = getDB();
$stmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$stmt->execute([authInstId()]);
$inst = $stmt->fetch();

$role     = authRole();
$isAdmin  = ($role === 'institution_admin');
$category = getInstitutionCategory($inst['institution_type'] ?? '');
$userId   = authId();
$instId   = authInstId();

$activeItems = getMenuItems('reports', $category, $role, $userId, $instId);

$comingSoon = [
    ['bi-people-fill',                  'linear-gradient(135deg,#0b5ed7,#1e78ff)', 'Member Report',       'Active, inactive and new member statistics with filters.',          false],
    ['bi-card-checklist',               'linear-gradient(135deg,#059669,#10b981)', 'Membership Report',   'Current, expired and expiring membership summaries.',               false],
    ['bi-cash-stack',                   'linear-gradient(135deg,#d97706,#f59e0b)', 'Financial Report',    'Revenue, collection and outstanding fee reports.',                  false],
    ['bi-calendar-check-fill',          'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Attendance Report',   'Daily and monthly attendance records per batch.',                   false],
    ['bi-trophy-fill',                  'linear-gradient(135deg,#0891b2,#06b6d4)', 'Performance Report',  'Athlete progress and performance tracking.',                        false],
    ['bi-file-earmark-arrow-down-fill', 'linear-gradient(135deg,#64748b,#94a3b8)', 'Export Data',         'Export member, financial and operational data to Excel / PDF.',     true],
];

$pageTitle   = 'Reports';
$breadcrumbs = ['Home' => dashboardUrl(), 'Reports' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-bar-chart-fill"></i></div>
  <div>
    <h4>Reports</h4>
    <p>Analyse member data, finances and operations through detailed reports.</p>
  </div>
</div>

<div class="row g-4">
<?php foreach ($activeItems as $item): ?>
  <?= renderMenuHubCard($item) ?>
<?php endforeach; ?>

<?php foreach ($comingSoon as [$icon, $gradient, $title, $desc, $adminOnly]): ?>
  <?php if ($adminOnly && !$isAdmin) continue; ?>
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