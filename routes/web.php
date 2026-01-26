<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AuthController;

// Ruta del Login (Vista)
Route::get('/login', [HomeController::class, 'showLogin'])->name('login');

// Ruta para PROCESAR el Login (Recibe los datos de Vue)
Route::post('/login-auth', [AuthController::class, 'login']);

Route::post('/login-verify', [AuthController::class, 'verify2FA']);

// Ruta del Dashboard (Protegida: Solo usuarios logueados pueden verla)
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return "<h1>¡Bienvenido al Panel de Control de la UGDA!</h1><a href='/logout'>Salir</a>";
    })->name('dashboard');
    
    Route::get('/logout', [AuthController::class, 'logout']);
});