<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
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
}
