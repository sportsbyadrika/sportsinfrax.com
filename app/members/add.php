<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin','staff']);

$db     = getDB();
$instId = authInstId();

// Check institution is active
$instStmt = $db->prepare("SELECT status FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();
if (!$inst || $inst['status'] !== 'active') {
    setFlash('error', 'Cannot add members – institution not active.');
    header('Location: ' . dashboardUrl()); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $firstName   = trim($_POST['first_name']  ?? '');
    $lastName    = trim($_POST['last_name']   ?? '');
    $mobile      = trim($_POST['mobile']      ?? '');

    if (!$firstName) $error = 'First name is required.';
    elseif (!$lastName) $error = 'Last name is required.';
    elseif (!preg_match('/^[6-9]\d{9}$/', $mobile)) $error = 'Valid 10-digit mobile is required.';

    if (!$error) {
        // Handle photo upload
        $photoName = null;
        if (!empty($_FILES['passport_photo']['name'])) {
            try {
                $photoName = uploadFile($_FILES['passport_photo'], PHOTO_DIR, ALLOWED_IMAGES);
            } catch (RuntimeException $e) {
                $error = 'Photo upload: ' . $e->getMessage();
            }
        }

        if (!$error) {
            $memberCode = generateMemberCode($instId);
            $db->prepare(
                "INSERT INTO members
                 (member_code, institution_id, first_name, last_name, date_of_birth, gender, blood_group,
                  nationality, email, mobile, alternate_mobile, address, city, state, pincode,
                  id_type, id_number, emergency_contact_name, emergency_contact_mobile, emergency_contact_relation,
                  medical_conditions, passport_photo, sport_category, experience_level, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)"
            )->execute([
                $memberCode,
                $instId,
                $firstName,
                $lastName,
                $_POST['date_of_birth']               ?: null,
                $_POST['gender']                       ?: null,
                $_POST['blood_group']                  ?: null,
                trim($_POST['nationality']             ?? 'Indian'),
                trim($_POST['email']                   ?? '') ?: null,
                $mobile,
                trim($_POST['alternate_mobile']        ?? '') ?: null,
                trim($_POST['address']                 ?? '') ?: null,
                trim($_POST['city']                    ?? '') ?: null,
                trim($_POST['state']                   ?? '') ?: null,
                trim($_POST['pincode']                 ?? '') ?: null,
                $_POST['id_type']                      ?: null,
                trim($_POST['id_number']               ?? '') ?: null,
                trim($_POST['emergency_contact_name']  ?? '') ?: null,
                trim($_POST['emergency_contact_mobile']?? '') ?: null,
                trim($_POST['emergency_contact_relation']??'') ?: null,
                trim($_POST['medical_conditions']      ?? '') ?: null,
                $photoName,
                trim($_POST['sport_category']          ?? '') ?: null,
                $_POST['experience_level']             ?: null,
                authId(),
            ]);
            $newMemberId = (int)$db->lastInsertId();
            setFlash('success', "Member {$firstName} {$lastName} (Code: {$memberCode}) registered successfully!");

            // Redirect to add membership
            header('Location: ' . BASE_URL . '/app/members/membership-add.php?member_id=' . $newMemberId . '&new=1');
            exit;
        }
    }
}

$pageTitle   = 'Add New Member';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Members'   => BASE_URL . '/app/members/index.php',
    'Add Member'=> '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<form method="POST" enctype="multipart/form-data" novalidate>
<?= csrfField() ?>
<div class="row g-4">

  <!-- Left: Personal Info -->
  <div class="col-lg-8">

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Personal Details -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-person-fill me-2 text-primary"></i>Personal Details</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">First Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="first_name" value="<?= h($_POST['first_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Last Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="last_name" value="<?= h($_POST['last_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date of Birth</label>
            <input type="date" class="form-control" name="date_of_birth" value="<?= h($_POST['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Gender</label>
            <select class="form-select" name="gender">
              <option value="">Select</option>
              <option value="male"   <?= ($_POST['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
              <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
              <option value="other"  <?= ($_POST['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Blood Group</label>
            <select class="form-select" name="blood_group">
              <option value="">Select</option>
              <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
              <option value="<?= $bg ?>" <?= ($_POST['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Nationality</label>
            <input type="text" class="form-control" name="nationality" value="<?= h($_POST['nationality'] ?? 'Indian') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Experience Level</label>
            <select class="form-select" name="experience_level">
              <option value="">Select</option>
              <option value="beginner"     <?= ($_POST['experience_level'] ?? '') === 'beginner'     ? 'selected' : '' ?>>Beginner</option>
              <option value="intermediate" <?= ($_POST['experience_level'] ?? '') === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
              <option value="advanced"     <?= ($_POST['experience_level'] ?? '') === 'advanced'     ? 'selected' : '' ?>>Advanced</option>
              <option value="professional" <?= ($_POST['experience_level'] ?? '') === 'professional' ? 'selected' : '' ?>>Professional</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Sport / Activity</label>
            <input type="text" class="form-control" name="sport_category" value="<?= h($_POST['sport_category'] ?? '') ?>"
                   placeholder="e.g. Cricket, Swimming, Badminton">
          </div>
          <div class="col-md-6">
            <label class="form-label">Medical Conditions</label>
            <input type="text" class="form-control" name="medical_conditions" value="<?= h($_POST['medical_conditions'] ?? '') ?>"
                   placeholder="Any health conditions (optional)">
          </div>
        </div>
      </div>
    </div>

    <!-- Contact -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-telephone-fill me-2 text-primary"></i>Contact Information</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Mobile <span class="required">*</span></label>
            <div class="input-group">
              <span class="input-group-text">+91</span>
              <input type="tel" class="form-control" name="mobile"
                     value="<?= h($_POST['mobile'] ?? '') ?>" pattern="[6-9][0-9]{9}" maxlength="10" required>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Alternate Mobile</label>
            <div class="input-group">
              <span class="input-group-text">+91</span>
              <input type="tel" class="form-control" name="alternate_mobile"
                     value="<?= h($_POST['alternate_mobile'] ?? '') ?>" maxlength="10">
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?= h($_POST['email'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Address</label>
            <textarea class="form-control" name="address" rows="2"><?= h($_POST['address'] ?? '') ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label">City</label>
            <input type="text" class="form-control" name="city" value="<?= h($_POST['city'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">State</label>
            <input type="text" class="form-control" name="state" value="<?= h($_POST['state'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Pincode</label>
            <input type="text" class="form-control" name="pincode" value="<?= h($_POST['pincode'] ?? '') ?>" maxlength="10">
          </div>
        </div>
      </div>
    </div>

    <!-- Identity -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-card-text me-2 text-primary"></i>Identity Proof</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">ID Type</label>
            <select class="form-select" name="id_type">
              <option value="">Select</option>
              <?php foreach (['aadhar'=>'Aadhar Card','pan'=>'PAN Card','passport'=>'Passport',
                              'voter_id'=>'Voter ID','driving_license'=>'Driving License','other'=>'Other'] as $v => $l): ?>
              <option value="<?= h($v) ?>" <?= ($_POST['id_type'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label">ID Number</label>
            <input type="text" class="form-control" name="id_number" value="<?= h($_POST['id_number'] ?? '') ?>"
                   placeholder="Enter ID number">
          </div>
        </div>
      </div>
    </div>

    <!-- Emergency Contact -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-person-heart me-2 text-primary"></i>Emergency Contact</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label">Contact Name</label>
            <input type="text" class="form-control" name="emergency_contact_name"
                   value="<?= h($_POST['emergency_contact_name'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Mobile</label>
            <div class="input-group">
              <span class="input-group-text">+91</span>
              <input type="tel" class="form-control" name="emergency_contact_mobile"
                     value="<?= h($_POST['emergency_contact_mobile'] ?? '') ?>" maxlength="10">
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Relation</label>
            <input type="text" class="form-control" name="emergency_contact_relation"
                   value="<?= h($_POST['emergency_contact_relation'] ?? '') ?>"
                   placeholder="e.g. Father, Mother">
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Right: Photo -->
  <div class="col-lg-4">
    <div class="card mb-4 position-sticky" style="top:80px;">
      <div class="card-header"><i class="bi bi-person-bounding-box me-2 text-primary"></i>Passport Photo</div>
      <div class="card-body text-center">
        <div class="mb-3">
          <img src="<?= h(BASE_URL) ?>/app/assets/img/photo-placeholder.svg"
               id="photoPreview"
               class="member-photo"
               alt="Photo Preview"
               onerror="this.style.display='none';document.getElementById('photoPlaceholder').style.display='flex';">
          <div id="photoPlaceholder" class="member-photo-placeholder mx-auto" style="display:none;">
            <i class="bi bi-person-fill"></i>
          </div>
        </div>
        <label class="form-label">Upload Photo</label>
        <input type="file" class="form-control form-control-sm" name="passport_photo"
               accept="image/*" data-preview="#photoPreview" id="photoInput">
        <div class="form-text">Passport size. JPG/PNG. Max 5 MB.</div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body p-3">
        <p class="small text-muted mb-3">
          <i class="bi bi-info-circle me-1 text-primary"></i>
          After saving the member, you will be redirected to add their membership details.
        </p>
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-person-plus me-2"></i>Save Member
          </button>
          <a href="<?= h(BASE_URL . '/app/members/index.php') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>

</div>
</form>

<script>
// Immediately show placeholder on page load if no preview image URL
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('photoPlaceholder').style.display = 'flex';
  document.getElementById('photoPreview').style.display = 'none';
});
document.getElementById('photoInput').addEventListener('change', function () {
  if (this.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      const preview = document.getElementById('photoPreview');
      preview.src = e.target.result;
      preview.style.display = 'block';
      document.getElementById('photoPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(this.files[0]);
  }
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
