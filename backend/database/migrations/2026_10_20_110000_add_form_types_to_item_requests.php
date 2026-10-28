<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request Barang comes in several forms (App\Support\ItemRequestForms): ATK
 * (with urgency), Maintenance General, Distribusi, Manufaktur & Assembly. A
 * share link opens exactly one form. Additive only — requests and links made
 * before keep working as the general form (UMUM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_request_links', function (Blueprint $table) {
            $table->string('form_type', 12)->default('UMUM')->after('label');
        });
        Schema::table('item_requests', function (Blueprint $table) {
            $table->string('form_type', 12)->default('UMUM')->after('source')->index();
            $table->string('urgency', 8)->nullable()->after('form_type'); // URGENT / NORMAL (ATK)
            $table->string('form_detail', 200)->nullable()->after('urgency'); // e.g. unit / mesin, armada, produk
        });
    }

    public function down(): void
    {
        Schema::table('item_requests', function (Blueprint $table) {
            $table->dropIndex(['form_type']);
            $table->dropColumn(['form_type', 'urgency', 'form_detail']);
        });
        Schema::table('item_request_links', fn (Blueprint $table) => $table->dropColumn('form_type'));
    }
};
