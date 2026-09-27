<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();

            $table->foreignId('resource_id')
                ->constrained('resources')
                ->restrictOnDelete();

            $table->timestamp('starts_at');

            $table->timestamp('ends_at');

            $table->unsignedInteger('quantity')
                ->default(1);

            $table->decimal('unit_price', 15, 2)
                ->default(0);

            $table->decimal('subtotal', 15, 2)
                ->default(0);

            $table->timestamps();

            $table->index([
                'resource_id',
                'starts_at',
                'ends_at',
            ]);

            $table->index([
                'booking_id',
                'resource_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};