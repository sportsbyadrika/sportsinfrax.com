<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// ── POST handling ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action']     ?? '';
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

    if ($action === 'add' && $vehicleId) {
        $chk = $db->prepare("SELECT id FROM transport_vehicles WHERE id = ? AND institution_id = ? AND is_active = 1");
        $chk->execute([$vehicleId, $instId]);
        if (!$chk->fetch()) {
            setFlash('error', 'Vehicle not found.');
            header('Location: ' . BASE_URL . '/app/services/vehicle-service?vehicle_id=' . $vehicleId);
            exit;
        }

        $serviceDate    = trim($_POST['service_date']     ?? '');
        $serviceType    = $_POST['service_type']          ?? '';
        $description    = trim($_POST['description']      ?? '');
        $odometerKm     = $_POST['odometer_km']           ?? '';
        $cost           = $_POST['cost']                  ?? '0';
        $vendor         = trim($_POST['vendor']           ?? '');
        $nextServiceDate = trim($_POST['next_service_date'] ?? '');
        $nextServiceKm  = $_POST['next_service_km']       ?? '';

        $validTypes = ['routine', 'repair', 'accident', 'insurance', 'fitness', 'permit', 'puc', 'other'];

        $err = '';
        if (!$serviceDate)                                        $err = 'Service date is required.';
        elseif (!in_array($serviceType, $validTypes, true))       $err = 'Please select a valid service type.';

        if ($err) {
            setFlash('error', $err);
        } else {
            $db->prepare(
                "INSERT INTO transport_vehicle_services
                     (vehicle_id, institution_id, service_date, service_type, description,
                      odometer_km, cost, vendor, next_service_date, next_service_km, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $vehicleId,
                $instId,
                $serviceDate,
                $serviceType,
                $description ?: null,
                ($odometerKm !== '' && $odometerKm !== null) ? (int)$odometerKm : null,
                number_format((float)$cost, 2, '.', ''),
                $vendor ?: null,
                $nextServiceDate ?: null,
                ($nextServiceKm !== '' && $nextServiceKm !== null) ? (int)$nextServiceKm : null,
                authId(),
            ]);
            setFlash('success', 'Service entry logged successfully.');
        }
        header('Location: ' . BASE_URL . '/app/services/vehicle-service?vehicle_id=' . $vehicleId);
        exit;

    } elseif ($action === 'delete') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($serviceId) {
            $db->prepare(
                "DELETE FROM transport_vehicle_services WHERE id = ? AND institution_id = ?"
            )->execute([$serviceId, $instId]);
            setFlash('success', 'Service entry deleted.');
        }
        header('Location: ' . BASE_URL . '/app/services/vehicle-service?vehicle_id=' . $vehicleId);
        exit;
    }
}

// ── Load all active vehicles for filter bar ────────────────────────────────────
$vehStmt = $db->prepare(
    "SELECT id, registration_no, make, model FROM transport_vehicles
     WHERE institution_id = ? AND is_active = 1
     ORDER BY registration_no ASC"
);
$vehStmt->execute([$instId]);
$allVehicles = $vehStmt->fetchAll();

// ── Selected vehicle ───────────────────────────────────────────────────────────
$selectedVehicleId = (int)($_GET['vehicle_id'] ?? 0);
$vehicle    = null;
$services   = [];
$stats      = ['total_this_year' => 0, 'cost_this_year' => '0.00', 'last_service' => null];

if ($selectedVehicleId) {
    $vs = $db->prepare(
        "SELECT * FROM transport_vehicles WHERE id = ? AND institution_id = ? AND is_active = 1"
    );
    $vs->execute([$selectedVehicleId, $instId]);
    $vehicle = $vs->fetch();

    if ($vehicle) {
        $currentYear = (int)date('Y');

        // Stats for current year
        $stStmt = $db->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(cost), 0) AS total_cost,
                    MAX(service_date) AS last_date
             FROM transport_vehicle_services
             WHERE vehicle_id = ? AND institution_id = ?
               AND YEAR(service_date) = ?"
        );
        $stStmt->execute([$selectedVehicleId, $instId, $currentYear]);
        $yearStats = $stStmt->fetch();

        // Last service overall (may be from a prior year)
        $lastStmt = $db->prepare(
            "SELECT MAX(service_date) AS last_date
             FROM transport_vehicle_services
             WHERE vehicle_id = ? AND institution_id = ?"
        );
        $lastStmt->execute([$selectedVehicleId, $instId]);
        $lastRow = $lastStmt->fetch();

        $stats = [
            'total_this_year' => (int)($yearStats['total'] ?? 0),
            'cost_this_year'  => number_format((float)($yearStats['total_cost'] ?? 0), 2),
            'last_service'    => $lastRow['last_date'] ?? null,
        ];

        // Service history
        $svcStmt = $db->prepare(
            "SELECT tvs.*, u.full_name AS done_by
             FROM transport_vehicle_services tvs
             LEFT JOIN users u ON u.id = tvs.created_by
             WHERE tvs.vehicle_id = ? AND tvs.institution_id = ?
             ORDER BY tvs.service_date DESC, tvs.id DESC
             LIMIT 50"
        );
        $svcStmt->execute([$selectedVehicleId, $instId]);
        $services = $svcStmt->fetchAll();
    } else {
        $selectedVehicleId = 0;
    }
}

