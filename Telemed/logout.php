<?php
if (ob_get_level()) {
    ob_end_clean();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session variables
$_SESSION = [];
session_unset();

// Invalidate browser session cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Dynamic redirect: works whether hosted on InfinityFree root or inside local /Telemed/
if (file_exists(__DIR__ . '/login.php')) {
    header("Location: login.php");
} elseif (file_exists(__DIR__ . '/../login.php')) {
    header("Location: ../login.php");
} else {
    header("Location: login.php");
}
exit;