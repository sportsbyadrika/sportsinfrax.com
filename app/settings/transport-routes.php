<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$routeId = (int)($_GET['id'] ?? 0);
$error   = '';

$freqLabels = [
    'monthly'     => 'Monthly',
    'quarterly'   => 'Quarterly',
    'half_yearly' => 'Half-Yearly',
    'annual'      => 'Annual',
    'one_time'    => 'One-Time',
];
$freqBadge = [
    'monthly'     => 'bg-primary',
    'quarterly'   => 'bg-info text-dark',
    'half_yearly' => 'bg-warning text-dark',
    'annual'      => 'bg-success',
    'one_time'    => 'bg-secondary',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_route') {
        $postRouteId   = (int)($_POST['route_id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $vehicleId     = (int)($_POST['vehicle_id'] ?? 0) ?: null;
        $driverName    = trim($_POST['driver_name'] ?? '');
        $driverPhone   = trim($_POST['driver_phone'] ?? '');
        $helperName    = trim($_POST['helper_name'] ?? '');
        $morningDep    = $_POST['morning_departure'] ?? '';
        $eveningDep    = $_POST['evening_departure'] ?? '';

        if (!$name) {
            $error = 'Route name is required.';
        } elseif (mb_strlen($name) > 120) {
            $error = 'Route name must not exceed 120 characters.';
        }

        if (!$error) {
            if ($postRouteId) {
                $db->prepare(
                    "UPDATE transport_routes
                     SET name = ?, description = ?, vehicle_id = ?, driver_name = ?,
                         driver_phone = ?, helper_name = ?, morning_departure = ?,
                         evening_departure = ?, updated_at = NOW()
                     WHERE id = ? AND institution_id = ?"
                )->execute([
                    $name, $description ?: null, $vehicleId, $driverName ?: null,
                    $driverPhone ?: null, $helperName ?: null,
                    $morningDep ?: null, $eveningDep ?: null,
                    $postRouteId, $instId,
                ]);
                setFlash('success', "Route '{$name}' updated.");
                header('Location: ' . BASE_URL . '/app/settings/transport-routes?id=' . $postRouteId);
            } else {
                $db->prepare(
                    "INSERT INTO transport_routes
                         (institution_id, name, description, vehicle_id, driver_name,
                          driver_phone, helper_name, morning_departure, evening_departure)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $instId, $name, $description ?: null, $vehicleId, $driverName ?: null,
                    $driverPhone ?: null, $helperName ?: null,
                    $morningDep ?: null, $eveningDep ?: null,
                ]);
                $newId = (int)$db->lastInsertId();
                setFlash('success', "Route '{$name}' added.");
                header('Location: ' . BASE_URL . '/app/settings/transport-routes?id=' . $newId);
            }
            exit;
        }

    } elseif ($action === 'toggle_route') {
        $toggleId = (int)($_POST['route_id'] ?? 0);
        if ($toggleId) {
            $db->prepare(
                "UPDATE transport_routes SET is_active = NOT is_active WHERE id = ? AND institution_id = ?"
            )->execute([$toggleId, $instId]);
            setFlash('success', 'Route status updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-routes');
        exit;

    } elseif ($action === 'delete_route') {
        $delId = (int)($_POST['route_id'] ?? 0);
        if ($delId) {
            $chk = $db->prepare(
                "SELECT COUNT(*) FROM transport_student_assignments WHERE route_id = ? AND institution_id = ?"
            );
            $chk->execute([$delId, $instId]);
            if ($chk->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: students are assigned to this route.');
            } else {
                $db->prepare(
                    "DELETE FROM transport_routes WHERE id = ? AND institution_id = ?"
                )->execute([$delId, $instId]);
                setFlash('success', 'Route deleted.');
            }
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-routes');
        exit;

    } elseif ($action === 'add_stop') {
        $stopRouteId = (int)($_POST['route_id'] ?? 0);
        $stopName    = trim($_POST['stop_name'] ?? '');
        $pickupTime  = $_POST['pickup_time'] ?? '';
        $dropTime    = $_POST['drop_time'] ?? '';
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        $routeChk = $db->prepare("SELECT id FROM transport_routes WHERE id = ? AND institution_id = ?");
        $routeChk->execute([$stopRouteId, $instId]);
        if (!$routeChk->fetch()) {
            setFlash('error', 'Route not found.');
            header('Location: ' . BASE_URL . '/app/settings/transport-routes');
            exit;
        }

        if (!$stopName) {
            setFlash('error', 'Stop name is required.');
        } else {
            $db->prepare(
                "INSERT INTO transport_route_stops
                     (route_id, institution_id, stop_name, pickup_time, drop_time, sort_order)
                 VALUES (?,?,?,?,?,?)"
            )->execute([
                $stopRouteId, $instId, $stopName,
                $pickupTime ?: null, $dropTime ?: null, $sortOrder,
            ]);
            setFlash('success', "Stop '{$stopName}' added.");
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-routes?id=' . $stopRouteId);
        exit;

    } elseif ($action === 'delete_stop') {
        $stopId      = (int)($_POST['stop_id'] ?? 0);
        $stopRouteId = (int)($_POST['route_id'] ?? 0);

        $routeChk = $db->prepare("SELECT id FROM transport_routes WHERE id = ? AND institution_id = ?");
        $routeChk->execute([$stopRouteId, $instId]);
        if (!$routeChk->fetch()) {
            setFlash('error', 'Route not found.');
            header('Location: ' . BASE_URL . '/app/settings/transport-routes');
            exit;
        }

        if ($stopId) {
            $db->prepare(
                "DELETE FROM transport_route_stops WHERE id = ? AND route_id = ? AND institution_id = ?"
            )->execute([$stopId, $stopRouteId, $instId]);
            setFlash('success', 'Stop removed.');
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-routes?id=' . $stopRouteId);
        exit;

    } elseif ($action === 'save_fee') {
        $feeRouteId      = (int)($_POST['route_id'] ?? 0);
        $academicYearId  = (int)($_POST['academic_year_id'] ?? 0);
        $amount          = $_POST['amount'] ?? '0.00';
        $frequency       = $_POST['frequency'] ?? 'monthly';
        $isActive        = isset($_POST['is_active']) ? 1 : 0;

        $routeChk = $db->prepare("SELECT id FROM transport_routes WHERE id = ? AND institution_id = ?");
        $routeChk->execute([$feeRouteId, $instId]);
        if (!$routeChk->fetch()) {
            setFlash('error', 'Route not found.');
            header('Location: ' . BASE_URL . '/app/settings/transport-routes');
            exit;
        }

        $validFreqs = array_keys($freqLabels);
        if (!$academicYearId || !is_numeric($amount) || (float)$amount < 0 || !in_array($frequency, $validFreqs, true)) {
            setFlash('error', 'Invalid fee data.');
        } else {
            $db->prepare(
                "INSERT INTO transport_route_fees
                     (route_id, institution_id, academic_year_id, amount, frequency, is_active)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                     amount = VALUES(amount),
                     frequency = VALUES(frequency),
                     is_active = VALUES(is_active)"
            )->execute([
                $feeRouteId, $instId, $academicYearId,
                number_format((float)$amount, 2, '.', ''),
                $frequency, $isActive,
            ]);
            setFlash('success', 'Fee saved.');
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-routes?id=' . $feeRouteId);
        exit;
    }
}

$vehicleStmt = $db->prepare(
    "SELECT id, registration_no, make, model FROM transport_vehicles
     WHERE institution_id = ? AND is_active = 1 ORDER BY registration_no"
);
$vehicleStmt->execute([$instId]);
$vehicles = $vehicleStmt->fetchAll();

$route = null;
if ($routeId) {
    $rs = $db->prepare("SELECT * FROM transport_routes WHERE id = ? AND institution_id = ?");
    $rs->execute([$routeId, $instId]);
    $route = $rs->fetch();
    if (!$route) {
        setFlash('error', 'Route not found.');
        header('Location: ' . BASE_URL . '/app/settings/transport-routes');
        exit;
    }
}

if ($routeId && $route) {
    $stopsStmt = $db->prepare(
        "SELECT * FROM transport_route_stops WHERE route_id = ? AND institution_id = ? ORDER BY sort_order ASC"
    );
    $stopsStmt->execute([$routeId, $instId]);
    $stops = $stopsStmt->fetchAll();

    $maxOrder = 0;
    foreach ($stops as $s) {
        if ((int)$s['sort_order'] > $maxOrder) {
            $maxOrder = (int)$s['sort_order'];
        }
    }
    $nextOrder = $maxOrder + 1;

    $yearsStmt = $db->prepare(
        "SELECT * FROM academic_years WHERE institution_id = ? ORDER BY label"
    );
    $yearsStmt->execute([$instId]);
    $academicYears = $yearsStmt->fetchAll();

    $feesStmt = $db->prepare(
        "SELECT * FROM transport_route_fees WHERE route_id = ? AND institution_id = ?"
    );
    $feesStmt->execute([$routeId, $instId]);
    $feesRaw = $feesStmt->fetchAll();
    $feesByYear = [];
    foreach ($feesRaw as $f) {
        $feesByYear[(int)$f['academic_year_id']] = $f;
    }
} else {
    $routeStmt = $db->prepare(
        "SELECT r.*, v.registration_no
         FROM transport_routes r
         LEFT JOIN transport_vehicles v ON v.id = r.vehicle_id AND v.institution_id = r.institution_id
         WHERE r.institution_id = ? ORDER BY r.name"
    );
    $routeStmt->execute([$instId]);
    $routes = $routeStmt->fetchAll();
}

$pageTitle   = 'Transport Routes';
$breadcrumbs = [
    'Dashboard'        => dashboardUrl(),
    'Settings'         => BASE_URL . '/app/settings',
    'Transport Routes' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-signpost-2-fill"></i></div>
  <div>
    <h4>Transport Routes</h4>
    <p>Define bus routes, stops, and per-year fee rates.</p>
  </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger py-2 small mb-3"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$routeId): ?>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- MODE A: Route List + Add Form                                         -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="row g-4">

  <!-- Add Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>Add Route
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_route">

          <div class="mb-3">
            <label class="form-label">Name <span class="required">*</span></label>
            <input type="text" class="form-control" name="name" maxlength="120"
                   value="<?= h($_POST['name'] ?? '') ?>"
                   placeholder="e.g. North Zone Route" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="2"
                      placeholder="Optional description"><?= h($_POST['description'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Vehicle</label>
            <select class="form-select" name="vehicle_id">
              <option value="">— None —</option>
              <?php foreach ($vehicles as $v): ?>
              <option value="<?= (int)$v['id'] ?>"
                <?= (int)($_POST['vehicle_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
                <?= h($v['registration_no']) ?>
                <?php if ($v['make'] || $v['model']): ?>
                  (<?= h(trim($v['make'] . ' ' . $v['model'])) ?>)
                <?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Driver Name</label>
            <input type="text" class="form-control" name="driver_name"
                   value="<?= h($_POST['driver_name'] ?? '') ?>" placeholder="Driver name">
          </div>

          <div class="mb-3">
            <label class="form-label">Driver Phone</label>
            <input type="text" class="form-control" name="driver_phone"
                   value="<?= h($_POST['driver_phone'] ?? '') ?>" placeholder="Phone number">
          </div>

          <div class="mb-3">
            <label class="form-label">Helper Name</label>
            <input type="text" class="form-control" name="helper_name"
                   value="<?= h($_POST['helper_name'] ?? '') ?>" placeholder="Helper / conductor name">
          </div>

          <div class="mb-3">
            <label class="form-label">Morning Departure</label>
            <input type="time" class="form-control" name="morning_departure"
                   value="<?= h($_POST['morning_departure'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Evening Departure</label>
            <input type="time" class="form-control" name="evening_departure"
                   value="<?= h($_POST['evening_departure'] ?? '') ?>">
          </div>

          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i>Add Route
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Route List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-signpost-2 me-2 text-primary"></i>Routes
        <span class="badge bg-secondary ms-1"><?= count($routes) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($routes): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>Departures</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($routes as $r): ?>
              <tr>
                <td>
                  <div class="fw-600 small"><?= h($r['name']) ?></div>
                  <?php if ($r['description']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= h($r['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small"><?= $r['registration_no'] ? h($r['registration_no']) : '<span class="text-muted">—</span>' ?></td>
                <td class="small">
                  <?php if ($r['driver_name']): ?>
                  <div><?= h($r['driver_name']) ?></div>
                  <?php if ($r['driver_phone']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= h($r['driver_phone']) ?></div>
                  <?php endif; ?>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?php if ($r['morning_departure']): ?>
                  <div><i class="bi bi-sunrise me-1"></i><?= h(substr($r['morning_departure'], 0, 5)) ?></div>
                  <?php endif; ?>
                  <?php if ($r['evening_departure']): ?>
                  <div><i class="bi bi-sunset me-1"></i><?= h(substr($r['evening_departure'], 0, 5)) ?></div>
                  <?php endif; ?>
                  <?php if (!$r['morning_departure'] && !$r['evening_departure']): ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $r['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <a href="<?= h(BASE_URL . '/app/settings/transport-routes?id=' . (int)$r['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon" title="Manage" data-bs-toggle="tooltip">
                      <i class="bi bi-gear"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle_route">
                      <input type="hidden" name="route_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm <?= $r['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon"
                              title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $r['is_active'] ? 'Deactivate this route?' : 'Activate this route?' ?>">
                        <i class="bi <?= $r['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete_route">
                      <input type="hidden" name="route_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete" data-bs-toggle="tooltip"
                              data-confirm="Delete route '<?= h($r['name']) ?>'? This cannot be undone.">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-signpost-2"></i>
          <h6>No routes yet</h6>
          <p class="small">Add your first transport route to get started.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- MODE B: Route Detail (Edit + Stops + Fees)                            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->

<!-- Section 1: Edit Route -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-pencil me-2 text-primary"></i>Edit Route
  </div>
  <div class="card-body">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_route">
      <input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Name <span class="required">*</span></label>
          <input type="text" class="form-control" name="name" maxlength="120"
                 value="<?= h($route['name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Vehicle</label>
          <select class="form-select" name="vehicle_id">
            <option value="">— None —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= (int)$v['id'] ?>"
              <?= (int)$route['vehicle_id'] === (int)$v['id'] ? 'selected' : '' ?>>
              <?= h($v['registration_no']) ?>
              <?php if ($v['make'] || $v['model']): ?>
                (<?= h(trim($v['make'] . ' ' . $v['model'])) ?>)
              <?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Description</label>
          <textarea class="form-control" name="description" rows="2"><?= h($route['description'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">Driver Name</label>
          <input type="text" class="form-control" name="driver_name"
                 value="<?= h($route['driver_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Driver Phone</label>
          <input type="text" class="form-control" name="driver_phone"
                 value="<?= h($route['driver_phone'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Helper Name</label>
          <input type="text" class="form-control" name="helper_name"
                 value="<?= h($route['helper_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Morning Departure</label>
          <input type="time" class="form-control" name="morning_departure"
                 value="<?= h($route['morning_departure'] ? substr($route['morning_departure'], 0, 5) : '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Evening Departure</label>
          <input type="time" class="form-control" name="evening_departure"
                 value="<?= h($route['evening_departure'] ? substr($route['evening_departure'], 0, 5) : '') ?>">
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check2 me-1"></i>Update Route
        </button>
        <a href="<?= h(BASE_URL . '/app/settings/transport-routes') ?>"
           class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>Back to Routes
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Section 2: Stops -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-geo-alt me-2 text-primary"></i>Stops
    <span class="badge bg-secondary ms-1"><?= count($stops) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if ($stops): ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:4rem;">Order</th>
            <th>Stop Name</th>
            <th>Pickup</th>
            <th>Drop</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stops as $stop): ?>
          <tr>
            <td class="text-muted small text-center"><?= (int)$stop['sort_order'] ?></td>
            <td class="fw-600 small"><?= h($stop['stop_name']) ?></td>
            <td class="small text-muted">
              <?= $stop['pickup_time'] ? h(substr($stop['pickup_time'], 0, 5)) : '—' ?>
            </td>
            <td class="small text-muted">
              <?= $stop['drop_time'] ? h(substr($stop['drop_time'], 0, 5)) : '—' ?>
            </td>
            <td>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_stop">
                <input type="hidden" name="stop_id" value="<?= (int)$stop['id'] ?>">
                <input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>">
                <button type="submit"
                        class="btn btn-sm btn-outline-danger btn-icon"
                        title="Delete stop" data-bs-toggle="tooltip"
                        data-confirm="Remove stop '<?= h($stop['stop_name']) ?>'?">
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
    <div class="empty-state py-3">
      <i class="bi bi-geo-alt"></i>
      <h6>No stops defined</h6>
      <p class="small">Add stops below to define the route path.</p>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-footer bg-light">
    <div class="small fw-600 mb-2">Add Stop</div>
    <form method="POST" class="row g-2 align-items-end">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_stop">
      <input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>">

      <div class="col-md-4">
        <label class="form-label form-label-sm">Stop Name <span class="required">*</span></label>
        <input type="text" class="form-control form-control-sm" name="stop_name"
               placeholder="e.g. City Gate" required>
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm">Pickup Time</label>
        <input type="time" class="form-control form-control-sm" name="pickup_time">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm">Drop Time</label>
        <input type="time" class="form-control form-control-sm" name="drop_time">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm">Sort Order</label>
        <input type="number" class="form-control form-control-sm" name="sort_order"
               value="<?= $nextOrder ?>" min="0">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-primary w-100">
          <i class="bi bi-plus-lg me-1"></i>Add Stop
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Section 3: Fees per Academic Year -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-cash-coin me-2 text-primary"></i>Fees per Academic Year
  </div>
  <div class="card-body p-0">
    <?php if (!$academicYears): ?>
    <div class="p-3">
      <div class="alert alert-info mb-0 small">
        No academic years found. Add one in
        <a href="<?= h(BASE_URL . '/app/settings/academic-years') ?>">Settings &rarr; Academic Years</a>.
      </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Year</th>
            <th>Amount</th>
            <th>Frequency</th>
            <th>Active</th>
            <th>Save</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($academicYears as $ay):
            $fee = $feesByYear[(int)$ay['id']] ?? null;
          ?>
          <tr>
            <td class="fw-600 small align-middle">
              <?= h($ay['label']) ?>
              <?php if ($ay['is_current'] ?? false): ?>
              <span class="badge bg-success ms-1" style="font-size:.65rem;">Current</span>
              <?php endif; ?>
            </td>
            <form method="POST">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="save_fee">
              <input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>">
              <input type="hidden" name="academic_year_id" value="<?= (int)$ay['id'] ?>">
              <td>
                <input type="number" class="form-control form-control-sm" name="amount"
                       step="0.01" min="0" style="width:8rem;"
                       value="<?= h($fee ? number_format((float)$fee['amount'], 2, '.', '') : '') ?>"
                       placeholder="0.00" required>
              </td>
              <td>
                <select class="form-select form-select-sm" name="frequency" style="width:10rem;">
                  <?php foreach ($freqLabels as $fval => $flabel): ?>
                  <option value="<?= $fval ?>"
                    <?= ($fee['frequency'] ?? '') === $fval ? 'selected' : '' ?>>
                    <?= $flabel ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="align-middle text-center">
                <div class="form-check d-inline-block">
                  <input class="form-check-input" type="checkbox" name="is_active" value="1"
                    <?= ($fee && $fee['is_active']) ? 'checked' : '' ?>>
                </div>
              </td>
              <td>
                <?php if (!$fee): ?>
                <button type="submit" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-plus-lg me-1"></i>Set Fee
                </button>
                <?php else: ?>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge <?= $freqBadge[$fee['frequency']] ?? 'bg-secondary' ?> small">
                    <?= h($freqLabels[$fee['frequency']] ?? $fee['frequency']) ?>
                  </span>
                  <button type="submit" class="btn btn-sm btn-outline-success btn-icon"
                          title="Save" data-bs-toggle="tooltip">
                    <i class="bi bi-check2"></i>
                  </button>
                </div>
                <?php endif; ?>
              </td>
            </form>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
