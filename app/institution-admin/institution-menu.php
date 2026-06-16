<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$stmt->execute([authInstId()]);
$inst = $stmt->fetch();

if (!$inst) { header('Location: ' . BASE_URL . '/app/institution-admin/dashboard.php'); exit; }

$status   = $inst['status'];
$isReady  = in_array($status, ['pending_approval', 'active']);

$pageTitle   = 'Institution';
$breadcrumbs = ['Home' => dashboardUrl(), 'Institution' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip">
  <div class="section-icon"><i class="bi bi-building-fill"></i></div>
  <div>
    <h4>Institution</h4>
    <p>Manage your institution profile, staff and organisational structure.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Institution Profile -->
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card">
      <div class="card-body d-flex flex-column p-4">
        <div class="menu-card-icon" style="background:linear-gradient(135deg,#0b5ed7,#1e78ff);">
          <i class="bi bi-building-gear"></i>
        </div>
        <h5 class="fw-bold mt-3 mb-1">Institution Profile</h5>
        <p class="text-muted small flex-grow-1">Update institution details, logo, registration documents, address and contact information.</p>
        <a href="<?= h(BASE_URL . '/app/institution-admin/profile.php') ?>" class="btn btn-primary mt-3">
          <i class="bi bi-arrow-right me-1"></i>Open
        </a>
      </div>
    </div>
  </div>

  <!-- Staff Management -->
  <?php if ($isReady): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card">
      <div class="card-body d-flex flex-column p-4">
        <div class="menu-card-icon" style="background:linear-gradient(135deg,#6f42c1,#9c68f0);">
          <i class="bi bi-people-fill"></i>
        </div>
        <h5 class="fw-bold mt-3 mb-1">Staff Management</h5>
        <p class="text-muted small flex-grow-1">Add, edit and manage staff accounts. Activate or deactivate staff access.</p>
        <a href="<?= h(BASE_URL . '/app/institution-admin/staff.php') ?>" class="btn btn-primary mt-3">
          <i class="bi bi-arrow-right me-1"></i>Open
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Branch Management (coming soon) -->
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card disabled-card">
      <div class="card-body d-flex flex-column p-4 position-relative">
        <span class="badge bg-secondary position-absolute top-0 end-0 m-3">Coming Soon</span>
        <div class="menu-card-icon" style="background:linear-gradient(135deg,#64748b,#94a3b8);">
          <i class="bi bi-diagram-3-fill"></i>
        </div>
        <h5 class="fw-bold mt-3 mb-1">Branch Management</h5>
        <p class="text-muted small flex-grow-1">Manage multiple branches or locations under your institution.</p>
        <button class="btn btn-secondary mt-3" disabled>Coming Soon</button>
      </div>
    </div>
  </div>

  <!-- Document Vault (coming soon) -->
  <div class="col-sm-6 col-lg-4">
    <div class="card h-100 menu-card disabled-card">
      <div class="card-body d-flex flex-column p-4 position-relative">
        <span class="badge bg-secondary position-absolute top-0 end-0 m-3">Coming Soon</span>
        <div class="menu-card-icon" style="background:linear-gradient(135deg,#64748b,#94a3b8);">
          <i class="bi bi-folder2-open"></i>
        </div>
        <h5 class="fw-bold mt-3 mb-1">Document Vault</h5>
        <p class="text-muted small flex-grow-1">Store and manage certificates, agreements and institutional documents.</p>
        <button class="btn btn-secondary mt-3" disabled>Coming Soon</button>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
