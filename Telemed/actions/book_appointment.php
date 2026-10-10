<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
if (!is_patient()) { header('Location: /login.php'); exit; }
$patient_id = $_SESSION['user']['id'];
$doctor_id = intval($_POST['doctor_id'] ?? 0);
$app_datetime = trim($_POST['app_datetime'] ?? '');
$reason = trim($_POST['reason'] ?? '');
$errors = [];
if (!$doctor_id) $errors[] = 'Select a doctor';
if (!$app_datetime) $errors[] = 'Select date & time';
if ($errors) {
    // fallback simple: redirect back (improve later with flash messages)
    header('Location: /dashboard/patient.php');
    exit;
}
run_query('INSERT INTO appointments (patient_id, doctor_id, app_datetime, reason, status, created_at) VALUES (?,?,?,?,?,datetime("now"))',
    [$patient_id, $doctor_id, $app_datetime, $reason, 'booked']);
header('Location: /dashboard/patient.php');
exit;
