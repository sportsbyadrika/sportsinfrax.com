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

// Memberships
$msStmt = $db->prepare(
    "SELECT ms.*, u.full_name AS created_by_name
     FROM memberships ms LEFT JOIN users u ON u.id = ms.created_by
     WHERE ms.member_id = ?
     ORDER BY ms.created_at DESC"
);
$msStmt->execute([$id]);
$memberships = $msStmt->fetchAll();

// Latest membership payments
$payStmt = $db->prepare(
    "SELECT mp.*, u.full_name AS recorded_by_name, ms.plan_name
     FROM membership_payments mp
     JOIN memberships ms ON ms.id = mp.membership_id
     LEFT JOIN users u ON u.id = mp.recorded_by
     WHERE ms.member_id = ?
     ORDER BY mp.payment_date DESC, mp.created_at DESC"
);
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

$fullName    = $member['first_name'] . ' ' . $member['last_name'];
$pageTitle   = $fullName;
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    memberLabel() => BASE_URL . '/app/members/list',
    $fullName   => '',
];
$pageAction  = '<div class="d-flex gap-2">
  <a href="' . h(BASE_URL . '/app/members/edit?id=' . $id) . '" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-pencil me-1"></i>Edit
  </a>
  <a href="' . h(BASE_URL . '/app/members/membership-add?member_id=' . $id) . '" class="btn btn-sm btn-primary">
    <i class="bi bi-card-checklist me-1"></i>Add/Renew Membership
  </a>
