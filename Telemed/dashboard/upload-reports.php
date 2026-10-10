<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
if (!is_patient()) {
    header('Location: ../login.php');
    exit;
}

$current_user = current_user();
$patient_user_id = $current_user['id'];

// Get patient's profile
$stmt = $db->prepare("SELECT * FROM patients WHERE user_id = ?");
$stmt->execute([$patient_user_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

$error = "";
$success = "";

// Create uploads directory if it doesn't exist
$upload_dir = __DIR__ . '/../uploads/medical_reports/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['medical_file'])) {
    $report_type = $_POST['report_type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $appointment_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
    
    $file = $_FILES['medical_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload failed. Please try again.";
    } elseif ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
        $error = "File size must be less than 10MB.";
    } else {
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            $error = "Only PDF, JPG, PNG, DOC, and DOCX files are allowed.";
        } else {
            try {
                // Generate unique filename
                $filename = 'report_' . $patient['id'] . '_' . time() . '_' . uniqid() . '.' . $file_extension;
                $file_path = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    // Save to database
                    $stmt = $db->prepare("INSERT INTO medical_reports (patient_id, appointment_id, report_type, file_name, file_path, file_size, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$patient['id'], $appointment_id, $report_type, $file['name'], $file_path, $file['size'], $description]);
                    
                    // Log the upload
                    $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, description) VALUES (?, 'report_uploaded', 'Patient uploaded medical report')");
                    $stmt->execute([$patient_user_id]);
                    
                    $success = "Medical report uploaded successfully!";
                } else {
                    $error = "Failed to save file. Please try again.";
                }
            } catch (Exception $e) {
                $error = "Database error. Please try again.";
            }
        }
    }
}

