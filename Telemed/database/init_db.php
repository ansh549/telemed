<?php
// database/init_db.php
$dbFile = __DIR__ . '/telemed.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /*
    ==========================
     USERS (Login Accounts)
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','doctor','patient')),
            email TEXT UNIQUE,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    /*
    ==========================
     PATIENTS
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            age INTEGER,
            gender TEXT CHECK(gender IN ('male','female','other')),
            contact TEXT,
            email TEXT,
            address TEXT,
            medical_history TEXT,
            emergency_contact TEXT,
            blood_type TEXT,
            allergies TEXT,
            profile_picture TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     DOCTORS
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS doctors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            specialization TEXT,
            qualification TEXT,
            experience INTEGER,
            contact TEXT,
            availability TEXT,
            consultation_fee DECIMAL(10,2) DEFAULT 0,
            profile_picture TEXT,
            is_available INTEGER DEFAULT 1,
            rating DECIMAL(3,2) DEFAULT 0,
            total_consultations INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     APPOINTMENTS (Updated with all required columns)
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            doctor_id INTEGER NOT NULL,
            appointment_date DATETIME NOT NULL,
            appointment_type TEXT DEFAULT 'chat' CHECK(appointment_type IN ('chat','video','in-person')),
            status TEXT DEFAULT 'pending' CHECK(status IN ('pending','confirmed','completed','cancelled','in-progress')),
            notes TEXT,
            symptoms TEXT,
            fee DECIMAL(10,2),
            payment_status TEXT DEFAULT 'pending' CHECK(payment_status IN ('pending','paid','refunded')),
            consultation_link TEXT,
            confirmed_at DATETIME,
            cancelled_at DATETIME,
            cancellation_reason TEXT,
            reschedule_reason TEXT,
            rescheduled_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     PRESCRIPTIONS
    ==========================
    */
    $db->exec("
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     MEDICAL REPORTS
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS medical_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            appointment_id INTEGER,
            report_type TEXT NOT NULL,
            file_name TEXT NOT NULL,
            file_path TEXT NOT NULL,
            file_size INTEGER,
            upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            description TEXT,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
        );
    ");

    /*
    ==========================
     CHAT MESSAGES
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            sender_type TEXT NOT NULL CHECK(sender_type IN ('doctor','patient')),
            message TEXT NOT NULL,
            message_type TEXT DEFAULT 'text' CHECK(message_type IN ('text','image','file','prescription')),
            file_path TEXT,
            is_read INTEGER DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     DOCTOR AVAILABILITY
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS doctor_availability (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doctor_id INTEGER NOT NULL,
            day_of_week TEXT NOT NULL CHECK(day_of_week IN ('monday','tuesday','wednesday','thursday','friday','saturday','sunday')),
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            is_available INTEGER DEFAULT 1,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     PATIENT REVIEWS
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS patient_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            doctor_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            rating INTEGER NOT NULL CHECK(rating >= 1 AND rating <= 5),
            review TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        );
    ");

    /*
    ==========================
     SYSTEM LOGS
    ==========================
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            description TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    /*
    ==========================
     DEFAULT USERS & SAMPLE DATA
    ==========================
    */
    
    // Check if users already exist to avoid duplicates
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    
    if ($userCount == 0) {
        $defaultUsers = [
            ['admin', password_hash('Admin@123', PASSWORD_DEFAULT), 'admin', 'admin@telemed.com'],
            ['Nishika', password_hash('Nishika@123', PASSWORD_DEFAULT), 'doctor', 'Nishika@telemed.com'],
            ['Vansh', password_hash('Vansh@123', PASSWORD_DEFAULT), 'doctor', 'vansh@telemed.com'],
            ['NishikaPatni', password_hash('Nishika@123', PASSWORD_DEFAULT), 'patient', 'Nishikapatni@telemed.com'],
            ['VanshPatni', password_hash('Vansh@123', PASSWORD_DEFAULT), 'patient', 'vanshpatni@telemed.com'],
        ];

        $stmt = $db->prepare("INSERT INTO users (username, password, role, email) VALUES (?, ?, ?, ?)");
        foreach ($defaultUsers as $user) {
            $stmt->execute($user);
        }
        
        echo "✅ Default users created\n";
    }

    // Get user IDs
    $adminId   = $db->query("SELECT id FROM users WHERE username='admin'")->fetchColumn();
    $doctor1Id = $db->query("SELECT id FROM users WHERE username='Nishika'")->fetchColumn();
    $doctor2Id = $db->query("SELECT id FROM users WHERE username='Vansh'")->fetchColumn();
    $patient1Id = $db->query("SELECT id FROM users WHERE username='NishikaPatni'")->fetchColumn();
    $patient2Id = $db->query("SELECT id FROM users WHERE username='VanshPatni'")->fetchColumn();

    // Insert doctor profiles
    $doctorCount = $db->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
    if ($doctorCount == 0) {
        $db->exec("
            INSERT INTO doctors (user_id, name, specialization, qualification, experience, contact, availability, consultation_fee, rating, total_consultations)
            VALUES 
            ($doctor1Id, 'Dr. Nishika', 'Cardiology', 'MBBS, MD (Cardiology)', 10, '9876543210', 'Mon-Fri 9am-5pm', 800.00, 4.7, 245),
            ($doctor2Id, 'Dr. Vansh', 'Dermatology', 'MBBS, MD (Dermatology)', 8, '9876543211', 'Mon-Sat 10am-6pm', 600.00, 4.5, 189)
        ");
        echo "✅ Doctor profiles created\n";
    }

    // Insert patient profiles
    $patientCount = $db->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    if ($patientCount == 0) {
        $db->exec("
            INSERT INTO patients (user_id, name, age, gender, contact, email, address, medical_history, blood_type, allergies)
            VALUES 
            ($patient1Id, 'Nishika Patni', 28, 'female', '9876501234', 'patient1@telemed.com', '123 Main St, City', 'Hypertension', 'A+', 'None'),
            ($patient2Id, 'Vansh Patni', 35, 'male', '9876501235', 'patient2@telemed.com', '456 Oak Ave, City', 'Diabetes Type 2', 'O+', 'Penicillin')
        ");
        echo "✅ Patient profiles created\n";
    }

    // Insert doctor availability
    $availabilityCount = $db->query("SELECT COUNT(*) FROM doctor_availability")->fetchColumn();
    if ($availabilityCount == 0) {
        $doctor1ProfileId = $db->query("SELECT id FROM doctors WHERE user_id=$doctor1Id")->fetchColumn();
        $doctor2ProfileId = $db->query("SELECT id FROM doctors WHERE user_id=$doctor2Id")->fetchColumn();
        
        $stmt = $db->prepare("INSERT INTO doctor_availability (doctor_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)");
        
        // Dr. Smith availability (Mon-Fri 9-5)
        $days1 = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        foreach ($days1 as $day) {
            $stmt->execute([$doctor1ProfileId, $day, '09:00:00', '17:00:00']);
        }
        
        // Dr. Wilson availability (Mon-Sat 10-6)
        $days2 = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        foreach ($days2 as $day) {
            $stmt->execute([$doctor2ProfileId, $day, '10:00:00', '18:00:00']);
        }
        
        echo "✅ Doctor availability schedules created\n";
    }

    // Insert sample appointments for testing
    $appointmentCount = $db->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
    if ($appointmentCount == 0) {
        $patient1ProfileId = $db->query("SELECT id FROM patients WHERE user_id=$patient1Id")->fetchColumn();
        $patient2ProfileId = $db->query("SELECT id FROM patients WHERE user_id=$patient2Id")->fetchColumn();
        $doctor1ProfileId = $db->query("SELECT id FROM doctors WHERE user_id=$doctor1Id")->fetchColumn();
        $doctor2ProfileId = $db->query("SELECT id FROM doctors WHERE user_id=$doctor2Id")->fetchColumn();
        
        $sampleAppointments = [
            // Today's appointments
            [$patient1ProfileId, $doctor1ProfileId, date('Y-m-d H:i:s', strtotime('today 10:00')), 'video', 'confirmed', 'Chest pain and shortness of breath', 800.00, 'paid'],
            [$patient2ProfileId, $doctor2ProfileId, date('Y-m-d H:i:s', strtotime('today 14:00')), 'chat', 'pending', 'Skin rash on arms', 600.00, 'pending'],
            
            // Tomorrow's appointments  
            [$patient1ProfileId, $doctor1ProfileId, date('Y-m-d H:i:s', strtotime('tomorrow 11:00')), 'chat', 'confirmed', 'Follow-up consultation', 800.00, 'paid'],
            [$patient2ProfileId, $doctor2ProfileId, date('Y-m-d H:i:s', strtotime('tomorrow 15:30')), 'video', 'pending', 'Acne treatment consultation', 600.00, 'pending'],
            
            // Next week appointments
            [$patient1ProfileId, $doctor1ProfileId, date('Y-m-d H:i:s', strtotime('+7 days 09:30')), 'video', 'confirmed', 'Regular checkup', 800.00, 'paid'],
        ];
        
        $stmt = $db->prepare("
            INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_type, status, symptoms, fee, payment_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($sampleAppointments as $apt) {
            $stmt->execute($apt);
        }
        
        echo "✅ Sample appointments created\n";
    }

    echo "✅ Telemed database with complete schema initialized successfully!\n";
    echo "📋 Default login credentials:\n";
    echo "   Admin: admin / Admin@123\n";  
    echo "   Doctor: ansh / ansh@123\n";
    echo "   Doctor: himesh / Vansh@123\n";
    echo "   Patient: Vanshpatni / vansh@123\n";
    echo "   Patient: NishikaPatni / Nishika@123\n";
    
} catch (PDOException $e) {
    die("❌ DB Error: " . $e->getMessage());
}
?>