<?php
/**
 * SportsInfraX – Application Navbar
 * Horizontal nav with section-based menus and avatar dropdown.
 */
$currentUrl = $_SERVER['REQUEST_URI'] ?? '';
$userRole   = authRole();
$userName   = authName();
$userEmail  = authEmail();
$instId     = authInstId();

// Fetch institution info for admin/staff
$institution = null;
if ($instId) {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT institution_name, logo, status, institution_type FROM institutions WHERE id = ?"
    );
    $stmt->execute([$instId]);
    $institution = $stmt->fetch();
}

// Determine active section from current URL for nav highlight
$_up = parse_url($currentUrl, PHP_URL_PATH) ?? '';
$currentSection = match(true) {
    str_contains($_up, '/institution-admin/') && !str_contains($_up, '/dashboard') => 'institution',
    str_contains($_up, '/members/')    => 'members',
    str_contains($_up, '/accounts/')   => 'accounts',
    str_contains($_up, '/services/')   => 'services',
    str_contains($_up, '/reports/')    => 'reports',
    str_contains($_up, '/settings/')   => 'settings',
    str_contains($_up, '/super-admin/') && str_contains($_up, 'institutions') => 'institutions',
    default => 'dashboard',
};

// Institution status shortcuts
$instReady  = $institution && in_array($institution['status'], ['active', 'pending_approval']);
$instActive = $institution && $institution['status'] === 'active';

// Nav items: [label, href, section]
$navItems = match($userRole) {
    'super_admin' => [
        ['Dashboard',    BASE_URL . '/app/super-admin/dashboard',    'dashboard'],
        ['Institutions', BASE_URL . '/app/super-admin/institutions', 'institutions'],
    ],
    'institution_admin' => array_values(array_filter([
        ['Institution', BASE_URL . '/app/institution-admin', 'institution'],
        $instReady  ? ['Members',  BASE_URL . '/app/members',   'members']  : null,
        $instActive ? ['Services', BASE_URL . '/app/services',  'services'] : null,
        $instActive ? ['Accounts', BASE_URL . '/app/accounts',  'accounts'] : null,
        $instActive ? ['Reports',  BASE_URL . '/app/reports',   'reports']  : null,
        ['Settings',    BASE_URL . '/app/settings',             'settings'],
    ])),
    'staff' => array_values(array_filter([
        $instActive ? ['Members',  BASE_URL . '/app/members',  'members']  : null,
        $instActive ? ['Services', BASE_URL . '/app/services', 'services'] : null,
        $instActive ? ['Accounts', BASE_URL . '/app/accounts', 'accounts'] : null,
        $instActive ? ['Reports',  BASE_URL . '/app/reports',  'reports']  : null,
    ])),
    default => [],
};
?>
<nav class="navbar navbar-expand-lg app-navbar sticky-top">
  <div class="container-xl">

    <!-- Brand → Dashboard -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(dashboardUrl()) ?>">
      <?php if ($institution && $institution['logo']): ?>
        <img src="<?= h(LOGO_URL . '/' . $institution['logo']) ?>"
             alt="Logo" class="nav-inst-logo">
      <?php else: ?>
        <span class="nav-brand-icon"><i class="bi bi-trophy-fill"></i></span>
      <?php endif; ?>
      <span class="nav-brand-text"><?= h(APP_NAME) ?></span>
      <?php if ($institution): ?>
        <span class="nav-inst-name d-none d-md-inline">| <?= h($institution['institution_name']) ?></span>
      <?php endif; ?>
    </a>

    <!-- Toggler -->
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#appNavbar"
            aria-controls="appNavbar" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Nav Items -->
    <div class="collapse navbar-collapse" id="appNavbar">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php foreach ($navItems as [$label, $href, $section]):
            $active = ($currentSection === $section) ? ' active' : '';
        ?>
        <li class="nav-item">
          <a class="nav-link<?= $active ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
        </li>
        <?php endforeach; ?>
      </ul>

      <!-- Right: Role badge + Avatar dropdown -->
      <div class="d-flex align-items-center gap-3 mb-2 mb-lg-0">
        <span class="nav-role-badge <?= $userRole ?>">
          <?= match($userRole) {
            'super_admin'       => '<i class="bi bi-shield-fill-check me-1"></i>Super Admin',
            'institution_admin' => '<i class="bi bi-building me-1"></i>Inst. Admin',
            'staff'             => '<i class="bi bi-person-badge me-1"></i>Staff',
            default             => '',
          } ?>
        </span>

        <div class="dropdown">
          <button class="nav-avatar-btn dropdown-toggle d-flex align-items-center gap-2"
                  type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar-circle"><?= h(mb_strtoupper(mb_substr($userName, 0, 1))) ?></span>
            <span class="d-none d-md-inline nav-user-name"><?= h($userName) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li>
              <div class="dropdown-header d-flex flex-column">
                <span class="fw-semibold"><?= h($userName) ?></span>
                <span class="text-muted small"><?= h($userEmail) ?></span>
              </div>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
              <a class="dropdown-item" href="<?= h(BASE_URL . '/app/auth/change-password') ?>">
                <i class="bi bi-key me-2"></i>Change Password
              </a>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
              <a class="dropdown-item text-danger" href="<?= h(BASE_URL . '/app/auth/logout') ?>">
                <i class="bi bi-box-arrow-right me-2"></i>Logout
              </a>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>
