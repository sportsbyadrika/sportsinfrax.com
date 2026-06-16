<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin','staff']);

$db       = getDB();
$instId   = authInstId();
$memberId = (int)($_GET['member_id'] ?? 0);
$isNew    = (bool)($_GET['new'] ?? false);

$mStmt = $db->prepare("SELECT * FROM members WHERE id = ? AND institution_id = ? AND is_active = 1");
$mStmt->execute([$memberId, $instId]);
$member = $mStmt->fetch();
if (!$member) { setFlash('error', 'Member not found.'); header('Location: ' . BASE_URL . '/app/members/list'); exit; }

// Last membership for renewal suggestion
$lastMs = $db->prepare("SELECT * FROM memberships WHERE member_id = ? ORDER BY created_at DESC LIMIT 1");
$lastMs->execute([$memberId]);
$prevMs = $lastMs->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $msType    = $_POST['membership_type'] ?? 'new';
    $planName  = trim($_POST['plan_name'] ?? '');
    $duration  = max(1, (int)($_POST['duration_months'] ?? 1));
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date']   ?? '';
    $amount    = (float)($_POST['amount'] ?? 0);
    $discount  = (float)($_POST['discount'] ?? 0);
    $net       = max(0, $amount - $discount);
    $notes     = trim($_POST['notes'] ?? '');

    if (!$planName)  $error = 'Plan name is required.';
    elseif (!$startDate) $error = 'Start date is required.';
    elseif (!$endDate)   $error = 'End date is required.';
    elseif ($endDate <= $startDate) $error = 'End date must be after start date.';
    elseif ($amount <= 0) $error = 'Membership amount must be greater than 0.';

    if (!$error) {
        $msNumber = generateMembershipNumber($instId);
        $db->prepare(
            "INSERT INTO memberships
             (membership_number, member_id, institution_id, membership_type, plan_name, duration_months,
              start_date, end_date, amount, discount, net_amount, payment_status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)"
        )->execute([
            $msNumber, $memberId, $instId, $msType, $planName, $duration,
            $startDate, $endDate, $amount, $discount, $net, $notes ?: null, authId()
        ]);
        $msId = (int)$db->lastInsertId();

        setFlash('success', "Membership #{$msNumber} created. Please add payment details.");
        header('Location: ' . BASE_URL . '/app/members/payment-add?membership_id=' . $msId . '&member_id=' . $memberId);
        exit;
    }
}

$fullName    = $member['first_name'] . ' ' . $member['last_name'];
$pageTitle   = ($prevMs ? 'Renew Membership' : 'Add Membership') . ' – ' . $fullName;
$breadcrumbs = [
    'Dashboard'            => dashboardUrl(),
    'Members'              => BASE_URL . '/app/members/list',
    $fullName              => BASE_URL . '/app/members/view?id=' . $memberId,
    ($prevMs ? 'Renew' : 'Add Membership') => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">
  <!-- Member Summary -->
  <div class="col-lg-4">
    <div class="card mb-4 position-sticky" style="top:80px;">
      <div class="card-header"><i class="bi bi-person me-2 text-primary"></i>Member</div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if ($member['passport_photo']): ?>
          <img src="<?= h(PHOTO_URL . '/' . $member['passport_photo']) ?>" alt=""
               style="width:50px;height:60px;object-fit:cover;border-radius:6px;">
          <?php else: ?>
          <div class="avatar-circle" style="width:50px;height:50px;font-size:1.2rem;border-radius:8px;">
            <?= mb_strtoupper(mb_substr($member['first_name'], 0, 1)) ?>
          </div>
          <?php endif; ?>
          <div>
            <div class="fw-bold"><?= h($fullName) ?></div>
            <div class="text-muted small"><?= h($member['member_code']) ?></div>
            <div class="text-muted small"><?= h($member['mobile']) ?></div>
          </div>
        </div>

        <?php if ($prevMs): ?>
        <div class="alert alert-info py-2 small mb-0">
          <strong>Previous Membership</strong><br>
          <?= h($prevMs['plan_name']) ?><br>
          Valid: <?= fmtDate($prevMs['start_date']) ?> – <?= fmtDate($prevMs['end_date']) ?><br>
          <?= membershipStatusBadge($prevMs['end_date']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Membership Form -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-card-checklist me-2 text-primary"></i>
        <?= $prevMs ? 'Renew / New Membership' : 'New Membership' ?>
      </div>
      <div class="card-body p-4">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($isNew): ?>
        <div class="alert alert-success py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-check-circle-fill"></i>
          Member registered! Now add their membership plan.
        </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">Membership Type <span class="required">*</span></label>
              <select class="form-select" name="membership_type" id="membership_type">
                <option value="new"     <?= ($prevMs ? '' : 'selected') ?>>New Membership</option>
                <option value="renewal" <?= ($prevMs ? 'selected' : '') ?>>Renewal</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Plan Name <span class="required">*</span></label>
              <input type="text" class="form-control" name="plan_name"
                     value="<?= h($_POST['plan_name'] ?? ($prevMs['plan_name'] ?? '')) ?>"
                     placeholder="e.g. Monthly, Quarterly, Annual" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Duration (Months) <span class="required">*</span></label>
              <input type="number" class="form-control" name="duration_months" id="duration_months"
                     value="<?= h($_POST['duration_months'] ?? ($prevMs['duration_months'] ?? 1)) ?>"
                     min="1" max="60" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Start Date <span class="required">*</span></label>
              <input type="date" class="form-control" name="start_date" id="start_date"
                     value="<?= h($_POST['start_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">End Date <span class="required">*</span></label>
              <input type="date" class="form-control" name="end_date" id="end_date"
                     value="<?= h($_POST['end_date'] ?? '') ?>" required>
              <div class="form-text">Auto-calculated from duration.</div>
            </div>
          </div>

          <hr>

          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Fee Amount (₹) <span class="required">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="amount" id="amount"
                       value="<?= h($_POST['amount'] ?? ($prevMs['amount'] ?? '')) ?>"
                       step="0.01" min="0" required>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Discount (₹)</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="discount" id="discount"
                       value="<?= h($_POST['discount'] ?? '0') ?>"
                       step="0.01" min="0">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Net Payable (₹)</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control fw-bold" name="net_amount" id="net_amount"
                       value="<?= h($_POST['net_amount'] ?? ($prevMs['net_amount'] ?? '')) ?>"
                       step="0.01" readonly style="background:#f8fafc;">
              </div>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Notes / Remarks</label>
            <textarea class="form-control" name="notes" rows="2"
                      placeholder="Any special notes for this membership…"><?= h($_POST['notes'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-check2 me-2"></i>Create Membership & Add Payment
            </button>
            <a href="<?= h(BASE_URL . '/app/members/view?id=' . $memberId) ?>"
               class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
