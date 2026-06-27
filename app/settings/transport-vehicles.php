<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$editId  = (int)($_GET['edit_id'] ?? 0);
$editVeh = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM transport_vehicles WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editVeh = $es->fetch();
    if (!$editVeh) {
        setFlash('error', 'Vehicle not found.');
        header('Location: ' . BASE_URL . '/app/settings/transport-vehicles');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int)($_POST['vehicle_id'] ?? 0);
        if ($id) {
            $db->prepare(
                "UPDATE transport_vehicles SET is_active = 1 - is_active WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Vehicle status updated.');
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-vehicles');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['vehicle_id'] ?? 0);
        if ($id) {
            $chk = $db->prepare(
                "SELECT COUNT(*) FROM transport_routes WHERE vehicle_id = ? AND institution_id = ?"
            );
            $chk->execute([$id, $instId]);
            if ((int)$chk->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: vehicle is assigned to one or more routes.');
            } else {
                $db->prepare(
                    "DELETE FROM transport_vehicles WHERE id = ? AND institution_id = ?"
                )->execute([$id, $instId]);
                setFlash('success', 'Vehicle deleted.');
            }
        }
        header('Location: ' . BASE_URL . '/app/settings/transport-vehicles');
        exit;

    } elseif ($action === 'add' || $action === 'edit') {
        $regNo           = strtoupper(trim($_POST['registration_no']  ?? ''));
        $make            = trim($_POST['make']             ?? '');
        $model           = trim($_POST['model']            ?? '');
        $year            = $_POST['manufacture_year']      ?? '';
        $color           = trim($_POST['color']            ?? '');
        $capacity        = (int)($_POST['capacity']        ?? 40);
        $vehicleType     = $_POST['vehicle_type']          ?? 'bus';
        $fuelType        = $_POST['fuel_type']             ?? 'diesel';
        $chassisNo       = trim($_POST['chassis_no']       ?? '');
        $engineNo        = trim($_POST['engine_no']        ?? '');
        $notes           = trim($_POST['notes']            ?? '');
        $insuranceNo     = trim($_POST['insurance_no']     ?? '');
        $insuranceExpiry = $_POST['insurance_expiry']      ?? '';
        $fitnessExpiry   = $_POST['fitness_expiry']        ?? '';
        $permitExpiry    = $_POST['permit_expiry']         ?? '';
        $pucExpiry       = $_POST['puc_expiry']            ?? '';
        $id              = (int)($_POST['vehicle_id']      ?? 0);

        $validTypes  = ['bus', 'minibus', 'van', 'car', 'other'];
        $validFuels  = ['diesel', 'petrol', 'cng', 'electric', 'other'];
        $currentYear = (int)date('Y');

        if (!$regNo) {
            $error = 'Registration number is required.';
        } elseif (!in_array($vehicleType, $validTypes, true)) {
            $error = 'Invalid vehicle type.';
        } elseif (!in_array($fuelType, $validFuels, true)) {
            $error = 'Invalid fuel type.';
        } elseif ($year !== '' && ($year < 1980 || $year > $currentYear + 1)) {
            $error = 'Manufacture year must be between 1980 and ' . ($currentYear + 1) . '.';
        } elseif ($capacity < 1 || $capacity > 255) {
            $error = 'Capacity must be between 1 and 255.';
        }

        if (!$error) {
            $yearVal           = $year !== '' ? (int)$year : null;
            $insuranceExpiryVal = $insuranceExpiry ?: null;
            $fitnessExpiryVal  = $fitnessExpiry ?: null;
            $permitExpiryVal   = $permitExpiry ?: null;
            $pucExpiryVal      = $pucExpiry ?: null;

            try {
                if ($action === 'edit' && $id) {
                    $db->prepare(
                        "UPDATE transport_vehicles
                         SET registration_no = ?, make = ?, model = ?, manufacture_year = ?,
                             color = ?, capacity = ?, vehicle_type = ?, fuel_type = ?,
                             chassis_no = ?, engine_no = ?, notes = ?,
                             insurance_no = ?, insurance_expiry = ?,
                             fitness_expiry = ?, permit_expiry = ?, puc_expiry = ?
                         WHERE id = ? AND institution_id = ?"
                    )->execute([
                        $regNo, $make ?: null, $model ?: null, $yearVal,
                        $color ?: null, $capacity, $vehicleType, $fuelType,
                        $chassisNo ?: null, $engineNo ?: null, $notes ?: null,
                        $insuranceNo ?: null, $insuranceExpiryVal,
                        $fitnessExpiryVal, $permitExpiryVal, $pucExpiryVal,
                        $id, $instId,
                    ]);
                    setFlash('success', "Vehicle '{$regNo}' updated.");
                } else {
                    $db->prepare(
                        "INSERT INTO transport_vehicles
                             (institution_id, registration_no, make, model, manufacture_year,
                              color, capacity, vehicle_type, fuel_type,
                              chassis_no, engine_no, notes,
                              insurance_no, insurance_expiry,
                              fitness_expiry, permit_expiry, puc_expiry, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)"
                    )->execute([
                        $instId, $regNo, $make ?: null, $model ?: null, $yearVal,
                        $color ?: null, $capacity, $vehicleType, $fuelType,
                        $chassisNo ?: null, $engineNo ?: null, $notes ?: null,
                        $insuranceNo ?: null, $insuranceExpiryVal,
                        $fitnessExpiryVal, $permitExpiryVal, $pucExpiryVal,
                    ]);
                    setFlash('success', "Vehicle '{$regNo}' added.");
                }
            } catch (Exception $e) {
                $error = 'A vehicle with that registration number already exists for this institution.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/settings/transport-vehicles');
                exit;
            }
        }
    }
}

