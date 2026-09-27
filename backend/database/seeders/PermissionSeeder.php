<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // User
            ['name' => 'user.view', 'display_name' => 'View Users', 'description' => 'Melihat data user.'],
            ['name' => 'user.update', 'display_name' => 'Update Users', 'description' => 'Mengubah data user.'],
            ['name' => 'user.manage', 'display_name' => 'Manage Users', 'description' => 'Mengelola user secara penuh.'],

            // Venue
            ['name' => 'venue.view', 'display_name' => 'View Venues', 'description' => 'Melihat venue.'],
            ['name' => 'venue.create', 'display_name' => 'Create Venues', 'description' => 'Membuat venue.'],
            ['name' => 'venue.update', 'display_name' => 'Update Venues', 'description' => 'Mengubah venue.'],
            ['name' => 'venue.delete', 'display_name' => 'Delete Venues', 'description' => 'Menghapus venue.'],

            // Resource
            ['name' => 'resource.view', 'display_name' => 'View Resources', 'description' => 'Melihat resource venue.'],
            ['name' => 'resource.create', 'display_name' => 'Create Resources', 'description' => 'Membuat resource venue.'],
            ['name' => 'resource.update', 'display_name' => 'Update Resources', 'description' => 'Mengubah resource venue.'],
            ['name' => 'resource.delete', 'display_name' => 'Delete Resources', 'description' => 'Menghapus resource venue.'],

            // Schedule
            ['name' => 'schedule.view', 'display_name' => 'View Schedules', 'description' => 'Melihat jadwal.'],
            ['name' => 'schedule.create', 'display_name' => 'Create Schedules', 'description' => 'Membuat jadwal.'],
            ['name' => 'schedule.update', 'display_name' => 'Update Schedules', 'description' => 'Mengubah jadwal.'],
            ['name' => 'schedule.delete', 'display_name' => 'Delete Schedules', 'description' => 'Menghapus jadwal.'],

            // Booking
            ['name' => 'booking.view', 'display_name' => 'View Bookings', 'description' => 'Melihat booking.'],
            ['name' => 'booking.create', 'display_name' => 'Create Bookings', 'description' => 'Membuat booking.'],
            ['name' => 'booking.update', 'display_name' => 'Update Bookings', 'description' => 'Mengubah booking.'],
            ['name' => 'booking.cancel', 'display_name' => 'Cancel Bookings', 'description' => 'Membatalkan booking.'],
            ['name' => 'booking.manage', 'display_name' => 'Manage Bookings', 'description' => 'Mengelola booking secara penuh.'],

            // Payment
            ['name' => 'payment.view', 'display_name' => 'View Payments', 'description' => 'Melihat informasi pembayaran.'],
            ['name' => 'payment.manage', 'display_name' => 'Manage Payments', 'description' => 'Mengelola pembayaran.'],

            // Ticket
            ['name' => 'ticket.view', 'display_name' => 'View Tickets', 'description' => 'Melihat tiket.'],
            ['name' => 'ticket.create', 'display_name' => 'Create Tickets', 'description' => 'Membuat tiket.'],
            ['name' => 'ticket.checkin', 'display_name' => 'Check-in Tickets', 'description' => 'Melakukan check-in tiket.'],

            // Event
            ['name' => 'event.view', 'display_name' => 'View Events', 'description' => 'Melihat event.'],
            ['name' => 'event.create', 'display_name' => 'Create Events', 'description' => 'Membuat event.'],
            ['name' => 'event.update', 'display_name' => 'Update Events', 'description' => 'Mengubah event.'],
            ['name' => 'event.delete', 'display_name' => 'Delete Events', 'description' => 'Menghapus event.'],

            // Admin
            ['name' => 'admin.manage', 'display_name' => 'Manage System', 'description' => 'Mengelola sistem VYBES.'],
        ];

        foreach ($permissions as &$permission) {
            $permission['created_at'] = now();
            $permission['updated_at'] = now();
        }

        DB::table('permissions')->upsert(
            $permissions,
            ['name'],
            ['display_name', 'description', 'updated_at']
        );

        $rolePermissions = [
            'customer' => [
                'venue.view',
                'resource.view',
                'schedule.view',
                'booking.view',
                'booking.create',
                'booking.update',
                'booking.cancel',
                'payment.view',
                'ticket.view',
            ],

            'merchant' => [
                'venue.view',
                'venue.create',
                'venue.update',
                'venue.delete',
                'resource.view',
                'resource.create',
                'resource.update',
                'resource.delete',
                'schedule.view',
                'schedule.create',
                'schedule.update',
                'schedule.delete',
                'booking.view',
                'booking.update',
                'booking.manage',
                'payment.view',
                'ticket.view',
                'ticket.checkin',
            ],

            'organizer' => [
                'event.view',
                'event.create',
                'event.update',
                'event.delete',
                'ticket.view',
                'ticket.create',
                'ticket.checkin',
                'booking.view',
                'booking.manage',
                'payment.view',
            ],

            'admin' => [
                'user.view',
                'user.update',
                'user.manage',
                'venue.view',
                'venue.create',
                'venue.update',
                'venue.delete',
                'resource.view',
                'resource.create',
                'resource.update',
                'resource.delete',
                'schedule.view',
                'schedule.create',
                'schedule.update',
                'schedule.delete',
                'booking.view',
                'booking.create',
                'booking.update',
                'booking.cancel',
                'booking.manage',
                'payment.view',
                'payment.manage',
                'ticket.view',
                'ticket.create',
                'ticket.checkin',
                'event.view',
                'event.create',
                'event.update',
                'event.delete',
                'admin.manage',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = DB::table('roles')
                ->where('name', $roleName)
                ->first();

            if (!$role) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permission = DB::table('permissions')
                    ->where('name', $permissionName)
                    ->first();

                if (!$permission) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ]);
            }
        }
    }
}