// ── Compliance badge helper ────────────────────────────────────────────────────
$todayTs = strtotime(date('Y-m-d'));
$soonTs  = $todayTs + (30 * 86400);

$complianceBadge = function (?string $date, string $label) use ($todayTs, $soonTs): string {
    if ($date === null || $date === '') {
        return '<span class="badge bg-light text-muted">' . h($label) . ': Not set</span>';
    }
    $ts = strtotime($date);
    if ($ts < $todayTs) {
        return '<span class="badge bg-danger">' . h($label) . ' Expired: ' . fmtDate($date, 'd M Y') . '</span>';
    }
    if ($ts <= $soonTs) {
        return '<span class="badge bg-warning text-dark">' . h($label) . ' Due: ' . fmtDate($date, 'd M Y') . '</span>';
    }
    return '<span class="badge bg-success">' . h($label) . ' Valid: ' . fmtDate($date, 'd M Y') . '</span>';
};

$typeLabels = [
    'bus'     => 'Bus',
    'minibus' => 'Minibus',
    'van'     => 'Van',
    'car'     => 'Car',
    'other'   => 'Other',
];
$fuelLabels = [
    'diesel'   => 'Diesel',
    'petrol'   => 'Petrol',
    'cng'      => 'CNG',
    'electric' => 'Electric',
    'other'    => 'Other',
];
$serviceTypeLabels = [
    'routine'   => 'Routine Service',
    'repair'    => 'Repair',
    'accident'  => 'Accident/Damage',
    'insurance' => 'Insurance Renewal',
    'fitness'   => 'Fitness Renewal',
    'permit'    => 'Permit Renewal',
    'puc'       => 'PUC Renewal',
    'other'     => 'Other',
];
$serviceTypeBadge = [
    'routine'   => 'bg-primary',
    'repair'    => 'bg-warning text-dark',
    'accident'  => 'bg-danger',
    'insurance' => 'bg-info text-dark',
    'fitness'   => 'bg-info text-dark',
    'permit'    => 'bg-info text-dark',
    'puc'       => 'bg-info text-dark',
    'other'     => 'bg-secondary',
];

