<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Payment Sessions
        |--------------------------------------------------------------------------
        */

        Schema::table('payment_sessions', function (Blueprint $table) {
            // Remove existing booking foreign key temporarily
            $table->dropForeign(['booking_id']);
        });

        Schema::table('payment_sessions', function (Blueprint $table) {
            // Booking is optional because payment session can belong
            // to either a booking or an event ticket order.
            $table->foreignId('booking_id')
                ->nullable()
                ->change();

            // Event ticket payment session
            $table->foreignId('event_ticket_order_id')
                ->nullable()
                ->unique()
                ->after('booking_id')
                ->constrained('event_ticket_orders')
                ->cascadeOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Payments
        |--------------------------------------------------------------------------
        */

        Schema::table('payments', function (Blueprint $table) {
            // Remove existing booking foreign key temporarily
            $table->dropForeign(['booking_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            // Booking is optional because payment can belong
            // to either a booking or an event ticket order.
            $table->foreignId('booking_id')
                ->nullable()
                ->change();

            // Event ticket payment
            $table->foreignId('event_ticket_order_id')
                ->nullable()
                ->after('booking_id')
                ->constrained('event_ticket_orders')
                ->cascadeOnDelete();

            $table->index('event_ticket_order_id');
        });
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Payments
        |--------------------------------------------------------------------------
        */

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['event_ticket_order_id']);
            $table->dropIndex(['event_ticket_order_id']);
            $table->dropColumn('event_ticket_order_id');

            $table->dropForeign(['booking_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('booking_id')
                ->nullable(false)
                ->change();

            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->cascadeOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Payment Sessions
        |--------------------------------------------------------------------------
        */

        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->dropForeign(['event_ticket_order_id']);
            $table->dropUnique(['event_ticket_order_id']);
            $table->dropColumn('event_ticket_order_id');

            $table->dropForeign(['booking_id']);
        });

        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->foreignId('booking_id')
                ->nullable(false)
                ->change();

            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->cascadeOnDelete();
        });
    }
};