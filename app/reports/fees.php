<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// ── Month / year helpers ──────────────────────────────────────────────────────
$monthNames = [
    1  => 'January',  2  => 'February', 3  => 'March',    4  => 'April',
    5  => 'May',      6  => 'June',     7  => 'July',      8  => 'August',
    9  => 'September',10 => 'October',  11 => 'November',  12 => 'December',
];

$modeLabels = [
    'cash'          => 'Cash',
    'card'          => 'Card',
    'upi'           => 'UPI',
    'cheque'        => 'Cheque',
    'bank_transfer' => 'Bank Transfer',
    'other'         => 'Other',
];

$currentYear = (int)date('Y');

// ── Filters ───────────────────────────────────────────────────────────────────
$filterMonth    = (int)($_GET['month']       ?? 0);
$filterYear     = (int)($_GET['year']        ?? $currentYear);
$filterFeeHead  = (int)($_GET['fee_head_id'] ?? 0);
$filterMode     = trim($_GET['payment_mode'] ?? '');

$filterMonth = max(0, min(12, $filterMonth));
$filterYear  = max($currentYear - 1, min($currentYear + 1, $filterYear));

$hasFilters = $filterMonth > 0 && $filterYear > 0;

// ── Fee heads for dropdown ────────────────────────────────────────────────────
$fhStmt = $db->prepare(
    "SELECT id, name FROM fee_heads WHERE institution_id = ? ORDER BY sort_order, name"
);
$fhStmt->execute([$instId]);
$allFeeHeads = $fhStmt->fetchAll();

// ── Build query base ──────────────────────────────────────────────────────────
$summaryData    = null;
$feeHeadBreakdown = [];
$modeBreakdown    = [];
$detailRows       = [];

if ($hasFilters) {
    $where  = "fp.institution_id = ? AND MONTH(fp.payment_date) = ? AND YEAR(fp.payment_date) = ?";
    $params = [$instId, $filterMonth, $filterYear];

    if ($filterFeeHead) {
        $where   .= " AND fp.fee_head_id = ?";
        $params[] = $filterFeeHead;
    }

    $validModes = ['cash','card','upi','cheque','bank_transfer','other'];
    if ($filterMode && in_array($filterMode, $validModes, true)) {
        $where   .= " AND fp.payment_mode = ?";
        $params[] = $filterMode;
    }

    // ── Summary ───────────────────────────────────────────────────────────────
    $sumStmt = $db->prepare(
        "SELECT
             SUM(fp.amount) AS total_amount,
             COUNT(fp.id)   AS total_payments,
             COUNT(DISTINCT fp.student_id) AS total_students
         FROM fee_payments fp
         WHERE {$where}"
    );
    $sumStmt->execute($params);
    $summaryData = $sumStmt->fetch();

    // ── Fee-head breakdown ────────────────────────────────────────────────────
    $fhbStmt = $db->prepare(
        "SELECT fh.name AS fee_head_name, fh.frequency,
                COUNT(fp.id)   AS payment_count,
                SUM(fp.amount) AS total_amount
         FROM fee_payments fp
         JOIN fee_heads fh ON fh.id = fp.fee_head_id
         WHERE {$where}
         GROUP BY fp.fee_head_id, fh.name, fh.frequency, fh.sort_order
         ORDER BY fh.sort_order, fh.name"
    );
    $fhbStmt->execute($params);
    $feeHeadBreakdown = $fhbStmt->fetchAll();

    // ── Mode breakdown ────────────────────────────────────────────────────────
    $mbStmt = $db->prepare(
        "SELECT fp.payment_mode,
                COUNT(fp.id)   AS payment_count,
                SUM(fp.amount) AS total_amount
         FROM fee_payments fp
         WHERE {$where}
         GROUP BY fp.payment_mode
         ORDER BY total_amount DESC"
    );
    $mbStmt->execute($params);
    $modeBreakdown = $mbStmt->fetchAll();

    // ── Detailed payments (limit 100) ─────────────────────────────────────────
    $detStmt = $db->prepare(
        "SELECT fp.payment_date, fp.amount, fp.payment_mode,
                fp.period_label, fp.reference_no, fp.receipt_no,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.admission_number,
                fh.name AS fee_head_name,
                u.full_name AS collected_by_name
         FROM fee_payments fp
         JOIN students s  ON s.id  = fp.student_id
         JOIN fee_heads fh ON fh.id = fp.fee_head_id
         LEFT JOIN users u ON u.id = fp.collected_by
         WHERE {$where}
         ORDER BY fp.payment_date DESC, fp.id DESC
         LIMIT 100"
    );
    $detStmt->execute($params);
    $detailRows = $detStmt->fetchAll();
}

