<?php
if (ob_get_level() === 0) {
    ob_start();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Dynamic Path Resolution for Includes
if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
}

// 2. Authentication & Access Gate
if (function_exists('require_login')) {
    require_login();
} elseif (empty($_SESSION['user']) && empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? []);
$user_role = strtolower($current_user['role'] ?? ($_SESSION['role'] ?? ''));

if ($user_role !== 'doctor' && $user_role !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$doctor_user_id = $current_user['id'] ?? ($_SESSION['user_id'] ?? 0);

// Dynamic relative path resolvers for standalone & subfolder setups
$video_room_path = file_exists(__DIR__ . '/video_room.php') ? 'video_room.php' : '../video_room.php';
$chat_path       = file_exists(__DIR__ . '/chat.php') ? 'chat.php' : '../chat.php';

// 3. SQLite Database Schema Auto-Migrations
try { $db->exec("ALTER TABLE appointments ADD COLUMN payment_status TEXT DEFAULT 'pending'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN consultation_fee REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN medicine_fee REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN total_amount REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN qualification TEXT DEFAULT 'MBBS, MD'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN clinic_address TEXT DEFAULT 'Room 104, OPD Block A, City Clinic'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN is_available INTEGER DEFAULT 1"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN is_clinic_open INTEGER DEFAULT 1"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN total_price REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN diagnosis TEXT DEFAULT 'Clinical Consultation'"); } catch (Throwable $e) {}

// Auto-migration for doctor 7-day working schedules
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS doctor_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doctor_id INTEGER NOT NULL,
            day_name TEXT NOT NULL,
            start_time TEXT DEFAULT '09:00',
            end_time TEXT DEFAULT '17:00',
            slot_duration INTEGER DEFAULT 30,
            is_active INTEGER DEFAULT 1,
            UNIQUE(doctor_id, day_name)
        )
    ");
} catch (Throwable $e) {}

