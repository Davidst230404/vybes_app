<?php

namespace Database\Seeders;

use App\Models\Resource;
use App\Models\Schedule;
use Illuminate\Database\Seeder;

class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $resources = Resource::whereIn('slug', [
            'lapangan-1',
            'lapangan-2',
        ])->get();

        if ($resources->isEmpty()) {
            $this->command->error(
                'Resource Lapangan 1 dan Lapangan 2 tidak ditemukan.'
            );

            return;
        }

        foreach ($resources as $resource) {
            for ($day = 0; $day <= 6; $day++) {
                Schedule::updateOrCreate(
                    [
                        'resource_id' => $resource->id,
                        'day_of_week' => $day,
                        'start_time' => '08:00:00',
                        'end_time' => '22:00:00',
                    ],
                    [
                        'is_available' => true,
                    ]
                );
            }
        }
    }
}