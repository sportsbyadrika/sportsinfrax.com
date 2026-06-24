<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$month       = (int)($_GET['month'] ?? date('n'));
$year        = (int)($_GET['year']  ?? date('Y'));
$month       = max(1, min(12, $month));
$currentYear = (int)date('Y');

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

$monthNames = [
    1  => 'January',  2  => 'February', 3  => 'March',    4  => 'April',
    5  => 'May',      6  => 'June',     7  => 'July',      8  => 'August',
    9  => 'September',10 => 'October',  11 => 'November',  12 => 'December',
];

// ── Data ──────────────────────────────────────────────────────────────────────

$staffStmt = $db->prepare(
    "SELECT s.id, u.full_name
     FROM staff s
     JOIN users u ON u.id = s.user_id
     WHERE s.institution_id = ? AND s.is_active = 1
     ORDER BY u.full_name"
);
$staffStmt->execute([$instId]);
$staffRows = $staffStmt->fetchAll();

$attStmt = $db->prepare(
    "SELECT staff_id, attendance_date, status
     FROM staff_attendance
     WHERE institution_id = ? AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?"
);
$attStmt->execute([$instId, $month, $year]);
$attRows = $attStmt->fetchAll();

// ── Build pivot ───────────────────────────────────────────────────────────────

$pivot = [];
foreach ($staffRows as $row) {
    $pivot[$row['id']] = ['name' => $row['full_name'], 'days' => []];
}
foreach ($attRows as $row) {
    $day = (int)date('j', strtotime($row['attendance_date']));
    if (isset($pivot[$row['staff_id']])) {
        $pivot[$row['staff_id']]['days'][$day] = $row['status'];
    }
}

foreach ($pivot as $sid => &$data) {
    $data['totals'] = ['P' => 0, 'A' => 0, 'H' => 0, 'L' => 0];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        match($data['days'][$d] ?? '') {
            'present'  => $data['totals']['P']++,
            'absent'   => $data['totals']['A']++,
            'half_day' => $data['totals']['H']++,
            'leave'    => $data['totals']['L']++,
            default    => null,
        };
    }
}
unset($data);

// ── Column totals ─────────────────────────────────────────────────────────────

$colTotals = ['P' => 0, 'A' => 0, 'H' => 0, 'L' => 0];
foreach ($pivot as $data) {
    $colTotals['P'] += $data['totals']['P'];
    $colTotals['A'] += $data['totals']['A'];
    $colTotals['H'] += $data['totals']['H'];
    $colTotals['L'] += $data['totals']['L'];
}

// ── Page setup ────────────────────────────────────────────────────────────────

$pageTitle   = 'Staff Attendance Report';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Reports'   => BASE_URL . '/app/reports',
    'Staff Attendance' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<style media="print">
    @page { size: landscape; margin: 0.5cm; }
    .no-print,
    nav.navbar,
    .app-footer,
    .page-header,
    .breadcrumb,
    .card-header .btn,
    .filter-card { display: none !important; }
    .app-main { padding: 0 !important; }
    .container-fluid { padding: 0 !important; }
    .print-title { display: block !important; }
    .table-pivot { width: 100% !important; font-size: 8pt !important; }
    .table-pivot th,
    .table-pivot td { padding: 2px 3px !important; }
    .badge { border: 1px solid #999; color: #000 !important;
             background: transparent !important; font-size: 7pt !important; }
</style>

<div class="print-title d-none mb-2">
    <h5 class="mb-0">Staff Attendance Report – <?= h($monthNames[$month]) ?> <?= h((string)$year) ?></h5>
</div>

<!-- Filter Panel -->
<div class="card mb-4 filter-card no-print">
    <div class="card-header"><i class="bi bi-funnel me-2 text-primary"></i>Filter</div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label">Month</label>
                <select class="form-select" name="month">
                    <?php foreach ($monthNames as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label">Year</label>
                <select class="form-select" name="year">
                    <?php for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>View Report
                </button>
                <button type="button" class="btn btn-outline-secondary no-print"
                        onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Report Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
        <span>
            <i class="bi bi-calendar-check me-2 text-primary"></i>
            <strong><?= h($monthNames[$month]) ?> <?= h((string)$year) ?></strong>
            <span class="text-muted ms-2 small"><?= count($pivot) ?> staff member<?= count($pivot) !== 1 ? 's' : '' ?></span>
        </span>
        <div class="d-flex gap-2">
            <span class="badge bg-success px-2 py-1">P = Present</span>
            <span class="badge bg-danger px-2 py-1">A = Absent</span>
            <span class="badge bg-warning text-dark px-2 py-1">H = Half Day</span>
            <span class="badge bg-info px-2 py-1">L = Leave</span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pivot)): ?>
        <div class="empty-state py-4">
            <i class="bi bi-people"></i>
            <h6>No active staff found</h6>
            <p class="small">Add staff members to see the attendance report.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-pivot mb-0" style="font-size: 0.8rem;">
                <thead class="table-dark">
                    <tr>
                        <th class="text-nowrap" style="min-width:140px;">Staff Name</th>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dow     = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isSun   = ($dow === 7);
                        ?>
                        <th class="text-center px-1 <?= $isSun ? 'table-warning' : '' ?>"
                            style="min-width:28px; font-size:0.7rem;">
                            <?= $d ?>
                        </th>
                        <?php endfor; ?>
                        <th class="text-center bg-success bg-opacity-25 px-1" style="min-width:30px;">P</th>
                        <th class="text-center bg-danger  bg-opacity-25 px-1" style="min-width:30px;">A</th>
                        <th class="text-center bg-warning bg-opacity-25 px-1" style="min-width:30px;">H</th>
                        <th class="text-center bg-info    bg-opacity-25 px-1" style="min-width:30px;">L</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pivot as $sid => $data): ?>
                    <tr>
                        <td class="fw-semibold text-nowrap" style="font-size:0.8rem;">
                            <?= h($data['name']) ?>
                        </td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dow   = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isSun = ($dow === 7);
                            $status = $data['days'][$d] ?? '';
                        ?>
                        <td class="text-center px-0 <?= $isSun ? 'table-warning' : '' ?>">
                            <?php if ($status === 'present'): ?>
                                <span class="badge bg-success" style="font-size:0.65rem; padding:2px 4px;">P</span>
                            <?php elseif ($status === 'absent'): ?>
                                <span class="badge bg-danger" style="font-size:0.65rem; padding:2px 4px;">A</span>
                            <?php elseif ($status === 'half_day'): ?>
                                <span class="badge bg-warning text-dark" style="font-size:0.65rem; padding:2px 4px;">H</span>
                            <?php elseif ($status === 'leave'): ?>
                                <span class="badge bg-info" style="font-size:0.65rem; padding:2px 4px;">L</span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:0.7rem;">–</span>
                            <?php endif; ?>
                        </td>
                        <?php endfor; ?>
                        <td class="text-center fw-bold text-success"><?= $data['totals']['P'] ?></td>
                        <td class="text-center fw-bold text-danger"><?= $data['totals']['A'] ?></td>
                        <td class="text-center fw-bold text-warning"><?= $data['totals']['H'] ?></td>
                        <td class="text-center fw-bold text-info"><?= $data['totals']['L'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td class="text-nowrap">Total</td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <td></td>
                        <?php endfor; ?>
                        <td class="text-center text-success"><?= $colTotals['P'] ?></td>
                        <td class="text-center text-danger"><?= $colTotals['A'] ?></td>
                        <td class="text-center text-warning"><?= $colTotals['H'] ?></td>
                        <td class="text-center text-info"><?= $colTotals['L'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
