<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
       $this->call([
        RoleSeeder::class,
         PermissionSeeder::class,
         CategorySeeder::class,
         MerchantSeeder::class,
         VenueSeeder::class,
         ResourceSeeder::class,
         ScheduleSeeder::class,
         AdminUserSeeder::class,
        ]);
    }
}