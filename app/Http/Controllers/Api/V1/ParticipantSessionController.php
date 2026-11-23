<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ParticipantPinRequest;
use App\Models\Group;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ParticipantSessionController extends Controller
{
    /**
     * Returning visit (e.g. another device): exchanges the PIN for the participant's session token.
     */
    public function store(ParticipantPinRequest $request, Group $group, Participant $participant): JsonResponse
    {
        if (! $participant->isClaimed()) {
            return response()->json([
                'message' => 'Ese nombre todavía no ha entrado.',
                'code' => 'NOT_CLAIMED',
            ], 409);
        }

        if (! Hash::check($request->validated('pin'), $participant->pin)) {
            return response()->json([
                'message' => 'PIN incorrecto. Revisa los 4 números e intenta de nuevo.',
                'code' => 'WRONG_PIN',
            ], 403);
        }

        return response()->json([
            'data' => [
                'claim_token' => $participant->claim_token,
                'revealed' => $participant->revealed_at !== null,
            ],
        ]);
    }
}