// 4. Fetch or Create Doctor Profile
$stmt = $db->prepare("SELECT d.*, u.email, u.username FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.user_id = ?");
$stmt->execute([$doctor_user_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    $doc_name = !empty($current_user['username']) ? $current_user['username'] : 'Specialist';
    $stmt = $db->prepare("INSERT INTO doctors (user_id, name, specialization, consultation_fee, is_available, is_clinic_open) VALUES (?, ?, 'General Practice', 1000.0, 1, 1)");
    $stmt->execute([$doctor_user_id, $doc_name]);
    $new_doc_id = $db->lastInsertId();

    $stmt = $db->prepare("SELECT d.*, u.email, u.username FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = ?");
    $stmt->execute([$new_doc_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------------------
// POST / AJAX: 1-CLICK SCHEDULE SETUP (Mon–Sat: 9AM–5PM, Sun: OFF)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup_default_schedule') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $stmt = $db->prepare("
            INSERT INTO doctor_schedules (doctor_id, day_name, start_time, end_time, slot_duration, is_active)
            VALUES (?, ?, '09:00', '17:00', 30, ?)
            ON CONFLICT(doctor_id, day_name) DO UPDATE SET
                start_time = '09:00',
                end_time = '17:00',
                slot_duration = 30,
                is_active = excluded.is_active
        ");

        foreach ($days as $day) {
            $is_active = ($day === 'Sunday') ? 0 : 1;
            $stmt->execute([$doctor['id'], $day, $is_active]);
        }

        echo json_encode(['success' => true, 'message' => 'Default schedule applied: Mon–Sat 9:00 AM – 5:00 PM (Sunday OFF).']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Schedule setup error: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: TOGGLE INDIVIDUAL DAY SCHEDULE (ON / OFF)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_day_schedule') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $day_name = trim($_POST['day_name'] ?? '');
    try {
        $stmt = $db->prepare("SELECT is_active FROM doctor_schedules WHERE doctor_id = ? AND day_name = ?");
        $stmt->execute([$doctor['id'], $day_name]);
        $current = $stmt->fetchColumn();

        $new_val = ($current === false || (int)$current === 0) ? 1 : 0;
        
        $upsert = $db->prepare("
            INSERT INTO doctor_schedules (doctor_id, day_name, start_time, end_time, slot_duration, is_active)
            VALUES (?, ?, '09:00', '17:00', 30, ?)
            ON CONFLICT(doctor_id, day_name) DO UPDATE SET is_active = excluded.is_active
        ");
        $upsert->execute([$doctor['id'], $day_name, $new_val]);

        echo json_encode(['success' => true, 'is_active' => $new_val]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: TOGGLE PHYSICAL CLINIC (ON / OFF)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_clinic') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $current_clinic_state = isset($doctor['is_clinic_open']) ? (int)$doctor['is_clinic_open'] : 1;
        $new_clinic_val = ($current_clinic_state === 1) ? 0 : 1;

        $stmt = $db->prepare("UPDATE doctors SET is_clinic_open = ? WHERE id = ?");
        $stmt->execute([$new_clinic_val, $doctor['id']]);

        echo json_encode(['success' => true, 'is_clinic_open' => $new_clinic_val]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: TOGGLE TELEHEALTH AVAILABILITY (ONLINE / OFFLINE)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_availability') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $new_val = (int)($doctor['is_available'] ? 0 : 1);
        $stmt = $db->prepare("UPDATE doctors SET is_available = ? WHERE id = ?");
        $stmt->execute([$new_val, $doctor['id']]);

        echo json_encode(['success' => true, 'is_available' => $new_val]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: UPDATE APPOINTMENT STATUS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $new_status     = trim($_POST['status'] ?? '');
    $allowed_status = ['pending', 'confirmed', 'in-progress', 'completed', 'cancelled'];

    if ($appointment_id <= 0 || !in_array($new_status, $allowed_status, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE appointments SET status = ? WHERE id = ? AND doctor_id = ?");
        $stmt->execute([$new_status, $appointment_id, $doctor['id']]);
        echo json_encode(['success' => true, 'message' => "Appointment status updated to " . ucfirst($new_status)]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: SAVE CLINICAL PRESCRIPTION (Rx)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_prescription') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $appointment_id = (int)($_POST['appointment_id'] ?? 0);
        $patient_id     = (int)($_POST['patient_id'] ?? 0);
        $diagnosis      = trim($_POST['diagnosis'] ?? 'General Consultation');
        $instructions   = trim($_POST['instructions'] ?? 'Take medicines as directed with water.');
        $med_names      = $_POST['med_name'] ?? [];
        $med_dosages    = $_POST['med_dosage'] ?? [];
        $med_durations  = $_POST['med_duration'] ?? [];
        $med_prices     = $_POST['med_price'] ?? [];

        if ($appointment_id <= 0 || $patient_id <= 0 || empty($med_names)) {
            echo json_encode(['success' => false, 'message' => 'Please provide at least one medication.']);
            exit;
        }

        $total_med_price = 0.0;
        foreach ($med_prices as $p) {
            $total_med_price += (float)$p;
        }

        $db->prepare("DELETE FROM prescriptions WHERE appointment_id = ?")->execute([$appointment_id]);

        $ins_rx = $db->prepare("
            INSERT INTO prescriptions 
                (appointment_id, patient_id, doctor_id, medication, dosage, duration, instructions, diagnosis, total_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");

        for ($i = 0; $i < count($med_names); $i++) {
            $name  = trim($med_names[$i]);
            $price = isset($med_prices[$i]) ? (float)$med_prices[$i] : 0.0;
            if ($name === '') {
                continue;
            }

            $tagged_name = ($price > 0) ? "{$name} (₹" . number_format($price, 2, '.', '') . ")" : $name;
            $dosage      = trim($med_dosages[$i] ?? '1 Tab Daily');
            $duration    = trim($med_durations[$i] ?? '5 Days');

            $ins_rx->execute([
                $appointment_id,
                $patient_id,
                $doctor['id'],
                $tagged_name,
                $dosage,
                $duration,
                $instructions,
                $diagnosis,
                $total_med_price
            ]);
        }

        $doc_fee = (float)($doctor['consultation_fee'] > 0 ? $doctor['consultation_fee'] : 1000.0);
        $grand_total = $doc_fee + $total_med_price;

        $stmt = $db->prepare("
            UPDATE appointments 
            SET status = 'completed',
                consultation_fee = ?,
                medicine_fee = ?,
                fee = ?,
                total_amount = ?
            WHERE id = ? AND doctor_id = ?
        ");
        $stmt->execute([$doc_fee, $total_med_price, $grand_total, $grand_total, $appointment_id, $doctor['id']]);

        echo json_encode([
            'success'     => true, 
            'message'     => 'Prescription issued and consultation marked as completed!',
            'grand_total' => $grand_total
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: UPDATE PROFILE SETTINGS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $name            = trim($_POST['name'] ?? '');
        $specialization  = trim($_POST['specialization'] ?? 'General Practice');
        $qualification   = trim($_POST['qualification'] ?? 'MBBS, MD');
        $clinic_address  = trim($_POST['clinic_address'] ?? 'City Clinic');
        $fee             = (float)($_POST['consultation_fee'] ?? 1000.0);
        $is_available    = isset($_POST['is_available']) ? 1 : 0;
        $is_clinic_open  = isset($_POST['is_clinic_open']) ? 1 : 0;

        $stmt = $db->prepare("
            UPDATE doctors 
            SET name = ?, specialization = ?, qualification = ?, clinic_address = ?, consultation_fee = ?, is_available = ?, is_clinic_open = ? 
            WHERE id = ?
        ");
        $stmt->execute([$name, $specialization, $qualification, $clinic_address, $fee, $is_available, $is_clinic_open, $doctor['id']]);

        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// 5. Fetch Doctor's Schedule
$sched_stmt = $db->prepare("SELECT day_name, start_time, end_time, is_active FROM doctor_schedules WHERE doctor_id = ?");
$sched_stmt->execute([$doctor['id']]);
$raw_sched = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

$doctor_schedule_map = [];
foreach ($raw_sched as $s) {
    $doctor_schedule_map[$s['day_name']] = $s;
}

// 6. Fetch Doctor's Appointment Queue
$stmt = $db->prepare("
    SELECT a.*, p.name as patient_name, p.age, p.gender, p.blood_type, p.allergies, p.contact as patient_phone
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE a.doctor_id = ?
    ORDER BY (CASE WHEN a.status = 'confirmed' THEN 0 WHEN a.status = 'pending' THEN 1 ELSE 2 END), a.appointment_date DESC
");
$stmt->execute([$doctor['id']]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

function safe_out($val, $fallback = '') {
    return htmlspecialchars($val ?? $fallback, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Portal - Consultations & Schedule</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col justify-between">

    <!-- Top Navigation -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🏣</span>
                <div>
                    <span class="text-xl font-extrabold text-indigo-600 tracking-tight">TeleMed</span>
                    <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Doctor Portal</span>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-4">
                <button onclick="openProfileModal()" class="text-xs font-bold px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    ⚙️ Dr. <?= safe_out($doctor['name']) ?>
                </button>
                <a href="../logout.php" class="text-xs font-bold px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 transition">
                    Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full flex-1">
        
        <!-- Summary Stats (Checking Fee, Telehealth, Physical Clinic, Appointments, Specialization) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase">Checking Fee</p>
                    <p class="text-2xl font-black text-slate-900 mt-1">₹<?= number_format($doctor['consultation_fee'], 2) ?></p>
                </div>
                <div class="text-3xl">💳</div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase">Telehealth Status</p>
                    <button onclick="toggleDoctorAvailability()" id="availStatusBtn" class="mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold transition <?= $doctor['is_available'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200' ?>" title="Click to toggle online telehealth availability">
                        <span class="w-2 h-2 rounded-full <?= $doctor['is_available'] ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400' ?>"></span>
                        <span id="availStatusText"><?= $doctor['is_available'] ? 'Online (Accepting)' : 'Offline (Paused)' ?></span>
                    </button>
                </div>
                <div class="text-3xl">🩺</div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase">Physical Clinic</p>
                    <button onclick="toggleClinicStatus()" id="clinicStatusBtn" class="mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold transition <?= ($doctor['is_clinic_open'] ?? 1) ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100' ?>" title="Click to turn Physical Clinic ON or OFF">
                        <span class="w-2 h-2 rounded-full <?= ($doctor['is_clinic_open'] ?? 1) ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500' ?>"></span>
                        <span id="clinicStatusText"><?= ($doctor['is_clinic_open'] ?? 1) ? 'Clinic ON (Open)' : 'Clinic OFF (Closed)' ?></span>
                    </button>
                </div>
                <div class="text-3xl">🏢</div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase">Appointments</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= count($appointments) ?></p>
                </div>
                <div class="text-3xl">📅</div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase">Specialization</p>
                    <p class="text-sm font-extrabold text-indigo-600 mt-1 truncate max-w-[130px]"><?= safe_out($doctor['specialization']) ?></p>
                </div>
                <div class="text-3xl">👨‍⚕️</div>
            </div>

        </div>

        <!-- 7-Day Slot Availability & Schedule Widget (30-Min Windows) -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8 mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-slate-100 gap-4">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                        <span>🗓️</span> 7-Day Slot Availability & Schedule
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">(30-Min Windows • Click any day card to toggle ON / OFF)</p>
                </div>

                <div>
                    <button type="button" onclick="triggerOneClickScheduleSetup()" id="btnOneClickSetup" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs shadow-sm transition flex items-center gap-2">
                        <span>⚡</span> 1-Click Setup (Mon–Sat, 9AM–5PM)
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3.5 pt-6">
                <?php
                for ($i = 0; $i < 7; $i++):
                    $ts = strtotime("+{$i} days");
                    $day_name = date('l', $ts);
                    $date_label = date('M d', $ts);
                    $is_today = ($i === 0);

                    // Read status from schedule map, defaulting to Sun OFF, Mon-Sat ON
                    $is_active = isset($doctor_schedule_map[$day_name])
                        ? (int)$doctor_schedule_map[$day_name]['is_active']
                        : ($day_name !== 'Sunday' ? 1 : 0);

                    $date_str = date('Y-m-d', $ts);
                    $count_stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND date(appointment_date) = ? AND status != 'cancelled'");
                    $count_stmt->execute([$doctor['id'], $date_str]);
                    $booked_count = (int)$count_stmt->fetchColumn();
                    
                    $total_slots = 16;
                    $free_slots = max(0, $total_slots - $booked_count);
                ?>
                    <div onclick="toggleDaySchedule('<?= $day_name ?>')" class="cursor-pointer p-4 rounded-2xl border transition flex flex-col justify-between <?= $is_active ? 'bg-slate-50/80 border-slate-200 hover:border-indigo-400 hover:bg-white' : 'bg-slate-100/60 border-slate-200/60 opacity-60' ?>" id="card_day_<?= $day_name ?>">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-bold text-slate-800">
                                    <?= $day_name ?> <?= $is_today ? '<span class="text-indigo-600 font-extrabold">(Today)</span>' : '' ?>
                                </span>
                            </div>
                            <div class="text-sm font-extrabold text-slate-900 mb-3"><?= $date_label ?></div>

                            <?php if ($is_active): ?>
                                <span class="inline-block px-2 py-0.5 rounded-md text-[11px] font-bold bg-emerald-100 text-emerald-800 mb-2">
                                    <?= $free_slots ?> Slots Free
                                </span>
                                <p class="text-xs font-semibold text-slate-600">9:00 AM – 5:00 PM</p>
                            <?php else: ?>
                                <span class="inline-block px-2 py-0.5 rounded-md text-[11px] font-bold bg-rose-100 text-rose-800 mb-2">
                                    OFF
                                </span>
                                <p class="text-xs font-semibold text-slate-400">No OPD Hours</p>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-200/60 text-[11px] text-slate-500 font-medium">
                            <?= $is_active ? "{$booked_count} Booked / {$total_slots} Total" : "Clinic Closed" ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Appointment Consultation Queue -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-10">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-extrabold text-slate-900 tracking-tight">Active Appointments & Clinical Actions</h2>
                <span class="text-xs font-semibold text-slate-500"><?= count($appointments) ?> Scheduled</span>
            </div>

            <?php if (empty($appointments)): ?>
                <div class="p-12 text-center text-slate-400 font-medium">
                    <span class="text-4xl block mb-2">📋</span>
                    No patient appointments booked yet.
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($appointments as $apt): ?>
                        <div class="p-6 flex flex-col lg:flex-row lg:items-center justify-between gap-6 hover:bg-slate-50 transition">
                            
                            <div class="space-y-1.5 flex-1">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-base font-extrabold text-slate-900"><?= safe_out($apt['patient_name']) ?></h3>
                                    <span class="text-xs font-bold px-2.5 py-0.5 rounded-full uppercase <?= ($apt['status'] === 'confirmed') ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : (($apt['status'] === 'completed') ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200') ?>">
                                        <?= safe_out($apt['status']) ?>
                                    </span>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-full <?= ($apt['payment_status'] === 'paid') ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= ($apt['payment_status'] === 'paid') ? '✓ Paid' : 'Pending Fee' ?>
                                    </span>
                                </div>

                                <div class="text-xs text-slate-500 flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <span>Age: <strong><?= !empty($apt['age']) ? $apt['age'] . ' Yrs' : 'N/A' ?></strong></span>
                                    <span>Sex: <strong><?= ucfirst($apt['gender'] ?? 'N/A') ?></strong></span>
                                    <span>Blood: <strong><?= safe_out($apt['blood_type'] ?: 'N/A') ?></strong></span>
                                    <span>Schedule: <strong class="text-indigo-600"><?= date('M d, Y - g:i A', strtotime($apt['appointment_date'])) ?></strong></span>
                                    <span>Type: <strong><?= ucfirst($apt['appointment_type']) ?></strong></span>
                                </div>

                                <?php if (!empty($apt['allergies'])): ?>
                                    <div class="inline-block mt-1 text-xs font-bold text-rose-700 bg-rose-50 border border-rose-200 px-3 py-1 rounded-lg">
                                        ⚠️ Drug Allergies: <?= safe_out($apt['allergies']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($apt['symptoms'])): ?>
                                    <p class="text-xs text-slate-600 mt-1">
                                        <span class="font-bold">Chief Complaint:</span> <?= safe_out($apt['symptoms']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <a href="<?= $video_room_path ?>?room=<?= $apt['id'] ?>&role=doctor" target="_blank" class="px-3.5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs flex items-center gap-1.5 shadow-sm transition">
                                    <span>📹</span> Video Call
                                </a>

                                <a href="<?= $chat_path ?>?appointment_id=<?= $apt['id'] ?>" target="_blank" class="px-3.5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs flex items-center gap-1.5 shadow-sm transition">
                                    <span>💬</span> Chat
                                </a>

                                <button onclick="openPrescriptionModal(<?= $apt['id'] ?>, <?= $apt['patient_id'] ?>, '<?= addslashes(safe_out($apt['patient_name'])) ?>')" class="px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-900 text-white font-extrabold text-xs flex items-center gap-1.5 shadow-sm transition">
                                    <span>🩺</span> Write Rx
                                </button>

                                <select onchange="updateAppointmentStatus(<?= $apt['id'] ?>, this.value)" class="text-xs font-bold px-3 py-2.5 rounded-xl border border-slate-300 bg-white focus:outline-none focus:border-indigo-600">
                                    <option value="pending" <?= ($apt['status'] === 'pending') ? 'selected' : '' ?>>Pending</option>
                                    <option value="confirmed" <?= ($apt['status'] === 'confirmed') ? 'selected' : '' ?>>Confirmed</option>
                                    <option value="completed" <?= ($apt['status'] === 'completed') ? 'selected' : '' ?>>Completed</option>
                                    <option value="cancelled" <?= ($apt['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Modal: Write Clinical Prescription -->
    <div id="rxModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 max-h-[90vh] overflow-y-auto border border-slate-200 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">Compile E-Prescription (Rx)</h2>
                    <p class="text-xs text-slate-500">Patient: <span id="rxModalPatName" class="font-bold text-indigo-600"></span></p>
                </div>
                <button onclick="closeModal('rxModal')" class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>

            <form id="rxForm" onsubmit="submitPrescription(event)" class="space-y-4">
                <input type="hidden" name="action" value="save_prescription">
                <input type="hidden" name="appointment_id" id="rxApptId">
                <input type="hidden" name="patient_id" id="rxPatId">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Clinical Diagnosis</label>
                    <input type="text" name="diagnosis" required placeholder="e.g. Acute Viral Bronchitis" class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:border-indigo-600">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-bold text-slate-700 uppercase">Prescribed Formulations</label>
                        <button type="button" onclick="addMedicationRow()" class="text-xs font-bold text-indigo-600 hover:underline">+ Add Medicine</button>
                    </div>

                    <div id="medRowsContainer" class="space-y-3">
                        <div class="grid grid-cols-12 gap-2 med-row">
                            <input type="text" name="med_name[]" required placeholder="Medicine Name" class="col-span-5 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                            <input type="text" name="med_dosage[]" required placeholder="Dosage" class="col-span-3 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                            <input type="text" name="med_duration[]" required placeholder="Duration" class="col-span-2 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                            <input type="number" step="0.01" name="med_price[]" placeholder="₹ Rate" class="col-span-2 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Doctor Advice / Diet Restrictions</label>
                    <textarea name="instructions" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:border-indigo-600" placeholder="Drink warm fluids. Rest for 3 days."></textarea>
                </div>

                <div class="pt-4 flex justify-end gap-3 border-t border-slate-100">
                    <button type="button" onclick="closeModal('rxModal')" class="px-5 py-2.5 rounded-xl bg-slate-100 font-bold text-xs text-slate-600">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs shadow-md">Sign & Issue Rx</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Doctor Profile Settings -->
    <div id="profileModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 border border-slate-200 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                <h2 class="text-lg font-extrabold text-slate-900">Doctor Profile Settings</h2>
                <button onclick="closeModal('profileModal')" class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>

            <form id="doctorProfileForm" onsubmit="submitProfile(event)" class="space-y-4">
                <input type="hidden" name="action" value="update_profile">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Doctor Full Name</label>
                    <input type="text" name="name" value="<?= safe_out($doctor['name']) ?>" required class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Specialization</label>
                    <input type="text" name="specialization" value="<?= safe_out($doctor['specialization']) ?>" required class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Qualifications</label>
                        <input type="text" name="qualification" value="<?= safe_out($doctor['qualification']) ?>" class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Checking Fee (₹)</label>
                        <input type="number" step="0.01" name="consultation_fee" value="<?= safe_out($doctor['consultation_fee']) ?>" required class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Clinic Address</label>
                    <input type="text" name="clinic_address" value="<?= safe_out($doctor['clinic_address']) ?>" class="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm">
                </div>

                <div class="space-y-2 pt-2 border-t border-slate-100">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_available" id="isAvailCheck" <?= $doctor['is_available'] ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <label for="isAvailCheck" class="text-xs font-bold text-slate-700">Online Telehealth Consultations (Active)</label>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_clinic_open" id="isClinicCheck" <?= ($doctor['is_clinic_open'] ?? 1) ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <label for="isClinicCheck" class="text-xs font-bold text-slate-700">Physical Clinic Open (Accept In-Person Visits)</label>
                    </div>
                </div>

                <div class="pt-4 flex justify-end gap-3 border-t border-slate-100">
                    <button type="button" onclick="closeModal('profileModal')" class="px-5 py-2.5 rounded-xl bg-slate-100 font-bold text-xs text-slate-600">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs shadow-md">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; <?= date('Y') ?> TeleMed Clinical Dashboard. All rights reserved.
    </footer>

    <script>
        function openProfileModal() { document.getElementById('profileModal').classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function triggerOneClickScheduleSetup() {
            const btn = document.getElementById('btnOneClickSetup');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> Setting up...';

            const fd = new FormData();
            fd.append('action', 'setup_default_schedule');

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert(data.message || 'Failed to update schedule.');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Server error setting up schedule.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        function toggleDaySchedule(dayName) {
            const fd = new FormData();
            fd.append('action', 'toggle_day_schedule');
            fd.append('day_name', dayName);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error updating day schedule.');
                    }
                })
                .catch(err => console.error(err));
        }

        function toggleClinicStatus() {
            const fd = new FormData();
            fd.append('action', 'toggle_clinic');

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const btn = document.getElementById('clinicStatusBtn');
                        const txt = document.getElementById('clinicStatusText');
                        if (res.is_clinic_open === 1) {
                            btn.className = "mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold transition bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100";
                            txt.innerText = "Clinic ON (Open)";
                            btn.querySelector('span').className = "w-2 h-2 rounded-full bg-emerald-500 animate-pulse";
                        } else {
                            btn.className = "mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold transition bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100";
                            txt.innerText = "Clinic OFF (Closed)";
                            btn.querySelector('span').className = "w-2 h-2 rounded-full bg-rose-500";
                        }
                    } else {
                        alert(res.message || 'Error toggling clinic status');
                    }
                })
                .catch(() => alert('Network error toggling clinic status'));
        }

        function toggleDoctorAvailability() {
            const fd = new FormData();
            fd.append('action', 'toggle_availability');

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const btn = document.getElementById('availStatusBtn');
                        const txt = document.getElementById('availStatusText');
                        if (res.is_available === 1) {
                            btn.className = "mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold transition bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100";
                            txt.innerText = "Online (Accepting)";
                            btn.querySelector('span').className = "w-2 h-2 rounded-full bg-emerald-500 animate-pulse";
                        } else {
                            btn.className = "mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-extrabold transition bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200";
                            txt.innerText = "Offline (Paused)";
                            btn.querySelector('span').className = "w-2 h-2 rounded-full bg-slate-400";
                        }
                    } else {
                        alert(res.message);
                    }
                });
        }

        function openPrescriptionModal(apptId, patId, patName) {
            document.getElementById('rxApptId').value = apptId;
            document.getElementById('rxPatId').value = patId;
            document.getElementById('rxModalPatName').innerText = patName;
            document.getElementById('rxModal').classList.remove('hidden');
        }

        function addMedicationRow() {
            const container = document.getElementById('medRowsContainer');
            const row = document.createElement('div');
            row.className = 'grid grid-cols-12 gap-2 med-row';
            row.innerHTML = `
                <input type="text" name="med_name[]" required placeholder="Medicine Name" class="col-span-5 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                <input type="text" name="med_dosage[]" required placeholder="Dosage" class="col-span-3 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                <input type="text" name="med_duration[]" required placeholder="Duration" class="col-span-2 px-3 py-2 rounded-xl border border-slate-300 text-xs">
                <input type="number" step="0.01" name="med_price[]" placeholder="₹ Rate" class="col-span-2 px-3 py-2 rounded-xl border border-slate-300 text-xs">
            `;
            container.appendChild(row);
        }

        function updateAppointmentStatus(apptId, status) {
            const fd = new FormData();
            fd.append('action', 'update_status');
            fd.append('appointment_id', apptId);
            fd.append('status', status);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        }

        function submitPrescription(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('rxForm'));
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.message);
                        location.reload();
                    } else {
                        alert(res.message);
                    }
                });
        }

        function submitProfile(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('doctorProfileForm'));
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        }
    </script>
</body>
</html>