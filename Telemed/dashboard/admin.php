<?php
// Clear output buffers to prevent header redirect errors
if (ob_get_level()) {
    ob_end_clean();
}

// -------------------------------------------------------------
// 1. SELF-CONTAINED LOGOUT HANDLER (NO EXTERNAL FILE NEEDED)
// -------------------------------------------------------------
if (isset($_GET['logout']) || (isset($_GET['action']) && $_GET['action'] === 'logout')) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    session_unset();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    // Auto-resolve redirect to login.php
    if (file_exists(__DIR__ . '/login.php')) {
        header("Location: login.php");
    } elseif (file_exists(__DIR__ . '/../login.php')) {
        header("Location: ../login.php");
    } else {
        header("Location: /Telemed/login.php");
    }
    exit;
}

// Dynamic include path resolver
if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
}

require_login();
if (!is_admin()) {
    header('Location: login.php');
    exit;
}

$current_user = current_user();

// -------------------------------------------------------------
// AUTO-MIGRATIONS: Guaranteed to prevent column missing errors
// -------------------------------------------------------------
try { $db->exec("ALTER TABLE users ADD COLUMN is_approved INTEGER DEFAULT 1"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN approval_status TEXT DEFAULT 'approved'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN contact TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN address TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN contact TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN qualification TEXT DEFAULT 'MBBS, MD'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN experience_years INTEGER DEFAULT 5"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN consultation_fee REAL DEFAULT 1000.0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN clinic_address TEXT DEFAULT 'Room 104, OPD Block A'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN bio TEXT DEFAULT 'Dedicated physician'"); } catch (Throwable $e) {}

$success_msg = '';
$error_msg = '';

// Active Tab ('patients' or 'doctors')
$current_tab = isset($_GET['tab']) && $_GET['tab'] === 'doctors' ? 'doctors' : 'patients';

