<?php

namespace App\Http\Controllers;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Services\AuthenticatorCodeService;
use App\Services\UserActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticatorCodeService $authenticatorCodes,
        private readonly UserActivityLogger $activityLogger
    ) {
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Correo invalido.',
            'password.required' => 'La contrasena es obligatoria.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario se encuentra inactivo.'], 403);
        }

        if ($this->temporaryPasswordExpired($user)) {
            return response()->json([
                'message' => 'La contrasena temporal ha expirado. Solicite un nuevo restablecimiento.',
            ], 401);
        }

        if ($user->must_change_password) {
            return response()->json([
                'message' => 'Debe cambiar la contrasena temporal antes de continuar.',
                'must_change_password' => true,
                'email' => $user->email,
            ]);
        }

        return $this->startLoginChallenge($request, $user);
    }

    public function completeTemporaryPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'current_password' => ['required'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.required' => 'El correo es obligatorio.',
            'current_password.required' => 'La contrasena temporal es obligatoria.',
            'password.required' => 'La nueva contrasena es obligatoria.',
            'password.min' => 'La nueva contrasena debe tener al menos :min caracteres.',
            'password.confirmed' => 'La confirmacion de la contrasena no coincide.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contrasena temporal no es valida.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario se encuentra inactivo.'], 403);
        }

        if ($this->temporaryPasswordExpired($user)) {
            return response()->json([
                'message' => 'La contrasena temporal ha expirado. Solicite un nuevo restablecimiento.',
            ], 401);
        }

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
            'password_changed_at' => Carbon::now(),
        ]);

        $this->activityLogger->log(
            $user,
            null,
            'temporary_password_changed',
            'Contrasena temporal cambiada',
            'El usuario actualizo la contrasena temporal durante el inicio de sesion.',
            $request
        );

        return $this->startLoginChallenge($request, $user->fresh());
    }

    public function verify2FA(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !$user->is_active) {
            return response()->json(['message' => 'El codigo es incorrecto o ha expirado.'], 401);
        }

        if (($user->two_factor_method ?: 'email') === 'authenticator') {
            if ($this->authenticatorCodes->verify($user->two_factor_secret, $request->code)) {
                return $this->finishLogin($request, $user);
            }

            return response()->json(['message' => 'El codigo es incorrecto o ha expirado.'], 401);
        }

        if ($user->two_factor_code &&
            Hash::check($request->code, $user->two_factor_code) &&
            $user->two_factor_expires_at &&
            Carbon::now()->lt($user->two_factor_expires_at)
        ) {
            $user->two_factor_code = null;
            $user->two_factor_expires_at = null;
            $user->save();

            return $this->finishLogin($request, $user);
        }

        return response()->json(['message' => 'El codigo es incorrecto o ha expirado.'], 401);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function startLoginChallenge(Request $request, User $user)
    {
        $method = $user->two_factor_method ?: 'email';

        if ($method === 'disabled') {
            return $this->finishLogin($request, $user);
        }

        if ($method === 'authenticator' && $user->two_factor_secret && $user->two_factor_confirmed_at) {
            return response()->json([
                'message' => 'Ingrese el codigo de su aplicacion autenticadora.',
                'require_2fa' => true,
                'two_factor_method' => 'authenticator',
                'email' => $user->email,
            ]);
        }

        $code = rand(100000, 999999);

        try {
            Mail::to($user->email)->send(new TwoFactorCodeMail($code));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error enviando correo: ' . $e->getMessage()], 500);
        }

        $user->two_factor_code = Hash::make($code);
        $user->two_factor_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        return response()->json([
            'message' => 'Codigo enviado',
            'require_2fa' => true,
            'two_factor_method' => 'email',
            'email' => $user->email,
        ]);
    }

    private function finishLogin(Request $request, User $user)
    {
        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => Carbon::now(),
        ])->save();

        $this->activityLogger->log(
            $user,
            $user,
            'login',
            'Inicio de sesion',
            'Acceso correcto al sistema.',
            $request
        );

        return response()->json(['redirect' => '/dashboard']);
    }

    private function temporaryPasswordExpired(User $user): bool
    {
        return $user->temporary_password_expires_at
            && Carbon::now()->greaterThan($user->temporary_password_expires_at);
    }
}
