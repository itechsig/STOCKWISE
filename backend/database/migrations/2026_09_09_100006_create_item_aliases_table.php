<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('alias_description', 400);
            $table->string('alias_normalized', 400)->index();
            $table->string('source', 60)->nullable();          // file/sheet the description came from
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('match_status', 15)->default('MANUAL'); // AUTO/MANUAL/PENDING
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['alias_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_aliases');
    }
};
