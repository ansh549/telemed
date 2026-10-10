<?php
// auth.php
// Handles authentication and session management

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php'; // Database connection

/**
 * Log the user in
 */
function login_user($user) {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'name' => $user['name'] ?? $user['username'],
        'role' => $user['role']
    ];
    
    // Also set the old session variables for backward compatibility
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user']) || isset($_SESSION['user_id']);
}

/**
 * Require login before accessing a page
 */
function require_login() {
    if (!is_logged_in()) {
        header("Location: /login.php");
        exit();
    }
}

/**
 * Log the user out
 */
function logout_user() {
    session_unset();
    session_destroy();
    header("Location: /login.php");
    exit();
}

/**
 * Get current logged in user
 */
function current_user() {
    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    
    // Fallback for old session structure
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'role' => $_SESSION['role'] ?? 'patient'
        ];
    }
    
    return null;
}

/**
 * Role checks
 */
function is_admin() {
    if (isset($_SESSION['user'])) {
        return is_logged_in() && $_SESSION['user']['role'] === 'admin';
    }
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'admin';
}

function is_doctor() {
    if (isset($_SESSION['user'])) {
        return is_logged_in() && $_SESSION['user']['role'] === 'doctor';
    }
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'doctor';
}

function is_patient() {
    if (isset($_SESSION['user'])) {
        return is_logged_in() && $_SESSION['user']['role'] === 'patient';
    }
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'patient';
}