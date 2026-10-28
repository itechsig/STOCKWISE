<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('global');
            $table->decimal('lead_time_threshold', 8, 2);
            $table->decimal('median_deficit', 12, 2);
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('tidak_aman_count');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('computed_at');
            $table->timestamps();
        });

        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->cascadeOnDelete(); // null = agregat semua gudang
            $table->foreignId('analysis_run_id')->nullable()->constrained('inventory_analysis_runs')->nullOnDelete();
            $table->decimal('actual', 14, 2)->default(0);
            $table->decimal('reserved', 14, 2)->default(0);
            $table->decimal('available', 14, 2)->default(0);
            $table->boolean('stock_known')->default(false);
            $table->decimal('safety_stock', 12, 2)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('selisih', 14, 2)->default(0);
            $table->string('status', 12)->default('AMAN');       // AMAN/TIDAK_AMAN/BEP
            $table->decimal('deficit', 14, 2)->default(0);
            $table->decimal('priority_score', 12, 2)->default(0);
            $table->string('priority_level', 8)->default('LOW');  // LOW/MEDIUM/HIGH
            $table->string('recommendation')->nullable();
            $table->decimal('recommended_qty', 12, 2)->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
            $table->index(['status', 'priority_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
        Schema::dropIfExists('inventory_analysis_runs');
    }
};
