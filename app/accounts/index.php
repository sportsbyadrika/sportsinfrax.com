<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$role    = authRole();
$isAdmin = ($role === 'institution_admin');

$pageTitle   = 'Accounts';
$breadcrumbs = ['Home' => dashboardUrl(), 'Accounts' => ''];
require_once APP_ROOT . '/includes/header.php';

$cards = [
    ['bi-cash-coin', 'linear-gradient(135deg,#059669,#10b981)',
     'Fee Collection', 'Record membership fee payments and issue receipts to members.',
     null, false],

    ['bi-receipt', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
     'Receipts', 'View and print payment receipts for all transactions.',
     null, false],

    ['bi-arrow-left-right', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
     'Refunds & Adjustments', 'Process refunds and fee adjustments for members.',
     null, false],

    ['bi-bar-chart-line-fill', 'linear-gradient(135deg,#0891b2,#06b6d4)',
     'Financial Summary', 'View daily, monthly and yearly collection summaries.',
     null, false],

    ['bi-wallet2', 'linear-gradient(135deg,#d97706,#f59e0b)',
     'Expense Tracking', 'Record and categorise institutional expenses.',
     null, true],

    ['bi-gear-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
     'Accounts Settings', 'Configure fee structures, payment modes and tax settings.',
     null, true],
];
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-cash-stack"></i></div>
  <div>
    <h4>Accounts</h4>
    <p>Manage fee collection, payments, receipts and financial records.</p>
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
