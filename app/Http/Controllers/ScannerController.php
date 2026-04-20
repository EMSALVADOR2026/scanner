<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ScannerController extends Controller
{
    // Tiempos de vida de cada clave en cache (segundos)
    private const LOCK_TTL    = 90;   // máximo tiempo de un escaneo activo
    private const PENDING_TTL = 300;  // el agente tiene 5 min para tomar la tarea
    private const STATUS_TTL  = 600;  // el status vive 10 min

    // ── Token de autenticación del agente ─────────────────────────────────────
    private function validateToken(Request $request): bool
    {
        return $request->header('X-Scanner-Token') === config('scanner.token');
    }

    // ── Helpers de cache ──────────────────────────────────────────────────────
    private function getStatus(): ?array
    {
        return Cache::get('scanner:status');
    }

    private function putStatus(array $data): void
    {
        Cache::put('scanner:status', $data, self::STATUS_TTL);
    }

    // =========================================================================
    // RUTAS DEL NAVEGADOR
    // =========================================================================

    public function index()
    {
        return view('scanner');
    }

    /**
     * El navegador solicita un nuevo escaneo.
     * Genera un scan_id único y deja el trabajo pendiente para el agente.
     */
    public function scan(Request $request)
    {
        // Lock: evita dos escaneos simultáneos
        if (Cache::get('scanner:lock')) {
            return response()->json([
                'success' => false,
                'message' => 'El escáner está ocupado. Espera un momento.',
            ], 423);
        }

        $scanId = Str::uuid()->toString();

        Cache::put('scanner:lock',    $scanId, self::LOCK_TTL);
        Cache::put('scanner:pending', [
            'scan_id' => $scanId,
            'created' => now()->timestamp,
        ], self::PENDING_TTL);

        $this->putStatus([
            'scan_id'  => $scanId,
            'status'   => 'pending',
            'filename' => null,
            'message'  => '',
            'updated'  => now()->timestamp,
        ]);

        Storage::disk('local')->makeDirectory('scanner/incoming');
        Storage::disk('local')->makeDirectory('scanner/processed');

        return response()->json([
            'success' => true,
            'scan_id' => $scanId,
        ]);
    }

    /**
     * El navegador consulta el estado del escaneo (polling cada ~2 seg).
     */
    public function poll(Request $request)
    {
        $scanId = $request->query('scan_id');

        if (!$scanId || $scanId === 'ping-check') {
            return response()->json(['status' => 'idle'], 400);
        }

        $data = $this->getStatus();

        if (!$data || ($data['scan_id'] ?? '') !== $scanId) {
            return response()->json(['status' => 'pending']);
        }

        $status = $data['status'] ?? 'pending';

        // Normaliza 'completed' → 'ready'
        if ($status === 'completed') {
            $status = 'ready';
        }

        // Libera el lock cuando el proceso termina
        if (in_array($status, ['ready', 'error'])) {
            Cache::forget('scanner:lock');
        }

        return response()->json([
            'status'   => $status,
            'filename' => $data['filename'] ?? null,
            'message'  => $data['message']  ?? null,
        ]);
    }

    /**
     * El navegador descarga el PDF generado.
     */
    public function download(Request $request)
    {
        $scanId = $request->query('scan_id');

        if (!$scanId) {
            return response()->json(['error' => 'scan_id requerido'], 400);
        }

        $data = $this->getStatus();

        if (!$data || ($data['scan_id'] ?? '') !== $scanId) {
            return response()->json(['error' => 'scan_id no coincide'], 404);
        }

        $filename = $data['filename'] ?? null;

        if (!$filename) {
            return response()->json(['error' => 'Archivo no disponible aún'], 404);
        }

        $safeName = basename($filename);

        if (Storage::disk('local')->exists('scanner/incoming/' . $safeName)) {
            $fullPath = Storage::disk('local')->path('scanner/incoming/' . $safeName);
        } elseif (Storage::disk('local')->exists('scanner/processed/' . $safeName)) {
            $fullPath = Storage::disk('local')->path('scanner/processed/' . $safeName);
        } else {
            Log::error('Scanner: archivo no encontrado: ' . $safeName);
            return response()->json(['error' => 'Archivo no encontrado'], 404);
        }

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeName . '"',
        ]);
    }

    /**
     * El navegador confirma que ya descargó el PDF.
     * Mueve el archivo a /processed y limpia el estado.
     */
    public function confirm(Request $request)
    {
        $scanId = $request->input('scan_id');

        if (!$scanId) {
            return response()->json(['ok' => false, 'error' => 'scan_id requerido'], 400);
        }

        $data     = $this->getStatus();
        $filename = $data['filename'] ?? null;

        if ($filename) {
            $from = 'scanner/incoming/'  . basename($filename);
            $to   = 'scanner/processed/' . basename($filename);
            if (Storage::disk('local')->exists($from)) {
                Storage::disk('local')->move($from, $to);
            }
        }

        Cache::forget('scanner:lock');
        Cache::forget('scanner:status');

        return response()->json(['ok' => true]);
    }

    /**
     * Genera y descarga el ZIP instalador para la PC del cliente (Windows).
     * scan.ps1 viene de resources/scanner/scan.ps1 (está en git).
     */
    public function downloadInstaller()
    {
        $serverUrl = config('app.url');
        $token     = config('scanner.token');

        $ps1Path = resource_path('scanner/scan.ps1');
        $zipPath = storage_path('app/scanner/ScannerAgente.zip');

        if (!file_exists($ps1Path)) {
            return response()->json([
                'error' => 'scan.ps1 no encontrado en resources/scanner/scan.ps1',
            ], 404);
        }

        Storage::disk('local')->makeDirectory('scanner');

        $batContent =
            "@echo off\r\n" .
            "set INSTALL_DIR=%USERPROFILE%\\ScannerAgente\r\n" .
            "set STARTUP_DIR=%APPDATA%\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\r\n" .
            "if not exist \"%INSTALL_DIR%\" mkdir \"%INSTALL_DIR%\"\r\n" .
            "if not exist \"%INSTALL_DIR%\\processed\" mkdir \"%INSTALL_DIR%\\processed\"\r\n" .
            "copy \"%~dp0scan.ps1\" \"%INSTALL_DIR%\\scan.ps1\" /Y\r\n" .
            "\r\n" .
            "(\r\n" .
            "echo SCANNER_SERVER_URL={$serverUrl}\r\n" .
            "echo SCANNER_TOKEN={$token}\r\n" .
            "echo SCANNER_WATCH_FOLDER=%USERPROFILE%\\Documents\r\n" .
            "echo SCANNER_ARCHIVE_FOLDER=%INSTALL_DIR%\\processed\r\n" .
            "echo SCANNER_WAIT_SECONDS=60\r\n" .
            ") > \"%INSTALL_DIR%\\.env\"\r\n" .
            "\r\n" .
            "(\r\n" .
            "echo Dim Shell\r\n" .
            "echo Set Shell = CreateObject^(\"WScript.Shell\"^)\r\n" .
            "echo Shell.Run \"powershell -WindowStyle Hidden -ExecutionPolicy Bypass -File \"\"%INSTALL_DIR%\\scan.ps1\"\"\", 0, False\r\n" .
            "echo Set Shell = Nothing\r\n" .
            ") > \"%STARTUP_DIR%\\ScannerAgente.vbs\"\r\n" .
            "\r\n" .
            "start \"\" wscript.exe \"%STARTUP_DIR%\\ScannerAgente.vbs\"\r\n" .
            "echo.\r\n" .
            "echo [OK] Agente instalado y ejecutandose\r\n" .
            "echo [OK] Servidor: {$serverUrl}\r\n" .
            "echo [OK] Al reiniciar la PC el agente se iniciara automaticamente\r\n" .
            "pause\r\n";

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear el ZIP'], 500);
        }
        $zip->addFromString('instalar.bat', $batContent);
        $zip->addFile($ps1Path, 'scan.ps1');
        $zip->close();

        return response()->download($zipPath, 'ScannerAgente.zip')->deleteFileAfterSend(false);
    }

    // =========================================================================
    // RUTAS DEL AGENTE PowerShell (requieren X-Scanner-Token)
    // =========================================================================

    /**
     * El agente pregunta cada ~500ms si hay trabajo.
     */
    public function pending(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $data = Cache::get('scanner:pending');

        if (!$data) {
            return response()->json(['pending' => false]);
        }

        // Expirado manualmente (por si el TTL del cache falló)
        if (now()->timestamp - ($data['created'] ?? 0) > self::PENDING_TTL) {
            Cache::forget('scanner:pending');
            return response()->json(['pending' => false]);
        }

        // Consume el pending — no se puede entregar dos veces
        Cache::forget('scanner:pending');

        // Actualiza el status a 'scanning' para que el navegador lo vea
        $this->putStatus([
            'scan_id'  => $data['scan_id'],
            'status'   => 'scanning',
            'filename' => null,
            'message'  => '',
            'updated'  => now()->timestamp,
        ]);

        return response()->json([
            'pending' => true,
            'scan_id' => $data['scan_id'],
        ]);
    }

    /**
     * El agente sube el PDF escaneado.
     */
    public function receive(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $request->validate([
            'file'    => 'required|file|mimes:pdf|max:102400',
            'scan_id' => 'required|string|uuid',
        ]);

        $scanId  = $request->input('scan_id');
        $current = $this->getStatus();

        if ($current && ($current['scan_id'] ?? '') !== $scanId) {
            Log::warning('Scanner receive: scan_id no coincide. ' .
                'Activo: ' . ($current['scan_id'] ?? 'ninguno') . ' | Recibido: ' . $scanId);
            return response()->json(['error' => 'scan_id no coincide'], 409);
        }

        $safeId   = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
        $filename = 'scan_' . $safeId . '.pdf';
        $path     = 'scanner/incoming/' . $filename;

        Storage::disk('local')->makeDirectory('scanner/incoming');

        try {
            Storage::disk('local')->put(
                $path,
                file_get_contents($request->file('file')->getRealPath())
            );

            $savedPath = Storage::disk('local')->path($path);
            if (!file_exists($savedPath) || filesize($savedPath) === 0) {
                throw new \RuntimeException('El archivo guardado está vacío');
            }

        } catch (\Throwable $e) {
            Log::error('Scanner: error guardando PDF: ' . $e->getMessage());
            $this->putStatus([
                'scan_id'  => $scanId,
                'status'   => 'error',
                'filename' => null,
                'message'  => 'Error al guardar el archivo en el servidor',
                'updated'  => now()->timestamp,
            ]);
            return response()->json(['error' => 'No se pudo guardar el archivo'], 500);
        }

        $this->putStatus([
            'scan_id'  => $scanId,
            'status'   => 'ready',
            'filename' => $filename,
            'updated'  => now()->timestamp,
        ]);

        Log::info('Scanner: PDF recibido → ' . $filename . ' (' .
            filesize(Storage::disk('local')->path($path)) . ' bytes)');

        return response()->json(['success' => true]);
    }

    /**
     * El agente reporta un error durante el escaneo.
     */
    public function updateStatus(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $current = $this->getStatus();

        if ($current) {
            $current['status']  = $request->input('status');
            $current['message'] = $request->input('message', '');
            $current['updated'] = now()->timestamp;

            if ($request->has('filename')) {
                $current['filename'] = $request->input('filename');
            }

            $this->putStatus($current);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * El agente hace ping cada ~30s para indicar que está vivo.
     * El navegador usa esto para mostrar el indicador verde.
     */
    public function agentPing(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        Cache::put('scanner:agent_online', true, 30);

        return response()->json(['ok' => true]);
    }
}