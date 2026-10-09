<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $customerRole = Role::where('name', 'customer')->first();
        $roleId = $customerRole ? $customerRole->id : 1;

        $demoUsers = [
            [
                'name' => 'David Pratama',
                'email' => 'david.pratama@example.com',
                'phone' => '081234567821',
                'status' => 'active',
                'created_at' => Carbon::create(2026, 9, 12, 10, 0, 0),
            ],
            [
                'name' => 'Sinta Maharani',
                'email' => 'sinta.maharani@example.com',
                'phone' => '081398765445',
                'status' => 'active',
                'created_at' => Carbon::create(2026, 9, 10, 14, 30, 0),
            ],
            [
                'name' => 'Raka Wijaya',
                'email' => 'raka.wijaya@example.com',
                'phone' => '085712345689',
                'status' => 'suspended',
                'created_at' => Carbon::create(2026, 9, 4, 9, 15, 0),
            ],
            [
                'name' => 'Nabila Putri',
                'email' => 'nabila.putri@example.com',
                'phone' => '082198765432',
                'status' => 'active',
                'created_at' => Carbon::create(2026, 8, 28, 16, 45, 0),
            ],
        ];

        foreach ($demoUsers as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'status' => $data['status'],
                    'password' => Hash::make('password123'),
                    'role_id' => $roleId,
                    'email_verified_at' => Carbon::now(),
                    'created_at' => $data['created_at'],
                    'updated_at' => $data['created_at'],
                ]
            );
        }
    }
}
