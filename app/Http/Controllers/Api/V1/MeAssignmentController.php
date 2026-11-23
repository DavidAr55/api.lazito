<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ParticipantResource;
use Illuminate\Http\Request;

class MeAssignmentController extends Controller
{
    /**
     * Returns only the authenticated participant's own assignment, and marks it as seen.
     */
    public function show(Request $request): ParticipantResource
    {
        $participant = $request->attributes->get('participant');
        $participant->markRevealed();

        return new ParticipantResource($participant->receiver);
    }
}
