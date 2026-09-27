<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->string('booking_code', 30)->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('venue_id')
                ->constrained('venues')
                ->restrictOnDelete();

            $table->string('status', 30)
                ->default('pending');

            $table->timestamp('starts_at');

            $table->timestamp('ends_at');

            $table->unsignedInteger('quantity')
                ->default(1);

            $table->decimal('subtotal', 15, 2)
                ->default(0);

            $table->decimal('total_amount', 15, 2)
                ->default(0);

            $table->timestamp('hold_expires_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['venue_id', 'status']);
            $table->index(['starts_at', 'ends_at']);
            $table->index('hold_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};