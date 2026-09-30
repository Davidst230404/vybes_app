<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XenditService
{
    private string $secretKey;

    private string $apiUrl;

    public function __construct()
    {
        $this->secretKey = config('services.xendit.secret_key');

        $this->apiUrl = rtrim(
            config('services.xendit.api_url'),
            '/'
        );

        if ($this->secretKey === '') {
            throw new RuntimeException(
                'Xendit secret key is not configured.'
            );
        }
    }

    /**
     * Create QRIS payment request through Xendit Payments API v3.
     */
    public function createQrisPayment(Payment $payment): array
    {
        if ($payment->amount <= 0) {
            throw new RuntimeException(
                'Payment amount must be greater than zero.'
            );
        }

        if ($payment->status !== 'pending') {
            throw new RuntimeException(
                'Payment must be pending before creating Xendit payment.'
            );
        }

        if ($payment->provider_request_id) {
            throw new RuntimeException(
                'Xendit payment request has already been created.'
            );
        }

        $payload = [
            'reference_id' => $payment->payment_code,
            'type' => 'PAY',
            'country' => 'ID',
            'currency' => 'IDR',
            'request_amount' => (float) $payment->amount,
            'capture_method' => 'AUTOMATIC',
            'channel_code' => 'QRIS',

            'metadata' => [
                'payment_id' => (string) $payment->id,
                'booking_id' => $payment->booking_id !== null
                    ? (string) $payment->booking_id
                    : null,
                'event_ticket_order_id' => $payment->event_ticket_order_id !== null
                    ? (string) $payment->event_ticket_order_id
                    : null,
                'payment_code' => $payment->payment_code,
            ],
        ];

        $response = Http::withBasicAuth(
            $this->secretKey,
            ''
        )
            ->acceptJson()
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->post(
                $this->apiUrl . '/v3/payment_requests',
                $payload
            );

        $this->handleError($response);

        return $response->json();
    }

    /**
     * Get Xendit payment request status.
     */
    public function getPaymentRequest(
        string $paymentRequestId
    ): array {
        $response = Http::withBasicAuth(
            $this->secretKey,
            ''
        )
            ->acceptJson()
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->get(
                $this->apiUrl .
                '/v3/payment_requests/' .
                $paymentRequestId
            );

        $this->handleError($response);

        return $response->json();
    }

    /**
     * Simulate payment completion in Xendit Test Mode.
     */
    public function simulatePayment(
        string $paymentRequestId,
        float $amount
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException(
                'Simulation amount must be greater than zero.'
            );
        }

        $response = Http::withBasicAuth(
            $this->secretKey,
            ''
        )
            ->acceptJson()
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->post(
                $this->apiUrl .
                '/v3/payment_requests/' .
                $paymentRequestId .
                '/simulate',
                [
                    'amount' => $amount,
                ]
            );

        $this->handleError($response);

        return $response->json();
    }

    /**
     * Cancel Xendit payment request.
     */
    public function cancelPaymentRequest(
        string $paymentRequestId
    ): array {
        $response = Http::withBasicAuth(
            $this->secretKey,
            ''
        )
            ->acceptJson()
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->post(
                $this->apiUrl .
                '/v3/payment_requests/' .
                $paymentRequestId .
                '/cancel'
            );

        $this->handleError($response);

        return $response->json();
    }

    /**
     * Create a refund for a successful Xendit payment request.
     *
     * The refund reference ID is generated internally by VYBES
     * and passed to Xendit for end-to-end traceability.
     */
    public function createRefund(
        Payment $payment,
        float $amount,
        string $reason = 'DUPLICATE',
        ?string $referenceId = null
    ): array {
        if ($payment->provider !== 'xendit') {
            throw new RuntimeException(
                'Refund is only supported for Xendit payments.'
            );
        }

        if ($payment->status !== 'paid') {
            throw new RuntimeException(
                'Only paid payments can be refunded.'
            );
        }

        if (!$payment->provider_request_id) {
            throw new RuntimeException(
                'Xendit payment request ID is missing.'
            );
        }

        if ($amount <= 0) {
            throw new RuntimeException(
                'Refund amount must be greater than zero.'
            );
        }

        if ($amount > (float) $payment->amount) {
            throw new RuntimeException(
                'Refund amount cannot exceed the payment amount.'
            );
        }

        /*
         * Generate a fallback reference ID when the caller
         * does not provide one.
         */
        $referenceId ??= 'VYB-REF-' .
            strtoupper(
                bin2hex(random_bytes(5))
            );

        $payload = [
            'reference_id' => $referenceId,
            'payment_request_id' => $payment->provider_request_id,
            'amount' => $amount,
            'reason' => $reason,
            'currency' => 'IDR',
        ];

        $response = Http::withBasicAuth(
            $this->secretKey,
            ''
        )
            ->acceptJson()
            ->post(
                $this->apiUrl . '/refunds',
                $payload
            );

        $this->handleError($response);

        return $response->json();
    }

    /**
     * Handle Xendit API errors.
     */
    private function handleError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            'Xendit API error: ' .
            $response->status() .
            ' - ' .
            $response->body()
        );
    }
}