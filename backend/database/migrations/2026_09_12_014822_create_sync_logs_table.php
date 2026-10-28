<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_batch_id')->constrained('sync_batches')->cascadeOnDelete();
            $table->string('entity', 40); // e.g. "item"
            $table->string('source_id', 60); // Accurate's ITEMNO for this row
            $table->enum('action', ['INSERT', 'UPDATE', 'SKIP', 'ERROR']);
            $table->enum('status', ['SUCCESS', 'FAILED']);
            $table->text('message')->nullable();
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sync_batch_id', 'action']);
            $table->index(['entity', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
