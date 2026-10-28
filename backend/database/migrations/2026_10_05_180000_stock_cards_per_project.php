<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project (e.g. a CCTV installation) holds many Kartu Stock — one per item.
 * Within a project: one card per item, card number (= item code by default)
 * unique. The same item may have a card in another project. A project with
 * cards cannot be deleted. Cards made before this (no project) stay, project null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_cards', function (Blueprint $t) {
            $t->foreignId('project_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });
        Schema::table('stock_cards', function (Blueprint $t) {
            $t->dropUnique(['card_number']);
            $t->unique(['project_id', 'card_number']);
            $t->unique(['project_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_cards', function (Blueprint $t) {
            $t->dropUnique(['project_id', 'item_id']);
            $t->dropUnique(['project_id', 'card_number']);
            $t->unique('card_number');
        });
        Schema::table('stock_cards', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
    }
};
