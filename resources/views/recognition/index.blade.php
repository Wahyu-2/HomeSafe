@extends('layouts.app')

@section('title', 'Smart Lock - Face Recognition')

@push('styles')
<style>
    #video-feed, #cctv-feed {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 0.5rem;
    }
    #overlay-canvas {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }
    .camera-container {
        position: relative;
        background: #000;
        border-radius: 0.5rem;
        overflow: hidden;
        aspect-ratio: 4/3;
    }
    .status-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 10;
    }
    .lock-indicator {
        position: absolute;
        bottom: 10px;
        left: 10px;
        right: 10px;
        z-index: 10;
        text-align: center;
    }
    #unlock-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 20;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.3s ease;
        border-radius: 0.5rem;
    }
    #unlock-overlay.show {
        opacity: 1;
    }
    #unlock-overlay .check-icon {
        font-size: 80px;
        color: #22c55e;
        text-shadow: 0 0 30px rgba(34, 197, 94, 0.7);
    }
    #unlock-overlay .unlock-text {
        font-size: 24px;
        font-weight: bold;
        color: #22c55e;
        margin-top: 10px;
        text-shadow: 0 0 20px rgba(34, 197, 94, 0.5);
    }
    #unlock-overlay .unlock-name {
        font-size: 18px;
        color: #86efac;
        margin-top: 5px;
    }
</style>
@endpush

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Kamera 1: Face Recognition / Pintu --}}
    <div class="bg-gray-800 rounded-lg p-4">
        <h2 class="text-lg font-semibold mb-3 flex items-center">
            <i class="fas fa-door-open text-blue-400 mr-2"></i>
            Pintu Utama - Face Recognition
        </h2>
        <div class="camera-container">
            <video id="video-feed" autoplay muted playsinline></video>
            <canvas id="overlay-canvas"></canvas>
            <div class="status-badge">
                <span id="recognition-status" class="px-2 py-1 text-xs rounded bg-yellow-600">
                    <i class="fas fa-circle-notch fa-spin mr-1"></i>Waiting...
                </span>
            </div>
            <div id="unlock-overlay">
                <div class="check-icon"><i class="fas fa-check-circle"></i></div>
                <div class="unlock-text">ACCESS GRANTED</div>
                <div class="unlock-name" id="unlock-name-display"></div>
            </div>
            <div class="lock-indicator">
                <div id="lock-status" class="inline-flex items-center px-4 py-2 rounded-lg bg-gray-900/80 backdrop-blur-sm">
                    <i id="lock-icon" class="fas fa-lock text-2xl text-red-400 mr-2"></i>
                    <span id="lock-text" class="font-semibold">LOCKED</span>
                    <span id="user-info" class="ml-3 text-sm text-gray-300 hidden"></span>
                </div>
            </div>
        </div>
        <div class="mt-3 flex justify-between text-sm text-gray-400">
            <span><i class="fas fa-users mr-1"></i>Face count: <span id="face-count">0</span></span>
            <span><i class="fas fa-tachometer-alt mr-1"></i><span id="process-time">0</span> ms</span>
        </div>
    </div>

    {{-- Kamera 2: CCTV Monitoring --}}
    <div class="bg-gray-800 rounded-lg p-4">
        <h2 class="text-lg font-semibold mb-3 flex items-center">
            <i class="fas fa-video text-green-400 mr-2"></i>
            CCTV Monitoring
        </h2>
        <div class="camera-container">
            <video id="cctv-feed" autoplay muted playsinline></video>
            <div class="status-badge">
                <span id="cctv-status" class="px-2 py-1 text-xs rounded bg-green-600">
                    <i class="fas fa-circle mr-1"></i>LIVE
                </span>
            </div>
        </div>
        <div class="mt-3 text-sm text-gray-400">
            <i class="fas fa-info-circle mr-1"></i>Live monitoring stream
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// === WEBSOCKET CONNECTION ===
const WS_URL = 'ws://127.0.0.1:5000/ws';
const video     = document.getElementById('video-feed');
const cctvVideo = document.getElementById('cctv-feed');
const canvas    = document.getElementById('overlay-canvas');
const ctx       = canvas.getContext('2d');

let ws = null;

function connectWebSocket() {
    ws = new WebSocket(WS_URL);

    ws.onopen = () => {
        document.getElementById('recognition-status').className = 'px-2 py-1 text-xs rounded bg-green-600';
        document.getElementById('recognition-status').innerHTML = '<i class="fas fa-circle mr-1"></i>Connected';
        startCamera();
        startCCTV();
    };

    ws.onmessage = (event) => {
        const data = JSON.parse(event.data);

        if (data.type === 'result') {
            updateRecognitionUI(data);
        }
    };

    ws.onclose = () => {
        document.getElementById('recognition-status').className = 'px-2 py-1 text-xs rounded bg-red-600';
        document.getElementById('recognition-status').innerHTML = '<i class="fas fa-times mr-1"></i>Disconnected';
        setTimeout(connectWebSocket, 3000);
    };

    ws.onerror = (err) => {
        console.error('WebSocket error:', err);
    };
}

// === KAMERA 1: FACE RECOGNITION ===
async function startCamera() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 640 },
                height: { ideal: 480 },
                facingMode: 'user'
            }
        });
        video.srcObject = stream;

        video.addEventListener('loadedmetadata', () => {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            sendFrames();
        });
    } catch (err) {
        console.error('Camera error:', err);
        document.getElementById('recognition-status').className = 'px-2 py-1 text-xs rounded bg-red-600';
        document.getElementById('recognition-status').innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>Camera Error';
    }
}

