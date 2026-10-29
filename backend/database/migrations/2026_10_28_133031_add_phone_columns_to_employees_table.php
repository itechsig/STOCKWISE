<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add phone_office column and rename phone to phone_wa for better clarity.
 * Important Request uses phone_wa to contact workers via WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Rename existing phone column to phone_wa
            $table->renameColumn('phone', 'phone_wa');

            // Add new phone_office column
            $table->string('phone_office', 40)->nullable()->after('phone_wa');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Remove phone_office column
            $table->dropColumn('phone_office');

            // Rename phone_wa back to phone
            $table->renameColumn('phone_wa', 'phone');
        });
    }
};