$pageTitle   = 'Vehicle Service Log';
$breadcrumbs = [
    'Dashboard'          => dashboardUrl(),
    'Services'           => BASE_URL . '/app/services',
    'Vehicle Service Log' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-tools"></i></div>
  <div>
    <h4>Vehicle Service Log</h4>
    <p>Track maintenance, repairs, and compliance renewals for your fleet.</p>
  </div>
</div>

<!-- ── Vehicle Filter Bar ─────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="d-flex align-items-center gap-3">
      <label class="form-label mb-0 fw-600 text-nowrap small">Select Vehicle</label>
      <select class="form-select form-select-sm" name="vehicle_id"
              style="max-width:360px;" onchange="this.form.submit()">
        <option value="">— Choose a vehicle —</option>
        <?php foreach ($allVehicles as $veh): ?>
        <option value="<?= (int)$veh['id'] ?>"
          <?= $selectedVehicleId === (int)$veh['id'] ? 'selected' : '' ?>>
          <?= h($veh['registration_no']) ?>
          <?php if ($veh['make'] || $veh['model']): ?>
          — <?= h(trim($veh['make'] . ' ' . $veh['model'])) ?>
          <?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if (!$selectedVehicleId || !$vehicle): ?>

<!-- ── Empty state ────────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-body">
    <div class="empty-state py-5">
      <i class="bi bi-truck"></i>
      <h6>No vehicle selected</h6>
      <p class="small">Select a vehicle above to view its service log.</p>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ── Vehicle Info Strip ─────────────────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div>
        <span class="badge bg-dark fs-6 px-3 py-2">
          <i class="bi bi-card-text me-1"></i><?= h($vehicle['registration_no']) ?>
        </span>
      </div>
      <div class="flex-grow-1">
        <?php $makeModel = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')); ?>
        <?php if ($makeModel): ?>
        <div class="fw-600"><?= h($makeModel) ?></div>
        <?php endif; ?>
        <div class="text-muted small">
          <?= h($typeLabels[$vehicle['vehicle_type']] ?? $vehicle['vehicle_type']) ?>
          &nbsp;·&nbsp;Capacity: <?= (int)$vehicle['capacity'] ?>
          &nbsp;·&nbsp;<?= h($fuelLabels[$vehicle['fuel_type']] ?? $vehicle['fuel_type']) ?>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?= $complianceBadge($vehicle['insurance_expiry'] ?? null, 'Insurance') ?>
        <?= $complianceBadge($vehicle['fitness_expiry']   ?? null, 'Fitness') ?>
        <?= $complianceBadge($vehicle['permit_expiry']    ?? null, 'Permit') ?>
        <?= $complianceBadge($vehicle['puc_expiry']       ?? null, 'PUC') ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Summary Stats ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="card text-center py-3">
      <div class="display-6 fw-700 text-primary"><?= $stats['total_this_year'] ?></div>
      <div class="small text-muted">Total Services This Year</div>
    </div>
  </div>
  <div class="col-4">
    <div class="card text-center py-3">
      <div class="display-6 fw-700 text-success">₹<?= h($stats['cost_this_year']) ?></div>
      <div class="small text-muted">Total Cost This Year</div>
    </div>
  </div>
  <div class="col-4">
    <div class="card text-center py-3">
      <div class="fw-700 text-dark" style="font-size:1.1rem;">
        <?= $stats['last_service'] ? fmtDate($stats['last_service'], 'd M Y') : '—' ?>
      </div>
      <div class="small text-muted">Last Service</div>
    </div>
  </div>
</div>

<!-- ── Two-Column: Form + History ────────────────────────────────────────────── -->
<div class="row g-4">

  <!-- ── Add Service Entry ────────────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>Add Service Entry
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">

          <div class="mb-3">
            <label class="form-label">Service Date <span class="required">*</span></label>
            <input type="date" class="form-control" name="service_date"
                   value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Service Type <span class="required">*</span></label>
            <select class="form-select" name="service_type" required>
              <option value="">— Select type —</option>
              <?php foreach ($serviceTypeLabels as $val => $lbl): ?>
              <option value="<?= $val ?>"><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3"
                      placeholder="Details of work done, parts replaced, etc."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Odometer (km)</label>
            <input type="number" class="form-control" name="odometer_km"
                   min="0" placeholder="Current odometer reading">
          </div>

          <div class="mb-3">
            <label class="form-label">Cost (₹)</label>
            <input type="number" class="form-control" name="cost"
                   step="0.01" min="0" placeholder="0.00">
          </div>

          <div class="mb-3">
            <label class="form-label">Vendor</label>
            <input type="text" class="form-control" name="vendor"
                   maxlength="120" placeholder="Garage / vendor name">
          </div>

          <div class="mb-3">
            <label class="form-label">Next Service Date</label>
            <input type="date" class="form-control" name="next_service_date">
          </div>

          <div class="mb-3">
            <label class="form-label">Next Service Odometer (km)</label>
            <input type="number" class="form-control" name="next_service_km"
                   min="0" placeholder="e.g. 55000">
          </div>

          <button type="submit" class="btn btn-success">
            <i class="bi bi-check2 me-1"></i>Log Entry
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Service History ──────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-clock-history me-2 text-primary"></i>Service History
        <span class="badge bg-secondary ms-1"><?= count($services) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($services): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>Odometer</th>
                <th>Cost</th>
                <th>Vendor</th>
                <th>Next Service</th>
                <th>By</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($services as $svc):
                $badgeCls = $serviceTypeBadge[$svc['service_type']] ?? 'bg-secondary';
                $typeLabel = $serviceTypeLabels[$svc['service_type']] ?? $svc['service_type'];
                $desc = $svc['description'] ?? '';
                $descShort = mb_strlen($desc) > 80 ? mb_substr($desc, 0, 80) . '…' : $desc;
                $hasCost = (float)($svc['cost'] ?? 0) > 0;
              ?>
              <tr>
                <td class="text-nowrap small"><?= fmtDate($svc['service_date'], 'd M Y') ?></td>
                <td><span class="badge <?= $badgeCls ?>"><?= h($typeLabel) ?></span></td>
                <td class="small" style="max-width:160px;">
                  <?php if ($desc): ?>
                  <span title="<?= h($desc) ?>"><?= h($descShort) ?></span>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="small text-nowrap">
                  <?= $svc['odometer_km'] !== null ? h((string)(int)$svc['odometer_km']) . ' km' : '—' ?>
                </td>
                <td class="small text-nowrap">
                  <?php if ($hasCost): ?>
                  <span class="text-success fw-600">₹<?= h(number_format((float)$svc['cost'], 2)) ?></span>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="small"><?= $svc['vendor'] ? h($svc['vendor']) : '<span class="text-muted">—</span>' ?></td>
                <td class="small text-nowrap">
                  <?php if ($svc['next_service_date']): ?>
                    <?= fmtDate($svc['next_service_date'], 'd M Y') ?>
                    <?php if ($svc['next_service_km'] !== null): ?>
                    <div class="text-muted" style="font-size:.72rem;">/ <?= h((string)(int)$svc['next_service_km']) ?> km</div>
                    <?php endif; ?>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted text-nowrap">
                  <?= $svc['done_by'] ? h($svc['done_by']) : '—' ?>
                </td>
                <td>
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                    <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-outline-danger btn-icon"
                            title="Delete"
                            data-confirm="Delete this service entry?">
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
          <i class="bi bi-tools"></i>
          <h6>No service entries yet</h6>
          <p class="small">Use the form on the left to log the first service entry.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->

<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
