<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$instId = authInstId();
$id     = (int)($_GET['id'] ?? 0);

$ar = getApprovalRequest($id);
if (!$ar || (int)$ar['institution_id'] !== $instId) {
    setFlash('error', 'Approval request not found.');
    header('Location: ' . BASE_URL . '/app/approval');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isInstAdmin()) {
    verifyCsrf();
    $action  = $_POST['action']  ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if (!in_array($action, ['approved', 'rejected', 'cancelled'], true)) {
        $error = 'Invalid action.';
    } else {
        $ok = reviewApprovalRequest($id, authId(), $action, $comment ?: null);
        if ($ok) {
            setFlash('success', 'Request marked as ' . $action . '.');
            header('Location: ' . BASE_URL . '/app/approval/review?id=' . $id);
            exit;
        }
        $error = 'Could not update – request may have already been reviewed.';
    }
}

$entity  = getApprovalEntityDetails($ar['entity_type'], (int)$ar['entity_id']);
$history = getApprovalHistory($id);
$isPending = $ar['status'] === 'pending';

$pageTitle   = 'Approval Request #' . $id;
$breadcrumbs = [
    'Dashboard'      => dashboardUrl(),
    'Approval Queue' => BASE_URL . '/app/approval',
    'Request #' . $id => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">

  <!-- Left: Entity Details -->
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-header">
        <i class="bi bi-receipt me-2 text-primary"></i>
        <?= h(ucwords(str_replace('_', ' ', $ar['entity_type']))) ?> Details
      </div>
      <?php if ($entity): ?>
      <div class="card-body small">
        <div class="row g-2">
          <div class="col-6">
            <div class="text-muted">Member</div>
            <div class="fw-semibold"><?= h($entity['first_name'] . ' ' . $entity['last_name']) ?></div>
            <div class="text-muted font-monospace" style="font-size:.72rem;"><?= h($entity['member_code']) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted">Membership</div>
            <div><?= h($entity['plan_name']) ?></div>
            <div class="text-muted font-monospace" style="font-size:.72rem;"><?= h($entity['membership_number'] ?? '—') ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted">Payment Date</div>
            <div><?= fmtDate($entity['payment_date']) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted">Amount</div>
            <div class="fw-semibold text-success">₹<?= number_format($entity['amount'], 2) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted">Mode</div>
            <div><?= h(ucfirst(str_replace('_', ' ', $entity['payment_mode']))) ?></div>
          </div>
          <?php if ($entity['transaction_ref']): ?>
          <div class="col-12">
            <div class="text-muted">Ref / Cheque</div>
            <div><?= h($entity['transaction_ref']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($entity['receipt_number']): ?>
          <div class="col-12">
            <div class="text-muted">Receipt #</div>
            <div><?= h($entity['receipt_number']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($entity['remarks']): ?>
          <div class="col-12">
            <div class="text-muted">Remarks</div>
            <div><?= nl2br(h($entity['remarks'])) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($entity['payment_proof']): ?>
          <div class="col-12">
            <a href="<?= h(PAYMENT_URL . '/' . $entity['payment_proof']) ?>" target="_blank"
               class="btn btn-sm btn-outline-primary mt-1">
              <i class="bi bi-paperclip me-1"></i>View Payment Proof
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="card-body text-muted small">Entity #<?= $ar['entity_id'] ?> (details unavailable)</div>
      <?php endif; ?>
    </div>

    <!-- Approval History -->
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Approval History</div>
      <div class="card-body p-0">
        <div class="timeline p-4">
          <?php foreach ($history as $h_row): ?>
          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="small">
              <span class="fw-semibold"><?= h($h_row['actor_name'] ?? 'System') ?></span>
              <span class="text-muted ms-1">
                <?= match($h_row['action']) {
                  'submitted'  => 'submitted this request',
                  'approved'   => '<span class="text-success">approved</span> this request',
                  'rejected'   => '<span class="text-danger">rejected</span> this request',
                  'cancelled'  => 'cancelled this request',
                  'commented'  => 'added a comment',
                  default      => h($h_row['action']),
                } ?>
              </span>
              <?php if ($h_row['comment']): ?>
              <div class="text-muted mt-1 ps-2 border-start"><?= nl2br(h($h_row['comment'])) ?></div>
              <?php endif; ?>
              <div class="text-muted" style="font-size:.72rem;"><?= fmtDate($h_row['created_at'], 'd M Y, H:i') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Review Panel -->
  <div class="col-lg-5">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-clipboard-check me-2 text-primary"></i>Request Status</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Status</span><?= approvalStatusBadge($ar['status']) ?>
        </div>
        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Submitted by</span><span><?= h($ar['requester_name']) ?></span>
        </div>
        <div class="d-flex justify-content-between small">
          <span class="text-muted">Submitted at</span><span><?= fmtDate($ar['created_at'], 'd M Y, H:i') ?></span>
        </div>
        <?php if ($ar['notes']): ?>
        <hr class="my-2">
        <div class="small text-muted">Note from staff:</div>
        <div class="small"><?= nl2br(h($ar['notes'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isPending && isInstAdmin()): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-pen me-2 text-warning"></i>Review This Request</div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label small">Comment (optional)</label>
            <textarea class="form-control form-control-sm" name="comment" rows="3"
                      placeholder="Reason for approval or rejection…"></textarea>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" name="action" value="approved" class="btn btn-success">
              <i class="bi bi-check2-circle me-2"></i>Approve
            </button>
            <button type="submit" name="action" value="rejected"
                    class="btn btn-danger"
                    onclick="return confirm('Reject this payment request?');">
              <i class="bi bi-x-circle me-2"></i>Reject
            </button>
            <button type="submit" name="action" value="cancelled"
                    class="btn btn-outline-secondary btn-sm"
                    onclick="return confirm('Cancel this request?');">
              Cancel Request
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php elseif (!$isPending): ?>
    <div class="alert alert-secondary small">This request has been <?= h($ar['status']) ?> and is now closed.</div>
    <?php endif; ?>

    <div class="mt-3">
      <a href="<?= h(BASE_URL . '/app/approval') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Queue
      </a>
    </div>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
