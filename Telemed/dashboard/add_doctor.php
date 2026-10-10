<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
if (!is_admin()) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $experience = (int)($_POST['experience'] ?? 0);
    $contact = trim($_POST['contact'] ?? '');
    $consultation_fee = (float)($_POST['consultation_fee'] ?? 0);

    $error = "";

    // Validation
    if (empty($name) || empty($username) || empty($email) || empty($password)) {
        $error = "Please fill all required fields.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            $db->beginTransaction();

            // Check if username or email exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "Username or email already exists.";
            } else {
                // Create user account
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'doctor')");
                $stmt->execute([$username, $email, $hashed_password]);
                $user_id = $db->lastInsertId();

                // Create doctor profile
                $stmt = $db->prepare("INSERT INTO doctors (user_id, name, specialization, qualification, experience, contact, consultation_fee, is_available, rating) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0.0)");
                $stmt->execute([$user_id, $name, $specialization, $qualification, $experience, $contact, $consultation_fee]);
                $doctor_id = $db->lastInsertId();

                // Add default availability (Monday to Friday, 10 AM to 5 PM)
                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
                $stmt = $db->prepare("INSERT INTO doctor_availability (doctor_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, 1)");
                foreach ($days as $day) {
                    $stmt->execute([$doctor_id, $day, '10:00:00', '17:00:00']);
                }

                // Log the action
                $current_user = current_user();
                $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, 'doctor_added', 'New doctor account created', ?)");
                $stmt->execute([$current_user['id'], $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

                $db->commit();
                header('Location: ../dashboard/admin.php?msg=Doctor added successfully!');
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to add doctor. Please try again.";
        }
    }

    if ($error) {
        header('Location: ../dashboard/admin.php?error=' . urlencode($error));
        exit;
    }
} else {
    header('Location: ../dashboard/admin.php');
    exit;
}
?>