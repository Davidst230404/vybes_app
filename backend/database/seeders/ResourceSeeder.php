<?php

namespace Database\Seeders;

use App\Models\Resource;
use App\Models\Venue;
use Illuminate\Database\Seeder;

class ResourceSeeder extends Seeder
{
    public function run(): void
    {
        $venue = Venue::where(
            'slug',
            'vybes-futsal-arena'
        )->first();

        if (!$venue) {
            $this->command->error(
                'Venue VYBES Futsal Arena tidak ditemukan.'
            );

            return;
        }

        $resources = [
            [
                'name' => 'Lapangan 1',
                'slug' => 'lapangan-1',
                'description' => 'Lapangan futsal utama.',
                'capacity' => 12,
                'type' => 'court',
                'status' => 'active',
            ],
            [
                'name' => 'Lapangan 2',
                'slug' => 'lapangan-2',
                'description' => 'Lapangan futsal kedua.',
                'capacity' => 12,
                'type' => 'court',
                'status' => 'active',
            ],
        ];

        foreach ($resources as $resource) {
            Resource::updateOrCreate(
                [
                    'venue_id' => $venue->id,
                    'slug' => $resource['slug'],
                ],
                [
                    'name' => $resource['name'],
                    'description' => $resource['description'],
                    'capacity' => $resource['capacity'],
                    'type' => $resource['type'],
                    'status' => $resource['status'],
                ]
            );
        }
    }
}