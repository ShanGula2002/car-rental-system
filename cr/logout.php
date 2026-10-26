<?php
// logout.php - Clear session and redirect to login
session_start();

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie if configured
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session data on the server
session_destroy();

// Redirect to login page
header('Location: ../index.php');
exit;
?>