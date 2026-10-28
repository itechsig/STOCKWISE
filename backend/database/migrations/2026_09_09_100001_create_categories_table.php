<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 150);
            $table->unsignedTinyInteger('level');            // 1..4 (Induk / Anak 1..3)
            $table->string('path', 600)->index();            // "Induk > Anak1 > ..."
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
