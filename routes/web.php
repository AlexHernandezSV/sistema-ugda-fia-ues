<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| Web Routes (Modo SPA con Vue Router)
|--------------------------------------------------------------------------
*/

// 1. Rutas publicas (autenticacion)
Route::post('/login-auth', [AuthController::class, 'login']);
Route::post('/login-verify', [AuthController::class, 'verify2FA']);
Route::get('/logout', [AuthController::class, 'logout']);

// 2. Rutas protegidas (API interna para Vue)
Route::middleware('auth')->group(function () {
    // Obtener usuario logueado (sidebar)
    Route::get('/api/user', function (Request $request) {
        $user = $request->user()->load([
            'unit',
            'person',
            'profiles' => function ($query) {
                $query->whereNull('user_profile.deleted_at')
                    ->where('profile.is_active', true);
            },
        ]);

        $activeProfile = $user->profiles
            ->first(fn ($profile) => (bool) ($profile->pivot->is_active ?? false))
            ?? $user->profiles->first();

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->person?->first_name,
            'second_name' => $user->person?->second_name,
            'first_last_name' => $user->person?->first_last_name,
            'second_last_name' => $user->person?->second_last_name,
            'carnet' => $user->person?->carnet,
            'unit_id' => $user->unit_id,
            'role' => $activeProfile?->name,
            'is_active' => (bool) $user->is_active,
            'unit' => $user->unit,
        ]);
    });

    // CRUD de unidades
    Route::get('/api/units', [UnitController::class, 'index']);
    Route::post('/api/units', [UnitController::class, 'store']);
    Route::put('/api/units/{id}', [UnitController::class, 'update']);
    Route::delete('/api/units/{id}', [UnitController::class, 'destroy']);

    // CRUD de usuarios
    Route::get('/api/users', [UserController::class, 'index']);
    Route::post('/api/users', [UserController::class, 'store']);
    Route::put('/api/users/{id}', [UserController::class, 'update']);
    Route::delete('/api/users/{id}', [UserController::class, 'destroy']);
});

// 3. Ruta comodin para Vue Router
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
