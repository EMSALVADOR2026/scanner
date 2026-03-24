<?php

use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

// ── Rutas del navegador ───────────────────────────────────────────────────────
Route::get('/',                           [ScannerController::class, 'index']);
Route::post('/scanner/scan',              [ScannerController::class, 'scan']);
Route::get('/scanner/poll',               [ScannerController::class, 'poll']);
Route::get('/scanner/download',           [ScannerController::class, 'download']);
Route::post('/scanner/confirm',           [ScannerController::class, 'confirm']);
Route::get('/scanner/download-installer', [ScannerController::class, 'downloadInstaller']);

// Ping navegador
Route::get('/scanner/agent-ping', function () {
    $online = cache()->get('scanner_agent_online', false);
    return response()->json(['online' => $online]);
});

// ── Rutas del agente PowerShell (excluidas de CSRF en bootstrap/app.php) ──────
Route::get('/scanner/pending',            [ScannerController::class, 'pending']);
Route::post('/scanner/receive',           [ScannerController::class, 'receive']);
Route::post('/scanner/update-status',     [ScannerController::class, 'updateStatus']);
Route::post('/scanner/agent-ping',        [ScannerController::class, 'agentPing']);

// Ruta de diagnóstico temporal
Route::post('/scanner/receive-debug', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'has_file'  => $request->hasFile('file'),
        'has_scan'  => $request->has('scan_id'),
        'scan_id'   => $request->input('scan_id'),
        'files'     => array_keys($request->allFiles()),
        'all_input' => $request->except('file'),
        'token'     => $request->header('X-Scanner-Token'),
    ]);
});