// Get patient's uploaded reports
$stmt = $db->prepare("SELECT mr.*, a.appointment_date, d.name as doctor_name 
    FROM medical_reports mr 
    LEFT JOIN appointments a ON a.id = mr.appointment_id 
    LEFT JOIN doctors d ON d.id = a.doctor_id 
    WHERE mr.patient_id = ? 
    ORDER BY mr.upload_date DESC");
$stmt->execute([$patient['id']]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get patient's appointments for linking reports
$stmt = $db->prepare("SELECT a.id, a.appointment_date, d.name as doctor_name 
    FROM appointments a 
    JOIN doctors d ON d.id = a.doctor_id 
    WHERE a.patient_id = ? AND a.status != 'cancelled'
    ORDER BY a.appointment_date DESC");
$stmt->execute([$patient['id']]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Medical Reports - Telemed</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1rem 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h1 { font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; }
        
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1rem; }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 1rem;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .card-content { padding: 2rem; }
        
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            background: #f9fafb;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .upload-area.dragover {
            border-color: #10b981;
            background: #ecfdf5;
        }
        
        .upload-icon {
            font-size: 3rem;
            color: #6b7280;
            margin-bottom: 1rem;
            display: block;
        }
        
        .upload-text {
            font-size: 1.1rem;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .upload-hint {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .file-input {
            display: none;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #10b981;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .required { color: #ef4444; }
        
        .submit-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            width: 100%;
        }
        
        .submit-btn:hover { transform: translateY(-2px); }
        .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .error {
            background: #fef2f2;
            color: #dc2626;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #fecaca;
            margin-bottom: 1rem;
        }
        
        .success {
            background: #f0fdf4;
            color: #16a34a;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
            margin-bottom: 1rem;
        }
        
        .back-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 1rem;
            display: inline-block;
        }
        .back-link:hover { text-decoration: underline; }
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .report-item {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .report-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .report-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        
        .report-type {
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 0.5rem;
        }
        
        .report-date {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .report-size {
            color: #6b7280;
            font-size: 0.8rem;
        }
        
        .report-description {
            color: #4b5563;
            font-size: 0.9rem;
            margin: 0.5rem 0;
        }
        
        .report-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .btn-small {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .btn-view {
            background: #3b82f6;
            color: white;
        }
        
        .btn-delete {
            background: #ef4444;
            color: white;
        }
        
        .btn-small:hover { opacity: 0.8; }
        
        .empty-state {
            text-align: center;
            color: #6b7280;
            font-style: italic;
            padding: 3rem;
        }
        
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .reports-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🏥 <a href="patient.php">Telemed</a></h1>
        <div>
            <a href="patient.php">Dashboard</a> |
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="patient.php" class="back-link">← Back to Dashboard</a>
        
        <!-- Upload Form -->
        <div class="card">
            <div class="card-header">
                📄 Upload Medical Report
            </div>
            <div class="card-content">
                <?php if ($error): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                    <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                        <span class="upload-icon">📁</span>
                        <div class="upload-text">Click to browse or drag and drop</div>
                        <div class="upload-hint">PDF, JPG, PNG, DOC, DOCX (Max 10MB)</div>
                        <input type="file" id="fileInput" name="medical_file" class="file-input" 
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    </div>
                    
                    <div id="fileInfo" style="display: none; margin-top: 1rem; padding: 1rem; background: #f0f9ff; border-radius: 8px;">
                        <div id="fileName" style="font-weight: 600; color: #1e40af;"></div>
                        <div id="fileSize" style="color: #6b7280; font-size: 0.9rem;"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Report Type <span class="required">*</span></label>
                            <select name="report_type" required>
                                <option value="">Select report type...</option>
                                <option value="blood_test">Blood Test</option>
                                <option value="x_ray">X-Ray</option>
                                <option value="mri">MRI Scan</option>
                                <option value="ct_scan">CT Scan</option>
                                <option value="ultrasound">Ultrasound</option>
                                <option value="ecg">ECG/EKG</option>
                                <option value="prescription">Prescription</option>
                                <option value="discharge_summary">Discharge Summary</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Related Appointment (Optional)</label>
                            <select name="appointment_id">
                                <option value="">Not linked to any appointment</option>
                                <?php foreach ($appointments as $apt): ?>
                                    <option value="<?= $apt['id'] ?>">
                                        <?= date('M d, Y', strtotime($apt['appointment_date'])) ?> - Dr. <?= htmlspecialchars($apt['doctor_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" 
                                  placeholder="Add any notes or description about this report..."
                                  rows="3"></textarea>
                    </div>

                    <button type="submit" class="submit-btn">Upload Report</button>
                </form>
            </div>
        </div>

        <!-- My Reports -->
        <div class="card">
            <div class="card-header">
                📋 My Medical Reports
            </div>
            <div class="card-content">
                <?php if (empty($reports)): ?>
                    <div class="empty-state">
                        No medical reports uploaded yet.<br>
                        Upload your first report using the form above.
                    </div>
                <?php else: ?>
                    <div class="reports-grid">
                        <?php foreach ($reports as $report): ?>
                            <div class="report-item">
                                <div class="report-name"><?= htmlspecialchars($report['file_name']) ?></div>
                                <span class="report-type"><?= ucwords(str_replace('_', ' ', $report['report_type'])) ?></span>
                                <div class="report-date">
                                    Uploaded: <?= date('M d, Y g:i A', strtotime($report['upload_date'])) ?>
                                </div>
                                <?php if ($report['doctor_name']): ?>
                                    <div class="report-date">
                                        Appointment: <?= date('M d, Y', strtotime($report['appointment_date'])) ?> - Dr. <?= htmlspecialchars($report['doctor_name']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="report-size">
                                    Size: <?= number_format($report['file_size'] / 1024, 1) ?> KB
                                </div>
                                <?php if ($report['description']): ?>
                                    <div class="report-description">
                                        <?= nl2br(htmlspecialchars($report['description'])) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="report-actions">
                                    <a href="view-report.php?id=<?= $report['id'] ?>" class="btn-small btn-view" target="_blank">
                                        👁️ View
                                    </a>
                                    <a href="delete-report.php?id=<?= $report['id'] ?>" 
                                       class="btn-small btn-delete"
                                       onclick="return confirm('Are you sure you want to delete this report?')">
                                        🗑️ Delete
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // File upload handling
        const uploadArea = document.querySelector('.upload-area');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');

        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                showFileInfo(files[0]);
            }
        });

        // File input change
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                showFileInfo(e.target.files[0]);
            }
        });

        function showFileInfo(file) {
            fileName.textContent = file.name;
            fileSize.textContent = `Size: ${(file.size / 1024 / 1024).toFixed(2)} MB`;
            fileInfo.style.display = 'block';
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>