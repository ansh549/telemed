<?php
// includes/db.php

$dbDir  = __DIR__ . '/../database';
$dbFile = $dbDir . '/telemed.db';

// Ensure the database directory exists
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

// Auto-initialize DB schema if database file does not exist
if (!file_exists($dbFile)) {
    $initScript = $dbDir . '/init_db.php';
    if (file_exists($initScript)) {
        require_once $initScript;
    }
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enforce foreign key constraints in SQLite
    $db->exec("PRAGMA foreign_keys = ON;");

    // Safe auto-migrations for system tables and columns
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password TEXT,
            email TEXT,
            role TEXT CHECK(role IN ('doctor','patient','admin')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS doctors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            specialization TEXT,
            qualification TEXT,
            contact TEXT,
            consultation_fee REAL DEFAULT 0.0,
            rating REAL DEFAULT 5.0,
            total_consultations INTEGER DEFAULT 0,
            is_available INTEGER DEFAULT 1,
            is_offline_available INTEGER DEFAULT 1,
            clinic_address TEXT DEFAULT 'Room 104, OPD Block A, City Clinic',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            age INTEGER,
            gender TEXT,
            contact TEXT,
            blood_type TEXT,
            allergies TEXT,
            medical_history TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            doctor_id INTEGER NOT NULL,
            appointment_date DATETIME NOT NULL,
            appointment_type TEXT DEFAULT 'video',
            symptoms TEXT,
            fee REAL DEFAULT 0.0,
            status TEXT DEFAULT 'pending',
            payment_status TEXT DEFAULT 'pending',
            payment_method TEXT,
            transaction_id TEXT,
            cancellation_reason TEXT,
            confirmed_at DATETIME,
            cancelled_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS prescriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER,
            doctor_id INTEGER NOT NULL,
            patient_id INTEGER,
            medications TEXT,
            medication TEXT,
            dosage TEXT,
            frequency TEXT,
            instructions TEXT,
            duration TEXT,
            notes TEXT,
            diagnosis TEXT,
            total_price REAL DEFAULT 0.0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            sender_type TEXT NOT NULL,
            message TEXT NOT NULL,
            message_type TEXT DEFAULT 'text',
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        );
    ");

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}