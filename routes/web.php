<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request; // <--- IMPORTANTE: Necesario para obtener el usuario actual
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;

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
// ------------------------------------------------------------------------

Route::middleware('auth')->group(function () {
    
    // --- OBTENER USUARIO LOGUEADO (PARA EL SIDEBAR) ---
    Route::get('/api/user', function (Request $request) {
        // Devolvemos el usuario y cargamos la relación 'unit' para saber su unidad
        return $request->user()->load('unit');
    });

    // --- CRUD DE UNIDADES ACADÉMICAS ---
    Route::get('/api/units', [UnitController::class, 'index']);
    Route::post('/api/units', [UnitController::class, 'store']);
    Route::put('/api/units/{id}', [UnitController::class, 'update']);
    Route::delete('/api/units/{id}', [UnitController::class, 'destroy']);

    // --- CRUD DE USUARIOS ---
    Route::get('/api/users', [UserController::class, 'index']); // Listar
    Route::post('/api/users', [UserController::class, 'store']); // Crear
    Route::put('/api/users/{id}', [UserController::class, 'update']); // Editar

});

// ------------------------------------------------------------------------
// 3. RUTA COMODÍN (Catch-all) PARA VUE ROUTER
// IMPORTANTE: Esta ruta DEBE ir siempre al FINAL del archivo.
// ------------------------------------------------------------------------

Route::get('/{any}', function () {
    return view('welcome'); 
})->where('any', '.*');