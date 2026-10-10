<?php
// Dynamic include path resolver (supports both root and subfolder execution)
if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
}

require_login();
if (!is_doctor()) {
    header('Location: ../login.php');
    exit;
}

$current_user = current_user();
$doctor_user_id = $current_user['id'];

// Safe DB Auto-Migrations (prevents column missing errors across SQLite tables)
try { $db->exec("ALTER TABLE patients ADD COLUMN profile_photo TEXT DEFAULT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE doctors ADD COLUMN profile_photo TEXT DEFAULT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN medications TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN medication TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN dosage TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN frequency TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN instructions TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN duration TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN notes TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN diagnosis TEXT"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE prescriptions ADD COLUMN total_price REAL DEFAULT 0.0"); } catch (Throwable $e) {}

// Get doctor's profile
$stmt = $db->prepare("SELECT id, name, specialization, qualification, clinic_address FROM doctors WHERE user_id = ?");
$stmt->execute([$doctor_user_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die("Doctor profile not found. Please contact administrator.");
}

$doctor_id = $doctor['id'];

// -------------------------------------------------------------------------
// COMPREHENSIVE PHARMACY MEDICINE CATALOG WITH INDICATIONS & INR PRICES
// -------------------------------------------------------------------------
$medicine_catalog = [
    // 1. Fever aur Pain Relief Medicines
    [
        'id' => 1,
        'brand' => 'Dolo 650 / Calpol',
        'generic' => 'Paracetamol 650mg',
        'category' => 'Fever & Pain Relief',
        'form' => 'Tablet',
        'use' => 'Fever aur mild pain ke liye sabse common',
        'dosage' => '1 Tab TDS / SOS (1-0-1)',
        'instructions' => 'Take with water after food for fever or body ache',
        'duration' => '3 - 5 Days',
        'price' => 32.00,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 2,
        'brand' => 'Brufen 400',
        'generic' => 'Ibuprofen 400mg',
        'category' => 'Fever & Pain Relief',
        'form' => 'Tablet',
        'use' => 'Pain aur inflammation ke liye effective',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take strictly after meals with water',
        'duration' => '3 Days',
        'price' => 24.50,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 3,
        'brand' => 'Ecosprin 75 / Disprin',
        'generic' => 'Aspirin 75mg / 325mg',
        'category' => 'Fever & Pain Relief',
        'form' => 'Tablet',
        'use' => 'Pain relief & blood thinner (Doctor advice zaroori)',
        'dosage' => '1 Tab OD (0-1-0)',
        'instructions' => 'Take after lunch with water. Avoid empty stomach.',
        'duration' => 'As advised',
        'price' => 18.00,
        'unit' => 'Strip of 14 Tabs'
    ],
    [
        'id' => 4,
        'brand' => 'Voveran 50',
        'generic' => 'Diclofenac 50mg',
        'category' => 'Fever & Pain Relief',
        'form' => 'Tablet',
        'use' => 'Strong painkiller for severe body ache & sprains',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take with food or antacid. Avoid overdose.',
        'duration' => '3 Days',
        'price' => 45.00,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 5,
        'brand' => 'Zerodol-P',
        'generic' => 'Aceclofenac 100mg + Paracetamol 325mg',
        'category' => 'Fever & Pain Relief',
        'form' => 'Tablet',
        'use' => 'Joint pain aur arthritis swelling ke liye useful',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take after food for joint pain and muscular stiffness',
        'duration' => '5 Days',
        'price' => 68.00,
        'unit' => 'Strip of 10 Tabs'
    ],

    // 2. Cold aur Cough Medicines
    [
        'id' => 6,
        'brand' => 'Cetzine 10',
        'generic' => 'Cetirizine 10mg',
        'category' => 'Cold & Cough',
        'form' => 'Tablet',
        'use' => 'Allergy aur sneezing ke liye effective',
        'dosage' => '1 Tab HS (0-0-1)',
        'instructions' => 'Take at bedtime. May cause mild drowsiness.',
        'duration' => '5 Days',
        'price' => 38.00,
        'unit' => 'Strip of 10 Tabs'
    ],
    [
        'id' => 7,
        'brand' => 'Levocet 5',
        'generic' => 'Levocetirizine 5mg',
        'category' => 'Cold & Cough',
        'form' => 'Tablet',
        'use' => 'Runny nose aur severe cold symptoms ke liye',
        'dosage' => '1 Tab HS (0-0-1)',
        'instructions' => 'Take once daily before sleep with water',
        'duration' => '5 Days',
        'price' => 58.00,
        'unit' => 'Strip of 10 Tabs'
    ],
    [
        'id' => 8,
        'brand' => 'Ambrodil Syrup',
        'generic' => 'Ambroxol 30mg/5ml',
        'category' => 'Cold & Cough',
        'form' => 'Syrup',
        'use' => 'Wet cough & balgham (mucus) nikalne ke liye',
        'dosage' => '10ml TDS (1-1-1)',
        'instructions' => 'Take 10ml thrice daily after food with warm water',
        'duration' => '5 Days',
        'price' => 85.00,
        'unit' => '100ml Bottle'
    ],
    [
        'id' => 9,
        'brand' => 'Ascoril-D Plus / Benadryl DR',
        'generic' => 'Dextromethorphan Hydrobromide 10mg/5ml',
        'category' => 'Cold & Cough',
        'form' => 'Syrup',
        'use' => 'Dry cough (sukhi khansi) me use hoti hai',
        'dosage' => '10ml TDS (1-1-1)',
        'instructions' => 'Take after food for soothing dry throat tickle',
        'duration' => '5 Days',
        'price' => 132.00,
        'unit' => '100ml Bottle'
    ],
    [
        'id' => 10,
        'brand' => 'Sinarest / Nasivion',
        'generic' => 'Phenylephrine + Paracetamol + CPM',
        'category' => 'Cold & Cough',
        'form' => 'Tablet / Drops',
        'use' => 'Blocked nose (band naak) aur sinus pressure ke liye',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take after food for nasal decongestion',
        'duration' => '3 - 5 Days',
        'price' => 62.00,
        'unit' => 'Strip of 10 Tabs'
    ],

    // 3. Antibiotics (Doctor ki Salah Zaroori)
    [
        'id' => 11,
        'brand' => 'Novamox 500 / Augmentin 625',
        'generic' => 'Amoxicillin 500mg (+ Clavulanic Acid)',
        'category' => 'Antibiotics',
        'form' => 'Tablet',
        'use' => 'Common bacterial infections ke treatment ke liye',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take every 12 hours with meals. Complete full course.',
        'duration' => '5 - 7 Days',
        'price' => 185.00,
        'unit' => 'Strip of 10 Tabs'
    ],
    [
        'id' => 12,
        'brand' => 'Azee 500',
        'generic' => 'Azithromycin 500mg',
        'category' => 'Antibiotics',
        'form' => 'Tablet',
        'use' => 'Throat aur chest infection ke liye effective',
        'dosage' => '1 Tab OD (1-0-0)',
        'instructions' => 'Take 1 hour before or 2 hours after food once daily',
        'duration' => '3 - 5 Days',
        'price' => 135.00,
        'unit' => 'Strip of 5 Tabs'
    ],
    [
        'id' => 13,
        'brand' => 'Cifran 500',
        'generic' => 'Ciprofloxacin 500mg',
        'category' => 'Antibiotics',
        'form' => 'Tablet',
        'use' => 'Urinary tract infection (UTI) aur bacterial infection',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take with full glass of water. Drink plenty of fluids.',
        'duration' => '5 Days',
        'price' => 48.00,
        'unit' => 'Strip of 10 Tabs'
    ],
    [
        'id' => 14,
        'brand' => 'Doxicip 100',
        'generic' => 'Doxycycline 100mg',
        'category' => 'Antibiotics',
        'form' => 'Capsule',
        'use' => 'Skin, acne aur bacterial infections ke liye',
        'dosage' => '1 Cap BD (1-0-1)',
        'instructions' => 'Take while sitting or standing with full glass of water',
        'duration' => '7 Days',
        'price' => 42.00,
        'unit' => 'Strip of 10 Caps'
    ],
    [
        'id' => 15,
        'brand' => 'Zifi 200',
        'generic' => 'Cefixime 200mg',
        'category' => 'Antibiotics',
        'form' => 'Tablet',
        'use' => 'Broad spectrum antibiotic for typhoid & chest infection',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take after food morning and evening. Do not skip doses.',
        'duration' => '5 - 7 Days',
        'price' => 120.00,
        'unit' => 'Strip of 10 Tabs'
    ],

    // 4. Digestive Medicines
    [
        'id' => 16,
        'brand' => 'Pan 40',
        'generic' => 'Pantoprazole 40mg',
        'category' => 'Digestive Medicines',
        'form' => 'Tablet',
        'use' => 'Acidity, heartburn aur pet me jalan ke liye',
        'dosage' => '1 Tab OD (1-0-0)',
        'instructions' => 'Take 30 minutes before breakfast on an empty stomach',
        'duration' => '14 Days',
        'price' => 155.00,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 17,
        'brand' => 'Omez 20',
        'generic' => 'Omeprazole 20mg',
        'category' => 'Digestive Medicines',
        'form' => 'Capsule',
        'use' => 'Gas aur acid reflux ki pareshani door karne ke liye',
        'dosage' => '1 Cap OD (1-0-0)',
        'instructions' => 'Take once daily before morning meal with water',
        'duration' => '10 Days',
        'price' => 65.00,
        'unit' => 'Strip of 15 Caps'
    ],
    [
        'id' => 18,
        'brand' => 'Domstal 10 / Vomistop',
        'generic' => 'Domperidone 10mg',
        'category' => 'Digestive Medicines',
        'form' => 'Tablet',
        'use' => 'Vomiting, ulti aur nausea control karne ke liye',
        'dosage' => '1 Tab TDS / SOS (15 mins before food)',
        'instructions' => 'Take 15-30 minutes before meals when feeling nauseous',
        'duration' => '3 Days',
        'price' => 42.00,
        'unit' => 'Strip of 10 Tabs'
    ],
    [
        'id' => 19,
        'brand' => 'Electral ORS',
        'generic' => 'Oral Rehydration Salts (WHO Formula)',
        'category' => 'Digestive Medicines',
        'form' => 'Sachet',
        'use' => 'Dehydration aur loose motions me helpful',
        'dosage' => '1 Sachet in 1 Litre Water',
        'instructions' => 'Dissolve completely in fresh boiled and cooled water. Sip throughout.',
        'duration' => '2 - 3 Days',
        'price' => 22.50,
        'unit' => '21.8g Sachet'
    ],
    [
        'id' => 20,
        'brand' => 'Duphalac / Cremaffin',
        'generic' => 'Lactulose Solution 10g/15ml',
        'category' => 'Digestive Medicines',
        'form' => 'Syrup',
        'use' => 'Constipation (kabz) aur digestion problem ke liye',
        'dosage' => '15ml to 30ml at Bedtime',
        'instructions' => 'Take at night with plenty of water. Normalizes bowel movements.',
        'duration' => '7 Days',
        'price' => 210.00,
        'unit' => '150ml Bottle'
    ],

    // 5. Chronic Disease Medicines
    [
        'id' => 21,
        'brand' => 'Glycomet 500 SR',
        'generic' => 'Metformin 500mg SR',
        'category' => 'Chronic Disease',
        'form' => 'Tablet',
        'use' => 'Type 2 Diabetes control karne ke liye',
        'dosage' => '1 Tab BD (1-0-1)',
        'instructions' => 'Take strictly with or immediately after meals',
        'duration' => '30 Days',
        'price' => 42.00,
        'unit' => 'Strip of 20 Tabs'
    ],
    [
        'id' => 22,
        'brand' => 'Amlong 5',
        'generic' => 'Amlodipine 5mg',
        'category' => 'Chronic Disease',
        'form' => 'Tablet',
        'use' => 'High blood pressure ko normal rakhne ke liye',
        'dosage' => '1 Tab OD (1-0-0)',
        'instructions' => 'Take every morning at the same time. Monitor BP.',
        'duration' => '30 Days',
        'price' => 45.00,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 23,
        'brand' => 'Atorva 10',
        'generic' => 'Atorvastatin 10mg',
        'category' => 'Chronic Disease',
        'form' => 'Tablet',
        'use' => 'Cholesterol control aur heart protection ke liye',
        'dosage' => '1 Tab HS (0-0-1)',
        'instructions' => 'Take at bedtime after dinner to lower LDL cholesterol',
        'duration' => '30 Days',
        'price' => 115.00,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 24,
        'brand' => 'Telma 40',
        'generic' => 'Telmisartan 40mg',
        'category' => 'Chronic Disease',
        'form' => 'Tablet',
        'use' => 'Hypertension aur blood pressure control karne ke liye',
        'dosage' => '1 Tab OD (1-0-0)',
        'instructions' => 'Take in the morning with water. Lowers cardiac risk.',
        'duration' => '30 Days',
        'price' => 165.00,
        'unit' => 'Strip of 15 Tabs'
    ],
    [
        'id' => 25,
        'brand' => 'Amaryl 1 / Glimestar 1',
        'generic' => 'Glimepiride 1mg',
        'category' => 'Chronic Disease',
        'form' => 'Tablet',
        'use' => 'Blood sugar manage karne ke liye (Stimulates insulin)',
        'dosage' => '1 Tab OD (1-0-0)',
        'instructions' => 'Take 15 minutes before breakfast. Do not skip meals.',
        'duration' => '30 Days',
        'price' => 75.00,
        'unit' => 'Strip of 15 Tabs'
    ]
];

// Handle Prescription Form Submission 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_prescription'])) {
    try {
        $patient_id  = filter_var($_POST['patient_id'] ?? 0, FILTER_VALIDATE_INT);
        $diagnosis   = trim($_POST['diagnosis'] ?? 'Clinical Consultation');
        $duration    = trim($_POST['duration'] ?? '5 Days');
        $notes       = trim($_POST['notes'] ?? '');
        $total_price = (float)($_POST['total_price'] ?? 0.00);

        // Auto-link latest appointment for this patient 
        $appointment_id = filter_var($_POST['appointment_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$appointment_id && $patient_id) {
            $stmt = $db->prepare("SELECT id FROM appointments WHERE patient_id = ? AND doctor_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$patient_id, $doctor_id]);
            $found_appt = $stmt->fetchColumn();
            $appointment_id = $found_appt ? (int)$found_appt : 0;
        }

        $med_names   = $_POST['item_name'] ?? [];
        $med_dosages = $_POST['item_dosage'] ?? [];
        $med_insts   = $_POST['item_instructions'] ?? [];
        $med_prices  = $_POST['item_price'] ?? [];

        if (empty($med_names) || empty(trim($med_names[0]))) {
            $error = "Please add at least one medication to the prescription.";
        } else {
            $med_summary_arr  = [];
            $dose_summary_arr = [];
            $inst_summary_arr = [];

            foreach ($med_names as $i => $m_name) {
                $m_name = trim($m_name);
                if (empty($m_name)) continue;

                $dose = trim($med_dosages[$i] ?? '');
                $inst = trim($med_insts[$i] ?? '');
                $prc  = (float)($med_prices[$i] ?? 0.0);

                $med_summary_arr[]  = "$m_name (₹" . number_format($prc, 2) . ")";
                $dose_summary_arr[] = "$m_name: $dose";
                if (!empty($inst)) {
                    $inst_summary_arr[] = "$m_name: $inst";
                }
            }

            $medications_str  = implode("\n", $med_summary_arr);
            $dosage_str       = implode("\n", $dose_summary_arr);
            $instructions_str = implode("\n", $inst_summary_arr);
            if (!empty($notes)) {
                $instructions_str .= "\nAdvice: " . $notes;
            }

            $first_med = trim($med_names[0]);

            $stmt = $db->prepare("
                INSERT INTO prescriptions 
                (doctor_id, patient_id, appointment_id, medications, medication, dosage, instructions, duration, notes, diagnosis, total_price, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            $stmt->execute([
                $doctor_id,
                $patient_id ?: null,
                $appointment_id ?: null,
                $medications_str,
                $first_med,
                $dosage_str,
                $instructions_str,
                $duration,
                $notes,
                $diagnosis,
                $total_price
            ]);
            
            $success = "Prescription created successfully! Total estimated pharmacy cost: ₹" . number_format($total_price, 2);
            
            if ($appointment_id) {
                $stmt = $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ? AND doctor_id = ?");
                $stmt->execute([$appointment_id, $doctor_id]);
            }
        }
    } catch (Throwable $e) {
        $error = "Error creating prescription: " . $e->getMessage();
    }
}

// Get recent prescriptions 
try {
    $stmt = $db->prepare("
        SELECT p.*, pt.name as patient_name, pt.age, pt.gender,
               COALESCE(a.appointment_date, p.created_at) as appointment_date
        FROM prescriptions p
        LEFT JOIN patients pt ON pt.id = p.patient_id
        LEFT JOIN appointments a ON a.id = p.appointment_id
        WHERE p.doctor_id = ?
        ORDER BY p.created_at DESC
        LIMIT 25
    ");
    $stmt->execute([$doctor_id]);
    $recent_prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recent_prescriptions = [];
}

// Get enrolled patients 
try {
    $stmt = $db->prepare("
        SELECT DISTINCT p.id, p.name, p.age, p.gender,
               (SELECT a.id FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = ? ORDER BY a.id DESC LIMIT 1) as latest_appointment_id
        FROM patients p
        ORDER BY p.name ASC
    ");
    $stmt->execute([$doctor_id]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $patients = [];
}

function safe_display($value, $default = '') {
    return htmlspecialchars($value ?? $default, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions & Medicine Catalog - Telemed</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; line-height: 1.6; }
        
        .navbar {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            padding: 1rem 2rem; color: white; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h1 { font-size: 1.45rem; font-weight: 600; }
        .back-btn { color: white; text-decoration: none; padding: 0.5rem 1rem; background: rgba(255,255,255,0.2); border-radius: 6px; font-weight: 500; }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 1rem; }
        
        .page-header {
            background: white; padding: 1.75rem 2rem; border-radius: 16px; margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center; border: 1px solid #e2e8f0;
        }
        .page-header h2 { color: #1f2937; font-size: 1.9rem; margin-bottom: 0.35rem; }
        .page-header p { color: #64748b; font-size: 1.05rem; }
        
        .main-grid { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 2rem; margin-bottom: 2rem; }
        
        .card {
            background: white; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            overflow: hidden; border: 1px solid #e2e8f0;
        }
        .card-header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white; padding: 1.25rem 1.5rem; font-weight: 600; font-size: 1.1rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .card-content { padding: 1.75rem 1.5rem; }
        
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; margin-bottom: 0.4rem; font-weight: 600; color: #334155; font-size: 0.9rem; }
        .form-input, .form-select, .form-textarea {
            width: 100%; padding: 0.75rem; border: 1.5px solid #cbd5e1; border-radius: 8px;
            font-size: 0.95rem; outline: none; transition: border-color 0.2s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: #059669; }
        
        /* MEDICINE CATALOG SEARCH & PILLS */
        .catalog-box {
            background: #f0fdf4; border: 2px dashed #86efac; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem;
        }
        .catalog-title { font-weight: 700; color: #166534; font-size: 1rem; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center; }
        
        .category-pills { display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 0.75rem 0; }
        .cat-pill {
            background: white; border: 1px solid #bbf7d0; padding: 0.35rem 0.75rem; border-radius: 20px;
            font-size: 0.8rem; font-weight: 600; color: #15803d; cursor: pointer; transition: all 0.2s;
        }
        .cat-pill:hover, .cat-pill.active { background: #059669; color: white; border-color: #059669; }

        .med-catalog-list {
            max-height: 270px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.5rem;
            background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.5rem; margin-top: 0.5rem;
        }
        .catalog-item {
            display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem;
            border-radius: 6px; background: #f8fafc; border: 1px solid #f1f5f9; cursor: pointer; transition: all 0.2s;
        }
        .catalog-item:hover { background: #dcfce7; border-color: #86efac; transform: translateX(3px); }
        .item-brand { font-weight: 700; color: #1e293b; font-size: 0.95rem; }
        .item-generic { font-size: 0.82rem; color: #475569; }
        .item-use-badge { font-size: 0.75rem; background: #fef3c7; color: #92400e; padding: 0.15rem 0.5rem; border-radius: 4px; font-weight: 600; margin-left: 0.4rem; }
        .item-price { font-weight: 700; color: #059669; font-size: 0.95rem; }

        /* PRESCRIBED ITEMS TABLE */
        .rx-table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.88rem; }
        .rx-table th { background: #f1f5f9; padding: 0.65rem 0.75rem; border-bottom: 2px solid #cbd5e1; text-align: left; }
        .rx-table td { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .rx-table input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; }

        .price-summary-banner {
            display: flex; justify-content: space-between; align-items: center; background: #f8fafc;
            border: 2px solid #e2e8f0; padding: 1rem 1.25rem; border-radius: 10px; margin-top: 1rem;
        }
        .total-price-text { font-size: 1.4rem; font-weight: 800; color: #059669; }

        .del-btn {
            background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 0.35rem 0.65rem;
            border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.85rem;
        }
        .del-btn:hover { background: #dc2626; color: white; }

        .btn {
            padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary { background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; width: 100%; justify-content: center; margin-top: 1rem; }
        .btn-primary:hover { box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3); transform: translateY(-2px); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; border-left: 4px solid #059669; }
        .stat-number { font-size: 2rem; font-weight: 700; color: #059669; display: block; }
        .stat-label { color: #64748b; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }

        .prescription-list { display: flex; flex-direction: column; gap: 1rem; }
        .prescription-item { background: #f8fafc; padding: 1.25rem; border-radius: 12px; border: 1px solid #e2e8f0; border-left: 4px solid #059669; }
        .prescription-header { display: flex; justify-content: space-between; margin-bottom: 0.75rem; }
        .prescription-patient { font-weight: 700; color: #1e293b; font-size: 1.05rem; }
        .prescription-date { font-size: 0.85rem; color: #64748b; }

        .alert { padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

        .empty-state { text-align: center; color: #64748b; font-style: italic; padding: 3rem; background: #f9fafb; border-radius: 8px; border: 2px dashed #d1d5db; }

        /* VIEW / PRINT PRESCRIPTION MODAL */
        .modal-bg {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);
        }
        .modal-box {
            background-color: white; margin: 3vh auto; border-radius: 14px; width: 95%; max-width: 780px;
            height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center;
        }
        .modal-body { padding: 2rem; overflow-y: auto; flex: 1; color: #1e293b; }
        .modal-close { font-size: 1.6rem; cursor: pointer; line-height: 1; font-weight: 700; }

        @media print {
            body * { visibility: hidden; }
            #viewRxModal, #viewRxModal * { visibility: visible; }
            #viewRxModal { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: white; }
            .modal-box { box-shadow: none; width: 100%; max-width: 100%; margin: 0; height: auto; }
            .no-print { display: none !important; }
        }

        @media (max-width: 900px) {
            .main-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="navbar no-print">
        <h1>💊 Prescriptions & Pharmacy Catalog</h1>
        <a href="doctor.php" class="back-btn">← Back to Dashboard</a>
    </div>

    <div class="container no-print">
        <div class="page-header">
            <h2>💊 Clinical Prescription & Drug Formulary Manager</h2>
            <p>Select medicines from the catalog with automated dosages, clinical use notes, and estimated retail prices</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">✅ <?= safe_display($success) ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">⚠️ <?= safe_display($error) ?></div>
        <?php endif; ?>

        <!-- Statistics Metrics  -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-number"><?= count($recent_prescriptions) ?></span>
                <div class="stat-label">Total Prescriptions</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?= count($medicine_catalog) ?> Medicines</span>
                <div class="stat-label">Essential Formulations</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?= count(array_unique(array_column($recent_prescriptions, 'patient_id'))) ?></span>
                <div class="stat-label">Patients Treated</div>
            </div>
        </div>

        <div class="main-grid">
            <!-- CREATE PRESCRIPTION WITH LIVE CATALOG -->
            <div class="card">
                <div class="card-header">
                    <span>➕ Formulate Digital Prescription</span>
                    <span style="font-size:0.85rem; font-weight:normal; opacity:0.9;">Dr. <?= safe_display($doctor['name']) ?></span>
                </div>
                <div class="card-content">
                    <form method="POST" id="prescriptionForm" onsubmit="return validateRxForm()">
                        <input type="hidden" name="create_prescription" value="1">
                        <input type="hidden" name="total_price" id="totalPriceInput" value="0.00">
                        <input type="hidden" name="appointment_id" id="patientApptIdInput" value="">

                        <div class="form-group">
                            <label class="form-label">Select Patient *</label>
                            <select name="patient_id" class="form-select" required onchange="handlePatientSelect(this)">
                                <option value="">-- Choose Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-appt="<?= $p['latest_appointment_id'] ?? '' ?>">
                                        <?= safe_display($p['name']) ?> 
                                        (<?= $p['age'] ? $p['age'] . ' yrs' : 'Age N/A' ?> &bull; <?= ucfirst($p['gender'] ?? 'N/A') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Clinical Diagnosis / Medical Reason *</label>
                            <input type="text" name="diagnosis" class="form-input" placeholder="e.g. Acute Viral Fever / Upper Respiratory Infection / Hypertension" required>
                        </div>

                        <!-- SEARCHABLE PHARMACY CATALOG WITH YOUR SPECIFIC 5 CATEGORIES -->
                        <div class="catalog-box">
                            <div class="catalog-title">
                                <span>🔍 Essential Medicines Catalog</span>
                                <span style="font-size:0.8rem; font-weight:normal; color:#475569;">Click any medicine to add to prescription</span>
                            </div>

                            <input type="text" id="catalogSearchInput" class="form-input" placeholder="Search by name or use (e.g. Paracetamol, Cough, Acidity, Diabetes, Fever)..." oninput="filterCatalog()">

                            <!-- Category Filter Pills Matching User Requirements -->
                            <div class="category-pills">
                                <span class="cat-pill active" onclick="filterCategory('All')">All Medicines</span>
                                <span class="cat-pill" onclick="filterCategory('Fever & Pain Relief')">Fever & Pain</span>
                                <span class="cat-pill" onclick="filterCategory('Cold & Cough')">Cold & Cough</span>
                                <span class="cat-pill" onclick="filterCategory('Antibiotics')">Antibiotics</span>
                                <span class="cat-pill" onclick="filterCategory('Digestive Medicines')">Digestive</span>
                                <span class="cat-pill" onclick="filterCategory('Chronic Disease')">Chronic Diseases</span>
                            </div>

                            <!-- List of medicines -->
                            <div class="med-catalog-list" id="catalogListContainer"></div>
                        </div>

                        <!-- PRESCRIBED ITEMS TABLE -->
                        <div class="form-group">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                <label class="form-label" style="margin:0;">Prescribed Items List *</label>
                                <button type="button" onclick="addBlankRow()" style="background:#0284c7; color:white; border:none; padding:0.35rem 0.75rem; border-radius:6px; font-size:0.8rem; cursor:pointer; font-weight:600;">
                                    + Add Custom Medicine
                                </button>
                            </div>

                            <div style="overflow-x:auto;">
                                <table class="rx-table">
                                    <thead>
                                        <tr>
                                            <th style="width:32%;">Medicine Name</th>
                                            <th style="width:24%;">Dosage & Timing</th>
                                            <th style="width:24%;">Instructions</th>
                                            <th style="width:12%;">Price (₹)</th>
                                            <th style="width:8%;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="prescribedItemsTbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TOTAL ESTIMATED PRICE BANNER -->
                        <div class="price-summary-banner">
                            <div>
                                <div style="font-weight:700; color:#334155; font-size:0.95rem;">Total Pharmacy Cost:</div>
                                <div style="font-size:0.8rem; color:#64748b;">Calculated with standard MRP pharmacy prices</div>
                            </div>
                            <div class="total-price-text" id="totalPriceDisplay">₹0.00</div>
                        </div>

                        <div class="form-group" style="margin-top:1.25rem;">
                            <label class="form-label">Treatment Duration</label>
                            <input type="text" name="duration" class="form-input" placeholder="e.g. 3 Days / 5 Days / 1 Month">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Doctor's Advice & Dietary Precautions</label>
                            <textarea name="notes" class="form-textarea" rows="2" placeholder="e.g. Take medicines after meals. Drink warm water. Avoid overdose."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            💾 Save Digital Prescription & Generate Bill
                        </button>
                    </form>
                </div>
            </div>

            <!-- RECENT PRESCRIPTIONS LIST  -->
            <div class="card">
                <div class="card-header">
                    <span>📋 Recent Prescriptions Issued</span>
                    <span><?= count($recent_prescriptions) ?> Records</span>
                </div>
                <div class="card-content">
                    <?php if (empty($recent_prescriptions)): ?>
                        <div class="empty-state">
                            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">💊</div>
                            <div>No prescriptions created yet.</div>
                        </div>
                    <?php else: ?>
                        <div class="prescription-list">
                            <?php foreach (array_slice($recent_prescriptions, 0, 6) as $rx): 
                                $cost = isset($rx['total_price']) ? (float)$rx['total_price'] : 0.00;
                            ?>
                                <div class="prescription-item">
                                    <div class="prescription-header">
                                        <div>
                                            <div class="prescription-patient">
                                                <?= $rx['patient_name'] ? safe_display($rx['patient_name']) : 'Patient' ?>
                                                <?= $rx['age'] ? '<span style="font-size:0.85rem; color:#64748b; font-weight:normal;">(' . $rx['age'] . ' yrs)</span>' : '' ?>
                                            </div>
                                            <div style="font-size:0.85rem; color:#059669; font-weight:600;">
                                                Diagnosis: <?= safe_display(!empty($rx['diagnosis']) ? $rx['diagnosis'] : 'General') ?>
                                            </div>
                                        </div>
                                        <div style="text-align:right;">
                                            <div class="prescription-date"><?= date('M d, Y', strtotime($rx['created_at'])) ?></div>
                                            <?php if ($cost > 0): ?>
                                                <span style="font-size:0.82rem; font-weight:700; color:#059669; background:#dcfce7; padding:0.15rem 0.5rem; border-radius:4px;">
                                                    Est. ₹<?= number_format($cost, 2) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div style="font-size:0.85rem; color:#334155; margin-bottom:0.4rem;">
                                        <strong>Prescribed Medications:</strong><br>
                                        <?= nl2br(safe_display($rx['medications'] ?: $rx['medication'])) ?>
                                    </div>

                                    <?php if (!empty($rx['instructions'])): ?>
                                        <div style="font-size:0.8rem; color:#64748b; margin-top:0.3rem;">
                                            <strong>Advice:</strong> <?= nl2br(safe_display($rx['instructions'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- VIEW & PRINT ACTION  -->
                                    <div style="margin-top:0.75rem; display:flex; gap:0.5rem;">
                                        <button type="button" onclick="previewRx(<?= htmlspecialchars(json_encode($rx), ENT_QUOTES, 'UTF-8') ?>)" style="background:#0284c7; color:white; border:none; padding:0.35rem 0.75rem; border-radius:5px; font-size:0.8rem; font-weight:600; cursor:pointer;">
                                            📄 View / Print Slip
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- BUILT-IN VIEW / PRINT MODAL -->
    <div id="viewRxModal" class="modal-bg">
        <div class="modal-box">
            <div class="modal-header no-print">
                <h3>📄 Official Medical Prescription Slip</h3>
                <div style="display:flex; gap:0.5rem; align-items:center;">
                    <button onclick="window.print()" style="background:#10b981; color:white; border:none; padding:0.4rem 0.8rem; border-radius:5px; font-weight:600; cursor:pointer;">
                        🖨️ Print / Download PDF
                    </button>
                    <span class="modal-close" onclick="closeViewRx()">&times;</span>
                </div>
            </div>
            <div class="modal-body" id="modalPrintArea">
                <div style="display:flex; justify-content:space-between; border-bottom:2px solid #059669; padding-bottom:0.75rem; margin-bottom:1rem;">
                    <div>
                        <h2 style="color:#059669; font-size:1.4rem;">🏥 TELEMED HEALTHCARE CLINIC</h2>
                        <div style="font-weight:700; font-size:1.05rem;">Dr. <?= safe_display($doctor['name']) ?></div>
                        <div style="font-size:0.85rem; color:#64748b;"><?= safe_display($doctor['specialization'] ?: 'General Practice') ?></div>
                        <div style="font-size:0.85rem; color:#64748b;"><?= safe_display($doctor['clinic_address'] ?? 'Main OPD Clinic') ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; color:#059669;">E-PRESCRIPTION SLIP</div>
                        <div style="font-size:0.85rem; color:#64748b;">Date: <span id="viewRxDate">--</span></div>
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem; display:flex; justify-content:space-between;">
                    <div>Patient: <strong id="viewRxPatient">--</strong></div>
                    <div>Age/Gender: <strong id="viewRxAgeGender">--</strong></div>
                    <div>Diagnosis: <strong id="viewRxDiagnosis" style="color:#059669;">--</strong></div>
                </div>

                <div style="font-size:1.8rem; font-weight:900; color:#059669; font-family:serif;">℞</div>

                <div style="margin:1rem 0; font-size:0.9rem; line-height:1.7;" id="viewRxMeds"></div>

                <div style="margin:1rem 0; padding:0.75rem; background:#f8fafc; border-radius:6px; font-size:0.85rem;" id="viewRxInstructions"></div>

                <div style="margin-top:2.5rem; display:flex; justify-content:space-between; align-items:flex-end; border-top:1px dashed #cbd5e1; padding-top:1rem;">
                    <div style="font-size:0.75rem; color:#64748b;">
                        🛡️ Digitally authorized electronic prescription. Valid for pharmacy dispensing under IT Act.
                    </div>
                    <div style="text-align:center;">
                        <div style="font-weight:700; color:#059669;">Dr. <?= safe_display($doctor['name']) ?></div>
                        <div style="font-size:0.75rem; color:#64748b; border-top:1px solid #94a3b8; width:150px; margin-top:2px;">Authorized Physician Seal</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const medicineCatalog = <?= json_encode($medicine_catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let selectedCategory = 'All';

        function renderCatalog(items) {
            const container = document.getElementById('catalogListContainer');
            if (items.length === 0) {
                container.innerHTML = '<div style="padding:1rem; text-align:center; color:#94a3b8; font-size:0.85rem;">No medicines found matching your search.</div>';
                return;
            }

            let html = '';
            items.forEach(med => {
                html += `
                    <div class="catalog-item" onclick="addMedicineToPrescription(${med.id})">
                        <div>
                            <div class="item-brand">
                                ${escapeHtml(med.brand)} 
                                <span style="font-size:0.75rem; background:#e0f2fe; color:#0369a1; padding:0.15rem 0.45rem; border-radius:4px; font-weight:600;">${escapeHtml(med.form)}</span>
                                <span class="item-use-badge">${escapeHtml(med.use)}</span>
                            </div>
                            <div class="item-generic">${escapeHtml(med.generic)} &bull; <em>${escapeHtml(med.dosage)}</em></div>
                        </div>
                        <div style="text-align:right;">
                            <div class="item-price">₹${parseFloat(med.price).toFixed(2)}</div>
                            <div style="font-size:0.75rem; color:#64748b;">${escapeHtml(med.unit)}</div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function filterCategory(cat) {
            selectedCategory = cat;
            document.querySelectorAll('.cat-pill').forEach(pill => {
                pill.classList.toggle('active', pill.textContent === cat || (cat === 'All' && pill.textContent === 'All Medicines'));
            });
            filterCatalog();
        }

        function filterCatalog() {
            const query = document.getElementById('catalogSearchInput').value.toLowerCase().trim();
            const filtered = medicineCatalog.filter(m => {
                const matchCat = (selectedCategory === 'All' || m.category === selectedCategory);
                const matchSearch = (!query || 
                    m.brand.toLowerCase().includes(query) || 
                    m.generic.toLowerCase().includes(query) ||
                    m.category.toLowerCase().includes(query) ||
                    m.use.toLowerCase().includes(query)
                );
                return matchCat && matchSearch;
            });
            renderCatalog(filtered);
        }

        function addMedicineToPrescription(medId) {
            const med = medicineCatalog.find(m => m.id === medId);
            if (!med) return;

            const tbody = document.getElementById('prescribedItemsTbody');
            const row = document.createElement('tr');

            row.innerHTML = `
                <td>
                    <input type="text" name="item_name[]" value="${escapeHtml(med.brand + ' (' + med.generic + ')')}" required>
                </td>
                <td>
                    <input type="text" name="item_dosage[]" value="${escapeHtml(med.dosage)}" placeholder="e.g. 1 Tab BD" required>
                </td>
                <td>
                    <input type="text" name="item_instructions[]" value="${escapeHtml(med.instructions)}" placeholder="Instructions">
                </td>
                <td>
                    <input type="number" step="0.5" name="item_price[]" value="${parseFloat(med.price).toFixed(2)}" class="price-input" oninput="calculateTotal()" required>
                </td>
                <td style="text-align:center;">
                    <button type="button" class="del-btn" onclick="removeRow(this)">&times;</button>
                </td>
            `;

            tbody.appendChild(row);
            calculateTotal();
        }

        function addBlankRow() {
            const tbody = document.getElementById('prescribedItemsTbody');
            const row = document.createElement('tr');

            row.innerHTML = `
                <td>
                    <input type="text" name="item_name[]" placeholder="Custom Medicine Name" required>
                </td>
                <td>
                    <input type="text" name="item_dosage[]" placeholder="e.g. 1 Tab OD" required>
                </td>
                <td>
                    <input type="text" name="item_instructions[]" placeholder="Take after food">
                </td>
                <td>
                    <input type="number" step="0.5" name="item_price[]" value="40.00" class="price-input" oninput="calculateTotal()" required>
                </td>
                <td style="text-align:center;">
                    <button type="button" class="del-btn" onclick="removeRow(this)">&times;</button>
                </td>
            `;

            tbody.appendChild(row);
            calculateTotal();
        }

        function removeRow(btn) {
            btn.closest('tr').remove();
            calculateTotal();
        }

        function calculateTotal() {
            const priceInputs = document.querySelectorAll('.price-input');
            let sum = 0;
            priceInputs.forEach(inp => {
                sum += parseFloat(inp.value) || 0;
            });

            document.getElementById('totalPriceDisplay').textContent = '₹' + sum.toFixed(2);
            document.getElementById('totalPriceInput').value = sum.toFixed(2);
        }

        function handlePatientSelect(select) {
            const option = select.selectedOptions[0];
            const apptId = option ? option.dataset.appt : '';
            document.getElementById('patientApptIdInput').value = apptId || '';
        }

        function validateRxForm() {
            const rows = document.querySelectorAll('#prescribedItemsTbody tr');
            if (rows.length === 0) {
                alert('Please select or add at least one medication from the catalog.');
                return false;
            }
            return true;
        }

        function previewRx(rx) {
            document.getElementById('viewRxDate').textContent = new Date(rx.created_at).toLocaleDateString();
            document.getElementById('viewRxPatient').textContent = rx.patient_name || 'Patient';
            document.getElementById('viewRxAgeGender').textContent = (rx.age ? rx.age + ' Yrs' : 'N/A') + ' / ' + (rx.gender ? rx.gender.toUpperCase() : 'N/A');
            document.getElementById('viewRxDiagnosis').textContent = rx.diagnosis || 'Clinical Assessment';
            
            const meds = (rx.medications || rx.medication || '').split('\n').map(m => `<div>• <strong>${escapeHtml(m)}</strong></div>`).join('');
            document.getElementById('viewRxMeds').innerHTML = meds;

            document.getElementById('viewRxInstructions').innerHTML = '<strong>Clinical Advice & Instructions:</strong><br>' + nl2br(escapeHtml(rx.instructions || rx.notes || 'Take medications as directed.'));

            document.getElementById('viewRxModal').style.display = 'block';
        }

        function closeViewRx() {
            document.getElementById('viewRxModal').style.display = 'none';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        function nl2br(text) {
            return (text || '').replace(/\n/g, '<br>');
        }

        document.addEventListener('DOMContentLoaded', () => {
            renderCatalog(medicineCatalog);
            // Default pre-population
            addMedicineToPrescription(1); // Paracetamol
            addMedicineToPrescription(16); // Pantoprazole
        });

        window.onclick = function(e) {
            if (e.target === document.getElementById('viewRxModal')) closeViewRx();
        }
    </script>
</body>
</html>