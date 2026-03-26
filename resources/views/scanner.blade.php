<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Escáner de documentos</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 700px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, .08);
            height: fit-content;
        }

        h1 {
            font-size: 22px;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 28px;
        }

        /* ── Sección del agente ── */
        .agent-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .agent-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .agent-icon {
            font-size: 28px;
        }

        .agent-info {
            flex: 1;
        }

        .agent-info p:first-child {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
            font-size: 14px;
        }

        .agent-info p:last-child {
            font-size: 12px;
            color: #94a3b8;
        }

        .agent-indicator {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
        }

        #agent-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e2e8f0;
            display: inline-block;
            transition: background .3s;
        }

        #agent-status-text {
            color: #94a3b8;
        }

        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            background: #3b82f6;
            color: white;
            transition: all .2s;
            border: none;
            cursor: pointer;
        }

        .btn-download:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        #download-instructions {
            display: none;
            margin-top: 14px;
            padding: 14px;
            background: #eff6ff;
            border-radius: 8px;
            font-size: 13px;
            color: #1e40af;
            line-height: 1.8;
        }

        /* ── Scanner box ── */
        #scanner-box {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 36px 24px;
            text-align: center;
            transition: all .3s;
            margin-bottom: 20px;
        }

        #scanner-box.active {
            border-color: #3b82f6;
            background: #eff6ff;
        }

        #scanner-box.ready {
            border-color: #22c55e;
            background: #f0fdf4;
        }

        #scanner-box.error {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .scanner-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            background: #f1f5f9;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            transition: background .3s;
        }

        #scanner-box.active .scanner-icon {
            background: #dbeafe;
        }

        #scanner-box.ready .scanner-icon {
            background: #dcfce7;
        }

        #scanner-box.error .scanner-icon {
            background: #fee2e2;
        }

        #btn-scan {
            padding: 12px 32px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            background: #3b82f6;
            color: white;
            transition: all .2s;
        }

        #btn-scan:hover:not(:disabled) {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, .4);
        }

        #btn-scan:disabled {
            opacity: .5;
            cursor: not-allowed;
            transform: none;
        }

        #status-msg {
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
            min-height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* ── Preview archivo ── */
        #file-preview {
            display: none;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            padding: 12px 16px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            font-size: 13px;
            color: #166534;
        }

        .file-info {
            flex: 1;
            text-align: left;
        }

        .file-name {
            font-weight: 600;
            display: block;
            margin-bottom: 2px;
        }

        .file-size {
            font-size: 11px;
            color: #4ade80;
        }

        #btn-preview-pdf {
            padding: 5px 12px;
            font-size: 12px;
            border: 1px solid #16a34a;
            border-radius: 6px;
            background: white;
            color: #16a34a;
            cursor: pointer;
            white-space: nowrap;
            transition: all .2s;
        }

        #btn-preview-pdf:hover {
            background: #dcfce7;
        }

        #btn-clear {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #94a3b8;
            flex-shrink: 0;
            transition: color .2s;
        }

        #btn-clear:hover {
            color: #ef4444;
        }

        /* ── Input file oculto ── */
        #pdfInput {
            display: none;
        }

        /* ── Divisor ── */
        .divider {
            height: 1px;
            background: #f1f5f9;
            margin: 20px 0;
        }

        /* ── Botón enviar ── */
        #btn-submit {
            width: 100%;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            background: #22c55e;
            color: white;
            transition: all .2s;
        }

        #btn-submit:hover:not(:disabled) {
            background: #16a34a;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, .4);
        }

        #btn-submit:disabled {
            opacity: .5;
            cursor: not-allowed;
            transform: none;
        }

        /* ── Visor PDF ── */
        #pdf-viewer-container {
            display: none;
            margin-top: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }

        .viewer-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .viewer-toolbar span {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }

        .viewer-actions {
            display: flex;
            gap: 8px;
        }

        .viewer-btn {
            padding: 5px 12px;
            font-size: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            color: #475569;
            cursor: pointer;
            transition: all .2s;
        }

        .viewer-btn:hover {
            background: #f1f5f9;
        }

        .viewer-btn.danger {
            color: #ef4444;
            border-color: #fecaca;
        }

        .viewer-btn.danger:hover {
            background: #fef2f2;
        }

        #pdf-iframe {
            width: 100%;
            height: 600px;
            border: none;
            display: block;
        }

        /* ── Spinner ── */
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            opacity: .6;
            flex-shrink: 0;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ── Toast ── */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            color: white;
            opacity: 0;
            transform: translateY(10px);
            transition: all .3s;
            pointer-events: none;
            z-index: 1000;
        }

        #toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        #toast.success {
            background: #22c55e;
        }

        #toast.error {
            background: #ef4444;
        }

        #toast.info {
            background: #3b82f6;
        }
    </style>
