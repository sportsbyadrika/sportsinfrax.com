<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$stmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$stmt->execute([$instId]);
$inst = $stmt->fetch();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'institution_type' => $_POST['institution_type'] ?? 'academy',
        'reg_number'       => trim($_POST['reg_number'] ?? ''),
        'address'          => trim($_POST['address'] ?? ''),
        'city'             => trim($_POST['city'] ?? ''),
        'state'            => trim($_POST['state'] ?? ''),
        'pincode'          => trim($_POST['pincode'] ?? ''),
        'country'          => trim($_POST['country'] ?? 'India'),
        'website'          => trim($_POST['website'] ?? ''),
        'contact_email'    => trim($_POST['contact_email'] ?? ''),
        'contact_phone'    => trim($_POST['contact_phone'] ?? ''),
    ];

    if (!$data['address']) {
        $error = 'Address is required.';
    } elseif (!$data['institution_type']) {
        $error = 'Institution type is required.';
    } else {
        // Handle logo upload
        if (!empty($_FILES['logo']['name'])) {
            try {
                $logoName = uploadFile($_FILES['logo'], LOGO_DIR, ALLOWED_IMAGES);
                // Delete old logo
                if ($inst['logo'] && file_exists(LOGO_DIR . '/' . $inst['logo'])) {
                    @unlink(LOGO_DIR . '/' . $inst['logo']);
                }
                $data['logo'] = $logoName;
            } catch (RuntimeException $e) {
                $error = 'Logo upload: ' . $e->getMessage();
            }
        }

        // Handle registration document upload
        if (!$error && !empty($_FILES['reg_document']['name'])) {
            try {
                $docName = uploadFile($_FILES['reg_document'], DOC_DIR, ALLOWED_DOCS);
                if ($inst['reg_document'] && file_exists(DOC_DIR . '/' . $inst['reg_document'])) {
                    @unlink(DOC_DIR . '/' . $inst['reg_document']);
                }
                $data['reg_document'] = $docName;
            } catch (RuntimeException $e) {
                $error = 'Document upload: ' . $e->getMessage();
            }
        }

        if (!$error) {
            // Build update query
            $setClauses = [];
            $params     = [];
            foreach ($data as $col => $val) {
                $setClauses[] = "`$col` = ?";
                $params[]     = $val;
            }

            // If moving from pending_profile → pending_approval
            if ($inst['status'] === 'pending_profile') {
                $setClauses[] = "`status` = ?";
                $params[]     = 'pending_approval';
            }

            $params[] = $instId;
            $db->prepare("UPDATE institutions SET " . implode(', ', $setClauses) . " WHERE id = ?")
               ->execute($params);

            setFlash('success', $inst['status'] === 'pending_profile'
                ? 'Profile submitted for approval! The Super Admin will review your details.'
                : 'Institution profile updated successfully.');
            header('Location: ' . BASE_URL . '/app/institution-admin/profile.php');
            exit;
        }
    }
}

// Reload
$stmt->execute([$instId]);
$inst = $stmt->fetch();

