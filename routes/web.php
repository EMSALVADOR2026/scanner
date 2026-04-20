<?php

use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

// ── Navegador ─────────────────────────────────────────────────────────────────
Route::get('/',                           [ScannerController::class, 'index']);
Route::post('/scanner/scan',              [ScannerController::class, 'scan']);
Route::get('/scanner/poll',               [ScannerController::class, 'poll']);
Route::get('/scanner/download',           [ScannerController::class, 'download']);
Route::post('/scanner/confirm',           [ScannerController::class, 'confirm']);
Route::get('/scanner/download-installer', [ScannerController::class, 'downloadInstaller']);

// Endpoint que el navegador usa para ver si el agente está online
Route::get('/scanner/agent-status', function () {
    return response()->json([
        'online' => (bool) cache('scanner:agent_online', false),
    ]);
});

// ── Agente PowerShell (sin CSRF — se protegen con X-Scanner-Token) ────────────
Route::get('/scanner/pending',        [ScannerController::class, 'pending']);
Route::post('/scanner/receive',       [ScannerController::class, 'receive']);
Route::post('/scanner/update-status', [ScannerController::class, 'updateStatus']);
Route::post('/scanner/agent-ping',    [ScannerController::class, 'agentPing']);