$freqLabels = [
    'monthly'     => 'Monthly',
    'quarterly'   => 'Quarterly',
    'half_yearly' => 'Half-Yearly',
    'annual'      => 'Annual',
    'one_time'    => 'One-Time',
];

$pageTitle   = 'Fee Report';
$breadcrumbs = [
    'Dashboard'  => dashboardUrl(),
    'Reports'    => BASE_URL . '/app/reports',
    'Fee Report' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<style media="print">
    @page { size: landscape; margin: 0.6cm; }
    .no-print,
    nav.navbar,
    .app-footer,
    .page-header,
    .breadcrumb,
    .filter-card { display: none !important; }
    .app-main { padding: 0 !important; }
    .container-fluid { padding: 0 !important; }
    .print-title { display: block !important; }
    body, table { font-size: 8pt !important; }
    .table th, .table td { padding: 2px 4px !important; }
    .badge { border: 1px solid #888; color: #000 !important;
             background: transparent !important; font-size: 7pt !important; }
    .card { border: 1px solid #ccc !important; margin-bottom: .3cm !important; }
    .card-header { background: #eee !important; color: #000 !important;
                   padding: 3px 6px !important; }
    .summary-cards .card { display: inline-block; width: 30%; margin: 2px; }
</style>

<!-- Print title (hidden on screen) -->
<div class="print-title d-none mb-2">
  <h5 class="mb-0">
    Fee Collection Report
    <?php if ($hasFilters): ?>
    – <?= h($monthNames[$filterMonth]) ?> <?= h((string)$filterYear) ?>
    <?php endif; ?>
  </h5>
</div>

<!-- ── Filter Card ────────────────────────────────────────────────────────── -->
<div class="card mb-4 filter-card no-print">
  <div class="card-header">
    <i class="bi bi-funnel me-2 text-primary"></i>Filters
  </div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Month <span class="required">*</span></label>
        <select class="form-select" name="month" required>
          <option value="">— Month —</option>
          <?php foreach ($monthNames as $num => $name): ?>
          <option value="<?= $num ?>" <?= $filterMonth === $num ? 'selected' : '' ?>><?= $name ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-md-2">
        <label class="form-label">Year <span class="required">*</span></label>
        <select class="form-select" name="year">
          <?php for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++): ?>
          <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-sm-6 col-md-3">
        <label class="form-label">Fee Head</label>
        <select class="form-select" name="fee_head_id">
          <option value="">All Heads</option>
          <?php foreach ($allFeeHeads as $fh): ?>
          <option value="<?= (int)$fh['id'] ?>" <?= $filterFeeHead === (int)$fh['id'] ? 'selected' : '' ?>>
            <?= h($fh['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select class="form-select" name="payment_mode">
          <option value="">All Modes</option>
          <?php foreach ($modeLabels as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $filterMode === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>View Report
        </button>
        <?php if ($hasFilters): ?>
        <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()">
          <i class="bi bi-printer me-1"></i>Print
        </button>
        <?php endif; ?>
        <a href="<?= h(BASE_URL . '/app/reports/fees') ?>" class="btn btn-outline-secondary no-print">
          Reset
        </a>
      </div>
    </form>
  </div>
</div>

<?php if (!$hasFilters): ?>
<!-- Prompt -->
<div class="card">
  <div class="card-body">
    <div class="alert alert-info d-flex align-items-center gap-3 mb-0">
      <i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i>
      <div>Select a <strong>month</strong> and <strong>year</strong> above to generate the report.</div>
    </div>
  </div>
</div>
<?php else: ?>

<?php
  $totalCollected = (float)($summaryData['total_amount']   ?? 0);
  $totalPayments  = (int)  ($summaryData['total_payments'] ?? 0);
  $totalStudents  = (int)  ($summaryData['total_students'] ?? 0);
?>

<!-- ── Summary Cards ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4 summary-cards">
  <div class="col-sm-4">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-currency-rupee me-1"></i>Total Collected</div>
        <div class="fw-bold fs-4 text-success">₹<?= number_format($totalCollected, 2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-receipt me-1"></i>Payments</div>
        <div class="fw-bold fs-4"><?= number_format($totalPayments) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-people me-1"></i>Students</div>
        <div class="fw-bold fs-4"><?= number_format($totalStudents) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Breakdown Tables ───────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Fee-head breakdown -->
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-tags me-2 text-primary"></i>By Fee Head
      </div>
      <div class="card-body p-0">
        <?php if ($feeHeadBreakdown): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Fee Head</th>
                <th>Frequency</th>
                <th class="text-center">Payments</th>
                <th class="text-end">Total (₹)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($feeHeadBreakdown as $row): ?>
              <tr>
                <td class="fw-600 small"><?= h($row['fee_head_name']) ?></td>
                <td><span class="badge bg-secondary bg-opacity-75"><?= h($freqLabels[$row['frequency']] ?? $row['frequency']) ?></span></td>
                <td class="text-center small"><?= (int)$row['payment_count'] ?></td>
                <td class="text-end fw-600 text-success small">₹<?= number_format((float)$row['total_amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-secondary fw-bold">
              <tr>
                <td colspan="2">Total</td>
                <td class="text-center"><?= $totalPayments ?></td>
                <td class="text-end text-success">₹<?= number_format($totalCollected, 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-tags"></i>
          <p class="small mb-0">No data for selected filters.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Payment mode breakdown -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-credit-card me-2 text-primary"></i>By Payment Mode
      </div>
      <div class="card-body p-0">
        <?php if ($modeBreakdown): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Mode</th>
                <th class="text-center">Payments</th>
                <th class="text-end">Total (₹)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($modeBreakdown as $row): ?>
              <tr>
                <td class="small fw-600"><?= h($modeLabels[$row['payment_mode']] ?? $row['payment_mode']) ?></td>
                <td class="text-center small"><?= (int)$row['payment_count'] ?></td>
                <td class="text-end fw-600 text-success small">₹<?= number_format((float)$row['total_amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-secondary fw-bold">
              <tr>
                <td>Total</td>
                <td class="text-center"><?= $totalPayments ?></td>
                <td class="text-end text-success">₹<?= number_format($totalCollected, 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-credit-card"></i>
          <p class="small mb-0">No data for selected filters.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->

<!-- ── Detailed Payments Table ───────────────────────────────────────────── -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-table me-2 text-primary"></i>
      Payment Details
      <span class="badge bg-secondary ms-1"><?= count($detailRows) ?></span>
      <?php if (count($detailRows) >= 100): ?>
      <span class="badge bg-warning text-dark ms-1">First 100 shown</span>
      <?php endif; ?>
    </span>
    <button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
  <div class="card-body p-0">
    <?php if ($detailRows): ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Student</th>
            <th>Fee Head</th>
            <th>Period</th>
            <th class="text-end">Amount (₹)</th>
            <th>Mode</th>
            <th>Reference No.</th>
            <th>Receipt No.</th>
            <th>Collected By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detailRows as $row): ?>
          <tr>
            <td class="text-nowrap small"><?= fmtDate($row['payment_date'], 'd M Y') ?></td>
            <td>
              <div class="fw-600 small"><?= h($row['student_name']) ?></div>
              <div class="text-muted" style="font-size:.7rem;"><?= h($row['admission_number']) ?></div>
            </td>
            <td class="small"><?= h($row['fee_head_name']) ?></td>
            <td class="small text-muted"><?= $row['period_label'] ? h($row['period_label']) : '—' ?></td>
            <td class="text-end fw-600 text-success small">₹<?= number_format((float)$row['amount'], 2) ?></td>
            <td><span class="badge bg-secondary bg-opacity-75"><?= h($modeLabels[$row['payment_mode']] ?? $row['payment_mode']) ?></span></td>
            <td class="small text-muted"><?= $row['reference_no'] ? h($row['reference_no']) : '—' ?></td>
            <td class="small text-muted"><?= $row['receipt_no'] ? h($row['receipt_no']) : '—' ?></td>
            <td class="small text-muted"><?= $row['collected_by_name'] ? h($row['collected_by_name']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-secondary fw-bold">
          <tr>
            <td colspan="4">Total</td>
            <td class="text-end text-success">₹<?= number_format($totalCollected, 2) ?></td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state py-5">
      <i class="bi bi-receipt"></i>
      <h6>No payments found</h6>
      <p class="small">No fee payments match the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; // $hasFilters ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