// -------------------------------------------------------------
// POST ACTIONS: REGISTER PATIENT, REGISTER DOCTOR, APPROVALS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // 1. REGISTER NEW PATIENT
    if ($_POST['action'] === 'register_patient') {
        $username       = trim($_POST['username'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $password       = trim($_POST['password'] ?? '');
        $full_name      = trim($_POST['full_name'] ?? '');
        $contact        = trim($_POST['contact'] ?? '');
        $age            = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $gender         = trim($_POST['gender'] ?? 'male');
        $initial_status = (int)($_POST['approval_status'] ?? 1);

        if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
            $error_msg = "Please fill in all required patient fields.";
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error_msg = "A user with this username or email already exists.";
            } else {
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $status_text = ($initial_status === 1) ? 'approved' : 'pending';

                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("
                        INSERT INTO users (username, email, password, role, is_approved, approval_status, created_at)
                        VALUES (?, ?, ?, 'patient', ?, ?, datetime('now'))
                    ");
                    $stmt->execute([$username, $email, $hashed_pw, $initial_status, $status_text]);
                    $new_user_id = $db->lastInsertId();

                    $stmt = $db->prepare("
                        INSERT INTO patients (user_id, name, contact, age, gender, created_at)
                        VALUES (?, ?, ?, ?, ?, datetime('now'))
                    ");
                    $stmt->execute([$new_user_id, $full_name, $contact, $age, $gender]);

                    $db->commit();
                    $success_msg = "New patient '$full_name' registered successfully with status: " . strtoupper($status_text) . "!";
                } catch (Throwable $e) {
                    $db->rollBack();
                    $error_msg = "Error registering patient: " . $e->getMessage();
                }
            }
        }
    }

    // 2. REGISTER NEW DOCTOR (WITH FULL SPECIALIZATION LIST)
    if ($_POST['action'] === 'register_doctor') {
        $username         = trim($_POST['username'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $password         = trim($_POST['password'] ?? '');
        $doctor_name      = trim($_POST['name'] ?? '');
        $specialization   = trim($_POST['specialization'] ?? 'Family Physician');
        $qualification    = trim($_POST['qualification'] ?? 'MBBS, MD');
        $experience_years = max(0, (int)($_POST['experience_years'] ?? 5));
        $consultation_fee = max(0, (float)($_POST['consultation_fee'] ?? 1000.0));
        $clinic_address   = trim($_POST['clinic_address'] ?? 'Room 104, OPD Block A');
        $contact          = trim($_POST['contact'] ?? '');
        $initial_status   = (int)($_POST['approval_status'] ?? 1);

        if (empty($username) || empty($email) || empty($password) || empty($doctor_name)) {
            $error_msg = "Please fill in all required doctor fields.";
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error_msg = "A doctor account with this username or email already exists.";
            } else {
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $status_text = ($initial_status === 1) ? 'approved' : 'pending';

                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("
                        INSERT INTO users (username, email, password, role, is_approved, approval_status, created_at)
                        VALUES (?, ?, ?, 'doctor', ?, ?, datetime('now'))
                    ");
                    $stmt->execute([$username, $email, $hashed_pw, $initial_status, $status_text]);
                    $new_user_id = $db->lastInsertId();

                    $stmt = $db->prepare("
                        INSERT INTO doctors 
                        (user_id, name, specialization, qualification, experience_years, consultation_fee, clinic_address, contact, is_available, is_offline_available, rating, total_consultations)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 5.0, 0)
                    ");
                    $stmt->execute([
                        $new_user_id, $doctor_name, $specialization, $qualification, 
                        $experience_years, $consultation_fee, $clinic_address, $contact
                    ]);

                    $db->commit();
                    $success_msg = "New Doctor 'Dr. $doctor_name' registered successfully with status: " . strtoupper($status_text) . "!";
                } catch (Throwable $e) {
                    $db->rollBack();
                    $error_msg = "Error registering doctor: " . $e->getMessage();
                }
            }
        }
    }

    // 3. APPROVE USER
    if ($_POST['action'] === 'approve_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $db->prepare("UPDATE users SET is_approved = 1, approval_status = 'approved' WHERE id = ?");
            $stmt->execute([$user_id]);
            $success_msg = "Account #$user_id approved successfully! Login access granted.";
        }
    }

    // 4. REJECT USER
    if ($_POST['action'] === 'reject_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $db->prepare("UPDATE users SET is_approved = -1, approval_status = 'rejected' WHERE id = ?");
            $stmt->execute([$user_id]);
            $success_msg = "Account #$user_id rejected. Login access blocked.";
        }
    }

    // 5. DELETE USER
    if ($_POST['action'] === 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $db->prepare("DELETE FROM patients WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $db->prepare("DELETE FROM doctors WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $success_msg = "Account #$user_id deleted successfully.";
        }
    }
}

