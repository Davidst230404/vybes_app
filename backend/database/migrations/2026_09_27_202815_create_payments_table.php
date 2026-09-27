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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();

            $table->foreignId('payment_session_id')
                ->nullable()
                ->constrained('payment_sessions')
                ->nullOnDelete();

            $table->string('payment_code', 50)
                ->unique();

            $table->string('provider', 50)
                ->nullable();

            $table->string('provider_transaction_id', 150)
                ->nullable()
                ->unique();

            $table->string('method', 50)
                ->nullable();

            $table->string('status', 30)
                ->default('pending');

            $table->decimal('amount', 15, 2);

            $table->timestamp('paid_at')->nullable();

            $table->timestamp('failed_at')->nullable();

            $table->text('failure_reason')->nullable();

            $table->json('provider_payload')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['payment_session_id', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};