<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free "Catatan" a user can put on any row shown in a table (PPB, NPBG, RI,
 * tracking rows, …). Keyed by the table's entity name + the row key, so it
 * never touches the Accurate mirror rows themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_notes', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40);
            $table->string('entity_key', 80);
            $table->text('note');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entity', 'entity_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_notes');
    }
};
