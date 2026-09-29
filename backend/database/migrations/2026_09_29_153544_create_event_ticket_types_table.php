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
        Schema::create('event_ticket_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->string('name', 100);
            $table->text('description')->nullable();

            $table->decimal('price', 15, 2)->default(0);

            $table->unsignedInteger('quota');
            $table->unsignedInteger('sold')->default(0);

            $table->dateTime('sales_starts_at')->nullable();
            $table->dateTime('sales_ends_at')->nullable();

            $table->string('status', 30)->default('active');

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['sales_starts_at', 'sales_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_ticket_types');
    }
};