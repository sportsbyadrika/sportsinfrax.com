<?php
/**
 * SportsInfraX – App Entry Point
 * Redirects based on login state.
 */
require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    header('Location: ' . dashboardUrl());
} else {
    header('Location: ' . BASE_URL . '/app/auth/login.php');
}
exit;
