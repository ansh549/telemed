<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
if (!is_doctor()) {
    header('Location: ../login.php');
    exit;
}

$current_user = current_user();
$doctor_user_id = $current_user['id'];

// Get doctor's ID
$stmt = $db->prepare("SELECT id FROM doctors WHERE user_id = ?");
$stmt->execute([$doctor_user_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die("Doctor profile not found. Please contact administrator.");
}

$doctor_id = $doctor['id'];

try {
    // Get active consultations (confirmed or in-progress)
    $stmt = $db->prepare("
        SELECT a.*, p.name as patient_name, p.age, p.gender, p.contact,
               CASE 
                   WHEN a.status = 'in-progress' THEN 1
                   WHEN a.status = 'confirmed' AND DATE(a.appointment_date) = DATE('now') THEN 2
                   ELSE 3
               END as priority
        FROM appointments a 
        JOIN patients p ON p.id = a.patient_id 
        WHERE a.doctor_id = ? 
        AND a.status IN ('confirmed', 'in-progress')
        AND DATE(a.appointment_date) >= DATE('now', '-1 days')
        ORDER BY priority ASC, a.appointment_date ASC
    ");
    $stmt->execute([$doctor_id]);
    $active_consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent completed consultations
    $stmt = $db->prepare("
        SELECT a.*, p.name as patient_name, p.age, p.gender, p.contact
        FROM appointments a 
        JOIN patients p ON p.id = a.patient_id 
        WHERE a.doctor_id = ? 
        AND a.status = 'completed'
        ORDER BY a.appointment_date DESC
        LIMIT 10
    ");
    $stmt->execute([$doctor_id]);
    $completed_consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get consultation statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'in-progress' THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'confirmed' AND DATE(appointment_date) = DATE('now') THEN 1 END) as todays_count,
            COUNT(CASE WHEN status = 'completed' AND DATE(appointment_date) = DATE('now') THEN 1 END) as completed_today,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as total_completed
        FROM appointments 
        WHERE doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get recent chat messages for active consultations
    $consultation_messages = [];
    if (!empty($active_consultations)) {
        $appointment_ids = array_column($active_consultations, 'id');
        $placeholders = str_repeat('?,', count($appointment_ids) - 1) . '?';
        
        $stmt = $db->prepare("
            SELECT cm.*, u.username, a.id as appointment_id
            FROM chat_messages cm
            JOIN users u ON u.id = cm.sender_id
            JOIN appointments a ON a.id = cm.appointment_id
            WHERE cm.appointment_id IN ($placeholders)
            AND cm.sent_at >= datetime('now', '-1 hours')
            ORDER BY cm.sent_at DESC
        ");
        $stmt->execute($appointment_ids);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group messages by appointment
        foreach ($messages as $msg) {
            $consultation_messages[$msg['appointment_id']][] = $msg;
        }
    }

} catch (Exception $e) {
    $error = "Error loading consultations: " . $e->getMessage();
    $active_consultations = [];
    $completed_consultations = [];
    $stats = ['active_count' => 0, 'todays_count' => 0, 'completed_today' => 0, 'total_completed' => 0];
}

function safe_display($value, $default = '') {
    return htmlspecialchars($value ?? $default, ENT_QUOTES, 'UTF-8');
}

function format_datetime($datetime) {
    return date('M d, Y - g:i A', strtotime($datetime));
}

function time_ago($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    return date('M d, g:i A', $time);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultations - Telemed</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; line-height: 1.6; }
        
        .navbar {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            padding: 1rem 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar h1 { font-size: 1.5rem; font-weight: 600; }
        .back-btn { color: white; text-decoration: none; padding: 0.5rem 1rem; background: rgba(255,255,255,0.2); border-radius: 6px; transition: all 0.3s; }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 1rem; }
        
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .page-header h2 {
            color: #1f2937;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: #6b7280;
            font-size: 1.1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-align: center;
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card.active { border-left-color: #ef4444; }
        .stat-card.today { border-left-color: #0ea5e9; }
        .stat-card.completed-today { border-left-color: #10b981; }
        .stat-card.total { border-left-color: #8b5cf6; }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            padding: 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-content { padding: 2rem 1.5rem; }
        
        .consultation-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .consultation-item {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #0ea5e9;
            position: relative;
            transition: all 0.2s;
        }
        
        .consultation-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .consultation-item.in-progress {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        
        .consultation-item.confirmed {
            border-left-color: #10b981;
            background: #f0fdf4;
        }
        
        .consultation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .consultation-patient {
            font-weight: 600;
            color: #1f2937;
            font-size: 1.1rem;
        }
        
        .consultation-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-in-progress {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .consultation-details {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
        }
        
        .consultation-time {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .consultation-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #0ea5e9;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }
        
        .recent-messages {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            max-height: 120px;
            overflow-y: auto;
        }
        
        .message-item {
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background: white;
            border-radius: 4px;
        }
        
        .message-sender {
            font-weight: 500;
            color: #374151;
        }
        
        .message-time {
            font-size: 0.75rem;
            color: #9ca3af;
            float: right;
        }
        
        .message-content {
            color: #1f2937;
            margin-top: 0.25rem;
        }
        
        .empty-state {
            text-align: center;
            color: #6b7280;
            font-style: italic;
            padding: 3rem;
            background: #f9fafb;
            border-radius: 8px;
            border: 2px dashed #d1d5db;
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .main-grid { grid-template-columns: 1fr; }
            .navbar { padding: 1rem; }
            .container { padding: 0 0.5rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .consultation-actions { justify-content: center; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>💬 Consultations</h1>
        <a href="doctor.php" class="back-btn">← Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="page-header">
            <h2>💬 Active Consultations</h2>
            <p>Manage your ongoing and scheduled consultations</p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;">
                <?= safe_display($error) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card active">
                <div class="stat-number <?= $stats['active_count'] > 0 ? 'pulse' : '' ?>"><?= $stats['active_count'] ?></div>
                <div class="stat-label">Active Now</div>
            </div>
            <div class="stat-card today">
                <div class="stat-number"><?= $stats['todays_count'] ?></div>
                <div class="stat-label">Today's Scheduled</div>
            </div>
            <div class="stat-card completed-today">
                <div class="stat-number"><?= $stats['completed_today'] ?></div>
                <div class="stat-label">Completed Today</div>
            </div>
            <div class="stat-card total">
                <div class="stat-number"><?= $stats['total_completed'] ?></div>
                <div class="stat-label">Total Completed</div>
            </div>
        </div>

        <div class="main-grid">
            <!-- Active Consultations -->
            <div class="card">
                <div class="card-header">
                    <span>🔴 Active & Scheduled Consultations</span>
                    <span><?= count($active_consultations) ?> active</span>
                </div>
                <div class="card-content">
                    <?php if (empty($active_consultations)): ?>
                        <div class="empty-state">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">💬</div>
                            <div>No active consultations</div>
                            <p style="margin-top: 0.5rem; font-size: 0.9rem;">Consultations will appear here when patients join</p>
                        </div>
                    <?php else: ?>
                        <div class="consultation-list">
                            <?php foreach ($active_consultations as $consultation): ?>
                                <div class="consultation-item <?= $consultation['status'] ?>">
                                    <div class="consultation-header">
                                        <div class="consultation-patient"><?= safe_display($consultation['patient_name']) ?></div>
                                        <div class="consultation-status status-<?= $consultation['status'] ?>">
                                            <?= $consultation['status'] === 'in-progress' ? '🔴 Live' : '🟢 Ready' ?>
                                        </div>
                                    </div>
                                    
                                    <div class="consultation-time">
                                        📅 <?= format_datetime($consultation['appointment_date']) ?>
                                    </div>
                                    
                                    <div class="consultation-details">
                                        👤 <?= $consultation['age'] ? $consultation['age'] . ' years' : 'Age not specified' ?> 
                                        <?= $consultation['gender'] ? '• ' . ucfirst($consultation['gender']) : '' ?>
                                        <?= $consultation['contact'] ? '• ' . $consultation['contact'] : '' ?>
                                    </div>
                                    
                                    <div class="consultation-details">
                                        🔄 Type: <?= $consultation['appointment_type'] === 'video' ? '📹 Video Call' : '💬 Text Chat' ?>
                                    </div>
                                    
                                    <?php if ($consultation['symptoms']): ?>
                                        <div class="consultation-details" style="margin-top: 0.5rem; font-weight: 500;">
                                            🩺 Symptoms: <?= safe_display($consultation['symptoms']) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Recent Messages -->
                                    <?php if (isset($consultation_messages[$consultation['id']]) && !empty($consultation_messages[$consultation['id']])): ?>
                                        <div class="recent-messages">
                                            <div style="font-weight: 500; margin-bottom: 0.5rem; font-size: 0.85rem; color: #374151;">Recent Messages:</div>
                                            <?php foreach (array_slice($consultation_messages[$consultation['id']], 0, 2) as $msg): ?>
                                                <div class="message-item">
                                                    <span class="message-sender"><?= safe_display($msg['username']) ?></span>
                                                    <span class="message-time"><?= time_ago($msg['sent_at']) ?></span>
                                                    <div class="message-content"><?= safe_display(substr($msg['message'], 0, 100)) ?><?= strlen($msg['message']) > 100 ? '...' : '' ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="consultation-actions">
                                        <a href="chat.php?appointment_id=<?= $consultation['id'] ?>" class="btn btn-primary">
                                            💬 <?= $consultation['status'] === 'in-progress' ? 'Continue Chat' : 'Start Chat' ?>
                                        </a>
                                        <a href="video-consultation.php?appointment_id=<?= $consultation['id'] ?>" class="btn btn-success">
                                            📹 <?= $consultation['status'] === 'in-progress' ? 'Join Video' : 'Start Video' ?>
                                        </a>
                                        <?php if ($consultation['status'] === 'in-progress'): ?>
                                            <a href="create-prescription.php?appointment_id=<?= $consultation['id'] ?>" class="btn btn-warning">💊 Prescription</a>
                                            <a href="complete-consultation.php?appointment_id=<?= $consultation['id'] ?>" class="btn btn-secondary">✅ Complete</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Completed -->
            <div class="card">
                <div class="card-header">
                    <span>✅ Recently Completed</span>
                    <span><?= count($completed_consultations) ?> recent</span>
                </div>
                <div class="card-content">
                    <?php if (empty($completed_consultations)): ?>
                        <div class="empty-state">
                            <div style="font-size: 2rem; margin-bottom: 1rem;">✅</div>
                            <div>No completed consultations yet</div>
                        </div>
                    <?php else: ?>
                        <div class="consultation-list">
                            <?php foreach (array_slice($completed_consultations, 0, 5) as $consultation): ?>
                                <div class="consultation-item">
                                    <div class="consultation-header">
                                        <div class="consultation-patient"><?= safe_display($consultation['patient_name']) ?></div>
                                        <div style="font-size: 0.8rem; color: #10b981; font-weight: 500;">✅ Completed</div>
                                    </div>
                                    
                                    <div class="consultation-time">
                                        📅 <?= format_datetime($consultation['appointment_date']) ?>
                                    </div>
                                    
                                    <div class="consultation-details">
                                        👤 <?= $consultation['age'] ? $consultation['age'] . ' years' : 'Age not specified' ?> 
                                        <?= $consultation['gender'] ? '• ' . ucfirst($consultation['gender']) : '' ?>
                                    </div>
                                    
                                    <div class="consultation-details">
                                        🔄 Type: <?= $consultation['appointment_type'] === 'video' ? '📹 Video Call' : '💬 Text Chat' ?>
                                    </div>
                                    
                                    <div class="consultation-actions" style="margin-top: 0.75rem;">
                                        <a href="view-consultation.php?id=<?= $consultation['id'] ?>" class="btn btn-secondary">📋 View Details</a>
                                        <a href="patient-history.php?id=<?= $consultation['patient_id'] ?>" class="btn btn-primary">👤 Patient History</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($completed_consultations) > 5): ?>
                            <div style="text-align: center; margin-top: 1rem;">
                                <a href="consultation-history.php" class="btn btn-secondary">View All History</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <span>🚀 Quick Actions</span>
            </div>
            <div class="card-content">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <a href="doctor.php" class="btn btn-primary" style="padding: 1rem; text-align: center;">
                        📊 Back to Dashboard
                    </a>
                    <a href="prescriptions.php" class="btn btn-warning" style="padding: 1rem; text-align: center;">
                        💊 Manage Prescriptions
                    </a>
                    <a href="schedule.php" class="btn btn-success" style="padding: 1rem; text-align: center;">
                        📅 View Schedule
                    </a>
                    <a href="patient-records.php" class="btn btn-secondary" style="padding: 1rem; text-align: center;">
                        📋 Patient Records
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds to keep consultation status updated
        let autoRefresh = setInterval(() => {
            // Only refresh if user is not actively interacting
            if (!document.hidden && !isUserActive()) {
                window.location.reload();
            }
        }, 30000);

        let lastActivity = Date.now();
        
        function isUserActive() {
            return (Date.now() - lastActivity) < 5000; // 5 seconds
        }

        // Track user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, () => {
                lastActivity = Date.now();
            }, true);
        });

        // Show notification for new active consultations
        <?php if ($stats['active_count'] > 0): ?>
        if ('Notification' in window && Notification.permission === 'granted') {
            const activeCount = <?= $stats['active_count'] ?>;
            if (activeCount === 1) {
                new Notification('Telemed Consultation', {
                    body: 'You have 1 active consultation waiting',
                    icon: '/favicon.ico',
                    tag: 'consultation'
                });
            } else if (activeCount > 1) {
                new Notification('Telemed Consultation', {
                    body: `You have ${activeCount} active consultations`,
                    icon: '/favicon.ico',
                    tag: 'consultation'
                });
            }
        }
        <?php endif; ?>

        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // Update page title with active consultation count
        const activeCount = <?= $stats['active_count'] ?>;
        if (activeCount > 0) {
            document.title = `(${activeCount}) Active Consultations - Telemed`;
        }

        // Play subtle notification sound for new messages (if available)
        function playNotificationSound() {
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhAS2A0fPVeikHKYHN8tiJOAcZZ73u55xMEQ1Mp+LwtmEcBjiOzPLNeSsFJHfH8N+TPHo');
                audio.volume = 0.1;
                audio.play().catch(() => {}); // Ignore errors if audio can't play
            } catch (e) {
                // Silent fail
            }
        }

        // Check for new messages periodically
        let lastMessageCheck = Date.now();
        setInterval(() => {
            // In a real implementation, you would check for new messages via AJAX
            // For now, we'll just refresh if there are active consultations
            <?php if (!empty($active_consultations)): ?>
            if (Date.now() - lastMessageCheck > 60000) { // Every minute
                lastMessageCheck = Date.now();
                // playNotificationSound();
            }
            <?php endif; ?>
        }, 10000);

        // Handle page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(autoRefresh);
            } else {
                // Restart auto-refresh when page becomes visible
                autoRefresh = setInterval(() => {
                    if (!isUserActive()) {
                        window.location.reload();
                    }
                }, 30000);
            }
        });
    </script>
</body>
</html>