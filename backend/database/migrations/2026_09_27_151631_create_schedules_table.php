<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('day_of_week');

            $table->time('start_time');

            $table->time('end_time');

            $table->boolean('is_available')
                ->default(true);

            $table->timestamps();

            $table->unique([
                'resource_id',
                'day_of_week',
                'start_time',
                'end_time',
            ]);

            $table->index([
                'resource_id',
                'day_of_week',
                'is_available',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};