$pageTitle   = 'Institution Profile';
$breadcrumbs = ['Dashboard' => BASE_URL . '/app/institution-admin/dashboard.php', 'Institution Profile' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<?php if ($inst['status'] === 'pending_profile'): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
  <div>
    <strong>Profile incomplete.</strong> Please fill in all details below and save to submit for Super Admin approval.
  </div>
</div>
<?php endif; ?>

<?php if ($inst['status'] === 'active'): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-check-circle-fill flex-shrink-0"></i>
  <div>
    <strong>Institution is Active.</strong> Valid until <strong><?= fmtDate($inst['valid_until']) ?></strong>.
    <?= institutionStatusBadge($inst['status']) ?>
  </div>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
<?= csrfField() ?>

<div class="row g-4">

  <!-- Left Column -->
  <div class="col-lg-8">

    <!-- Basic Info -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Basic Information</div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Institution Name</label>
            <input type="text" class="form-control" value="<?= h($inst['institution_name']) ?>" disabled>
            <div class="form-text">Contact Super Admin to change institution name.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Institution Type <span class="required">*</span></label>
            <select class="form-select" name="institution_type" required>
              <?php
              $types = ['academy'=>'Sports Academy','club'=>'Sports Club','stadium'=>'Stadium','complex'=>'Sports Complex',
                        'gym'=>'Gym / Fitness Centre','turf'=>'Turf / Ground','swimming_pool'=>'Swimming Pool',
                        'training_centre'=>'Training Centre','association'=>'Sports Association','other'=>'Other'];
              foreach ($types as $val => $label): ?>
              <option value="<?= h($val) ?>" <?= $inst['institution_type'] === $val ? 'selected' : '' ?>>
                <?= h($label) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Registration Number</label>
            <input type="text" class="form-control" name="reg_number"
                   value="<?= h($inst['reg_number'] ?? '') ?>"
                   placeholder="Govt / sports body registration number">
          </div>
          <div class="col-md-6">
            <label class="form-label">Website</label>
            <input type="url" class="form-control" name="website"
                   value="<?= h($inst['website'] ?? '') ?>"
                   placeholder="https://yourwebsite.com">
          </div>

          <div class="col-md-6">
            <label class="form-label">Contact Email</label>
            <input type="email" class="form-control" name="contact_email"
                   value="<?= h($inst['contact_email'] ?? '') ?>"
                   placeholder="info@institution.com">
          </div>
          <div class="col-md-6">
            <label class="form-label">Contact Phone</label>
            <input type="tel" class="form-control" name="contact_phone"
                   value="<?= h($inst['contact_phone'] ?? '') ?>"
                   placeholder="+91 XXXXXXXXXX">
          </div>
        </div>
      </div>
    </div>

    <!-- Address -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-geo-alt me-2 text-primary"></i>Address</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Street Address <span class="required">*</span></label>
            <textarea class="form-control" name="address" rows="2" required><?= h($inst['address']) ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label">City</label>
            <input type="text" class="form-control" name="city" value="<?= h($inst['city'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">State</label>
            <input type="text" class="form-control" name="state" value="<?= h($inst['state'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Pincode</label>
            <input type="text" class="form-control" name="pincode" value="<?= h($inst['pincode'] ?? '') ?>" maxlength="10">
          </div>
          <div class="col-md-2">
            <label class="form-label">Country</label>
            <input type="text" class="form-control" name="country" value="<?= h($inst['country'] ?? 'India') ?>">
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Right Column -->
  <div class="col-lg-4">

    <!-- Logo Upload -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-image me-2 text-primary"></i>Institution Logo</div>
      <div class="card-body text-center">
        <?php if ($inst['logo']): ?>
        <img src="<?= h(LOGO_URL . '/' . $inst['logo']) ?>" alt="Logo" id="logoPreview" class="logo-preview mb-3"
             style="width:120px;height:120px;">
        <?php else: ?>
        <div class="logo-preview d-flex align-items-center justify-content-center text-muted mb-3"
             id="logoPreview" style="width:120px;height:120px;margin:0 auto;">
          <i class="bi bi-building" style="font-size:2.5rem;"></i>
        </div>
        <?php endif; ?>
        <div>
          <label class="form-label d-block">Upload Logo</label>
          <input type="file" class="form-control form-control-sm" name="logo"
                 accept="image/*" data-preview="#logoPreview">
          <div class="form-text">JPG, PNG or WebP. Max 5 MB.</div>
        </div>
      </div>
    </div>

    <!-- Registration Document -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Registration Document</div>
      <div class="card-body">
        <?php if ($inst['reg_document']): ?>
        <div class="mb-2">
          <a href="<?= h(DOC_URL . '/' . $inst['reg_document']) ?>" target="_blank"
             class="btn btn-sm btn-outline-success w-100">
            <i class="bi bi-file-earmark-text me-1"></i>View Uploaded Document
          </a>
        </div>
        <div class="form-text text-center">Upload new to replace</div>
        <?php endif; ?>
        <input type="file" class="form-control form-control-sm mt-2" name="reg_document"
               accept=".pdf,image/*">
        <div class="form-text">PDF or image. Max 5 MB.</div>
      </div>
    </div>

    <!-- Status -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-info-circle me-2 text-primary"></i>Status</div>
      <div class="card-body small">
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Profile Status</span>
          <?= institutionStatusBadge($inst['status']) ?>
        </div>
        <?php if ($inst['approved_at']): ?>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Approved On</span>
          <span><?= fmtDate($inst['approved_at'], 'd M Y') ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="d-flex gap-2 pb-3">
  <button type="submit" class="btn btn-primary px-4">
    <i class="bi bi-check2 me-2"></i>
    <?= $inst['status'] === 'pending_profile' ? 'Save & Submit for Approval' : 'Save Changes' ?>
  </button>
  <a href="<?= h(BASE_URL . '/app/institution-admin/dashboard.php') ?>" class="btn btn-outline-secondary">
    Cancel
  </a>
</div>

</form>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
