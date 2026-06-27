<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$instStmt = $db->prepare("SELECT institution_type FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst     = $instStmt->fetch();
$isSchool = ($inst && getInstitutionCategory($inst['institution_type'] ?? '') === 'school');

$staffId = (int)($_GET['staff_id'] ?? 0);
if (!$staffId) {
    setFlash('error', 'No staff member specified.');
    header('Location: ' . BASE_URL . '/app/institution-admin/staff');
    exit;
}

$staffStmt = $db->prepare(
    "SELECT s.id AS staff_id, s.user_id, u.full_name, u.email, s.passport_photo,
            sr.label AS role_label
     FROM staff s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN sch_staff_roles sr ON sr.id = s.sch_role_id
     WHERE s.id = ? AND s.institution_id = ?"
);
$staffStmt->execute([$staffId, $instId]);
$staffMember = $staffStmt->fetch();
if (!$staffMember) {
    setFlash('error', 'Staff member not found.');
    header('Location: ' . BASE_URL . '/app/institution-admin/staff');
    exit;
}

// Load current permissions keyed by module → scope
$permStmt = $db->prepare(
    "SELECT module, scope FROM staff_permissions
      WHERE user_id = ? AND institution_id = ? AND action = 'manage'"
);
$permStmt->execute([$staffMember['user_id'], $instId]);
$currentPerms = array_column($permStmt->fetchAll(), 'scope', 'module');

// Module registry — extend as new modules are built
$modules = [];
if ($isSchool) {
    $modules['students']    = ['label' => 'Students',    'icon' => 'bi-mortarboard-fill',    'desc' => 'Add, view and edit student records.',              'ready' => true];
    $modules['attendance']  = ['label' => 'Attendance',  'icon' => 'bi-calendar-check-fill', 'desc' => 'Mark and view student attendance records.',        'ready' => true];
    $modules['subjects']    = ['label' => 'Subjects',    'icon' => 'bi-book-fill',           'desc' => 'Manage subject master and assignments.',            'ready' => true];
    $modules['timetable']   = ['label' => 'Timetable',   'icon' => 'bi-calendar3-week-fill', 'desc' => 'View and manage the weekly class timetable.',       'ready' => true];
    $modules['exam_marks']  = ['label' => 'Exam Marks',  'icon' => 'bi-pencil-square',       'desc' => 'Enter and view student exam marks.',                'ready' => true];
    $modules['fee_collection'] = ['label' => 'Fee Collection', 'icon' => 'bi-cash-stack',    'desc' => 'Record and view student fee payments.',             'ready' => false];
    $modules['transport']      = ['label' => 'Transport',     'icon' => 'bi-bus-front-fill', 'desc' => 'Manage transport assignments and fee collection.',   'ready' => true];
} else {
    $modules['attendance']  = ['label' => 'Attendance',  'icon' => 'bi-calendar-check-fill', 'desc' => 'Mark and view member attendance records.',         'ready' => true];
}

$levels = [
    'none'      => ['label' => 'No Access',        'badge' => 'bg-secondary'],
    'own_class' => ['label' => 'Own Section Only', 'badge' => 'bg-warning text-dark'],
    'all'       => ['label' => 'Full Access',      'badge' => 'bg-success'],
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $db->beginTransaction();
    try {
        foreach ($modules as $module => $info) {
            if (!$info['ready']) continue;
            $level = $_POST["module_{$module}"] ?? 'none';
            if (!array_key_exists($level, $levels)) $level = 'none';

            $db->prepare(
                "DELETE FROM staff_permissions WHERE user_id = ? AND institution_id = ? AND module = ?"
            )->execute([$staffMember['user_id'], $instId, $module]);

            if ($level !== 'none') {
                $db->prepare(
                    "INSERT INTO staff_permissions
                     (user_id, institution_id, module, action, scope, granted_by)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$staffMember['user_id'], $instId, $module, 'manage', $level, authId()]);
            }
        }
        $db->commit();
        setFlash('success', 'Permissions updated for ' . $staffMember['full_name'] . '.');
        header('Location: ' . BASE_URL . '/app/institution-admin/staff-permissions?staff_id=' . $staffId);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $error = 'Failed to update permissions. Please try again.';
    }
}

