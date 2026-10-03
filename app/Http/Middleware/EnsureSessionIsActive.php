<?php

namespace App\Http\Middleware;

use App\Support\SessionIdle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureSessionIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            $status = SessionIdle::status($request);
            if ($status['server_time'] >= $status['expires_at']) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->put('expired_by_inactivity', true);

                if ($request->expectsJson()) {
                    return $this->expiredResponse();
                }

                if (!$request->is('login')) {
                    return redirect('/login?reason=inactivity');
                }
            }
        }

        if (!$request->user() && $request->session()->get('expired_by_inactivity')) {
            if ($request->is('api/*')) {
                return $this->expiredResponse();
            }
            if ($request->is('login') && $request->isMethod('GET') && $request->query('reason') !== 'inactivity') {
                return redirect('/login?reason=inactivity');
            }
        }

        return $next($request);
    }

    private function expiredResponse()
    {
        return response()->json([
            'message' => 'Tu sesión expiró por inactividad.',
            'code' => 'SESSION_IDLE_EXPIRED',
        ], 401);
    }
}
