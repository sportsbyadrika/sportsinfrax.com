<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db   = getDB();
$stmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$stmt->execute([authInstId()]);
$inst = $stmt->fetch();

$category    = getInstitutionCategory($inst['institution_type'] ?? '');
$userId      = authId();
$instId      = authInstId();
$activeItems = getMenuItems('settings', $category, 'institution_admin', $userId, $instId);

// Settings page is admin-only, so all items visible — no adminOnly filtering needed
$comingSoon = [
    ['bi-sliders',           'linear-gradient(135deg,#0b5ed7,#1e78ff)', 'General Settings',       'Configure general preferences for your institution account.',           false],
    ['bi-envelope-fill',     'linear-gradient(135deg,#059669,#10b981)', 'Notification Templates', 'Customise email and SMS templates sent to members and staff.',          false],
    ['bi-shield-lock-fill',  'linear-gradient(135deg,#6f42c1,#9c68f0)', 'User Access Control',    'Set role-based permissions and feature access for staff.',              false],
    ['bi-collection-fill',   'linear-gradient(135deg,#d97706,#f59e0b)', 'Custom Fields',          'Add extra fields to member, service and payment forms.',                false],
    ['bi-printer-fill',      'linear-gradient(135deg,#0891b2,#06b6d4)', 'Print Templates',        'Configure letterhead, ID card and receipt print layouts.',              false],
    ['bi-cloud-upload-fill', 'linear-gradient(135deg,#64748b,#94a3b8)', 'Data Import',            'Bulk import members and historical data from Excel / CSV.',             false],
];

$pageTitle   = 'Settings';
$breadcrumbs = ['Home' => dashboardUrl(), 'Settings' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-gear-wide-connected"></i></div>
  <div>
    <h4>Settings</h4>
    <p>Configure your institution's application preferences, templates and access controls.</p>
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
      'required_role' => 'institution_admin',
  ]) ?>
<?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
