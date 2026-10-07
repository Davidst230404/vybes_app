<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
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
            'email' => $roleName . '@audit-test.local',
            'password' => 'password123',
            'role_id' => $role->id,
        ]);
    }

    /**
     * Unauthenticated user receives 401 when accessing audit logs.
     */
    public function test_unauthenticated_user_cannot_read_audit(): void
    {
        $this->getJson('/api/admin/audit')->assertUnauthorized();
    }

    /**
     * Authenticated non-admin user receives 403 when accessing audit logs.
     */
    public function test_non_admin_cannot_read_audit(): void
    {
        $customer = $this->createUserWithRole('customer');

        $this->actingAs($customer)
            ->getJson('/api/admin/audit')
            ->assertForbidden();
    }

    /**
     * Admin can read audit logs with pagination and filters.
     */
    public function test_admin_can_read_audit_logs(): void
    {
        $admin = $this->createUserWithRole('admin');

        AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'test.action',
            'entity_type' => 'TestEntity',
            'entity_id' => '123',
            'metadata' => [
                'before' => ['status' => 'draft'],
                'after' => ['status' => 'active'],
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/audit')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'actor_id',
                    'action',
                    'entity_type',
                    'entity_id',
                    'metadata',
                    'before',
                    'after',
                    'ip_address',
                    'created_at',
                ],
            ],
            'meta',
            'links',
        ]);

        $this->assertSame('test.action', $response->json('data.0.action'));
    }

    /**
     * Admin actions create audit log records with before/after state.
     */
    public function test_admin_actions_create_audit_logs(): void
    {
        $admin = $this->createUserWithRole('admin');

        // Create a category
        $createResponse = $this->actingAs($admin)
            ->postJson('/api/admin/categories', [
                'name' => 'Pilates Studio',
                'description' => 'Reformer pilates',
            ])
            ->assertCreated();

        $categoryId = $createResponse->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'category.create',
            'entity_type' => 'category',
            'entity_id' => (string) $categoryId,
            'actor_id' => $admin->id,
        ]);

        // Update the category
        $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$categoryId}", [
                'name' => 'Pilates & Yoga Studio',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'category.update',
            'entity_type' => 'category',
            'entity_id' => (string) $categoryId,
            'actor_id' => $admin->id,
        ]);
    }

    /**
     * AuditLogger redacts sensitive credentials and secrets.
     */
    public function test_audit_logger_redacts_sensitive_credentials(): void
    {
        $admin = $this->createUserWithRole('admin');

        AuditLogger::record(
            actor: $admin,
            action: 'user.password_reset',
            entityType: 'user',
            entityId: 99,
            before: ['password' => 'secret_hash_1', 'token' => 'plain_text_token'],
            after: ['password' => 'secret_hash_2', 'token' => 'new_plain_token', 'safe_field' => 'ok']
        );

        $log = AuditLog::where('action', 'user.password_reset')->first();
        $this->assertNotNull($log);

        $this->assertSame('[REDACTED]', $log->metadata['before']['password']);
        $this->assertSame('[REDACTED]', $log->metadata['before']['token']);
        $this->assertSame('[REDACTED]', $log->metadata['after']['password']);
        $this->assertSame('[REDACTED]', $log->metadata['after']['token']);
        $this->assertSame('ok', $log->metadata['after']['safe_field']);
    }

    /**
     * Audit log endpoint is read-only; mutations are not permitted.
     */
    public function test_audit_log_endpoint_is_read_only(): void
    {
        $admin = $this->createUserWithRole('admin');

        // POST /api/admin/audit should not exist (405 or 404)
        $this->actingAs($admin)
            ->postJson('/api/admin/audit', ['action' => 'fake'])
            ->assertStatus(405);

        // DELETE /api/admin/audit/1 should not exist
        $this->actingAs($admin)
            ->deleteJson('/api/admin/audit/1')
            ->assertStatus(404);
    }

    /**
     * AuditLog records cannot be updated at model level.
     */
    public function test_audit_log_records_cannot_be_updated_at_model_level(): void
    {
        $log = AuditLog::create([
            'action' => 'immutable.test',
            'entity_type' => 'test',
            'entity_id' => '1',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AuditLog records are append-only and cannot be updated.');

        $log->update(['action' => 'tampered.action']);
    }

    /**
     * AuditLog records cannot be deleted at model level.
     */
    public function test_audit_log_records_cannot_be_deleted_at_model_level(): void
    {
        $log = AuditLog::create([
            'action' => 'immutable.test',
            'entity_type' => 'test',
            'entity_id' => '1',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AuditLog records are immutable and cannot be deleted.');

        $log->delete();
    }
}
