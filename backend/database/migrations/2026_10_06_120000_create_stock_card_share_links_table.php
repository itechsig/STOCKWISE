<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu Stock share links: anyone holding the link of a project can open its cards
 * without an account — to look (VIEW), to fill in (EDIT) or with full access (FULL).
 * Deleting the link takes the access away. Lines written through a link remember it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_card_share_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('token', 64)->unique();
            $t->string('access', 10);                 // VIEW / EDIT / FULL
            $t->string('label', 100)->nullable();     // e.g. "Teknisi lapangan"
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        Schema::table('stock_card_entries', function (Blueprint $t) {
            $t->foreignId('share_link_id')->nullable()->after('created_by')->constrained('stock_card_share_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_card_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId('share_link_id'));
        Schema::dropIfExists('stock_card_share_links');
    }
};
