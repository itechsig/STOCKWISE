<?php

namespace Tests\Feature\Accurate;

use App\Models\Ppb;
use App\Support\PpbLineStatus as S;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** PPB line status = the "1. PPB - RI" Excel rule, on Accurate data. */
class PpbLineStatusTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_rule_matches_the_excel(): void
    {
        // the status Accurate holds …
        $this->assertSame(S::REQUESTED, S::of(15, 0, false), 'no RI yet');
        $this->assertSame(S::SHORTAGE, S::of(15, 10, false), '10 of 15 came, 5 to follow');
        $this->assertSame(S::COMPLETED, S::of(15, 15, false));
        $this->assertSame(S::COMPLETED, S::of(15, 16, true), 'more than asked is still complete');
        $this->assertSame(S::CLOSE, S::of(15, 10, true), 'closed after a partial RI');
        $this->assertSame(S::CLOSE, S::of(15, 0, true), 'closed with nothing received, no PO open');
        // Accurate closes a PPB once it is all on a PO — goods still on their way (PPB/NA/26/IX/032 → BL/D/26/IX/079 SENT)
        $this->assertSame(S::REQUESTED, S::of(1, 0, true, true), 'closed but its PO is still open → Requested');
        $this->assertSame(S::SHORTAGE, S::of(10, 4, true, true), 'closed, PO still open, part received → Shortage');
        $this->assertNull(S::amendSource(0, true, false, true), 'waiting on a PO is not Amend');
        // … and the Excel "Amend" next to it (user 2026-10-01: Amend + Requested)
        $this->assertSame(S::AMEND_NOTE, S::amendSource(0, false, true), 'listed in PPB AMEND (…), still open → Amend + Requested');
        $this->assertSame(S::AMEND_NOTE, S::amendSource(1, false, true), 'listed and something came → Amend + Completed/Shortage');
        $this->assertSame(S::AMEND_CLOSED, S::amendSource(0, true, false), 'closed, nothing received = not bought → Amend + Close');
        $this->assertNull(S::amendSource(10, true, false), 'closed after a partial RI is a plain Close');
        $this->assertNull(S::amendSource(0, false, false));

        $this->assertSame(['BSP.0727', 'SSP.0609', 'SSP.0522'],
            S::amendedCodes("HPP MATERIAL PROJECT INSTALASI RS. BHAYANGKARA SURABAYA\r\nPPB AMEND (BSP.0727 , SSP.0609 , SSP.0522)"));
        $this->assertSame([], S::amendedCodes('UNTUK STOK GUDANG'));
    }

    public function test_refresh_follows_the_po_of_the_line(): void
    {
        $base = ['accurate_seq' => 0, 'tgl_ppb' => '2026-09-28', 'kuantitas' => 1, 'satuan' => 'PCS', 'status' => 'CLOSED', 'qty_diterima' => 0];
        $onPo = Ppb::create($base + ['accurate_reqid' => 624, 'no_ppb' => 'PPB/NA/26/IX/032', 'kode_barang' => 'MAI.0828', 'qty_dipesan' => 1]);
        $never = Ppb::create($base + ['accurate_reqid' => 625, 'no_ppb' => 'PPB/NA/26/IX/033', 'kode_barang' => 'MAI.0001', 'qty_dipesan' => 0]);
        $poClosed = Ppb::create($base + ['accurate_reqid' => 626, 'no_ppb' => 'PPB/NA/26/IX/034', 'kode_barang' => 'MAI.0002', 'qty_dipesan' => 1]);
        $sent = \App\Models\PurchaseOrder::factory()->create(['status' => 'SENT']);
        $closed = \App\Models\PurchaseOrder::factory()->create(['status' => 'CLOSED']);
        \App\Models\PurchaseOrderItem::create(['purchase_order_id' => $sent->id, 'accurate_ppb_id' => $onPo->id, 'description_raw' => 'X', 'qty' => 1, 'qty_received' => 0]);
        \App\Models\PurchaseOrderItem::create(['purchase_order_id' => $closed->id, 'accurate_ppb_id' => $poClosed->id, 'description_raw' => 'X', 'qty' => 1, 'qty_received' => 0]);

        S::refreshAll();

        $this->assertSame(['REQUESTED', false], [$onPo->fresh()->line_status, (bool) $onPo->fresh()->is_amend], 'PO still SENT → waiting');
        $this->assertSame(['CLOSE', true, 'CLOSED'], [$never->fresh()->line_status, (bool) $never->fresh()->is_amend, $never->fresh()->amend_source], 'never on a PO');
        $this->assertSame(['CLOSE', true], [$poClosed->fresh()->line_status, (bool) $poClosed->fresh()->is_amend], 'PO closed empty');
        $this->assertSame(0, S::refreshAll()['updated'], 'idempotent');
    }

    public function test_sync_stores_it_and_the_list_filters_on_it(): void
    {
        Schema::dropIfExists('accurate_requisition');
        Schema::dropIfExists('accurate_requisitiondet');
        Schema::create('accurate_requisition', function ($t) {
            $t->integer('REQID')->nullable();
            $t->string('REQNO')->nullable();
            $t->date('REQDATE')->nullable();
            $t->boolean('ISCLOSED')->nullable();
            $t->string('DESCRIPTION')->nullable();
        });
        Schema::create('accurate_requisitiondet', function ($t) {
            $t->integer('REQID')->nullable();
            $t->integer('SEQ')->nullable();
            $t->string('ITEMNO')->nullable();
            $t->string('ITEMOVDESC')->nullable();
            foreach (['QUANTITY', 'QTYORDERED', 'QTYRECEIVED'] as $c) {
                $t->decimal($c, 14, 2)->nullable();
            }
            $t->string('ITEMUNIT')->nullable();
            $t->string('ITEMRESERVED3')->nullable();
            $t->string('NOTES')->nullable();
        });
        Process::fake();
        DB::table('accurate_requisition')->insert([
            ['REQID' => 1, 'REQNO' => 'PPB/NA/26/II/019', 'REQDATE' => '2026-02-10', 'ISCLOSED' => false, 'DESCRIPTION' => 'STOK'],
            ['REQID' => 2, 'REQNO' => 'PPB/NA/25/X/011', 'REQDATE' => '2025-10-08', 'ISCLOSED' => true, 'DESCRIPTION' => "HPP PROJECT\r\nPPB AMEND (SSP.0609)"],
        ]);
        DB::table('accurate_requisitiondet')->insert([
            ['REQID' => 1, 'SEQ' => 0, 'ITEMNO' => 'SSP.0803', 'QUANTITY' => 11, 'QTYORDERED' => 11, 'QTYRECEIVED' => 4],
            ['REQID' => 1, 'SEQ' => 1, 'ITEMNO' => 'SSP.0811', 'QUANTITY' => 5, 'QTYORDERED' => 5, 'QTYRECEIVED' => 5],
            ['REQID' => 2, 'SEQ' => 0, 'ITEMNO' => 'SSP.0609', 'QUANTITY' => 4, 'QTYORDERED' => 0, 'QTYRECEIVED' => 0],
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();

        $this->assertSame(['SSP.0803' => 'SHORTAGE', 'SSP.0811' => 'COMPLETED', 'SSP.0609' => 'CLOSE'],
            Ppb::orderBy('id')->pluck('line_status', 'kode_barang')->all());
        $this->assertSame(['SSP.0803' => false, 'SSP.0811' => false, 'SSP.0609' => true],
            Ppb::orderBy('id')->pluck('is_amend', 'kode_barang')->map(fn ($v) => (bool) $v)->all());
        $this->getJson('/api/ppb?line_status=amend')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.kode_barang', 'SSP.0609')->assertJsonPath('data.0.line_status', 'CLOSE')
            ->assertJsonPath('data.0.is_amend', true)->assertJsonPath('data.0.amend_source', 'NOTE');
        $this->getJson('/api/ppb?line_status=shortage')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.kode_barang', 'SSP.0803')
            ->assertJsonPath('data.0.line_status', 'SHORTAGE');
    }
}
