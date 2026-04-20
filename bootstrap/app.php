<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Excluye las rutas del agente PowerShell de la verificación CSRF.
         * El agente no tiene sesión de navegador — se autentica con X-Scanner-Token.
         * Estas rutas siguen protegidas: solo responden si el token es válido.
         */
        $middleware->validateCsrfTokens(except: [
            'scanner/receive',
            'scanner/update-status',
            'scanner/agent-ping',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();