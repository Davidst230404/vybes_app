<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApprovalRequest;
use App\Http\Resources\Admin\MerchantResource;
use App\Http\Resources\Admin\OrganizerResource;
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
                'merchants' => MerchantResource::collection($merchants),
                'organizers' => OrganizerResource::collection($organizers),
            ],
        ]);
    }

    /**
     * Update merchant approval status.
     */
    public function updateMerchantStatus(ApprovalRequest $request, Merchant $merchant, \App\Services\AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validated();
        $oldStatus = $merchant->status;

        if ($oldStatus === $validated['status']) {
            return response()->json([
                'message' => "Merchant status is already {$validated['status']}.",
                'data' => new MerchantResource($merchant->load('user')),
            ]);
        }

        $merchant->update(['status' => $validated['status']]);

        $auditLogger->log(
            actor: $request->user(),
            action: 'merchant.approval',
            entityType: 'merchant',
            entityId: $merchant->id,
            metadata: [
                'business_name' => $merchant->business_name,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
            ]
        );

        return response()->json([
            'message' => "Merchant status updated to {$validated['status']}.",
            'data' => new MerchantResource($merchant->load('user')),
        ]);
    }

    /**
     * Update organizer approval status.
     */
    public function updateOrganizerStatus(ApprovalRequest $request, Organizer $organizer, \App\Services\AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validated();
        $oldStatus = $organizer->status;

        if ($oldStatus === $validated['status']) {
            return response()->json([
                'message' => "Organizer status is already {$validated['status']}.",
                'data' => new OrganizerResource($organizer->load('user')),
            ]);
        }

        $organizer->update(['status' => $validated['status']]);

        $auditLogger->log(
            actor: $request->user(),
            action: 'organizer.approval',
            entityType: 'organizer',
            entityId: $organizer->id,
            metadata: [
                'organization_name' => $organizer->organization_name,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
            ]
        );

        return response()->json([
            'message' => "Organizer status updated to {$validated['status']}.",
            'data' => new OrganizerResource($organizer->load('user')),
        ]);
    }
}
