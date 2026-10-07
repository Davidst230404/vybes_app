<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
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
            'email' => $roleName . '@settings-test.local',
            'password' => 'password123',
            'role_id' => $role->id,
        ]);
    }

    /**
     * Unauthenticated user cannot access platform settings.
     */
    public function test_unauthenticated_user_receives_401(): void
    {
        $this->getJson('/api/admin/settings')->assertUnauthorized();
        $this->patchJson('/api/admin/settings', ['booking_hold_duration_minutes' => 20])->assertUnauthorized();
    }

    /**
     * Authenticated non-admin user receives 403.
     */
    public function test_authenticated_non_admin_receives_403(): void
    {
        $customer = $this->createUserWithRole('customer');

        $this->actingAs($customer)
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $this->actingAs($customer)
            ->patchJson('/api/admin/settings', ['booking_hold_duration_minutes' => 20])
            ->assertForbidden();
    }

    /**
     * Admin can view platform settings with default booking hold duration.
     */
    public function test_admin_can_read_default_settings(): void
    {
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk();

        $response->assertJsonPath('data.0.key', 'booking_hold_duration_minutes')
            ->assertJsonPath('data.0.value', 15)
            ->assertJsonPath('data.0.type', 'integer');
    }

    /**
     * Admin can update booking hold duration within valid boundaries.
     */
    public function test_admin_can_update_booking_hold_duration(): void
    {
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)
            ->patchJson('/api/admin/settings', [
                'booking_hold_duration_minutes' => 30,
            ])
            ->assertOk();

        $response->assertJsonPath('data.0.value', 30);

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'booking_hold_duration_minutes',
            'value' => '30',
        ]);

        // Runtime consumer check
        $this->assertSame(30, PlatformSetting::get('booking_hold_duration_minutes'));
    }

    /**
     * Validation rejects invalid booking hold duration values.
     */
    public function test_validation_rejects_invalid_values(): void
    {
        $admin = $this->createUserWithRole('admin');

        // Below minimum (min: 1)
        $this->actingAs($admin)
            ->patchJson('/api/admin/settings', [
                'booking_hold_duration_minutes' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking_hold_duration_minutes']);

        // Above maximum (max: 1440)
        $this->actingAs($admin)
            ->patchJson('/api/admin/settings', [
                'booking_hold_duration_minutes' => 2000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking_hold_duration_minutes']);

        // Non-integer
        $this->actingAs($admin)
            ->patchJson('/api/admin/settings', [
                'booking_hold_duration_minutes' => 'fifteen',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking_hold_duration_minutes']);
    }

    /**
     * PlatformSetting::get returns default value when key does not exist.
     */
    public function test_missing_setting_returns_default_fallback(): void
    {
        $this->assertSame(45, PlatformSetting::get('non_existent_key', 45));
        $this->assertNull(PlatformSetting::get('non_existent_key'));
    }
}
