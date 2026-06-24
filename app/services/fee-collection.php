<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action']     ?? '';
    $studentId = (int)($_POST['student_id'] ?? 0);

    if ($action === 'add' && $studentId) {
        $feeHeadId   = (int)($_POST['fee_head_id']   ?? 0);
        $amount      = $_POST['amount']               ?? '';
        $paymentDate = trim($_POST['payment_date']    ?? '');
        $paymentMode = $_POST['payment_mode']          ?? 'cash';
        $periodLabel = trim($_POST['period_label']    ?? '');
        $referenceNo = trim($_POST['reference_no']    ?? '');
        $receiptNo   = trim($_POST['receipt_no']      ?? '');
        $remarks     = trim($_POST['remarks']         ?? '');

        $validModes = ['cash','card','upi','cheque','bank_transfer','other'];

        $err = '';
        if (!$feeHeadId)                                      $err = 'Please select a fee head.';
        elseif (!is_numeric($amount) || (float)$amount <= 0)  $err = 'Amount must be greater than zero.';
        elseif (!$paymentDate)                                 $err = 'Payment date is required.';
        elseif (!in_array($paymentMode, $validModes, true))    $err = 'Invalid payment mode.';

        if (!$err) {
            // Verify student belongs to this institution
            $chk = $db->prepare("SELECT id FROM students WHERE id = ? AND institution_id = ?");
            $chk->execute([$studentId, $instId]);
            if (!$chk->fetch()) $err = 'Student not found.';
        }

        if ($err) {
            setFlash('error', $err);
        } else {
            $db->prepare(
                "INSERT INTO fee_payments
                     (institution_id, student_id, fee_head_id, period_label, amount,
                      payment_date, payment_mode, reference_no, receipt_no, remarks, collected_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $instId,
                $studentId,
                $feeHeadId,
                $periodLabel ?: null,
                number_format((float)$amount, 2, '.', ''),
                $paymentDate,
                $paymentMode,
                $referenceNo ?: null,
                $receiptNo   ?: null,
                $remarks     ?: null,
                authId(),
            ]);
            setFlash('success', 'Payment recorded successfully.');
        }
        header('Location: ' . BASE_URL . '/app/services/fee-collection?student_id=' . $studentId);
        exit;

    } elseif ($action === 'delete') {
        $payId = (int)($_POST['pay_id'] ?? 0);
        if ($payId) {
            $db->prepare(
                "DELETE FROM fee_payments WHERE id = ? AND institution_id = ?"
            )->execute([$payId, $instId]);
            setFlash('success', 'Payment record deleted.');
        }
        header('Location: ' . BASE_URL . '/app/services/fee-collection?student_id=' . $studentId);
        exit;
    }
}

// ── GET: search or load student ───────────────────────────────────────────────
$search    = trim($_GET['q']          ?? '');
$studentId = (int)($_GET['student_id'] ?? 0);

$student        = null;
$studentSection = '';
$feeHeads       = [];
$payments       = [];
$searchResults  = [];

// Load active fee heads (always needed for the add-payment select)
$fhStmt = $db->prepare(
    "SELECT id, name, default_amount, frequency FROM fee_heads
     WHERE institution_id = ? AND is_active = 1
     ORDER BY sort_order, name"
);
$fhStmt->execute([$instId]);
$feeHeads = $fhStmt->fetchAll();

// Index fee heads by id for quick lookup
$feeHeadsById = [];
foreach ($feeHeads as $fh) {
    $feeHeadsById[(int)$fh['id']] = $fh;
}

// ── Student search results ────────────────────────────────────────────────────
if ($search && !$studentId) {
    $like = '%' . $search . '%';
    $srStmt = $db->prepare(
        "SELECT s.id, s.first_name, s.last_name, s.admission_number, s.passport_photo,
                cls.name AS class_name, dv.name AS div_name
         FROM students s
         LEFT JOIN sections sec ON sec.id = s.section_id
         LEFT JOIN classes  cls ON cls.id = sec.class_id
         LEFT JOIN divisions dv ON  dv.id = sec.division_id
         WHERE s.institution_id = ? AND s.is_active = 1
           AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ?)
         ORDER BY s.first_name, s.last_name LIMIT 20"
    );
    $srStmt->execute([$instId, $like, $like, $like]);
    $searchResults = $srStmt->fetchAll();
}

