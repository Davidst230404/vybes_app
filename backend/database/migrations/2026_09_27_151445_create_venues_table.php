<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('merchant_id')
                ->constrained('merchants')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('name', 150);

            $table->string('slug', 180)->unique();

            $table->text('description')->nullable();

            $table->string('phone', 30)->nullable();

            $table->text('address');

            $table->string('city', 100);

            $table->string('province', 100);

            $table->decimal('latitude', 10, 7)->nullable();

            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('cover_image')->nullable();

            $table->string('status', 30)
                ->default('draft');

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};