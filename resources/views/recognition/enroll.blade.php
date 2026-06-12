@extends('layouts.app')

@section('title', 'Enroll New User - Smart Lock')

@push('styles')
<style>
    #enroll-video {
        width: 100%;
        border-radius: 0.5rem;
    }
    #enroll-canvas {
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
</style>
@endpush

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-gray-800 rounded-lg p-6">
        <h2 class="text-xl font-bold mb-4"><i class="fas fa-user-plus text-blue-400 mr-2"></i>Register New Face</h2>

        <form id="enroll-form" class="mb-4">
            <div class="flex gap-3">
                <input type="text" id="user-name" placeholder="Enter name..." required
                    class="flex-1 px-4 py-2 rounded bg-gray-700 border border-gray-600 focus:border-blue-500 focus:outline-none text-white">
                <button type="submit" id="btn-start"
                    class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded font-semibold transition">
                    <i class="fas fa-play mr-1"></i>Start
                </button>
                <button type="button" id="btn-cancel"
                    class="px-6 py-2 bg-red-600 hover:bg-red-700 rounded font-semibold transition hidden">
                    <i class="fas fa-stop mr-1"></i>Cancel
                </button>
            </div>
        </form>

        <div class="camera-container">
            <video id="enroll-video" autoplay muted playsinline></video>
            <canvas id="enroll-canvas"></canvas>
        </div>

        {{-- Progress --}}
        <div id="progress-area" class="mt-4 hidden">
            <div class="flex justify-between text-sm mb-1">
                <span id="progress-message">Capturing biometric data...</span>
                <span id="progress-count">0 / 20</span>
            </div>
            <div class="w-full bg-gray-700 rounded-full h-3">
                <div id="progress-bar" class="bg-blue-500 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>

        {{-- Status messages --}}
        <div id="enroll-status" class="mt-3 hidden"></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const WS_URL = 'ws://127.0.0.1:5000/ws/enroll';
const video   = document.getElementById('enroll-video');
const canvas  = document.getElementById('enroll-canvas');
const ctx     = canvas.getContext('2d');

let ws = null;
let stream = null;
let isScanning = false;
let frameInterval = null;

// Connect WebSocket
function connect() {
    ws = new WebSocket(WS_URL);

    ws.onopen = () => {
        showStatus('Connected to server. Ready to scan.', 'bg-green-600');
        startCamera();
    };

    ws.onmessage = (event) => {
        const data = JSON.parse(event.data);

        switch (data.type) {
            case 'preview':
                drawPreview(data);
                break;
            case 'warn':
                showStatus(data.message, 'bg-yellow-600');
                break;
            case 'register_progress':
                updateProgress(data);
                break;
            case 'register_success':
                showStatus(`✅ User '${data.name}' registered successfully!`, 'bg-green-600');
                isScanning = false;
                document.getElementById('btn-cancel').classList.add('hidden');
                document.getElementById('btn-start').classList.remove('hidden');
                document.getElementById('progress-area').classList.add('hidden');
                document.getElementById('user-name').value = '';
                break;
            case 'register_error':
                showStatus(`❌ ${data.message}`, 'bg-red-600');
                isScanning = false;
                document.getElementById('btn-cancel').classList.add('hidden');
                document.getElementById('btn-start').classList.remove('hidden');
                document.getElementById('progress-area').classList.add('hidden');
                break;
            case 'status':
                showStatus(data.message, 'bg-blue-600');
                break;
        }
    };

    ws.onclose = () => {
        showStatus('Disconnected from server. Reconnecting...', 'bg-red-600');
        setTimeout(connect, 3000);
    };

    ws.onerror = (err) => console.error('WS error:', err);
}

function startCamera() {
    navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' }
    }).then(s => {
        stream = s;
        video.srcObject = s;
        video.addEventListener('loadedmetadata', () => {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
        });
    }).catch(err => {
        showStatus('Camera error: ' + err.message, 'bg-red-600');
    });
}

function drawPreview(data) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (data.bbox) {
        const [x, y, w, h] = data.bbox;
        ctx.strokeStyle = '#3b82f6';
        ctx.lineWidth = 3;
        ctx.strokeRect(x, y, w, h);
    }
}

function updateProgress(data) {
    document.getElementById('progress-area').classList.remove('hidden');
    document.getElementById('progress-bar').style.width = data.progress + '%';
    document.getElementById('progress-count').textContent = `${data.count} / ${data.total}`;
    document.getElementById('progress-message').textContent = data.message;
}

function showStatus(msg, colorClass) {
    const el = document.getElementById('enroll-status');
    el.className = `mt-3 px-4 py-2 rounded text-white ${colorClass}`;
    el.textContent = msg;
    el.classList.remove('hidden');
}

// Event listeners
document.getElementById('enroll-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const name = document.getElementById('user-name').value.trim();
    if (!name) return;

    isScanning = true;
    ws.send(JSON.stringify({ type: 'register_start', name }));
    document.getElementById('btn-start').classList.add('hidden');
    document.getElementById('btn-cancel').classList.remove('hidden');
    document.getElementById('progress-area').classList.remove('hidden');
    showStatus(`Starting scan for '${name}'...`, 'bg-blue-600');

    // Start sending frames
    if (frameInterval) clearInterval(frameInterval);
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = video.videoWidth || 640;
    tempCanvas.height = video.videoHeight || 480;
    const tempCtx = tempCanvas.getContext('2d');

    frameInterval = setInterval(() => {
        if (!ws || ws.readyState !== WebSocket.OPEN || !isScanning) return;
        tempCtx.drawImage(video, 0, 0);
        const base64 = tempCanvas.toDataURL('image/jpeg', 0.7);
        ws.send(JSON.stringify({ type: 'frame', image: base64 }));
    }, 200);
});

document.getElementById('btn-cancel').addEventListener('click', () => {
    isScanning = false;
    if (frameInterval) clearInterval(frameInterval);
    ws.send(JSON.stringify({ type: 'register_cancel' }));
    document.getElementById('btn-start').classList.remove('hidden');
    document.getElementById('btn-cancel').classList.add('hidden');
    document.getElementById('progress-area').classList.add('hidden');
    showStatus('Registration cancelled.', 'bg-yellow-600');
});

// Start
connect();
</script>
@endpush
</final_file_content>