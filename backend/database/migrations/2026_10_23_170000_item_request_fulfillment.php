<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request Barang as the parent transaction (App\Services\ItemRequestFulfillmentService):
 *  - per line: the approved quantity (Manager / Direktur may lower it), how much was handed over,
 *    and a closing of what is left (with reason);
 *  - fulfillments: each time Gudang sets how much it gives (from real stock) it makes one NPBG
 *    (#1, #2 = NPBG lanjutan …) and later one serah terima (photo + signature + quantities).
 * Existing requests are all finished: their lines are marked accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // guarded: a first run on MySQL stopped half-way (DDL is not transactional) — a re-run finishes it
        if (! Schema::hasColumn('item_request_lines', 'approved_qty')) {
            Schema::table('item_request_lines', function (Blueprint $table) {
                $table->decimal('approved_qty', 14, 2)->nullable()->after('qty');
                $table->decimal('fulfilled_qty', 14, 2)->default(0)->after('approved_qty');
                $table->timestamp('closed_at')->nullable();
                $table->string('closed_reason', 255)->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('item_requests', 'npbg_pelanggan')) {
            Schema::table('item_requests', function (Blueprint $table) {
                // NPBG header the admin gives; Gudang's NPBG (#1, #2 …) are made with it
                $table->string('npbg_pelanggan', 200)->nullable();
                $table->string('npbg_tipe', 20)->nullable();
            });
        }

        if (! Schema::hasTable('item_request_fulfillments')) {
            Schema::create('item_request_fulfillments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_request_id')->constrained('item_requests')->cascadeOnDelete();
                $table->unsignedSmallInteger('seq');
                // DISIAPKAN (NPBG made, goods being prepared) / DISERAHKAN
                $table->string('status', 12)->default('DISIAPKAN');
                $table->string('no_npbg', 60)->nullable();
                $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('prepared_at')->nullable();
                $table->foreignId('handed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('handed_at')->nullable();
                $table->string('received_by_name', 150)->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->unique(['item_request_id', 'seq']);
            });
        }
        Schema::dropIfExists('item_request_fulfillment_lines'); // half-made by that first run (no rows yet)
        Schema::create('item_request_fulfillment_lines', function (Blueprint $table) {
            $table->id();
            // MySQL names are ≤ 64 characters: the default one for this key is longer
            $table->foreignId('item_request_fulfillment_id')->constrained('item_request_fulfillments', 'id', 'irf_lines_fulfillment_fk')->cascadeOnDelete();
            $table->foreignId('item_request_line_id')->constrained('item_request_lines')->cascadeOnDelete();
            $table->decimal('qty', 14, 2);
            $table->decimal('handed_qty', 14, 2)->nullable();
            $table->foreignId('npbg_id')->nullable()->constrained('npbg')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamps();
        });

        // every existing request is finished: approved = asked; a finished request's goods count as
        // given, anything else of it as closed (the old flow had no partial fulfilment)
        DB::table('item_request_lines')->whereIn('decision', ['NPBG', 'PPB'])->update(['approved_qty' => DB::raw('qty')]);
        $done = DB::table('item_requests')->where('status', 'SELESAI')->pluck('id');
        DB::table('item_request_lines')->whereIn('item_request_id', $done)->where('decision', 'NPBG')->update(['fulfilled_qty' => DB::raw('qty')]);
        DB::table('item_request_lines')->whereIn('item_request_id', $done)->where('decision', 'PPB')
            ->update(['closed_at' => now(), 'closed_reason' => 'Alur lama: diteruskan ke pembelian']);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_request_fulfillment_lines');
        Schema::dropIfExists('item_request_fulfillments');
        Schema::table('item_requests', fn (Blueprint $t) => $t->dropColumn(['npbg_pelanggan', 'npbg_tipe']));
        Schema::table('item_request_lines', function (Blueprint $t) {
                $t->dropConstrainedForeignId('closed_by');
                $t->dropColumn(['approved_qty', 'fulfilled_qty', 'closed_at', 'closed_reason']);
            });
    }
};