$vehStmt = $db->prepare(
    "SELECT * FROM transport_vehicles
     WHERE institution_id = ?
     ORDER BY is_active DESC, registration_no ASC"
);
$vehStmt->execute([$instId]);
$vehicles = $vehStmt->fetchAll();

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

$today       = strtotime(date('Y-m-d'));
$soon        = $today + (30 * 86400);
$currentYear = (int)date('Y');

$v = fn(string $f, string $d = '') => h($editVeh[$f] ?? $_POST[$f] ?? $d);

$pageTitle   = 'Vehicles';
$breadcrumbs = [
    'Dashboard' => dashboardUrl(),
    'Settings'  => BASE_URL . '/app/settings',
    'Vehicles'  => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-truck"></i></div>
  <div>
    <h4>Vehicles</h4>
    <p>Manage your fleet and track compliance dates.</p>
  </div>
</div>

<div class="row g-4">

  <!-- ── Add / Edit Form ────────────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-plus-circle me-2 text-primary"></i>
        <?= $editVeh ? 'Edit Vehicle' : 'Add Vehicle' ?>
      </div>
      <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editVeh ? 'edit' : 'add' ?>">
          <?php if ($editVeh): ?>
          <input type="hidden" name="vehicle_id" value="<?= (int)$editVeh['id'] ?>">
          <?php endif; ?>

          <p class="text-muted small mb-3 fw-600">Vehicle Info</p>

          <div class="mb-3">
            <label class="form-label">Registration No <span class="required">*</span></label>
            <input type="text" class="form-control text-uppercase" name="registration_no"
                   maxlength="30"
                   value="<?= $v('registration_no') ?>"
                   placeholder="e.g. MH12AB1234" required>
            <div class="form-text">Must be unique for this institution.</div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Make</label>
              <input type="text" class="form-control" name="make" maxlength="60"
                     value="<?= $v('make') ?>" placeholder="e.g. Tata">
            </div>
            <div class="col-6">
              <label class="form-label">Model</label>
              <input type="text" class="form-control" name="model" maxlength="60"
                     value="<?= $v('model') ?>" placeholder="e.g. Starbus">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Manufacture Year</label>
              <input type="number" class="form-control" name="manufacture_year"
                     min="1980" max="<?= $currentYear + 1 ?>"
                     value="<?= $v('manufacture_year') ?>" placeholder="<?= $currentYear ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Color</label>
              <input type="text" class="form-control" name="color" maxlength="30"
                     value="<?= $v('color') ?>" placeholder="e.g. Yellow">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Capacity</label>
              <input type="number" class="form-control" name="capacity"
                     min="1" max="255"
                     value="<?= $v('capacity', '40') ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Vehicle Type</label>
              <select class="form-select" name="vehicle_type">
                <?php foreach ($typeLabels as $val => $lbl):
                  $sel = ($v('vehicle_type', 'bus') === $val) ? 'selected' : '';
                ?>
                <option value="<?= $val ?>" <?= $sel ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Fuel Type</label>
            <select class="form-select" name="fuel_type">
              <?php foreach ($fuelLabels as $val => $lbl):
                $sel = ($v('fuel_type', 'diesel') === $val) ? 'selected' : '';
              ?>
              <option value="<?= $val ?>" <?= $sel ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Chassis No</label>
            <input type="text" class="form-control" name="chassis_no" maxlength="60"
                   value="<?= $v('chassis_no') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Engine No</label>
            <input type="text" class="form-control" name="engine_no" maxlength="60"
                   value="<?= $v('engine_no') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="3"
                      placeholder="Any additional notes"><?= $v('notes') ?></textarea>
          </div>

          <details <?= ($v('insurance_no') || $v('insurance_expiry') || $v('fitness_expiry') || $v('permit_expiry') || $v('puc_expiry')) ? 'open' : '' ?>>
            <summary class="text-muted small fw-600 mb-3" style="cursor:pointer;">Compliance Dates</summary>

            <div class="mb-3 mt-3">
              <label class="form-label">Insurance No</label>
              <input type="text" class="form-control" name="insurance_no" maxlength="80"
                     value="<?= $v('insurance_no') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Insurance Expiry</label>
              <input type="date" class="form-control" name="insurance_expiry"
                     value="<?= $v('insurance_expiry') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Fitness Expiry</label>
              <input type="date" class="form-control" name="fitness_expiry"
                     value="<?= $v('fitness_expiry') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Permit Expiry</label>
              <input type="date" class="form-control" name="permit_expiry"
                     value="<?= $v('permit_expiry') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">PUC Expiry</label>
              <input type="date" class="form-control" name="puc_expiry"
                     value="<?= $v('puc_expiry') ?>">
            </div>
          </details>

          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $editVeh ? 'Update Vehicle' : 'Add Vehicle' ?>
            </button>
            <?php if ($editVeh): ?>
            <a href="<?= h(BASE_URL . '/app/settings/transport-vehicles') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Vehicle List ──────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-truck me-2 text-primary"></i>Fleet
        <span class="badge bg-secondary ms-1"><?= count($vehicles) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($vehicles): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Registration</th>
                <th>Make / Model</th>
                <th>Type</th>
                <th>Cap.</th>
                <th>Compliance</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vehicles as $veh):
                $expiryDates = [];
                foreach (['insurance_expiry', 'fitness_expiry', 'permit_expiry', 'puc_expiry'] as $field) {
                    if (!empty($veh[$field])) {
                        $expiryDates[] = strtotime($veh[$field]);
                    }
                }
                $complianceBadge = '<span class="badge bg-success">OK</span>';
                if ($expiryDates) {
                    foreach ($expiryDates as $ts) {
                        if ($ts < $today) {
                            $complianceBadge = '<span class="badge bg-danger">Expired</span>';
                            break;
                        }
                    }
                    if ($complianceBadge === '<span class="badge bg-success">OK</span>') {
                        foreach ($expiryDates as $ts) {
                            if ($ts <= $soon) {
                                $complianceBadge = '<span class="badge bg-warning text-dark">Expiring Soon</span>';
                                break;
                            }
                        }
                    }
                }
              ?>
              <tr>
                <td>
                  <div class="fw-600 small"><?= h($veh['registration_no']) ?></div>
                  <?php if ($veh['color']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= h($veh['color']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?php if ($veh['make'] || $veh['model']): ?>
                  <div><?= h(trim($veh['make'] . ' ' . $veh['model'])) ?></div>
                  <?php endif; ?>
                  <?php if ($veh['manufacture_year']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= (int)$veh['manufacture_year'] ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-primary bg-opacity-10 text-primary">
                    <?= h($typeLabels[$veh['vehicle_type']] ?? $veh['vehicle_type']) ?>
                  </span>
                </td>
                <td class="small text-center"><?= (int)$veh['capacity'] ?></td>
                <td><?= $complianceBadge ?></td>
                <td>
                  <?= $veh['is_active']
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?>
                </td>
                <td>
                  <div class="d-flex gap-1 flex-nowrap">
                    <a href="<?= h(BASE_URL . '/app/settings/transport-vehicles?edit_id=' . (int)$veh['id']) ?>"
                       class="btn btn-sm btn-outline-primary btn-icon"
                       title="Edit" data-bs-toggle="tooltip">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="vehicle_id" value="<?= (int)$veh['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-icon <?= $veh['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                              title="<?= $veh['is_active'] ? 'Deactivate' : 'Activate' ?>"
                              data-bs-toggle="tooltip"
                              data-confirm="<?= $veh['is_active'] ? 'Deactivate this vehicle?' : 'Activate this vehicle?' ?>">
                        <i class="bi <?= $veh['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="vehicle_id" value="<?= (int)$veh['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger btn-icon"
                              title="Delete" data-bs-toggle="tooltip"
                              data-confirm="Delete vehicle '<?= h(addslashes($veh['registration_no'])) ?>'? This cannot be undone.">
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
          <i class="bi bi-truck"></i>
          <h6>No vehicles yet</h6>
          <p class="small">Add your first vehicle to start managing your fleet.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
