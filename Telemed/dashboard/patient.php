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
$patient_user_id = $current_user['id'] ?? ($_SESSION['user_id'] ?? 0);

// Dynamic relative path resolvers for subfolder safety
$video_room_path = file_exists(__DIR__ . '/video_room.php') ? 'video_room.php' : '../video_room.php';
$chat_path       = file_exists(__DIR__ . '/chat.php') ? 'chat.php' : '../chat.php';

// 3. SQLite Database Schema Auto-Migrations
try { $db->exec("ALTER TABLE appointments ADD COLUMN payment_status TEXT DEFAULT 'pending'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN payment_method TEXT DEFAULT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN transaction_id TEXT DEFAULT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN fee REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN consultation_fee REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN medicine_fee REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN total_amount REAL DEFAULT 0.0"); } catch (Throwable $e) {}

try { $db->exec("ALTER TABLE patients ADD COLUMN allergies TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN medical_history TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN address TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN emergency_contact TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN profile_photo TEXT DEFAULT NULL"); } catch (Throwable $e) {}

try { $db->exec("ALTER TABLE doctors ADD COLUMN is_available INTEGER DEFAULT 1"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN is_clinic_open INTEGER DEFAULT 1"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN total_price REAL DEFAULT 0.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN diagnosis TEXT DEFAULT 'Clinical Consultation'"); } catch (Throwable $e) {}

