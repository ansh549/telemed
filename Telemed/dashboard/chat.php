<?php
if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
}

require_login();

$current_user = current_user();
$user_id = $current_user['id'];
$user_role = isset($current_user['role']) ? $current_user['role'] : (isset($_SESSION['role']) ? $_SESSION['role'] : '');
$is_doctor_user = ($user_role === 'doctor');

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : (isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0);

if ($appointment_id <= 0) {
    die("No appointment specified.");
}

// Ensure database tables exist in SQLite
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            sender_type TEXT NOT NULL CHECK(sender_type IN ('doctor','patient')),
            message TEXT NOT NULL,
            message_type TEXT DEFAULT 'text',
            file_path TEXT,
            is_read INTEGER DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS prescriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            doctor_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            medication TEXT NOT NULL,
            dosage TEXT,
            frequency TEXT,
            duration TEXT,
            instructions TEXT,
            diagnosis TEXT,
            follow_up_date DATETIME,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (Throwable $e) {}

// Fetch appointment details joined with doctor and patient profiles
$stmt = $db->prepare("
    SELECT a.*, 
           d.id as doctor_id, d.name as doctor_name, d.specialization,
           p.id as patient_id, p.name as patient_name, p.age, p.gender, p.contact, p.blood_type, p.allergies, p.medical_history
    FROM appointments a 
    JOIN doctors d ON d.id = a.doctor_id 
    JOIN patients p ON p.id = a.patient_id 
    WHERE a.id = ? AND (d.user_id = ? OR p.user_id = ?)
");
$stmt->execute([$appointment_id, $user_id, $user_id]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appointment) {
    die("Appointment not found or unauthorized access.");
}

// -------------------------------------------------------------
// POST CONTROLLERS (MESSAGES, PRESCRIPTION, COMPLETION)
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 1. COMPLETE CONSULTATION ACTION
    if (isset($_POST['action']) && $_POST['action'] === 'complete_consultation') {
        $stmt = $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?");
        $stmt->execute([$appointment_id]);

        $notice = "🔔 Consultation has been concluded by Dr. " . ($appointment['doctor_name'] ?? '');
        $stmt = $db->prepare("INSERT INTO chat_messages (appointment_id, sender_id, sender_type, message, message_type) VALUES (?, ?, 'doctor', ?, 'text')");
        $stmt->execute([$appointment_id, $user_id, $notice]);

        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        header("Location: chat.php?appointment_id=" . $appointment_id);
        exit;
    }

    // 2. SAVE DIGITAL PRESCRIPTION & CLINICAL CHARTING
    if (isset($_POST['action']) && $_POST['action'] === 'save_prescription') {
        $diagnosis      = trim($_POST['diagnosis'] ?? '');
        $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
        $mark_completed = isset($_POST['mark_completed']) ? (int)$_POST['mark_completed'] : 0;
        $instructions   = trim($_POST['instructions'] ?? '');

        $med_names   = $_POST['med_name'] ?? [];
        $dosages     = $_POST['dosage'] ?? [];
        $frequencies = $_POST['frequency'] ?? [];
        $durations   = $_POST['duration'] ?? [];

        if (!empty($diagnosis) && !empty($med_names)) {
            $db->beginTransaction();
            try {
                // Delete previous prescriptions for this appointment
                $stmt = $db->prepare("DELETE FROM prescriptions WHERE appointment_id = ?");
                $stmt->execute([$appointment_id]);

                $stmt = $db->prepare("
                    INSERT INTO prescriptions 
                    (appointment_id, doctor_id, patient_id, medication, dosage, frequency, duration, instructions, diagnosis, follow_up_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $med_summary = [];
                foreach ($med_names as $i => $m_name) {
                    $m_name = trim($m_name);
                    if ($m_name === '') continue;
                    $dose = trim($dosages[$i] ?? '');
                    $freq = trim($frequencies[$i] ?? '');
                    $dur  = trim($durations[$i] ?? '');

                    $stmt->execute([
                        $appointment_id,
                        $appointment['doctor_id'],
                        $appointment['patient_id'],
                        $m_name,
                        $dose,
                        $freq,
                        $dur,
                        $instructions,
                        $diagnosis,
                        $follow_up_date
                    ]);
                    $med_summary[] = "$m_name ($dose, $freq)";
                }

                if ($mark_completed === 1) {
                    $stmt = $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$appointment_id]);
                }

                // Add digital prescription notification to chat stream
                $rx_chat_msg = "💊 Digital Prescription Issued:\nDiagnosis: " . $diagnosis . "\nMedications: " . implode(', ', $med_summary);
                if (!empty($instructions)) {
                    $rx_chat_msg .= "\nClinical Advice: " . $instructions;
                }
                $stmt = $db->prepare("INSERT INTO chat_messages (appointment_id, sender_id, sender_type, message, message_type) VALUES (?, ?, 'doctor', ?, 'prescription')");
                $stmt->execute([$appointment_id, $user_id, $rx_chat_msg]);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
            }
        }
        header("Location: chat.php?appointment_id=" . $appointment_id);
        exit;
    }

    // 3. SEND CHAT MESSAGE
    if (isset($_POST['message'])) {
        $message = trim($_POST['message']);
        if (!empty($message)) {
            $sender_type = $is_doctor_user ? 'doctor' : 'patient';
            
            $stmt = $db->prepare("INSERT INTO chat_messages (appointment_id, sender_id, sender_type, message, message_type) VALUES (?, ?, ?, ?, 'text')");
            $stmt->execute([$appointment_id, $user_id, $sender_type, $message]);
            
            // Advance status from confirmed to in-progress on first message
            if ($appointment['status'] === 'confirmed') {
                $stmt = $db->prepare("UPDATE appointments SET status = 'in-progress' WHERE id = ?");
                $stmt->execute([$appointment_id]);
            }
            
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            header("Location: chat.php?appointment_id=" . $appointment_id);
            exit;
        }
    }
}

