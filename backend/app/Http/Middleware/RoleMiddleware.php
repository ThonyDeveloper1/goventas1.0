<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Usage in routes:  middleware('role:admin')
     *                   middleware('role:admin,supervisor')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return response()->json(
                ['message' => 'No autenticado.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (! in_array($request->user()->role, $roles, true)) {
            return response()->json(
                ['message' => 'No tienes permisos para realizar esta acción.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
