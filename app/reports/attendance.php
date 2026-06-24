<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

// ── Institution type ──────────────────────────────────────────────────────────

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst       = $instStmt->fetch();
$isSchool   = ($inst && getInstitutionCategory($inst['institution_type'] ?? '') === 'school');
$entityType = $isSchool ? 'student' : 'member';

// ── Scope / staff ─────────────────────────────────────────────────────────────

$scope   = getModuleScope('attendance');
$staffId = authStaffId();

if ($scope === 'none') {
    setFlash('error', 'You do not have permission to access the Attendance module.');
    header('Location: ' . dashboardUrl());
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────

$month       = (int)($_GET['month']      ?? date('n'));
$year        = (int)($_GET['year']       ?? date('Y'));
$sessionId   = (int)($_GET['session_id'] ?? 0);
$sectionId   = (int)($_GET['section_id'] ?? 0);
$month       = max(1, min(12, $month));
$currentYear = (int)date('Y');

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

$monthNames = [
    1  => 'January',  2  => 'February', 3  => 'March',    4  => 'April',
    5  => 'May',      6  => 'June',     7  => 'July',      8  => 'August',
    9  => 'September',10 => 'October',  11 => 'November',  12 => 'December',
];

// ── Attendance sessions dropdown ──────────────────────────────────────────────

$sesStmt = $db->prepare(
    "SELECT id, label FROM attendance_sessions
     WHERE institution_id = ? AND is_active = 1
     ORDER BY sort_order, label"
);
$sesStmt->execute([$instId]);
$sessions = $sesStmt->fetchAll();

// ── Sections dropdown (school only) ──────────────────────────────────────────

$sectionOptions = [];
if ($isSchool) {
    $secWhere  = 'sec.institution_id = ? AND sec.is_active = 1';
    $secParams = [$instId];

    if ($scope === 'own_class' && $staffId) {
        $teacherSectionIds = getTeacherSectionIds($staffId, $instId) ?: [0];
        $ph        = implode(',', array_fill(0, count($teacherSectionIds), '?'));
        $secWhere .= " AND sec.id IN ({$ph})";
        $secParams = array_merge($secParams, $teacherSectionIds);
    }

    $secStmt = $db->prepare(
        "SELECT sec.id,
                ay.label  AS year_label,
                CONCAT(cls.name, ' \u{2013} ', dv.name) AS section_label
         FROM sections sec
         JOIN academic_years ay  ON ay.id  = sec.academic_year_id
         JOIN classes         cls ON cls.id = sec.class_id
         JOIN divisions       dv  ON dv.id  = sec.division_id
         WHERE {$secWhere}
         ORDER BY ay.start_date DESC, cls.numeric_order, cls.name, dv.name"
    );
    $secStmt->execute($secParams);
    $sectionOptions = $secStmt->fetchAll();

    // Group by academic year label
    $secByYear = [];
    foreach ($sectionOptions as $sec) {
        $secByYear[$sec['year_label']][] = $sec;
    }
}

// ── Determine whether we have enough to run the pivot ────────────────────────

$canQuery = $sessionId > 0 && ($isSchool ? $sectionId > 0 : true);

// ── Build pivot ───────────────────────────────────────────────────────────────

$pivot     = [];
$colTotals = ['P' => 0, 'A' => 0, 'La' => 0, 'L' => 0];

if ($canQuery) {
    if ($isSchool) {
        $entityStmt = $db->prepare(
            "SELECT id, first_name, last_name, admission_number
             FROM students
             WHERE institution_id = ? AND section_id = ? AND is_active = 1
             ORDER BY first_name, last_name"
        );
        $entityStmt->execute([$instId, $sectionId]);
    } else {
        $entityStmt = $db->prepare(
            "SELECT id, first_name, last_name, member_code
             FROM members
             WHERE institution_id = ? AND is_active = 1
             ORDER BY first_name, last_name"
        );
        $entityStmt->execute([$instId]);
    }
    $entities = $entityStmt->fetchAll();

    foreach ($entities as $ent) {
        $code = $isSchool ? ($ent['admission_number'] ?? '') : ($ent['member_code'] ?? '');
        $pivot[$ent['id']] = [
            'name' => $ent['first_name'] . ' ' . $ent['last_name'],
            'code' => $code,
            'days' => [],
        ];
    }

    $attStmt = $db->prepare(
        "SELECT entity_id, attendance_date, status
         FROM member_attendance
         WHERE institution_id = ? AND session_id = ? AND entity_type = ?
           AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?"
    );
    $attStmt->execute([$instId, $sessionId, $entityType, $month, $year]);
    $attRows = $attStmt->fetchAll();

    foreach ($attRows as $row) {
        $day = (int)date('j', strtotime($row['attendance_date']));
        if (isset($pivot[$row['entity_id']])) {
            $pivot[$row['entity_id']]['days'][$day] = $row['status'];
        }
    }

    foreach ($pivot as $eid => &$data) {
        $data['totals'] = ['P' => 0, 'A' => 0, 'La' => 0, 'L' => 0];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            match($data['days'][$d] ?? '') {
                'present' => $data['totals']['P']++,
                'absent'  => $data['totals']['A']++,
                'late'    => $data['totals']['La']++,
                'leave'   => $data['totals']['L']++,
                default   => null,
            };
        }
        $colTotals['P']  += $data['totals']['P'];
        $colTotals['A']  += $data['totals']['A'];
        $colTotals['La'] += $data['totals']['La'];
        $colTotals['L']  += $data['totals']['L'];
    }
    unset($data);
}

