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

$pageTitle   = 'Members';
$breadcrumbs = ['Home' => dashboardUrl(), 'Members' => ''];
require_once APP_ROOT . '/includes/header.php';

// ── Card definitions per category ────────────────────────────
// Each card: [icon, gradient, title, desc, href|null, adminOnly]

if ($category === 'school') {
    $sectionDesc = 'Manage student admissions, records and academic progressions.';
    $cards = [
        // Transactional – staff + admin
        ['bi-person-plus-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
         'Student Admission', 'Register a new student with full details and documents.',
         BASE_URL . '/app/members/add.php', false],

        ['bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
         'Student List', 'View, search and manage all enrolled students.',
         BASE_URL . '/app/members/index.php', false],

        // Admin only
        ['bi-mortarboard-fill', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
         'Student Promotion', 'Bulk promote students to the next class or level.',
         null, true],

        ['bi-file-earmark-text-fill', 'linear-gradient(135deg,#d97706,#f59e0b)',
         'Transfer Certificate', 'Issue and manage transfer certificates for students.',
         null, true],

        ['bi-card-list', 'linear-gradient(135deg,#0891b2,#06b6d4)',
         'ID Card Generation', 'Generate student identity cards in bulk.',
         null, true],

        ['bi-gear-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
         'Student Settings', 'Configure admission form fields and student categories.',
         null, true],
    ];

} elseif ($category === 'association') {
    $sectionDesc = 'Manage association members, affiliated clubs and delegate functions.';
    $cards = [
        ['bi-person-plus-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
         'Add Member', 'Register a new association member.',
         BASE_URL . '/app/members/add.php', false],

        ['bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
         'Member List', 'View and manage all registered association members.',
         BASE_URL . '/app/members/index.php', false],

        ['bi-building-fill', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
         'Affiliate Clubs / Organisations', 'Manage affiliated clubs and their delegates.',
         null, false],

        ['bi-award-fill', 'linear-gradient(135deg,#d97706,#f59e0b)',
         'Member Certificates', 'Issue membership and participation certificates.',
         null, false],

        ['bi-gear-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
         'Member Settings', 'Configure membership categories and application fields.',
         null, true],
    ];

} else {
    // sports_club / general
    $sectionDesc = 'Manage members, memberships and related information.';
    $cards = [
        ['bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
         'Member List', 'View, search and filter all registered members.',
         BASE_URL . '/app/members/index.php', false],

        ['bi-person-plus-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
         'Add New Member', 'Register a new member with full application details.',
         BASE_URL . '/app/members/add.php', false],

        ['bi-card-checklist', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
         'Memberships', 'View and manage memberships for all members.',
         BASE_URL . '/app/members/index.php', false],

        ['bi-person-badge-fill', 'linear-gradient(135deg,#0891b2,#06b6d4)',
         'Member ID Cards', 'Generate and print member identity cards.',
         null, false],

        ['bi-gear-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
         'Member Settings', 'Configure member categories and application form fields.',
         null, true],
    ];
}
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-people-fill"></i></div>
  <div>
    <h4>Members</h4>
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
<?php foreach ($cards as [$icon, $gradient, $title, $desc, $href, $adminOnly]): ?>
  <?php
  $isComingSoon = ($href === null);
  $isHidden     = ($adminOnly && !$isAdmin);
  if ($isHidden) continue;
  ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card <?= $isComingSoon ? 'disabled-card' : '' ?>">
      <div class="card-body d-flex flex-column p-4 position-relative">
        <?php if ($isComingSoon): ?>
          <span class="badge bg-secondary position-absolute top-0 end-0 m-3">Coming Soon</span>
        <?php endif; ?>
        <?php if ($adminOnly): ?>
          <span class="menu-card-role-badge">Admin Only</span>
        <?php endif; ?>
        <div class="menu-card-icon" style="background:<?= $gradient ?>;">
          <i class="bi <?= $icon ?>"></i>
        </div>
        <h5 class="fw-bold mt-3 mb-1"><?= h($title) ?></h5>
        <p class="text-muted small flex-grow-1"><?= h($desc) ?></p>
        <?php if ($isComingSoon): ?>
          <button class="btn btn-secondary mt-3" disabled>Coming Soon</button>
        <?php else: ?>
          <a href="<?= h($href) ?>" class="btn btn-primary mt-3">
            <i class="bi bi-arrow-right me-1"></i>Open
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
