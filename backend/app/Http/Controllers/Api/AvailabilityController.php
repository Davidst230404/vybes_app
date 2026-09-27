<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function check(
        Request $request,
        Resource $resource,
        AvailabilityService $availabilityService
    ): JsonResponse {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $startsAt = Carbon::parse($validated['starts_at']);
        $endsAt = Carbon::parse($validated['ends_at']);

        $available = $availabilityService->isAvailable(
            $resource,
            $startsAt,
            $endsAt
        );

        return response()->json([
            'data' => [
                'resource_id' => $resource->id,
                'resource_name' => $resource->name,
                'starts_at' => $startsAt->toISOString(),
                'ends_at' => $endsAt->toISOString(),
                'available' => $available,
            ],
        ]);
    }
}