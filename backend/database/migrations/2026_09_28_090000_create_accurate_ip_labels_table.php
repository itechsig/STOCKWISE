<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accurate Online page: who uses which IP (user name, division, note), so a PC
 * shows its user as soon as it connects to Accurate. STOCKWISE-only data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accurate_ip_labels', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('user_name', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_ip_labels');
    }
};
