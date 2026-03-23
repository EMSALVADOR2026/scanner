<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Scanner Test</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: sans-serif;
            background: #f1f5f9;
            display: flex;
            justify-content: center;
            padding: 40px 20px;
            min-height: 100vh;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 700px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            height: fit-content;
        }

        h1 { font-size: 20px; color: #1e293b; margin-bottom: 24px; }

        #scanner-box {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 32px 24px;
            text-align: center;
            transition: all .3s;
            margin-bottom: 20px;
        }
        #scanner-box.active { border-color: #3b82f6; background: #eff6ff; }
        #scanner-box.ready  { border-color: #22c55e; background: #f0fdf4; }
        #scanner-box.error  { border-color: #ef4444; background: #fef2f2; }

        #btn-scan {
            padding: 12px 32px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            background: #3b82f6;
            color: white;
            transition: opacity .2s, transform .1s;
        }
        #btn-scan:hover:not(:disabled) { opacity: .9; transform: translateY(-1px); }
        #btn-scan:disabled { opacity: .5; cursor: not-allowed; }

        #status-msg {
            margin-top: 16px;
            font-size: 13px;
            color: #64748b;
            min-height: 20px;
        }

        #file-preview {
            display: none;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding: 10px 14px;
            background: #dcfce7;
            border-radius: 8px;
            font-size: 13px;
            color: #166534;
        }
        #file-preview span { flex: 1; text-align: left; word-break: break-all; }

        #btn-clear {
            background: none; border: none;
            font-size: 16px; cursor: pointer;
            color: #dc2626; flex-shrink: 0;
        }

        #btn-preview {
            padding: 4px 10px;
            font-size: 12px;
            border: 1px solid #16a34a;
            border-radius: 6px;
            background: white;
            color: #16a34a;
            cursor: pointer;
            white-space: nowrap;
        }
        #btn-preview:hover { background: #f0fdf4; }

        #pdfInput { display: none; }

        #btn-submit {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            background: #22c55e;
            color: white;
            transition: opacity .2s;
        }
        #btn-submit:hover { opacity: .9; }

        /* ── Visor PDF ── */
        #pdf-viewer-container {
            display: none;
            margin-top: 24px;
        }

        .viewer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .viewer-header span {
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }

        .viewer-header button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: #64748b;
            line-height: 1;
        }
        .viewer-header button:hover { color: #dc2626; }

        #pdf-iframe {
            width: 100%;
            height: 600px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        /* ── Spinner ── */
        .spinner {
            display: inline-block;
            width: 14px; height: 14px;
            border: 2px solid #bfdbfe;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="card">
    <h1>Prueba de Escáner</h1>

    <form method="POST" action="/guardar" enctype="multipart/form-data">
        @csrf

        <div id="scanner-box">
            <button type="button" id="btn-scan" onclick="startScan()">
                Escanear documento
            </button>
            <div id="status-msg">Haz clic para iniciar el escaneo</div>

            <div id="file-preview">
                📄 <span id="file-name"></span>
                <button type="button" id="btn-preview" onclick="reopenViewer()">
                    👁 Ver PDF
                </button>
                <button type="button" id="btn-clear" onclick="clearFile()">✕</button>
            </div>
        </div>

        <input type="file" id="pdfInput" name="documento" accept="application/pdf" />

        <button type="submit" id="btn-submit">Enviar formulario</button>
    </form>

    {{-- Visor de PDF --}}
    <div id="pdf-viewer-container">
        <div class="viewer-header">
            <span>👁 Vista previa del documento</span>
            <button type="button" onclick="closePdfViewer()">✕</button>
        </div>
        <iframe id="pdf-iframe" src="" type="application/pdf"></iframe>
    </div>
</div>

<script>
    const CSRF        = document.querySelector('meta[name="csrf-token"]').content;
    const scanBtn     = document.getElementById('btn-scan');
    const statusMsg   = document.getElementById('status-msg');
    const scannerBox  = document.getElementById('scanner-box');
    const filePreview = document.getElementById('file-preview');
    const fileNameEl  = document.getElementById('file-name');
    const inputEl     = document.getElementById('pdfInput');

    let currentBlobUrl = null;

    async function startScan() {
        setBusy(true);
        scannerBox.className = 'active';
        setStatus('scanning', 'Comunicando con el escáner...');

        try {
            const res  = await fetch('/scanner/scan', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': CSRF,
                    'Accept':       'application/json',
                },
            });

            const data = await res.json();

            if (!data.success) throw new Error(data.message || 'Error desconocido');

            setStatus('scanning', 'Descargando PDF...');
            await loadFile(data.filename);

        } catch (err) {
            scannerBox.className = 'error';
            setStatus('error', '' + err.message);
            setBusy(false);
        }
    }

    async function loadFile(filename) {
        const res = await fetch(`/scanner/download?filename=${encodeURIComponent(filename)}`);

        if (!res.ok) throw new Error('No se pudo descargar el PDF del servidor');

        const blob         = await res.blob();
        const file         = new File([blob], filename, { type: 'application/pdf' });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);

        // Asigna al input
        inputEl.files = dataTransfer.files;
        inputEl.dispatchEvent(new Event('change', { bubbles: true }));

        // Guarda la URL del blob para poder reabrir el visor
        if (currentBlobUrl) URL.revokeObjectURL(currentBlobUrl);
        currentBlobUrl = URL.createObjectURL(blob);

        // Muestra el visor
        showPdfViewer(currentBlobUrl);

        fileNameEl.textContent    = filename;
        filePreview.style.display = 'flex';
        scannerBox.className      = 'ready';
        setStatus('ready', 'Documento listo para enviar');
        setBusy(false);

        await confirmFile(filename);
    }

    function showPdfViewer(blobUrl) {
        const viewer = document.getElementById('pdf-viewer-container');
        const iframe = document.getElementById('pdf-iframe');
        iframe.src   = blobUrl;
        viewer.style.display = 'block';
        viewer.scrollIntoView({ behavior: 'smooth' });
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

    async function confirmFile(filename) {
        await fetch('/scanner/confirm', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
            },
            body: JSON.stringify({ filename }),
        });
    }

    function clearFile() {
        inputEl.value             = '';
        filePreview.style.display = 'none';
        scannerBox.className      = '';
        closePdfViewer();
        if (currentBlobUrl) {
            URL.revokeObjectURL(currentBlobUrl);
            currentBlobUrl = null;
        }
        setStatus('waiting', 'Haz clic para iniciar el escaneo');
    }

    function setBusy(busy) {
        scanBtn.disabled    = busy;
        scanBtn.textContent = busy ? 'Escaneando...' : 'Escanear documento';
    }

    function setStatus(type, message) {
        const spinner = type === 'scanning' ? '<span class="spinner"></span>' : '';
        statusMsg.innerHTML = spinner + message;
    }
</script>

</body>
</html>