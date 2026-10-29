<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Important Request, simple like Google Classroom: a recipient's part is DITUGASKAN, DISERAHKAN
 * (DISERAHKAN_TERLAMBAT after the deadline), REVISI or SELESAI; the request DITUGASKAN, MENUNGGU_REVIEW,
 * SELESAI (or DRAFT / DIBATALKAN). The steps confirm / start / progress / blocker are gone: their
 * statuses become DITUGASKAN; a result waiting for its review becomes DISERAHKAN (or late).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('important_request_assignees')->whereIn('status', ['MENUNGGU_KONFIRMASI', 'DITERIMA', 'IN_PROGRESS', 'TERKENDALA'])->update(['status' => 'DITUGASKAN']);
        $waiting = DB::table('important_request_assignees as a')->join('important_requests as r', 'r.id', '=', 'a.important_request_id')
            ->where('a.status', 'MENUNGGU_REVIEW')->get(['a.id', 'a.submitted_at', 'r.deadline']);
        foreach ($waiting as $w) {
            $late = $w->deadline && $w->submitted_at && $w->submitted_at > $w->deadline;
            DB::table('important_request_assignees')->where('id', $w->id)->update(['status' => $late ? 'DISERAHKAN_TERLAMBAT' : 'DISERAHKAN']);
        }
        foreach (DB::table('important_requests')->whereNotIn('status', ['DRAFT', 'SELESAI', 'DIBATALKAN'])->pluck('id') as $id) {
            $s = DB::table('important_request_assignees')->where('important_request_id', $id)->pluck('status');
            $next = $s->isNotEmpty() && $s->every(fn ($x) => $x === 'SELESAI') ? 'SELESAI'
                : ($s->contains(fn ($x) => in_array($x, ['DISERAHKAN', 'DISERAHKAN_TERLAMBAT'], true)) ? 'MENUNGGU_REVIEW' : 'DITUGASKAN');
            DB::table('important_requests')->where('id', $id)->update(['status' => $next]);
        }
    }

    public function down(): void
    {
        // the simple statuses stay (the old steps are gone)
    }
};
