<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
if (!is_doctor()) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_GET['id'])) {
    header('Location: doctor.php');
    exit;
}

$appointment_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if (!$appointment_id) {
    $_SESSION['error'] = "Invalid appointment ID.";
    header('Location: doctor.php');
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
        die("Doctor profile not found.");
    }

    // Get appointment details - Fixed SQL to remove p.email reference
    $stmt = $db->prepare("
        SELECT a.*, p.name as patient_name, p.contact
        FROM appointments a 
        JOIN patients p ON p.id = a.patient_id 
        WHERE a.id = ? AND a.doctor_id = ?
    ");
    $stmt->execute([$appointment_id, $doctor['id']]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        $_SESSION['error'] = "Appointment not found.";
        header('Location: doctor.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_date = trim($_POST['new_date'] ?? '');
        $new_time = trim($_POST['new_time'] ?? '');
        $reason = trim($_POST['reason'] ?? 'Rescheduled by doctor');
        
        if (empty($new_date) || empty($new_time)) {
            $error = "Please select both date and time.";
        } else {
            // Validate date format
            $dateObj = DateTime::createFromFormat('Y-m-d', $new_date);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $new_date) {
                $error = "Invalid date format.";
            } else {
                // Validate time format
                $timeObj = DateTime::createFromFormat('H:i', $new_time);
                if (!$timeObj || $timeObj->format('H:i') !== $new_time) {
                    $error = "Invalid time format.";
                } else {
                    $new_datetime = $new_date . ' ' . $new_time . ':00';
                    
                    // Check if the new time is in the past
                    if (strtotime($new_datetime) <= time()) {
                        $error = "Cannot reschedule to a past date and time.";
                    } else {
                        // Check if the new time slot is available
                        $stmt = $db->prepare("
                            SELECT COUNT(*) FROM appointments 
                            WHERE doctor_id = ? AND appointment_date = ? AND status != 'cancelled' AND id != ?
                        ");
                        $stmt->execute([$doctor['id'], $new_datetime, $appointment_id]);
                        $existing = $stmt->fetchColumn();
                        
                        if ($existing > 0) {
                            $error = "This time slot is already booked. Please choose another time.";
                        } else {
                            // Update appointment
                            $stmt = $db->prepare("
                                UPDATE appointments 
                                SET appointment_date = ?, reschedule_reason = ?, rescheduled_at = datetime('now')
                                WHERE id = ?
                            ");
                            $stmt->execute([$new_datetime, $reason, $appointment_id]);
                            
                            $_SESSION['success'] = "Appointment rescheduled successfully!";
                            header('Location: doctor.php');
                            exit;
                        }
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    $error = "Error processing request: " . $e->getMessage();
}

// Helper function for safe HTML output
function safe_display($value, $default = '') {
    return htmlspecialchars($value ?? $default, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reschedule Appointment - Telemed</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; }
        
        .navbar {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            padding: 1rem 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h1 { font-size: 1.5rem; }
        .back-btn { 
            background: rgba(255,255,255,0.2); 
            color: white; 
            border: none; 
            padding: 0.5rem 1rem; 
            border-radius: 5px; 
            text-decoration: none; 
            transition: background 0.3s;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 1.5rem;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .card-content { padding: 2rem; }
        
        .current-appointment {
            background: #f0f9ff;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
            margin-bottom: 2rem;
        }
        
        .appointment-info {
            display: grid;
            gap: 0.5rem;
        }
        
        .appointment-info p {
            margin: 0;
        }
        
        .appointment-info strong {
            color: #1e40af;
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }
        
        .form-input, .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        
        .form-textarea {
            height: 100px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background: #059669;
            color: white;
        }
        
        .btn-primary:hover {
            background: #047857;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border-left-color: #dc2626;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left-color: #059669;
        }
        
        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .button-group { 
                flex-direction: column;
                align-items: stretch;
            }
            .navbar { padding: 1rem; }
            .navbar h1 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>📅 Reschedule Appointment</h1>
        <a href="doctor.php" class="back-btn">← Back to Dashboard</a>
    </div>

    <div class="container">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?= safe_display($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?= safe_display($_SESSION['success']) ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= safe_display($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                📄 Reschedule Appointment
            </div>
            <div class="card-content">
                <div class="current-appointment">
                    <h4 style="margin-bottom: 1rem; color: #1e40af;">Current Appointment Details</h4>
                    <div class="appointment-info">
                        <p><strong>Patient:</strong> <?= safe_display($appointment['patient_name']) ?></p>
                        <p><strong>Current Date & Time:</strong> <?= date('M d, Y - g:i A', strtotime($appointment['appointment_date'])) ?></p>
                        <p><strong>Type:</strong> <?= $appointment['appointment_type'] === 'video' ? '📹 Video Call' : '💬 Chat' ?></p>
                        <p><strong>Contact:</strong> <?= safe_display($appointment['contact']) ?></p>
                        <p><strong>Status:</strong> <span style="color: #f59e0b; font-weight: 600;"><?= ucfirst($appointment['status']) ?></span></p>
                        <?php if ($appointment['symptoms']): ?>
                            <p><strong>Symptoms:</strong> <?= safe_display($appointment['symptoms']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" id="rescheduleForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="new_date">New Date *</label>
                            <input type="date" 
                                   id="new_date"
                                   name="new_date" 
                                   class="form-input" 
                                   min="<?= date('Y-m-d') ?>" 
                                   max="<?= date('Y-m-d', strtotime('+30 days')) ?>" 
                                   value="<?= $_POST['new_date'] ?? '' ?>"
                                   required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_time">New Time *</label>
                            <input type="time" 
                                   id="new_time"
                                   name="new_time" 
                                   class="form-input" 
                                   value="<?= $_POST['new_time'] ?? '' ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="reason">Reason for Rescheduling</label>
                        <textarea id="reason"
                                  name="reason" 
                                  class="form-textarea" 
                                  placeholder="Please provide a reason for rescheduling..."><?= safe_display($_POST['reason'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            Reschedule Appointment
                        </button>
                        <a href="doctor.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Form validation and UX improvements
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('rescheduleForm');
            const dateInput = document.getElementById('new_date');
            const timeInput = document.getElementById('new_time');
            const submitBtn = document.getElementById('submitBtn');

            // Set minimum time based on selected date
            dateInput.addEventListener('change', function() {
                const selectedDate = new Date(this.value);
                const today = new Date();
                
                if (selectedDate.toDateString() === today.toDateString()) {
                    // If today is selected, set minimum time to current time + 1 hour
                    const minTime = new Date(today.getTime() + 60 * 60 * 1000);
                    const hours = minTime.getHours().toString().padStart(2, '0');
                    const minutes = minTime.getMinutes().toString().padStart(2, '0');
                    timeInput.min = hours + ':' + minutes;
                    
                    // Show info message
                    showTemporaryMessage('For today\'s date, please select a time at least 1 hour from now.', 'info');
                } else {
                    timeInput.removeAttribute('min');
                }
            });

            // Form submission with loading state
            form.addEventListener('submit', function(e) {
                const date = dateInput.value;
                const time = timeInput.value;
                
                if (!date || !time) {
                    e.preventDefault();
                    showTemporaryMessage('Please select both date and time.', 'error');
                    return;
                }

                // Check if selected datetime is in the past
                const selectedDateTime = new Date(date + 'T' + time);
                const now = new Date();
                
                if (selectedDateTime <= now) {
                    e.preventDefault();
                    showTemporaryMessage('Cannot reschedule to a past date and time.', 'error');
                    return;
                }

                // Show loading state
                submitBtn.disabled = true;
                submitBtn.textContent = 'Rescheduling...';
                form.classList.add('loading');
            });

            // Auto-populate current date as minimum
            if (!dateInput.value) {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                dateInput.value = tomorrow.toISOString().split('T')[0];
                dateInput.dispatchEvent(new Event('change'));
            }
        });

        function showTemporaryMessage(message, type) {
            // Remove existing temporary messages
            const existing = document.querySelectorAll('.temp-alert');
            existing.forEach(el => el.remove());

            const alert = document.createElement('div');
            alert.className = `alert alert-${type === 'error' ? 'error' : 'success'} temp-alert`;
            alert.textContent = message;
            alert.style.marginBottom = '1rem';
            
            const container = document.querySelector('.container');
            container.insertBefore(alert, container.firstElementChild.nextElementSibling);
            
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>