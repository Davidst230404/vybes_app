<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventTicketOrder;
use App\Models\EventTicketType;
use App\Models\Merchant;
use App\Models\Organizer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PlatformSetting;
use App\Models\Resource;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingService;
use App\Services\EventTicketPurchaseService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $name): Role
    {
        return Role::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'description' => "Role {$name}",
        ]);
    }

    private function createUserWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->first() ?? $this->createRole($roleName);

        return User::create([
            'name' => ucfirst($roleName) . ' Test',
            'email' => $roleName . '_' . uniqid() . '@admin-test.local',
            'password' => 'password123',
            'role_id' => $role->id,
        ]);
    }

    /**
     * Unauthenticated user receives 401 on Admin endpoints.
     */
    public function test_unauthenticated_user_receives_401(): void
    {
        $this->getJson('/api/admin/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->getJson('/api/admin/users')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    /**
     * Authenticated non-admin user (customer/merchant/organizer) receives 403.
     */
    public function test_authenticated_non_admin_receives_403(): void
    {
        $customer = $this->createUserWithRole('customer');
        $merchant = $this->createUserWithRole('merchant');
        $organizer = $this->createUserWithRole('organizer');

        $this->actingAs($customer)
            ->getJson('/api/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to access the VYBES Admin CMS.');

        $this->actingAs($merchant)
            ->getJson('/api/admin/users')
            ->assertForbidden();

        $this->actingAs($organizer)
            ->getJson('/api/admin/categories')
            ->assertForbidden();
    }

    /**
     * Authorized admin can access dashboard and metrics.
     */
    public function test_admin_can_access_dashboard_metrics(): void
    {
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'metrics' => [
                    'total_users',
                    'total_merchants',
                    'total_bookings',
                    'confirmed_bookings',
                    'total_transactions',
                    'total_venues',
                    'total_events',
                    'total_revenue',
                    'platform_revenue',
                    'total_refunds',
                    'pending_approvals',
                ],
                'pending_breakdown' => [
                    'merchants',
                    'organizers',
                ],
                'operational_summary' => [
                    'approved_merchants',
                    'approved_organizers',
                    'active_venues',
                    'upcoming_bookings',
                ],
                'recent_activity',
            ],
        ]);
    }

    /**
     * Admin can list users with pagination and update a user role.
     */
    public function test_admin_can_list_and_update_users(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $merchantRole = Role::where('name', 'merchant')->first() ?? $this->createRole('merchant');

        $listResponse = $this->actingAs($admin)
            ->getJson('/api/admin/users?per_page=10')
            ->assertOk();

        $listResponse->assertJsonStructure([
            'data',
            'links',
            'meta',
        ]);

        $updateResponse = $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$customer->id}", [
                'name' => 'Promoted Merchant',
                'role_id' => $merchantRole->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Promoted Merchant')
            ->assertJsonPath('data.role_id', $merchantRole->id);

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'name' => 'Promoted Merchant',
            'role_id' => $merchantRole->id,
        ]);
    }

    /**
     * Admin Category validation and CRUD.
     */
    public function test_admin_category_crud_and_validation(): void
    {
        $admin = $this->createUserWithRole('admin');

        // Validation test: missing required name
        $this->actingAs($admin)
            ->postJson('/api/admin/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Create category
        $createResponse = $this->actingAs($admin)
            ->postJson('/api/admin/categories', [
                'name' => 'Badminton Court',
                'description' => 'Indoor badminton courts',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Badminton Court')
            ->assertJsonPath('data.slug', 'badminton-court');

        $categoryId = $createResponse->json('data.id');

        // Update category
        $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$categoryId}", [
                'name' => 'Badminton Arena',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Badminton Arena');

        // Delete category
        $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$categoryId}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', [
            'id' => $categoryId,
        ]);
    }

    /**
     * Admin approval is idempotent and rejects arbitrary statuses.
     */
    public function test_admin_approval_is_idempotent_and_validates_status(): void
    {
        $admin = $this->createUserWithRole('admin');
        $merchantUser = $this->createUserWithRole('merchant');

        $merchant = Merchant::create([
            'user_id' => $merchantUser->id,
            'business_name' => 'Court Master',
            'phone' => '081234567890',
            'status' => 'pending',
        ]);

        // Invalid status injection rejected
        $this->actingAs($admin)
            ->postJson("/api/admin/approvals/merchants/{$merchant->id}", [
                'status' => 'arbitrary_status',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // First approval: status changed to approved
        $this->actingAs($admin)
            ->postJson("/api/admin/approvals/merchants/{$merchant->id}", [
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Repeated approval: idempotent early return
        $repeatResponse = $this->actingAs($admin)
            ->postJson("/api/admin/approvals/merchants/{$merchant->id}", [
                'status' => 'approved',
            ])
            ->assertOk();

        $repeatResponse->assertJsonPath('message', 'Merchant status is already approved.');
    }

    /**
     * Refund fails with 422 when payment is not paid.
     */
    public function test_refund_fails_with_422_when_payment_is_not_paid(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');

        $payment = Payment::create([
            'payment_code' => 'VYB-PAY-TEST123',
            'payment_session_id' => null,
            'provider' => 'xendit',
            'provider_request_id' => 'req_test_123',
            'status' => 'pending', // Unpaid
            'amount' => 150000,
            'currency' => 'IDR',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 150000,
                'reason' => 'CUSTOMER_REQUEST',
            ])
            ->assertStatus(422);

        $response->assertJsonPath('message', 'Only paid payments can be refunded.');
    }

    /**
     * Category deletion fails when venues are assigned.
     */
    public function test_category_deletion_fails_when_venues_are_assigned(): void
    {
        $admin = $this->createUserWithRole('admin');
        $merchantUser = $this->createUserWithRole('merchant');

        $merchant = Merchant::create([
            'user_id' => $merchantUser->id,
            'business_name' => 'Arena Sport',
            'phone' => '081234567891',
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Basketball Gym',
            'slug' => 'basketball-gym',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Venue::create([
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'name' => 'Downtown Gym',
            'slug' => 'downtown-gym',
            'status' => 'published',
            'address' => 'Jl. Olahraga No. 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete category that is currently assigned to venues.');
    }

    /**
     * User update ignores arbitrary fields and cannot inject status or password.
     */
    public function test_user_update_prevents_arbitrary_field_and_status_injection(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $originalPassword = $customer->password;

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$customer->id}", [
                'name' => 'Safe Name',
                'status' => 'suspended', // Non-existent column / unvalidated field
                'password' => 'new_hacked_password', // Not in FormRequest rules
            ])
            ->assertOk();

        $customer->refresh();
        $this->assertSame('Safe Name', $customer->name);
        $this->assertSame($originalPassword, $customer->password);
    }

    /**
     * Approvals index returns properly transformed MerchantResource and OrganizerResource.
     */
    public function test_admin_approvals_index_returns_merchant_and_organizer_resources(): void
    {
        $admin = $this->createUserWithRole('admin');
        $merchantUser = $this->createUserWithRole('merchant');
        $organizerUser = $this->createUserWithRole('organizer');

        Merchant::create([
            'user_id' => $merchantUser->id,
            'business_name' => 'Futsal Arena',
            'phone' => '081234567892',
            'status' => 'pending',
        ]);

        Organizer::create([
            'user_id' => $organizerUser->id,
            'organization_name' => 'Jakarta Fest',
            'phone' => '081234567893',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/approvals?status=pending')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'merchants' => [
                    '*' => ['id', 'user_id', 'business_name', 'phone', 'status', 'user'],
                ],
                'organizers' => [
                    '*' => ['id', 'user_id', 'organization_name', 'phone', 'status', 'user'],
                ],
            ],
        ]);

        $this->assertSame('Futsal Arena', $response->json('data.merchants.0.business_name'));
        $this->assertSame('Jakarta Fest', $response->json('data.organizers.0.organization_name'));
    }

    /**
     * Booking hold expiration console command delegates to BookingService and expires past holds.
     */
    public function test_booking_service_and_console_command_expire_holds(): void
    {
        $customer = $this->createUserWithRole('customer');
        $merchantUser = $this->createUserWithRole('merchant');

        $merchant = Merchant::create([
            'user_id' => $merchantUser->id,
            'business_name' => 'Badminton Center',
            'phone' => '081234567894',
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Sports Hall',
            'slug' => 'sports-hall',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $venue = Venue::create([
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'name' => 'Hall A',
            'slug' => 'hall-a',
            'status' => 'published',
            'address' => 'Jl. Merdeka No. 10',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
        ]);

        $expiredBooking = Booking::create([
            'booking_code' => 'VYB-EXP-001',
            'user_id' => $customer->id,
            'venue_id' => $venue->id,
            'status' => 'held',
            'starts_at' => now()->addDays(1),
            'ends_at' => now()->addDays(1)->addHours(2),
            'quantity' => 1,
            'subtotal' => 100000,
            'total_amount' => 100000,
            'hold_expires_at' => now()->subMinutes(10), // Past
        ]);

        $activeBooking = Booking::create([
            'booking_code' => 'VYB-ACT-001',
            'user_id' => $customer->id,
            'venue_id' => $venue->id,
            'status' => 'held',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
            'quantity' => 1,
            'subtotal' => 100000,
            'total_amount' => 100000,
            'hold_expires_at' => now()->addMinutes(15), // Future
        ]);

        Artisan::call('bookings:expire-holds');

        $this->assertSame('expired', $expiredBooking->fresh()->status);
        $this->assertSame('held', $activeBooking->fresh()->status);
    }

    /**
     * BookingService respects dynamically configured hold duration from PlatformSetting.
     */
    public function test_booking_service_uses_dynamic_platform_setting_hold_duration(): void
    {
        $customer = $this->createUserWithRole('customer');
        $merchantUser = $this->createUserWithRole('merchant');

        $merchant = Merchant::create([
            'user_id' => $merchantUser->id,
            'business_name' => 'Sport Club',
            'phone' => '081234567895',
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Tennis Court',
            'slug' => 'tennis-court',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $venue = Venue::create([
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'name' => 'Tennis Arena',
            'slug' => 'tennis-arena',
            'status' => 'published',
            'address' => 'Jl. Tennis No. 5',
            'city' => 'Surabaya',
            'province' => 'Jawa Timur',
        ]);

        $resource = Resource::create([
            'venue_id' => $venue->id,
            'name' => 'Court 1',
            'slug' => 'court-1',
            'base_price' => 75000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $tomorrow = Carbon::tomorrow();
        Schedule::create([
            'resource_id' => $resource->id,
            'day_of_week' => $tomorrow->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '22:00:00',
            'is_available' => true,
        ]);

        // Configure custom hold duration: 45 minutes
        PlatformSetting::set('booking_hold_duration_minutes', 45, 'integer');

        $bookingService = app(BookingService::class);
        $booking = $bookingService->createHold(
            userId: $customer->id,
            resource: $resource,
            startsAt: Carbon::tomorrow()->setTime(10, 0),
            endsAt: Carbon::tomorrow()->setTime(12, 0),
            quantity: 1
        );

        $this->assertNotNull($booking->hold_expires_at);
        $diffMinutes = (int) round(now()->diffInMinutes($booking->hold_expires_at));
        $this->assertSame(45, $diffMinutes);
    }

    /**
     * Authorization Matrix: Unauthenticated (401) and Non-Admin (403) across all sensitive Admin endpoints.
     */
    public function test_admin_authorization_matrix_protects_all_sensitive_endpoints(): void
    {
        $customer = $this->createUserWithRole('customer');
        $targetUser = $this->createUserWithRole('customer');

        $category = Category::create([
            'name' => 'Auth Matrix Category',
            'slug' => 'auth-matrix-category',
            'is_active' => true,
        ]);

        $merchant = Merchant::create([
            'user_id' => $targetUser->id,
            'business_name' => 'Matrix Merchant',
            'phone' => '081234567897',
            'status' => 'pending',
        ]);

        $organizer = Organizer::create([
            'user_id' => $targetUser->id,
            'organization_name' => 'Matrix Organizer',
            'phone' => '081234567898',
            'status' => 'pending',
        ]);

        $sensitiveEndpoints = [
            ['method' => 'patchJson', 'url' => "/api/admin/users/{$targetUser->id}", 'data' => ['name' => 'Hacker']],
            ['method' => 'getJson', 'url' => '/api/admin/approvals'],
            ['method' => 'postJson', 'url' => "/api/admin/approvals/merchants/{$merchant->id}", 'data' => ['status' => 'approved']],
            ['method' => 'postJson', 'url' => "/api/admin/approvals/organizers/{$organizer->id}", 'data' => ['status' => 'approved']],
            ['method' => 'postJson', 'url' => '/api/admin/categories', 'data' => ['name' => 'Unauthorized Category']],
            ['method' => 'putJson', 'url' => "/api/admin/categories/{$category->id}", 'data' => ['name' => 'Updated Category']],
            ['method' => 'deleteJson', 'url' => "/api/admin/categories/{$category->id}"],
            ['method' => 'getJson', 'url' => '/api/admin/bookings'],
            ['method' => 'getJson', 'url' => '/api/admin/refunds'],
            ['method' => 'postJson', 'url' => '/api/admin/refunds', 'data' => ['payment_id' => 1, 'amount' => 50000]],
            ['method' => 'getJson', 'url' => '/api/admin/venues'],
            ['method' => 'getJson', 'url' => '/api/admin/settings'],
            ['method' => 'patchJson', 'url' => '/api/admin/settings', 'data' => ['booking_hold_duration_minutes' => 30]],
            ['method' => 'getJson', 'url' => '/api/admin/audit'],
        ];

        // 1. Unauthenticated requests -> 401
        foreach ($sensitiveEndpoints as $endpoint) {
            $method = $endpoint['method'];
            $url = $endpoint['url'];
            $data = $endpoint['data'] ?? [];

            $unauthResponse = empty($data)
                ? $this->{$method}($url)
                : $this->{$method}($url, $data);
            $unauthResponse->assertUnauthorized();
        }

        // 2. Non-admin requests (customer) -> 403
        $this->actingAs($customer);
        foreach ($sensitiveEndpoints as $endpoint) {
            $method = $endpoint['method'];
            $url = $endpoint['url'];
            $data = $endpoint['data'] ?? [];

            $nonAdminResponse = empty($data)
                ? $this->{$method}($url)
                : $this->{$method}($url, $data);
            $nonAdminResponse->assertForbidden();
        }
    }

    /**
     * Event ticket reservation respects dynamic hold duration setting and console command expires past holds.
     */
    public function test_event_ticket_order_uses_dynamic_platform_setting_hold_duration_and_expires(): void
    {
        $customer = $this->createUserWithRole('customer');
        $organizerUser = $this->createUserWithRole('organizer');

        $organizer = Organizer::create([
            'user_id' => $organizerUser->id,
            'organization_name' => 'Concert Group',
            'phone' => '081234567896',
            'status' => 'approved',
        ]);

        $event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => null,
            'title' => 'Indie Fest 2026',
            'slug' => 'indie-fest-2026',
            'description' => 'Live concert',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(10)->addHours(4),
            'status' => 'published',
        ]);

        $ticketType = EventTicketType::create([
            'event_id' => $event->id,
            'name' => 'VIP Access',
            'price' => 250000,
            'quota' => 50,
            'sold' => 0,
            'reserved' => 0,
            'sales_starts_at' => now()->subDay(),
            'sales_ends_at' => now()->addDays(9),
            'status' => 'active',
        ]);

        // Configure custom hold duration: 25 minutes
        PlatformSetting::set('booking_hold_duration_minutes', 25, 'integer');

        $purchaseService = app(EventTicketPurchaseService::class);
        $order = $purchaseService->createHold(
            user: $customer,
            ticketType: $ticketType,
            quantity: 2
        );

        $this->assertSame('held', $order->status);
        $this->assertNotNull($order->hold_expires_at);

        $diffMinutes = (int) round(now()->diffInMinutes($order->hold_expires_at));
        $this->assertSame(25, $diffMinutes);

        // Verify reserved count on ticket type
        $ticketType->refresh();
        $this->assertSame(2, $ticketType->reserved);

        // Simulate expiration: update hold_expires_at into past
        $order->update([
            'hold_expires_at' => now()->subMinutes(5),
        ]);

        Artisan::call('event-ticket-orders:expire-holds');

        $order->refresh();
        $ticketType->refresh();

        $this->assertSame('expired', $order->status);
        $this->assertSame(0, $ticketType->reserved);
    }

    /**
     * Refund duplicate protection returns existing refund without calling Xendit again.
     */
    public function test_refund_duplicate_protection_and_validations(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payment = Payment::create([
            'payment_code' => 'VYB-PAY-REFUND-001',
            'provider' => 'xendit',
            'provider_request_id' => 'req_paid_001',
            'status' => 'paid',
            'amount' => 200000,
            'currency' => 'IDR',
        ]);

        // 1. Amount exceeding payment amount is rejected with 422
        $this->actingAs($admin)
            ->postJson('/api/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 300000, // Exceeds 200,000
                'reason' => 'OVER_REFUND',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Refund amount cannot exceed the payment amount.');

        // 2. Mock XenditService for initial successful refund
        $xenditMock = Mockery::mock(XenditService::class);
        $xenditMock->shouldReceive('createRefund')
            ->once()
            ->andReturn([
                'id' => 'xendit_ref_123',
                'status' => 'SUCCEEDED',
                'failure_code' => null,
                'failure_reason' => null,
                'refund_fee_amount' => 0,
            ]);

        $this->app->instance(XenditService::class, $xenditMock);

        $firstResponse = $this->actingAs($admin)
            ->postJson('/api/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 200000,
                'reason' => 'CUSTOMER_REQUEST',
            ])
            ->assertStatus(201);

        $refundId = $firstResponse->json('data.id');
        $this->assertNotNull($refundId);
        $this->assertSame('succeeded', $firstResponse->json('data.status'));

        // 3. Repeated refund request returns existing refund without calling Xendit again
        $repeatResponse = $this->actingAs($admin)
            ->postJson('/api/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 200000,
                'reason' => 'CUSTOMER_REQUEST',
            ])
            ->assertStatus(201);

        $this->assertSame($refundId, $repeatResponse->json('data.id'));
        $this->assertSame(1, PaymentRefund::where('payment_id', $payment->id)->count());
    }

    /**
     * Refund handles external provider failure safely, persisting failed status and error reason.
     */
    public function test_refund_handles_external_xendit_failure_safely(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payment = Payment::create([
            'payment_code' => 'VYB-PAY-REFUND-FAIL',
            'provider' => 'xendit',
            'provider_request_id' => 'req_paid_fail_001',
            'status' => 'paid',
            'amount' => 100000,
            'currency' => 'IDR',
        ]);

        $xenditMock = Mockery::mock(XenditService::class);
        $xenditMock->shouldReceive('createRefund')
            ->once()
            ->andThrow(new \RuntimeException('Xendit connection timeout occurred'));

        $this->app->instance(XenditService::class, $xenditMock);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 100000,
                'reason' => 'SYSTEM_ERROR',
            ])
            ->assertStatus(422);

        $response->assertJsonPath('message', 'Xendit connection timeout occurred');

        // Verify the failed refund record is persisted for auditability
        $failedRefund = PaymentRefund::where('payment_id', $payment->id)->first();
        $this->assertNotNull($failedRefund);
        $this->assertSame('failed', $failedRefund->status);
        $this->assertSame('Xendit connection timeout occurred', $failedRefund->failure_reason);
    }
}

