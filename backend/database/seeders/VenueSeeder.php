<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VenueSeeder extends Seeder
{
    public function run(): void
    {
        $merchant = Merchant::where(
            'business_name',
            'VYBES Sports'
        )->first();

        if (!$merchant) {
            $this->command->error(
                'Merchant VYBES Sports tidak ditemukan.'
            );

            return;
        }

        $category = Category::where(
            'slug',
            'sports'
        )->first();

        if (!$category) {
            $this->command->error(
                'Category Sports tidak ditemukan.'
            );

            return;
        }

        Venue::updateOrCreate(
            [
                'slug' => 'vybes-futsal-arena',
            ],
            [
                'merchant_id' => $merchant->id,
                'category_id' => $category->id,
                'name' => 'VYBES Futsal Arena',
                'description' => 'Lapangan futsal untuk aktivitas olahraga dan booking komunitas.',
                'phone' => '081234567890',
                'address' => 'Jl. Contoh No. 10',
                'city' => 'Yogyakarta',
                'province' => 'DI Yogyakarta',
                'latitude' => -7.7956,
                'longitude' => 110.3695,
                'cover_image' => null,
                'status' => 'published',
            ]
        );
    }
}