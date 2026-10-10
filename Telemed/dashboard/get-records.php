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
    
    // Get all patients who have had appointments with this doctor
    $stmt = $db->prepare("SELECT p.*, 
        COUNT(a.id) as total_visits,
        MAX(a.appointment_date) as last_visit
        FROM patients p 
        JOIN appointments a ON a.patient_id = p.id 
        WHERE a.doctor_id = ? 
        GROUP BY p.id
        ORDER BY last_visit DESC");
    $stmt->execute([$doctor['id']]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'patients' => $patients
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading patient records: ' . $e->getMessage()
    ]);
}
?>