// ── Session label for display ─────────────────────────────────────────────────

$sessionLabel   = '';
$sectionLabel   = '';
foreach ($sessions as $ses) {
    if ((int)$ses['id'] === $sessionId) { $sessionLabel = $ses['label']; break; }
}
if ($isSchool) {
    foreach ($sectionOptions as $sec) {
        if ((int)$sec['id'] === $sectionId) { $sectionLabel = $sec['section_label']; break; }
    }
}

// ── Page setup ────────────────────────────────────────────────────────────────

$pageTitle   = 'Attendance Report';
$breadcrumbs = [
    'Dashboard'  => dashboardUrl(),
    'Reports'    => BASE_URL . '/app/reports',
    'Attendance' => '',
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
    <h5 class="mb-0">
        Attendance Report
        <?php if ($sessionLabel): ?> – <?= h($sessionLabel) ?><?php endif; ?>
        <?php if ($sectionLabel): ?> / <?= h($sectionLabel) ?><?php endif; ?>
        – <?= h($monthNames[$month]) ?> <?= h((string)$year) ?>
    </h5>
</div>

<!-- Filter Panel -->
<div class="card mb-4 filter-card no-print">
    <div class="card-header"><i class="bi bi-funnel me-2 text-primary"></i>Filter</div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2 col-6">
                <label class="form-label">Month</label>
                <select class="form-select" name="month">
                    <?php foreach ($monthNames as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label">Year</label>
                <select class="form-select" name="year">
                    <?php for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label">Session</label>
                <select class="form-select" name="session_id">
                    <option value="">— Select session —</option>
                    <?php foreach ($sessions as $ses): ?>
                    <option value="<?= (int)$ses['id'] ?>" <?= $sessionId === (int)$ses['id'] ? 'selected' : '' ?>>
                        <?= h($ses['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($isSchool): ?>
            <div class="col-md-3 col-6">
                <label class="form-label">Section</label>
                <select class="form-select" name="section_id">
                    <option value="">— Select section —</option>
                    <?php foreach (($secByYear ?? []) as $yearLabel => $secList): ?>
                    <optgroup label="<?= h($yearLabel) ?>">
                        <?php foreach ($secList as $sec): ?>
                        <option value="<?= (int)$sec['id'] ?>" <?= $sectionId === (int)$sec['id'] ? 'selected' : '' ?>>
                            <?= h($sec['section_label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>View Report
                </button>
                <?php if ($canQuery): ?>
                <button type="button" class="btn btn-outline-secondary no-print"
                        onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Report Table -->
<?php if (!$canQuery): ?>
<div class="card">
    <div class="card-body">
        <div class="alert alert-info d-flex align-items-center gap-3 mb-0">
            <i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i>
            <div>
                Please select
                <?php if (!$sessionId): ?><strong>session</strong><?php endif; ?>
                <?php if (!$sessionId && $isSchool && !$sectionId): ?> and <?php endif; ?>
                <?php if ($isSchool && !$sectionId): ?><strong>section</strong><?php endif; ?>
                to view the report.
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
        <span>
            <i class="bi bi-calendar-check me-2 text-primary"></i>
            <strong><?= h($monthNames[$month]) ?> <?= h((string)$year) ?></strong>
            <?php if ($sessionLabel): ?>
            <span class="badge bg-primary bg-opacity-10 text-primary ms-2"><?= h($sessionLabel) ?></span>
            <?php endif; ?>
            <?php if ($sectionLabel): ?>
            <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?= h($sectionLabel) ?></span>
            <?php endif; ?>
            <span class="text-muted ms-2 small"><?= count($pivot) ?> <?= $isSchool ? 'student' : 'member' ?><?= count($pivot) !== 1 ? 's' : '' ?></span>
        </span>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-success px-2 py-1">P = Present</span>
            <span class="badge bg-danger px-2 py-1">A = Absent</span>
            <span class="badge bg-warning text-dark px-2 py-1">La = Late</span>
            <span class="badge bg-info px-2 py-1">L = Leave</span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pivot)): ?>
        <div class="empty-state py-4">
            <i class="bi bi-people"></i>
            <h6>No <?= $isSchool ? 'students' : 'members' ?> found</h6>
            <p class="small">
                <?php if ($isSchool): ?>
                    No active students in this section.
                <?php else: ?>
                    No active members for this institution.
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-pivot mb-0" style="font-size: 0.8rem;">
                <thead class="table-dark">
                    <tr>
                        <th class="text-nowrap" style="min-width:140px;"><?= $isSchool ? 'Student Name' : 'Member Name' ?></th>
                        <th class="text-nowrap" style="min-width:80px; font-size:0.7rem;"><?= $isSchool ? 'Adm. No.' : 'Code' ?></th>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dow   = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isSun = ($dow === 7);
                        ?>
                        <th class="text-center px-1 <?= $isSun ? 'table-warning' : '' ?>"
                            style="min-width:28px; font-size:0.7rem;">
                            <?= $d ?>
                        </th>
                        <?php endfor; ?>
                        <th class="text-center bg-success bg-opacity-25 px-1" style="min-width:30px;">P</th>
                        <th class="text-center bg-danger  bg-opacity-25 px-1" style="min-width:30px;">A</th>
                        <th class="text-center bg-warning bg-opacity-25 px-1" style="min-width:30px;">La</th>
                        <th class="text-center bg-info    bg-opacity-25 px-1" style="min-width:30px;">L</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pivot as $eid => $data): ?>
                    <tr>
                        <td class="fw-semibold text-nowrap" style="font-size:0.8rem;"><?= h($data['name']) ?></td>
                        <td class="text-muted small text-nowrap"><?= h($data['code']) ?></td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dow    = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isSun  = ($dow === 7);
                            $status = $data['days'][$d] ?? '';
                        ?>
                        <td class="text-center px-0 <?= $isSun ? 'table-warning' : '' ?>">
                            <?php if ($status === 'present'): ?>
                                <span class="badge bg-success" style="font-size:0.65rem; padding:2px 4px;">P</span>
                            <?php elseif ($status === 'absent'): ?>
                                <span class="badge bg-danger" style="font-size:0.65rem; padding:2px 4px;">A</span>
                            <?php elseif ($status === 'late'): ?>
                                <span class="badge bg-warning text-dark" style="font-size:0.65rem; padding:2px 4px;">La</span>
                            <?php elseif ($status === 'leave'): ?>
                                <span class="badge bg-info" style="font-size:0.65rem; padding:2px 4px;">L</span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:0.7rem;">–</span>
                            <?php endif; ?>
                        </td>
                        <?php endfor; ?>
                        <td class="text-center fw-bold text-success"><?= $data['totals']['P'] ?></td>
                        <td class="text-center fw-bold text-danger"><?= $data['totals']['A'] ?></td>
                        <td class="text-center fw-bold text-warning"><?= $data['totals']['La'] ?></td>
                        <td class="text-center fw-bold text-info"><?= $data['totals']['L'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td class="text-nowrap" colspan="2">Total</td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <td></td>
                        <?php endfor; ?>
                        <td class="text-center text-success"><?= $colTotals['P'] ?></td>
                        <td class="text-center text-danger"><?= $colTotals['A'] ?></td>
                        <td class="text-center text-warning"><?= $colTotals['La'] ?></td>
                        <td class="text-center text-info"><?= $colTotals['L'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
