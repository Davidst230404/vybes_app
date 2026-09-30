<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\EventTicketOrder;
use App\Services\PaymentService;
use App\Services\PaymentSessionService;
use App\Services\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Create payment session and Xendit payment request
     * for a regular booking.
     */
    public function createSession(
        Request $request,
        Booking $booking,
        PaymentSessionService $paymentSessionService,
        PaymentService $paymentService,
        XenditService $xenditService
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

        $payment = $paymentService->createXenditPayment(
            $payment,
            $xenditService
        );

        return response()->json([
            'message' => 'Xendit payment request created successfully.',
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

    /**
     * Create payment session and Xendit payment request
     * for an event ticket order.
     */
    public function createEventTicketSession(
        Request $request,
        EventTicketOrder $order,
        PaymentSessionService $paymentSessionService,
        PaymentService $paymentService,
        XenditService $xenditService
    ): JsonResponse {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not allowed to access this ticket order.',
            ], 403);
        }

        $paymentSession = $paymentSessionService->createForEventTicket(
            $order
        );

        $payment = $paymentService->createFromSession(
            $paymentSession
        );

        $payment = $paymentService->createXenditPayment(
            $payment,
            $xenditService
        );

        return response()->json([
            'message' => 'Xendit payment request created successfully.',
            'data' => [
                'payment_session' => $paymentSession,
                'payment' => $payment,
                'order' => $order->fresh([
                    'paymentSession',
                    'payment',
                    'event',
                    'ticketType',
                ]),
            ],
        ], 201);
    }
}