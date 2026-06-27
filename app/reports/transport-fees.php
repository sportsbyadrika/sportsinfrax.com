<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$modeLabels = [
    'cash'          => 'Cash',
    'card'          => 'Card',
    'upi'           => 'UPI',
    'cheque'        => 'Cheque',
    'bank_transfer' => 'Bank Transfer',
    'other'         => 'Other',
];

$freqLabels = [
    'monthly'     => 'Monthly',
    'quarterly'   => 'Quarterly',
    'half_yearly' => 'Half-Yearly',
    'annual'      => 'Annual',
    'one_time'    => 'One-Time',
];

$today          = date('Y-m-d');
$firstOfMonth   = date('Y-m-01');

$filterFrom   = trim($_GET['from_date']    ?? $firstOfMonth);
$filterTo     = trim($_GET['to_date']      ?? $today);
$filterRoute  = (int)($_GET['route_id']    ?? 0);
$filterMode   = trim($_GET['payment_mode'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) { $filterFrom = $firstOfMonth; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   { $filterTo   = $today; }
if ($filterTo < $filterFrom) { $filterTo = $filterFrom; }

$routeStmt = $db->prepare(
    "SELECT id, name FROM transport_routes WHERE institution_id = ? ORDER BY name"
);
$routeStmt->execute([$instId]);
$allRoutes = $routeStmt->fetchAll();

$where  = "tfp.institution_id = ? AND tfp.payment_date BETWEEN ? AND ?";
$params = [$instId, $filterFrom, $filterTo];

if ($filterRoute) {
    $where   .= " AND tsa.route_id = ?";
    $params[] = $filterRoute;
}

$validModes = ['cash', 'card', 'upi', 'cheque', 'bank_transfer', 'other'];
if ($filterMode && in_array($filterMode, $validModes, true)) {
    $where   .= " AND tfp.payment_mode = ?";
    $params[] = $filterMode;
}

$sumStmt = $db->prepare(
    "SELECT
         SUM(tfp.amount)                  AS total_amount,
         COUNT(tfp.id)                    AS total_payments,
         COUNT(DISTINCT tfp.student_id)   AS total_students,
         COUNT(DISTINCT tsa.route_id)     AS total_routes
     FROM transport_fee_payments tfp
     JOIN transport_student_assignments tsa ON tsa.id = tfp.assignment_id
     WHERE {$where}"
);
$sumStmt->execute($params);
$summaryData = $sumStmt->fetch();

$routeBreakStmt = $db->prepare(
    "SELECT tr.name AS route_name,
            COUNT(tfp.id)                  AS payment_count,
            COUNT(DISTINCT tfp.student_id) AS student_count,
            SUM(tfp.amount)                AS total_amount
     FROM transport_fee_payments tfp
     JOIN transport_student_assignments tsa ON tsa.id = tfp.assignment_id
     JOIN transport_routes tr ON tr.id = tsa.route_id
     WHERE {$where}
     GROUP BY tsa.route_id, tr.name
     ORDER BY tr.name"
);
$routeBreakStmt->execute($params);
$routeBreakdown = $routeBreakStmt->fetchAll();

$modeBreakStmt = $db->prepare(
    "SELECT tfp.payment_mode,
            COUNT(tfp.id)   AS payment_count,
            SUM(tfp.amount) AS total_amount
     FROM transport_fee_payments tfp
     JOIN transport_student_assignments tsa ON tsa.id = tfp.assignment_id
     WHERE {$where}
     GROUP BY tfp.payment_mode
     ORDER BY total_amount DESC"
);
$modeBreakStmt->execute($params);
$modeBreakdown = $modeBreakStmt->fetchAll();

$detStmt = $db->prepare(
    "SELECT tfp.id, tfp.payment_date, tfp.period_label, tfp.amount, tfp.payment_mode,
            tfp.reference_no, tfp.receipt_no,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.admission_number,
            cls.name AS class_name, dv.name AS div_name,
            tr.name AS route_name, trs.stop_name,
            u.full_name AS collected_by_name
     FROM transport_fee_payments tfp
     JOIN transport_student_assignments tsa ON tsa.id = tfp.assignment_id
     JOIN students s ON s.id = tfp.student_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     LEFT JOIN classes cls ON cls.id = sec.class_id
     LEFT JOIN divisions dv ON dv.id = sec.division_id
     JOIN transport_routes tr ON tr.id = tsa.route_id
     LEFT JOIN transport_route_stops trs ON trs.id = tsa.stop_id
     LEFT JOIN users u ON u.id = tfp.collected_by
     WHERE {$where}
     ORDER BY tfp.payment_date DESC, tr.name, s.first_name"
);
$detStmt->execute($params);
$detailRows = $detStmt->fetchAll();

$totalCollected = (float)($summaryData['total_amount']   ?? 0);
$totalPayments  = (int)  ($summaryData['total_payments'] ?? 0);
$totalStudents  = (int)  ($summaryData['total_students'] ?? 0);
$totalRoutes    = (int)  ($summaryData['total_routes']   ?? 0);

$modeBadge = [
    'cash'          => 'bg-success',
    'card'          => 'bg-info text-dark',
    'upi'           => 'bg-primary',
    'cheque'        => 'bg-warning text-dark',
    'bank_transfer' => 'bg-secondary',
    'other'         => 'bg-light text-dark',
];

$pageTitle   = 'Transport Fee Report';
$breadcrumbs = [
    'Dashboard'          => dashboardUrl(),
    'Reports'            => BASE_URL . '/app/reports',
    'Transport Fees'     => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<!-- ── Section header ──────────────────────────────────────────────────────── -->
<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-bus-front"></i></div>
  <div>
    <h4>Transport Fee Report</h4>
    <p>Route-wise and student-wise transport fee collection summary.</p>
  </div>
</div>

<!-- ── Filter Card ────────────────────────────────────────────────────────── -->
<div class="card mb-4 no-print">
  <div class="card-header">
    <i class="bi bi-funnel me-2 text-primary"></i>Filters
  </div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-sm-6 col-md-2">
        <label class="form-label">From Date</label>
        <input type="date" class="form-control" name="from_date"
               value="<?= h($filterFrom) ?>">
      </div>

      <div class="col-sm-6 col-md-2">
        <label class="form-label">To Date</label>
        <input type="date" class="form-control" name="to_date"
               value="<?= h($filterTo) ?>">
      </div>

      <div class="col-sm-6 col-md-3">
        <label class="form-label">Route</label>
        <select class="form-select" name="route_id">
          <option value="">All Routes</option>
          <?php foreach ($allRoutes as $rt): ?>
          <option value="<?= (int)$rt['id'] ?>" <?= $filterRoute === (int)$rt['id'] ? 'selected' : '' ?>>
            <?= h($rt['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select class="form-select" name="payment_mode">
          <option value="">All</option>
          <?php foreach ($modeLabels as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $filterMode === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Search
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
          <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="<?= h(BASE_URL . '/app/reports/transport-fees') ?>" class="btn btn-outline-secondary">
          Reset
        </a>
      </div>
    </form>
  </div>
</div>

<?php if (!$detailRows): ?>
<!-- ── Empty state ────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-body">
    <div class="empty-state py-5">
      <i class="bi bi-bus-front"></i>
      <h6>No transport payments found</h6>
      <p class="small">No transport payments found for the selected period.</p>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ── Summary Cards ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-currency-rupee me-1"></i>Total Collected</div>
        <div class="fw-bold fs-4 text-success">₹<?= number_format($totalCollected, 2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-receipt me-1"></i># Payments</div>
        <div class="fw-bold fs-4"><?= number_format($totalPayments) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-people me-1"></i># Students</div>
        <div class="fw-bold fs-4"><?= number_format($totalStudents) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card text-center">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-signpost-split me-1"></i># Routes</div>
        <div class="fw-bold fs-4"><?= number_format($totalRoutes) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Breakdown Tables ───────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Route summary -->
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-signpost-split me-2 text-primary"></i>Route Summary
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Route</th>
                <th class="text-center">Payments</th>
                <th class="text-center">Students</th>
                <th class="text-end">Total Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($routeBreakdown as $row): ?>
              <tr>
                <td class="small fw-semibold"><?= h($row['route_name']) ?></td>
                <td class="text-center small"><?= (int)$row['payment_count'] ?></td>
                <td class="text-center small"><?= (int)$row['student_count'] ?></td>
                <td class="text-end small text-success fw-semibold">₹<?= number_format((float)$row['total_amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-secondary fw-bold">
              <tr>
                <td>Total</td>
                <td class="text-center"><?= $totalPayments ?></td>
                <td class="text-center"><?= $totalStudents ?></td>
                <td class="text-end text-success">₹<?= number_format($totalCollected, 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment mode breakdown -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-credit-card me-2 text-primary"></i>Payment Mode Breakdown
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Mode</th>
                <th class="text-center">Count</th>
                <th class="text-end">Amount</th>
                <th class="text-end">% of Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($modeBreakdown as $row): ?>
              <?php $pct = $totalCollected > 0 ? round((float)$row['total_amount'] / $totalCollected * 100, 1) : 0; ?>
              <tr>
                <td class="small fw-semibold"><?= h($modeLabels[$row['payment_mode']] ?? $row['payment_mode']) ?></td>
                <td class="text-center small"><?= (int)$row['payment_count'] ?></td>
                <td class="text-end small text-success fw-semibold">₹<?= number_format((float)$row['total_amount'], 2) ?></td>
                <td class="text-end small text-muted"><?= $pct ?>%</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-secondary fw-bold">
              <tr>
                <td>Total</td>
                <td class="text-center"><?= $totalPayments ?></td>
                <td class="text-end text-success">₹<?= number_format($totalCollected, 2) ?></td>
                <td class="text-end">100%</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Detailed Payments Table ───────────────────────────────────────────── -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-table me-2 text-primary"></i>
      Payment Details
      <span class="badge bg-secondary ms-1"><?= count($detailRows) ?></span>
    </span>
    <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Student</th>
            <th>Class</th>
            <th>Route</th>
            <th>Stop</th>
            <th>Period</th>
            <th class="text-end">Amount</th>
            <th>Mode</th>
            <th>Receipt No.</th>
            <th>Collected By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detailRows as $row): ?>
          <tr>
            <td class="text-nowrap small"><?= fmtDate($row['payment_date'], 'd M Y') ?></td>
            <td>
              <div class="fw-semibold small"><?= h($row['student_name']) ?></div>
              <div class="text-muted" style="font-size:.7rem;"><?= h($row['admission_number']) ?></div>
            </td>
            <td class="small">
              <?php
                $cls = trim(($row['class_name'] ?? '') . ' ' . ($row['div_name'] ?? ''));
                echo $cls ? h($cls) : '—';
              ?>
            </td>
            <td class="small"><?= h($row['route_name']) ?></td>
            <td class="small text-muted"><?= $row['stop_name'] ? h($row['stop_name']) : '—' ?></td>
            <td class="small text-muted"><?= $row['period_label'] ? h($row['period_label']) : '—' ?></td>
            <td class="text-end fw-semibold text-success small">₹<?= number_format((float)$row['amount'], 2) ?></td>
            <td>
              <?php $mode = $row['payment_mode']; ?>
              <span class="badge <?= h($modeBadge[$mode] ?? 'bg-secondary') ?>">
                <?= h($modeLabels[$mode] ?? $mode) ?>
              </span>
            </td>
            <td class="small text-muted"><?= $row['receipt_no'] ? h($row['receipt_no']) : '—' ?></td>
            <td class="small text-muted"><?= $row['collected_by_name'] ? h($row['collected_by_name']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-secondary fw-bold">
          <tr>
            <td colspan="6">Total</td>
            <td class="text-end text-success">₹<?= number_format($totalCollected, 2) ?></td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<style>
@media print {
  .no-print, .sidebar, nav, .section-header-strip, form { display: none !important; }
  @page { size: landscape; margin: 1cm; }
  body { font-size: 8pt; }
  .card { border: 1px solid #ccc !important; break-inside: avoid; }
}
</style>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
