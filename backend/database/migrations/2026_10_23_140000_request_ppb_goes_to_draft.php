<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request Barang: a line decided PPB (no stock) no longer becomes a PPB straight away —
 * it goes into a Draft PPB to review first (PpbDraftService); only the NPBG lines go through
 * the approval and the serah terima. When the draft is submitted, the request line gets
 * the PPB it became (item_request_lines.ppb_id), through ppb_draft_lines.item_request_line_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppb_drafts', function (Blueprint $table) {
            $table->foreignId('item_request_id')->nullable()->after('source')->constrained('item_requests')->nullOnDelete();
        });
        Schema::table('ppb_draft_lines', function (Blueprint $table) {
            $table->foreignId('item_request_line_id')->nullable()->after('item_id')->constrained('item_request_lines')->nullOnDelete();
        });
        Schema::table('item_request_lines', function (Blueprint $table) {
            $table->foreignId('ppb_draft_id')->nullable()->after('ppb_id')->constrained('ppb_drafts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('item_request_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('ppb_draft_id'));
        Schema::table('ppb_draft_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('item_request_line_id'));
        Schema::table('ppb_drafts', fn (Blueprint $table) => $table->dropConstrainedForeignId('item_request_id'));
    }
};
