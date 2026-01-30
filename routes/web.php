<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UnitController; // <--- 1. IMPORTANTE: Importar el controlador nuevo

/*
|--------------------------------------------------------------------------
| Web Routes (Modo SPA con Vue Router)
|--------------------------------------------------------------------------
*/

// ------------------------------------------------------------------------
// 1. RUTAS PÚBLICAS (Autenticación)
// ------------------------------------------------------------------------

Route::post('/login-auth', [AuthController::class, 'login']);
Route::post('/login-verify', [AuthController::class, 'verify2FA']);
Route::get('/logout', [AuthController::class, 'logout']);

// ------------------------------------------------------------------------
// 2. RUTAS PROTEGIDAS (API Interna para Vue)
// Estas rutas solo funcionan si el usuario ha iniciado sesión.
// Devuelven JSON para que Axios los consuma.
// ------------------------------------------------------------------------

Route::middleware('auth')->group(function () {
    
    // CRUD DE UNIDADES ACADÉMICAS
    Route::get('/api/units', [UnitController::class, 'index']);
    Route::post('/api/units', [UnitController::class, 'store']);
    Route::put('/api/units/{id}', [UnitController::class, 'update']);
    Route::delete('/api/units/{id}', [UnitController::class, 'destroy']);

});

// ------------------------------------------------------------------------
// 3. RUTA COMODÍN (Catch-all) PARA VUE ROUTER
// IMPORTANTE: Esta ruta DEBE ir siempre al FINAL del archivo.
// ------------------------------------------------------------------------

Route::get('/{any}', function () {
    return view('welcome'); 
})->where('any', '.*');