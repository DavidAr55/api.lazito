<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ParticipantResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): ParticipantResource
    {
        return new ParticipantResource($request->attributes->get('participant'));
    }
}
