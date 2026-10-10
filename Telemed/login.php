<?php
// Prevent "Headers already sent" redirect errors
if (ob_get_level() === 0) {
    ob_start();
}

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Dynamic include path resolution
if (file_exists(__DIR__ . '/includes/db.php')) {
    require_once __DIR__ . '/includes/db.php';
} elseif (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
}

if (file_exists(__DIR__ . '/includes/auth.php')) {
    require_once __DIR__ . '/includes/auth.php';
} elseif (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
}

// Helper: Safely resolve authentication state without fatal error risks
function check_user_authenticated() {
    if (function_exists('is_logged_in') && is_logged_in()) {
        return true;
    }
    return !empty($_SESSION['user']) || !empty($_SESSION['user_id']);
}

// Helper: Safely resolve user role
function get_authenticated_role() {
    if (function_exists('is_admin') && is_admin()) return 'admin';
    if (function_exists('is_doctor') && is_doctor()) return 'doctor';
    
    $role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
    return strtolower(trim($role));
}

// Allow switching account via URL parameter (?switch=1)
$is_switching = isset($_GET['switch']) && $_GET['switch'] === '1';

// Redirect already logged-in users only if not explicitly switching
if (!$is_switching && check_user_authenticated()) {
    $current_role = get_authenticated_role();
    if ($current_role === 'admin') {
        header('Location: dashboard/admin.php');
        exit;
    } elseif ($current_role === 'doctor') {
        header('Location: dashboard/doctor.php');
        exit;
    } else {
        header('Location: dashboard/patient.php');
        exit;
    }
}

$error = '';
$input_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($input_username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } elseif (!isset($db) || !($db instanceof PDO)) {
        $error = 'Database connection error. Please ensure your SQLite database is accessible.';
    } else {
        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :u OR email = :u LIMIT 1");
            $stmt->execute([':u' => $input_username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
                // Check Admin Approval Status (1 = Approved, 0 = Pending, -1 = Rejected)
                $approval = isset($user['is_approved']) ? (int)$user['is_approved'] : 1;

                if ($approval === 0) {
                    $error = '⏳ Your account registration is pending admin approval.';
                } elseif ($approval === -1) {
                    $error = '❌ Your account request has been declined. Please contact clinic support.';
                } else {
                    // Reset existing session before writing new credentials
                    $_SESSION = [];
                    if (session_id()) {
                        session_regenerate_id(true);
                    }

                    // Authenticate user
                    if (function_exists('login_user')) {
                        login_user($user);
                    } else {
                        $_SESSION['user'] = $user;
                        $_SESSION['user_id'] = $user['id'] ?? null;
                        $_SESSION['role'] = strtolower($user['role'] ?? 'patient');
                    }

                    $role = strtolower($user['role'] ?? 'patient');

                    if ($role === 'admin') {
                        header('Location: dashboard/admin.php');
                    } elseif ($role === 'doctor') {
                        header('Location: dashboard/doctor.php');
                    } else {
                        header('Location: dashboard/patient.php');
                    }
                    exit;
                }
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            error_log('Login Error: ' . $e->getMessage());
            $error = 'An error occurred while logging in. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeleMed - Account Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-3xl shadow-xl border border-slate-200/80 p-8 sm:p-10">
        
        <!-- Brand Header -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center gap-2 mb-3">
                <span class="text-3xl">🏣</span>
                <span class="text-2xl font-extrabold text-indigo-600 tracking-tight">TeleMed</span>
            </a>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Welcome Back</h1>
            <p class="text-xs text-slate-500 mt-1">Sign in to your patient, doctor, or admin portal</p>
        </div>

        <!-- Active Session Banner -->
        <?php if (check_user_authenticated()): ?>
            <?php 
                $active_role = get_authenticated_role();
                $dash_link = ($active_role === 'admin') ? 'dashboard/admin.php' : (($active_role === 'doctor') ? 'dashboard/doctor.php' : 'dashboard/patient.php');
            ?>
            <div class="mb-5 p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
                <p class="font-bold">⚠️ An account is currently active in this browser.</p>
                <p class="mt-1 text-slate-600">Logging in below will replace the active session with the new account.</p>
                <div class="mt-2.5">
                    <a href="<?= htmlspecialchars($dash_link, ENT_QUOTES, 'UTF-8') ?>" class="font-bold text-indigo-600 hover:underline">
                        &rarr; Continue to Current Dashboard
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (!empty($error)): ?>
            <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold leading-relaxed">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php?switch=1" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Username or Email</label>
                <input
                    type="text"
                    name="username"
                    value="<?= htmlspecialchars($input_username, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Enter your username or email"
                    autocomplete="username"
                    required
                    class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none transition"
                >
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
                <input
                    type="password"
                    name="password"
                    placeholder="Enter your account password"
                    autocomplete="current-password"
                    required
                    class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none transition"
                >
            </div>

            <div class="pt-2">
                <button
                    type="submit"
                    class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-sm uppercase tracking-wider rounded-xl shadow-md transition transform hover:-translate-y-0.5"
                >
                    Sign In to Portal
                </button>
            </div>
        </form>

        <!-- Footer Links -->
        <div class="mt-8 pt-6 border-t border-slate-100 text-center text-xs text-slate-500 space-y-2">
            <div>
                New to TeleMed? 
                <a href="register.php?role=patient" class="text-indigo-600 font-bold hover:underline">Register as Patient</a>
                or 
                <a href="register.php?role=doctor" class="text-emerald-600 font-bold hover:underline">Join as Doctor</a>
            </div>
            <div>
                <a href="index.php" class="text-slate-400 hover:text-slate-600 font-semibold">&larr; Return to Homepage</a>
            </div>
        </div>

    </div>

</body>
</html>