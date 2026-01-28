<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use App\Mail\TwoFactorCodeMail;
use Carbon\Carbon;

class AuthController extends Controller
{
    // PASO 1: Validar credenciales y enviar código SEGURO
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Correo inválido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        // Verificar usuario y contraseña sin loguear
        $user = User::where('email', $request->email)->first();

        // Si el usuario no existe o la contraseña está mal
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        // Generar código numérico
        $code = rand(100000, 999999);

        // 1. ENVIAR EL CÓDIGO (Texto plano al correo)
        try {
            Mail::to($user->email)->send(new TwoFactorCodeMail($code));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error enviando correo: ' . $e->getMessage()], 500);
        }

        // 2. GUARDAR ENCRIPTADO (Hash en la BD)
        $user->two_factor_code = Hash::make($code);
        $user->two_factor_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        return response()->json([
            'message' => 'Código enviado',
            'require_2fa' => true,
            'email' => $user->email
        ]);
    }

    // PASO 2: Verificar código HASHEADO
    public function verify2FA(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'code' => 'required',
        ]);

        // Buscar usuario solo por email
        $user = User::where('email', $request->email)->first();

        // Validar lógica de seguridad
        if ($user && 
            $user->two_factor_code && 
            Hash::check($request->code, $user->two_factor_code) &&
            Carbon::now()->lt($user->two_factor_expires_at)) 
        {
            // Limpiar código por seguridad
            $user->two_factor_code = null;
            $user->two_factor_expires_at = null;
            $user->save();

            // Iniciar sesión real
            Auth::login($user);
            $request->session()->regenerate();

            // --- CORRECCIÓN CLAVE ---
            // Cambiamos route('dashboard') por '/dashboard' en texto plano.
            // Esto evita el error si la ruta no tiene nombre en web.php
            return response()->json(['redirect' => '/dashboard']);
        }

        return response()->json(['message' => 'El código es incorrecto o ha expirado.'], 401);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Redirigir al login
        return redirect('/login');
    }
}