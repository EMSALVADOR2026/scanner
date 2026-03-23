<?php

use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/',                [ScannerController::class, 'index']);
Route::post('/scanner/scan',   [ScannerController::class, 'scan']);
Route::get('/scanner/download',[ScannerController::class, 'download']);
Route::post('/scanner/confirm',[ScannerController::class, 'confirm']);