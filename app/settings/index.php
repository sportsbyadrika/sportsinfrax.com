<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$pageTitle   = 'Settings';
$breadcrumbs = ['Home' => dashboardUrl(), 'Settings' => ''];
require_once APP_ROOT . '/includes/header.php';

$cards = [
    ['bi-sliders', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
     'General Settings', 'Configure general preferences for your institution account.'],

    ['bi-envelope-fill', 'linear-gradient(135deg,#059669,#10b981)',
     'Notification Templates', 'Customise email and SMS templates sent to members and staff.'],

    ['bi-shield-lock-fill', 'linear-gradient(135deg,#6f42c1,#9c68f0)',
     'User Access Control', 'Set role-based permissions and feature access for staff.'],

    ['bi-collection-fill', 'linear-gradient(135deg,#d97706,#f59e0b)',
     'Custom Fields', 'Add extra fields to member, service and payment forms.'],

    ['bi-printer-fill', 'linear-gradient(135deg,#0891b2,#06b6d4)',
     'Print Templates', 'Configure letterhead, ID card and receipt print layouts.'],

    ['bi-cloud-upload-fill', 'linear-gradient(135deg,#64748b,#94a3b8)',
     'Data Import', 'Bulk import members and historical data from Excel / CSV.'],
];
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-gear-wide-connected"></i></div>
  <div>
    <h4>Settings</h4>
    <p>Configure your institution's application preferences, templates and access controls.</p>
  </div>
</div>

<div class="row g-4">
<?php foreach ($cards as [$icon, $gradient, $title, $desc]): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card disabled-card">
      <div class="card-body d-flex flex-column p-4 position-relative">
        <span class="badge bg-secondary position-absolute top-0 end-0 m-3">Coming Soon</span>
        <div class="menu-card-icon" style="background:<?= $gradient ?>;">
          <i class="bi <?= $icon ?>"></i>
        </div>
        <h5 class="fw-bold mt-3 mb-1"><?= h($title) ?></h5>
        <p class="text-muted small flex-grow-1"><?= h($desc) ?></p>
        <button class="btn btn-secondary mt-3" disabled>Coming Soon</button>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
