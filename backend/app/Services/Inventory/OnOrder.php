<?php

namespace App\Services\Inventory;

use App\Services\PpbApprovalService;
use Illuminate\Support\Facades\DB;

/**
 * What is already on its way per item, so Restock does not buy it twice:
 *  - draft   lines of Draft PPB not submitted yet (status DRAFT);
 *  - ppb     PPB lines (from PpbApprovalService::GO_LIVE, or STOCKWISE ones) not rejected and without any PO yet;
 *  - po      PO lines not fully received: STOCKWISE POs (Menunggu PO … partly received) and Accurate's open ones.
 * A PPB line that has a PO is counted only through the PO.
 */
class OnOrder
{
    /** @return array<int, array{draft:float, ppb:float, po:float, total:float, docs:list<string>}> item id => quantities */
    public static function byItem(?array $itemIds = null): array
    {
        $out = [];
        $add = function (int $id, string $kind, float $q, ?string $doc) use (&$out) {
            $out[$id] ??= ['draft' => 0.0, 'ppb' => 0.0, 'po' => 0.0, 'total' => 0.0, 'docs' => []];
            $out[$id][$kind] = round($out[$id][$kind] + $q, 2);
            $out[$id]['total'] = round($out[$id]['total'] + $q, 2);
            if ($doc && ! in_array($doc, $out[$id]['docs'], true) && count($out[$id]['docs']) < 5) {
                $out[$id]['docs'][] = $doc;
            }
        };
        $only = fn ($q, string $col) => $itemIds === null ? $q : $q->whereIn($col, $itemIds);

        foreach ($only(DB::table('ppb_draft_lines as l')->join('ppb_drafts as d', 'd.id', '=', 'l.ppb_draft_id')
            ->where('d.status', 'DRAFT')->whereNull('l.ppb_id')->whereNotNull('l.item_id'), 'l.item_id')->get(['l.item_id', 'l.qty', 'd.number']) as $r) {
            $add((int) $r->item_id, 'draft', (float) $r->qty, "Draft {$r->number}");
        }

        $rejected = DB::table(PpbApprovalService::TABLE.' as a')->select('a.no_ppb')->where('a.action', 'REJECT')
            ->whereRaw('a.id = (select max(b.id) from '.PpbApprovalService::TABLE." b where b.no_ppb = a.no_ppb and b.action in ('APPROVE', 'REJECT', 'APPROVE_MANAGER'))");
        $ppb = DB::table('ppb as p')->join('items as i', 'i.code', '=', 'p.kode_barang')
            ->where(fn ($w) => $w->whereDate('p.tgl_ppb', '>=', PpbApprovalService::GO_LIVE)->orWhere(fn ($m) => $m->whereNull('p.accurate_reqid')->whereNotNull('p.created_by')))
            ->whereNotIn('p.no_ppb', $rejected)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_order_items as x')->whereColumn('x.accurate_ppb_id', 'p.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_order_items as y')->whereColumn('y.approved_ppb_id', 'p.id'));
        foreach ($only($ppb, 'i.id')->get(['i.id', 'p.kuantitas', 'p.qty_diterima', 'p.no_ppb']) as $r) {
            $left = (float) $r->kuantitas - (float) ($r->qty_diterima ?? 0);
            if ($left > 0) {
                $add((int) $r->id, 'ppb', $left, "PPB {$r->no_ppb}");
            }
        }

        $po = DB::table('purchase_order_items as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->whereNotNull('l.item_id')->whereColumn('l.qty_received', '<', 'l.qty')
            ->whereIn('o.status', [PpbApprovalService::WAITING, 'DRAFT', 'APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED'])
            ->whereNotIn('l.line_status', ['CLOSED', 'RECEIVED']);
        foreach ($only($po, 'l.item_id')->get(['l.item_id', 'l.qty', 'l.qty_received', 'o.number']) as $r) {
            $add((int) $r->item_id, 'po', (float) $r->qty - (float) $r->qty_received, "PO {$r->number}");
        }

        return $out;
    }
}
