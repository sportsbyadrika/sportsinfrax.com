<?php
/**
 * SportsInfraX – Authentication & Role Helpers
 */

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireLogin(string $redirectTo = ''): void
{
    if (!isLoggedIn()) {
        $back = $redirectTo ?: BASE_URL . '/app/auth/login';
        header('Location: ' . $back);
        exit;
    }

    // Regenerate session ID periodically to prevent fixation
    if (empty($_SESSION['last_regenerated']) ||
        (time() - $_SESSION['last_regenerated']) > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }
}

/**
 * Ensure the logged-in user has one of the allowed roles.
 * Redirects to the appropriate dashboard if not.
 */
function requireRole(array|string $roles): void
{
    requireLogin();
    if (!is_array($roles)) $roles = [$roles];
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        setFlash('error', 'You do not have permission to access that page.');
        header('Location: ' . dashboardUrl());
        exit;
    }
}

function dashboardUrl(): string
{
    return match($_SESSION['user_role'] ?? '') {
        'super_admin'       => BASE_URL . '/app/super-admin/dashboard',
        'institution_admin' => BASE_URL . '/app/institution-admin/dashboard',
        'staff'             => BASE_URL . '/app/staff/dashboard',
        default             => BASE_URL . '/app/auth/login',
    };
}

// ── Session Accessors ──────────────────────────────────────
function authId(): int      { return (int)($_SESSION['user_id']   ?? 0); }
function authName(): string { return $_SESSION['user_name']        ?? ''; }
function authEmail(): string{ return $_SESSION['user_email']       ?? ''; }
function authRole(): string { return $_SESSION['user_role']        ?? ''; }
function authInstId(): ?int { return isset($_SESSION['institution_id']) ? (int)$_SESSION['institution_id'] : null; }

function isRole(string $role): bool { return authRole() === $role; }
function isSuperAdmin(): bool       { return isRole('super_admin'); }
function isInstAdmin(): bool        { return isRole('institution_admin'); }
function isStaff(): bool            { return isRole('staff'); }

// ── Session Initialiser (on login) ─────────────────────────
function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_name']       = $user['full_name'];
    $_SESSION['user_role']       = $user['role'];
    $_SESSION['institution_id']  = $user['institution_id'] ?? null;
    $_SESSION['last_regenerated']= time();

    // Update last_login
    $db   = getDB();
    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Permission Gate ────────────────────────────────────────

/**
 * Returns true if the logged-in user may perform module+action (optionally scoped).
 * super_admin and institution_admin always return true.
 * Staff are checked against staff_permissions via hasStaffPermission().
 */
function canDo(string $module, string $action, string $scope = 'all'): bool
{
    $role = authRole();
    if ($role === 'super_admin' || $role === 'institution_admin') return true;
    if ($role !== 'staff') return false;
    $userId = authId();
    $instId = authInstId();
    if (!$userId || !$instId) return false;
    return hasStaffPermission($userId, $instId, $module . '.' . $action, $scope);
}

/**
 * Gate — redirects to dashboard with error flash if the current user
 * cannot perform the given module+action (optionally scoped).
 */
function requirePermission(string $module, string $action, string $scope = 'all'): void
{
    requireLogin();
    if (!canDo($module, $action, $scope)) {
        setFlash('error', 'You do not have permission to perform this action.');
        header('Location: ' . dashboardUrl());
        exit;
    }
}
