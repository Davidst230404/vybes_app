<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();

        if (!$adminRole) {
            return;
        }

        User::updateOrCreate(
            ['email' => 'admin@vybes.test'],
            [
                'name' => 'VYBES Administrator',
                'password' => Hash::make('password123'),
                'role_id' => $adminRole->id,
            ]
        );
    }
}
