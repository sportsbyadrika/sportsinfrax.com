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

$activeItems = getMenuItems('accounts', $category, $role, $userId, $instId);

$comingSoon = [
    ['bi-cash-coin',           'linear-gradient(135deg,#059669,#10b981)', 'Fee Collection',        'Record membership fee payments and issue receipts to members.',   false],
    ['bi-receipt',             'linear-gradient(135deg,#0b5ed7,#1e78ff)', 'Receipts',              'View and print payment receipts for all transactions.',            false],
    ['bi-arrow-left-right',    'linear-gradient(135deg,#6f42c1,#9c68f0)', 'Refunds & Adjustments', 'Process refunds and fee adjustments for members.',                true],
    ['bi-bar-chart-line-fill', 'linear-gradient(135deg,#0891b2,#06b6d4)', 'Financial Summary',     'View daily, monthly and yearly collection summaries.',            true],
    ['bi-wallet2',             'linear-gradient(135deg,#d97706,#f59e0b)', 'Expense Tracking',      'Record and categorise institutional expenses.',                   true],
    ['bi-gear-fill',           'linear-gradient(135deg,#64748b,#94a3b8)', 'Accounts Settings',     'Configure fee structures, payment modes and tax settings.',       true],
];

$pageTitle   = 'Accounts';
$breadcrumbs = ['Home' => dashboardUrl(), 'Accounts' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-cash-stack"></i></div>
  <div>
    <h4>Accounts</h4>
    <p>Manage fee collection, payments, receipts and financial records.</p>
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
