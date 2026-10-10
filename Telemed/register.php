<?php
if (file_exists(__DIR__ . '/includes/auth.php')) {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
} else {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
}

// Auto-Migrations to guarantee tables support approval & roles
try { $db->exec("ALTER TABLE users ADD COLUMN is_approved INTEGER DEFAULT 0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN approval_status TEXT DEFAULT 'pending'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE patients ADD COLUMN contact TEXT DEFAULT ''"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN qualification TEXT DEFAULT 'MBBS'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN consultation_fee REAL DEFAULT 1000.0"); } catch (Throwable $e) {}

$selected_role = isset($_GET['role']) && $_GET['role'] === 'doctor' ? 'doctor' : 'patient';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = trim($_POST['role'] ?? 'patient');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $contact  = trim($_POST['contact'] ?? '');

    if (empty($username) || empty($email) || empty($password) || empty($name)) {
        $error = 'Please fill out all required fields.';
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error = 'This username or email is already registered. Please login instead.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $db->beginTransaction();
            try {
                // Register into users table (requires admin approval: is_approved = 0)
                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password, role, is_approved, approval_status, created_at)
                    VALUES (?, ?, ?, ?, 0, 'pending', datetime('now'))
                ");
                $stmt->execute([$username, $email, $hashed_password, $role]);
                $new_user_id = $db->lastInsertId();

                if ($role === 'doctor') {
                    $specialization = trim($_POST['specialization'] ?? 'Family Physician');
                    $qualification  = trim($_POST['qualification'] ?? 'MBBS, MD');
                    $fee            = max(100, (float)($_POST['consultation_fee'] ?? 1000.0));

                    $stmt = $db->prepare("
                        INSERT INTO doctors (user_id, name, specialization, qualification, consultation_fee, contact, is_available, is_offline_available, rating, total_consultations)
                        VALUES (?, ?, ?, ?, ?, ?, 1, 1, 5.0, 0)
                    ");
                    $stmt->execute([$new_user_id, $name, $specialization, $qualification, $fee, $contact]);
                } else {
                    $age    = !empty($_POST['age']) ? (int)$_POST['age'] : null;
                    $gender = trim($_POST['gender'] ?? 'male');

                    $stmt = $db->prepare("
                        INSERT INTO patients (user_id, name, age, gender, contact, created_at)
                        VALUES (?, ?, ?, ?, ?, datetime('now'))
                    ");
                    $stmt->execute([$new_user_id, $name, $age, $gender, $contact]);
                }

                $db->commit();
                $success = "Registration submitted successfully! Your account is pending admin approval before you can sign in.";
            } catch (Throwable $e) {
                $db->rollBack();
                $error = "Registration error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - TeleMed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen py-10 px-4 flex items-center justify-center">

    <div class="max-w-xl w-full bg-white rounded-3xl shadow-xl border border-slate-200/80 p-8 sm:p-10">
        
        <!-- Header -->
        <div class="text-center mb-6">
            <a href="index.php" class="inline-flex items-center gap-2 mb-3">
                <span class="text-3xl">🏣</span>
                <span class="text-2xl font-extrabold text-indigo-600">TeleMed</span>
            </a>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Create an Account</h1>
            <p class="text-xs text-slate-500 mt-1">Select your account role below to get started</p>
        </div>

        <!-- Role Toggle Tabs -->
        <div class="grid grid-cols-2 gap-2 p-1.5 bg-slate-100 rounded-2xl mb-6">
            <a href="?role=patient" class="py-2.5 text-center text-xs font-bold rounded-xl transition <?= $selected_role === 'patient' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                👤 Register as Patient
            </a>
            <a href="?role=doctor" class="py-2.5 text-center text-xs font-bold rounded-xl transition <?= $selected_role === 'doctor' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                👨‍⚕️ Register as Doctor
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold leading-relaxed">
                <?= htmlspecialchars($success) ?>
                <div class="mt-2">
                    <a href="login.php" class="underline font-extrabold">Proceed to Login &rarr;</a>
                </div>
            </div>
        <?php else: ?>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="role" value="<?= htmlspecialchars($selected_role) ?>">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <?= $selected_role === 'doctor' ? 'Doctor Full Name (with Dr.) *' : 'Patient Full Name *' ?>
                    </label>
                    <input type="text" name="name" placeholder="<?= $selected_role === 'doctor' ? 'e.g. Dr. Rajesh Sharma' : 'e.g. Ramesh Patel' ?>" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Username *</label>
                        <input type="text" name="username" placeholder="Choose a username" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Phone Number</label>
                        <input type="text" name="contact" placeholder="+91 9876543210" class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Email Address *</label>
                    <input type="email" name="email" placeholder="name@example.com" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Password *</label>
                    <input type="password" name="password" placeholder="Create a secure password" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                </div>

                <!-- Doctor-Specific Fields -->
                <?php if ($selected_role === 'doctor'): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Specialization *</label>
                            <select name="specialization" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-emerald-600 focus:outline-none bg-white">
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
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Qualifications *</label>
                            <input type="text" name="qualification" placeholder="e.g. MBBS, MD" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-emerald-600 focus:outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Checking / Consultation Fee (INR ₹) *</label>
                        <input type="number" step="50" min="100" name="consultation_fee" value="1000" required class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-emerald-600 focus:outline-none">
                    </div>
                <?php else: ?>
                    <!-- Patient-Specific Fields -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Age (Years)</label>
                            <input type="number" min="1" max="120" name="age" placeholder="e.g. 25" class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Gender</label>
                            <select name="gender" class="w-full px-4 py-3 rounded-xl border border-slate-300 text-sm focus:border-indigo-600 focus:outline-none">
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="pt-3">
                    <button type="submit" class="w-full py-3.5 <?= $selected_role === 'doctor' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-indigo-600 hover:bg-indigo-700' ?> text-white font-extrabold text-sm uppercase tracking-wider rounded-xl shadow-md transition">
                        <?= $selected_role === 'doctor' ? '👨‍⚕️ Submit Doctor Registration' : 'Register as Patient' ?>
                    </button>
                </div>

            </form>

        <?php endif; ?>

        <div class="text-center mt-6 text-xs text-slate-500">
            Already have an account? 
            <a href="login.php" class="text-indigo-600 font-bold hover:underline">Sign In here</a>
        </div>

    </div>

</body>
</html>