<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings')
                ->cascadeOnDelete();

            $table->string('ticket_code', 50)
                ->unique();

            $table->string('status', 30)
                ->default('active');

            $table->text('qr_payload');

            $table->timestamp('issued_at');

            $table->timestamp('checked_in_at')
                ->nullable();

            $table->timestamps();

            $table->index(['status', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_tickets');
    }
};