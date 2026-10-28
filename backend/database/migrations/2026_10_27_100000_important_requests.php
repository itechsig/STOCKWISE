<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Important Request: work instructions from a Direktur / superior to people of any division —
 * recipients (each with their own status), files (original / discussion / submission / revision),
 * discussion with replies, submissions and their review, and an activity log.
 * Additive only: new tables and permission rows; nothing existing changes.
 */
return new class extends Migration
{
    /** slug => [name, roles it is granted to ('*' = every role)] */
    private const PERMISSIONS = [
        'important_request.access' => ['Important Request: lihat & kerjakan tugas yang ditugaskan, berdiskusi', ['*']],
        'important_request.create' => ['Important Request: buat & tugaskan instruksi, review hasil', ['super_admin', 'direktur', 'manager', 'bos']],
        'important_request.view_all' => ['Important Request: pantau semua request (admin)', ['super_admin', 'direktur', 'bos']],
        'important_request.analytics' => ['Important Request: analitik (Tracking & Analytic)', ['super_admin', 'direktur', 'bos']],
    ];

    public function up(): void
    {
        Schema::create('important_requests', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->string('title', 200);
            $t->text('description');
            $t->string('category', 60)->nullable();
            $t->string('priority', 10)->default('MEDIUM')->index();          // LOW / MEDIUM / HIGH / URGENT
            $t->dateTime('deadline')->nullable()->index();
            $t->boolean('requires_approval')->default(true);
            $t->string('assignment_mode', 12)->default('INDIVIDUAL');        // INDIVIDUAL / COLLECTIVE
            $t->text('note')->nullable();
            $t->string('status', 24)->default('DRAFT')->index();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            // reminders sent once (deadline in 24 h / overdue)
            $t->timestamp('reminded_soon_at')->nullable();
            $t->timestamp('reminded_overdue_at')->nullable();
            $t->timestamps();
        });

        Schema::create('important_request_assignees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('important_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('status', 24)->default('MENUNGGU_KONFIRMASI')->index();
            $t->unsignedTinyInteger('progress')->default(0);
            $t->string('blocker_note', 1000)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['important_request_id', 'user_id']);
            $t->index(['user_id', 'status']);
        });

        Schema::create('important_request_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('important_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('important_request_comments')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('kind', 16)->default('COMMENT');                       // COMMENT / PROGRESS / BLOCKER / SYSTEM
            $t->text('body');
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->index(['important_request_id', 'parent_id']);
        });

        Schema::create('important_request_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('important_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignee_id')->constrained('important_request_assignees')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedSmallInteger('round')->default(1);
            $t->text('note')->nullable();
            $t->string('status', 16)->default('MENUNGGU_REVIEW')->index();   // MENUNGGU_REVIEW / DISETUJUI / REVISI / OTOMATIS
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
        });

        Schema::create('important_request_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('important_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('comment_id')->nullable()->constrained('important_request_comments')->nullOnDelete();
            $t->foreignId('submission_id')->nullable()->constrained('important_request_submissions')->nullOnDelete();
            $t->string('kind', 12)->index();                                  // ORIGINAL / DISCUSSION / SUBMISSION / REVISION
            $t->string('original_name', 255);
            $t->string('path', 500);
            $t->unsignedBigInteger('size');
            $t->string('mime', 150)->nullable();
            $t->string('extension', 12)->nullable();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamp('deleted_at')->nullable();
            $t->foreignId('deleted_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('important_request_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('important_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->string('action', 40)->index();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // "new comments" indicator: when each person last opened the request
        Schema::create('important_request_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('important_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('last_read_at');
            $t->unique(['important_request_id', 'user_id']);
        });

        $now = now();
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::PERMISSIONS as $slug => [$name, $to]) {
            $id = DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'group' => 'important_request', 'created_at' => $now, 'updated_at' => $now]);
            foreach ($to === ['*'] ? $roles->values() : collect($to)->map(fn ($r) => $roles[$r] ?? null)->filter() as $roleId) {
                if (! DB::table('permission_role')->where(['permission_id' => $id, 'role_id' => $roleId])->exists()) {
                    DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $roleId]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        foreach (['important_request_reads', 'important_request_activities', 'important_request_attachments', 'important_request_submissions',
            'important_request_comments', 'important_request_assignees', 'important_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
