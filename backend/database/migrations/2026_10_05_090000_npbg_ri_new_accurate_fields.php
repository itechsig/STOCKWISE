<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accurate input format since mid-September 2026 (NPBG from NA/26/IX/106, RI
 * from RI/NV/26/IX/031): the requester / checker is picked as a Department
 * (DEPTID), ITEMRESERVED1/2 now hold the warehouse / rack, and an NPBG has a
 * "jenis pengeluaran" (ARINV.SHIPVIA → SHIPMENT: UMUM, STPP, PROYEK, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npbg', function (Blueprint $t) {
            $t->string('jenis_pengeluaran', 60)->nullable()->index()->after('divisi');
            $t->string('lokasi_gudang', 60)->nullable()->after('peminta');
            $t->string('lokasi_rak', 60)->nullable()->after('lokasi_gudang');
        });
        Schema::table('ri', function (Blueprint $t) {
            $t->string('lokasi_gudang', 60)->nullable()->after('pemeriksa');
            $t->string('lokasi_rak', 60)->nullable()->after('lokasi_gudang');
        });
    }

    public function down(): void
    {
        Schema::table('npbg', fn (Blueprint $t) => $t->dropColumn(['jenis_pengeluaran', 'lokasi_gudang', 'lokasi_rak']));
        Schema::table('ri', fn (Blueprint $t) => $t->dropColumn(['lokasi_gudang', 'lokasi_rak']));
    }
};
