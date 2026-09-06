<?php

namespace App\Http\Controllers;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Services\AuthenticatorCodeService;
use App\Services\UserActivityLogger;
use App\Support\PasswordSecurity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const FIRST_LOCKOUT_ATTEMPTS = 5;
    private const SECOND_LOCKOUT_ATTEMPTS = 8;
    private const FAILURE_COUNTER_TTL_SECONDS = 86400;
    private const FIRST_LOCKOUT_SECONDS = 60;
    private const SECOND_LOCKOUT_SECONDS = 900;
    private const FINAL_LOCKOUT_SECONDS = 3600;

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

        $throttleKey = $this->throttleKey($request, 'login');

        if ($this->tooManyAttempts($throttleKey)) {
            return $this->lockoutResponse($throttleKey);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->hitFailedAttempt($throttleKey);

            if ($this->tooManyAttempts($throttleKey)) {
                return $this->lockoutResponse($throttleKey);
            }

            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        $this->clearFailedAttempts($throttleKey);

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

    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
        ]);

        $email = Str::lower(trim((string) $request->input('email')));

        if (User::where('email', $email)->where('is_active', true)->exists()) {
            try {
                $status = PasswordBroker::broker()->sendResetLink(['email' => $email]);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'No fue posible enviar el enlace. Intenta nuevamente más tarde.',
                ], 500);
            }

            if ($status === PasswordBroker::RESET_THROTTLED) {
                return response()->json([
                    'message' => 'Ya se solicitó un enlace recientemente. Espera un minuto antes de intentarlo de nuevo.',
                    'retry_after' => 60,
                ], 429);
            }
        }

        return response()->json([
            'message' => 'Si el correo corresponde a un usuario activo, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                Password::min(10)->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
        ], [
            'token.required' => 'El enlace de restablecimiento no es válido.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.symbols' => 'La contraseña debe incluir al menos un símbolo.',
        ]);

        $passwordViolations = PasswordSecurity::violations(
            (string) $request->input('password'),
            ''
        );

        if ($passwordViolations !== []) {
            return response()->json([
                'message' => $passwordViolations[0],
                'errors' => ['password' => $passwordViolations],
            ], 422);
        }

        $payload = $this->decodePasswordResetToken((string) $request->input('token'));
        $email = Str::lower(trim((string) ($payload['email'] ?? '')));
        $brokerToken = (string) ($payload['token'] ?? '');

        if ($email === '' || $brokerToken === '') {
            return response()->json([
                'message' => 'El enlace no es válido o ya expiró.',
            ], 422);
        }

        $user = User::where('email', $email)->where('is_active', true)->first();

        if (!$user) {
            return response()->json([
                'message' => 'El enlace no es válido o ya expiró.',
            ], 422);
        }

        if (Hash::check((string) $request->input('password'), (string) $user->password)) {
            return response()->json([
                'message' => 'La nueva contraseña debe ser diferente a la contraseña actual.',
                'errors' => [
                    'password' => ['La nueva contraseña debe ser diferente a la contraseña actual.'],
                ],
            ], 422);
        }

        $status = PasswordBroker::broker()->reset(
            [
                'email' => $email,
                'password' => $request->input('password'),
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $brokerToken,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'temporary_password_expires_at' => null,
                    'password_changed_at' => Carbon::now(),
                ])->save();
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json([
                'message' => 'El enlace no es válido o ya expiró.',
            ], 422);
        }

        $this->activityLogger->log(
            $user,
            null,
            'password_reset',
            'Restablecimiento de contraseña',
            'El usuario restableció su contraseña mediante el enlace de recuperación.',
            $request
        );

        return response()->json([
            'message' => 'Tu contraseña fue restablecida correctamente. Ya puedes iniciar sesión.',
        ]);
    }

    public function validateResetToken(string $token)
    {
        $payload = $this->decodePasswordResetToken($token);
        $email = Str::lower(trim((string) ($payload['email'] ?? '')));
        $brokerToken = (string) ($payload['token'] ?? '');

        if ($email === '' || $brokerToken === '') {
            return response()->json([
                'message' => 'El enlace no es válido o ya expiró.',
            ], 422);
        }

        $user = User::where('email', $email)->where('is_active', true)->first();

        if (!$user || !PasswordBroker::broker()->tokenExists($user, $brokerToken)) {
            return response()->json([
                'message' => 'El enlace no es válido o ya expiró.',
            ], 422);
        }

        return response()->json([
            'message' => 'El enlace de restablecimiento es válido.',
        ]);
    }

    private function decodePasswordResetToken(string $encodedToken): ?array
    {
        $encodedToken = strtr($encodedToken, '-_', '+/');
        $padding = strlen($encodedToken) % 4;

        if ($padding > 0) {
            $encodedToken .= str_repeat('=', 4 - $padding);
        }

        $encryptedPayload = base64_decode($encodedToken, true);

        if ($encryptedPayload === false) {
            return null;
        }

        try {
            $payload = json_decode(
                Crypt::decryptString($encryptedPayload),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    public function completeTemporaryPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'current_password' => ['required'],
            'password' => [
                'required',
                'string',
                Password::min(10)->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
        ], [
            'email.required' => 'El correo es obligatorio.',
            'current_password.required' => 'La contrasena temporal es obligatoria.',
            'password.required' => 'La nueva contrasena es obligatoria.',
            'password.confirmed' => 'La confirmacion de la contrasena no coincide.',
        ]);

        $throttleKey = $this->throttleKey($request, 'temporary-password');

        if ($this->tooManyAttempts($throttleKey)) {
            return $this->lockoutResponse($throttleKey);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->current_password, $user->password)) {
            $this->hitFailedAttempt($throttleKey);

            if ($this->tooManyAttempts($throttleKey)) {
                return $this->lockoutResponse($throttleKey);
            }

            return response()->json(['message' => 'La contrasena temporal no es válida.'], 401);
        }

        $this->clearFailedAttempts($throttleKey);

        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario se encuentra inactivo.'], 403);
        }

        if ($this->temporaryPasswordExpired($user)) {
            return response()->json([
                'message' => 'La contrasena temporal ha expirado. Solicite un nuevo restablecimiento.',
            ], 401);
        }

        if (Hash::check((string) $request->input('password'), (string) $user->password)) {
            return response()->json([
                'message' => 'La nueva contraseña debe ser diferente a la contraseña temporal actual.',
                'errors' => [
                    'password' => ['La nueva contraseña debe ser diferente a la contraseña temporal actual.'],
                ],
            ], 422);
        }

        $passwordViolations = PasswordSecurity::violations(
            (string) $request->input('password'),
            ''
        );

        if ($passwordViolations !== []) {
            return response()->json([
                'message' => $passwordViolations[0],
                'errors' => ['password' => $passwordViolations],
            ], 422);
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
            'Contraseña temporal cambiada',
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

        $throttleKey = $this->throttleKey($request, 'two-factor');

        if ($this->tooManyAttempts($throttleKey)) {
            return $this->lockoutResponse($throttleKey);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !$user->is_active) {
            $this->hitFailedAttempt($throttleKey);

            if ($this->tooManyAttempts($throttleKey)) {
                return $this->lockoutResponse($throttleKey);
            }

            return response()->json(['message' => 'El código es incorrecto o ha expirado.'], 401);
        }

        if (($user->two_factor_method ?: 'email') === 'authenticator') {
            if ($this->authenticatorCodes->verify($user->two_factor_secret, $request->code)) {
                $this->clearFailedAttempts($throttleKey);

                return $this->finishLogin($request, $user);
            }

            $this->hitFailedAttempt($throttleKey);

            if ($this->tooManyAttempts($throttleKey)) {
                return $this->lockoutResponse($throttleKey);
            }

            return response()->json(['message' => 'El código es incorrecto o ha expirado.'], 401);
        }

        if ($user->two_factor_code &&
            Hash::check($request->code, $user->two_factor_code) &&
            $user->two_factor_expires_at &&
            Carbon::now()->lt($user->two_factor_expires_at)
        ) {
            $user->two_factor_code = null;
            $user->two_factor_expires_at = null;
            $user->save();

            $this->clearFailedAttempts($throttleKey);

            return $this->finishLogin($request, $user);
        }

        $this->hitFailedAttempt($throttleKey);

        if ($this->tooManyAttempts($throttleKey)) {
            return $this->lockoutResponse($throttleKey);
        }

        return response()->json(['message' => 'El código es incorrecto o ha expirado.'], 401);
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
                'message' => 'Ingrese el código de su aplicación autenticadora.',
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
            'message' => 'Código enviado',
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

    private function throttleKey(Request $request, string $action): string
    {
        $email = Str::lower((string) $request->input('email', ''));

        return 'auth:' . $action . ':' . sha1($email . '|' . $request->ip());
    }

    private function hitFailedAttempt(string $throttleKey): void
    {
        Cache::add($this->attemptsKey($throttleKey), 0, self::FAILURE_COUNTER_TTL_SECONDS);

        $attempts = Cache::increment($this->attemptsKey($throttleKey));
        $seconds = $this->lockoutSecondsForAttempts($attempts);

        if ($seconds > 0) {
            Cache::put($this->lockoutKey($throttleKey), Carbon::now()->addSeconds($seconds)->timestamp, $seconds);
        }
    }

    private function tooManyAttempts(string $throttleKey): bool
    {
        return $this->lockoutSecondsRemaining($throttleKey) > 0;
    }

    private function lockoutResponse(string $throttleKey)
    {
        $seconds = $this->lockoutSecondsRemaining($throttleKey);
        $wait = $this->formatLockoutWait($seconds);

        return response()->json([
            'message' => "Demasiados intentos fallidos. Intente nuevamente en {$wait}.",
            'retry_after' => $seconds,
        ], 429);
    }

    private function clearFailedAttempts(string $throttleKey): void
    {
        Cache::forget($this->attemptsKey($throttleKey));
        Cache::forget($this->lockoutKey($throttleKey));
    }

    private function lockoutSecondsForAttempts(int $attempts): int
    {
        if ($attempts < self::FIRST_LOCKOUT_ATTEMPTS) {
            return 0;
        }

        if ($attempts === self::FIRST_LOCKOUT_ATTEMPTS) {
            return self::FIRST_LOCKOUT_SECONDS;
        }

        if ($attempts <= self::SECOND_LOCKOUT_ATTEMPTS) {
            return self::SECOND_LOCKOUT_SECONDS;
        }

        return self::FINAL_LOCKOUT_SECONDS;
    }

    private function lockoutSecondsRemaining(string $throttleKey): int
    {
        $lockedUntil = (int) Cache::get($this->lockoutKey($throttleKey), 0);

        return max(0, $lockedUntil - Carbon::now()->timestamp);
    }

    private function formatLockoutWait(int $seconds): string
    {
        if ($seconds >= self::FINAL_LOCKOUT_SECONDS) {
            return '1 hora';
        }

        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes === 1 ? '1 minuto' : "{$minutes} minutos";
    }

    private function attemptsKey(string $throttleKey): string
    {
        return $throttleKey . ':attempts';
    }

    private function lockoutKey(string $throttleKey): string
    {
        return $throttleKey . ':locked-until';
    }
}