// -------------------------------------------------------------
// DATA FETCHING & COUNTERS
// -------------------------------------------------------------
// Patients List
$stmt = $db->query("
    SELECT u.id as user_id, u.username, u.email, u.created_at as registered_at,
           COALESCE(u.is_approved, 1) as is_approved,
           COALESCE(u.approval_status, 'approved') as approval_status,
           p.id as patient_id, p.name as patient_name, p.contact, p.age, p.gender,
           (SELECT COUNT(*) FROM appointments WHERE patient_id = p.id) as total_appointments
    FROM users u
    LEFT JOIN patients p ON p.user_id = u.id
    WHERE u.role = 'patient'
    ORDER BY (CASE WHEN COALESCE(u.is_approved, 1) = 0 THEN 0 ELSE 1 END), u.created_at DESC
");
$patients_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Doctors List
$stmt = $db->query("
    SELECT u.id as user_id, u.username, u.email, u.created_at as registered_at,
           COALESCE(u.is_approved, 1) as is_approved,
           COALESCE(u.approval_status, 'approved') as approval_status,
           d.id as doctor_id, d.name as doctor_name, d.specialization, d.qualification,
           d.experience_years, d.consultation_fee, d.clinic_address, d.contact,
           (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id) as total_appointments
    FROM users u
    LEFT JOIN doctors d ON d.user_id = u.id
    WHERE u.role = 'doctor'
    ORDER BY (CASE WHEN COALESCE(u.is_approved, 1) = 0 THEN 0 ELSE 1 END), u.created_at DESC
");
$doctors_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counters
$total_patients   = count($patients_list);
$pending_patients = count(array_filter($patients_list, function($p) { return (int)$p['is_approved'] === 0; }));

$total_doctors    = count($doctors_list);
$pending_doctors  = count(array_filter($doctors_list, function($d) { return (int)$d['is_approved'] === 0; }));

function safe($val, $def = '') {
    return htmlspecialchars($val ?? $def, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Register Patients & Doctors</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800">

    <!-- Top Admin Header -->
    <header class="bg-indigo-900 text-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🛡️</span>
                <div>
                    <h1 class="text-lg font-bold tracking-tight">Telemed Admin Portal</h1>
                    <span class="text-xs text-indigo-200">User Approvals & Registrations</span>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm font-semibold text-indigo-100">Admin: <?= safe($current_user['username']) ?></span>
                <!-- In-file direct logout trigger (?logout=1) -->
                <a href="?logout=1" class="px-3 py-1.5 bg-indigo-800 hover:bg-indigo-700 text-xs font-bold rounded-lg border border-indigo-600 transition">Logout</a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <?php if (!empty($success_msg)): ?>
            <div class="mb-6 p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-xl shadow-sm flex items-center justify-between">
                <div><strong>Success:</strong> <?= safe($success_msg) ?></div>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 font-bold">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="mb-6 p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 rounded-xl shadow-sm flex items-center justify-between">
                <div><strong>Error:</strong> <?= safe($error_msg) ?></div>
                <button onclick="this.parentElement.remove()" class="text-rose-600 font-bold">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($pending_patients > 0 || $pending_doctors > 0): ?>
            <div class="mb-6 p-5 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-2xl shadow-lg flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">⏳</span>
                    <div>
                        <h2 class="text-lg font-extrabold">Pending Approval Queue</h2>
                        <p class="text-xs text-amber-100 mt-0.5">
                            <?= $pending_patients ?> Patient(s) and <?= $pending_doctors ?> Doctor(s) are waiting for your approval before they can log in.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm text-center">
                <div class="text-2xl font-black text-slate-800"><?= $total_patients ?></div>
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500 mt-1">Total Patients</div>
            </div>
            <div class="bg-white p-5 rounded-2xl border-2 border-amber-400 shadow-sm text-center bg-amber-50/30">
                <div class="text-2xl font-black text-amber-600"><?= $pending_patients ?></div>
                <div class="text-xs font-bold uppercase tracking-wider text-amber-700 mt-1">Pending Patients</div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm text-center">
                <div class="text-2xl font-black text-indigo-700"><?= $total_doctors ?></div>
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500 mt-1">Total Doctors</div>
            </div>
            <div class="bg-white p-5 rounded-2xl border-2 border-amber-400 shadow-sm text-center bg-amber-50/30">
                <div class="text-2xl font-black text-amber-600"><?= $pending_doctors ?></div>
                <div class="text-xs font-bold uppercase tracking-wider text-amber-700 mt-1">Pending Doctors</div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2">
                <a href="?tab=patients" class="px-5 py-2.5 rounded-xl text-xs font-extrabold uppercase tracking-wider transition-all <?= $current_tab === 'patients' ? 'bg-indigo-600 text-white shadow' : 'bg-white text-slate-600 hover:bg-slate-200 border border-slate-200' ?>">
                    👥 Manage Patients (<?= $total_patients ?>)
                </a>
                <a href="?tab=doctors" class="px-5 py-2.5 rounded-xl text-xs font-extrabold uppercase tracking-wider transition-all <?= $current_tab === 'doctors' ? 'bg-indigo-600 text-white shadow' : 'bg-white text-slate-600 hover:bg-slate-200 border border-slate-200' ?>">
                    👨‍⚕️ Manage Doctors (<?= $total_doctors ?>)
                </a>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('registerPatientModal').classList.remove('hidden')" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold uppercase tracking-wider rounded-xl shadow flex items-center gap-1.5">
                    <span>➕ Register Patient</span>
                </button>
                <button onclick="document.getElementById('registerDoctorModal').classList.remove('hidden')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-extrabold uppercase tracking-wider rounded-xl shadow flex items-center gap-1.5">
                    <span>➕ Register Doctor</span>
                </button>
            </div>
        </div>

        <!-- TAB 1: PATIENTS MANAGEMENT -->
        <?php if ($current_tab === 'patients'): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-4 bg-slate-50 border-b border-slate-200 font-bold text-sm text-slate-700 flex justify-between items-center">
                    <span>Registered Patients (Clients)</span>
                    <span class="text-xs font-normal text-slate-500">Unapproved patients cannot log in</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-100 text-xs font-bold uppercase tracking-wider text-slate-600">
                            <tr>
                                <th class="py-3 px-4"># User ID</th>
                                <th class="py-3 px-4">Patient Name</th>
                                <th class="py-3 px-4">Login Account</th>
                                <th class="py-3 px-4">Contact</th>
                                <th class="py-3 px-4">Approval Status</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($patients_list)): ?>
                                <tr><td colspan="6" class="py-8 text-center text-slate-400 italic">No patients registered yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($patients_list as $p): 
                                    $status = (int)$p['is_approved'];
                                ?>
                                    <tr class="hover:bg-slate-50 transition-colors <?= $status === 0 ? 'bg-amber-50/40' : '' ?>">
                                        <td class="py-4 px-4 font-bold text-slate-500">#<?= $p['user_id'] ?></td>
                                        <td class="py-4 px-4">
                                            <div class="font-bold text-slate-900 text-base"><?= safe($p['patient_name'] ?: $p['username']) ?></div>
                                            <div class="text-xs text-slate-500"><?= $p['age'] ? $p['age'] . ' yrs • ' : '' ?><?= ucfirst($p['gender'] ?? 'N/A') ?></div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="font-semibold text-slate-800"><code><?= safe($p['username']) ?></code></div>
                                            <div class="text-xs text-slate-500"><?= safe($p['email']) ?></div>
                                        </td>
                                        <td class="py-4 px-4 text-xs font-semibold text-slate-700">
                                            <?= safe($p['contact'] ?: 'Not Provided') ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <?php if ($status === 1): ?>
                                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">✅ Approved</span>
                                            <?php elseif ($status === 0): ?>
                                                <span class="px-2.5 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800 border border-amber-300 animate-pulse">⏳ Pending</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-300">❌ Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 px-4 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <?php if ($status !== 1): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="approve_user">
                                                        <input type="hidden" name="user_id" value="<?= $p['user_id'] ?>">
                                                        <button type="submit" class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold">✅ Approve</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($status !== -1): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="reject_user">
                                                        <input type="hidden" name="user_id" value="<?= $p['user_id'] ?>">
                                                        <button type="submit" class="px-2.5 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-bold">❌ Reject</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Permanently delete this patient?');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $p['user_id'] ?>">
                                                    <button type="submit" class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg text-xs font-bold">🗑️</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 2: DOCTORS MANAGEMENT -->
        <?php if ($current_tab === 'doctors'): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-4 bg-slate-50 border-b border-slate-200 font-bold text-sm text-slate-700 flex justify-between items-center">
                    <span>Registered Physicians & Specialists</span>
                    <span class="text-xs font-normal text-slate-500">Unapproved doctors cannot log into doctor portal</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-100 text-xs font-bold uppercase tracking-wider text-slate-600">
                            <tr>
                                <th class="py-3 px-4"># Doctor ID</th>
                                <th class="py-3 px-4">Doctor Name & Specialization</th>
                                <th class="py-3 px-4">Login Account</th>
                                <th class="py-3 px-4">Checking Fee</th>
                                <th class="py-3 px-4">Approval Status</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($doctors_list)): ?>
                                <tr><td colspan="6" class="py-8 text-center text-slate-400 italic">No doctors registered yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($doctors_list as $d): 
                                    $status = (int)$d['is_approved'];
                                ?>
                                    <tr class="hover:bg-slate-50 transition-colors <?= $status === 0 ? 'bg-amber-50/40' : '' ?>">
                                        <td class="py-4 px-4 font-bold text-slate-500">#<?= $d['user_id'] ?></td>
                                        <td class="py-4 px-4">
                                            <div class="font-bold text-slate-900 text-base">Dr. <?= safe($d['doctor_name']) ?></div>
                                            <div class="text-xs font-semibold text-emerald-700"><?= safe($d['specialization']) ?> &bull; <?= safe($d['qualification']) ?></div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="font-semibold text-slate-800"><code><?= safe($d['username']) ?></code></div>
                                            <div class="text-xs text-slate-500"><?= safe($d['email']) ?></div>
                                        </td>
                                        <td class="py-4 px-4 font-extrabold text-emerald-600">
                                            ₹<?= number_format((float)($d['consultation_fee'] > 0 ? $d['consultation_fee'] : 1000.0), 2) ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <?php if ($status === 1): ?>
                                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">✅ Approved</span>
                                            <?php elseif ($status === 0): ?>
                                                <span class="px-2.5 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800 border border-amber-300 animate-pulse">⏳ Pending</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-300">❌ Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 px-4 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <?php if ($status !== 1): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="approve_user">
                                                        <input type="hidden" name="user_id" value="<?= $d['user_id'] ?>">
                                                        <button type="submit" class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold">✅ Approve</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($status !== -1): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="reject_user">
                                                        <input type="hidden" name="user_id" value="<?= $d['user_id'] ?>">
                                                        <button type="submit" class="px-2.5 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-bold">❌ Reject</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Permanently delete this doctor profile?');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $d['user_id'] ?>">
                                                    <button type="submit" class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg text-xs font-bold">🗑️</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- -------------------------------------------------------------
         MODAL 1: REGISTER NEW PATIENT
    -------------------------------------------------------------- -->
    <div id="registerPatientModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200">
            <div class="bg-emerald-800 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-bold text-base">➕ Register New Patient (Client)</h3>
                <button onclick="document.getElementById('registerPatientModal').classList.add('hidden')" class="text-slate-300 hover:text-white text-xl font-bold">&times;</button>
            </div>
            
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="register_patient">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Patient Full Name *</label>
                    <input type="text" name="full_name" placeholder="e.g. Ramesh Patel" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Username *</label>
                        <input type="text" name="username" placeholder="ramesh21" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Contact Phone</label>
                        <input type="text" name="contact" placeholder="+91 9876543210" class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Email Address *</label>
                    <input type="email" name="email" placeholder="patient@example.com" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none">
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Password *</label>
                        <input type="password" name="password" placeholder="Password" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Age</label>
                        <input type="number" min="1" max="120" name="age" placeholder="25" class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Gender</label>
                        <select name="gender" class="w-full px-3 py-2 border rounded-xl text-sm focus:border-emerald-600 focus:outline-none bg-white">
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Initial Approval Status *</label>
                    <select name="approval_status" class="w-full px-3 py-2 border rounded-xl text-sm font-semibold focus:border-emerald-600 focus:outline-none bg-white">
                        <option value="1">✅ Approve Immediately (Allow Immediate Login)</option>
                        <option value="0">⏳ Keep as Pending (Approval Required Before Login)</option>
                    </select>
                </div>

                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" onclick="document.getElementById('registerPatientModal').classList.add('hidden')" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold uppercase rounded-xl shadow">Save Patient</button>
                </div>
            </form>
        </div>
    </div>

    <!-- -------------------------------------------------------------
         MODAL 2: REGISTER NEW DOCTOR (WITH FULL SPECIALIZATION LIST)
    -------------------------------------------------------------- -->
    <div id="registerDoctorModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full overflow-hidden border border-slate-200">
            <div class="bg-indigo-900 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-bold text-base">➕ Register New Doctor</h3>
                <button onclick="document.getElementById('registerDoctorModal').classList.add('hidden')" class="text-slate-300 hover:text-white text-xl font-bold">&times;</button>
            </div>
            
            <form method="POST" class="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
                <input type="hidden" name="action" value="register_doctor">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Doctor Full Name (with Dr.) *</label>
                    <input type="text" name="name" placeholder="e.g. Dr. Rajesh Sharma" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Username *</label>
                        <input type="text" name="username" placeholder="drrajesh" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Contact Phone</label>
                        <input type="text" name="contact" placeholder="+91 9876543210" class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Doctor Email *</label>
                        <input type="email" name="email" placeholder="doctor@clinic.com" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Password *</label>
                        <input type="password" name="password" placeholder="Create doctor password" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Specialization *</label>
                        <select name="specialization" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none bg-white">
                            <option value="" disabled selected>Select Specialization</option>
                            <optgroup label="Primary Care Doctors">
                                <option value="Family Physician">Family Physician (All ages)</option>
                                <option value="Internist">Internist (Adults)</option>
                                <option value="Pediatrician">Pediatrician (Infants & Children)</option>
                            </optgroup>
                            <optgroup label="Medical Specialists">
                                <option value="Allergist/Immunologist">Allergist / Immunologist</option>
                                <option value="Cardiologist">Cardiologist (Heart)</option>
                                <option value="Dermatologist">Dermatologist (Skin, Hair, Nails)</option>
                                <option value="Endocrinologist">Endocrinologist (Hormones, Thyroid)</option>
                                <option value="Gastroenterologist">Gastroenterologist (Digestion, Stomach)</option>
                                <option value="Nephrologist">Nephrologist (Kidneys)</option>
                                <option value="Neurologist">Neurologist (Brain & Spine)</option>
                                <option value="Oncologist">Oncologist (Cancer)</option>
                                <option value="Ophthalmologist">Ophthalmologist (Eyes)</option>
                                <option value="Otolaryngologist (ENT)">Otolaryngologist (ENT)</option>
                                <option value="Pulmonologist">Pulmonologist (Lungs)</option>
                                <option value="Rheumatologist">Rheumatologist (Joints & Muscles)</option>
                            </optgroup>
                            <optgroup label="Surgical Specialists">
                                <option value="Cardiothoracic Surgeon">Cardiothoracic Surgeon</option>
                                <option value="General Surgeon">General Surgeon</option>
                                <option value="Neurosurgeon">Neurosurgeon</option>
                                <option value="Orthopaedic Surgeon">Orthopaedic Surgeon</option>
                                <option value="Plastic Surgeon">Plastic Surgeon</option>
                            </optgroup>
                            <optgroup label="Reproductive & Urinary">
                                <option value="Gynecologist">Gynecologist</option>
                                <option value="Obstetrician (OB)">Obstetrician (OB)</option>
                                <option value="Urologist">Urologist</option>
                            </optgroup>
                            <optgroup label="Mental & Behavioral Health">
                                <option value="Neuropsychologist">Neuropsychologist</option>
                                <option value="Psychiatrist">Psychiatrist</option>
                            </optgroup>
                            <optgroup label="Diagnostics & Support">
                                <option value="Anesthesiologist">Anesthesiologist</option>
                                <option value="Pathologist">Pathologist</option>
                                <option value="Radiologist">Radiologist</option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Qualifications *</label>
                        <input type="text" name="qualification" placeholder="e.g. MBBS, MD" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Checking Fee (INR ₹) *</label>
                        <input type="number" step="50" min="100" name="consultation_fee" value="1000.00" required class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Experience (Years)</label>
                        <input type="number" min="0" max="60" name="experience_years" value="5" class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">OPD Chamber Address</label>
                    <input type="text" name="clinic_address" placeholder="Room 104, OPD Block A, City Clinic" class="w-full px-3 py-2 border rounded-xl text-sm focus:border-indigo-600 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Initial Approval Status *</label>
                    <select name="approval_status" class="w-full px-3 py-2 border rounded-xl text-sm font-semibold focus:border-indigo-600 focus:outline-none bg-white">
                        <option value="1">✅ Approve Immediately (Allow Immediate Login)</option>
                        <option value="0">⏳ Keep as Pending (Approval Required Before Login)</option>
                    </select>
                </div>

                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" onclick="document.getElementById('registerDoctorModal').classList.add('hidden')" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-extrabold uppercase rounded-xl shadow">Save Doctor</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>