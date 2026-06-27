<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

$pageTitle   = 'Transport Assignments';
$breadcrumbs = [
    'Dashboard'            => dashboardUrl(),
    'Services'             => BASE_URL . '/app/services',
    'Transport Assignments' => '',
];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action   = $_POST['action'] ?? '';
    $ayId     = (int)($_POST['academic_year_id'] ?? 0);
    $routeId  = (int)($_POST['route_id_filter'] ?? 0);

    $redirect = BASE_URL . '/app/services/transport-assignments?ay_id=' . $ayId
        . ($routeId ? '&route_id=' . $routeId : '')
        . (isset($_POST['q']) && $_POST['q'] !== '' ? '&q=' . urlencode($_POST['q']) : '');

    if ($action === 'assign') {
        $assignId    = (int)($_POST['assign_id'] ?? 0);
        $studentId   = (int)($_POST['student_id'] ?? 0);
        $selRouteId  = (int)($_POST['route_id'] ?? 0);
        $stopId      = (int)($_POST['stop_id'] ?? 0) ?: null;
        $assignedFrom = trim($_POST['assigned_from'] ?? date('Y-m-d'));
        $remarks     = trim($_POST['remarks'] ?? '');

        if (!$studentId || !$selRouteId || !$ayId) {
            setFlash('error', 'Student, route and academic year are required.');
            header('Location: ' . $redirect);
            exit;
        }

        $row = $db->fetchOne(
            'SELECT id FROM students WHERE id = ? AND institution_id = ?',
            [$studentId, $instId]
        );
        if (!$row) {
            setFlash('error', 'Student not found.');
            header('Location: ' . $redirect);
            exit;
        }

        if ($assignId > 0) {
            $db->execute(
                'UPDATE transport_student_assignments
                    SET route_id = ?, stop_id = ?, assigned_from = ?, remarks = ?, is_active = 1
                  WHERE id = ? AND institution_id = ?',
                [$selRouteId, $stopId, $assignedFrom, $remarks, $assignId, $instId]
            );
            setFlash('success', 'Assignment updated.');
        } else {
            $existing = $db->fetchOne(
                'SELECT id FROM transport_student_assignments
                  WHERE student_id = ? AND academic_year_id = ?',
                [$studentId, $ayId]
            );
            if ($existing) {
                $db->execute(
                    'UPDATE transport_student_assignments
                        SET route_id = ?, stop_id = ?, assigned_from = ?, remarks = ?, is_active = 1
                      WHERE id = ?',
                    [$selRouteId, $stopId, $assignedFrom, $remarks, $existing['id']]
                );
                setFlash('success', 'Assignment updated.');
            } else {
                $db->execute(
                    'INSERT INTO transport_student_assignments
                        (institution_id, student_id, route_id, stop_id, academic_year_id,
                         assigned_from, is_active, remarks, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [$instId, $studentId, $selRouteId, $stopId, $ayId,
                     $assignedFrom, $remarks, authId()]
                );
                setFlash('success', 'Student assigned to route.');
            }
        }

        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'remove') {
        $assignId = (int)($_POST['assign_id'] ?? 0);
        if ($assignId > 0) {
            $db->execute(
                'DELETE tsa FROM transport_student_assignments tsa
                   JOIN students s ON s.id = tsa.student_id
                  WHERE tsa.id = ? AND tsa.institution_id = ? AND s.institution_id = ?',
                [$assignId, $instId, $instId]
            );
            setFlash('success', 'Assignment removed.');
        }
        header('Location: ' . $redirect);
        exit;
    }

    header('Location: ' . $redirect);
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterAyId    = (int)($_GET['ay_id'] ?? 0);
$filterRouteId = (int)($_GET['route_id'] ?? 0);
$q             = trim($_GET['q'] ?? '');

$academicYears = $db->fetchAll(
    'SELECT id, label, is_current FROM academic_years
      WHERE institution_id = ? ORDER BY label DESC',
    [$instId]
);

if (!$filterAyId) {
    foreach ($academicYears as $ay) {
        if ($ay['is_current']) {
            $filterAyId = (int)$ay['id'];
            break;
        }
    }
}

$routes = $db->fetchAll(
    'SELECT id, name FROM transport_routes
      WHERE institution_id = ? AND is_active = 1 ORDER BY name',
    [$instId]
);

// ── Route stops JSON for JS ───────────────────────────────────────────────────
$allStops = $db->fetchAll(
    'SELECT trs.id, trs.route_id, trs.stop_name
       FROM transport_route_stops trs
       JOIN transport_routes tr ON tr.id = trs.route_id
      WHERE trs.institution_id = ? AND tr.is_active = 1
      ORDER BY trs.sort_order, trs.stop_name',
    [$instId]
);
$routeStopsMap = [];
foreach ($allStops as $stop) {
    $routeStopsMap[$stop['route_id']][] = ['id' => $stop['id'], 'stop_name' => $stop['stop_name']];
}

// ── Students query ────────────────────────────────────────────────────────────
$students = [];
if ($filterAyId) {
    $params = [$filterAyId, $instId];
    $sql = 'SELECT s.id, s.first_name, s.last_name, s.admission_number, s.passport_photo,
                   cls.name AS class_name, dv.name AS div_name,
                   tsa.id AS assign_id, tsa.route_id AS assigned_route_id,
                   tr.name AS route_name, tsa.stop_id, trs.stop_name,
                   tsa.is_active AS assign_active, tsa.assigned_from, tsa.remarks
              FROM students s
              LEFT JOIN sections sec ON sec.id = s.section_id
              LEFT JOIN classes cls ON cls.id = sec.class_id
              LEFT JOIN divisions dv ON dv.id = sec.division_id
              LEFT JOIN transport_student_assignments tsa
                     ON tsa.student_id = s.id AND tsa.academic_year_id = ?
              LEFT JOIN transport_routes tr ON tr.id = tsa.route_id
              LEFT JOIN transport_route_stops trs ON trs.id = tsa.stop_id
             WHERE s.institution_id = ? AND s.is_active = 1';

    if ($filterRouteId) {
        $sql .= ' AND tsa.route_id = ?';
        $params[] = $filterRouteId;
    }

    if ($q !== '') {
        $sql .= ' AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY class_name, div_name, s.first_name LIMIT 50';
    $students = $db->fetchAll($sql, $params);
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
    <div class="section-icon"><i class="bi bi-bus-front-fill"></i></div>
    <div>
        <h4>Transport Assignments</h4>
        <p>Assign students to bus routes for the academic year.</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-sm-auto">
                <label class="form-label mb-1 small fw-semibold">Academic Year</label>
                <select name="ay_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">— Select Year —</option>
                    <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= h($ay['id']) ?>"<?= $filterAyId == $ay['id'] ? ' selected' : '' ?>>
                        <?= h($ay['label']) ?><?= $ay['is_current'] ? ' (Current)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-auto">
                <label class="form-label mb-1 small fw-semibold">Route</label>
                <select name="route_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">— All Routes —</option>
                    <?php foreach ($routes as $rt): ?>
                    <option value="<?= h($rt['id']) ?>"<?= $filterRouteId == $rt['id'] ? ' selected' : '' ?>>
                        <?= h($rt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-auto">
                <label class="form-label mb-1 small fw-semibold">Search</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control" placeholder="Name or admission no."
                           value="<?= h($q) ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </div>
            <?php if ($q !== '' || $filterRouteId): ?>
            <div class="col-12 col-sm-auto">
                <a href="<?= BASE_URL ?>/app/services/transport-assignments<?= $filterAyId ? '?ay_id=' . $filterAyId : '' ?>"
                   class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Student list -->
<?php if (!$filterAyId): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-calendar3 fs-2 d-block mb-2"></i>
        Select an academic year above.
    </div>
</div>
<?php elseif (empty($students)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-person-x fs-2 d-block mb-2"></i>
        No students found.
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Transport Status</th>
                        <th>Route</th>
                        <th>Stop</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($s['passport_photo'])): ?>
                            <img src="<?= h(PHOTO_URL . '/' . $s['passport_photo']) ?>"
                                 alt="" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;">
                            <?php else: ?>
                            <div class="avatar-circle" style="width:36px;height:36px;font-size:.8rem;border-radius:6px;flex-shrink:0;">
                                <?= mb_strtoupper(mb_substr($s['first_name'], 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-semibold lh-sm"><?= h($s['first_name'] . ' ' . $s['last_name']) ?></div>
                                <div class="text-muted small"><?= h($s['admission_number']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($s['class_name']): ?>
                        <?= h($s['class_name']) ?><?= $s['div_name'] ? ' – ' . h($s['div_name']) : '' ?>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['assign_id']): ?>
                        <span class="badge bg-success">Assigned</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['route_name'] ? h($s['route_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $s['stop_name']  ? h($s['stop_name'])  : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end">
                        <?php if (!$s['assign_id']): ?>
                        <button type="button" class="btn btn-sm btn-primary"
                                onclick="openAssignModal(
                                    <?= (int)$s['id'] ?>,
                                    <?= json_encode($s['first_name'] . ' ' . $s['last_name']) ?>,
                                    0, 0, 0, '', '')">
                            Assign
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                onclick="openAssignModal(
                                    <?= (int)$s['id'] ?>,
                                    <?= json_encode($s['first_name'] . ' ' . $s['last_name']) ?>,
                                    <?= (int)$s['assign_id'] ?>,
                                    <?= (int)$s['assigned_route_id'] ?>,
                                    <?= (int)$s['stop_id'] ?>,
                                    <?= json_encode($s['assigned_from'] ?? '') ?>,
                                    <?= json_encode($s['remarks'] ?? '') ?>)">
                            Edit
                        </button>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Remove this transport assignment?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="assign_id" value="<?= (int)$s['assign_id'] ?>">
                            <input type="hidden" name="academic_year_id" value="<?= $filterAyId ?>">
                            <input type="hidden" name="route_id_filter" value="<?= $filterRouteId ?>">
                            <input type="hidden" name="q" value="<?= h($q) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Assign / Edit Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="academic_year_id" value="<?= $filterAyId ?>">
                <input type="hidden" name="route_id_filter" value="<?= $filterRouteId ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="student_id" id="modalStudentId">
                <input type="hidden" name="assign_id" id="modalAssignId">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignModalLabel">Assign Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Student: <strong id="modalStudentName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Route <span class="text-danger">*</span></label>
                        <select name="route_id" id="modalRouteId" class="form-select" required
                                onchange="populateStops(this.value)">
                            <option value="">— Select Route —</option>
                            <?php foreach ($routes as $rt): ?>
                            <option value="<?= h($rt['id']) ?>"><?= h($rt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Stop</label>
                        <select name="stop_id" id="modalStopId" class="form-select">
                            <option value="">— Select Stop —</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Assigned From</label>
                        <input type="date" name="assigned_from" id="modalAssignedFrom"
                               class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks</label>
                        <input type="text" name="remarks" id="modalRemarks"
                               class="form-control" maxlength="255" placeholder="Optional">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const routeStops = <?= json_encode($routeStopsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;

function populateStops(routeId, selectedStopId) {
    const sel = document.getElementById('modalStopId');
    sel.innerHTML = '<option value="">— Select Stop —</option>';
    const stops = routeStops[routeId] || [];
    stops.forEach(function(stop) {
        const opt = document.createElement('option');
        opt.value = stop.id;
        opt.textContent = stop.stop_name;
        if (selectedStopId && stop.id == selectedStopId) {
            opt.selected = true;
        }
        sel.appendChild(opt);
    });
}

function openAssignModal(studentId, studentName, assignId, routeId, stopId, assignedFrom, remarks) {
    document.getElementById('modalStudentId').value   = studentId;
    document.getElementById('modalStudentName').textContent = studentName;
    document.getElementById('modalAssignId').value    = assignId;
    document.getElementById('modalAssignedFrom').value = assignedFrom || '<?= date('Y-m-d') ?>';
    document.getElementById('modalRemarks').value     = remarks || '';

    const routeSel = document.getElementById('modalRouteId');
    routeSel.value = routeId || '';
    populateStops(routeId, stopId);

    new bootstrap.Modal(document.getElementById('assignModal')).show();
}
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
