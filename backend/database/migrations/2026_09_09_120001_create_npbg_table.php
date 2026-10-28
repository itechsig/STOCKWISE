<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npbg', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('prefix', 12)->default('NA');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('sequence');
            $table->date('date');
            $table->string('type', 15)->default('NON_PENJUALAN'); // PENJUALAN / NON_PENJUALAN
            $table->string('classification', 25)->default('UMUM');
            $table->foreignId('material_request_id')->nullable()->constrained()->nullOnDelete();
            // customers/projects/assets masters land in PHASE 8 — keep as loose refs for now
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->string('project_name', 200)->nullable();
            $table->string('asset_ref', 100)->nullable();      // Nopol / No Seri
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 150)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            // DRAFT / PREPARING / READY_TO_PICKUP / PICKED_UP / COMPLETED / CANCELLED
            $table->string('status', 20)->default('DRAFT')->index();
            $table->string('signature_path')->nullable();
            $table->string('picked_up_by', 150)->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['prefix', 'year', 'month', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npbg');
    }
};
