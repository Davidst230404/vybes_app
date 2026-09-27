<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\PaymentService;
use App\Services\PaymentSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Create payment session for a booking.
     */
    public function createSession(
        Request $request,
        Booking $booking,
        PaymentSessionService $paymentSessionService,
        PaymentService $paymentService
    ): JsonResponse {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not allowed to access this booking.',
            ], 403);
        }

        $paymentSession = $paymentSessionService->create($booking);

        $payment = $paymentService->createFromSession(
            $paymentSession
        );

        return response()->json([
            'message' => 'Payment session created successfully.',
            'data' => [
                'payment_session' => $paymentSession,
                'payment' => $payment,
                'booking' => $booking->fresh([
                    'paymentSession',
                    'payment',
                ]),
            ],
        ], 201);
    }
}