<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SessionIdle
{
    private static function retentionSeconds(): int
    {
        // Conservar el registro después del límite para detectar la sesión vencida.
        return (int) config('session_idle.timeout_seconds') + (int) config('session.lifetime') * 60;
    }

    public static function key(Request $request): string
    {
        return 'session-idle:'.hash('sha256', $request->session()->getId());
    }

    public static function touch(Request $request): void
    {
        Cache::store('file')->put(self::key($request), time(), self::retentionSeconds());
        $request->attributes->remove('session_idle_status');
    }

    public static function status(Request $request): array
    {
        // Reutilizar solamente dentro de esta petición, nunca entre peticiones.
        if ($request->attributes->has('session_idle_status')) {
            return $request->attributes->get('session_idle_status');
        }

        $store = Cache::store('file');
        $key = self::key($request);
        $lastActivity = $store->get($key);
        if ($lastActivity === null) {
            // Inicializar solo si falta, conservando la creación concurrente de otra pestaña.
            $store->add($key, time(), self::retentionSeconds());
            $lastActivity = $store->get($key, time());
        }
        $lastActivity = (int) $lastActivity;

        $status = [
            'session_key' => $key,
            'last_activity' => $lastActivity,
            'expires_at' => $lastActivity + (int) config('session_idle.timeout_seconds'),
            'server_time' => time(),
            'warning_seconds' => (int) config('session_idle.warning_seconds'),
        ];
        $request->attributes->set('session_idle_status', $status);

        return $status;
    }
}
