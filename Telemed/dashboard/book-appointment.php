<?php
// Set timezone to India Standard Time for accurate local time-slot validation
date_default_timezone_set('Asia/Kolkata');

// Dynamic authentication bootstrap
if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
}

require_login();
if (!is_patient()) {
    header('Location: login.php');
    exit;
}

$current_user = current_user();
$patient_user_id = $current_user['id'];

// ----------------------------------------------------------------------------------
// AUTO-MIGRATION: Update SQLite constraint to allow 'both' (Both Video & Chat)
// ----------------------------------------------------------------------------------
try {
    $table_sql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='appointments'")->fetchColumn();
    if ($table_sql && stripos($table_sql, "'both'") === false && stripos($table_sql, "CHECK") !== false) {
        $db->exec("PRAGMA foreign_keys = OFF;");
        
        $col_info = $db->query("PRAGMA table_info(appointments)")->fetchAll(PDO::FETCH_ASSOC);
        $col_names = array_map(function($c) { return '"' . $c['name'] . '"'; }, $col_info);
        $cols_str = implode(', ', $col_names);
        
        $db->beginTransaction();
        $db->exec("ALTER TABLE appointments RENAME TO appointments_old_table;");
        
        $new_table_sql = preg_replace(
            "/CHECK\s*\(\s*appointment_type\s+IN\s*\([^)]+\)\s*\)/i",
            "CHECK(appointment_type IN ('chat', 'video', 'in-person', 'both'))",
            $table_sql
        );
        if ($new_table_sql === $table_sql) {
            $new_table_sql = preg_replace("/CHECK\s*\([^)]*appointment_type[^)]*\)/i", "", $table_sql);
        }
        
        $new_table_sql = preg_replace('/CREATE\s+TABLE\s+["`]?appointments(_old_table)?["`]?/i', 'CREATE TABLE "appointments"', $new_table_sql);
        $db->exec($new_table_sql);
        
        $new_col_info = $db->query("PRAGMA table_info(appointments)")->fetchAll(PDO::FETCH_ASSOC);
        $new_col_names = array_column($new_col_info, 'name');
        foreach ($col_info as $c) {
            if (!in_array($c['name'], $new_col_names, true)) {
                $type = $c['type'] ?: 'TEXT';
                $dflt = $c['dflt_value'] !== null ? " DEFAULT " . $c['dflt_value'] : "";
                $db->exec("ALTER TABLE appointments ADD COLUMN \"" . $c['name'] . "\" $type $dflt;");
            }
        }
        
        $db->exec("INSERT INTO appointments ($cols_str) SELECT $cols_str FROM appointments_old_table;");
        $db->exec("DROP TABLE appointments_old_table;");
        $db->commit();
        $db->exec("PRAGMA foreign_keys = ON;");
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->exec("PRAGMA foreign_keys = ON;");
}

// Ensure patient record exists
$stmt = $db->prepare("SELECT id, name, age, gender, contact, allergies FROM patients WHERE user_id = ?");
$stmt->execute([$patient_user_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    $stmt = $db->prepare("INSERT INTO patients (user_id, name) VALUES (?, ?)");
    $stmt->execute([$patient_user_id, $current_user['username'] ?? 'Patient']);
    $new_id = $db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$new_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ----------------------------------------------------------------------------------
// REAL-TIME AJAX ENDPOINT: FETCH DYNAMIC SLOTS & AVAILABILITY
// ----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_slots'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $doctor_id = (int)($_GET['doctor_id'] ?? 0);
        $date      = trim($_GET['date'] ?? date('Y-m-d'));
        $type      = trim($_GET['type'] ?? 'both');

        if ($doctor_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a doctor.']);
            exit;
        }

        // 1. Check Doctor Profile & Master Availability
        $stmt = $db->prepare("SELECT is_available, is_offline_available, consultation_fee, clinic_address FROM doctors WHERE id = ?");
        $stmt->execute([$doctor_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            echo json_encode(['success' => false, 'message' => 'Doctor not found.']);
            exit;
        }

        // Safe status checks (default to 1 if not explicitly toggled off)
        $doc_online  = isset($doc['is_available']) ? (int)$doc['is_available'] : 1;
        $doc_offline = isset($doc['is_offline_available']) ? (int)$doc['is_offline_available'] : 1;

        if ($type === 'in-person' && $doc_offline === 0) {
            echo json_encode([
                'success' => true,
                'is_working' => false,
                'message' => 'Doctor is currently not accepting physical in-person visits.',
                'slots' => []
            ]);
            exit;
        }

        if ($type !== 'in-person' && $doc_online === 0) {
            echo json_encode([
                'success' => true,
                'is_working' => false,
                'message' => 'Doctor is currently off-duty for online telehealth consultations.',
                'slots' => []
            ]);
            exit;
        }

        // 2. Fetch Doctor's Schedule (or use standard active hours 09:00 AM - 08:00 PM)
        $day_name = strtolower(date('l', strtotime($date)));
        $stmt = $db->prepare("SELECT start_time, end_time, is_available FROM doctor_availability WHERE doctor_id = ? AND day_of_week = ?");
        $stmt->execute([$doctor_id, $day_name]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fallback to active clinic hours if no custom weekly schedule was saved yet
        $start_str = ($schedule && !empty($schedule['start_time'])) ? $schedule['start_time'] : '09:00:00';
        $end_str   = ($schedule && !empty($schedule['end_time']))   ? $schedule['end_time']   : '20:00:00';
        $is_day_off = ($schedule && isset($schedule['is_available']) && (int)$schedule['is_available'] === 0);

        if ($is_day_off) {
            echo json_encode([
                'success' => true,
                'is_working' => false,
                'message' => 'Doctor has no scheduled OPD sessions on ' . ucfirst($day_name) . 's.',
                'slots' => []
            ]);
            exit;
        }

        // 3. Query All Booked Appointments for this Doctor on Target Date
        $stmt = $db->prepare("
            SELECT appointment_date 
            FROM appointments 
            WHERE doctor_id = ? 
              AND DATE(appointment_date) = ? 
              AND status != 'cancelled'
        ");
        $stmt->execute([$doctor_id, $date]);
        $booked_rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $booked_times = [];
        foreach ($booked_rows as $b_date) {
            $booked_times[] = date('H:i', strtotime($b_date));
        }

        // 4. Generate 30-Minute Real-Time Slots
        $start_ts = strtotime("$date $start_str");
        $end_ts   = strtotime("$date $end_str");
        $current_now_ts = time();
        $is_today = ($date === date('Y-m-d'));

        $slots = [];
        $available_count = 0;
        $cursor = $start_ts;

        while ($cursor < $end_ts) {
            $time_24 = date('H:i', $cursor);
            $time_label = date('g:i A', $cursor) . ' - ' . date('g:i A', $cursor + 1800);
            
            // A slot is past only if booking for today and the slot time has already elapsed
            $is_past = ($is_today && $cursor <= $current_now_ts);
            $is_booked = in_array($time_24, $booked_times, true);
            $is_available = (!$is_past && !$is_booked);

            if ($is_available) {
                $available_count++;
            }

            $slots[] = [
                'time_24'      => $time_24,
                'label'        => $time_label,
                'is_available' => $is_available,
                'is_booked'    => $is_booked,
                'is_past'      => $is_past
            ];

            $cursor += 1800; // Increment 30 minutes
        }

        echo json_encode([
            'success'          => true,
            'is_working'       => true,
            'is_today'         => $is_today,
            'available_count'  => $available_count,
            'consultation_fee' => (float)($doc['consultation_fee'] > 0 ? $doc['consultation_fee'] : 1000.0),
            'clinic_address'   => $doc['clinic_address'] ?? 'Room 104, OPD Block A',
            'slots'            => $slots
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error calculating slots: ' . $e->getMessage()]);
        exit;
    }
}

// ----------------------------------------------------------------------------------
// POST: SUBMIT APPOINTMENT BOOKING (FAILSAFE WITH DUAL VIDEO & CHAT SUPPORT)
// ----------------------------------------------------------------------------------
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {
    try {
        $doctor_id        = (int)($_POST['doctor_id'] ?? 0);
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $time_slot        = trim($_POST['time_slot'] ?? '');
        $appointment_type = trim($_POST['appointment_type'] ?? 'both');
        $symptoms         = trim($_POST['symptoms'] ?? '');

        if ($doctor_id <= 0 || empty($appointment_date) || empty($time_slot)) {
            $error = 'Please select a doctor, appointment date, and an available time slot.';
        } else {
            $allowed_types = ['video', 'chat', 'both', 'in-person'];
            if (!in_array($appointment_type, $allowed_types, true)) {
                $appointment_type = 'both';
            }

            $full_datetime = $appointment_date . ' ' . $time_slot . ':00';

            // Real-time double-booking check
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM appointments 
                WHERE doctor_id = ? AND appointment_date = ? AND status != 'cancelled'
            ");
            $stmt->execute([$doctor_id, $full_datetime]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error = 'This time slot was just booked by another patient. Please choose an alternate slot.';
            } else {
                $stmt = $db->prepare("SELECT consultation_fee FROM doctors WHERE id = ?");
                $stmt->execute([$doctor_id]);
                $consult_fee = (float)$stmt->fetchColumn();
                if ($consult_fee <= 0) {
                    $consult_fee = 1000.00;
                }

                try {
                    $stmt =$db->prepare("
                        INSERT INTO appointments 
                        (patient_id, doctor_id, appointment_date, appointment_type, symptoms, fee, consultation_fee, medicine_fee, total_amount, status, payment_status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0.0, ?, 'pending', 'pending', datetime('now'))
                    ");
                    $stmt->execute([
                        $patient['id'],$doctor_id,
                        $full_datetime,$appointment_type,
                        $symptoms,$consult_fee,
                        $consult_fee,$consult_fee
                    ]);
                } catch (PDOException $pdo_e) {
                    // Failsafe in case SQLite still has an active unmigrated CHECK constraint
                    if (strpos($pdo_e->getMessage(), 'CHECK constraint failed') !== false) {$fallback_type = 'video';
                        $tagged_symptoms = "[Consultation: Both Video & Chat] " . $symptoms;
                        
                        $stmt =$db->prepare("
                            INSERT INTO appointments 
                            (patient_id, doctor_id, appointment_date, appointment_type, symptoms, fee, consultation_fee, medicine_fee, total_amount, status, payment_status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 0.0, ?, 'pending', 'pending', datetime('now'))
                        ");
                        $stmt->execute([
                            $patient['id'],$doctor_id,
                            $full_datetime,$fallback_type,
                            $tagged_symptoms,$consult_fee,
                            $consult_fee,$consult_fee
                        ]);
                    } else {
                        throw $pdo_e;
                    }
                }

                $_SESSION['success'] = "Appointment booked successfully for " . date('M d, Y - g:i A', strtotime($full_datetime)) . "!";
                header('Location: patient.php');
                exit;
            }
        }
    } catch (Throwable $e) {$error = "Booking failed: " . $e->getMessage();
    }
}

$preselected_doc_id = (int)($_GET['doctor_id'] ?? 0);

// Load Active Doctors
$stmt =$db->prepare("
    SELECT d.*, u.email 
    FROM doctors d 
    JOIN users u ON u.id = d.user_id 
    ORDER BY d.is_available DESC, d.rating DESC
");
$stmt->execute();
$doctors_list =$stmt->fetchAll(PDO::FETCH_ASSOC);

function safe_display($val,$def = '') {
    return htmlspecialchars($val ?? $def, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Consultation Appointment - TeleMed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .slot-pill {
            transition: all 0.15s ease-in-out;
        }
        .slot-pill:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2);
        }
        .slot-pill.active {
            background-color: #059669 !important;
            color: #ffffff !important;
            border-color: #059669 !important;
            font-weight: 700;
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-800">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-50 shadow-sm">
        <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
            <a href="patient.php" class="flex items-center gap-2 text-indigo-600 font-bold text-lg hover:text-indigo-700">
                <span>← Back to Dashboard</span>
            </a>
            <div class="text-sm font-semibold text-slate-600">
                Patient: <span class="text-slate-900"><?= safe_display($patient['name']) ?></span>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-8">
        
        <div class="text-center mb-8">
            <span class="inline-block px-3 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full mb-2 uppercase tracking-wider">
                Live Dynamic Scheduling
            </span>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Book a Doctor Consultation</h1>
            <p class="text-slate-600 text-sm mt-1">Select your doctor, consultation mode, and 30-minute real-time interval.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 rounded-xl mb-6 shadow-sm">
                <strong>Error:</strong> <?= safe_display($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="bookingForm" class="space-y-6">
            <input type="hidden" name="book_appointment" value="1">
            <input type="hidden" name="time_slot" id="selectedTimeSlotInput" value="">

            <!-- STEP 1: SELECT DOCTOR -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <label class="block text-sm font-bold text-slate-800 uppercase tracking-wider mb-3">
                    1. Select Consulting Physician *
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" id="doctorsGrid">
                    <?php 
                    $first_doc_id = !empty($doctors_list) ? (int)$doctors_list[0]['id'] : 0;
                    foreach ($doctors_list as$doc): 
                        $doc_fee = (float)($doc['consultation_fee'] > 0 ? $doc['consultation_fee'] : 1000.0);$is_checked = false;
                        if ($preselected_doc_id > 0) {
                            if ((int)$doc['id'] === $preselected_doc_id) {$is_checked = true;
                            }
                        } else {
                            if ((int)$doc['id'] === $first_doc_id) {$is_checked = true;
                            }
                        }

                        $card_classes =$is_checked ? 'border-emerald-500 bg-emerald-50/50' : 'border-slate-200 hover:border-slate-300';
                    ?>
                        <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition-all doc-card-label <?= $card_classes ?>">
                            <input type="radio" name="doctor_id" value="<?= $doc['id'] ?>" class="mt-1 text-emerald-600 focus:ring-emerald-500" <?= $is_checked ? 'checked' : '' ?> onchange="onDoctorChanged()">
                            <div class="flex-1">
                                <div class="font-bold text-slate-900 text-base">Dr. <?= safe_display($doc['name']) ?></div>
                                <div class="text-xs font-semibold text-emerald-700"><?= safe_display($doc['specialization'] ?: 'General Physician') ?> &bull; <?= safe_display($doc['qualification'] ?: 'MBBS') ?></div>
                                <div class="text-xs text-slate-500 mt-1">⭐ <?= number_format($doc['rating'], 1) ?> (<?=$doc['total_consultations'] ?> visits)</div>
                                <div class="mt-2 text-sm font-extrabold text-slate-800">
                                    Checking Fee: <span class="text-emerald-600">₹<?= number_format($doc_fee, 2) ?></span>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- STEP 2: CHOOSE CONSULTATION TYPE (BOTH VIDEO & CHAT ENABLED) -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <label class="block text-sm font-bold text-slate-800 uppercase tracking-wider mb-3">
                    2. Choose Consultation Type *
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    
                    <!-- 1. BOTH VIDEO AND CHAT (DEFAULT RECOMMENDED) -->
                    <label class="flex flex-col p-4 rounded-xl border-2 border-emerald-500 bg-emerald-50/40 cursor-pointer transition-all type-option-card">
                        <input type="radio" name="appointment_type" value="both" class="sr-only" checked onchange="onTypeChanged()">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-2xl">⚡</span>
                            <span class="text-[10px] font-extrabold uppercase px-1.5 py-0.5 bg-emerald-600 text-white rounded">Recommended</span>
                        </div>
                        <div class="font-bold text-slate-900 text-sm">Both Video & Chat</div>
                        <div class="text-xs text-slate-600 mt-1">Simultaneous HD Video Call + Real-time medical chat.</div>
                    </label>

                    <!-- 2. VIDEO ONLY -->
                    <label class="flex flex-col p-4 rounded-xl border-2 border-slate-200 hover:border-indigo-400 cursor-pointer transition-all type-option-card">
                        <input type="radio" name="appointment_type" value="video" class="sr-only" onchange="onTypeChanged()">
                        <div class="text-2xl mb-1">📹</div>
                        <div class="font-bold text-slate-900 text-sm">Video Call Only</div>
                        <div class="text-xs text-slate-500 mt-1">Direct face-to-face live video consultation room.</div>
                    </label>

                    <!-- 3. CHAT ONLY -->
                    <label class="flex flex-col p-4 rounded-xl border-2 border-slate-200 hover:border-indigo-400 cursor-pointer transition-all type-option-card">
                        <input type="radio" name="appointment_type" value="chat" class="sr-only" onchange="onTypeChanged()">
                        <div class="text-2xl mb-1">💬</div>
                        <div class="font-bold text-slate-900 text-sm">Chat Session Only</div>
                        <div class="text-xs text-slate-500 mt-1">Live clinical messaging & digital prescription.</div>
                    </label>

                    <!-- 4. IN-PERSON CLINIC -->
                    <label class="flex flex-col p-4 rounded-xl border-2 border-slate-200 hover:border-indigo-400 cursor-pointer transition-all type-option-card">
                        <input type="radio" name="appointment_type" value="in-person" class="sr-only" onchange="onTypeChanged()">
                        <div class="text-2xl mb-1">🏢</div>
                        <div class="font-bold text-slate-900 text-sm">In-Person Clinic</div>
                        <div class="text-xs text-slate-500 mt-1">Physical OPD room check-up at the hospital.</div>
                    </label>

                </div>
            </div>

            <!-- STEP 3: APPOINTMENT DATE & REAL-TIME SLOTS -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-800 uppercase tracking-wider">
                            3. Select Date & Real-Time Slot *
                        </label>
                        <p class="text-xs text-slate-500">Pick any date to refresh available 30-minute intervals instantly.</p>
                    </div>
                    <div>
                        <input type="date" 
                               name="appointment_date" 
                               id="appointmentDateInput" 
                               value="<?= date('Y-m-d') ?>" 
                               min="<?= date('Y-m-d') ?>" 
                               max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
                               class="px-3 py-2 border-2 border-slate-300 rounded-lg text-sm font-semibold focus:border-emerald-500 focus:outline-none"
                               onchange="loadRealTimeSlots()">
                    </div>
                </div>

                <div class="flex items-center gap-4 text-xs font-semibold mb-3">
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-500"></span> Available</span>
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-slate-300"></span> Booked / Passed</span>
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-700"></span> Selected</span>
                </div>

                <div id="slotsContainer" class="min-h-[140px] flex items-center justify-center border border-dashed border-slate-300 rounded-xl p-4">
                    <div class="text-sm text-slate-500 animate-pulse">Calculating available doctor slots...</div>
                </div>
            </div>

            <!-- STEP 4: SYMPTOMS -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <label class="block text-sm font-bold text-slate-800 uppercase tracking-wider mb-2">
                    4. Symptoms & Chief Complaints (Optional)
                </label>
                <textarea name="symptoms" rows="3" placeholder="Describe symptoms or reasons for visit (e.g. fever, cough, joint pain)..." class="w-full p-3 border-2 border-slate-200 rounded-xl text-sm focus:border-emerald-500 focus:outline-none"></textarea>
            </div>

            <!-- STEP 5: TOTAL FEE & SUBMIT -->
            <div class="bg-slate-900 text-white p-6 rounded-2xl shadow-lg flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <div class="text-xs uppercase font-bold tracking-wider text-emerald-400">Total Consultation Fee</div>
                    <div class="text-3xl font-extrabold" id="summaryFeeText">₹1,000.00</div>
                    <div class="text-xs text-slate-400 mt-0.5" id="selectedSlotPreviewText">Please pick a slot above to proceed.</div>
                </div>
                <button type="submit" id="submitBookingBtn" disabled class="w-full sm:w-auto px-8 py-3.5 bg-emerald-500 hover:bg-emerald-600 disabled:bg-slate-700 disabled:cursor-not-allowed text-white font-extrabold rounded-xl transition-all shadow-md">
                    Confirm Appointment &rarr;
                </button>
            </div>

        </form>

    </main>

    <script>
        let currentSelectedTime = '';

        function onDoctorChanged() {
            document.querySelectorAll('.doc-card-label').forEach(lbl => {
                const radio = lbl.querySelector('input[type="radio"]');
                if (radio.checked) {
                    lbl.classList.add('border-emerald-500', 'bg-emerald-50/50');
                    lbl.classList.remove('border-slate-200');
                } else {
                    lbl.classList.remove('border-emerald-500', 'bg-emerald-50/50');
                    lbl.classList.add('border-slate-200');
                }
            });
            loadRealTimeSlots();
        }

        function onTypeChanged() {
            document.querySelectorAll('.type-option-card').forEach(card => {
                const radio = card.querySelector('input[type="radio"]');
                if (radio.checked) {
                    card.classList.add('border-emerald-500', 'bg-emerald-50/40');
                    card.classList.remove('border-slate-200');
                } else {
                    card.classList.remove('border-emerald-500', 'bg-emerald-50/40');
                    card.classList.add('border-slate-200');
                }
            });
            loadRealTimeSlots();
        }

        function advanceDateToTomorrow() {
            const today = new Date();
            today.setDate(today.getDate() + 1);
            const tomorrowStr = today.toISOString().split('T')[0];
            document.getElementById('appointmentDateInput').value = tomorrowStr;
            loadRealTimeSlots();
        }

        function loadRealTimeSlots() {
            const docRadio = document.querySelector('input[name="doctor_id"]:checked');
            const typeRadio = document.querySelector('input[name="appointment_type"]:checked');
            const dateInput = document.getElementById('appointmentDateInput');
            const container = document.getElementById('slotsContainer');
            const submitBtn = document.getElementById('submitBookingBtn');
            const previewText = document.getElementById('selectedSlotPreviewText');

            if (!docRadio || !dateInput.value) return;

            const docId = docRadio.value;
            const date = dateInput.value;
            const type = typeRadio ? typeRadio.value : 'both';

            container.innerHTML = `<div class="text-sm text-slate-500 animate-pulse">Calculating available doctor slots...</div>`;
            submitBtn.disabled = true;
            document.getElementById('selectedTimeSlotInput').value = '';
            previewText.textContent = 'Please pick a slot above to proceed.';
            currentSelectedTime = '';

            fetch(`?get_slots=1&doctor_id=${docId}&date=${date}&type=${type}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.is_working) {
                        container.innerHTML = `
                            <div class="text-center py-4">
                                <div class="text-2xl mb-1">🏖️</div>
                                <div class="font-bold text-slate-700 text-sm">${data.message || 'No slots available on this date.'}</div>
                                <div class="text-xs text-slate-500 mt-1">Please select another date.</div>
                            </div>
                        `;
                        return;
                    }

                    if (data.consultation_fee) {
                        document.getElementById('summaryFeeText').textContent = '₹' + parseFloat(data.consultation_fee).toFixed(2);
                    }

                    const slots = data.slots || [];
                    if (slots.length === 0) {
                        container.innerHTML = `<div class="text-sm text-slate-500">No working hours found for this physician.</div>`;
                        return;
                    }

                    // Check if all slots for today have already elapsed
                    if (data.available_count === 0 && data.is_today) {
                        container.innerHTML = `
                            <div class="text-center py-5">
                                <div class="text-2xl mb-1">⏰</div>
                                <div class="font-bold text-slate-800 text-sm">All consultation slots for today have already passed.</div>
                                <p class="text-xs text-slate-500 mt-1 mb-3">You can book open slots for tomorrow or any upcoming date.</p>
                                <button type="button" onclick="advanceDateToTomorrow()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg transition-all shadow-sm">
                                    🗓️ View Tomorrow's Open Slots &rarr;
                                </button>
                            </div>
                        `;
                        return;
                    }

                    let gridHtml = `
                        <div class="w-full">
                            <div class="text-xs font-bold text-emerald-800 mb-2">
                                ✓ Found ${data.available_count} Open Slots for ${date}:
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 w-full">
                    `;
                    
                    slots.forEach(s => {
                        if (s.is_available) {
                            gridHtml += `
                                <button type="button" 
                                        onclick="pickSlot('${s.time_24}', '${s.label}', this)" 
                                        class="slot-pill px-3 py-2 rounded-lg border text-xs font-semibold text-center border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100">
                                    ${s.label}
                                </button>
                            `;
                        } else {
                            const reasonTag = s.is_booked ? 'Booked' : 'Passed';
                            gridHtml += `
                                <button type="button" disabled 
                                        class="px-3 py-2 rounded-lg border text-xs font-semibold text-center border-slate-200 bg-slate-100 text-slate-400 cursor-not-allowed">
                                    ${s.label} <span class="text-[10px] block opacity-75">(${reasonTag})</span>
                                </button>
                            `;
                        }
                    });

                    gridHtml += `</div></div>`;
                    container.innerHTML = gridHtml;
                })
                .catch(() => {
                    container.innerHTML = `<div class="text-sm text-rose-500 font-semibold">Failed to fetch slots. Please try again.</div>`;
                });
        }

        function pickSlot(time24, label, btnElement) {
            document.querySelectorAll('.slot-pill').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');

            currentSelectedTime = time24;
            document.getElementById('selectedTimeSlotInput').value = time24;
            document.getElementById('selectedSlotPreviewText').textContent = 'Selected: ' + label + ' on ' + document.getElementById('appointmentDateInput').value;
            document.getElementById('submitBookingBtn').disabled = false;
        }

        document.addEventListener('DOMContentLoaded', () => {
            onDoctorChanged();
            onTypeChanged();
        });
    </script>
</body>
</html>