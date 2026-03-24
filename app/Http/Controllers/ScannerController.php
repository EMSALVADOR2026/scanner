<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScannerController extends Controller
{
    // ── Helper de rutas ───────────────────────────────────────────────────────
    private function scannerPath(string $append = ''): string
    {
        $base = storage_path('app' . DIRECTORY_SEPARATOR . 'scanner');
        return $append ? $base . DIRECTORY_SEPARATOR . $append : $base;
    }

    // ── Valida que la petición viene del agente ───────────────────────────────
    private function validateToken(Request $request): bool
    {
        return $request->header('X-Scanner-Token') === config('scanner.token');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RUTAS DEL NAVEGADOR
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        return view('scanner');
    }

    // Usuario presiona "Escanear" — responde INMEDIATAMENTE
    public function scan(Request $request)
    {
        Storage::disk('local')->makeDirectory('scanner/incoming');
        Storage::disk('local')->makeDirectory('scanner/processed');

        $lockFile = $this->scannerPath('scanner.lock');

        // FIX #1: Duración del lock alineada con el timeout del polling en JS (90s)
        if (file_exists($lockFile) && time() - filemtime($lockFile) < 90) {
            return response()->json([
                'success' => false,
                'message' => 'El escáner está ocupado. Espera un momento.',
            ], 423);
        }

        file_put_contents($lockFile, getmypid());

        $scanId = Str::uuid()->toString();

        Storage::disk('local')->put('scanner/pending.json', json_encode([
            'scan_id' => $scanId,
            'created' => now()->timestamp,
        ]));

        Storage::disk('local')->put('scanner/status.json', json_encode([
            'scan_id'  => $scanId,
            'status'   => 'pending',
            'filename' => null,
            'message'  => '',
            'updated'  => now()->timestamp,
        ]));

        return response()->json([
            'success' => true,
            'scan_id' => $scanId,
        ]);
    }

    // El JS pregunta el estado cada 2 segundos
    public function poll(Request $request)
    {
        $scanId     = $request->query('scan_id');
        $statusFile = 'scanner/status.json';
        $lockFile   = $this->scannerPath('scanner.lock');

        if (!$scanId) {
            return response()->json(['status' => 'error', 'message' => 'scan_id requerido'], 400);
        }

        if (!Storage::disk('local')->exists($statusFile)) {
            return response()->json(['status' => 'pending']);
        }

        $data = json_decode(Storage::disk('local')->get($statusFile), true);

        if (($data['scan_id'] ?? '') !== $scanId) {
            return response()->json(['status' => 'pending']);
        }

        $status = $data['status'] ?? 'pending';

        // FIX #2: 'completed' se trata igual que 'ready' para el frontend
        // Además se limpia el lock en ambos casos
        if (in_array($status, ['ready', 'completed', 'error'])) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
            // Normaliza 'completed' → 'ready' para que el JS siempre entre al mismo case
            if ($status === 'completed') {
                $status = 'ready';
            }
        }

        return response()->json([
            'status'   => $status,
            'filename' => $data['filename'] ?? null,
            'message'  => $data['message']  ?? null,
        ]);
    }

    // Descarga el PDF al navegador
    public function download(Request $request)
    {
        $scanId     = $request->query('scan_id');
        $statusFile = 'scanner/status.json';

        if (!$scanId) {
            return response()->json(['error' => 'scan_id requerido'], 400);
        }

        // Lee el status.json para obtener el filename real
        if (!Storage::disk('local')->exists($statusFile)) {
            return response()->json(['error' => 'No hay escaneo activo'], 404);
        }

        $data = json_decode(Storage::disk('local')->get($statusFile), true);

        if (($data['scan_id'] ?? '') !== $scanId) {
            return response()->json(['error' => 'scan_id no coincide'], 404);
        }

        $filename = $data['filename'] ?? null;

        if (!$filename) {
            return response()->json(['error' => 'Filename no disponible'], 404);
        }

        $fullPath = storage_path(
            'app' . DIRECTORY_SEPARATOR . 'scanner' .
                DIRECTORY_SEPARATOR . 'incoming' .
                DIRECTORY_SEPARATOR . basename($filename)
        );

        Log::info('Descargando: ' . $fullPath);

        if (!file_exists($fullPath)) {
            Log::error('Archivo no encontrado: ' . $fullPath);
            return response()->json([
                'error' => 'Archivo no encontrado: ' . $filename
            ], 404);
        }

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
    // Confirma que el PDF fue tomado por el navegador
    public function confirm(Request $request)
    {
        $scanId     = $request->input('scan_id');
        $statusFile = 'scanner/status.json';

        if (!$scanId) {
            return response()->json(['ok' => false, 'error' => 'scan_id requerido'], 400);
        }

        // Obtiene el filename desde status.json
        $filename = null;
        if (Storage::disk('local')->exists($statusFile)) {
            $data     = json_decode(Storage::disk('local')->get($statusFile), true);
            $filename = $data['filename'] ?? null;
        }

        // Mueve el archivo a processed
        if ($filename) {
            $from = 'scanner/incoming/'  . basename($filename);
            $to   = 'scanner/processed/' . basename($filename);

            if (Storage::disk('local')->exists($from)) {
                Storage::disk('local')->move($from, $to);
            }
        }

        // Limpia el lock y el status
        $lockFile = $this->scannerPath('scanner.lock');
        if (file_exists($lockFile)) unlink($lockFile);

        Storage::disk('local')->delete($statusFile);

        return response()->json(['ok' => true]);
    }


    public function downloadInstaller()
    {
        $serverUrl = config('app.url');
        $token     = config('scanner.token');
        $zipPath   = storage_path('app/scanner/ScannerAgente.zip');
        $ps1Path   = storage_path('app/scanner/scan.ps1');

        if (!file_exists($ps1Path)) {
            return response()->json(['error' => 'scan.ps1 no encontrado'], 404);
        }

        $batContent = "@echo off\r\n" .
            "title Instalador del Agente Escaner\r\n" .
            "echo ================================================\r\n" .
            "echo   Instalando agente del escaner...\r\n" .
            "echo ================================================\r\n" .
            "echo.\r\n" .
            "set SCRIPT_DIR=%~dp0\r\n" .
            "set STARTUP_DIR=%APPDATA%\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\r\n" .
            "\r\n" .
            "set INSTALL_DIR=%USERPROFILE%\\ScannerAgente\r\n" .
            "if not exist \"%INSTALL_DIR%\" mkdir \"%INSTALL_DIR%\"\r\n" .
            "copy \"%SCRIPT_DIR%scan.ps1\" \"%INSTALL_DIR%\\scan.ps1\" /Y\r\n" .
            "\r\n" .
            "(\r\n" .
            "echo Dim Shell\r\n" .
            "echo Set Shell = CreateObject^(\"WScript.Shell\"^)\r\n" .
            "echo Shell.Run \"powershell -WindowStyle Hidden -ExecutionPolicy Bypass -File \"\"%INSTALL_DIR%\\scan.ps1\"\" -ServerUrl \"\"{$serverUrl}\"\" -Token \"\"{$token}\"\"\", 0, False\r\n" .
            "echo Set Shell = Nothing\r\n" .
            ") > \"%STARTUP_DIR%\\ScannerAgente.vbs\"\r\n" .
            "\r\n" .
            "start \"\" wscript.exe \"%STARTUP_DIR%\\ScannerAgente.vbs\"\r\n" .
            "\r\n" .
            "echo.\r\n" .
            "echo [OK] Agente instalado en: %INSTALL_DIR%\r\n" .
            "echo [OK] Se iniciara automaticamente al encender la PC\r\n" .
            "echo [OK] Corriendo en segundo plano ahora mismo\r\n" .
            "echo.\r\n" .
            "pause\r\n";

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('instalar.bat', $batContent);
        $zip->addFile($ps1Path, 'scan.ps1');
        $zip->close();

        return response()->download($zipPath, 'ScannerAgente.zip')
            ->deleteFileAfterSend(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RUTAS DEL AGENTE POWERSHELL
    // ─────────────────────────────────────────────────────────────────────────

    // Agente pregunta si hay trabajo pendiente
    public function pending(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $pendingFile = 'scanner/pending.json';

        if (!Storage::disk('local')->exists($pendingFile)) {
            return response()->json(['pending' => false]);
        }

        $data = json_decode(Storage::disk('local')->get($pendingFile), true);

        if (now()->timestamp - ($data['created'] ?? 0) > 300) {
            Storage::disk('local')->delete($pendingFile);
            return response()->json(['pending' => false]);
        }

        Storage::disk('local')->delete($pendingFile);

        Storage::disk('local')->put('scanner/status.json', json_encode([
            'scan_id'  => $data['scan_id'],
            'status'   => 'scanning',
            'filename' => null,
            'message'  => '',
            'updated'  => now()->timestamp,
        ]));

        return response()->json([
            'pending' => true,
            'scan_id' => $data['scan_id'],
        ]);
    }

    // Agente sube el PDF escaneado
    public function receive(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $request->validate([
            'file'    => 'required|file|mimes:pdf|max:102400',
            'scan_id' => 'required|string|uuid',
        ]);

        $scanId = $request->input('scan_id');

        // FIX #5: Verifica que el scan_id coincide con el trabajo activo
        $statusFile = 'scanner/status.json';
        if (Storage::disk('local')->exists($statusFile)) {
            $current = json_decode(Storage::disk('local')->get($statusFile), true);
            if (($current['scan_id'] ?? '') !== $scanId) {
                Log::warning('receive: scan_id no coincide. Esperado: ' .
                    ($current['scan_id'] ?? 'ninguno') . ' | Recibido: ' . $scanId);
                return response()->json(['error' => 'scan_id no coincide con el trabajo activo'], 409);
            }
        }

        // FIX #6: Nombre del archivo vinculado al scan_id para evitar colisiones
        $safeId   = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
        $filename = 'scan_' . $safeId . '.pdf';
        $path     = 'scanner/incoming/' . $filename;

        Storage::disk('local')->makeDirectory('scanner/incoming');

        // FIX #7: Verifica que el archivo se guardó correctamente antes de marcar ready
        try {
            Storage::disk('local')->put(
                $path,
                file_get_contents($request->file('file')->getRealPath())
            );

            $savedPath = Storage::disk('local')->path($path);
            if (!file_exists($savedPath) || filesize($savedPath) === 0) {
                throw new \RuntimeException('El archivo quedó vacío o no se pudo guardar');
            }
        } catch (\Throwable $e) {
            Log::error('Error guardando PDF: ' . $e->getMessage());
            Storage::disk('local')->put('scanner/status.json', json_encode([
                'scan_id'  => $scanId,
                'status'   => 'error',
                'filename' => null,
                'message'  => 'Error al guardar el archivo en el servidor',
                'updated'  => now()->timestamp,
            ]));
            return response()->json(['error' => 'No se pudo guardar el archivo'], 500);
        }

        // Actualiza estado a ready con el filename correcto
        Storage::disk('local')->put('scanner/status.json', json_encode([
            'scan_id'  => $scanId,
            'status'   => 'ready',
            'filename' => $filename,
            'updated'  => now()->timestamp,
        ]));

        Log::info('PDF recibido del agente: ' . $filename . ' (' .
            filesize(Storage::disk('local')->path($path)) . ' bytes)');

        return response()->json(['success' => true]);
    }

    // Agente actualiza el estado
    public function updateStatus(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $statusFile = 'scanner/status.json';

        if (Storage::disk('local')->exists($statusFile)) {
            $current = json_decode(Storage::disk('local')->get($statusFile), true);

            $current['status']  = $request->input('status');
            $current['message'] = $request->input('message', '');
            $current['updated'] = now()->timestamp;

            // FIX #8: Solo sobreescribe filename si viene explícitamente en el request
            // Evita que un update de estado borre el filename que puso receive()
            if ($request->has('filename')) {
                $current['filename'] = $request->input('filename');
            }

            Storage::disk('local')->put($statusFile, json_encode($current));
        }

        return response()->json(['ok' => true]);
    }

    // Agente avisa que está vivo
    public function agentPing(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        cache()->put('scanner_agent_online', true, now()->addSeconds(30));

        return response()->json(['ok' => true]);
    }
}
