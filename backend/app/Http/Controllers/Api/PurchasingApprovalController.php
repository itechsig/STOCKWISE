<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PpbApprovalService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Approval page's purchasing part: STOCKWISE PPBs waiting for this user's step (Manager / Direktur)
 * and STOCKWISE POs waiting for the Direktur. Accurate's PPBs keep their own list (PPB page).
 */
class PurchasingApprovalController extends Controller
{
    public const PERMISSIONS = ['ppb.approve_manager', 'ppb.approve_director', 'po.approve_director', 'npbg.approve_manager', 'npbg.approve_director'];

    /** GET /api/approvals/purchasing */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => self::waiting($request->user())]);
    }

    /** @return array{ppb: list<array>, po: list<array>, total: int} */
    public static function waiting(User $user): array
    {
        $ppb = [];
        if ($user->hasPermission('ppb.approve_manager') || $user->hasPermission('ppb.approve_director')) {
            $docs = Ppb::query()->whereNull('accurate_reqid')->whereNotNull('created_by')->whereNotNull('no_ppb')
                ->selectRaw('no_ppb, MIN(id) as id, MIN(tgl_ppb) as tgl, COUNT(*) as line_count, MAX(peminta) as peminta, MAX(divisi) as divisi')
                ->groupBy('no_ppb')->orderBy('tgl')->get();
            $status = PpbApprovalService::statusFor($docs->pluck('no_ppb'));
            foreach ($docs as $d) {
                $s = $status[$d->no_ppb] ?? null;
                if (! $s || ! in_array($s['status'], ['MENUNGGU', 'MENUNGGU_DIREKTUR'], true) || ! PpbApprovalService::canDecide($user, $s)) {
                    continue;
                }
                $ppb[] = ['id' => (int) $d->id, 'no_ppb' => $d->no_ppb, 'tgl_ppb' => substr((string) $d->tgl, 0, 10), 'lines' => (int) $d->line_count,
                    'peminta' => $d->peminta, 'divisi' => $d->divisi, 'status' => $s['status'], 'step' => $s['step'],
                    'items' => Ppb::where('no_ppb', $d->no_ppb)->orderBy('id')->limit(3)->pluck('deskripsi_barang')];
            }
        }
        $po = [];
        if ($user->hasPermission(PurchaseOrderService::DIRECTOR)) {
            $last = DB::table(PurchaseOrderService::LOG.' as a')->whereRaw('a.id = (select max(b.id) from '.PurchaseOrderService::LOG.' b where b.purchase_order_id = a.purchase_order_id)')
                ->where('a.action', 'DIAJUKAN')->pluck('a.purchase_order_id');
            foreach (PurchaseOrder::with('vendor:id,name')->withCount('items')->whereIn('id', $last)->where('status', 'DRAFT')->whereNull('accurate_po_id')->orderBy('id')->get() as $p) {
                $po[] = ['id' => $p->id, 'number' => $p->number, 'vendor' => $p->vendor?->name, 'total' => (float) $p->total, 'items_count' => $p->items_count,
                    'from_ppb' => $p->from_ppb_approval, 'date' => $p->date?->toDateString()];
            }
        }

        // manual NPBG (not from a Request Barang): Manager → Direktur
        $npbg = [];
        if ($user->hasPermission('npbg.approve_manager') || $user->hasPermission('npbg.approve_director')) {
            $rows = \App\Models\Npbg::query()->whereNull('accurate_arinvoice_id')->whereNotNull('created_by')->orderBy('id')->get();
            $status = \App\Services\NpbgApprovalService::statusFor($rows->pluck('no_npbg'));
            foreach ($rows->groupBy('no_npbg') as $no => $docRows) {
                $s = $status[$no] ?? null;
                if (! $s || ! \App\Services\NpbgApprovalService::canDecide($user, $s)) {
                    continue;
                }
                $first = $docRows->first();
                $npbg[] = ['id' => $first->id, 'no_npbg' => $no, 'tgl_npbg' => $first->tgl_npbg?->toDateString(), 'pelanggan' => $first->pelanggan,
                    'peminta' => $first->peminta, 'divisi' => $first->divisi, 'step' => $s['step'],
                    'items' => $docRows->map(fn ($r) => "{$r->deskripsi_barang} ".rtrim(rtrim(number_format((float) $r->kuantitas, 2, ',', '.'), '0'), ',')." {$r->satuan}")->values()];
            }
        }

        return ['ppb' => $ppb, 'po' => $po, 'npbg' => $npbg, 'total' => count($ppb) + count($po) + count($npbg)];
    }
}