// -------------------------------------------------------------
// AJAX MESSAGES POLLING
// -------------------------------------------------------------
if (isset($_GET['fetch_messages'])) {
    header('Content-Type: application/json');
    $stmt = $db->prepare("SELECT * FROM chat_messages WHERE appointment_id = ? ORDER BY sent_at ASC");
    $stmt->execute([$appointment_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'messages' => $messages, 'current_user_id' => $user_id]);
    exit;
}

// Fetch initial messages for server rendering
$stmt = $db->prepare("SELECT * FROM chat_messages WHERE appointment_id = ? ORDER BY sent_at ASC");
$stmt->execute([$appointment_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Chat - Telemed</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #e2e8f0; height: 100vh; display: flex; flex-direction: column; }
        
        .chat-top-header {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: white; padding: 0.85rem 1.5rem; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .chat-top-header h2 { font-size: 1.25rem; display: flex; align-items: center; gap: 0.4rem; }
        .chat-top-header p { font-size: 0.82rem; opacity: 0.95; margin-top: 0.15rem; }
        .back-btn { color: white; text-decoration: none; padding: 0.4rem 0.85rem; background: rgba(255,255,255,0.2); border-radius: 6px; font-size: 0.85rem; font-weight: 500; }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .status-banner {
            background: #f0fdf4; border-bottom: 1px solid #bbf7d0; text-align: center; padding: 0.55rem;
            font-size: 0.88rem; font-weight: 600; color: #166534; display: flex; justify-content: center; align-items: center; gap: 0.4rem;
        }
        .status-banner.completed { background: #f1f5f9; border-bottom-color: #cbd5e1; color: #475569; }

        .chat-container {
            flex: 1; max-width: 1000px; width: 100%; margin: 0 auto; background: white; display: flex; flex-direction: column;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06); height: calc(100vh - 120px);
        }
        
        .messages-box {
            flex: 1; padding: 1.25rem 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.85rem;
        }
        
        .message-bubble {
            max-width: 70%; padding: 0.75rem 1rem; border-radius: 14px; font-size: 0.92rem; line-height: 1.4;
            word-wrap: break-word; white-space: pre-line;
        }
        .message-bubble.you {
            align-self: flex-end; background: #0284c7; color: white; border-bottom-right-radius: 3px;
        }
        .message-bubble.other {
            align-self: flex-start; background: #f1f5f9; color: #1e293b; border-bottom-left-radius: 3px; border: 1px solid #e2e8f0;
        }
        .msg-time { font-size: 0.7rem; opacity: 0.75; margin-top: 0.25rem; text-align: right; }

        /* YELLOW DOCTOR ACTION BAR MATCHING SCREENSHOT */
        .doctor-action-bar {
            background: #fef9c3; border-top: 1px solid #fde047; padding: 0.65rem 1rem;
            display: flex; justify-content: center; gap: 1rem; align-items: center; flex-wrap: wrap;
        }
        .act-btn {
            border: none; padding: 0.55rem 1.2rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;
            cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: transform 0.15s, opacity 0.2s;
        }
        .act-btn:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-rx { background: #059669; color: white; }
        .btn-complete { background: #4b5563; color: white; }
        
        .input-bar {
            background: white; border-top: 1px solid #e2e8f0; padding: 0.75rem 1rem; display: flex; gap: 0.75rem; align-items: center;
        }
        .input-bar input {
            flex: 1; padding: 0.75rem 1.1rem; border: 2px solid #e2e8f0; border-radius: 25px; font-size: 0.95rem; outline: none;
        }
        .input-bar input:focus { border-color: #0284c7; }
        .send-btn {
            background: #0284c7; color: white; border: none; padding: 0.75rem 1.6rem; border-radius: 25px;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
        }

        /* -------------------------------------------------------------
           SPLIT-SCREEN CLINICAL CHARTING MODAL
        ------------------------------------------------------------- */
        .modal-bg {
            display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);
        }
        .modal-box {
            background-color: #f8fafc; margin: 2vh auto; border-radius: 14px; width: 95%; max-width: 1150px;
            height: 94vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: linear-gradient(135deg, #0f766e 0%, #047857 100%);
            color: white; padding: 0.9rem 1.4rem; display: flex; justify-content: space-between; align-items: center;
        }
        .modal-header h3 { font-size: 1.15rem; font-weight: 600; }
        .modal-close { font-size: 1.6rem; cursor: pointer; line-height: 1; font-weight: 700; }

        .split-body { display: grid; grid-template-columns: 1fr 1.35fr; flex: 1; overflow: hidden; }

        .left-patient-pane { background: white; border-right: 2px solid #e2e8f0; padding: 1.25rem; overflow-y: auto; }
        .section-tag { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 0.5rem; }
        .patient-card-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem; margin-bottom: 1rem; }
        .p-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.5rem 0.75rem; }
        .p-stat small { color: #64748b; font-size: 0.72rem; display: block; font-weight: 600; }
        .p-stat span { font-weight: 700; color: #1e293b; font-size: 0.9rem; }

        .allergy-alert {
            background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #ef4444; color: #991b1b;
            padding: 0.65rem 0.85rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem;
        }

        .right-rx-pane { padding: 1.25rem; overflow-y: auto; background: #f8fafc; }
        .form-row { margin-bottom: 0.9rem; }
        .form-row label { display: block; font-size: 0.82rem; font-weight: 600; color: #334155; margin-bottom: 0.3rem; }
        .form-row input, .form-row textarea { width: 100%; padding: 0.6rem 0.8rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; }

        .med-row { display: grid; grid-template-columns: 1.8fr 1fr 1fr 1fr 0.3fr; gap: 0.4rem; margin-bottom: 0.4rem; align-items: center; }
        .med-row input { padding: 0.45rem; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.82rem; }
        .del-med-btn { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 4px; padding: 0.45rem; cursor: pointer; font-weight: 700; }

        @media (max-width: 800px) {
            .split-body { grid-template-columns: 1fr; }
            .left-patient-pane { max-height: 220px; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="chat-top-header">
        <div>
            <h2>💬 Consultation Chat</h2>
            <p><?= htmlspecialchars($appointment['patient_name'] ?? 'Patient') ?> &bull; <?= date('M d, Y g:i A', strtotime($appointment['appointment_date'])) ?></p>
        </div>
        <a href="<?= $is_doctor_user ? 'doctor.php' : 'patient.php' ?>" class="back-btn">&larr; Back to Dashboard</a>
    </div>

    <!-- Status Banner -->
    <div class="status-banner <?= $appointment['status'] === 'completed' ? 'completed' : '' ?>" id="statusBanner">
        <?php if ($appointment['status'] === 'completed'): ?>
            <span>✅ Consultation Completed</span>
        <?php elseif ($appointment['status'] === 'confirmed'): ?>
            <span>🟡 Consultation confirmed - Start chatting</span>
        <?php else: ?>
            <span>🟢 Consultation in progress</span>
        <?php endif; ?>
    </div>

    <div class="chat-container">
        <!-- Messages Box -->
        <div class="messages-box" id="messagesBox">
            <?php if (empty($messages)): ?>
                <div style="text-align: center; color: #94a3b8; font-style: italic; padding: 3rem;">
                    👋 Start your consultation by sending a message
                </div>
            <?php else: ?>
                <?php foreach ($messages as$msg): ?>
                    <div class="message-bubble <?= ($msg['sender_id'] ==$user_id) ? 'you' : 'other' ?>">
                        <div><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                        <div class="msg-time"><?= date('g:i A', strtotime($msg['sent_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- DOCTOR ACTION BAR (WORKING BUTTONS FROM SCREENSHOT) -->
        <?php if ($is_doctor_user): ?>
            <div class="doctor-action-bar">
                <button type="button" class="act-btn btn-rx" onclick="openRxModal()">
                    💊 Create Prescription
                </button>

                <?php if ($appointment['status'] !== 'completed'): ?>
                    <button type="button" class="act-btn btn-complete" onclick="triggerCompleteConsultation()">
                        ✅ Complete Consultation
                    </button>
                <?php else: ?>
                    <span style="font-size:0.85rem; font-weight:700; color:#15803d; padding:0.45rem 0.8rem; background:#dcfce7; border-radius:6px;">
                        ✓ Consultation Completed
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Message Input -->
        <?php if ($appointment['status'] !== 'cancelled'): ?>
            <form class="input-bar" id="chatSendForm" onsubmit="handleSendMessage(event)">
                <input type="text" id="chatMessageInput" placeholder="Type your message..." autocomplete="off" required>
                <button type="submit" class="send-btn">Send</button>
            </form>
        <?php else: ?>
            <div style="padding: 1rem; text-align: center; color: #64748b; background: #f8fafc;">
                This consultation is cancelled.
            </div>
        <?php endif; ?>
    </div>

    <!-- -------------------------------------------------------------
         SPLIT-SCREEN CLINICAL CHARTING MODAL (POPUP)
    -------------------------------------------------------------- -->
    <div id="rxModal" class="modal-bg">
        <div class="modal-box">
            <div class="modal-header">
                <h3>🩺 Clinical Charting & Digital Prescription Pad</h3>
                <span class="modal-close" onclick="closeRxModal()">&times;</span>
            </div>
            <div class="split-body">
                <!-- Left Pane: Patient Clinical History -->
                <div class="left-patient-pane">
                    <div class="section-tag">Patient Demographics</div>
                    <div class="patient-card-grid">
                        <div class="p-stat">
                            <small>Patient Name</small>
                            <span><?= htmlspecialchars($appointment['patient_name'] ?? 'N/A') ?></span>
                        </div>
                        <div class="p-stat">
                            <small>Age / Gender</small>
                            <span><?= ($appointment['age'] ? $appointment['age'] . ' Yrs' : 'N/A') . ' / ' . ucfirst($appointment['gender'] ?? 'N/A') ?></span>
                        </div>
                        <div class="p-stat">
                            <small>Blood Group</small>
                            <span><?= htmlspecialchars($appointment['blood_type'] ?? 'Unknown') ?></span>
                        </div>
                        <div class="p-stat">
                            <small>Contact Phone</small>
                            <span><?= htmlspecialchars($appointment['contact'] ?? 'N/A') ?></span>
                        </div>
                    </div>

                    <div class="section-tag">Allergies & Critical Warnings</div>
                    <div class="allergy-alert">
                        <strong>⚠️ Allergies:</strong>
                        <div><?= htmlspecialchars(!empty($appointment['allergies']) ?$appointment['allergies'] : 'No known drug allergies reported.') ?></div>
                    </div>

                    <div class="section-tag">Reported Symptoms / Complaints</div>
                    <div style="background:#f1f5f9; padding:0.65rem; border-radius:6px; font-size:0.85rem; margin-bottom:1rem;">
                        <?= htmlspecialchars(!empty($appointment['symptoms']) ?$appointment['symptoms'] : 'General consultation requested.') ?>
                    </div>

                    <div class="section-tag">Medical History</div>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:0.65rem; border-radius:6px; font-size:0.85rem;">
                        <?= htmlspecialchars(!empty($appointment['medical_history']) ?$appointment['medical_history'] : 'No chronic conditions on record.') ?>
                    </div>
                </div>

                <!-- Right Pane: Prescription Builder -->
                <div class="right-rx-pane">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="save_prescription">

                        <div class="form-row">
                            <label>Clinical Diagnosis / Assessment *</label>
                            <input type="text" name="diagnosis" placeholder="e.g. Acute Viral Bronchitis" required>
                        </div>

                        <div class="form-row">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                                <label style="margin:0;">Prescribed Medications *</label>
                                <button type="button" onclick="addMedRow()" style="background:#0284c7; color:white; border:none; padding:0.25rem 0.5rem; border-radius:4px; font-size:0.75rem; cursor:pointer;">
                                    + Add Medicine
                                </button>
                            </div>
                            <div id="medListContainer">
                                <div class="med-row">
                                    <input type="text" name="med_name[]" placeholder="Drug Name (e.g. Amoxicillin)" required>
                                    <input type="text" name="dosage[]" placeholder="Dosage (500mg)">
                                    <input type="text" name="frequency[]" placeholder="Freq (1-0-1)">
                                    <input type="text" name="duration[]" placeholder="Duration (5 Days)">
                                    <button type="button" class="del-med-btn" onclick="removeMed(this)">&times;</button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label>Physician Advice & Dietary Instructions</label>
                            <textarea name="instructions" rows="2" placeholder="e.g. Drink warm fluids, avoid cold water, take after meals."></textarea>
                        </div>

                        <div class="form-row" style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem;">
                            <div>
                                <label>Follow-up Date</label>
                                <input type="date" name="follow_up_date" min="<?= date('Y-m-d') ?>">
                            </div>
                            <div style="display:flex; align-items:center; margin-top:1.2rem;">
                                <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-weight:600; color:#0f766e;">
                                    <input type="checkbox" name="mark_completed" value="1" checked style="width:16px; height:16px;">
                                    <span>Complete Consultation & Sign</span>
                                </label>
                            </div>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1rem;">
                            <button type="button" class="act-btn" style="background:#64748b; color:white;" onclick="closeRxModal()">Cancel</button>
                            <button type="submit" class="act-btn btn-rx" style="padding:0.65rem 1.4rem;">💾 Sign & Send Prescription</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const appointmentId = <?= $appointment['id'] ?>;
        const currentUserId = <?= $user_id ?>;
        const messagesBox = document.getElementById('messagesBox');

        function scrollToBottom() {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }

        function openRxModal() {
            document.getElementById('rxModal').style.display = 'block';
        }
        function closeRxModal() {
            document.getElementById('rxModal').style.display = 'none';
        }

        function addMedRow() {
            const container = document.getElementById('medListContainer');
            const div = document.createElement('div');
            div.className = 'med-row';
            div.innerHTML = `
                <input type="text" name="med_name[]" placeholder="Drug Name (e.g. Paracetamol)" required>
                <input type="text" name="dosage[]" placeholder="Dosage (500mg)">
                <input type="text" name="frequency[]" placeholder="Freq (1-0-1)">
                <input type="text" name="duration[]" placeholder="Duration (5 Days)">
                <button type="button" class="del-med-btn" onclick="removeMed(this)">&times;</button>
            `;
            container.appendChild(div);
        }

        function removeMed(btn) {
            const rows = document.querySelectorAll('.med-row');
            if (rows.length > 1) {
                btn.closest('.med-row').remove();
            } else {
                alert('At least one medication is required.');
            }
        }

        // Live Chat Send (AJAX)
        function handleSendMessage(e) {
            e.preventDefault();
            const input = document.getElementById('chatMessageInput');
            const msg = input.value.trim();
            if (!msg) return;

            const fd = new FormData();
            fd.append('message', msg);
            fd.append('ajax', '1');

            input.value = '';

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.success) fetchMessages();
                })
                .catch(() => {});
        }

        // Live Polling
        function fetchMessages() {
            fetch(`?fetch_messages=1&appointment_id=${appointmentId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.messages) return;

                    let html = '';
                    data.messages.forEach(m => {
                        const isYou = (parseInt(m.sender_id) === currentUserId);
                        const bubbleClass = isYou ? 'you' : 'other';
                        const time = new Date(m.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                        html += `
                            <div class="message-bubble ${bubbleClass}">
                                <div>${escapeHtml(m.message)}</div>
                                <div class="msg-time">${time}</div>
                            </div>
                        `;
                    });

                    if (messagesBox.innerHTML !== html) {
                        messagesBox.innerHTML = html;
                        scrollToBottom();
                    }
                })
                .catch(() => {});
        }

        // Complete Consultation
        function triggerCompleteConsultation() {
            if (confirm('Are you sure you want to mark this consultation as completed?')) {
                const fd = new FormData();
                fd.append('action', 'complete_consultation');
                fd.append('ajax', '1');

                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            window.location.reload();
                        }
                    })
                    .catch(() => {
                        window.location.reload();
                    });
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Initial setup
        scrollToBottom();
        setInterval(fetchMessages, 2500);

        window.onclick = function(e) {
            const modal = document.getElementById('rxModal');
            if (e.target === modal) closeRxModal();
        }
    </script>
</body>
</html>