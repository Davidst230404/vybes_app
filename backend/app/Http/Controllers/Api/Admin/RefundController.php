<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateRefundRequest;
use App\Http\Resources\Admin\RefundResource;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RefundController extends Controller
{
    /**
     * List all platform refunds with optional status filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentRefund::with('payment');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $refunds = $query->latest('id')->paginate($perPage);

        return RefundResource::collection($refunds);
    }

    /**
     * Show refund detail.
     */
    public function show(PaymentRefund $refund): JsonResponse
    {
        $refund->load('payment');

        return response()->json([
            'data' => new RefundResource($refund),
        ]);
    }

    /**
     * Issue refund through existing PaymentRefundService.
     */
    public function store(CreateRefundRequest $request, PaymentRefundService $refundService, \App\Services\AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validated();
        $payment = Payment::findOrFail($validated['payment_id']);

        try {
            $refund = $refundService->createRefund(
                payment: $payment,
                amount: isset($validated['amount']) ? (float) $validated['amount'] : null,
                reason: $validated['reason'] ?? 'ADMIN_REFUND'
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $auditLogger->log(
            actor: $request->user(),
            action: 'refund.create',
            entityType: 'refund',
            entityId: $refund->id,
            metadata: [
                'payment_id' => $payment->id,
                'amount' => (float) $refund->amount,
                'reason' => $refund->reason,
            ]
        );

        return response()->json([
            'message' => 'Refund processed.',
            'data' => new RefundResource($refund->load('payment')),
        ], 201);
    }
}
