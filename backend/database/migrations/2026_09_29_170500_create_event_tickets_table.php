<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_ticket_order_id')
                ->constrained('event_ticket_orders')
                ->cascadeOnDelete();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->string('ticket_code', 30)->unique();

            $table->string('status', 30)->default('active');

            $table->text('qr_payload');

            $table->timestamp('issued_at')->nullable();

            $table->timestamp('checked_in_at')->nullable();

            $table->timestamps();

            $table->index('event_ticket_order_id');
            $table->index('event_id');
            $table->index('status');
            $table->index('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tickets');
    }
};