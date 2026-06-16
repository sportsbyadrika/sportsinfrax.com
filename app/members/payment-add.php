<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin','staff']);

$db           = getDB();
$instId       = authInstId();
$membershipId = (int)($_GET['membership_id'] ?? 0);
$memberId     = (int)($_GET['member_id'] ?? 0);

// Load membership
$msStmt = $db->prepare(
    "SELECT ms.*, m.first_name, m.last_name, m.member_code, m.id AS member_id
     FROM memberships ms JOIN members m ON m.id = ms.member_id
     WHERE ms.id = ? AND ms.institution_id = ?"
);
$msStmt->execute([$membershipId, $instId]);
$ms = $msStmt->fetch();
if (!$ms) { setFlash('error', 'Membership not found.'); header('Location: ' . BASE_URL . '/app/members/list'); exit; }

$memberId = $memberId ?: $ms['member_id'];

// Existing payments for this membership
$paidStmt = $db->prepare("SELECT SUM(amount) FROM membership_payments WHERE membership_id = ?");
$paidStmt->execute([$membershipId]);
$totalPaid = (float)($paidStmt->fetchColumn() ?? 0);
$remaining  = max(0, $ms['net_amount'] - $totalPaid);

// Payment history
$histStmt = $db->prepare(
    "SELECT mp.*, u.full_name AS recorded_by_name
     FROM membership_payments mp LEFT JOIN users u ON u.id = mp.recorded_by
     WHERE mp.membership_id = ? ORDER BY mp.payment_date DESC"
);
$histStmt->execute([$membershipId]);
$payHistory = $histStmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $payDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount  = (float)($_POST['amount'] ?? 0);
    $mode    = $_POST['payment_mode'] ?? '';
    $ref     = trim($_POST['transaction_ref']  ?? '');
    $receipt = trim($_POST['receipt_number']   ?? '');
    $remarks = trim($_POST['remarks']          ?? '');

    if (!$payDate)  $error = 'Payment date is required.';
    elseif ($amount <= 0) $error = 'Amount must be greater than 0.';
    elseif (!$mode) $error = 'Payment mode is required.';

    if (!$error) {
        $proofName = null;
        if (!empty($_FILES['payment_proof']['name'])) {
            try {
                $proofName = uploadFile($_FILES['payment_proof'], PAYMENT_DIR, ALLOWED_DOCS);
            } catch (RuntimeException $e) {
                $error = 'Proof upload: ' . $e->getMessage();
            }
        }

        if (!$error) {
            $db->prepare(
                "INSERT INTO membership_payments
                 (membership_id, payment_date, amount, payment_mode, transaction_ref,
                  receipt_number, payment_proof, remarks, recorded_by)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([$membershipId, $payDate, $amount, $mode, $ref ?: null, $receipt ?: null,
                        $proofName, $remarks ?: null, authId()]);
            $newPaymentId = (int)$db->lastInsertId();

            // Update payment status
            $newPaid = $totalPaid + $amount;
            $status  = 'pending';
            if ($newPaid >= $ms['net_amount'] - 0.01) $status = 'paid';
            elseif ($newPaid > 0)                       $status = 'partial';

            $db->prepare("UPDATE memberships SET payment_status = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$status, $membershipId]);

            // Staff-recorded payments require institution_admin approval
            if (authRole() === 'staff') {
                createApprovalRequest('membership_payment', $newPaymentId, $instId, authId());
                setFlash('success', 'Payment of ₹' . number_format($amount, 2) . ' recorded and submitted for approval.');
            } else {
                setFlash('success', 'Payment of ₹' . number_format($amount, 2) . ' recorded successfully.');
            }
            header('Location: ' . BASE_URL . '/app/members/payment-add?membership_id=' . $membershipId . '&member_id=' . $memberId);
            exit;
        }
    }
}

$fullName    = $ms['first_name'] . ' ' . $ms['last_name'];
$pageTitle   = 'Add Payment – ' . $fullName;
$breadcrumbs = [
    'Dashboard'  => dashboardUrl(),
    'Members'    => BASE_URL . '/app/members/list',
    $fullName    => BASE_URL . '/app/members/view?id=' . $memberId,
    'Add Payment'=> '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">

  <!-- Left: Membership Summary -->
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-card-checklist me-2 text-primary"></i>Membership Summary</div>
      <div class="card-body small">
        <div class="fw-bold mb-1"><?= h($ms['plan_name']) ?></div>
        <div class="text-muted mb-2 font-monospace" style="font-size:.75rem;"><?= h($ms['membership_number'] ?? '—') ?></div>

        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Type</span>
          <span class="badge <?= $ms['membership_type'] === 'new' ? 'bg-success' : 'bg-info text-dark' ?>"><?= ucfirst($ms['membership_type']) ?></span></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Period</span>
          <span><?= fmtDate($ms['start_date'], 'd M Y') ?> – <?= fmtDate($ms['end_date'], 'd M Y') ?></span></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Status</span><?= membershipStatusBadge($ms['end_date']) ?></div>

        <hr class="my-2">

        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Fee</span><span>₹<?= number_format($ms['net_amount'], 2) ?></span></div>
        <div class="d-flex justify-content-between mb-1 text-success"><span>Paid</span><strong>₹<?= number_format($totalPaid, 2) ?></strong></div>
        <div class="d-flex justify-content-between <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>">
          <span>Remaining</span><strong>₹<?= number_format($remaining, 2) ?></strong></div>

        <div class="mt-2"><?= paymentStatusBadge($ms['payment_status']) ?></div>
      </div>
    </div>

    <!-- Payment History -->
    <div class="card">
      <div class="card-header"><i class="bi bi-receipt me-2 text-primary"></i>Payment History</div>
      <div class="card-body p-0">
        <?php if ($payHistory): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($payHistory as $p): ?>
          <li class="list-group-item px-3 py-2 small">
            <div class="d-flex justify-content-between">
              <span class="fw-600">₹<?= number_format($p['amount'], 2) ?></span>
              <span class="text-muted"><?= fmtDate($p['payment_date'], 'd M Y') ?></span>
            </div>
            <div class="text-muted"><?= h(ucfirst(str_replace('_',' ',$p['payment_mode']))) ?>
              <?php if ($p['transaction_ref']): ?> · <?= h($p['transaction_ref']) ?><?php endif; ?></div>
            <?php if ($p['payment_proof']): ?>
            <a href="<?= h(PAYMENT_URL . '/' . $p['payment_proof']) ?>" target="_blank" class="small text-primary">
              <i class="bi bi-paperclip me-1"></i>View Proof
            </a>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty-state py-3">
          <i class="bi bi-receipt"></i>
          <p class="small">No payments yet.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Add Payment Form -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-cash-coin me-2 text-primary"></i>Record New Payment</div>
      <div class="card-body p-4">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($remaining <= 0): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 py-2 small">
          <i class="bi bi-check-circle-fill flex-shrink-0"></i>
          Full payment received for this membership. You can still add additional payments if needed.
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>

          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Payment Date <span class="required">*</span></label>
              <input type="date" class="form-control" name="payment_date"
                     value="<?= h($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Amount (₹) <span class="required">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="amount"
                       value="<?= h($_POST['amount'] ?? number_format($remaining, 2, '.', '')) ?>"
                       step="0.01" min="0.01" required>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Payment Mode <span class="required">*</span></label>
              <select class="form-select" name="payment_mode" required>
                <option value="">Select mode</option>
                <?php
                $modes = ['cash'=>'Cash','cheque'=>'Cheque','upi'=>'UPI','card'=>'Card / Debit / Credit',
                          'online'=>'Online Transfer','bank_transfer'=>'Bank Transfer','other'=>'Other'];
                foreach ($modes as $v => $l): ?>
                <option value="<?= h($v) ?>" <?= ($_POST['payment_mode'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Transaction Reference / Cheque No.</label>
              <input type="text" class="form-control" name="transaction_ref"
                     value="<?= h($_POST['transaction_ref'] ?? '') ?>"
                     placeholder="UTR, Cheque number, UPI ID…">
            </div>
            <div class="col-md-6">
              <label class="form-label">Receipt Number</label>
              <input type="text" class="form-control" name="receipt_number"
                     value="<?= h($_POST['receipt_number'] ?? '') ?>"
                     placeholder="Internal receipt number">
            </div>
            <div class="col-12">
              <label class="form-label">Payment Proof</label>
              <input type="file" class="form-control" name="payment_proof"
                     accept="image/*,.pdf">
              <div class="form-text">Upload screenshot, bank slip or receipt. JPG, PNG or PDF. Max 5 MB.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Remarks</label>
              <textarea class="form-control" name="remarks" rows="2"
                        placeholder="Any notes about this payment…"><?= h($_POST['remarks'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-cash me-2"></i>Record Payment
            </button>
            <a href="<?= h(BASE_URL . '/app/members/view?id=' . $memberId) ?>"
               class="btn btn-outline-secondary">Back to Member</a>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
