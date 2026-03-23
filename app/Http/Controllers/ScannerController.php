<?php

namespace App\Http\Controllers;

//use Illuminate\Container\Attributes\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScannerController extends Controller
{
       // ─── Helper de rutas ──────────────────────────────────────────────────────
    private function scannerPath(string $append = ''): string
    {
        $base = storage_path('app' . DIRECTORY_SEPARATOR . 'scanner');
        return $append ? $base . DIRECTORY_SEPARATOR . $append : $base;
    }
    public function index()
    {
        return view("scanner");
    }

    public function scan()
{
    Storage::disk('local')->makeDirectory('scanner/incoming');
    Storage::disk('local')->makeDirectory('scanner/processed');

    $incomingPath = $this->scannerPath('incoming');
    $scriptPath   = $this->scannerPath('scan.ps1');
    $resultFile   = $this->scannerPath('result.txt');

    if (!file_exists($scriptPath)) {
        return response()->json([
            'success' => false,
            'message' => 'No se encontró scan.ps1 en: ' . $scriptPath,
        ], 500);
    }

    if (file_exists($resultFile)) {
        unlink($resultFile);
    }

    $command = 'powershell -ExecutionPolicy Bypass -File "'
             . $scriptPath . '" -OutputPath "'
             . $incomingPath . '" -ResultFile "'
             . $resultFile . '"';

    exec($command, $output, $exitCode);

    if (!file_exists($resultFile)) {
        return response()->json([
            'success' => false,
            'message' => 'El script no generó resultado.',
        ], 500);
    }

    $result = trim(file_get_contents($resultFile));
    unlink($resultFile);

    // ✅ Elimina BOM si existe
    $result = str_replace("\xEF\xBB\xBF", '', $result);

    \Log::info('Scanner result: ' . $result);

    if (!str_starts_with($result, 'OK:')) {
        return response()->json([
            'success' => false,
            'message' => $result,
        ], 500);
    }

    $filename = basename(str_replace('OK:', '', $result));

    return response()->json([
        'success'  => true,
        'filename' => $filename,
    ]);
}
    public function download(Request $request)
    {
        $filename = basename($request->query('filename'));

        // Ruta absoluta directa, sin doble concatenación
        $fullPath = storage_path('app' . DIRECTORY_SEPARATOR . 'scanner' . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR . $filename);

        \Log::info('Descargando archivo: ' . $fullPath);

        if (!file_exists($fullPath)) {
            return response()->json([
                'error' => 'Archivo no encontrado: ' . $fullPath
            ], 404);
        }

        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function confirm(Request $request)
    {
        $filename = basename($request->input('filename'));
        $from = 'scanner/incoming/' . $filename;
        $to = 'scanner/processed/' . $filename;

        if (Storage::disk('local')->exists($from)) {
            Storage::disk('local')->move($from, $to);
        }

        return response()->json(['ok' => true]);
    }
}
