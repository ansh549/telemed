<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
if (!is_admin()) { header('Location: /login.php'); exit; }
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$specialty = trim($_POST['specialty'] ?? '');
$errors = [];
if ($name === '') $errors[] = 'Name required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required';
if (strlen($password) < 6) $errors[] = 'Password min 6 chars';
if ($errors) {
    header('Location: /dashboard/admin.php?msg=' . urlencode($errors[0]));
    exit;
}
$hash = password_hash($password, PASSWORD_DEFAULT);
try {
    run_query('INSERT INTO users (name, email, password, role, specialty, created_at) VALUES (?,?,?,?,?,datetime("now"))',
        [$name, $email, $hash, 'doctor', $specialty]);
    header('Location: /dashboard/admin.php?msg=' . urlencode('Doctor added'));
    exit;
} catch (Exception $e) {
    header('Location: /dashboard/admin.php?msg=' . urlencode('Email already exists'));
    exit;
}
