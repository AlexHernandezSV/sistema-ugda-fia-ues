<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Web Routes (Modo SPA con Vue Router)
|--------------------------------------------------------------------------
*/

// ------------------------------------------------------------------------
// 1. RUTAS DE BACKEND (API / Autenticación)
// Estas rutas SI son procesadas por Laravel y devuelven datos JSON o realizan acciones.
// ------------------------------------------------------------------------

Route::post('/login-auth', [AuthController::class, 'login']);
Route::post('/login-verify', [AuthController::class, 'verify2FA']);
Route::get('/logout', [AuthController::class, 'logout']);


// ------------------------------------------------------------------------
// 2. RUTA COMODÍN (Catch-all) PARA VUE ROUTER
// Esta es la "magia". Le dice a Laravel: "Si te piden CUALQUIER otra ruta 
// (como /login, /dashboard, /usuarios), no des error 404.
// Simplemente carga la vista 'welcome' y deja que Vue Router tome el control".
// ------------------------------------------------------------------------

Route::get('/{any}', function () {
    return view('welcome'); // Asegúrate que tu vista blade se llame 'welcome.blade.php'
})->where('any', '.*');