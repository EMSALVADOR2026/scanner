<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Escáner de documentos</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; justify-content: center; padding: 40px 20px; }
        .card { background: white; border-radius: 16px; padding: 40px; width: 100%; max-width: 700px; box-shadow: 0 4px 24px rgba(0,0,0,.08); height: fit-content; }
        h1 { font-size: 22px; color: #1e293b; margin-bottom: 6px; }
        .subtitle { font-size: 13px; color: #94a3b8; margin-bottom: 28px; }
        .agent-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .agent-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .agent-info { flex: 1; }
        .agent-info p:first-child { font-weight: 600; color: #1e293b; font-size: 14px; margin-bottom: 2px; }
        .agent-info p:last-child  { font-size: 12px; color: #94a3b8; }
        .agent-indicator { display: flex; align-items: center; gap: 6px; font-size: 12px; }
        #agent-dot { width: 8px; height: 8px; border-radius: 50%; background: #e2e8f0; display: inline-block; transition: background .3s; }
        #agent-status-text { color: #94a3b8; }
        .btn-download { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px; text-decoration: none; background: #3b82f6; color: white; transition: all .2s; border: none; cursor: pointer; }
        .btn-download:hover { background: #2563eb; transform: translateY(-1px); }
        #download-instructions { display: none; margin-top: 14px; padding: 14px; background: #eff6ff; border-radius: 8px; font-size: 13px; color: #1e40af; line-height: 1.8; }
        #scanner-box { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 36px 24px; text-align: center; transition: all .3s; margin-bottom: 20px; }
        #scanner-box.active { border-color: #3b82f6; background: #eff6ff; }
        #scanner-box.ready  { border-color: #22c55e; background: #f0fdf4; }
        #scanner-box.error  { border-color: #ef4444; background: #fef2f2; }
        .scanner-icon { width: 64px; height: 64px; margin: 0 auto 16px; background: #f1f5f9; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 32px; transition: background .3s; }
        #scanner-box.active .scanner-icon { background: #dbeafe; }
        #scanner-box.ready  .scanner-icon { background: #dcfce7; }
        #scanner-box.error  .scanner-icon { background: #fee2e2; }
        #btn-scan { padding: 12px 32px; font-size: 15px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; background: #3b82f6; color: white; transition: all .2s; }
        #btn-scan:hover:not(:disabled) { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,.4); }
        #btn-scan:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        #status-msg { margin-top: 14px; font-size: 13px; color: #64748b; min-height: 22px; display: flex; align-items: center; justify-content: center; gap: 6px; }
        #file-preview { display: none; align-items: center; gap: 10px; margin-top: 14px; padding: 12px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; font-size: 13px; color: #166534; }
        .file-info { flex: 1; text-align: left; }
        .file-name { font-weight: 600; display: block; margin-bottom: 2px; word-break: break-all; }
        .file-size { font-size: 11px; color: #4ade80; }
        #btn-preview-pdf { padding: 5px 12px; font-size: 12px; border: 1px solid #16a34a; border-radius: 6px; background: white; color: #16a34a; cursor: pointer; white-space: nowrap; transition: all .2s; }
        #btn-preview-pdf:hover { background: #dcfce7; }
        #btn-clear { background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8; flex-shrink: 0; transition: color .2s; }
        #btn-clear:hover { color: #ef4444; }
        #pdfInput { display: none; }
        #filename-section { display: none; margin-bottom: 20px; }
        #filename-section label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .filename-input-wrapper { display: flex; gap: 8px; align-items: center; }
        #filename-input { flex: 1; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #1e293b; outline: none; transition: border-color .2s, box-shadow .2s; }
        #filename-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
        .filename-ext { font-size: 13px; color: #94a3b8; white-space: nowrap; font-weight: 500; }
        #filename-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .divider { height: 1px; background: #f1f5f9; margin: 20px 0; }
        #btn-submit { width: 100%; padding: 14px; font-size: 15px; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; background: #22c55e; color: white; transition: all .2s; }
        #btn-submit:hover:not(:disabled) { background: #16a34a; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(34,197,94,.4); }
        #btn-submit:disabled { opacity: .4; cursor: not-allowed; transform: none; }
        #pdf-viewer-container { display: none; margin-top: 24px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        .viewer-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .viewer-toolbar span { font-size: 13px; font-weight: 600; color: #475569; }
        .viewer-actions { display: flex; gap: 8px; }
        .viewer-btn { padding: 5px 12px; font-size: 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: white; color: #475569; cursor: pointer; transition: all .2s; }
        .viewer-btn:hover { background: #f1f5f9; }
        .viewer-btn.danger { color: #ef4444; border-color: #fecaca; }
        .viewer-btn.danger:hover { background: #fef2f2; }
        #pdf-iframe { width: 100%; height: 600px; border: none; display: block; }
        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin .7s linear infinite; opacity: .6; flex-shrink: 0; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 10px; font-size: 13px; font-weight: 500; color: white; opacity: 0; transform: translateY(10px); transition: all .3s; pointer-events: none; z-index: 1000; }
        #toast.show    { opacity: 1; transform: translateY(0); }
        #toast.success { background: #22c55e; }
        #toast.error   { background: #ef4444; }
        #toast.info    { background: #3b82f6; }
    </style>
</head>
<body>
<div class="card">
    <h1>Escáner de documentos</h1>
    <p class="subtitle">Genera el PDF con Epson y envíalo automáticamente</p>

    <div class="agent-section">
        <div class="agent-header">
            <div class="agent-info">
                <p>Agente del escáner</p>
                <p>Instálalo una sola vez en tu PC para detectar y subir el PDF</p>
            </div>
            <div class="agent-indicator">
                <span id="agent-dot"></span>
                <span id="agent-status-text">Verificando...</span>
            </div>
        </div>
        <a href="/scanner/download-installer" class="btn-download" onclick="showDownloadInstructions()">
            Descargar agente para Windows
        </a>
        <div id="download-instructions">
            <strong>Archivo descargado: ScannerAgente.zip</strong><br><br>
            1. Extrae el ZIP en cualquier carpeta<br>
            2. Haz doble clic en <strong>instalar.bat</strong><br>
            3. Espera a que diga <em>"Agente instalado correctamente"</em><br>
            4. Configura Epson / Document Capture Pro para guardar PDFs en <strong>Documentos</strong><br>
            5. El indicador de arriba se pondrá en verde<br><br>
            <span style="color:#64748b;font-size:12px">Solo necesitas hacer esto una vez. El agente se inicia automáticamente con Windows.</span>
        </div>
    </div>

    <form id="main-form" method="POST" action="/guardar" enctype="multipart/form-data">
        @csrf
        <div id="scanner-box">
            <div class="scanner-icon">📄</div>
            <button type="button" id="btn-scan" onclick="startScan()">Escanear documento</button>
            <div id="status-msg">Presiona el botón y luego usa el Epson</div>
            <div id="file-preview">
                <div class="file-info">
                    <span class="file-name" id="file-name">documento.pdf</span>
                    <span class="file-size" id="file-size"></span>
                </div>
                <button type="button" id="btn-preview-pdf" onclick="reopenViewer()">Ver PDF</button>
                <button type="button" id="btn-clear" onclick="clearFile()">x</button>
            </div>
        </div>
        <input type="file" id="pdfInput" name="documento" accept="application/pdf" />
        <div id="filename-section">
            <label for="filename-input">Nombre del documento</label>
            <div class="filename-input-wrapper">
                <input type="text" id="filename-input" name="document_name" placeholder="Ej: Contrato_empresa_2024" oninput="updateFilename()" maxlength="100" autocomplete="off" />
                <span class="filename-ext">.pdf</span>
            </div>
            <p id="filename-hint">Deja vacío para usar el nombre por defecto</p>
        </div>
        <div class="divider"></div>
        <button type="submit" id="btn-submit" disabled>Enviar documento</button>
    </form>

    <div id="pdf-viewer-container">
        <div class="viewer-toolbar">
            <span>Vista previa</span>
            <div class="viewer-actions">
                <button class="viewer-btn" onclick="downloadPdf()">Descargar</button>
                <button class="viewer-btn danger" onclick="closePdfViewer()">Cerrar</button>
            </div>
        </div>
        <iframe id="pdf-iframe" src="" type="application/pdf"></iframe>
    </div>
</div>
<div id="toast"></div>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const scanBtn = document.getElementById('btn-scan');
const statusMsg = document.getElementById('status-msg');
const scannerBox = document.getElementById('scanner-box');
const filePreview = document.getElementById('file-preview');
const fileNameEl = document.getElementById('file-name');
const fileSizeEl = document.getElementById('file-size');
const inputEl = document.getElementById('pdfInput');
const submitBtn = document.getElementById('btn-submit');

let currentBlobUrl = null;
let pollingInterval = null;
let currentScanId = null;
let pollCount = 0;
let currentBlob = null;

const POLL_MAX_IDLE = 45;
const POLL_MAX_ABSOLUTE = 150;

function getFilename() {
    const value = (document.getElementById('filename-input')?.value || '').trim();
    if (!value) return 'documento.pdf';
    const clean = value.replace(/[<>:"/\\|?*]/g,'').replace(/\s+/g,'_').replace(/\.pdf$/i,'').trim();
    return clean ? clean + '.pdf' : 'documento.pdf';
}

function updateFilename() {
    const filename = getFilename();
    fileNameEl.textContent = filename;
    if (currentBlob) assignFileToInput(currentBlob, filename);
}

function assignFileToInput(blob, filename) {
    const file = new File([blob], filename, { type: 'application/pdf' });
    const dt = new DataTransfer();
    dt.items.add(file);
    inputEl.files = dt.files;
    inputEl.dispatchEvent(new Event('change', { bubbles: true }));
    fileNameEl.textContent = filename;
}

async function startScan() {
    setBusy(true);
    scannerBox.className = 'active';
    setStatus('scanning', 'Enviando solicitud...');
    pollCount = 0;
    window._pollErrors = 0;
    try {
        const res = await fetch('/scanner/scan', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.message || 'Error del servidor (' + res.status + ')');
        }
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Error al iniciar');
        currentScanId = data.scan_id;
        setStatus('scanning', 'Solicitud enviada — usa el boton del Epson...');
        pollingInterval = setInterval(pollStatus, 2000);
    } catch (err) {
        setError(err.message);
    }
}

async function pollStatus() {
    try {
        pollCount++;
        if (pollCount > POLL_MAX_ABSOLUTE) {
            clearInterval(pollingInterval);
            setError('Tiempo maximo de espera agotado. Intenta de nuevo.');
            return;
        }
        const res = await fetch('/scanner/poll?scan_id=' + encodeURIComponent(currentScanId), { signal: AbortSignal.timeout(5000) });
        if (!res.ok) throw new Error('Error del servidor (' + res.status + ')');
        const data = await res.json();
        window._pollErrors = 0;
        if (data.status === 'pending') {
            if (pollCount > POLL_MAX_IDLE) {
                clearInterval(pollingInterval);
                setError('El agente no respondio. Verifica que este corriendo en tu PC.');
            } else {
                setStatus('scanning', 'Esperando el PDF del Epson... (' + (pollCount * 2) + 's)');
            }
        } else if (data.status === 'scanning') {
            setStatus('scanning', 'Escanea ahora en el Epson...');
            pollCount = Math.min(pollCount, POLL_MAX_IDLE - 1);
        } else if (data.status === 'completed' || data.status === 'ready') {
            clearInterval(pollingInterval);
            setStatus('scanning', 'Descargando PDF...');
            await loadFile(currentScanId);
        } else if (data.status === 'error') {
            clearInterval(pollingInterval);
            setError(data.message || 'Error en el escaner');
        }
    } catch (err) {
        window._pollErrors = (window._pollErrors || 0) + 1;
        if (window._pollErrors >= 3) {
            clearInterval(pollingInterval);
            window._pollErrors = 0;
            setError('Error de conexion con el servidor');
        }
    }
}

async function loadFile(scanId) {
    try {
        const res = await fetch('/scanner/download?scan_id=' + encodeURIComponent(scanId));
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || 'No se pudo descargar el PDF');
        }
        const blob = await res.blob();
        currentBlob = blob;
        const filename = getFilename();
        assignFileToInput(blob, filename);
        if (currentBlobUrl) URL.revokeObjectURL(currentBlobUrl);
        currentBlobUrl = URL.createObjectURL(blob);
        showPdfViewer(currentBlobUrl);
        fileNameEl.textContent = filename;
        fileSizeEl.textContent = formatBytes(blob.size);
        filePreview.style.display = 'flex';
        scannerBox.className = 'ready';
        submitBtn.disabled = false;
        document.getElementById('filename-section').style.display = 'block';
        setStatus('ready', 'Documento listo para enviar');
        showToast('PDF cargado correctamente', 'success');
        await confirmFile(scanId);
    } catch (err) {
        setError('No se pudo cargar el PDF: ' + err.message);
    }
}

async function confirmFile(scanId) {
    try {
        await fetch('/scanner/confirm', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ scan_id: scanId }),
        });
    } catch {}
}

function showPdfViewer(blobUrl) {
    const viewer = document.getElementById('pdf-viewer-container');
    document.getElementById('pdf-iframe').src = blobUrl;
    viewer.style.display = 'block';
    viewer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function closePdfViewer() {
    document.getElementById('pdf-viewer-container').style.display = 'none';
    document.getElementById('pdf-iframe').src = '';
}
function reopenViewer() { if (currentBlobUrl) showPdfViewer(currentBlobUrl); }
function downloadPdf() {
    if (!currentBlobUrl) return;
    const a = document.createElement('a');
    a.href = currentBlobUrl;
    a.download = getFilename();
    a.click();
}

function clearFile() {
    if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
    window._pollErrors = 0;
    currentBlob = null;
    inputEl.value = '';
    filePreview.style.display = 'none';
    submitBtn.disabled = true;
    scannerBox.className = '';
    const fi = document.getElementById('filename-input');
    if (fi) fi.value = '';
    document.getElementById('filename-section').style.display = 'none';
    closePdfViewer();
    if (currentBlobUrl) { URL.revokeObjectURL(currentBlobUrl); currentBlobUrl = null; }
    currentScanId = null;
    pollCount = 0;
    setStatus('idle', 'Presiona el boton y luego usa el Epson');
    setBusy(false);
}

async function checkAgentStatus() {
    const dot = document.getElementById('agent-dot');
    const text = document.getElementById('agent-status-text');
    try {
        const res = await fetch('/scanner/agent-status', { signal: AbortSignal.timeout(4000) });
        const data = await res.json();
        if (data.online) {
            dot.style.background = '#22c55e';
            text.style.color = '#16a34a';
            text.style.fontWeight = '600';
            text.textContent = 'Agente activo';
        } else {
            dot.style.background = '#f59e0b';
            text.style.color = '#92400e';
            text.style.fontWeight = 'normal';
            text.textContent = 'Agente no detectado';
        }
    } catch {
        dot.style.background = '#e2e8f0';
        text.style.color = '#94a3b8';
        text.style.fontWeight = 'normal';
        text.textContent = 'Sin conexion';
    }
    setTimeout(checkAgentStatus, 10000);
}

function showDownloadInstructions() {
    setTimeout(() => { document.getElementById('download-instructions').style.display = 'block'; }, 500);
}

function setBusy(busy) {
    scanBtn.disabled = busy;
    scanBtn.innerHTML = busy ? '<span class="spinner"></span> Esperando PDF...' : 'Escanear documento';
}
function setStatus(type, message) {
    statusMsg.innerHTML = (type === 'scanning' ? '<span class="spinner"></span>' : '') + message;
}
function setError(message) {
    if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
    scannerBox.className = 'error';
    setStatus('error', 'x ' + message);
    setBusy(false);
    showToast(message, 'error');
}
function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
}
function showToast(message, type) {
    const t = document.getElementById('toast');
    t.textContent = message;
    t.className = 'show ' + (type || 'info');
    setTimeout(() => { t.className = type || 'info'; }, 3000);
}

window.addEventListener('load', checkAgentStatus);
</script>
</body>
</html>