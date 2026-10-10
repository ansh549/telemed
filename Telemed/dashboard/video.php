<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();

$current_user = current_user();
$user_role = $current_user['role'] ?? ($_SESSION['role'] ?? '');
$appointment_id = (int)($_GET['appointment_id'] ?? 0);

if (!$appointment_id) {
    die("No appointment specified.");
}

// Get appointment details with proper role-based access
if ($user_role === 'patient') {
    $stmt = $db->prepare("SELECT a.*, d.name as doctor_name, p.name as patient_name, p.id as patient_id
        FROM appointments a 
        JOIN doctors d ON d.id = a.doctor_id 
        JOIN patients p ON p.id = a.patient_id 
        WHERE a.id = ? AND p.user_id = ?");
    $stmt->execute([$appointment_id, $current_user['id']]);
} elseif ($user_role === 'doctor') {
    $stmt = $db->prepare("SELECT a.*, d.name as doctor_name, p.name as patient_name, p.id as patient_id
        FROM appointments a 
        JOIN doctors d ON d.id = a.doctor_id 
        JOIN patients p ON p.id = a.patient_id 
        WHERE a.id = ? AND d.user_id = ?");
    $stmt->execute([$appointment_id, $current_user['id']]);
} else {
    die("Access denied.");
}

$appointment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appointment) {
    die("Appointment not found or access denied.");
}

// Update appointment status to in-progress if it was confirmed
if ($appointment['status'] === 'confirmed') {
    $stmt = $db->prepare("UPDATE appointments SET status = 'in-progress' WHERE id = ?");
    $stmt->execute([$appointment_id]);
    $appointment['status'] = 'in-progress';
}

// Generate unique room ID based on appointment
$room_id = 'consultation_' . $appointment_id . '_' . md5($appointment_id . date('Y-m-d'));

function safe_display($value, $default = '') {
    return htmlspecialchars($value ?? $default, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Consultation - Telemed</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #1a1a1a; 
            height: 100vh; 
            display: flex; 
            flex-direction: column; 
            overflow: hidden;
        }
        
        .navbar {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            padding: 0.75rem 1.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            z-index: 100;
        }
        
        .navbar h1 { font-size: 1.2rem; font-weight: 600; }
        .navbar .info { 
            font-size: 0.9rem; 
            opacity: 0.9; 
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .status-indicator {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-live {
            background: #ef4444;
            color: white;
            animation: pulse 2s infinite;
        }
        
        .back-btn { 
            color: white; 
            text-decoration: none; 
            padding: 0.5rem 1rem; 
            background: rgba(255,255,255,0.2); 
            border-radius: 6px; 
            transition: all 0.3s;
        }
        
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .video-container {
            flex: 1;
            display: flex;
            position: relative;
            background: #000;
        }
        
        .main-video {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1a1a1a;
        }
        
        .video-element {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #2a2a2a;
        }
        
        .video-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            background: radial-gradient(circle at center, #2a2a2a 0%, #1a1a1a 100%);
        }
        
        .video-placeholder .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.6;
        }
        
        .video-placeholder .text {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .video-placeholder .subtext {
            font-size: 0.9rem;
            opacity: 0.7;
        }
        
        .local-video {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 200px;
            height: 150px;
            background: #2a2a2a;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #059669;
            z-index: 10;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .local-video:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 20px rgba(5, 150, 105, 0.3);
        }
        
        .local-video video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .local-video-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 0.8rem;
        }
        
        .controls {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 1rem;
            background: rgba(0,0,0,0.8);
            padding: 1rem 1.5rem;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .control-btn {
            width: 50px;
            height: 50px;
            border: none;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
        }
        
        .control-btn.camera {
            background: #6b7280;
            color: white;
        }
        
        .control-btn.camera.active {
            background: #059669;
            color: white;
        }
        
        .control-btn.mic {
            background: #6b7280;
            color: white;
        }
        
        .control-btn.mic.active {
            background: #059669;
            color: white;
        }
        
        .control-btn.end-call {
            background: #ef4444;
            color: white;
        }
        
        .control-btn.chat {
            background: #0ea5e9;
            color: white;
        }
        
        .control-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        
        .control-btn:active {
            transform: scale(0.95);
        }
        
        .sidebar {
            width: 300px;
            background: #f8fafc;
            border-left: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.3s;
        }
        
        .sidebar.open {
            transform: translateX(0);
        }
        
        .sidebar-header {
            padding: 1rem 1.5rem;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }
        
        .patient-info {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .patient-info h3 {
            color: #1f2937;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }
        
        .info-label {
            color: #6b7280;
            font-weight: 500;
        }
        
        .info-value {
            color: #1f2937;
            font-weight: 600;
        }
        
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .action-btn {
            padding: 0.75rem 1rem;
            background: #059669;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            text-align: center;
        }
        
        .action-btn:hover {
            background: #047857;
            transform: translateY(-1px);
        }
        
        .action-btn.secondary {
            background: #6b7280;
        }
        
        .action-btn.secondary:hover {
            background: #4b5563;
        }
        
        .connection-status {
            position: absolute;
            top: 80px;
            left: 20px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            backdrop-filter: blur(10px);
        }
        
        .connection-good { border-left: 3px solid #10b981; }
        .connection-poor { border-left: 3px solid #f59e0b; }
        .connection-lost { border-left: 3px solid #ef4444; }
        
        .notification {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.9rem;
            z-index: 20;
            animation: slideDown 0.3s;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        @keyframes slideDown {
            from { transform: translate(-50%, -100%); opacity: 0; }
            to { transform: translate(-50%, 0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .local-video {
                width: 120px;
                height: 90px;
                top: 10px;
                right: 10px;
            }
            
            .controls {
                bottom: 10px;
                padding: 0.75rem 1rem;
                gap: 0.75rem;
            }
            
            .control-btn {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .sidebar {
                width: 100%;
                position: absolute;
                top: 0;
                bottom: 0;
                z-index: 50;
            }
            
            .navbar .info {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>📹 Video Consultation</h1>
        <div class="info">
            <span><?= $user_role === 'patient' ? 'Dr. ' . safe_display($appointment['doctor_name']) : safe_display($appointment['patient_name']) ?></span>
            <div class="status-indicator status-live">🔴 LIVE</div>
        </div>
        <a href="<?= $user_role ?>.php" class="back-btn">← End Call</a>
    </div>

    <div class="video-container">
        <div class="main-video">
            <video id="remoteVideo" class="video-element" autoplay playsinline></video>
            <div class="video-placeholder" id="remotePlaceholder">
                <div class="icon">👤</div>
                <div class="text">Waiting for <?= $user_role === 'doctor' ? 'patient' : 'doctor' ?> to join...</div>
                <div class="subtext">They will appear here once connected</div>
            </div>
        </div>

        <div class="local-video" id="localVideoContainer">
            <video id="localVideo" autoplay muted playsinline></video>
            <div class="local-video-placeholder" id="localPlaceholder">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📷</div>
                <div>Your Camera</div>
            </div>
        </div>

        <div class="connection-status connection-good" id="connectionStatus">
            🟢 Connected
        </div>

        <div class="controls">
            <button class="control-btn camera active" id="cameraBtn" title="Toggle Camera">
                📷
            </button>
            <button class="control-btn mic active" id="micBtn" title="Toggle Microphone">
                🎤
            </button>
            <button class="control-btn chat" id="chatBtn" title="Open Chat">
                💬
            </button>
            <button class="control-btn end-call" id="endCallBtn" title="End Call">
                📞
            </button>
        </div>

        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Consultation Info</h3>
                <button onclick="closeSidebar()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">×</button>
            </div>
            <div class="sidebar-content">
                <div class="patient-info">
                    <h3><?= $user_role === 'doctor' ? 'Patient Information' : 'Consultation Details' ?></h3>
                    <div class="info-item">
                        <span class="info-label">Name:</span>
                        <span class="info-value"><?= $user_role === 'doctor' ? safe_display($appointment['patient_name']) : 'Dr. ' . safe_display($appointment['doctor_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date:</span>
                        <span class="info-value"><?= date('M d, Y', strtotime($appointment['appointment_date'])) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Time:</span>
                        <span class="info-value"><?= date('g:i A', strtotime($appointment['appointment_date'])) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Type:</span>
                        <span class="info-value">Video Consultation</span>
                    </div>
                    <?php if ($appointment['symptoms']): ?>
                        <div class="info-item" style="flex-direction: column; align-items: flex-start;">
                            <span class="info-label">Symptoms:</span>
                            <span class="info-value" style="margin-top: 0.25rem;"><?= safe_display($appointment['symptoms']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="quick-actions">
                    <a href="chat.php?appointment_id=<?= $appointment_id ?>" class="action-btn" target="_blank">
                        💬 Open Chat
                    </a>
                    <?php if ($user_role === 'doctor'): ?>
                        <a href="create-prescription.php?appointment_id=<?= $appointment_id ?>" class="action-btn secondary" target="_blank">
                            💊 Create Prescription
                        </a>
                        <button onclick="completeConsultation()" class="action-btn secondary">
                            ✅ Complete Consultation
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let localStream = null;
        let remoteStream = null;
        let peerConnection = null;
        let socket = null;
        let isConnected = false;
        let cameraEnabled = true;
        let micEnabled = true;

        // Room and user info
        const roomId = '<?= $room_id ?>';
        const userId = '<?= $current_user['id'] ?>';
        const userRole = '<?= $user_role ?>';
        const appointmentId = <?= $appointment_id ?>;

        // Initialize video call
        async function initializeCall() {
            try {
                // Get user media
                localStream = await navigator.mediaDevices.getUserMedia({
                    video: true,
                    audio: true
                });

                const localVideo = document.getElementById('localVideo');
                localVideo.srcObject = localStream;
                document.getElementById('localPlaceholder').style.display = 'none';

                // Initialize WebRTC
                initializePeerConnection();
                
                // Simulate connection (in real app, you'd use WebSocket/Socket.io)
                simulateConnection();
                
                showNotification('Camera and microphone ready');
            } catch (error) {
                console.error('Error accessing media devices:', error);
                showNotification('Unable to access camera/microphone');
                updateConnectionStatus('lost');
            }
        }

        function initializePeerConnection() {
            // WebRTC peer connection setup
            const configuration = {
                iceServers: [
                    { urls: 'stun:stun.l.google.com:19302' },
                    { urls: 'stun:stun1.l.google.com:19302' }
                ]
            };

            peerConnection = new RTCPeerConnection(configuration);

            // Add local stream to peer connection
            localStream.getTracks().forEach(track => {
                peerConnection.addTrack(track, localStream);
            });

            // Handle remote stream
            peerConnection.ontrack = (event) => {
                const remoteVideo = document.getElementById('remoteVideo');
                remoteVideo.srcObject = event.streams[0];
                document.getElementById('remotePlaceholder').style.display = 'none';
                updateConnectionStatus('good');
                showNotification(`${userRole === 'doctor' ? 'Patient' : 'Doctor'} joined the call`);
            };

            // Handle connection state changes
            peerConnection.onconnectionstatechange = () => {
                const state = peerConnection.connectionState;
                console.log('Connection state:', state);
                
                switch (state) {
                    case 'connected':
                        updateConnectionStatus('good');
                        break;
                    case 'disconnected':
                        updateConnectionStatus('poor');
                        break;
                    case 'failed':
                    case 'closed':
                        updateConnectionStatus('lost');
                        break;
                }
            };
        }

        function simulateConnection() {
            // Simulate remote peer joining after 3 seconds
            setTimeout(() => {
                const remoteVideo = document.getElementById('remoteVideo');
                // In a real application, this would be the actual remote stream
                // For demo purposes, we'll just hide the placeholder
                document.getElementById('remotePlaceholder').innerHTML = `
                    <div class="icon">📹</div>
                    <div class="text">Connected to ${userRole === 'doctor' ? 'Patient' : 'Doctor'}</div>
                    <div class="subtext">Video call in progress</div>
                `;
                updateConnectionStatus('good');
                isConnected = true;
            }, 3000);
        }

        // Control functions
        function toggleCamera() {
            if (localStream) {
                const videoTrack = localStream.getVideoTracks()[0];
                if (videoTrack) {
                    videoTrack.enabled = !videoTrack.enabled;
                    cameraEnabled = videoTrack.enabled;
                    
                    const cameraBtn = document.getElementById('cameraBtn');
                    if (cameraEnabled) {
                        cameraBtn.classList.add('active');
                        cameraBtn.innerHTML = '📷';
                        document.getElementById('localPlaceholder').style.display = 'none';
                    } else {
                        cameraBtn.classList.remove('active');
                        cameraBtn.innerHTML = '📷';
                        cameraBtn.style.background = '#ef4444';
                        document.getElementById('localPlaceholder').style.display = 'flex';
                        document.getElementById('localPlaceholder').innerHTML = `
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">📷</div>
                            <div>Camera Off</div>
                        `;
                    }
                    showNotification(`Camera ${cameraEnabled ? 'enabled' : 'disabled'}`);
                }
            }
        }

        function toggleMicrophone() {
            if (localStream) {
                const audioTrack = localStream.getAudioTracks()[0];
                if (audioTrack) {
                    audioTrack.enabled = !audioTrack.enabled;
                    micEnabled = audioTrack.enabled;
                    
                    const micBtn = document.getElementById('micBtn');
                    if (micEnabled) {
                        micBtn.classList.add('active');
                        micBtn.innerHTML = '🎤';
                        micBtn.style.background = '#059669';
                    } else {
                        micBtn.classList.remove('active');
                        micBtn.innerHTML = '🔇';
                        micBtn.style.background = '#ef4444';
                    }
                    showNotification(`Microphone ${micEnabled ? 'enabled' : 'disabled'}`);
                }
            }
        }

        function openChat() {
            window.open(`chat.php?appointment_id=${appointmentId}`, '_blank', 'width=800,height=600');
        }

        function endCall() {
            if (confirm('Are you sure you want to end this consultation?')) {
                // Clean up streams
                if (localStream) {
                    localStream.getTracks().forEach(track => track.stop());
                }
                if (peerConnection) {
                    peerConnection.close();
                }
                
                // Redirect back to dashboard
                window.location.href = userRole + '.php';
            }
        }

        function completeConsultation() {
            if (confirm('Mark this consultation as completed?')) {
                window.location.href = `complete-consultation.php?appointment_id=${appointmentId}`;
            }
        }

        function updateConnectionStatus(status) {
            const statusEl = document.getElementById('connectionStatus');
            statusEl.className = `connection-status connection-${status}`;
            
            switch (status) {
                case 'good':
                    statusEl.innerHTML = '🟢 Connected';
                    break;
                case 'poor':
                    statusEl.innerHTML = '🟡 Poor Connection';
                    break;
                case 'lost':
                    statusEl.innerHTML = '🔴 Connection Lost';
                    break;
            }
        }

        function showNotification(message) {
            const notification = document.createElement('div');
            notification.className = 'notification';
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
        }

        // Event listeners
        document.getElementById('cameraBtn').addEventListener('click', toggleCamera);
        document.getElementById('micBtn').addEventListener('click', toggleMicrophone);
        document.getElementById('chatBtn').addEventListener('click', openChat);
        document.getElementById('endCallBtn').addEventListener('click', endCall);

        // Toggle sidebar on mobile
        document.getElementById('localVideoContainer').addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.toggle('open');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'c' || e.key === 'C') toggleCamera();
            if (e.key === 'm' || e.key === 'M') toggleMicrophone();
            if (e.key === 'Escape') endCall();
        });

        // Handle page visibility
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Optionally pause video when tab is hidden
                console.log('Tab hidden');
            } else {
                console.log('Tab visible');
            }
        });

        // Handle beforeunload
        window.addEventListener('beforeunload', (e) => {
            if (isConnected) {
                e.preventDefault();
                e.returnValue = 'Are you sure you want to leave the consultation?';
            }
        });

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', () => {
            initializeCall();
            showNotification('Initializing video consultation...');
            
            // Auto-hide notifications after page load
            setTimeout(() => {
                showNotification('Press M to toggle microphone, C to toggle camera');
            }, 2000);
        });

        // Responsive handling
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });
    </script>
</body>
</html> 