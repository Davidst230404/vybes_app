<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Restaurant',
                'slug' => 'restaurant',
                'description' => 'Restaurant and dining experiences.',
                'icon' => 'restaurant',
                'sort_order' => 1,
            ],
            [
                'name' => 'Cafe',
                'slug' => 'cafe',
                'description' => 'Cafes and coffee shops.',
                'icon' => 'cafe',
                'sort_order' => 2,
            ],
            [
                'name' => 'Sports',
                'slug' => 'sports',
                'description' => 'Sports venues and activities.',
                'icon' => 'sports',
                'sort_order' => 3,
            ],
            [
                'name' => 'Live Music',
                'slug' => 'live-music',
                'description' => 'Live music and entertainment venues.',
                'icon' => 'music',
                'sort_order' => 4,
            ],
            [
                'name' => 'Event',
                'slug' => 'event',
                'description' => 'Events, workshops, and experiences.',
                'icon' => 'event',
                'sort_order' => 5,
            ],
            [
                'name' => 'Studio',
                'slug' => 'studio',
                'description' => 'Studios for creative and recreational activities.',
                'icon' => 'studio',
                'sort_order' => 6,
            ],
            [
                'name' => 'Coworking',
                'slug' => 'coworking',
                'description' => 'Coworking spaces and meeting rooms.',
                'icon' => 'coworking',
                'sort_order' => 7,
            ],
            [
                'name' => 'Karaoke',
                'slug' => 'karaoke',
                'description' => 'Karaoke rooms and entertainment.',
                'icon' => 'karaoke',
                'sort_order' => 8,
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'icon' => $category['icon'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                ]
            );
        }
    }
}