function sendFrames() {
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = video.videoWidth;
    tempCanvas.height = video.videoHeight;
    const tempCtx = tempCanvas.getContext('2d');

    function capture() {
        if (ws && ws.readyState === WebSocket.OPEN) {
            tempCtx.drawImage(video, 0, 0);
            const base64 = tempCanvas.toDataURL('image/jpeg', 0.7);
            ws.send(JSON.stringify({ type: 'frame', image: base64 }));
        }
        setTimeout(capture, 150); // ~ 6-7 fps
    }
    capture();
}

function updateRecognitionUI(data) {
    // Update face count
    document.getElementById('face-count').textContent = data.face_count || 0;
    document.getElementById('process-time').textContent = data.process_time_ms || 0;

    // Draw bounding box
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (data.bbox && data.face_detected) {
        // bbox from backend is OBJECT: {xmin, ymin, width, height}
        const x = data.bbox.xmin || data.bbox.x || 0;
        const y = data.bbox.ymin || data.bbox.y || 0;
        const w = data.bbox.width || 0;
        const h = data.bbox.height || 0;
        const color = data.matched ? '#22c55e' : '#ef4444';
        ctx.strokeStyle = color;
        ctx.lineWidth = 3;
        ctx.strokeRect(x, y, w, h);

        // Label
        ctx.fillStyle = color;
        ctx.font = 'bold 14px sans-serif';
        const label = data.matched ? `✓ ${data.name} (${data.percentage}%)` : 'Unknown';
        ctx.fillText(label, x, y - 8);
    }

    // Lock status + overlay effect
    const lockIcon = document.getElementById('lock-icon');
    const lockText = document.getElementById('lock-text');
    const userInfo = document.getElementById('user-info');
    const lockStatus = document.getElementById('lock-status');
    const unlockOverlay = document.getElementById('unlock-overlay');
    const unlockNameDisplay = document.getElementById('unlock-name-display');

    if (data.unlocked) {
        // Show UNLOCKED state
        lockIcon.className = 'fas fa-lock-open text-2xl text-green-400 mr-2';
        lockText.textContent = 'UNLOCKED';
        lockStatus.className = 'inline-flex items-center px-4 py-2 rounded-lg bg-green-900/80 backdrop-blur-sm';
        userInfo.textContent = `${data.name} (${data.percentage}%)`;
        userInfo.className = 'ml-3 text-sm text-green-300';
        userInfo.classList.remove('hidden');

        // Show overlay effect
        unlockNameDisplay.textContent = data.name;
        unlockOverlay.classList.add('show');

        // Auto-hide overlay after 2 seconds
        setTimeout(() => {
            unlockOverlay.classList.remove('show');
        }, 2000);
    } else {
        lockIcon.className = 'fas fa-lock text-2xl text-red-400 mr-2';
        lockText.textContent = 'LOCKED';
        lockStatus.className = 'inline-flex items-center px-4 py-2 rounded-lg bg-red-900/80 backdrop-blur-sm';
        userInfo.classList.add('hidden');
        unlockOverlay.classList.remove('show');
    }

    // Status badge
    const statusBadge = document.getElementById('recognition-status');
    if (data.quality_issue) {
        statusBadge.className = 'px-2 py-1 text-xs rounded bg-orange-600';
        statusBadge.innerHTML = `<i class="fas fa-exclamation-circle mr-1"></i>${data.quality_issue.replace('_', ' ')}`;
    } else if (data.face_detected) {
        statusBadge.className = 'px-2 py-1 text-xs rounded bg-blue-600';
        statusBadge.innerHTML = '<i class="fas fa-face-smile mr-1"></i>Face detected';
    } else {
        statusBadge.className = 'px-2 py-1 text-xs rounded bg-yellow-600';
        statusBadge.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i>No face';
    }
}

// === KAMERA 2: CCTV MONITORING ===
async function startCCTV() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 640 },
                height: { ideal: 480 },
                facingMode: 'environment'
            }
        });
        cctvVideo.srcObject = stream;

        cctvVideo.addEventListener('loadedmetadata', () => {
            document.getElementById('cctv-status').className = 'px-2 py-1 text-xs rounded bg-green-600';
            document.getElementById('cctv-status').innerHTML = '<i class="fas fa-circle mr-1"></i>LIVE';
        });
    } catch (err) {
        console.error('CCTV camera error:', err);
        document.getElementById('cctv-status').className = 'px-2 py-1 text-xs rounded bg-red-600';
        document.getElementById('cctv-status').innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>CCTV Unavailable';
    }
}

// Start connection
connectWebSocket();
</script>
@endpush
</final_file_content>

IMPORTANT: For any future changes to this file, use the final_file_content shown above as your reference. This content reflects the current state of the file, including any auto-formatting (e.g., if you used single quotes but the formatter converted them to double quotes). Always base your SEARCH/REPLACE operations on this final version to ensure accuracy.

<environment_details>
# Workspace Roots
- pengenalan_uji: c:\SCHOOL\SEM 4\PBL\pengenalan_uji (none)
- Website: c:\SCHOOL\SEM 4\PBL\Website (none)

Primary workspace: pengenalan_uji

# Workspace Configuration
{
  "workspaces": {
    "c:\\SCHOOL\\SEM 4\\PBL\\pengenalan_uji": {
      "hint": "pengenalan_uji"
    },
    "c:\\SCHOOL\\SEM 4\\PBL\\Website": {
      "hint": "Website"
    }
  }
}

# Detected CLI Tools
These are some of the tools on the user's machine, and may be useful if needed to accomplish the task: git, npm, pip, curl, python, node, code, dotnet. This list is not exhaustive, and other tools may be available.

# Context Window Usage
52,074 / 1,048.576K tokens used (5%)

# Current Mode
ACT MODE
</｜｜DSML｜｜>