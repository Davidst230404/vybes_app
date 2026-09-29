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
        Schema::create('event_ticket_orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_code', 30)->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->foreignId('event_ticket_type_id')
                ->constrained('event_ticket_types')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            $table->decimal('unit_price', 15, 2);

            $table->decimal('total_amount', 15, 2);

            $table->string('status', 30)
                ->default('pending');

            $table->timestamp('hold_expires_at')
                ->nullable();

            $table->timestamp('confirmed_at')
                ->nullable();

            $table->timestamp('cancelled_at')
                ->nullable();

            $table->text('notes')
                ->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('event_id');
            $table->index('event_ticket_type_id');
            $table->index('status');
            $table->index('hold_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_ticket_orders');
    }
};