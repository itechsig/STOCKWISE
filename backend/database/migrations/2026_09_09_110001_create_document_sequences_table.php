<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 10);          // REQ, NPBG, PPB, PO, RI, SO, ...
            $table->string('prefix', 12);            // NA, ATK, NV, site code, ...
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['doc_type', 'prefix', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
