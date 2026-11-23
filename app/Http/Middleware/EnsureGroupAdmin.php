<?php

namespace App\Http\Middleware;

use App\Models\Group;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGroupAdmin
{
    /**
     * Allows the request only when the bearer token is this group's admin token.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $group = $request->route('group');

        if (! $group instanceof Group || ! $group->hasAdminToken($request->bearerToken())) {
            return response()->json([
                'message' => 'Este enlace de administración no es válido.',
                'code' => 'INVALID_ADMIN_TOKEN',
            ], 403);
        }

        return $next($request);
    }
}
