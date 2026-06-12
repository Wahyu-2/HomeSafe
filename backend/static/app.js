/* ===== SMART LOCK - RECOGNITION DASHBOARD ===== */

const video    = document.getElementById('webcam');
const canvas   = document.getElementById('overlay-canvas');
const ctx      = canvas.getContext('2d');

const portalRing  = document.getElementById('portal-ring');
const portalSymbol= document.getElementById('portal-symbol');
const portalTitle = document.getElementById('portal-state-title');
const portalDesc  = document.getElementById('portal-state-desc');
const lockBar  = document.getElementById('lock-status-bar');
const lockIcon = document.getElementById('bar-status-icon');
const lockText = document.getElementById('bar-status-text');

const profileName = document.getElementById('profile-name');
const matchConf   = document.getElementById('match-confidence');
const confBar     = document.getElementById('confidence-bar-fill');
const latencyVal  = document.getElementById('latency-val');
const cosineVal   = document.getElementById('cosine-val');
const fpsDisplay  = document.getElementById('fps-display');

const userList = document.getElementById('user-list-container');
const emptyMsg = document.getElementById('empty-list-msg');

let ws            = null;
let frameInterval = null;
let fpsCount      = 0;
let fpsTs         = Date.now();
let lockResetTimer= null;

// ── WebSocket ──────────────────────────────────────────────────────────────────
function connectWS() {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  ws = new WebSocket(`${proto}://${location.host}/ws`);
  ws.onopen  = () => startFrameLoop();
  ws.onclose = () => { stopFrameLoop(); setTimeout(connectWS, 2000); };
  ws.onerror = (e) => console.error('[WS]', e);
  ws.onmessage = (e) => { try { handleMessage(JSON.parse(e.data)); } catch(err) {} };
}

function sendJSON(obj) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
}

// ── Camera ─────────────────────────────────────────────────────────────────────
async function startCamera() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { width:640, height:480 }, audio: false });
    video.srcObject = stream;
    await new Promise(r => video.onloadedmetadata = r);
  } catch(e) {
    alert('Camera access denied. Allow permission and reload.');
  }
}

function startFrameLoop() {
  stopFrameLoop();
  frameInterval = setInterval(captureAndSend, 100);
}

function stopFrameLoop() {
  if (frameInterval) { clearInterval(frameInterval); frameInterval = null; }
}

function captureAndSend() {
  if (!ws || ws.readyState !== WebSocket.OPEN || video.readyState < 2) return;
  const tmp = document.createElement('canvas');
  tmp.width = 640; tmp.height = 480;
  tmp.getContext('2d').drawImage(video, 0, 0, 640, 480);
  sendJSON({ type: 'frame', image: tmp.toDataURL('image/jpeg', 0.7) });
  fpsCount++;
  const now = Date.now();
  if (now - fpsTs >= 1000) {
    fpsDisplay.textContent = `${fpsCount} FPS`;
    fpsCount = 0; fpsTs = now;
  }
}

// ── Message Handler ────────────────────────────────────────────────────────────
function handleMessage(msg) {
  if (msg.type !== 'result') return;
  updateOverlay(msg);
  updateMetrics(msg);
  updateLockState(msg);
}

// ── Overlay ────────────────────────────────────────────────────────────────────
const QUALITY_LABELS = {
  multiple_faces: '⚠ MULTIPLE FACES',
  too_small:      '↑ MOVE CLOSER',
  too_close:      '↓ MOVE BACK',
};

