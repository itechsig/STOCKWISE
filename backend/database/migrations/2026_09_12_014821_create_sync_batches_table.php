<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->string('sync_code', 40)->unique(); // e.g. SYNC-20260912-001
            $table->string('source', 40)->default('accurate');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->enum('status', ['PENDING', 'RUNNING', 'SUCCESS', 'PARTIAL', 'FAILED'])
                ->default('PENDING');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('inserted_records')->default(0);
            $table->unsignedInteger('updated_records')->default(0);
            $table->unsignedInteger('skipped_records')->default(0);
            $table->unsignedInteger('error_records')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_batches');
    }
};
