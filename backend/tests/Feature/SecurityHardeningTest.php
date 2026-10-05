<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a role for the test environment.
     */
    private function createRole(string $name): Role
    {
        return Role::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'description' => $name,
        ]);
    }

    /**
     * Create a test user with the given role.
     */
    private function createUserWithRole(
        string $roleName
    ): User {
        $role = Role::where(
            'name',
            $roleName
        )->first()
            ?? $this->createRole(
                $roleName
            );

        return User::create([
            'name' =>
                ucfirst($roleName) .
                ' Test',

            'email' =>
                $roleName .
                '@security.test',

            'password' =>
                'password123',

            'role_id' =>
                $role->id,
        ]);
    }

    /**
     * Customer must not access organizer event APIs.
     */
    public function test_customer_cannot_access_organizer_event_api(): void
    {
        $user = $this->createUserWithRole(
            'customer'
        );

        $this->actingAs($user)
            ->getJson(
                '/api/organizer/events'
            )
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Only organizers can manage events.'
            );
    }

    /**
     * Organizer with pending status must not create events.
     */
    public function test_pending_organizer_cannot_create_event(): void
    {
        $user = $this->createUserWithRole(
            'organizer'
        );

        Organizer::create([
            'user_id' =>
                $user->id,

            'organization_name' =>
                'Pending Organizer',

            'status' =>
                'pending',
        ]);

        $this->actingAs($user)
            ->postJson(
                '/api/organizer/events',
                [
                    'title' =>
                        'Security Test Event',

                    'starts_at' =>
                        now()
                            ->addDay()
                            ->toISOString(),

                    'ends_at' =>
                        now()
                            ->addDay()
                            ->addHour()
                            ->toISOString(),
                ]
            )
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Organizer account is not approved.'
            );
    }

    /**
     * Client must not be able to force a newly-created
     * organizer event directly into published state.
     */
    public function test_organizer_cannot_publish_event_by_setting_status_in_request(): void
    {
        $user = $this->createUserWithRole(
            'organizer'
        );

        Organizer::create([
            'user_id' =>
                $user->id,

            'organization_name' =>
                'Approved Organizer',

            'status' =>
                'approved',
        ]);

        $response = $this->actingAs($user)
            ->postJson(
                '/api/organizer/events',
                [
                    'title' =>
                        'Security Test Event',

                    'starts_at' =>
                        now()
                            ->addDay()
                            ->toISOString(),

                    'ends_at' =>
                        now()
                            ->addDay()
                            ->addHour()
                            ->toISOString(),

                    /*
                     * Malicious / unauthorized status injection.
                     */
                    'status' =>
                        'published',
                ]
            )
            ->assertCreated();

        $eventId =
            $response->json(
                'data.id'
            );

        /*
         * Server must ignore the injected status.
         */
        $this->assertSame(
            'draft',
            $response->json(
                'data.status'
            )
        );

        $this->assertDatabaseHas(
            'events',
            [
                'id' =>
                    $eventId,

                'status' =>
                    'draft',
            ]
        );
    }

    /**
     * Client must not be able to modify the status
     * of an existing event through the normal update endpoint.
     */
    public function test_organizer_cannot_change_existing_event_status_via_update(): void
    {
        $user = $this->createUserWithRole(
            'organizer'
        );

        $organizer = Organizer::create([
            'user_id' =>
                $user->id,

            'organization_name' =>
                'Approved Organizer',

            'status' =>
                'approved',
        ]);

        $event = Event::create([
            'organizer_id' =>
                $organizer->id,

            'title' =>
                'Existing Event',

            'slug' =>
                'existing-event',

            'starts_at' =>
                now()->addDay(),

            'ends_at' =>
                now()
                    ->addDay()
                    ->addHour(),

            'status' =>
                'draft',
        ]);

        $this->actingAs($user)
            ->putJson(
                '/api/organizer/events/' .
                $event->id,
                [
                    /*
                     * Attempt to bypass event lifecycle.
                     */
                    'status' =>
                        'published',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.status',
                'draft'
            );

        $this->assertDatabaseHas(
            'events',
            [
                'id' =>
                    $event->id,

                'status' =>
                    'draft',
            ]
        );
    }

    /**
     * Webhook data must be an array/object.
     */
    public function test_xendit_webhook_requires_array_data(): void
    {
        Config::set(
            'services.xendit.webhook_token',
            'security-test-token'
        );

        $this->postJson(
            '/api/webhooks/xendit',
            [
                'event' =>
                    'payment.capture',

                'data' =>
                    'not-an-array',
            ],
            [
                'x-callback-token' =>
                    'security-test-token',
            ]
        )->assertStatus(422);
    }

    /**
 * Unknown webhook events must never reach
 * unsupported business logic.
 */
public function test_xendit_webhook_ignores_unknown_events(): void
{
    Config::set(
        'services.xendit.webhook_token',
        'security-test-token'
    );

    $this->postJson(
        '/api/webhooks/xendit',
        [
            /*
             * Event sengaja dibuat tidak didukung,
             * tetapi payload tetap valid.
             */
            'event' => 'future.unknown.event',

            /*
             * Harus berupa array yang TIDAK kosong,
             * karena Laravel "required|array" menolak [].
             */
            'data' => [
                'test' => true,
            ],
        ],
        [
            'x-callback-token' => 'security-test-token',
        ]
    )
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Webhook event ignored.'
        );
}
}