</div>';
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">

  <!-- Left: Member Profile -->
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-body text-center p-4">
        <!-- Photo -->
        <?php if ($member['passport_photo']): ?>
        <img src="<?= h(PHOTO_URL . '/' . $member['passport_photo']) ?>"
             alt="<?= h($fullName) ?>" class="member-photo mx-auto d-block mb-3"
             style="width:100px;height:120px;">
        <?php else: ?>
        <div class="member-photo-placeholder mx-auto mb-3"
             style="width:100px;height:120px;font-size:3rem;">
          <i class="bi bi-person-fill"></i>
        </div>
        <?php endif; ?>

        <h5 class="fw-bold mb-0"><?= h($fullName) ?></h5>
        <p class="text-muted small mb-2"><?= h($member['sport_category'] ?? '—') ?></p>
        <span class="badge bg-primary bg-opacity-10 text-primary font-monospace"><?= h($member['member_code']) ?></span>
        <?php if ($member['experience_level']): ?>
        <span class="badge bg-success bg-opacity-10 text-success ms-1"><?= h(ucfirst($member['experience_level'])) ?></span>
        <?php endif; ?>
        <?= $member['is_active'] ? '' : '<div class="mt-2"><span class="badge bg-danger">Inactive</span></div>' ?>
      </div>

      <div class="card-body border-top p-3 small">
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Mobile</span><strong><?= h($member['mobile']) ?></strong></div>
        <?php if ($member['alternate_mobile']): ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Alt Mobile</span><span><?= h($member['alternate_mobile']) ?></span></div>
        <?php endif; ?>
        <?php if ($member['email']): ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Email</span><span><?= h($member['email']) ?></span></div>
        <?php endif; ?>
        <?php if ($member['date_of_birth']): ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">DOB</span><span><?= fmtDate($member['date_of_birth']) ?></span></div>
        <?php endif; ?>
        <?php if ($member['gender']): ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Gender</span><span><?= h(ucfirst($member['gender'])) ?></span></div>
        <?php endif; ?>
        <?php if ($member['blood_group']): ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Blood Group</span><span class="badge bg-danger"><?= h($member['blood_group']) ?></span></div>
        <?php endif; ?>
        <?php if ($member['id_type']): ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">ID Type</span><span><?= h(ucfirst(str_replace('_',' ',$member['id_type']))) ?></span></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">ID Number</span><span class="font-monospace"><?= h(maskIdNumber($member['id_number'] ?? null)) ?></span></div>
        <?php endif; ?>
        <?php if ($member['address']): ?>
        <div class="pt-2 border-top">
          <div class="text-muted mb-1">Address</div>
          <div><?= nl2br(h($member['address'])) ?></div>
          <?php if ($member['city']): ?><div><?= h($member['city']) ?>, <?= h($member['state'] ?? '') ?> – <?= h($member['pincode'] ?? '') ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Emergency Contact -->
    <?php if ($member['emergency_contact_name']): ?>
    <div class="card mb-4">
      <div class="card-header small fw-600"><i class="bi bi-person-heart me-2 text-danger"></i>Emergency Contact</div>
      <div class="card-body small">
        <div class="fw-600"><?= h($member['emergency_contact_name']) ?></div>
        <div class="text-muted"><?= h($member['emergency_contact_relation'] ?? '') ?></div>
        <div><?= h($member['emergency_contact_mobile'] ?? '') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($member['medical_conditions']): ?>
    <div class="card mb-4">
      <div class="card-header small fw-600"><i class="bi bi-heart-pulse me-2 text-danger"></i>Medical Notes</div>
      <div class="card-body small"><?= nl2br(h($member['medical_conditions'])) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: Memberships & Payments -->
  <div class="col-lg-8">

    <!-- Memberships -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-card-checklist me-2 text-primary"></i>Memberships</span>
        <a href="<?= h(BASE_URL . '/app/members/membership-add?member_id=' . $id) ?>"
           class="btn btn-sm btn-primary">
          <i class="bi bi-plus me-1"></i>Add/Renew
        </a>
      </div>
      <div class="card-body p-0">
        <?php if ($memberships): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Membership #</th>
                <th>Type</th>
                <th>Plan</th>
                <th>Period</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($memberships as $ms): ?>
              <tr>
                <td class="small font-monospace text-muted"><?= h($ms['membership_number'] ?? '—') ?></td>
                <td>
                  <span class="badge <?= $ms['membership_type'] === 'new' ? 'bg-success' : 'bg-info text-dark' ?>">
                    <?= ucfirst($ms['membership_type']) ?>
                  </span>
                </td>
                <td class="small"><?= h($ms['plan_name']) ?><div class="text-muted" style="font-size:.72rem;"><?= $ms['duration_months'] ?> month<?= $ms['duration_months'] > 1 ? 's' : '' ?></div></td>
                <td class="small">
                  <?= fmtDate($ms['start_date'], 'd M Y') ?><br>
                  <?= fmtDate($ms['end_date'], 'd M Y') ?>
                  <?= membershipStatusBadge($ms['end_date']) ?>
                </td>
                <td class="small">
                  <div>₹<?= number_format($ms['net_amount'], 2) ?></div>
                  <?php if ($ms['discount'] > 0): ?>
                  <div class="text-success" style="font-size:.72rem;">-₹<?= number_format($ms['discount'], 2) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= paymentStatusBadge($ms['payment_status']) ?></td>
                <td>
                  <a href="<?= h(BASE_URL . '/app/members/payment-add?membership_id=' . $ms['id'] . '&member_id=' . $id) ?>"
                     class="btn btn-sm btn-outline-success btn-icon" title="Add Payment" data-bs-toggle="tooltip">
                    <i class="bi bi-cash-coin"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-card-checklist"></i>
          <h6>No memberships</h6>
          <a href="<?= h(BASE_URL . '/app/members/membership-add?member_id=' . $id) ?>"
             class="btn btn-primary btn-sm mt-2">Add First Membership</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Payment History -->
    <div class="card">
      <div class="card-header"><i class="bi bi-receipt me-2 text-primary"></i>Payment History</div>
      <div class="card-body p-0">
        <?php if ($payments): ?>
        <div class="timeline p-4">
          <?php foreach ($payments as $p): ?>
          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="fw-600 small">₹<?= number_format($p['amount'], 2) ?> – <?= h(ucfirst(str_replace('_',' ',$p['payment_mode']))) ?></div>
                <div class="text-muted" style="font-size:.75rem;">
                  <?= h($p['plan_name']) ?> &bull; <?= fmtDate($p['payment_date']) ?>
                  <?php if ($p['transaction_ref']): ?>&bull; Ref: <?= h($p['transaction_ref']) ?><?php endif; ?>
                </div>
                <?php if ($p['receipt_number']): ?>
                <div class="text-muted" style="font-size:.72rem;">Receipt: <?= h($p['receipt_number']) ?></div>
                <?php endif; ?>
                <?php if ($p['remarks']): ?>
                <div class="text-muted" style="font-size:.72rem;"><?= h($p['remarks']) ?></div>
                <?php endif; ?>
              </div>
              <div class="text-end">
                <?php if ($p['payment_proof']): ?>
                <a href="<?= h(PAYMENT_URL . '/' . $p['payment_proof']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-primary btn-icon" title="View Proof" data-bs-toggle="tooltip">
                  <i class="bi bi-file-earmark-image"></i>
                </a>
                <?php endif; ?>
                <div class="text-muted" style="font-size:.72rem;"><?= h($p['recorded_by_name'] ?? 'System') ?></div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-receipt"></i>
          <h6>No payments recorded</h6>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
