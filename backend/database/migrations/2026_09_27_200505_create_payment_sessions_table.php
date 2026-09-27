<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings')
                ->cascadeOnDelete();

            $table->string('session_code', 50)->unique();

            $table->string('status', 30)
                ->default('active');

            $table->decimal('amount', 15, 2);

            $table->timestamp('started_at');

            $table->timestamp('expires_at');

            $table->timestamp('paid_at')->nullable();

            $table->timestamp('expired_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};