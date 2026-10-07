<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\VenueResource;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VenueController extends Controller
{
    /**
     * List all platform venues with search and status filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Venue::with([
            'merchant',
            'category',
        ]);

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('city', 'ilike', $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $venues = $query->latest('id')->paginate($perPage);

        return VenueResource::collection($venues);
    }

    /**
     * Show venue detail.
     */
    public function show(Venue $venue): JsonResponse
    {
        $venue->load([
            'merchant',
            'category',
            'resources',
        ]);

        return response()->json([
            'data' => new VenueResource($venue),
        ]);
    }
}
