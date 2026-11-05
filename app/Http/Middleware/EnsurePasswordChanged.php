<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'This account is inactive.'], 403)
                : redirect()->route('login');
        }

        if ($request->user()?->must_change_password && ! $request->routeIs('password.change', 'password.update', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Change your temporary password before continuing.',
                    'redirect_url' => route('password.change'),
                ], 423);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
