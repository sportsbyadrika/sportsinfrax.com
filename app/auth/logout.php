<?php
require_once dirname(__DIR__) . '/bootstrap.php';
logoutUser();
header('Location: ' . BASE_URL . '/app/auth/login?logged_out=1');
exit;
