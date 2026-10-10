<?php
// 1. Output buffering prevents "Headers already sent" redirect errors
if (ob_get_level() === 0) {
    ob_start();
}

// 2. Ensure session is active before checking login state
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Dynamic include path resolver
if (file_exists(__DIR__ . '/includes/auth.php')) {
    require_once __DIR__ . '/includes/auth.php';
} elseif (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
}

// 4. Safe role detection (prevents fatal crashes if helper functions are absent)
if (function_exists('is_logged_in') && is_logged_in()) {
    $user_role = '';

    if (function_exists('current_user')) {
        $user_data = current_user();
        $user_role = strtolower($user_data['role'] ?? '');
    } elseif (isset($_SESSION['user']['role'])) {
        $user_role = strtolower($_SESSION['user']['role']);
    } elseif (isset($_SESSION['role'])) {
        $user_role = strtolower($_SESSION['role']);
    }

    $is_admin_user  = (function_exists('is_admin') && is_admin())   || ($user_role === 'admin');
    $is_doctor_user = (function_exists('is_doctor') && is_doctor()) || ($user_role === 'doctor');

    if ($is_admin_user) {
        header('Location: dashboard/admin.php');
        exit;
    } elseif ($is_doctor_user) {
        header('Location: dashboard/doctor.php');
        exit;
    } else {
        header('Location: dashboard/patient.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="TeleMed is a modern digital clinic platform offering real-time 30-minute slot scheduling, clinical charting, and digital verified prescriptions.">
    
    <!-- Google Search Console Verification Meta Tag (Replace with your token when verifying) -->
    <!-- <meta name="google-site-verification" content="YOUR_GOOGLE_VERIFICATION_TOKEN_HERE"> -->

    <title>TeleMed - Modern Telehealth Consultations & E-Prescriptions</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col justify-between">

    <!-- Top Navigation Bar -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            
            <!-- Brand Logo -->
            <a href="index.php" class="flex items-center gap-2.5">
                <span class="text-3xl">🏣</span>
                <span class="text-2xl font-extrabold text-indigo-600 tracking-tight">TeleMed</span>
            </a>

            <!-- Right Actions -->
            <div class="flex items-center gap-2 sm:gap-4">
                <a href="login.php" class="text-xs sm:text-sm font-bold text-slate-600 hover:text-indigo-600 transition px-2 py-1">
                    Login
                </a>
                <a href="register.php?role=doctor" class="text-xs font-bold uppercase tracking-wider text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3 py-2 rounded-xl transition">
                    👨‍⚕️ Join as Doctor
                </a>
                <a href="register.php?role=patient" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs sm:text-sm font-bold px-3.5 sm:px-5 py-2 sm:py-2.5 rounded-xl shadow-sm transition">
                    Register
                </a>
            </div>
        </div>
    </header>

    <!-- Main Hero Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-16 my-auto w-full">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
            
            <!-- Left Hero Content -->
            <div class="lg:col-span-7 space-y-6">
                
                <!-- Tag Badge -->
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-emerald-50 border border-emerald-200/80 text-emerald-700 text-xs font-bold tracking-wide">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    Telemedicine & Digital Clinic Platform
                </div>

                <!-- Main Headline -->
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 leading-[1.15] tracking-tight">
                    Modern Telehealth <br>
                    <span class="text-indigo-600">Consultations & E-Prescriptions</span>
                </h1>

                <!-- Subtitle -->
                <p class="text-slate-600 text-base sm:text-lg leading-relaxed max-w-xl">
                    Schedule live doctor appointments with real-time 30-minute slot availability, consult via secure WebRTC video or clinical chat, and download verified digital Rx slips.
                </p>

                <!-- Action Button Group -->
                <div class="flex flex-wrap items-center gap-3.5 pt-2">
                    <a href="register.php?role=patient" class="px-6 py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm sm:text-base rounded-xl shadow-sm transition transform hover:-translate-y-0.5">
                        Register as Patient
                    </a>

                    <a href="register.php?role=doctor" class="px-6 py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm sm:text-base rounded-xl shadow-sm transition transform hover:-translate-y-0.5 flex items-center gap-2">
                        <span>👨‍⚕️</span>
                        <span>Register as Doctor</span>
                    </a>

                    <a href="login.php" class="px-6 py-3.5 bg-white hover:bg-slate-50 text-slate-700 font-bold text-sm sm:text-base rounded-xl border border-slate-300 transition">
                        Sign In
                    </a>
                </div>

                <!-- Core Benefits Checklist -->
                <div class="flex flex-wrap items-center gap-y-2 gap-x-6 pt-4 text-xs sm:text-sm font-semibold text-slate-600">
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <span>✓</span> <span class="text-slate-700">Instant Slot Booking</span>
                    </span>
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <span>✓</span> <span class="text-slate-700">Verifiable Rx Slips</span>
                    </span>
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <span>✓</span> <span class="text-slate-700">UPI & Clinic Payments</span>
                    </span>
                </div>

            </div>

            <!-- Right Core Capabilities Card -->
            <div class="lg:col-span-5">
                <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-xl border border-slate-200/70 relative">
                    
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">Core Capabilities</h2>
                        <span class="px-3 py-1 bg-indigo-50 text-indigo-700 text-xs font-bold rounded-full border border-indigo-100">
                            Telemed Suite
                        </span>
                    </div>

                    <div class="space-y-4">
                        
                        <!-- Capability 1 -->
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex items-start gap-4">
                            <div class="text-2xl mt-0.5">🗓️</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Dynamic Slot Scheduling</h3>
                                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">
                                    Automated 30-min intervals, live capacity counts, and dual online/clinic consultation toggles.
                                </p>
                            </div>
                        </div>

                        <!-- Capability 2 -->
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex items-start gap-4">
                            <div class="text-2xl mt-0.5">🩺</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Split-Screen Clinical Charting</h3>
                                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">
                                    Inspect patient allergy warnings and chief complaints while compiling structured medication dosages.
                                </p>
                            </div>
                        </div>

                        <!-- Capability 3 -->
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex items-start gap-4">
                            <div class="text-2xl mt-0.5">📄</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Digital Prescription (Rx) Slips</h3>
                                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">
                                    Generate formal medical slips complete with electronic verification hashes and 1-click PDF download.
                                </p>
                            </div>
                        </div>

                        <!-- Capability 4 -->
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex items-start gap-4">
                            <div class="text-2xl mt-0.5">💳</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Integrated Payment Gateway</h3>
                                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">
                                    Instant checkout supporting UPI QR code, Debit/Credit Card, Net Banking, and Pay-at-Clinic billing.
                                </p>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; <?= date('Y') ?> TeleMed Healthcare Platform. All rights reserved.
    </footer>

</body>
</html>