</head>

<body>

    <div class="card">
        <h1>Escáner de documentos</h1>
        <p class="subtitle">Escanea tu documento y envíalo automáticamente</p>

        <div class="agent-section">
            <div class="agent-header">
                <span class="agent-icon"></span>
                <div class="agent-info">
                    <p>Agente del escáner</p>
                    <p>Instálalo una sola vez en tu PC para poder escanear</p>
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
                1. Extrae el ZIP en cualquier carpeta de tu PC<br>
                2. Haz doble clic en <strong>instalar.bat</strong><br>
                3. Espera a que diga <em>"Agente instalado correctamente"</em><br>
                4. ¡Listo! El indicador de arriba se pondrá en verde<br><br>
                <span style="color:#64748b; font-size:12px;">
                    Solo necesitas hacer esto una vez. El agente se iniciará
                    automáticamente cada vez que enciendas tu PC.
                </span>
            </div>
        </div>

        <form id="main-form" method="POST" action="/guardar" enctype="multipart/form-data">
            @csrf

            <div id="scanner-box">
                <div class="scanner-icon">📄</div>

                <button type="button" id="btn-scan" onclick="startScan()">
                    Escanear documento
                </button>

                <div id="status-msg">
                    Coloca el documento en el escáner y presiona el botón
                </div>

                <div id="file-preview">
                    <div class="file-info">
                        <span class="file-name" id="file-name">documento.pdf</span>
                        <span class="file-size" id="file-size"></span>
                    </div>
                    <button type="button" id="btn-preview-pdf" onclick="reopenViewer()">
                        Ver PDF
                    </button>
                    <button type="button" id="btn-clear" onclick="clearFile()">✕</button>
                </div>
            </div>

            <input type="file" id="pdfInput" name="documento" accept="application/pdf" />

            <div class="divider"></div>

            <button type="submit" id="btn-submit" disabled>
                Enviar documento
            </button>
        </form>

        <div id="pdf-viewer-container">
            <div class="viewer-toolbar">
                <span>Vista previa</span>
                <div class="viewer-actions">
                    <button class="viewer-btn" onclick="downloadPdf()">⬇ Descargar</button>
                    <button class="viewer-btn danger" onclick="closePdfViewer()">✕ Cerrar</button>
                </div>
            </div>
            <iframe id="pdf-iframe" src="" type="application/pdf"></iframe>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        // ── Referencias DOM ───────────────────────────────────────────────────
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

        // FIX #1: Timeout absoluto independiente del estado (evita polling infinito)
        const POLL_MAX_IDLE = 45;  // 90s sin actividad → error
        const POLL_MAX_ABSOLUTE = 150; // 300s tope absoluto → error

        // ── Inicia el escaneo ─────────────────────────────────────────────────
        async function startScan() {
            setBusy(true);
            scannerBox.className = 'active';
            setStatus('scanning', 'Enviando solicitud al escáner...');
            pollCount = 0;

            try {
                const res = await fetch('/scanner/scan', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json',
                    },
                });

                // FIX #2: Manejo explícito de respuestas no-OK del servidor
                if (!res.ok) {
                    const errData = await res.json().catch(() => ({}));
                    throw new Error(errData.message || `Error del servidor (${res.status})`);
                }

                const data = await res.json();

                if (!data.success) throw new Error(data.message || 'Error al iniciar');

                currentScanId = data.scan_id;
                setStatus('scanning', 'Esperando al escáner...');

                pollingInterval = setInterval(pollStatus, 2000);

            } catch (err) {
                setError(err.message);
            }
        }

        // ── Polling ───────────────────────────────────────────────────────────
        async function pollStatus() {
            try {
                pollCount++;

                // FIX #3: Timeout absoluto para evitar polling eterno
                if (pollCount > POLL_MAX_ABSOLUTE) {
                    clearInterval(pollingInterval);
                    setError('Tiempo máximo de espera agotado. Intenta de nuevo.');
                    return;
                }

                const res = await fetch(
                    `/scanner/poll?scan_id=${encodeURIComponent(currentScanId)}`,
                    { signal: AbortSignal.timeout(5000) }
                );

                // FIX #4: Manejo de respuestas HTTP no-OK en el polling
                if (!res.ok) {
                    throw new Error(`Error del servidor (${res.status})`);
                }

                const data = await res.json();

                switch (data.status) {

                    case 'pending':
                        // FIX #5: Timeout de idle separado del absoluto
                        if (pollCount > POLL_MAX_IDLE) {
                            clearInterval(pollingInterval);
                            setError('El escáner no respondió. ¿Está activo el agente?');
                        } else {
                            setStatus('scanning', `Esperando al agente... (${pollCount * 2}s)`);
                        }
                        break;

                    case 'scanning':
                        setStatus('scanning', 'Escaneando hojas del documento...');
                        // FIX #6: Resetea solo el contador de idle, no el absoluto
                        pollCount = Math.min(pollCount, POLL_MAX_IDLE - 1);
                        break;

                    // FIX #7: 'completed' y 'ready' van al mismo handler
                    case 'completed':
                    case 'ready':
                        clearInterval(pollingInterval);
                        // FIX #8: Valida que el servidor retornó un filename
                        if (!data.filename) {
                            setError('El servidor no devolvió el nombre del archivo.');
                            return;
                        }
                        setStatus('scanning', 'Descargando PDF...');
                        await loadFile(currentScanId);

                        break;

                    case 'error':
                        clearInterval(pollingInterval);
                        setError(data.message || 'Error en el escáner');
                        break;

                    default:
                        // Status desconocido — ignorar silenciosamente
                        break;
                }

            } catch (err) {
                // FIX #9: Un error de red no detiene el polling inmediatamente
                // Solo detiene si es un error persistente (3 fallos seguidos)
                if (!window._pollErrors) window._pollErrors = 0;
                window._pollErrors++;

                if (window._pollErrors >= 3) {
                    clearInterval(pollingInterval);
                    window._pollErrors = 0;
                    setError('Error de conexión con el servidor');
                }
            }
        }

        async function loadFile(scanId) {
            try {
                const res = await fetch(`/scanner/download?scan_id=${encodeURIComponent(scanId)}`);

                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.error || 'No se pudo descargar el PDF');
                }

                const blob = await res.blob();
                const filename = 'documento.pdf';
                const file = new File([blob], filename, { type: 'application/pdf' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);

                inputEl.files = dataTransfer.files;
                inputEl.dispatchEvent(new Event('change', { bubbles: true }));

                if (currentBlobUrl) URL.revokeObjectURL(currentBlobUrl);
                currentBlobUrl = URL.createObjectURL(blob);

                showPdfViewer(currentBlobUrl);

                fileNameEl.textContent = filename;
                fileSizeEl.textContent = formatBytes(blob.size);
                filePreview.style.display = 'flex';
                scannerBox.className = 'ready';
                submitBtn.disabled = false;

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
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                    },
                    body: JSON.stringify({ scan_id: scanId }),
                });
            } catch { }
        }

        // ── Visor de PDF ──────────────────────────────────────────────────────
        function showPdfViewer(blobUrl) {
            const viewer = document.getElementById('pdf-viewer-container');
            const iframe = document.getElementById('pdf-iframe');
            iframe.src = blobUrl;
            viewer.style.display = 'block';
            viewer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function closePdfViewer() {
            const viewer = document.getElementById('pdf-viewer-container');
            const iframe = document.getElementById('pdf-iframe');
            viewer.style.display = 'none';
            iframe.src = '';
        }

        function reopenViewer() {
            if (currentBlobUrl) showPdfViewer(currentBlobUrl);
        }

        function downloadPdf() {
            if (!currentBlobUrl) return;
            const a = document.createElement('a');
            a.href = currentBlobUrl;
            a.download = fileNameEl.textContent || 'documento.pdf';
            a.click();
        }

        // ── Limpia el archivo ─────────────────────────────────────────────────
        function clearFile() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
            window._pollErrors = 0;
            inputEl.value = '';
            filePreview.style.display = 'none';
            submitBtn.disabled = true;
            scannerBox.className = '';
            closePdfViewer();

            if (currentBlobUrl) {
                URL.revokeObjectURL(currentBlobUrl);
                currentBlobUrl = null;
            }

            currentScanId = null;
            pollCount = 0;
            setStatus('idle', 'Coloca el documento en el escáner y presiona el botón');
            setBusy(false);
        }

        // ── Estado del agente ─────────────────────────────────────────────────
        async function checkAgentStatus() {
            const dot = document.getElementById('agent-dot');
            const text = document.getElementById('agent-status-text');

            try {
                const res = await fetch('/scanner/agent-ping', {
                    signal: AbortSignal.timeout(4000)
                });

                // FIX #14: Verifica HTTP status antes de parsear JSON
                if (!res.ok) {
                    setAgentUnknown(dot, text);
                    return;
                }

                const data = await res.json();

                if (data.online) {
                    dot.style.background = '#22c55e';
                    text.style.color = '#16a34a';
                    text.style.fontWeight = '600';
                    text.textContent = 'Agente activo ✓';
                } else {
                    setAgentInactive(dot, text);
                }
            } catch {
                setAgentUnknown(dot, text);
            }

            // FIX #15: Usa setTimeout en vez de encadenado para evitar
            // múltiples timers si la función se llama varias veces
            setTimeout(checkAgentStatus, 10000);
        }

        function setAgentInactive(dot, text) {
            dot.style.background = '#f59e0b';
            text.style.color = '#92400e';
            text.style.fontWeight = 'normal';
            text.textContent = 'Agente no detectado';
        }

        function setAgentUnknown(dot, text) {
            dot.style.background = '#e2e8f0';
            text.style.color = '#94a3b8';
            text.style.fontWeight = 'normal';
            text.textContent = 'Sin conexión';
        }

        function showDownloadInstructions() {
            setTimeout(() => {
                document.getElementById('download-instructions').style.display = 'block';
            }, 500);
        }

        // ── Helpers ───────────────────────────────────────────────────────────
        function setBusy(busy) {
            scanBtn.disabled = busy;
            scanBtn.innerHTML = busy
                ? '<span class="spinner"></span> Escaneando...'
                : 'Escanear documento';
        }

        function setStatus(type, message) {
            const spinner = type === 'scanning'
                ? '<span class="spinner"></span>'
                : '';
            statusMsg.innerHTML = spinner + message;
        }

        function setError(message) {
            scannerBox.className = 'error';
            setStatus('error', '✕ ' + message);
            setBusy(false);
            showToast(message, 'error');
        }

        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `show ${type}`;
            setTimeout(() => { toast.className = type; }, 3000);
        }

        window.addEventListener('load', checkAgentStatus);
    </script>
</body>

</html>