// 4. Fetch or Create Patient Profile
$stmt = $db->prepare("SELECT p.*, u.email, u.username FROM patients p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?");
$stmt->execute([$patient_user_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    $patient_name = !empty($current_user['username']) ? $current_user['username'] : 'Patient';
    $stmt = $db->prepare("INSERT INTO patients (user_id, name) VALUES (?, ?)");
    $stmt->execute([$patient_user_id, $patient_name]);
    $new_patient_id = $db->lastInsertId();

    $stmt = $db->prepare("SELECT p.*, u.email, u.username FROM patients p JOIN users u ON u.id = p.user_id WHERE p.id = ?");
    $stmt->execute([$new_patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------------------
// POST / AJAX: UPDATE PATIENT PROFILE & ALLERGIES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $name              = trim($_POST['name'] ?? '');
        $age               = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $gender            = trim($_POST['gender'] ?? '');
        $blood_type        = trim($_POST['blood_type'] ?? '');
        $contact           = trim($_POST['contact'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $address           = trim($_POST['address'] ?? '');
        $allergies         = trim($_POST['allergies'] ?? '');
        $medical_history   = trim($_POST['medical_history'] ?? '');

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Patient name cannot be blank.']);
            exit;
        }

        $stmt = $db->prepare("
            UPDATE patients 
            SET name = ?, age = ?, gender = ?, blood_type = ?, contact = ?, 
                emergency_contact = ?, address = ?, allergies = ?, medical_history = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $name, $age, $gender, $blood_type, $contact, 
            $emergency_contact, $address, $allergies, $medical_history, 
            $patient['id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// POST / AJAX: PROCESS PAYMENT
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $appointment_id   = (int)($_POST['appointment_id'] ?? 0);
        $payment_method   = trim($_POST['payment_method'] ?? 'UPI');
        $consultation_fee = (float)($_POST['consultation_fee'] ?? 0.00);
        $medicine_fee     = (float)($_POST['medicine_fee'] ?? 0.00);
        $amount           = (float)($_POST['amount'] ?? 0.00);
        $custom_utr       = trim($_POST['utr_ref'] ?? '');

        if ($appointment_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
            exit;
        }

        $grand_total = ($amount > 0) ? $amount : ($consultation_fee + $medicine_fee);
        $txn_id = ($custom_utr !== '') 
            ? 'UTR-' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $custom_utr)) 
            : 'TXN-UPI-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

        $stmt = $db->prepare("
            UPDATE appointments 
            SET payment_status = 'paid',
                payment_method = ?,
                transaction_id = ?,
                consultation_fee = ?,
                medicine_fee = ?,
                fee = ?,
                total_amount = ? 
            WHERE id = ? AND patient_id = ?
        ");
        $stmt->execute([
            $payment_method,
            $txn_id,
            $consultation_fee,
            $medicine_fee,
            $grand_total,
            $grand_total,
            $appointment_id,
            $patient['id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Payment recorded successfully!']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// GET / AJAX: RX SLIP DETAILS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_rx_details'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $appointment_id = (int)($_GET['appointment_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT a.*, 
                   COALESCE(d.name, 'Doctor') as doctor_name, 
                   COALESCE(d.specialization, 'General Physician') as specialization, 
                   COALESCE(d.qualification, 'MBBS, MD') as qualification, 
                   COALESCE(d.clinic_address, 'Room 104, OPD Block A, City Clinic') as clinic_address,
                   COALESCE(d.consultation_fee, 1000.0) as doctor_checking_fee,
                   COALESCE(pt.name, 'Patient') as patient_name, 
                   pt.age, pt.gender, pt.blood_type, pt.allergies
            FROM appointments a
            LEFT JOIN doctors d ON d.id = a.doctor_id
            LEFT JOIN patients pt ON pt.id = a.patient_id
            WHERE a.id = ? AND a.patient_id = ?
        ");
        $stmt->execute([$appointment_id, $patient['id']]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$details) {
            echo json_encode(['success' => false, 'message' => 'Consultation record not found.']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM prescriptions WHERE appointment_id = ? ORDER BY id ASC");
        $stmt->execute([$appointment_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $date_ref = !empty($details['appointment_date']) ? $details['appointment_date'] : date('Y-m-d');
        $verification_code = 'TELEMED-RX-' . strtoupper(substr(md5($appointment_id . $date_ref), 0, 8));

        echo json_encode([
            'success'           => true,
            'details'           => $details,
            'items'             => $items,
            'verification_code' => $verification_code
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// 5. Fetch Active Patient Appointments
$stmt = $db->prepare("
    SELECT a.*, d.name as doctor_name, d.specialization, d.consultation_fee as doc_fee,
           COALESCE((SELECT total_price FROM prescriptions WHERE appointment_id = a.id ORDER BY id DESC LIMIT 1), 0.0) as rx_med_fee
    FROM appointments a 
    JOIN doctors d ON d.id = a.doctor_id 
    WHERE a.patient_id = ? AND a.status != 'cancelled'
    ORDER BY (CASE WHEN a.payment_status != 'paid' THEN 0 ELSE 1 END), a.appointment_date DESC 
    LIMIT 10
");
$stmt->execute([$patient['id']]);
$upcoming_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Fetch Available Doctors (Online Telehealth Active)
$stmt = $db->prepare("
    SELECT d.*, u.email 
    FROM doctors d 
    JOIN users u ON u.id = d.user_id 
    WHERE d.is_available = 1 
    ORDER BY d.id DESC 
    LIMIT 6
");
$stmt->execute();
$available_doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

function safe_p($val, $fallback = '') {
    return htmlspecialchars($val ?? $fallback, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal - Consultations & Appointments</title>
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
                    <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">Patient Portal</span>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-4">
                <button onclick="openProfileModal()" class="text-xs font-bold px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    👤 <?= safe_p($patient['name']) ?>
                </button>
                <a href="../logout.php" class="text-xs font-bold px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 transition">
                    Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full flex-1">
        
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-10">
            
            <!-- Left: Personal Health Profile -->
            <div class="lg:col-span-4 bg-white rounded-3xl border border-slate-200 p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h2 class="text-base font-extrabold text-slate-900">Personal Health Profile</h2>
                    <button onclick="openProfileModal()" class="text-xs font-bold text-indigo-600 hover:underline">Edit</button>
                </div>

                <div class="space-y-2.5 text-xs">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500 font-semibold">Full Name</span>
                        <span class="font-bold text-slate-900"><?= safe_p($patient['name']) ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500 font-semibold">Age / Gender</span>
                        <span class="font-bold text-slate-900"><?= $patient['age'] ? $patient['age'] . ' Yrs' : 'N/A' ?> / <?= ucfirst($patient['gender'] ?: 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500 font-semibold">Blood Group</span>
                        <span class="font-bold text-emerald-600"><?= safe_p($patient['blood_type'] ?: 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500 font-semibold">Contact Phone</span>
                        <span class="font-bold text-slate-900"><?= safe_p($patient['contact'] ?: 'Not Added') ?></span>
                    </div>
                </div>

                <?php if (!empty($patient['allergies'])): ?>
                    <div class="p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                        <span class="font-bold block mb-0.5">⚠️ Known Allergies:</span>
                        <?= safe_p($patient['allergies']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Active Appointments & Consultations -->
            <div class="lg:col-span-8 bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                    <h2 class="text-base font-extrabold text-slate-900">My Consultations & Active Calls</h2>
                    <span class="text-xs font-bold text-slate-400"><?= count($upcoming_appointments) ?> Records</span>
                </div>

                <?php if (empty($upcoming_appointments)): ?>
                    <p class="text-center py-8 text-xs font-semibold text-slate-400">No scheduled consultations found.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($upcoming_appointments as $apt):$c_fee = (float)($apt['consultation_fee'] > 0 ?$apt['consultation_fee'] : ($apt['doc_fee'] ?? 1000.0));$m_fee = (float)($apt['medicine_fee'] > 0 ?$apt['medicine_fee'] : ($apt['rx_med_fee'] ?? 0.0));$total = (float)($apt['total_amount'] > 0 ?$apt['total_amount'] : ($c_fee +$m_fee));
                            $is_paid = ($apt['payment_status'] === 'paid');
                        ?>
                            <div class="p-4 rounded-2xl border border-slate-200/80 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-extrabold text-slate-900">Dr. <?= safe_p($apt['doctor_name']) ?></h3>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full uppercase <?= ($apt['status'] === 'confirmed') ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-700' ?>">
                                            <?= safe_p($apt['status']) ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-slate-500">
                                        <?= date('M d, Y - g:i A', strtotime($apt['appointment_date'])) ?> &bull; <?= ucfirst($apt['appointment_type']) ?>
                                    </p>
                                    <p class="text-xs font-bold text-emerald-700">
                                        Total Bill: ₹<?= number_format($total, 2) ?> <?=$is_paid ? '(Paid ✓)' : '(Unpaid)' ?>
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="<?= $video_room_path ?>?room=<?= $apt['id'] ?>&role=patient" target="_blank" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs flex items-center gap-1 shadow-sm transition">
                                        <span>📹</span> Video Call
                                    </a>

                                    <a href="<?= $chat_path ?>?appointment_id=<?= $apt['id'] ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs flex items-center gap-1 shadow-sm transition">
                                        <span>💬</span> Chat
                                    </a>

                                    <?php if (!$is_paid): ?>
                                        <button onclick="openPayModal(<?= $apt['id'] ?>, <?=$c_fee ?>, <?= $m_fee ?>, <?= $total ?>)" class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-extrabold text-xs shadow-sm transition">
                                            💳 Pay
                                        </button>
                                    <?php endif; ?>

                                    <button onclick="viewRxSlip(<?= $apt['id'] ?>)" class="px-3.5 py-2 rounded-xl bg-white border border-slate-300 font-bold text-xs text-slate-700 hover:bg-slate-50 transition">
                                        📄 Slip
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Available Doctors & Online Specialists -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 shadow-sm mb-10">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 tracking-tight">Available Doctors & Online Specialists</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Verified physicians currently available for online consultations</p>
                </div>
                <span class="px-3 py-1 bg-emerald-50 text-emerald-700 text-xs font-extrabold rounded-full border border-emerald-200">
                    ● Accepting Bookings
                </span>
            </div>

            <?php if (empty($available_doctors)): ?>
                <div class="text-center py-10 text-slate-400">
                    <span class="text-4xl block mb-2">🩺</span>
                    <p class="text-xs font-semibold">No doctors are currently available. Please check back shortly.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($available_doctors as$doc): ?>
                        <div class="p-5 rounded-2xl border border-slate-200/80 bg-slate-50/60 hover:bg-white hover:shadow-md transition flex flex-col justify-between">
                            <div class="space-y-2">
                                <div class="flex items-start justify-between">
                                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 border border-indigo-100 text-indigo-600 flex items-center justify-center text-xl font-bold">
                                        👨‍⚕️
                                    </div>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                        Available
                                    </span>
                                </div>

                                <div>
                                    <h3 class="text-base font-extrabold text-slate-900">Dr. <?= safe_p($doc['name']) ?></h3>
                                    <p class="text-xs font-bold text-indigo-600"><?= safe_p($doc['specialization'] ?: 'General Practice') ?></p>
                                    <p class="text-[11px] text-slate-400 mt-0.5"><?= safe_p($doc['qualification'] ?: 'MBBS, MD') ?></p>
                                </div>

                                <div class="pt-2 border-t border-slate-200/60 flex items-center justify-between text-xs">
                                    <span class="text-slate-500 font-semibold">Checking Fee</span>
                                    <span class="font-extrabold text-slate-900">₹<?= number_format($doc['consultation_fee'] > 0 ?$doc['consultation_fee'] : 1000.0, 2) ?></span>
                                </div>
                            </div>

                            <div class="pt-4">
                                <a href="book-appointment.php?doctor_id=<?= $doc['id'] ?>" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs rounded-xl shadow-sm text-center block transition">
                                    Book Consultation
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Modal: Electronic Prescription Slip -->
    <div id="rxSlipModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 border border-slate-200 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-200 pb-4 mb-4">
                <span class="text-xs font-extrabold uppercase tracking-wider text-emerald-700">Official Electronic Prescription</span>
                <div class="flex items-center gap-2">
                    <button onclick="window.print()" class="text-xs font-bold px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">🖨️ Print</button>
                    <button onclick="closeModal('rxSlipModal')" class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
                </div>
            </div>

            <div id="rxSlipContent" class="space-y-4 text-xs"></div>
        </div>
    </div>

    <!-- Modal: Instant UPI Payment Gateway -->
    <div id="payModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 border border-slate-200 shadow-2xl text-center">
            <h2 class="text-lg font-extrabold text-slate-900 mb-1">Instant UPI Payment</h2>
            <p class="text-xs text-slate-500 mb-4">Scan QR code using Paytm, Google Pay, or PhonePe</p>

            <img id="upiQrImg" src="" alt="UPI QR Code" class="w-48 h-48 mx-auto rounded-2xl border border-slate-200 p-2 mb-3">
            
            <p class="text-xs font-mono font-bold text-indigo-600 mb-4">er.ansh.soni.021-1@oksbi</p>

            <form id="payForm" onsubmit="submitPayment(event)" class="space-y-3 text-left">
                <input type="hidden" name="action" value="process_payment">
                <input type="hidden" name="appointment_id" id="payApptId">
                <input type="hidden" name="consultation_fee" id="payConsultFee">
                <input type="hidden" name="medicine_fee" id="payMedFee">
                <input type="hidden" name="amount" id="payAmount">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">UPI UTR / Reference ID (Optional)</label>
                    <input type="text" name="utr_ref" placeholder="12-digit UTR Number" class="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs">
                </div>

                <div class="pt-2 flex gap-2">
                    <button type="button" onclick="closeModal('payModal')" class="w-1/2 py-2.5 rounded-xl bg-slate-100 font-bold text-xs text-slate-600">Cancel</button>
                    <button type="submit" class="w-1/2 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs shadow-md">Confirm Paid</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Profile & Medical History -->
    <div id="profileModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 border border-slate-200 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
                <h2 class="text-lg font-extrabold text-slate-900">Update Profile & Vitals</h2>
                <button onclick="closeModal('profileModal')" class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>

            <form id="patientProfileForm" onsubmit="submitPatientProfile(event)" class="space-y-3">
                <input type="hidden" name="action" value="update_profile">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Full Name</label>
                    <input type="text" name="name" value="<?= safe_p($patient['name']) ?>" required class="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Age</label>
                        <input type="number" name="age" value="<?= safe_p($patient['age']) ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Blood Type</label>
                        <input type="text" name="blood_type" value="<?= safe_p($patient['blood_type']) ?>" placeholder="e.g. O+" class="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Phone Contact</label>
                    <input type="text" name="contact" value="<?= safe_p($patient['contact']) ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-rose-700 uppercase mb-1">⚠️ Drug / Food Allergies</label>
                    <textarea name="allergies" rows="2" class="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs" placeholder="Penicillin, Peanuts, Sulfa drugs..."><?= safe_p($patient['allergies']) ?></textarea>
                </div>

                <div class="pt-3 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('profileModal')" class="px-4 py-2 rounded-xl bg-slate-100 font-bold text-xs text-slate-600">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs shadow-md">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; <?= date('Y') ?> TeleMed Healthcare Platform. All rights reserved.
    </footer>

    <script>
        function openProfileModal() { document.getElementById('profileModal').classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function openPayModal(apptId, cFee, mFee, total) {
            document.getElementById('payApptId').value = apptId;
            document.getElementById('payConsultFee').value = cFee;
            document.getElementById('payMedFee').value = mFee;
            document.getElementById('payAmount').value = total;

            const upiId = "er.ansh.soni.021-1@oksbi";
            const upiUrl = `upi://pay?pa=${encodeURIComponent(upiId)}&pn=TeleMed&am=${total.toFixed(2)}&cu=INR&tn=Consultation_${apptId}`;
            document.getElementById('upiQrImg').src = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(upiUrl)}`;

            document.getElementById('payModal').classList.remove('hidden');
        }

        function submitPayment(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('payForm'));
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        }

        function submitPatientProfile(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('patientProfileForm'));
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        }

        function viewRxSlip(apptId) {
            fetch(`?get_rx_details=1&appointment_id=${apptId}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        alert(data.message);
                        return;
                    }
                    const d = data.details;
                    const items = data.items;

                    let html = `
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-base font-extrabold text-slate-900">Dr. ${d.doctor_name}</h3>
                            <p class="text-slate-500">${d.qualification} - ${d.specialization}</p>
                            <p class="text-slate-400">${d.clinic_address}</p>
                        </div>
                        <div class="flex justify-between py-2 border-b border-slate-100 font-semibold">
                            <span>Patient: <strong>${d.patient_name}</strong></span>
                            <span>Date: ${d.appointment_date}</span>
                        </div>
                        <div class="py-1">
                            <strong class="text-indigo-600">Diagnosis:</strong> ${items.length > 0 ? items[0].diagnosis : 'Clinical Consultation'}
                        </div>
                        <div class="py-2">
                            <p class="font-bold mb-1">Prescribed Medicines:</p>
                            <ul class="list-disc pl-5 space-y-1">
                                ${items.map(m => `<li><strong>${m.medication}</strong> - ${m.dosage} (${m.duration})</li>`).join('')}
                            </ul>
                        </div>
                        <div class="mt-4 p-3 bg-slate-50 rounded-xl border border-slate-200">
                            <p class="font-mono text-center text-slate-500">Hash Verification: ${data.verification_code}</p>
                        </div>
                    `;

                    document.getElementById('rxSlipContent').innerHTML = html;
                    document.getElementById('rxSlipModal').classList.remove('hidden');
                });
        }
    </script>
</body>
</html>