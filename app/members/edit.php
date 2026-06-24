<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin','staff']);

$db     = getDB();
$instId = authInstId();
$id     = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND institution_id = ? AND is_active = 1");
$stmt->execute([$id, $instId]);
$member = $stmt->fetch();
if (!$member) { setFlash('error', 'Member not found.'); header('Location: ' . BASE_URL . '/app/members/list'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $mobile    = trim($_POST['mobile']     ?? '');

    if (!$firstName) $error = 'First name is required.';
    elseif (!$lastName) $error = 'Last name is required.';
    elseif (!preg_match('/^[6-9]\d{9}$/', $mobile)) $error = 'Valid 10-digit mobile is required.';

    if (!$error) {
        // Handle photo: prefer cropped base64 data over raw file upload
        $photoName   = $member['passport_photo'];
        $croppedData = $_POST['cropped_photo_data'] ?? '';
        if ($croppedData !== '' && str_starts_with($croppedData, 'data:image/')) {
            try {
                if ($photoName && file_exists(PHOTO_DIR . '/' . $photoName)) {
                    @unlink(PHOTO_DIR . '/' . $photoName);
                }
                $photoName = saveCroppedPhoto($croppedData, PHOTO_DIR);
            } catch (RuntimeException $e) {
                $error = 'Photo save failed: ' . $e->getMessage();
            }
        } elseif (!empty($_FILES['passport_photo']['name'])) {
            try {
                $newPhoto = uploadFile($_FILES['passport_photo'], PHOTO_DIR, ALLOWED_IMAGES);
                if ($photoName && file_exists(PHOTO_DIR . '/' . $photoName)) {
                    @unlink(PHOTO_DIR . '/' . $photoName);
                }
                $photoName = $newPhoto;
            } catch (RuntimeException $e) {
                $error = 'Photo upload: ' . $e->getMessage();
            }
        }

        if (!$error) {
            // Build new values for audit log comparison (before DB write)
            $newData = [
                'mobile'                   => $mobile,
                'email'                    => trim($_POST['email'] ?? '') ?: null,
                'id_number'                => trim($_POST['id_number'] ?? '') ?: null,
                'id_type'                  => $_POST['id_type'] ?: null,
                'is_active'                => $member['is_active'],
                'emergency_contact_mobile' => trim($_POST['emergency_contact_mobile'] ?? '') ?: null,
            ];

            $db->prepare(
                "UPDATE members SET
                   first_name=?, last_name=?, date_of_birth=?, gender=?, blood_group=?,
                   nationality=?, email=?, mobile=?, alternate_mobile=?, address=?,
                   city=?, state=?, pincode=?, id_type=?, id_number=?,
                   emergency_contact_name=?, emergency_contact_mobile=?, emergency_contact_relation=?,
                   medical_conditions=?, passport_photo=?, sport_category=?, experience_level=?,
                   updated_at=NOW()
                 WHERE id = ? AND institution_id = ?"
            )->execute([
                $firstName,
                $lastName,
                $_POST['date_of_birth']                ?: null,
                $_POST['gender']                        ?: null,
                $_POST['blood_group']                   ?: null,
                trim($_POST['nationality']              ?? 'Indian'),
                $newData['email'],
                $mobile,
                trim($_POST['alternate_mobile']         ?? '') ?: null,
                trim($_POST['address']                  ?? '') ?: null,
                trim($_POST['city']                     ?? '') ?: null,
                trim($_POST['state']                    ?? '') ?: null,
                trim($_POST['pincode']                  ?? '') ?: null,
                $newData['id_type'],
                $newData['id_number'],
                trim($_POST['emergency_contact_name']   ?? '') ?: null,
                $newData['emergency_contact_mobile'],
                trim($_POST['emergency_contact_relation']??'') ?: null,
                trim($_POST['medical_conditions']       ?? '') ?: null,
                $photoName,
                trim($_POST['sport_category']           ?? '') ?: null,
                $_POST['experience_level']              ?: null,
                $id,
                $instId,
            ]);

            logFieldChanges('member', $id, $instId, $member, $newData);

            setFlash('success', 'Member updated successfully.');
            header('Location: ' . BASE_URL . '/app/members/view?id=' . $id);
            exit;
        }
    }
}

$m           = $member;
$useCropper  = true;
$pageTitle   = 'Edit ' . memberLabel(false) . ' – ' . h($member['first_name'] . ' ' . $member['last_name']);
$breadcrumbs = [
    'Dashboard'   => dashboardUrl(),
    memberLabel() => BASE_URL . '/app/members/list',
    $member['first_name'] . ' ' . $member['last_name'] => BASE_URL . '/app/members/view?id=' . $id,
    'Edit'      => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<form method="POST" enctype="multipart/form-data" novalidate>
<?= csrfField() ?>
<div class="row g-4">
  <div class="col-lg-8">

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Personal -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-person-fill me-2 text-primary"></i>Personal Details</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">First Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="first_name" value="<?= h($m['first_name']) ?>" required></div>
          <div class="col-md-4"><label class="form-label">Last Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="last_name" value="<?= h($m['last_name']) ?>" required></div>
          <div class="col-md-4"><label class="form-label">Date of Birth</label>
            <input type="date" class="form-control" name="date_of_birth" value="<?= h($m['date_of_birth'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="form-label">Gender</label>
            <select class="form-select" name="gender">
              <option value="">Select</option>
              <?php foreach (['male','female','other'] as $g): ?>
              <option value="<?= $g ?>" <?= $m['gender'] === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-3"><label class="form-label">Blood Group</label>
            <select class="form-select" name="blood_group">
              <option value="">Select</option>
              <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
              <option value="<?= $bg ?>" <?= $m['blood_group'] === $bg ? 'selected' : '' ?>><?= $bg ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-3"><label class="form-label">Nationality</label>
            <input type="text" class="form-control" name="nationality" value="<?= h($m['nationality'] ?? 'Indian') ?>"></div>
          <div class="col-md-3"><label class="form-label">Experience</label>
            <select class="form-select" name="experience_level">
              <option value="">Select</option>
              <?php foreach (['beginner','intermediate','advanced','professional'] as $el): ?>
              <option value="<?= $el ?>" <?= $m['experience_level'] === $el ? 'selected' : '' ?>><?= ucfirst($el) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Sport / Activity</label>
            <input type="text" class="form-control" name="sport_category" value="<?= h($m['sport_category'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Medical Conditions</label>
            <input type="text" class="form-control" name="medical_conditions" value="<?= h($m['medical_conditions'] ?? '') ?>"></div>
        </div>
      </div>
    </div>

    <!-- Contact -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-telephone-fill me-2 text-primary"></i>Contact</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Mobile <span class="required">*</span></label>
            <div class="input-group"><span class="input-group-text">+91</span>
            <input type="tel" class="form-control" name="mobile" value="<?= h($m['mobile']) ?>" pattern="[6-9][0-9]{9}" maxlength="10" required></div></div>
          <div class="col-md-4"><label class="form-label">Alternate Mobile</label>
            <div class="input-group"><span class="input-group-text">+91</span>
            <input type="tel" class="form-control" name="alternate_mobile" value="<?= h($m['alternate_mobile'] ?? '') ?>" maxlength="10"></div></div>
          <div class="col-md-4"><label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?= h($m['email'] ?? '') ?>"></div>
          <div class="col-12"><label class="form-label">Address</label>
            <textarea class="form-control" name="address" rows="2"><?= h($m['address'] ?? '') ?></textarea></div>
          <div class="col-md-4"><label class="form-label">City</label>
            <input type="text" class="form-control" name="city" value="<?= h($m['city'] ?? '') ?>"></div>
          <div class="col-md-4"><label class="form-label">State</label>
            <input type="text" class="form-control" name="state" value="<?= h($m['state'] ?? '') ?>"></div>
          <div class="col-md-4"><label class="form-label">Pincode</label>
            <input type="text" class="form-control" name="pincode" value="<?= h($m['pincode'] ?? '') ?>" maxlength="10"></div>
        </div>
      </div>
    </div>

    <!-- Identity -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-card-text me-2 text-primary"></i>Identity Proof</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label">ID Type</label>
          <select class="form-select" name="id_type">
            <option value="">Select</option>
            <?php foreach (['aadhar'=>'Aadhar','pan'=>'PAN','passport'=>'Passport','voter_id'=>'Voter ID','driving_license'=>'Driving License','other'=>'Other'] as $v=>$l): ?>
            <option value="<?= h($v) ?>" <?= $m['id_type'] === $v ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="col-md-8"><label class="form-label">ID Number</label>
          <input type="text" class="form-control" name="id_number" value="<?= h($m['id_number'] ?? '') ?>"></div>
      </div></div>
    </div>

    <!-- Emergency Contact -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-person-heart me-2 text-primary"></i>Emergency Contact</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-5"><label class="form-label">Name</label>
          <input type="text" class="form-control" name="emergency_contact_name" value="<?= h($m['emergency_contact_name'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Mobile</label>
          <div class="input-group"><span class="input-group-text">+91</span>
          <input type="tel" class="form-control" name="emergency_contact_mobile" value="<?= h($m['emergency_contact_mobile'] ?? '') ?>" maxlength="10"></div></div>
        <div class="col-md-3"><label class="form-label">Relation</label>
          <input type="text" class="form-control" name="emergency_contact_relation" value="<?= h($m['emergency_contact_relation'] ?? '') ?>"></div>
      </div></div>
    </div>

  </div>

  <!-- Right: Photo -->
  <div class="col-lg-4">
    <div class="card mb-4 position-sticky" style="top:80px;">
      <div class="card-header"><i class="bi bi-person-bounding-box me-2 text-primary"></i>Passport Photo</div>
      <div class="card-body text-center">
        <div class="mb-3">
          <?php if ($m['passport_photo']): ?>
          <img src="<?= h(PHOTO_URL . '/' . $m['passport_photo']) ?>"
               id="photoPreview" class="member-photo mx-auto d-block" alt="Photo">
          <div id="photoPlaceholder" class="member-photo-placeholder mx-auto" style="display:none;">
            <i class="bi bi-person-fill"></i>
          </div>
          <?php else: ?>
          <img id="photoPreview" src="" alt="Photo" class="member-photo mx-auto d-block" style="display:none;">
          <div id="photoPlaceholder" class="member-photo-placeholder mx-auto" style="display:flex;">
            <i class="bi bi-person-fill"></i>
          </div>
          <?php endif; ?>
        </div>
        <label class="form-label small">Replace Photo</label>
        <input type="file" class="form-control form-control-sm" name="passport_photo"
               accept="image/jpeg,image/png,image/webp" id="photoInput">
        <div class="form-text">JPG / PNG / WebP &middot; Max 5 MB &middot; Will be cropped to passport size.</div>
      </div>
      <div class="card-footer">
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-2"></i>Save Changes</button>
          <a href="<?= h(BASE_URL . '/app/members/view?id=' . $id) ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once APP_ROOT . '/includes/photo_cropper.php'; ?>
</form>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
