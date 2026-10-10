<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Sanitize Room Identifier
$raw_room = trim($_GET['room'] ?? '');
if ($raw_room === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/',$raw_room)) {
    die("<div style='font-family:sans-serif;padding:30px;text-align:center;'><h2>Invalid Consultation Room</h2><p>Please launch the call from your dashboard appointments list.</p><a href='index.php'>Return Home</a></div>");
}
$room = htmlspecialchars($raw_room, ENT_QUOTES, 'UTF-8');

// 2. Identify Role (Using in_array to prevent operator corruption)
$role_param = strtolower(trim($_GET['role'] ?? ''));
if (in_array($role_param, ['doctor', 'patient'], true)) {
    $my_role =$role_param;
} elseif (isset($_SESSION['user']['role'])) {
    $my_role = (strtolower($_SESSION['user']['role']) === 'doctor') ? 'doctor' : 'patient';
} else {
    $my_role = 'patient';
}

$target_role = ($my_role === 'doctor') ? 'patient' : 'doctor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeleMed Consultation - Room <?= $room ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js"></script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .mirror-mode { transform: scaleX(-1); }
    </style>
</head>
<body class="bg-slate-950 text-white min-h-screen flex flex-col justify-between overflow-hidden">

    <!-- Top Consultation Bar -->
    <header class="bg-slate-900/90 backdrop-blur border-b border-slate-800 px-4 sm:px-6 py-3 flex items-center justify-between z-20">
        <div class="flex items-center gap-3">
            <span class="text-2xl">🏣</span>
            <div>
                <h1 class="text-sm sm:text-base font-extrabold text-white tracking-tight">TeleMed Video Consultation</h1>
                <p class="text-xs text-slate-400">
                    Room: <span class="font-mono text-indigo-400 font-bold"><?= $room ?></span> &bull; 
                    Portal: <span class="font-bold text-emerald-400 uppercase"><?= $my_role ?></span>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <span id="connectionBadge" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                <span id="statusDot" class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                <span id="statusText">Starting Device...</span>
            </span>

            <button onclick="copyInviteLink()" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-bold transition text-slate-300 border border-slate-700">
                <span>📋</span> Copy Link
            </button>
        </div>
    </header>

    <!-- Multi-Browser Notice Banner -->
    <div id="camConflictBanner" class="hidden mx-4 mt-2 p-2.5 rounded-2xl bg-indigo-950/60 border border-indigo-800/60 text-xs text-indigo-200 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span>ℹ️</span>
            <span>Testing 2 browsers on 1 PC: Camera locked by other window. Audio & interactive visual feed are live.</span>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="retryRealCamera()" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-lg transition text-[11px]">
                📷 Grab Real Camera
            </button>
            <button onclick="shareScreenAsVideo()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-lg transition text-[11px]">
                🖥️ Share Screen
            </button>
        </div>
    </div>

    <!-- Video Grid -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 flex items-center justify-center relative">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 w-full h-[65vh] sm:h-[72vh]">
            
            <!-- Local Feed (Self) -->
            <div class="relative bg-slate-900 rounded-3xl overflow-hidden border border-slate-800 shadow-2xl flex items-center justify-center">
                <video id="myVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                <div class="absolute bottom-3 left-3 bg-slate-950/80 backdrop-blur px-3 py-1.5 rounded-xl text-xs font-bold text-slate-200 border border-slate-700/70">
                    You (<?= ucfirst($my_role) ?>)
                </div>
            </div>

            <!-- Remote Feed (Participant) -->
            <div class="relative bg-slate-900 rounded-3xl overflow-hidden border border-slate-800 shadow-2xl flex items-center justify-center">
                <video id="otherVideo" autoplay playsinline class="w-full h-full object-cover"></video>
                
                <div id="remotePlaceholder" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-900 text-slate-400 space-y-3 p-4 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-800 flex items-center justify-center text-3xl">
                        🩺
                    </div>
                    <p class="text-sm font-bold text-slate-300">
                        Waiting for <?= ucfirst($target_role) ?> to connect...
                    </p>
                    <p id="subStatusText" class="text-xs text-slate-500">
                        Searching for participant in Room <?= $room ?>...
                    </p>
                </div>

                <div class="absolute bottom-3 left-3 bg-slate-950/80 backdrop-blur px-3 py-1.5 rounded-xl text-xs font-bold text-slate-200 border border-slate-700/70">
                    <?= ucfirst($target_role) ?>
                </div>
            </div>

        </div>
    </main>

    <!-- Controls Dock -->
    <footer class="bg-slate-900/90 backdrop-blur border-t border-slate-800 py-4 px-6 z-20">
        <div class="max-w-md mx-auto flex items-center justify-center gap-3 sm:gap-4">
            <button id="micBtn" onclick="toggleAudio()" class="w-12 h-12 rounded-2xl bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center text-lg transition border border-slate-700" title="Mute/Unmute Mic">
                🎤
            </button>

            <button id="camBtn" onclick="toggleVideo()" class="w-12 h-12 rounded-2xl bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center text-lg transition border border-slate-700" title="Camera On/Off">
                📹
            </button>

            <button id="screenBtn" onclick="shareScreenAsVideo()" class="px-4 h-12 rounded-2xl bg-slate-800 hover:bg-slate-700 text-white font-bold text-xs flex items-center gap-1.5 transition border border-slate-700" title="Share Screen">
                <span>🖥️</span> Screen
            </button>

            <button onclick="endCall()" class="px-5 h-12 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold text-xs uppercase tracking-wider flex items-center gap-1.5 transition shadow-lg shadow-rose-600/30">
                <span>📞</span> Leave
            </button>
        </div>
    </footer>

    <script>
        const roomName = "<?= $room ?>";
        const myRole = "<?= $my_role ?>";
        const targetRole = "<?= $target_role ?>";

        const myPeerId = "telemed_sync_" + roomName + "_" + myRole;
        const targetPeerId = "telemed_sync_" + roomName + "_" + targetRole;

        let localStream = null;
        let peer = null;
        let activeCall = null;
        let handshakeInterval = null;
        let isConnected = false;
        let isAudioMuted = false;
        let isVideoOff = false;
        let isUsingRealCamera = false;
        let animInterval = null;
        let audioContext = null;
        let analyser = null;
        let audioDataArray = null;

        // 1. Setup Local Media with Audio-Reactive Visualizer Fallback
        async function setupLocalMedia() {
            try {
                localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                isUsingRealCamera = true;
                document.getElementById('myVideo').classList.add('mirror-mode');
                document.getElementById('camConflictBanner').classList.add('hidden');
            } catch (err) {
                // Physical camera is locked by the other browser window
                isUsingRealCamera = false;
                document.getElementById('camConflictBanner').classList.remove('hidden');

                let realAudioTrack = null;
                try {
                    const audioStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
                    realAudioTrack = audioStream.getAudioTracks()[0];
                    setupAudioAnalyser(audioStream);
                } catch (audioErr) {
                    console.warn("Microphone blocked", audioErr);
                }

                const canvasStream = createActiveVisualizerStream(myRole);
                localStream = new MediaStream([
                    canvasStream.getVideoTracks()[0],
                    ...(realAudioTrack ? [realAudioTrack] : canvasStream.getAudioTracks())
                ]);
            }

            document.getElementById('myVideo').srcObject = localStream;
            initPeer();
        }

        // Setup real-time voice meter for the visualizer
        function setupAudioAnalyser(stream) {
            try {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const source = audioContext.createMediaStreamSource(stream);
                analyser = audioContext.createAnalyser();
                analyser.fftSize = 64;
                source.connect(analyser);
                audioDataArray = new Uint8Array(analyser.frequencyBinCount);
            } catch (e) {}
        }

        // Live, high-frame-rate animated video canvas that reacts to speech
        function createActiveVisualizerStream(role) {
            const canvas = document.createElement('canvas');
            canvas.width = 640;
            canvas.height = 480;
            const ctx = canvas.getContext('2d');
            let tick = 0;

            function render() {
                ctx.fillStyle = '#090d16';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // Grid lines background
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 1;
                for (let x = 0; x < canvas.width; x += 40) {
                    ctx.beginPath();
                    ctx.moveTo(x, 0);
                    ctx.lineTo(x, canvas.height);
                    ctx.stroke();
                }

                // Avatar Circle
                ctx.fillStyle = '#1e293b';
                ctx.beginPath();
                ctx.arc(320, 180, 65, 0, Math.PI * 2);
                ctx.fill();

                // Role Icon
                ctx.font = '55px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(role === 'doctor' ? '👨‍⚕️' : '👤', 320, 180);

                // Label
                ctx.fillStyle = '#10b981';
                ctx.font = 'bold 22px sans-serif';
                ctx.fillText(role.toUpperCase() + ' LIVE STREAM', 320, 280);

                // Microphone Audio Level Waveform
                let volume = 5;
                if (analyser && audioDataArray) {
                    analyser.getByteFrequencyData(audioDataArray);
                    let sum = 0;
                    for (let i = 0; i < audioDataArray.length; i++) sum += audioDataArray[i];
                    volume = Math.max(5, (sum / audioDataArray.length) * 0.8);
                }

                ctx.fillStyle = '#38bdf8';
                for (let b = -6; b <= 6; b++) {
                    const barHeight = Math.min(60, volume * (1 - Math.abs(b) * 0.12) + Math.sin(tick * 0.2 + b) * 4);
                    ctx.fillRect(320 + b * 14 - 4, 340 - barHeight / 2, 8, Math.max(6, barHeight));
                }

                // Subtitle
                ctx.fillStyle = '#94a3b8';
                ctx.font = '13px sans-serif';
                ctx.fillText('Audio Live • Real-time Speech Waveform', 320, 390);

                tick++;
            }

            animInterval = setInterval(render, 50); // Steady 20 FPS
            render();

            const stream = canvas.captureStream(25);

            // Add silent audio fallback track to prevent SDP negotiation errors
            try {
                const dummyCtx = new (window.AudioContext || window.webkitAudioContext)();
                const dest = dummyCtx.createMediaStreamDestination();
                const osc = dummyCtx.createOscillator();
                const gain = dummyCtx.createGain();
                gain.gain.value = 0.0001;
                osc.connect(gain);
                gain.connect(dest);
                osc.start();
                stream.addTrack(dest.stream.getAudioTracks()[0]);
            } catch (e) {}

            return stream;
        }

        // Retry physical webcam dynamically without reloading
        async function retryRealCamera() {
            try {
                const freshStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                const freshVideoTrack = freshStream.getVideoTracks()[0];

                replaceVideoTrack(freshVideoTrack);
                document.getElementById('myVideo').classList.add('mirror-mode');
                document.getElementById('camConflictBanner').classList.add('hidden');
                isUsingRealCamera = true;
                if (animInterval) clearInterval(animInterval);
            } catch (e) {
                alert("The other browser window is still holding the webcam. Close or mute video in the other window first, then click here again.");
            }
        }

        // Dynamic Screen Share as Video Track
        async function shareScreenAsVideo() {
            try {
                const screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
                const screenVideoTrack = screenStream.getVideoTracks()[0];

                replaceVideoTrack(screenVideoTrack);
                document.getElementById('myVideo').classList.remove('mirror-mode');
                document.getElementById('camConflictBanner').classList.add('hidden');

                screenVideoTrack.onended = () => {
                    setupLocalMedia();
                };
            } catch (e) {}
        }

        // Replace outgoing track inside WebRTC peer connection
        function replaceVideoTrack(newTrack) {
            const oldTrack = localStream.getVideoTracks()[0];
            if (oldTrack) {
                localStream.removeTrack(oldTrack);
                oldTrack.stop();
            }
            localStream.addTrack(newTrack);
            document.getElementById('myVideo').srcObject = localStream;

            if (activeCall && activeCall.peerConnection) {
                const sender = activeCall.peerConnection.getSenders().find(s => s.track && s.track.kind === 'video');
                if (sender) {
                    sender.replaceTrack(newTrack);
                }
            }
        }

        // 2. PeerJS Setup
        function initPeer() {
            peer = new Peer(myPeerId, {
                debug: 1,
                config: {
                    iceServers: [
                        { urls: 'stun:stun.l.google.com:19302' },
                        { urls: 'stun:stun1.l.google.com:19302' }
                    ]
                }
            });

            peer.on('open', () => {
                setStatus("Waiting for " + targetRole + "...", "amber");
                startHandshake();
            });

            peer.on('call', (incomingCall) => {
                activeCall = incomingCall;
                incomingCall.answer(localStream);
                bindCall(incomingCall);
            });

            peer.on('error', (err) => {
                if (err.type === 'unavailable-id') {
                    setTimeout(() => {
                        if (peer) peer.destroy();
                        initPeer();
                    }, 1200);
                }
            });
        }

        // 3. Handshake: Continuous Dial Loop
        function startHandshake() {
            if (handshakeInterval) clearInterval(handshakeInterval);

            if (myRole === 'patient') {
                handshakeInterval = setInterval(() => {
                    if (isConnected || !peer || peer.destroyed) return;
                    document.getElementById('subStatusText').innerText = "Connecting to Doctor's room...";
                    const call = peer.call(targetPeerId, localStream);
                    if (call) {
                        activeCall = call;
                        bindCall(call);
                    }
                }, 2200);
            } else {
                document.getElementById('subStatusText').innerText = "Doctor room ready. Awaiting patient connection...";
            }
        }

        // 4. Bind Incoming Stream to Video Element
        function bindCall(call) {
            call.on('stream', (remoteStream) => {
                isConnected = true;
                if (handshakeInterval) clearInterval(handshakeInterval);

                const otherVideo = document.getElementById('otherVideo');
                otherVideo.srcObject = remoteStream;
                otherVideo.play().catch(() => {});

                document.getElementById('remotePlaceholder').classList.add('hidden');
                setStatus("Connected • In Call", "emerald");
            });

            call.on('close', () => {
                isConnected = false;
                document.getElementById('remotePlaceholder').classList.remove('hidden');
                setStatus(targetRole + " disconnected", "amber");
                if (myRole === 'patient') startHandshake();
            });
        }

        function setStatus(text, color) {
            const badge = document.getElementById('connectionBadge');
            const dot = document.getElementById('statusDot');
            const statusText = document.getElementById('statusText');

            statusText.innerText = text;
            badge.className = `inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-${color}-500/10 text-${color}-400 border border-${color}-500/30`;
            dot.className = `w-2 h-2 rounded-full bg-${color}-400 ${color === 'emerald' ? '' : 'animate-pulse'}`;
        }

        function toggleAudio() {
            if (!localStream) return;
            const track = localStream.getAudioTracks()[0];
            if (track) {
                isAudioMuted = !isAudioMuted;
                track.enabled = !isAudioMuted;
                document.getElementById('micBtn').innerText = isAudioMuted ? '🔇' : '🎤';
            }
        }

        function toggleVideo() {
            if (!localStream) return;
            const track = localStream.getVideoTracks()[0];
            if (track) {
                isVideoOff = !isVideoOff;
                track.enabled = !isVideoOff;
                document.getElementById('camBtn').innerText = isVideoOff ? '🚫' : '📹';
            }
        }

        function endCall() {
            if (animInterval) clearInterval(animInterval);
            if (handshakeInterval) clearInterval(handshakeInterval);
            if (localStream) localStream.getTracks().forEach(t => t.stop());
            if (activeCall) activeCall.close();
            if (peer) peer.destroy();
            window.location.href = (myRole === 'doctor') ? 'dashboard/doctor.php' : 'dashboard/patient.php';
        }

        function copyInviteLink() {
            navigator.clipboard.writeText(window.location.href).then(() => {
                alert("Consultation room link copied!");
            });
        }

        setupLocalMedia();
    </script>
</body>
</html>