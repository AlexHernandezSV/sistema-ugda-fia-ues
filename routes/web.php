<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request; // <--- IMPORTANTE: Necesario para obtener el usuario actual
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentarySeriesController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequestCatalogController;
use App\Http\Controllers\TransferController;
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
            'profiles.roles' => function ($query) {
                $query->whereNull('profile_role.deleted_at')
                    ->where('roles.is_active', true);
            },
        ]);

        $activeProfile = $user->activeProfile();
        $activeUnit = $user->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $user->units->first();
        $permissions = $user->permissionNames();

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
            'profile' => $activeProfile ? [
                'id' => $activeProfile->id,
                'name' => $activeProfile->name,
            ] : null,
            'profiles' => $user->profiles->map(fn ($profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_active' => (bool) ($profile->pivot->is_active ?? false),
            ])->values(),
            'permissions' => $permissions,
            'is_active' => (bool) $user->is_active,
            'unit' => $activeUnit,
            'units' => $user->units->values(),
        ]);
    })->middleware('permission:endpoint.api.user.read');

    Route::get('/api/profiles', [ProfileController::class, 'index'])
        ->middleware('permission:endpoint.api.profiles.read');

    Route::get('/api/units', [UnitController::class, 'index'])
        ->middleware('permission:endpoint.api.units.read');
    Route::post('/api/units', [UnitController::class, 'store'])
        ->middleware('permission:endpoint.api.units.create');
    Route::put('/api/units/{id}', [UnitController::class, 'update'])
        ->middleware('permission:endpoint.api.units.update');
    Route::delete('/api/units/{id}', [UnitController::class, 'destroy'])
        ->middleware('permission:endpoint.api.units.delete');

    Route::get('/api/users', [UserController::class, 'index'])
        ->middleware('permission:endpoint.api.users.read');
    Route::post('/api/users', [UserController::class, 'store'])
        ->middleware('permission:endpoint.api.users.create');
    Route::put('/api/users/{id}', [UserController::class, 'update'])
        ->middleware('permission:endpoint.api.users.update');
    Route::delete('/api/users/{id}', [UserController::class, 'destroy'])
        ->middleware('permission:endpoint.api.users.delete');

    Route::get('/api/documentary-series', [DocumentarySeriesController::class, 'index'])
        ->middleware('permission:endpoint.api.documentary-series.read');
    Route::post('/api/documentary-series', [DocumentarySeriesController::class, 'store'])
        ->middleware('permission:endpoint.api.documentary-series.create');
    Route::put('/api/documentary-series/{id}', [DocumentarySeriesController::class, 'update'])
        ->middleware('permission:endpoint.api.documentary-series.update');
    Route::delete('/api/documentary-series/{id}', [DocumentarySeriesController::class, 'destroy'])
        ->middleware('permission:endpoint.api.documentary-series.delete');
    Route::post('/api/documentary-series/{seriesId}/subseries', [DocumentarySeriesController::class, 'storeSubseries'])
        ->middleware('permission:endpoint.api.documentary-series.create');
    Route::put('/api/documentary-subseries/{id}', [DocumentarySeriesController::class, 'updateSubseries'])
        ->middleware('permission:endpoint.api.documentary-series.update');
    Route::delete('/api/documentary-subseries/{id}', [DocumentarySeriesController::class, 'destroySubseries'])
        ->middleware('permission:endpoint.api.documentary-series.delete');

    Route::get('/api/request-catalog', [RequestCatalogController::class, 'index']);
    Route::get('/api/dashboard-summary', [RequestCatalogController::class, 'dashboard']);
    Route::get('/api/transfers/next-code', [TransferController::class, 'nextCode']);
    Route::get('/api/transfers/document-import-template', [TransferController::class, 'documentImportTemplate']);
    Route::post('/api/transfers/import-documents', [TransferController::class, 'importDocuments']);
    Route::post('/api/transfers', [TransferController::class, 'store']);
    Route::get('/api/transfers/{number}', [RequestCatalogController::class, 'showTransfer']);
    Route::get('/api/transfers/{number}/edit-data', [TransferController::class, 'editData']);
    Route::put('/api/transfers/{number}/resubmit-correction', [TransferController::class, 'resubmitCorrection']);
    Route::get('/api/transfers/{number}/documents/{code}/download', [RequestCatalogController::class, 'downloadTransferDocument']);
    Route::post('/api/transfers/{number}/unit-authorize', [TransferController::class, 'authorizeByUnit']);
    Route::post('/api/transfers/{number}/unit-deny', [TransferController::class, 'denyByUnit']);
    Route::post('/api/transfers/{number}/schedule', [TransferController::class, 'schedule']);
    Route::post('/api/transfers/{number}/complete-locations', [TransferController::class, 'completeLocations']);
    Route::post('/api/transfers/{number}/observe', [TransferController::class, 'observe']);
    Route::post('/api/transfers/{number}/deny', [TransferController::class, 'deny']);
    Route::get('/api/loans/{number}', [RequestCatalogController::class, 'showLoan']);
    Route::post('/api/loans/{number}/authorize', [LoanController::class, 'authorizeLoan']);
    Route::post('/api/loans/{number}/observe', [LoanController::class, 'observe']);
    Route::post('/api/loans/{number}/deny', [LoanController::class, 'deny']);
    Route::post('/api/loans/{number}/search/start', [LoanController::class, 'startSearch']);
    Route::post('/api/loans/{number}/search/finish', [LoanController::class, 'finishSearch']);
    Route::post('/api/loans/{number}/register', [LoanController::class, 'registerLoan']);
    Route::post('/api/loans/{number}/return', [LoanController::class, 'registerReturn']);
});

// ------------------------------------------------------------------------
// 3. RUTA COMODÍN (Catch-all) PARA VUE ROUTER
// IMPORTANTE: Esta ruta DEBE ir siempre al FINAL del archivo.
// ------------------------------------------------------------------------

Route::get('/{any}', function () {
    return view('welcome'); 
})->where('any', '.*');
