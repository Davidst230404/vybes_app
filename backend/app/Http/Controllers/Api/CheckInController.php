<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function checkIn(
        Request $request,
        CheckInService $checkInService
    ): JsonResponse {

        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        if (!$request->user()->hasRole('merchant')) {
            return response()->json([
                'message' => 'Only merchants can perform check-in.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'qr_payload' => [
                'required',
                'string',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Process Check-in
        |--------------------------------------------------------------------------
        */

        $ticket = $checkInService->checkIn(
            $validated['qr_payload'],
            $request->user()
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => 'Check-in successful.',
            'data' => [
                'ticket' => $ticket,
            ],
        ]);
    }
}