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

$pageTitle   = 'Services';
$breadcrumbs = ['Home' => dashboardUrl(), 'Services' => ''];
require_once APP_ROOT . '/includes/header.php';

// Card sets vary slightly by category
$commonCards = [
    ['bi-card-checklist', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
     'Membership Plans', 'Define and manage membership plan types, durations and pricing.',
     null, true],

    ['bi-calendar2-week-fill', 'linear-gradient(135deg,#059669,#10b981)',
     'Training Schedules', 'Create and publish training timetables for coaches and members.',
     null, false],

    ['bi-person-workspace', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
     'Coaching Programs', 'Manage coaching programs, batches and trainer assignments.',
     null, false],

    ['bi-door-open-fill', 'linear-gradient(135deg,#d97706,#f59e0b)',
     'Facility Booking', 'Allow members to book courts, halls and other facilities.',
     null, false],

    ['bi-trophy-fill', 'linear-gradient(135deg,#0891b2,#06b6d4)',
     'Events & Tournaments', 'Organise and manage internal and external events.',
     null, false],

    ['bi-gear-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
     'Service Settings', 'Configure service categories, pricing rules and availability.',
     null, true],
];
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-grid-fill"></i></div>
  <div>
    <h4>Services</h4>
    <p>Manage all services offered to your members — programs, schedules, bookings and events.</p>
  </div>
</div>

<div class="row g-4">
<?php foreach ($commonCards as [$icon, $gradient, $title, $desc, $href, $adminOnly]): ?>
  <?php if ($adminOnly && !$isAdmin) continue; ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card <?= $href ? '' : 'disabled-card' ?>">
      <div class="card-body d-flex flex-column p-4 position-relative">
        <?php if (!$href): ?>
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
        <?php if ($href): ?>
          <a href="<?= h($href) ?>" class="btn btn-primary mt-3">
            <i class="bi bi-arrow-right me-1"></i>Open
          </a>
        <?php else: ?>
          <button class="btn btn-secondary mt-3" disabled>Coming Soon</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
