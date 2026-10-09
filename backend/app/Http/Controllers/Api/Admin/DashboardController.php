<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Merchant;
use App\Models\Organizer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Return real aggregated platform statistics derived from the database.
     */
    public function index(): JsonResponse
    {
        $totalUsers = User::count();
        $totalMerchants = Merchant::count();
        $totalBookings = Booking::count();
        $confirmedBookings = Booking::where('status', 'confirmed')->count();
        $totalTransactions = Payment::count();
        $totalVenues = Venue::count();
        $totalEvents = Event::count();

        $totalRevenue = (float) Payment::where('status', 'paid')->sum('amount');
        $totalRefunds = (float) PaymentRefund::where('status', 'succeeded')->sum('amount');

        $pendingMerchants = Merchant::where('status', 'pending')->count();
        $pendingOrganizers = Organizer::where('status', 'pending')->count();

        $approvedMerchants = Merchant::where('status', 'approved')->count();
        $approvedOrganizers = Organizer::where('status', 'approved')->count();
        $activeVenues = Venue::where('status', 'active')->count();
        $upcomingBookings = Booking::where('starts_at', '>=', now())
            ->whereIn('status', ['confirmed', 'paid'])
            ->count();

        $activities = collect();

        // 1. Merchant applications
        Merchant::latest()->take(5)->get()->each(function ($m) use (&$activities) {
            $activities->push([
                'id' => 'merchant-' . $m->id,
                'entity' => $m->business_name,
                'type' => 'Merchant application',
                'status' => $m->status === 'pending' ? 'Pending Review' : ucfirst($m->status),
                'status_raw' => $m->status,
                'created_at' => $m->created_at?->toISOString() ?? now()->toISOString(),
            ]);
        });

        // 2. Organizer applications
        Organizer::latest()->take(5)->get()->each(function ($o) use (&$activities) {
            $activities->push([
                'id' => 'organizer-' . $o->id,
                'entity' => $o->organization_name,
                'type' => 'Organizer application',
                'status' => $o->status === 'pending' ? 'Pending Review' : ucfirst($o->status),
                'status_raw' => $o->status,
                'created_at' => $o->created_at?->toISOString() ?? now()->toISOString(),
            ]);
        });

        // 3. Platform Bookings
        Booking::latest()->take(5)->get()->each(function ($b) use (&$activities) {
            $activities->push([
                'id' => 'booking-' . $b->id,
                'entity' => $b->booking_code,
                'type' => 'Booking',
                'status' => $b->status === 'pending' ? 'Pending Payment' : ucfirst($b->status),
                'status_raw' => $b->status,
                'created_at' => $b->created_at?->toISOString() ?? now()->toISOString(),
            ]);
        });

        // 4. Refund requests
        PaymentRefund::with('payment.booking')->latest()->take(5)->get()->each(function ($r) use (&$activities) {
            $code = $r->payment?->booking?->booking_code ?? $r->reference_id ?? ('REF-' . $r->id);
            $activities->push([
                'id' => 'refund-' . $r->id,
                'entity' => $code,
                'type' => 'Refund request',
                'status' => $r->status === 'pending' ? 'Pending Review' : ucfirst($r->status),
                'status_raw' => $r->status,
                'created_at' => ($r->requested_at ?? $r->created_at)?->toISOString() ?? now()->toISOString(),
            ]);
        });

        $recentActivity = $activities->sortByDesc('created_at')->values()->take(10)->all();

        return response()->json([
            'data' => [
                'metrics' => [
                    'total_users' => $totalUsers,
                    'total_merchants' => $totalMerchants,
                    'total_bookings' => $totalBookings,
                    'confirmed_bookings' => $confirmedBookings,
                    'total_transactions' => $totalTransactions,
                    'total_venues' => $totalVenues,
                    'total_events' => $totalEvents,
                    'total_revenue' => $totalRevenue,
                    'platform_revenue' => $totalRevenue,
                    'total_refunds' => $totalRefunds,
                    'pending_approvals' => $pendingMerchants + $pendingOrganizers,
                ],
                'pending_breakdown' => [
                    'merchants' => $pendingMerchants,
                    'organizers' => $pendingOrganizers,
                ],
                'operational_summary' => [
                    'approved_merchants' => $approvedMerchants,
                    'approved_organizers' => $approvedOrganizers,
                    'active_venues' => $activeVenues,
                    'upcoming_bookings' => $upcomingBookings,
                ],
                'recent_activity' => $recentActivity,
            ],
        ]);
    }
}
