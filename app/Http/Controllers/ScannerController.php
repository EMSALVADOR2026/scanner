<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScannerController extends Controller
{
    private function scannerPath(string $append = ''): string
    {
        $base = storage_path('app' . DIRECTORY_SEPARATOR . 'scanner');
        return $append ? $base . DIRECTORY_SEPARATOR . $append : $base;
    }

    private function validateToken(Request $request): bool
    {
        return $request->header('X-Scanner-Token') === config('scanner.token');
    }

    public function index()
    {
        return view('scanner');
    }

    public function scan(Request $request)
    {
        Storage::disk('local')->makeDirectory('scanner/incoming');
        Storage::disk('local')->makeDirectory('scanner/processed');

        $lockFile = $this->scannerPath('scanner.lock');

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

        if (in_array($status, ['ready', 'completed', 'error'])) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            if ($status === 'completed') {
                $status = 'ready';
            }
        }

        return response()->json([
            'status'   => $status,
            'filename' => $data['filename'] ?? null,
            'message'  => $data['message'] ?? null,
        ]);
    }

    public function download(Request $request)
    {
        $scanId     = $request->query('scan_id');
        $statusFile = 'scanner/status.json';

        if (!$scanId) {
            return response()->json(['error' => 'scan_id requerido'], 400);
        }

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

        $safeName = basename($filename);

        $incomingPath  = 'scanner/incoming/' . $safeName;
        $processedPath = 'scanner/processed/' . $safeName;

        if (Storage::disk('local')->exists($incomingPath)) {
            $fullPath = Storage::disk('local')->path($incomingPath);
        } elseif (Storage::disk('local')->exists($processedPath)) {
            $fullPath = Storage::disk('local')->path($processedPath);
        } else {
            Log::error('Archivo no encontrado en incoming ni processed: ' . $safeName);
            return response()->json(['error' => 'Archivo no encontrado: ' . $safeName], 404);
        }

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeName . '"',
        ]);
    }

    public function confirm(Request $request)
    {
        $scanId     = $request->input('scan_id');
        $statusFile = 'scanner/status.json';

        if (!$scanId) {
            return response()->json(['ok' => false, 'error' => 'scan_id requerido'], 400);
        }

        $filename = null;
        if (Storage::disk('local')->exists($statusFile)) {
            $data     = json_decode(Storage::disk('local')->get($statusFile), true);
            $filename = $data['filename'] ?? null;
        }

        if ($filename) {
            $from = 'scanner/incoming/' . basename($filename);
            $to   = 'scanner/processed/' . basename($filename);

            if (Storage::disk('local')->exists($from)) {
                Storage::disk('local')->move($from, $to);
            }
        }

        $lockFile = $this->scannerPath('scanner.lock');
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        Storage::disk('local')->delete($statusFile);

        return response()->json(['ok' => true]);
    }

    public function downloadInstaller()
    {
        $serverUrl = config('app.url');
        $token     = config('scanner.token');

        $zipDir  = storage_path('app/scanner');
        $zipPath = $zipDir . DIRECTORY_SEPARATOR . 'ScannerAgente.zip';
        $ps1Path = $zipDir . DIRECTORY_SEPARATOR . 'scan.ps1';

        if (!file_exists($ps1Path)) {
            return response()->json(['error' => 'scan.ps1 no encontrado'], 404);
        }

        if (!is_dir($zipDir)) {
            mkdir($zipDir, 0777, true);
        }

        $serverUrlEsc = str_replace('"', '""', $serverUrl);
        $tokenEsc     = str_replace('"', '""', $token);

        $batContent =
            "@echo off\r\n" .
            "set INSTALL_DIR=%USERPROFILE%\\ScannerAgente\r\n" .
            "set STARTUP_DIR=%APPDATA%\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\r\n" .
            "if not exist \"%INSTALL_DIR%\" mkdir \"%INSTALL_DIR%\"\r\n" .
            "if not exist \"%INSTALL_DIR%\\processed\" mkdir \"%INSTALL_DIR%\\processed\"\r\n" .
            "copy \"%~dp0scan.ps1\" \"%INSTALL_DIR%\\scan.ps1\" /Y\r\n" .
            "\r\n" .
            ":: Crea el .env con las credenciales\r\n" .
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
            "echo [OK] Agente instalado\r\n" .
            "echo [OK] Servidor: {$serverUrl}\r\n" .
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

        $statusFile = 'scanner/status.json';
        if (Storage::disk('local')->exists($statusFile)) {
            $current = json_decode(Storage::disk('local')->get($statusFile), true);
            if (($current['scan_id'] ?? '') !== $scanId) {
                Log::warning('receive: scan_id no coincide. Esperado: ' .
                    ($current['scan_id'] ?? 'ninguno') . ' | Recibido: ' . $scanId);
                return response()->json(['error' => 'scan_id no coincide con el trabajo activo'], 409);
            }
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

            if ($request->has('filename')) {
                $current['filename'] = $request->input('filename');
            }

            Storage::disk('local')->put($statusFile, json_encode($current));
        }

        return response()->json(['ok' => true]);
    }

    public function agentPing(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        cache()->put('scanner_agent_online', true, now()->addSeconds(30));

        return response()->json(['ok' => true]);
    }
}
