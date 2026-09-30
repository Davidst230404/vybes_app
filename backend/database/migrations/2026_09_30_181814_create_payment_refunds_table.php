<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->cascadeOnDelete();

            $table->string('provider', 50);

            $table->string('provider_refund_id')
                ->nullable()
                ->unique();

            $table->string('reference_id')
                ->unique();

            $table->decimal('amount', 15, 2);

            $table->string('currency', 3)
                ->default('IDR');

            $table->string('status', 30)
                ->default('pending');

            $table->string('reason', 50)
                ->nullable();

            $table->string('failure_code', 100)
                ->nullable();

            $table->text('failure_reason')
                ->nullable();

            $table->decimal('refund_fee_amount', 15, 2)
                ->nullable();

            $table->json('provider_payload')
                ->nullable();

            $table->timestamp('requested_at')
                ->nullable();

            $table->timestamp('succeeded_at')
                ->nullable();

            $table->timestamp('failed_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'payment_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};