<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ParticipantPinRequest;
use App\Models\Group;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;

class ParticipantClaimController extends Controller
{
    /**
     * First visit: claims the name and sets its PIN.
     */
    public function store(ParticipantPinRequest $request, Group $group, Participant $participant): JsonResponse
    {
        $claimToken = $participant->claim($request->validated('pin'));

        if ($claimToken === null) {
            return response()->json([
                'message' => 'Ese nombre ya entró. Usa tu PIN para volver.',
                'code' => 'ALREADY_CLAIMED',
            ], 409);
        }

        return response()->json([
            'data' => ['claim_token' => $claimToken, 'revealed' => false],
        ], 201);
    }
}
