<?php
// includes/header.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Support both auth.php and session_helper.php if available
if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
} elseif (file_exists(__DIR__ . '/session_helper.php')) {
    require_once __DIR__ . '/session_helper.php';
}

// Dynamic Project Base URL Resolver (eliminates broken absolute / links in XAMPP)
$projectDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$baseUrl    = rtrim(str_replace($docRoot, '', $projectDir), '/') . '/';
if ($baseUrl === '//' || empty($baseUrl)) {
    $baseUrl = '/';
}

// User state detection supporting multiple session structures
$isLoggedIn = false;
if (function_exists('is_logged_in')) {
    $isLoggedIn = is_logged_in();
} else {
    $isLoggedIn = isset($_SESSION['user']) || isset($_SESSION['user_id']);
}

$currentUser = null;
if (function_exists('current_user')) {
    $currentUser = current_user();
} elseif (function_exists('get_logged_user')) {
    $currentUser = get_logged_user();
} elseif (isset($_SESSION['user'])) {
    $currentUser = $_SESSION['user'];
}

$displayName = 'User';
$userRole    = 'patient';

if ($currentUser) {
    $displayName = $currentUser['name'] ?? ($currentUser['username'] ?? ($_SESSION['username'] ?? 'User'));
    $userRole    = $currentUser['role'] ?? ($_SESSION['role'] ?? 'patient');
}

// Determine dashboard redirect path
$dashboardUrl = $baseUrl . (file_exists($projectDir . "/dashboard/{$userRole}.php") ? "dashboard/{$userRole}.php" : "{$userRole}.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeleMed — Telemedicine System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col text-slate-800">
    <nav class="bg-white shadow-sm border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Brand / Logo -->
                <div class="flex items-center">
                    <a href="<?= htmlspecialchars($baseUrl) ?>index.php" class="flex items-center gap-2 text-xl font-bold text-indigo-600 hover:text-indigo-700">
                        <span class="text-2xl">🏥</span>
                        <span>TeleMed</span>
                    </a>
                </div>

                <!-- Right Corner Authentication Links -->
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <?php if ($isLoggedIn): ?>
                        <span class="text-sm text-slate-600 hidden sm:inline">
                            Welcome, <strong class="text-slate-900"><?= htmlspecialchars($displayName) ?></strong>
                        </span>
                        <span class="text-xs font-semibold bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded capitalize">
                            <?= htmlspecialchars($userRole) ?>
                        </span>
                        <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                            Dashboard
                        </a>
                        <a href="<?= htmlspecialchars($baseUrl) ?>logout.php" class="text-sm font-semibold text-rose-600 hover:text-rose-800 transition-colors">
                            Logout
                        </a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($baseUrl) ?>login.php" class="text-sm font-semibold text-slate-600 hover:text-indigo-600 px-3 py-1.5 rounded transition-colors">
                            Login
                        </a>
                        <a href="<?= htmlspecialchars($baseUrl) ?>register.php" class="text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-1.5 rounded-lg shadow-sm transition-all">
                            Register
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex-1 w-full">
        <?php
        // Auto-render flash notification messages if messages.php exists
        $msgFile = __DIR__ . '/messages.php';
        if (file_exists($msgFile)) {
            require_once $msgFile;
        }
        ?>