// ── Load selected student ─────────────────────────────────────────────────────
if ($studentId) {
    $stStmt = $db->prepare(
        "SELECT s.id, s.first_name, s.last_name, s.admission_number, s.passport_photo,
                cls.name AS class_name, dv.name AS div_name
         FROM students s
         LEFT JOIN sections sec ON sec.id = s.section_id
         LEFT JOIN classes  cls ON cls.id = sec.class_id
         LEFT JOIN divisions dv ON  dv.id = sec.division_id
         WHERE s.id = ? AND s.institution_id = ?"
    );
    $stStmt->execute([$studentId, $instId]);
    $student = $stStmt->fetch();

    if ($student) {
        $parts = array_filter([$student['class_name'], $student['div_name']]);
        $studentSection = implode(' – ', $parts);

        // Last payment per fee head
        $lpStmt = $db->prepare(
            "SELECT fee_head_id, MAX(payment_date) AS last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(period_label ORDER BY payment_date DESC), ',', 1) AS last_period
             FROM fee_payments
             WHERE institution_id = ? AND student_id = ?
             GROUP BY fee_head_id"
        );
        $lpStmt->execute([$instId, $studentId]);
        $lastPayments = [];
        foreach ($lpStmt->fetchAll() as $lp) {
            $lastPayments[(int)$lp['fee_head_id']] = $lp;
        }

        // Payment history — last 20
        $phStmt = $db->prepare(
            "SELECT fp.id, fp.payment_date, fp.amount, fp.payment_mode, fp.period_label,
                    fp.reference_no, fp.receipt_no,
                    fh.name AS fee_head_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS collected_by_name
             FROM fee_payments fp
             JOIN fee_heads fh ON fh.id = fp.fee_head_id
             LEFT JOIN users u ON u.id = fp.collected_by
             WHERE fp.institution_id = ? AND fp.student_id = ?
             ORDER BY fp.payment_date DESC, fp.id DESC
             LIMIT 20"
        );
        $phStmt->execute([$instId, $studentId]);
        $payments = $phStmt->fetchAll();
    }
}

$freqLabels = [
    'monthly'     => 'Monthly',
    'quarterly'   => 'Quarterly',
    'half_yearly' => 'Half-Yearly',
    'annual'      => 'Annual',
    'one_time'    => 'One-Time',
];

$modeLabels = [
    'cash'          => 'Cash',
    'card'          => 'Card',
    'upi'           => 'UPI',
    'cheque'        => 'Cheque',
    'bank_transfer' => 'Bank Transfer',
    'other'         => 'Other',
];

