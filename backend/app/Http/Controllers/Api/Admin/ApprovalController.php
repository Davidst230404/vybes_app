<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApprovalRequest;
use App\Models\Merchant;
use App\Models\Organizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    /**
     * List pending merchant and organizer applications.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $merchants = Merchant::with('user')
            ->where('status', $status)
            ->latest('id')
            ->get();

        $organizers = Organizer::with('user')
            ->where('status', $status)
            ->latest('id')
            ->get();

        return response()->json([
            'data' => [
                'merchants' => $merchants,
                'organizers' => $organizers,
            ],
        ]);
    }

    /**
     * Update merchant approval status.
     */
    public function updateMerchantStatus(ApprovalRequest $request, Merchant $merchant): JsonResponse
    {
        $validated = $request->validated();
        $merchant->update(['status' => $validated['status']]);

        return response()->json([
            'message' => "Merchant status updated to {$validated['status']}.",
            'data' => $merchant->load('user'),
        ]);
    }

    /**
     * Update organizer approval status.
     */
    public function updateOrganizerStatus(ApprovalRequest $request, Organizer $organizer): JsonResponse
    {
        $validated = $request->validated();
        $organizer->update(['status' => $validated['status']]);

        return response()->json([
            'message' => "Organizer status updated to {$validated['status']}.",
            'data' => $organizer->load('user'),
        ]);
    }
}
