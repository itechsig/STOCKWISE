<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_safety_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('source_category', 60)->nullable();   // SAFETY STOCK <sheet>
            $table->string('period_label', 30)->nullable();
            $table->decimal('avg_usage_1m', 12, 2)->nullable();
            $table->decimal('avg_usage_3m', 12, 2)->nullable();
            $table->decimal('avg_usage_6m', 12, 2)->nullable();
            $table->decimal('avg_usage_12m', 12, 2)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('sqrt_lt', 8, 4)->nullable();
            $table->decimal('safety_stock', 12, 2)->default(0);
            $table->decimal('min_pr', 12, 2)->nullable();
            $table->date('effective_date')->nullable();
            $table->boolean('is_effective')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'is_effective']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_safety_stocks');
    }
};
