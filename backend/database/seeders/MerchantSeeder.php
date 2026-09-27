<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'david@vybes.test')->first();

        if (!$user) {
            $this->command->error(
                'User david@vybes.test tidak ditemukan.'
            );

            return;
        }

        $merchantRole = \App\Models\Role::where('name', 'merchant')->first();

        if (!$merchantRole) {
            $this->command->error(
                'Role merchant tidak ditemukan.'
            );

            return;
        }

        // Jadikan user sebagai merchant.
        $user->update([
            'role_id' => $merchantRole->id,
        ]);

        Merchant::updateOrCreate(
            [
                'user_id' => $user->id,
            ],
            [
                'business_name' => 'VYBES Sports',
                'phone' => '081234567890',
                'description' => 'Sports venue untuk berbagai aktivitas olahraga.',
                'status' => 'approved',
            ]
        );
    }
}