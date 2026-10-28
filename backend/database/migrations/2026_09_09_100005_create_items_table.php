<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();                 // Kode Barang
            $table->string('description', 400);
            $table->string('description_normalized', 400)->index(); // for matching Excel transaction rows
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type', 20)->default('CONSUMABLE'); // CONSUMABLE/SERIALIZED/ASSET_PART/TYRE/MANUFACTURED
            $table->boolean('needs_blueprint')->default(false);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('default_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('blueprint_img_path')->nullable();
            $table->string('blueprint_pdf_path')->nullable();
            $table->string('blueprint_3d_ref', 60)->nullable();    // raw, for review (NC-8)
            $table->string('source', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
