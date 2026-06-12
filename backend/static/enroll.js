/* ===== ENROLLMENT PAGE JS ===== */

const video       = document.getElementById('enroll-video');
const canvas      = document.getElementById('enroll-canvas');
const ctx         = canvas.getContext('2d');

const statusPill  = document.getElementById('enroll-status-pill');
const statusText  = document.getElementById('enroll-status-text');
const guideBox    = document.getElementById('guide-box');
const guideLabel  = document.getElementById('guide-label');
const scanLine    = document.getElementById('scan-line');
const warnEl      = document.getElementById('enroll-warn');
const progressWrap= document.getElementById('enroll-progress-wrap');
const epBarFill   = document.getElementById('ep-bar-fill');
const epLabel     = document.getElementById('ep-label');

const nameInput   = document.getElementById('name-input');
const nameHint    = document.getElementById('name-hint');
const startBtn    = document.getElementById('start-scan-btn');
const cancelBtn   = document.getElementById('cancel-scan-btn');

const stepResult  = document.getElementById('step-result');
const resultIcon  = document.getElementById('result-icon');
const resultTitle = document.getElementById('result-title');
const resultMsg   = document.getElementById('result-msg');
const againBtn    = document.getElementById('enroll-again-btn');
const homeBtn     = document.getElementById('go-home-btn');

const fqDotSingle = document.getElementById('fq-dot-single');
const fqDotSize   = document.getElementById('fq-dot-size');
const fqDotDetect = document.getElementById('fq-dot-detect');

let ws            = null;
let frameInterval = null;
let isScanning    = false;
let nameValid     = false;
let lastQuality   = null;  // latest quality state from server preview

// ── Camera ─────────────────────────────────────────────────────────────────────
async function startCamera() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
    video.srcObject = stream;
    await new Promise(r => video.onloadedmetadata = r);
  } catch(e) {
    warnEl.textContent = 'Camera access denied. Allow permission and reload.';
  }
}

// ── WebSocket ──────────────────────────────────────────────────────────────────
function connectWS() {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  ws = new WebSocket(`${proto}://${location.host}/ws/enroll`);

  ws.onopen  = () => startFrameLoop();
  ws.onclose = () => { stopFrameLoop(); setTimeout(connectWS, 2000); };
  ws.onerror = (e) => console.error('[WS enroll]', e);
  ws.onmessage = (e) => { try { handleMessage(JSON.parse(e.data)); } catch(err) {} };
}

function sendJSON(obj) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
}

// ── Frame Loop ─────────────────────────────────────────────────────────────────
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
  sendJSON({ type: 'frame', image: tmp.toDataURL('image/jpeg', 0.75) });
}

// ── Message Handler ────────────────────────────────────────────────────────────
function handleMessage(msg) {
  switch (msg.type) {
    case 'preview':
      updatePreview(msg);
      break;
    case 'warn':
      showWarn(msg.message);
      break;
    case 'register_progress':
      updateProgress(msg.progress, msg.count, msg.total);
      break;
    case 'register_success':
      showResult('success', msg.name);
      break;
    case 'register_error':
      showResult('error', null, msg.message);
      break;
    case 'status':
      setStatus(msg.message, 'scanning');
      break;
  }
}

// ── Preview & Quality ──────────────────────────────────────────────────────────
function updatePreview(data) {
  canvas.width  = video.videoWidth  || 640;
  canvas.height = video.videoHeight || 480;
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  const q          = data.quality_issue;
  const faceCount  = data.face_count || 0;
  const bbox       = data.bbox;
  lastQuality      = q;

  // Draw bbox if face found
  if (bbox && faceCount === 1 && !q) {
    const { xmin, ymin, width, height } = bbox;
    ctx.strokeStyle = isScanning ? '#00f5ff' : '#00ff88';
    ctx.lineWidth   = 2;
    ctx.shadowColor = ctx.strokeStyle;
    ctx.shadowBlur  = 8;
    ctx.strokeRect(xmin, ymin, width, height);
    ctx.shadowBlur  = 0;
  }

  // Update guide box style
  if (faceCount === 0) {
    setGuide('', 'ALIGN FACE HERE');
    setQualityDots(false, false, false);
    if (!isScanning) clearWarn();
  } else if (q === 'multiple_faces') {
    setGuide('warn', 'MULTIPLE FACES');
    setQualityDots(false, true, true);
  } else if (q === 'too_small') {
    setGuide('warn', 'MOVE CLOSER');
    setQualityDots(true, false, true);
  } else if (q === 'too_close') {
    setGuide('warn', 'MOVE BACK');
    setQualityDots(true, false, true);
  } else if (!q && faceCount === 1) {
    setGuide(isScanning ? 'scanning' : 'ok', isScanning ? 'SCANNING...' : 'FACE OK — READY');
    setQualityDots(true, true, true);
    if (!isScanning) clearWarn();
  }

  // Enable/disable start button based on quality + name
  startBtn.disabled = !(nameValid && faceCount === 1 && !q);
}

