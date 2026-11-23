<?php

namespace App\Http\Middleware;

use App\Models\Group;
use App\Models\Participant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateParticipant
{
    /**
     * Resolves the participant from the bearer claim token and exposes it as the `participant` attribute.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $group = $request->route('group');
        $claimToken = $request->bearerToken();

        $participant = $group instanceof Group && $claimToken !== null
            ? Participant::query()
                ->whereBelongsTo($group)
                ->where('claim_token', $claimToken)
                ->first()
            : null;

        if ($participant === null) {
            return response()->json([
                'message' => 'Tu sesión ya no es válida. Elige tu nombre otra vez.',
                'code' => 'INVALID_SESSION',
            ], 401);
        }

        $request->attributes->set('participant', $participant);

        return $next($request);
    }
}
