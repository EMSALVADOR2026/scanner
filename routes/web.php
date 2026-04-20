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