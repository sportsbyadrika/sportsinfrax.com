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

$activeItems = getMenuItems('services', $category, $role, $userId, $instId);

$comingSoon = [
    ['bi-card-checklist',      'linear-gradient(135deg,#0b5ed7,#1e78ff)', 'Membership Plans',      'Define and manage membership plan types, durations and pricing.',   true],
    ['bi-calendar2-week-fill', 'linear-gradient(135deg,#059669,#10b981)', 'Training Schedules',    'Create and publish training timetables for coaches and members.',   false],
    ['bi-person-workspace',    'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Coaching Programs',     'Manage coaching programs, batches and trainer assignments.',        false],
    ['bi-door-open-fill',      'linear-gradient(135deg,#d97706,#f59e0b)', 'Facility Booking',      'Allow members to book courts, halls and other facilities.',         false],
    ['bi-trophy-fill',         'linear-gradient(135deg,#0891b2,#06b6d4)', 'Events & Tournaments',  'Organise and manage internal and external events.',                 false],
    ['bi-gear-fill',           'linear-gradient(135deg,#64748b,#94a3b8)', 'Service Settings',      'Configure service categories, pricing rules and availability.',     true],
];

$pageTitle   = 'Services';
$breadcrumbs = ['Home' => dashboardUrl(), 'Services' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-grid-fill"></i></div>
  <div>
    <h4>Services</h4>
    <p>Manage all services offered to your members — programs, schedules, bookings and events.</p>
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