function setGuide(state, label) {
  guideBox.className = `guide-box${state ? ' ' + state : ''}`;
  guideLabel.textContent = label;
}

function setQualityDots(single, size, detect) {
  fqDotSingle.className = `fq-dot ${single ? 'ok' : 'fail'}`;
  fqDotSize.className   = `fq-dot ${size   ? 'ok' : 'fail'}`;
  fqDotDetect.className = `fq-dot ${detect ? 'ok' : 'fail'}`;
}

let warnTimer = null;
function showWarn(msg) {
  warnEl.textContent = msg;
  if (warnTimer) clearTimeout(warnTimer);
  warnTimer = setTimeout(clearWarn, 2500);
}
function clearWarn() { warnEl.textContent = ''; }

// ── Status pill ────────────────────────────────────────────────────────────────
function setStatus(text, state = '') {
  statusText.textContent  = text;
  statusPill.className    = `enroll-status-pill${state ? ' ' + state : ''}`;
}

// ── Progress ───────────────────────────────────────────────────────────────────
function updateProgress(pct, count, total) {
  progressWrap.classList.add('active');
  epBarFill.style.width = `${pct}%`;
  epLabel.textContent   = `${count} / ${total} frames`;
}

// ── Buttons ────────────────────────────────────────────────────────────────────
nameInput.addEventListener('input', () => {
  const val = nameInput.value.trim();
  const ok  = val.length >= 2 && /^[a-zA-Z0-9_\-\s]+$/.test(val);
  nameValid = ok;
  nameInput.className = val.length === 0 ? '' : (ok ? 'valid' : 'invalid');
  nameHint.textContent = val.length === 0 ? '' : (ok ? '✓ Valid name' : 'Use letters, numbers, _ - only');
  nameHint.className   = `input-hint ${val.length === 0 ? '' : (ok ? 'ok' : 'err')}`;
  // Re-evaluate start btn
  startBtn.disabled = !(nameValid && lastQuality === null);
});

startBtn.addEventListener('click', () => {
  const name = nameInput.value.trim();
  if (!name || !nameValid) return;
  isScanning = true;
  sendJSON({ type: 'register_start', name });
  startBtn.style.display  = 'none';
  cancelBtn.style.display = 'block';
  scanLine.classList.add('active');
  setStatus('SCANNING', 'scanning');
  progressWrap.classList.add('active');
  epBarFill.style.width = '0%';
  epLabel.textContent   = '0 / 10 frames';
});

cancelBtn.addEventListener('click', () => {
  isScanning = false;
  sendJSON({ type: 'register_cancel' });
  startBtn.style.display  = 'block';
  cancelBtn.style.display = 'none';
  scanLine.classList.remove('active');
  setStatus('READY', '');
  progressWrap.classList.remove('active');
  setGuide('', 'ALIGN FACE HERE');
});

// ── Result ─────────────────────────────────────────────────────────────────────
function showResult(type, name, errMsg) {
  isScanning = false;
  scanLine.classList.remove('active');
  stopFrameLoop();

  stepResult.style.display = 'flex';
  stepResult.style.flexDirection = 'column';

  if (type === 'success') {
    resultIcon.textContent  = '✅';
    resultTitle.textContent = 'ENROLLMENT COMPLETE';
    resultTitle.style.color = 'var(--green)';
    resultMsg.textContent   = `"${name}" has been successfully registered in the biometric database.`;
    setStatus('SUCCESS', 'success');
    againBtn.style.display = 'block';
    homeBtn.style.display  = 'block';
  } else {
    resultIcon.textContent  = '❌';
    resultTitle.textContent = 'ENROLLMENT FAILED';
    resultTitle.style.color = 'var(--red)';
    resultMsg.textContent   = errMsg || 'Unknown error occurred.';
    setStatus('ERROR', 'error');
    againBtn.style.display = 'block';
  }
}

againBtn.addEventListener('click', () => {
  stepResult.style.display = 'none';
  nameInput.value = '';
  nameValid       = false;
  nameInput.className = '';
  nameHint.textContent = '';
  startBtn.style.display  = 'block';
  cancelBtn.style.display = 'none';
  startBtn.disabled = true;
  progressWrap.classList.remove('active');
  epBarFill.style.width = '0%';
  setStatus('READY', '');
  setGuide('', 'ALIGN FACE HERE');
  setQualityDots(false, false, false);
  connectWS();
});

// ── Init ───────────────────────────────────────────────────────────────────────
(async function init() {
  await startCamera();
  connectWS();
})();
