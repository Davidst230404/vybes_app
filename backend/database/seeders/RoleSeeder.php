<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'customer',
                'display_name' => 'Customer',
                'description' => 'User yang menggunakan VYBES untuk menemukan dan melakukan booking.',
            ],
            [
                'name' => 'merchant',
                'display_name' => 'Merchant',
                'description' => 'Pengelola venue atau bisnis yang menyediakan layanan di VYBES.',
            ],
            [
                'name' => 'organizer',
                'display_name' => 'Event Organizer',
                'description' => 'Pengelola event dan tiket di VYBES.',
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Pengelola dan administrator sistem VYBES.',
            ],
        ];

        DB::table('roles')->upsert(
            $roles,
            ['name'],
            ['display_name', 'description']
        );
    }
}