function updateOverlay(data) {
  canvas.width  = video.videoWidth  || 640;
  canvas.height = video.videoHeight || 480;
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  const { face_detected, face_count, quality_issue, bbox, matched, name, percentage } = data;

  // Multiple faces — draw warning
  if (quality_issue === 'multiple_faces') {
    ctx.font = '14px Orbitron, monospace';
    ctx.fillStyle = '#ffaa00';
    ctx.shadowColor = '#ffaa00'; ctx.shadowBlur = 8;
    ctx.fillText('⚠  MULTIPLE FACES DETECTED', 16, 28);
    ctx.shadowBlur = 0;
    return;
  }

  if (!face_detected || !bbox) {
    // Quality hint
    if (quality_issue && QUALITY_LABELS[quality_issue]) {
      ctx.font = '13px Orbitron, monospace';
      ctx.fillStyle = '#ffaa00';
      ctx.shadowColor = '#ffaa00'; ctx.shadowBlur = 6;
      ctx.fillText(QUALITY_LABELS[quality_issue], 16, 28);
      ctx.shadowBlur = 0;
    }
    return;
  }

  const { xmin, ymin, width, height } = bbox;
  const color = matched ? '#00ff88' : '#00f5ff';

  ctx.strokeStyle = color;
  ctx.lineWidth   = 2;
  ctx.shadowColor = color;
  ctx.shadowBlur  = 10;
  ctx.strokeRect(xmin, ymin, width, height);

  // Label
  const label = matched
    ? `${name}  ${percentage.toFixed(1)}%`
    : 'SCANNING...';
  ctx.font      = '13px Orbitron, monospace';
  ctx.fillStyle = color;
  ctx.shadowBlur = 6;
  ctx.fillText(label, xmin, ymin > 20 ? ymin - 6 : ymin + height + 16);
  ctx.shadowBlur = 0;
}

// ── Metrics ────────────────────────────────────────────────────────────────────
function updateMetrics(data) {
  latencyVal.textContent = `${data.process_time_ms} ms`;

  if (!data.face_detected) {
    profileName.textContent = 'None';
    matchConf.textContent   = '0.00%';
    confBar.style.width     = '0%';
    cosineVal.textContent   = '0.000';
    return;
  }
  profileName.textContent = data.matched ? data.name : 'Unknown';
  matchConf.textContent   = `${data.percentage.toFixed(2)}%`;
  confBar.style.width     = `${Math.min(data.percentage, 100)}%`;
  cosineVal.textContent   = data.similarity.toFixed(3);
}

// ── Lock State ─────────────────────────────────────────────────────────────────
function updateLockState(data) {
  // Block unlock if multiple faces
  if (data.quality_issue === 'multiple_faces') {
    resetLockState();
    return;
  }

  if (data.unlocked) {
    portalRing.classList.add('unlocked');
    portalSymbol.textContent = '🔓';
    portalTitle.textContent  = 'ACCESS GRANTED';
    portalTitle.style.color  = 'var(--green)';
    portalDesc.textContent   = `Welcome, ${data.name}`;
    lockBar.classList.add('unlocked');
    lockIcon.textContent = '🔓';
    lockText.textContent = 'ACCESS GRANTED';
    if (lockResetTimer) clearTimeout(lockResetTimer);
    lockResetTimer = setTimeout(resetLockState, 3000);
  } else {
    // Only reset if currently showing unlocked
    if (lockBar.classList.contains('unlocked') && !lockResetTimer) resetLockState();
  }
}

function resetLockState() {
  if (lockResetTimer) { clearTimeout(lockResetTimer); lockResetTimer = null; }
  portalRing.classList.remove('unlocked');
  portalSymbol.textContent = '🔒';
  portalTitle.textContent  = 'SYSTEM SECURE';
  portalTitle.style.color  = '';
  portalDesc.textContent   = 'Waiting for authorized facial profile';
  lockBar.classList.remove('unlocked');
  lockIcon.textContent = '🔒';
  lockText.textContent = 'SECURE LOCK ACTIVE';
}

// ── User List ──────────────────────────────────────────────────────────────────
async function loadUsers() {
  try {
    const res  = await fetch('/api/users');
    const data = await res.json();
    renderUsers(data.users || []);
  } catch(e) { console.error('loadUsers', e); }
}

function renderUsers(users) {
  userList.innerHTML = '';
  if (!users.length) { userList.appendChild(emptyMsg); return; }
  users.forEach(name => {
    const item = document.createElement('div');
    item.className = 'user-item';
    item.innerHTML = `<span class="user-item-name">👤 ${name}</span>
      <button class="user-item-delete" title="Remove">✕</button>`;
    item.querySelector('.user-item-delete').onclick = () => deleteUser(name);
    userList.appendChild(item);
  });
}

async function deleteUser(name) {
  if (!confirm(`Remove "${name}" from the database?`)) return;
  try {
    const res = await fetch(`/api/users/${encodeURIComponent(name)}`, { method: 'DELETE' });
    if (res.ok) loadUsers();
    else alert('Failed to delete user.');
  } catch(e) { console.error('deleteUser', e); }
}

// ── Init ───────────────────────────────────────────────────────────────────────
(async function init() {
  await startCamera();
  connectWS();
  loadUsers();
})();
