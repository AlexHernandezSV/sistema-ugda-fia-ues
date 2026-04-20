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
    Route::get('/api/user', function (Request $request) {
        $user = $request->user()->load([
            'units' => function ($query) {
                $query->whereNull('user_unit.deleted_at');
            },
            'person',
            'profiles' => function ($query) {
                $query->whereNull('user_profile.deleted_at')
                    ->where('profiles.is_active', true);
            },
        ]);

        $activeProfile = $user->profiles
            ->first(fn ($profile) => (bool) ($profile->pivot->is_active ?? false))
            ?? $user->profiles->first();
        $activeUnit = $user->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $user->units->first();

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->person?->first_name,
            'second_name' => $user->person?->second_name,
            'first_last_name' => $user->person?->first_last_name,
            'second_last_name' => $user->person?->second_last_name,
            'carnet' => $user->person?->carnet,
            'unit_id' => $activeUnit?->id,
            'unit_ids' => $user->units->pluck('id')->values()->all(),
            'role' => $activeProfile?->name,
            'is_active' => (bool) $user->is_active,
            'unit' => $activeUnit,
            'units' => $user->units->values(),
        ]);
    });

    Route::get('/api/units', [UnitController::class, 'index']);
    Route::post('/api/units', [UnitController::class, 'store']);
    Route::put('/api/units/{id}', [UnitController::class, 'update']);
    Route::delete('/api/units/{id}', [UnitController::class, 'destroy']);

    Route::get('/api/users', [UserController::class, 'index']);
    Route::post('/api/users', [UserController::class, 'store']);
    Route::put('/api/users/{id}', [UserController::class, 'update']);
    Route::delete('/api/users/{id}', [UserController::class, 'destroy']);
});

// ------------------------------------------------------------------------
// 3. RUTA COMODÍN (Catch-all) PARA VUE ROUTER
// IMPORTANTE: Esta ruta DEBE ir siempre al FINAL del archivo.
// ------------------------------------------------------------------------

Route::get('/{any}', function () {
    return view('welcome'); 
})->where('any', '.*');
