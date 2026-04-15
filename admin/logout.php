<?php
// admin/logout.php
// Destroys admin session only — does NOT affect regular user sessions


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Only unset admin-specific session keys
// Regular user session keys (user_id, user_name) are untouched
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);


// If no other session data remains, destroy entirely
if (empty($_SESSION)) {
    session_destroy();
}


header('Location: admin-login.php');
exit;