$pageTitle   = 'Fee Collection';
$breadcrumbs = [
    'Dashboard'      => dashboardUrl(),
    'Services'       => BASE_URL . '/app/services',
    'Fee Collection' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-cash-coin"></i></div>
  <div>
    <h4>Fee Collection</h4>
    <p>Search a student, record fee payments, and view payment history.</p>
  </div>
</div>

<!-- ── Student Search ─────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-search me-2 text-primary"></i>Find Student
  </div>
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-8 col-md-6">
        <label class="form-label small mb-1">Search by name or admission number</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person-search"></i></span>
          <input type="text" class="form-control" name="q"
                 value="<?= h($search) ?>"
                 placeholder="e.g. Rahul, ADM-001">
        </div>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Search
        </button>
        <?php if ($search || $studentId): ?>
        <a href="<?= h(BASE_URL . '/app/services/fee-collection') ?>"
           class="btn btn-outline-secondary ms-1">Clear</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- ── Search Results ─────────────────────────────────────────────────────── -->
<?php if ($search && !$studentId): ?>
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-list-ul me-2 text-primary"></i>
    Search Results
    <span class="badge bg-secondary ms-1"><?= count($searchResults) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if ($searchResults): ?>
    <div class="list-group list-group-flush">
      <?php foreach ($searchResults as $sr): ?>
      <a href="<?= h(BASE_URL . '/app/services/fee-collection?student_id=' . $sr['id']) ?>"
         class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 px-3">
        <?php if (!empty($sr['passport_photo'])): ?>
        <img src="<?= h(PHOTO_URL . '/' . $sr['passport_photo']) ?>"
             alt="" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;">
        <?php else: ?>
        <div class="avatar-circle" style="width:36px;height:36px;font-size:.8rem;border-radius:6px;flex-shrink:0;">
          <?= mb_strtoupper(mb_substr($sr['first_name'], 0, 1)) ?>
        </div>
        <?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-600 small"><?= h($sr['first_name'] . ' ' . $sr['last_name']) ?></div>
          <div class="text-muted" style="font-size:.72rem;">
            <?= h($sr['admission_number']) ?>
            <?php if ($sr['class_name']): ?>
            &nbsp;·&nbsp;<?= h($sr['class_name']) ?><?= $sr['div_name'] ? ' – ' . h($sr['div_name']) : '' ?>
            <?php endif; ?>
          </div>
        </div>
        <i class="bi bi-chevron-right text-muted"></i>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state py-4">
      <i class="bi bi-person-x"></i>
      <h6>No students found</h6>
      <p class="small">Try a different name or admission number.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Student Selected View ─────────────────────────────────────────────── -->
<?php if ($student): ?>

<!-- Student Info Card -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex align-items-center gap-3">
      <?php if (!empty($student['passport_photo'])): ?>
      <img src="<?= h(PHOTO_URL . '/' . $student['passport_photo']) ?>"
           alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;">
      <?php else: ?>
      <div class="avatar-circle" style="width:64px;height:64px;font-size:1.4rem;border-radius:10px;flex-shrink:0;">
        <?= mb_strtoupper(mb_substr($student['first_name'], 0, 1)) ?>
      </div>
      <?php endif; ?>
      <div>
        <h5 class="mb-1"><?= h($student['first_name'] . ' ' . $student['last_name']) ?></h5>
        <div class="text-muted small">
          <i class="bi bi-card-text me-1"></i><?= h($student['admission_number']) ?>
          <?php if ($studentSection): ?>
          &nbsp;·&nbsp;<i class="bi bi-diagram-3 me-1"></i><?= h($studentSection) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">

  <!-- ── Active Fee Heads ──────────────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-tags me-2 text-primary"></i>Fee Heads
      </div>
      <div class="card-body p-0">
        <?php if ($feeHeads): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Fee Head</th>
                <th>Frequency</th>
                <th>Last Payment</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($feeHeads as $fh):
                $lp = $lastPayments[(int)$fh['id']] ?? null;
              ?>
              <tr>
                <td>
                  <div class="fw-600 small"><?= h($fh['name']) ?></div>
                  <div class="text-muted" style="font-size:.72rem;">₹<?= number_format((float)$fh['default_amount'], 2) ?></div>
                </td>
                <td><span class="badge bg-secondary bg-opacity-75 small"><?= h($freqLabels[$fh['frequency']] ?? $fh['frequency']) ?></span></td>
                <td class="small text-muted">
                  <?php if ($lp): ?>
                    <?= fmtDate($lp['last_date'], 'd M Y') ?>
                    <?php if ($lp['last_period']): ?>
                    <div style="font-size:.7rem;"><?= h($lp['last_period']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-warning">No payments</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-3">
          <i class="bi bi-tags"></i>
          <p class="small mb-0">No active fee heads.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Add Payment Form ──────────────────────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>Record Payment
      </div>
      <div class="card-body">
        <?php if (!$feeHeads): ?>
        <div class="alert alert-warning mb-0">
          <i class="bi bi-exclamation-triangle me-2"></i>
          No active fee heads found. Please add fee heads in Settings first.
        </div>
        <?php else: ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">

          <div class="row g-3">
            <div class="col-sm-12">
              <label class="form-label">Fee Head <span class="required">*</span></label>
              <select class="form-select" name="fee_head_id" id="feeHeadSelect" required>
                <option value="">— Select fee head —</option>
                <?php foreach ($feeHeads as $fh): ?>
                <option value="<?= (int)$fh['id'] ?>"
                        data-amount="<?= h($fh['default_amount']) ?>">
                  <?= h($fh['name']) ?> (₹<?= number_format((float)$fh['default_amount'], 2) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-6">
              <label class="form-label">Amount (₹) <span class="required">*</span></label>
              <input type="number" class="form-control" name="amount" id="payAmount"
                     step="0.01" min="0.01" placeholder="0.00" required>
            </div>

            <div class="col-sm-6">
              <label class="form-label">Payment Date <span class="required">*</span></label>
              <input type="date" class="form-control" name="payment_date"
                     value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="col-sm-6">
              <label class="form-label">Payment Mode <span class="required">*</span></label>
              <select class="form-select" name="payment_mode">
                <?php foreach ($modeLabels as $val => $lbl): ?>
                <option value="<?= $val ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-6">
              <label class="form-label">Period Label</label>
              <input type="text" class="form-control" name="period_label"
                     placeholder="e.g. Jun 2024" maxlength="20">
            </div>

            <div class="col-sm-6">
              <label class="form-label">Reference No.</label>
              <input type="text" class="form-control" name="reference_no"
                     placeholder="UTR / cheque no." maxlength="100">
            </div>

            <div class="col-sm-6">
              <label class="form-label">Receipt No.</label>
              <input type="text" class="form-control" name="receipt_no"
                     placeholder="Receipt number" maxlength="50">
            </div>

            <div class="col-sm-12">
              <label class="form-label">Remarks</label>
              <input type="text" class="form-control" name="remarks"
                     placeholder="Optional notes" maxlength="300">
            </div>
          </div>

          <div class="mt-3">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-cash me-1"></i>Save Payment
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->

<!-- ── Payment History ───────────────────────────────────────────────────── -->
<div class="card mt-4">
  <div class="card-header">
    <i class="bi bi-clock-history me-2 text-primary"></i>
    Payment History
    <span class="badge bg-secondary ms-1"><?= count($payments) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if ($payments): ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Fee Head</th>
            <th>Amount</th>
            <th>Mode</th>
            <th>Period</th>
            <th>Receipt No.</th>
            <th>Collected By</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $pay): ?>
          <tr>
            <td class="text-nowrap small"><?= fmtDate($pay['payment_date'], 'd M Y') ?></td>
            <td class="small fw-600"><?= h($pay['fee_head_name']) ?></td>
            <td class="fw-600 text-success small">₹<?= number_format((float)$pay['amount'], 2) ?></td>
            <td><span class="badge bg-secondary bg-opacity-75"><?= h($modeLabels[$pay['payment_mode']] ?? $pay['payment_mode']) ?></span></td>
            <td class="small text-muted"><?= $pay['period_label'] ? h($pay['period_label']) : '—' ?></td>
            <td class="small text-muted"><?= $pay['receipt_no'] ? h($pay['receipt_no']) : '—' ?></td>
            <td class="small text-muted"><?= $pay['collected_by_name'] ? h($pay['collected_by_name']) : '—' ?></td>
            <td>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                <input type="hidden" name="pay_id" value="<?= (int)$pay['id'] ?>">
                <button type="submit"
                        class="btn btn-sm btn-outline-danger btn-icon"
                        title="Delete" data-bs-toggle="tooltip"
                        data-confirm="Delete this payment record? This cannot be undone.">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state py-4">
      <i class="bi bi-receipt"></i>
      <h6>No payments recorded</h6>
      <p class="small">Use the form above to record the first payment for this student.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif (!$search): ?>
<!-- Default empty state -->
<div class="card">
  <div class="card-body">
    <div class="empty-state py-5">
      <i class="bi bi-person-vcard"></i>
      <h6>No student selected</h6>
      <p class="small">Use the search above to find a student and record payments.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
    const sel = document.getElementById('feeHeadSelect');
    const amt = document.getElementById('payAmount');
    if (!sel || !amt) return;
    sel.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        const def = opt ? (opt.dataset.amount || '') : '';
        if (def) amt.value = parseFloat(def).toFixed(2);
    });
})();
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