$pageTitle   = 'Staff Permissions';
$breadcrumbs = [
    'Dashboard' => BASE_URL . '/app/institution-admin/dashboard',
    'Staff'     => BASE_URL . '/app/institution-admin/staff',
    'Permissions' => '',
];
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">

    <!-- Staff identity card -->
    <div class="card mb-4">
      <div class="card-body py-3 d-flex align-items-center gap-3">
        <?php if (!empty($staffMember['passport_photo'])): ?>
        <img src="<?= h(PHOTO_URL . '/' . $staffMember['passport_photo']) ?>"
             alt="" style="width:48px;height:48px;border-radius:10px;object-fit:cover;flex-shrink:0;">
        <?php else: ?>
        <div class="avatar-circle" style="width:48px;height:48px;font-size:1.1rem;border-radius:10px;flex-shrink:0;">
          <?= mb_strtoupper(mb_substr($staffMember['full_name'], 0, 1)) ?>
        </div>
        <?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-600"><?= h($staffMember['full_name']) ?></div>
          <div class="text-muted small"><?= h($staffMember['email']) ?></div>
          <?php if ($staffMember['role_label']): ?>
          <span class="badge bg-primary bg-opacity-10 text-primary mt-1" style="font-size:.7rem;">
            <?= h($staffMember['role_label']) ?>
          </span>
          <?php endif; ?>
        </div>
        <a href="<?= h(BASE_URL . '/app/institution-admin/staff') ?>"
           class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Back
        </a>
      </div>
    </div>

    <!-- Permissions card -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-shield-lock me-2 text-primary"></i>Module Access Permissions
      </div>
      <div class="card-body">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (!$modules): ?>
        <p class="text-muted small text-center py-3">No configurable permissions for this institution type.</p>
        <?php else: ?>

        <p class="text-muted small mb-4">
          <strong>Own Section Only</strong> limits the staff member to sections where they are
          assigned as class teacher via Services → Sections.
        </p>

        <form method="POST">
          <?= csrfField() ?>
          <div class="d-flex flex-column gap-3">

          <?php foreach ($modules as $module => $info):
            $curScope = $currentPerms[$module] ?? 'none';
            if (!in_array($curScope, ['all','own_class'], true)) $curScope = 'none';
          ?>
          <div class="card border <?= !$info['ready'] ? 'opacity-50' : '' ?>">
            <div class="card-body py-3">
              <div class="d-flex align-items-start gap-3">
                <div class="rounded p-2 bg-primary bg-opacity-10 text-primary flex-shrink-0">
                  <i class="bi <?= h($info['icon']) ?> fs-5"></i>
                </div>
                <div class="flex-grow-1">
                  <div class="fw-600 mb-1">
                    <?= h($info['label']) ?>
                    <?php if (!$info['ready']): ?>
                    <span class="badge bg-secondary ms-2" style="font-size:.65rem;">Coming Soon</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted small mb-3"><?= h($info['desc']) ?></div>
                  <?php if ($info['ready']): ?>
                  <div class="d-flex gap-3 flex-wrap">
                    <?php foreach ($levels as $lvl => $lvlInfo): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio"
                             name="module_<?= h($module) ?>"
                             id="<?= h("{$module}_{$lvl}") ?>"
                             value="<?= h($lvl) ?>"
                             <?= $curScope === $lvl ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="<?= h("{$module}_{$lvl}") ?>">
                        <span class="badge <?= $lvlInfo['badge'] ?>"><?= h($lvlInfo['label']) ?></span>
                      </label>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

          </div><!-- gap-3 -->

          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i>Save Permissions
            </button>
            <a href="<?= h(BASE_URL . '/app/institution-admin/staff') ?>"
               class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>

        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
