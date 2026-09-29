<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organizer_id')
                ->constrained('organizers')
                ->cascadeOnDelete();

            $table->foreignId('venue_id')
                ->nullable()
                ->constrained('venues')
                ->nullOnDelete();

            $table->string('title', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();

            $table->string('cover_image')->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->string('status', 30)->default('draft');

            $table->timestamps();

            $table->index(['organizer_id', 'status']);
            $table->index(['venue_id', 'starts_at']);
            $table->index('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};