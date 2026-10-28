<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('code', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('code', 30)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE / CLOSED
            $table->timestamps();
        });

        Schema::create('workshops', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->boolean('is_internal')->default(false);
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // Nopol / kode aset
            $table->string('name', 150);
            $table->string('asset_type', 20)->default('VEHICLE'); // VEHICLE/FORKLIFT/TRAILER_TAIL/OTHER
            $table->string('brand_model', 120)->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('serial_units', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no', 80)->index();
            $table->string('kind', 20)->default('STPP_TOOL'); // STPP_TOOL / TYRE / MANUFACTURED
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_raw', 300)->nullable();
            $table->string('maker_code', 40)->nullable(); // (M-xxxx) untuk ban
            $table->string('status', 20)->default('IN_STOCK'); // IN_STOCK / IN_USE / RETIRED
            $table->timestamps();
            $table->unique(['serial_no', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_units');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('workshops');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('customers');
    }
};
