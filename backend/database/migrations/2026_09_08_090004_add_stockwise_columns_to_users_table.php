<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->unique()->after('name');
            $table->foreignId('site_id')->nullable()->after('username')->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        // Login is by username; email becomes optional.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['username', 'is_active', 'last_login_at']);
        });
    }
};
