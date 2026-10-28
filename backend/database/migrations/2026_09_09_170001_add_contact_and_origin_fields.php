<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email'); // nomor WhatsApp
            $table->string('position', 60)->nullable()->after('phone'); // jabatan (IT Engineer, dst.)
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('name_normalized');
            $table->string('position', 60)->nullable()->after('phone');
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('requester_wa', 30)->nullable()->after('requester_name');
            $table->text('notes')->nullable()->after('purpose'); // catatan tambahan peminta
            // Lokasi permintaan berdasarkan IP (validasi: jaringan kantor / luar).
            $table->string('request_ip', 45)->nullable()->after('npbg_no');
            $table->string('network_label', 12)->nullable()->after('request_ip'); // OFFICE / EXTERNAL / UNKNOWN
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['phone', 'position']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['phone', 'position']));
        Schema::table('material_requests', fn (Blueprint $t) => $t->dropColumn(['requester_wa', 'notes', 'request_ip', 'network_label']));
    }
};
