<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$role    = authRole();
$isAdmin = ($role === 'institution_admin');

$pageTitle   = 'Reports';
$breadcrumbs = ['Home' => dashboardUrl(), 'Reports' => ''];
require_once APP_ROOT . '/includes/header.php';

$cards = [
    ['bi-people-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
     'Member Report', 'Active, inactive and new member statistics with filters.',
     null, false],

    ['bi-card-checklist', 'linear-gradient(135deg,#059669,#10b981)',
     'Membership Report', 'Current, expired and expiring membership summaries.',
     null, false],

    ['bi-cash-stack', 'linear-gradient(135deg,#d97706,#f59e0b)',
     'Financial Report', 'Revenue, collection and outstanding fee reports.',
     null, false],

    ['bi-calendar-check-fill', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
     'Attendance Report', 'Daily and monthly attendance records per batch.',
     null, false],

    ['bi-trophy-fill', 'linear-gradient(135deg,#0891b2,#06b6d4)',
     'Performance Report', 'Athlete progress and performance tracking.',
     null, false],

    ['bi-file-earmark-arrow-down-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
     'Export Data', 'Export member, financial and operational data to Excel / PDF.',
     null, $isAdmin ? false : true],
];
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-bar-chart-fill"></i></div>
  <div>
    <h4>Reports</h4>
    <p>Analyse member data, finances and operations through detailed reports.</p>
  </div>
</div>

<div class="row g-4">
<?php foreach ($cards as [$icon, $gradient, $title, $desc, $href, $adminOnly]): ?>
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
