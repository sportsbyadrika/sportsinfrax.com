<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$stmt->execute([authInstId()]);
$inst = $stmt->fetch();

if (!$inst) { header('Location: ' . dashboardUrl()); exit; }

$role     = authRole();
$isAdmin  = ($role === 'institution_admin');
$category = getInstitutionCategory($inst['institution_type'] ?? '');
$userId   = authId();
$instId   = authInstId();

// Registry-driven active items
$activeItems = getMenuItems('members', $category, $role, $userId, $instId);

// Coming-soon items per category (hardcoded — not in registry until built)
$comingSoon = [];
if ($category === 'school') {
    $comingSoon = [
        ['bi-mortarboard-fill',       'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Student Promotion',      'Bulk promote students to the next class or level.',                true],
        ['bi-file-earmark-text-fill', 'linear-gradient(135deg,#d97706,#f59e0b)', 'Transfer Certificate',   'Issue and manage transfer certificates for students.',             true],
        ['bi-card-list',              'linear-gradient(135deg,#0891b2,#06b6d4)', 'ID Card Generation',     'Generate student identity cards in bulk.',                         true],
        ['bi-gear-fill',              'linear-gradient(135deg,#64748b,#94a3b8)', 'Student Settings',       'Configure admission form fields and student categories.',          true],
    ];
} elseif ($category === 'association') {
    $comingSoon = [
        ['bi-building-fill',          'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Affiliate Clubs / Organisations', 'Manage affiliated clubs and their delegates.',          false],
        ['bi-award-fill',             'linear-gradient(135deg,#d97706,#f59e0b)', 'Member Certificates',    'Issue membership and participation certificates.',                 false],
        ['bi-gear-fill',              'linear-gradient(135deg,#64748b,#94a3b8)', 'Member Settings',        'Configure membership categories and application fields.',         true],
    ];
} else {
    // sports_club / general
    $comingSoon = [
        ['bi-card-checklist',         'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Memberships',            'View and manage memberships for all members.',                     false],
        ['bi-person-badge-fill',      'linear-gradient(135deg,#0891b2,#06b6d4)', 'Member ID Cards',        'Generate and print member identity cards.',                        false],
        ['bi-gear-fill',              'linear-gradient(135deg,#64748b,#94a3b8)', 'Member Settings',        'Configure member categories and application form fields.',        true],
    ];
}

$sectionDesc = match($category) {
    'school'      => 'Manage student admissions, records and academic progressions.',
    'association' => 'Manage association members, affiliated clubs and delegate functions.',
    default       => 'Manage members, memberships and related information.',
};

$pageTitle   = memberLabel();
$breadcrumbs = ['Home' => dashboardUrl(), memberLabel() => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-people-fill"></i></div>
  <div>
    <h4><?= memberLabel() ?></h4>
    <p><?= h($sectionDesc) ?></p>
  </div>
</div>

<?php if (!$isAdmin): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2 small mb-4">
  <i class="bi bi-info-circle-fill flex-shrink-0"></i>
  <span>You have access to transactional functions. Contact your Institution Admin for configuration options.</span>
</div>
<?php endif; ?>

<div class="row g-4">
<?php foreach ($activeItems as $item): ?>
  <?= renderMenuHubCard($item) ?>
<?php endforeach; ?>

<?php if ($category === 'school'): ?>
  <?= renderMenuHubCard([
      'icon'          => 'bi-person-plus-fill',
      'gradient'      => 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
      'label'         => 'Student Admission',
      'description'   => 'Register a new student with full details and documents.',
      'route'         => '/app/services/students-add',
      'required_role' => 'any',
  ]) ?>
  <?= renderMenuHubCard([
      'icon'          => 'bi-people-fill',
      'gradient'      => 'linear-gradient(135deg,#059669,#10b981)',
      'label'         => 'Student List',
      'description'   => 'View, search and manage all enrolled students.',
      'route'         => '/app/services/students',
      'required_role' => 'any',
  ]) ?>
<?php endif; ?>

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
