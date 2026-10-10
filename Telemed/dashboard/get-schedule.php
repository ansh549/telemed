<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

require_login();
if (!is_doctor()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$current_user = current_user();
$doctor_user_id = $current_user['id'];

try {
    // Get doctor's ID
    $stmt = $db->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$doctor_user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        echo json_encode(['success' => false, 'message' => 'Doctor profile not found']);
        exit;
    }
    
    // Get all appointments for this doctor (next 30 days)
    $stmt = $db->prepare("SELECT a.*, p.name as patient_name, p.age, p.gender, p.contact 
        FROM appointments a 
        JOIN patients p ON p.id = a.patient_id 
        WHERE a.doctor_id = ? AND a.appointment_date >= datetime('now') 
        AND a.appointment_date <= datetime('now', '+30 days')
        ORDER BY a.appointment_date ASC");
    $stmt->execute([$doctor['id']]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'appointments' => $appointments
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading schedule: ' . $e->getMessage()
    ]);
}
?>