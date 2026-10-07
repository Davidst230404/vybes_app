<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->string('type', 30)->default('string'); // integer, string, boolean, json
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // Seed initial default platform setting per PRD BR-003
        DB::table('platform_settings')->insert([
            'key' => 'booking_hold_duration_minutes',
            'value' => '15',
            'type' => 'integer',
            'description' => 'Default hold duration for resource booking and ticket reservation in minutes (PRD BR-003).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
