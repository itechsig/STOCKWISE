<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail for npbg_verifications — every state transition,
 * decision, and attachment is appended here and never updated/deleted (see
 * App\Models\NpbgVerificationLog::booted()). This is the record that proves
 * what happened, by whom, and when — required explicitly by the brief.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npbg_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npbg_verification_id')->constrained('npbg_verifications')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npbg_verification_logs');
    }
};
