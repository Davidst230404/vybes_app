<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venue_id')
                ->constrained('venues')
                ->cascadeOnDelete();

            $table->string('name', 150);

            $table->string('slug', 180);

            $table->text('description')->nullable();

            $table->unsignedInteger('capacity')->nullable();

            $table->string('type', 50)->nullable();

            $table->string('status', 30)
                ->default('active');

            $table->timestamps();

            $table->unique(['venue_id', 'slug']);

            $table->index(['venue_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};