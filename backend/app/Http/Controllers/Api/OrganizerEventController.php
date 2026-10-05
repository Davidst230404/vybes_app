<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use App\Models\EventTicketType;
use App\Models\Payment;
use App\Services\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizerEventController extends Controller
{
    /**
     * List events owned by authenticated organizer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('organizer')) {
            return response()->json([
                'message' => 'Only organizers can manage events.',
            ], 403);
        }

        $organizer = $user->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        if ($organizer->status !== 'approved') {
            return response()->json([
                'message' => 'Organizer account is not approved.',
            ], 403);
        }

        $events = Event::query()
            ->where('organizer_id', $organizer->id)
            ->with([
                'venue',
                'ticketTypes',
            ])
            ->latest()
            ->get();

        return response()->json([
            'data' => $events,
        ]);
    }

    /**
     * Create new event.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('organizer')) {
            return response()->json([
                'message' => 'Only organizers can manage events.',
            ], 403);
        }

        $organizer = $user->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        if ($organizer->status !== 'approved') {
            return response()->json([
                'message' => 'Organizer account is not approved.',
            ], 403);
        }

        $validated = $request->validate([
            'venue_id' => [
                'nullable',
                'integer',
                'exists:venues,id',
            ],

            'title' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'cover_image' => [
                'nullable',
                'string',
                'max:255',
            ],

            'starts_at' => [
                'required',
                'date',
            ],

            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],
        ]);

        $event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $validated['venue_id'] ?? null,

            'title' => $validated['title'],

            'slug' => $this->generateUniqueSlug(
                $validated['title']
            ),

            'description' => $validated['description'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,

            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],

            /*
             * Publishing/cancellation/completion are controlled transitions.
             * Client tidak boleh mengubah status secara langsung.
             */
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Event created successfully.',

            'data' => $event->load([
                'venue',
                'ticketTypes',
            ]),
        ], 201);
    }

    /**
     * Show organizer event detail.
     */
    public function show(
        Request $request,
        Event $event
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        return response()->json([
            'data' => $event->load([
                'venue',
                'ticketTypes',
            ]),
        ]);
    }

    /**
     * Update organizer event.
     */
    public function update(
        Request $request,
        Event $event
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        if ($event->status === 'cancelled') {
            return response()->json([
                'message' => 'Cancelled events cannot be updated.',
            ], 422);
        }

        $validated = $request->validate([
            'venue_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:venues,id',
            ],

            'title' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'cover_image' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'starts_at' => [
                'sometimes',
                'required',
                'date',
            ],

            'ends_at' => [
                'sometimes',
                'required',
                'date',
            ],
        ]);

        /*
         * Jika hanya salah satu starts_at / ends_at dikirim,
         * gunakan nilai lama untuk validasi kombinasi tanggal.
         */
        $startsAt = $validated['starts_at']
            ?? $event->starts_at;

        $endsAt = $validated['ends_at']
            ?? $event->ends_at;

        if (
            $startsAt !== null &&
            $endsAt !== null &&
            strtotime((string) $endsAt) <= strtotime((string) $startsAt)
        ) {
            throw ValidationException::withMessages([
                'ends_at' => [
                    'The ends_at must be after starts_at.',
                ],
            ]);
        }

        /*
         * Generate slug hanya jika title berubah.
         */
        if (
            array_key_exists('title', $validated) &&
            $validated['title'] !== $event->title
        ) {
            $validated['slug'] = $this->generateUniqueSlug(
                $validated['title'],
                $event->id
            );
        }

        $event->update($validated);

        return response()->json([
            'message' => 'Event updated successfully.',

            'data' => $event
                ->fresh()
                ->load([
                    'venue',
                    'ticketTypes',
                ]),
        ]);
    }

    /**
     * Cancel an event.
     *
     * Flow:
     *
     * 1. Validate organizer ownership.
     * 2. Lock event.
     * 3. Mark event cancelled.
     * 4. Disable ticket types.
     * 5. Cancel confirmed / active ticket orders.
     * 6. Cancel issued tickets.
     * 7. Collect paid payment IDs.
     * 8. Commit DB transaction.
     * 9. Process Xendit refunds OUTSIDE transaction.
     */
    public function cancel(
        Request $request,
        Event $event,
        PaymentRefundService $refundService
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        $validated = $request->validate([
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $refundPaymentIds = [];

        /*
         * ==========================================================
         * DATABASE TRANSACTION
         * ==========================================================
         *
         * Jangan panggil Xendit di dalam transaction.
         */
        DB::transaction(function () use (
            $event,
            $validated,
            &$refundPaymentIds
        ) {
            /*
             * Lock event.
             */
            $lockedEvent = Event::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Idempotency.
             */
            if ($lockedEvent->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'event' => [
                        'This event has already been cancelled.',
                    ],
                ]);
            }

            /*
             * Event completed tidak boleh dibatalkan.
             */
            if ($lockedEvent->status === 'completed') {
                throw ValidationException::withMessages([
                    'event' => [
                        'Completed events cannot be cancelled.',
                    ],
                ]);
            }

            /*
             * Cancel event.
             */
            $lockedEvent->update([
                'status' => 'cancelled',
            ]);

            /*
             * Disable semua ticket type.
             */
            EventTicketType::query()
                ->where('event_id', $lockedEvent->id)
                ->update([
                    'status' => 'inactive',
                ]);

            /*
             * Ambil semua ticket order.
             */
            $orders = EventTicketOrder::query()
                ->where('event_id', $lockedEvent->id)
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                /*
                 * ==================================================
                 * CONFIRMED
                 * ==================================================
                 *
                 * Order confirmed:
                 * - cancel order
                 * - cari payment paid
                 * - simpan payment ID untuk refund
                 */
                if ($order->status === 'confirmed') {
                    $order->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                    ]);

                    $payment = Payment::query()
                        ->where(
                            'event_ticket_order_id',
                            $order->id
                        )
                        ->where('status', 'paid')
                        ->lockForUpdate()
                        ->first();

                    if ($payment) {
                        $refundPaymentIds[] = $payment->id;
                    }

                    continue;
                }

                /*
                 * ==================================================
                 * HELD / WAITING PAYMENT / PENDING
                 * ==================================================
                 */
                if (
                    in_array(
                        $order->status,
                        [
                            'held',
                            'waiting_payment',
                            'pending',
                        ],
                        true
                    )
                ) {
                    $order->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                    ]);
                }
            }

            /*
             * ======================================================
             * CANCEL ISSUED TICKETS
             * ======================================================
             *
             * Ticket tidak dihapus supaya historical record tetap
             * tersedia.
             */
            EventTicket::query()
                ->where('event_id', $lockedEvent->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                ]);
        });

        /*
         * ==========================================================
         * REFUND XENDIT
         * ==========================================================
         *
         * Dilakukan SETELAH DB transaction selesai.
         */
        $refunds = [];
        $refundFailures = [];

        foreach (array_unique($refundPaymentIds) as $paymentId) {
            try {
                $payment = Payment::query()
                    ->find($paymentId);

                if (!$payment) {
                    continue;
                }

                $refund = $refundService->createRefund(
                    payment: $payment,
                    amount: (float) $payment->amount,
                    reason: 'CANCELLATION'
                );

                $refunds[] = [
                    'payment_id' => $payment->id,
                    'refund_id' => $refund->id,
                    'status' => $refund->status,
                    'amount' => $refund->amount,
                ];
            } catch (\Throwable $exception) {
                /*
                 * Event tetap cancelled walaupun refund provider
                 * gagal.
                 *
                 * Detail exception hanya ditulis ke log server.
                 * Jangan expose error internal/provider ke client.
                 */
                Log::error('Organizer event refund failed.', [
                    'event_id' => $event->id,
                    'payment_id' => $paymentId,
                    'exception' => $exception,
                ]);

                $refundFailures[] = [
                    'payment_id' => $paymentId,
                    'message' => 'Refund processing failed. Please contact support.',
                ];
            }
        }

        /*
         * Refresh event setelah seluruh proses selesai.
         */
        $event->refresh();

        return response()->json([
            'message' => 'Event cancelled successfully.',

            'data' => [
                'event' => $event->load([
                    'venue',
                    'ticketTypes',
                ]),

                'refunds' => $refunds,

                'refund_failures' => $refundFailures,
            ],
        ]);
    }

    /**
     * Reschedule organizer event.
     *
     * Existing ticket/order tetap dipertahankan.
     * Yang berubah hanya jadwal event.
     */
    public function reschedule(
        Request $request,
        Event $event
    ): JsonResponse {
        $authorizationError = $this->authorizeOrganizerEvent(
            $request,
            $event
        );

        if ($authorizationError) {
            return $authorizationError;
        }

        if ($event->status === 'cancelled') {
            return response()->json([
                'message' => 'Cancelled events cannot be rescheduled.',
            ], 422);
        }

        if ($event->status === 'completed') {
            return response()->json([
                'message' => 'Completed events cannot be rescheduled.',
            ], 422);
        }

        $validated = $request->validate([
            'starts_at' => [
                'required',
                'date',
                'after:now',
            ],

            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],

            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        /*
         * Update schedule secara atomic.
         */
        DB::transaction(function () use (
            $event,
            $validated
        ) {
            $lockedEvent = Event::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEvent->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'event' => [
                        'Cancelled events cannot be rescheduled.',
                    ],
                ]);
            }

            if ($lockedEvent->status === 'completed') {
                throw ValidationException::withMessages([
                    'event' => [
                        'Completed events cannot be rescheduled.',
                    ],
                ]);
            }

            $lockedEvent->update([
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
            ]);
        });

        /*
         * Reason belum disimpan karena events table belum memiliki
         * kolom dedicated untuk reschedule reason.
         */
        return response()->json([
            'message' => 'Event rescheduled successfully.',

            'data' => [
                'event' => $event
                    ->fresh()
                    ->load([
                        'venue',
                        'ticketTypes',
                    ]),
            ],
        ]);
    }

    /**
     * Authorize organizer ownership.
     */
    private function authorizeOrganizerEvent(
        Request $request,
        Event $event
    ): ?JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
         * User harus memiliki role organizer.
         */
        if (!$user->hasRole('organizer')) {
            return response()->json([
                'message' => 'Only organizers can manage events.',
            ], 403);
        }

        /*
         * Organizer profile harus tersedia.
         */
        $organizer = $user->organizer;

        if (!$organizer) {
            return response()->json([
                'message' => 'Organizer profile not found.',
            ], 403);
        }

        /*
         * Organizer harus sudah approved.
         */
        if ($organizer->status !== 'approved') {
            return response()->json([
                'message' => 'Organizer account is not approved.',
            ], 403);
        }

        /*
         * IMPORTANT:
         *
         * events.organizer_id -> organizers.id
         *
         * BUKAN users.id.
         */
        if ((int) $event->organizer_id !== (int) $organizer->id) {
            return response()->json([
                'message' => 'You are not authorized to access this event.',
            ], 403);
        }

        return null;
    }

    /**
     * Generate unique event slug.
     *
     * $ignoreEventId digunakan saat UPDATE supaya slug milik
     * event yang sedang di-update tidak dianggap duplicate.
     */
    private function generateUniqueSlug(
        string $title,
        ?int $ignoreEventId = null
    ): string {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'event';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (true) {
            $query = Event::query()
                ->where('slug', $slug);

            /*
             * Jangan menggunakan whereKeyNot().
             *
             * Gunakan where('id', '!=', ...) agar kompatibel
             * dengan versi Laravel/Eloquent yang digunakan.
             */
            if ($ignoreEventId !== null) {
                $query->where('id', '!=', $ignoreEventId);
            }

            if (!$query->exists()) {
                break;
            }

            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}