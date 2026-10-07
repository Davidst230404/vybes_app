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
        $totalBookings = Booking::count();
        $confirmedBookings = Booking::where('status', 'confirmed')->count();
        $totalVenues = Venue::count();
        $totalEvents = Event::count();

        $totalRevenue = (float) Payment::where('status', 'paid')->sum('amount');
        $totalRefunds = (float) PaymentRefund::where('status', 'succeeded')->sum('amount');

        $pendingMerchants = Merchant::where('status', 'pending')->count();
        $pendingOrganizers = Organizer::where('status', 'pending')->count();

        return response()->json([
            'data' => [
                'metrics' => [
                    'total_users' => $totalUsers,
                    'total_bookings' => $totalBookings,
                    'confirmed_bookings' => $confirmedBookings,
                    'total_venues' => $totalVenues,
                    'total_events' => $totalEvents,
                    'total_revenue' => $totalRevenue,
                    'total_refunds' => $totalRefunds,
                    'pending_approvals' => $pendingMerchants + $pendingOrganizers,
                ],
                'pending_breakdown' => [
                    'merchants' => $pendingMerchants,
                    'organizers' => $pendingOrganizers,
                ],
            ],
        ]);
    }
}
