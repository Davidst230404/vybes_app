<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Organizer;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'email' => $roleName . '@admin-test.local',
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
                    'total_bookings',
                    'confirmed_bookings',
                    'total_venues',
                    'total_events',
                    'total_revenue',
                    'total_refunds',
                    'pending_approvals',
                ],
                'pending_breakdown' => [
                    'merchants',
                    'organizers',
                ],
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
}
