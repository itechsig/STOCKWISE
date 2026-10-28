<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('name_normalized', 150)->index();   // uppercased/trimmed for matching Excel
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role_hint', 40)->nullable();       // peminta / pemeriksa / petugas_gudang ...
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(true);    // seeded from Excel, unverified
            $table->string('source', 60)->nullable();          // which Excel file/sheet
            $table->timestamps();

            $table->unique(['name_normalized', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
