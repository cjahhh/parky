<?php
// admin/includes/auth_check.php
// Include this at the top of every admin page
// Redirects to login if admin session is not set


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'admin-login.php' : 'admin/admin-login.php'));
    exit;
}

