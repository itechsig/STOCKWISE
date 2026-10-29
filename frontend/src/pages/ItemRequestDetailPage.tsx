import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { AlertTriangle, Ban, CheckCircle2, ChevronLeft, ChevronRight, ClipboardList, Loader2, Copy, Lock, MessageSquare, MousePointerClick, PackageCheck, Plus, Search, Undo2 } from 'lucide-react'
import { toast, invalidTo } from '@/lib/notify'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import {
  STAGE_INFO, trackUrl, useCancelItemRequest, useShareBase, useMatchLine, useCloseRemaining, useFulfill, useFulfillmentFile, useHandoverFile, useItemRequest,
  useProcessItemRequest, useSetUrgency,
  type Fulfillment, type ItemRequestDetail, type ItemRequestLine, type LineStatus, type StockRecommendation,
} from '@/features/itemRequests/api'
import { ApprovalActions, HandoverQty, type HandoverDraft } from '@/components/itemRequests/ApprovalActions'
import { StepsBar } from '@/components/itemRequests/RequestTimeline'
import type { ItemLookupResult } from '@/features/inventory/api'
import { RequestStatusBadge } from '@/pages/ItemRequestListPage'
import { BackLink } from '@/components/BackLink'
import { ItemPicker } from '@/components/ItemPicker'
import { ItemFormModal } from '@/components/ItemFormModal'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { cn, copyText } from '@/lib/utils'
import { BYPASS_TEXT, isAutoApproval } from '@/lib/approval'
import { confirmDialog } from '@/components/ui/confirm'
import { DetailFallback } from '@/components/DetailFallback'
import { LineImagesCell, RequestImages } from '@/components/itemRequests/RequestImages'
import { QtyProgress } from '@/components/QtyProgress'

const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-')
const fmtDateTime = (v: string | null) => (v ? new Date(v).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-')
const fmtQty = (n: number | string | null) => (n == null ? '?' : Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 }))
/** "1,5" or "1.5" → 1.5; NaN when not a number */
const num = (v: string) => Number(v.trim().replace(',', '.'))
/** older notes saved with a long dash: shown with a comma instead */
const clean = (t: string | null | undefined) => (t ? t.replace(/\s*—\s*/g, ', ') : t)

interface Choice {
  /** the Master Produk items picked for this requested item (one or more, in the order picked) */
  items: StockRecommendation[]
  /** how many of each picked item (its id → qty as typed) */
  qtys: Record<number, string>
}

/** "1,5" / "1.5" → 1.5; 0 when empty or not a number (a qty field) */
const qtyOf = (v: string | undefined) => { const n = num(v ?? ''); return Number.isFinite(n) && n > 0 ? n : 0 }
const round2 = (n: number) => Math.round(n * 100) / 100
/** what a master item can give from stock now (unknown stock counts as none) */
const inStock = (item: StockRecommendation) => (item.stock_known ? Math.max(0, item.available ?? 0) : 0)

/**
 * How many of each picked item: from stock first (in the order picked), the rest on the first item —
 * that part is bought (Draft PPB) after the approval. Verifying is only "which item, how many".
 */
function distribute(items: StockRecommendation[], needed: number): Record<number, string> {
  const qtys: Record<number, string> = {}
  if (items.length === 0) return qtys
  if (items.length === 1) return { [items[0].id]: String(needed) }
  let left = needed
  for (const it of items) {
    const take = round2(Math.min(left, Math.floor(inStock(it))))
    qtys[it.id] = String(take)
    left = round2(left - take)
  }
  qtys[items[0].id] = String(round2(qtyOf(qtys[items[0].id]) + left))
  return qtys
}

/** The total picked, and what the stock gives now (NPBG) vs what is bought (Draft PPB). */
function split(choice: Choice) {
  let total = 0, stock = 0
  for (const it of choice.items) {
    const q = qtyOf(choice.qtys[it.id])
    total += q
    stock += Math.min(q, inStock(it))
  }
  return { total: round2(total), stock: round2(stock), buy: round2(total - stock) }
}

/** Stock of a master item, as a short colored text. */
function stockText(item: StockRecommendation, qty: number) {
  if (!item.stock_known) return <span className="text-muted-foreground">stok ?</span>
  const a = item.available ?? 0
  return <span className={a >= qty ? 'text-emerald-700' : a > 0 ? 'text-amber-700' : 'text-red-600'}>stok {fmtQty(a)}</span>
}

/** The master item already saved on a line, as a pick option. */
const savedItem = (l: ItemRequestLine): StockRecommendation | null => (l.item_id ? {
  id: l.item_id, code: l.kode_barang ?? '', description: l.item_description ?? l.deskripsi_barang, unit: l.satuan,
  available: l.available ?? null, stock_known: l.available != null, score: 0,
} : null)

/** One requested item to verify: which Master Produk item(s) and how many — stock decides NPBG / PPB later. */
function OpenLine({ line, requestId, choice, onChange, onPick, saving }: {
  line: ItemRequestLine; requestId: number; choice: Choice; onChange: (c: Choice) => void
  /** save the pick at once (the Item table below follows) */
  onPick: (itemId: number) => void
  saving: boolean
}) {
  const { hasPermission } = useAuth()
  const saved = savedItem(line)
  const [extra, setExtra] = useState<StockRecommendation | null>(saved && !line.recommendations.some((r) => r.id === saved.id) ? saved : null)
  const [searching, setSearching] = useState(false)
  const [adding, setAdding] = useState(false)
  const options = extra && !line.recommendations.some((r) => r.id === extra.id) ? [...line.recommendations, extra] : line.recommendations
  const needed = Number(line.qty)

  const toggleItem = (item: StockRecommendation) => {
    const on = choice.items.some((i) => i.id === item.id)
    const items = on ? choice.items.filter((i) => i.id !== item.id) : [...choice.items, item]
    if (!on && item.id !== line.item_id && choice.items.length === 0) onPick(item.id)
    onChange({ items, qtys: distribute(items, needed) })
  }
  const fromLookup = (it: ItemLookupResult) => {
    const r: StockRecommendation = { id: it.id, code: it.code, description: it.description, unit: it.unit, available: it.available, stock_known: it.stock_known, score: 0 }
    setExtra(r)
    setSearching(false)
    if (!choice.items.some((i) => i.id === r.id)) toggleItem(r)
  }
  // any amount: more than the stock is fine, the rest is bought after the approval
  const setQty = (itemId: number, v: string) => onChange({ ...choice, qtys: { ...choice.qtys, [itemId]: v.replace(/[^\d.,]/g, '') } })

  const sum = split(choice)
  const diff = round2(sum.total - needed)

  return (
    <Card className="space-y-4 rounded-2xl p-4 shadow-sm sm:p-5">
      <div className="flex items-baseline justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <div className="min-w-0 break-words text-base font-semibold">{line.deskripsi_peminta ?? line.deskripsi_barang}</div>
          <RequestImages requestId={requestId} lineId={line.id} slots={line.images} size="size-12" className="shrink-0 gap-1" />
        </div>
        <div className="shrink-0 font-semibold tabular-nums">{fmtQty(line.qty)} <span className="font-normal text-muted-foreground">{line.satuan ?? ''}</span></div>
      </div>
      {line.note && <p className="-mt-3 text-sm text-muted-foreground">{line.note}</p>}

      {/* which warehouse item */}
      <div className="-mb-2 flex items-center justify-between text-xs text-muted-foreground">
        <span>Barang di Master Produk, boleh lebih dari satu (mis. buku Sidu + buku Sinar)</span>
        {saving ? <span className="flex items-center gap-1"><Loader2 className="size-3 animate-spin" /> Menyimpan…</span>
          : choice.items.length > 0 ? <span className="flex items-center gap-1 text-emerald-700"><CheckCircle2 className="size-3" /> {choice.items.length} barang dipilih</span>
          : <span>Pilih barang untuk memenuhi permintaan</span>}
      </div>
      <div className="divide-y rounded-lg border">
        {/* every recommendation, best match first: 5 rows in view, the rest by scrolling */}
        <div className="max-h-[14.5rem] divide-y overflow-y-auto overscroll-contain">
          {options.length === 0 && <p className="px-3 py-3 text-sm text-muted-foreground">Tidak ada rekomendasi, cari barang lain atau tambahkan ke Master Produk.</p>}
          {options.map((r, i) => {
            const isSelected = choice.items.some((item) => item.id === r.id)
            const q = qtyOf(choice.qtys[r.id])
            const fromStock = Math.min(q, inStock(r))
            return (
              <div key={r.id} className={cn('flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2.5 text-sm', isSelected ? 'bg-primary/5' : 'hover:bg-muted/50')}>
                <label className="flex min-w-0 flex-1 basis-60 cursor-pointer items-center gap-3">
                  <input type="checkbox" className="size-4 shrink-0 accent-primary" checked={isSelected} onChange={() => toggleItem(r)} />
                  <span className="w-5 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{i + 1}</span>
                  <span className="min-w-0 flex-1 break-words">{r.description}<span className="ml-1.5 font-mono text-xs text-muted-foreground">{r.code}</span></span>
                  <span className="shrink-0 text-xs">{stockText(r, isSelected ? q : needed)}</span>
                </label>
                {isSelected && (
                  // phone: its own row under the name; wider: on the right
                  <div className="ml-14 flex items-center gap-2 sm:ml-0">
                    <Input className="h-8 w-20 text-right tabular-nums" inputMode="decimal" aria-label={`Jumlah ${r.description}`}
                      value={choice.qtys[r.id] ?? ''} onChange={(e) => setQty(r.id, e.target.value)} />
                    <span className="w-36 text-xs leading-tight text-muted-foreground">
                      {q <= 0 ? 'isi jumlahnya' : <>
                        {fromStock > 0 && <span className="text-emerald-700">{fmtQty(fromStock)} dari stok</span>}
                        {fromStock > 0 && q > fromStock && ' · '}
                        {q > fromStock && <span className="text-amber-700">{fmtQty(round2(q - fromStock))} dibeli</span>}
                      </>}
                    </span>
                  </div>
                )}
              </div>
            )
          })}
        </div>
        {options.length > 5 && <p className="bg-muted/30 px-3 py-1 text-center text-xs text-muted-foreground">{options.length} rekomendasi, scroll untuk melihat lainnya</p>}
        {searching ? (
          <div className="p-2"><ItemPicker onPick={fromLookup} /></div>
        ) : (
          <div className="flex flex-wrap px-3 py-2">
            <button type="button" onClick={() => setSearching(true)} className="flex items-center gap-2 px-3 py-2.5 text-sm text-primary">
              <Search className="size-4" /> Cari barang lain
            </button>
            {hasPermission('item.create') && (
              <button type="button" onClick={() => setAdding(true)} className="flex items-center gap-2 px-3 py-2.5 text-sm text-primary">
                <Plus className="size-4" /> Belum ada? Tambah ke Master Produk
              </button>
            )}
          </div>
        )}
      </div>
      {/* not in the master yet: added there first (Barang / Jasa, stock 0), then picked here */}
      <ItemFormModal open={adding} onClose={() => setAdding(false)} initial={{ description: (line.deskripsi_peminta ?? line.deskripsi_barang).toUpperCase() }}
        onCreated={(it) => {
          const r: StockRecommendation = { id: it.id, code: it.code, description: it.description, unit: it.unit?.code ?? null, available: 0, stock_known: true, score: 0 }
          setExtra(r)
          if (!choice.items.some((i) => i.id === r.id)) toggleItem(r)
        }} />

      {choice.items.length === 0 ? (
        <p className="text-sm text-amber-700">Pilih barang dari Master Produk. Belum ada? Tambahkan dulu.</p>
      ) : (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg bg-muted/40 px-3 py-2 text-sm">
          <span>
            <span className="text-muted-foreground">Total </span>
            <span className={cn('font-semibold tabular-nums', diff === 0 ? 'text-emerald-700' : 'text-red-600')}>{fmtQty(sum.total)}</span>
            <span className="text-muted-foreground"> / {fmtQty(needed)} {line.satuan ?? ''}</span>
            {diff !== 0 && <span className="ml-1.5 text-xs font-medium text-red-600">{diff > 0 ? `lebih ${fmtQty(diff)}` : `kurang ${fmtQty(-diff)}`}</span>}
          </span>
          {sum.stock > 0 && <span className="text-xs text-emerald-700">Dari stok {fmtQty(sum.stock)} → NPBG</span>}
          {sum.buy > 0 && <span className="text-xs text-amber-700">Dibeli {fmtQty(sum.buy)} → Draft PPB</span>}
          {choice.items.length > 1 && (
            <button type="button" className="ml-auto text-xs font-medium text-primary hover:underline" onClick={() => onChange({ ...choice, qtys: distribute(choice.items, needed) })}>
              Bagi ulang otomatis
            </button>
          )}
          <span className="basis-full text-xs text-muted-foreground">Perkiraan dari stok sekarang; dicek lagi setelah approval. Yang kurang otomatis masuk Draft PPB.</span>
        </div>
      )}
    </Card>
  )
}

/** One color per line status: [text, background]. */
const LINE_TONE: Partial<Record<LineStatus, string>> = {
  MENUNGGU: 'bg-amber-50 text-amber-800', PERLU_PERBAIKAN: 'bg-orange-50 text-orange-800',
  APPROVAL_MANAGER: 'bg-blue-50 text-blue-800', APPROVAL_DIREKTUR: 'bg-blue-50 text-blue-800',
  DISIAPKAN: 'bg-violet-50 text-violet-800', SIAP_DISERAHKAN: 'bg-cyan-50 text-cyan-800',
  DIPENUHI_SEBAGIAN: 'bg-teal-50 text-teal-800', MENUNGGU_PENGADAAN: 'bg-indigo-50 text-indigo-800', MENUNGGU_BARANG: 'bg-indigo-50 text-indigo-800',
  SELESAI: 'bg-emerald-50 text-emerald-800', DITUTUP: 'bg-slate-100 text-slate-700', DITOLAK: 'bg-red-50 text-red-800', DIBATALKAN: 'bg-slate-100 text-slate-600',
}

/** What a decided line became: its status, and its Draft PPB / PPB document, or why it was rejected / closed. */
function DecisionCell({ line }: { line: ItemRequestLine }) {
  const badge = <span className={cn('inline-block rounded px-1.5 py-0.5 text-xs font-medium', LINE_TONE[line.line_status] ?? 'bg-muted')}>{line.line_status_label}</span>
  if (line.decision === null) return badge
  if (line.decision === 'TOLAK') return <div className="space-y-0.5 leading-tight">{badge}<div className="text-xs text-muted-foreground">{line.reject_reason}</div></div>
  return (
    <div className="space-y-0.5 leading-tight">
      {badge}
      {line.closed_reason && <div className="text-xs text-muted-foreground">Ditutup: {clean(line.closed_reason)}</div>}
    </div>
  )
}

/** Before the system decides (until the approval): what the stock now would give — an estimate. */
const BEFORE_ALLOCATION = ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN', 'APPROVAL_MANAGER', 'APPROVAL_DIREKTUR']
function estimate(l: ItemRequestLine, status: string): { npbg: number; ppb: number } | null {
  if (!BEFORE_ALLOCATION.includes(status) || l.decision === 'TOLAK') return null
  const need = Number(l.approved_qty ?? l.qty)
  const fromStock = l.item_id ? Math.min(need, Math.max(0, l.available ?? 0)) : 0
  return { npbg: Math.round(fromStock * 100) / 100, ppb: Math.round((need - fromStock) * 100) / 100 }
}

/** NPBG column: only the quantity given from stock (its NPBG and state in the tooltip). */
function NpbgCell({ line, status }: { line: ItemRequestLine; status: string }) {
  const est = estimate(line, status)
  if (est) return <span className="text-muted-foreground" title="Perkiraan dari stok sekarang, ditentukan sistem setelah approval">{fmtQty(est.npbg)}</span>
  if (!line.npbg) return <span className="text-muted-foreground">0</span>
  const tip = line.npbg.docs.map((d) => `${d.no} · ${d.handed ? 'diserahkan' : 'siap diserahkan'}`).join(' | ')
  return <span className="font-semibold text-emerald-700" title={tip}>{fmtQty(line.npbg.qty)}</span>
}

/** PPB column: only the quantity to be bought (its Draft / PPB / PO and stage in the tooltip). */
function PpbCell({ line, status }: { line: ItemRequestLine; status: string }) {
  const est = estimate(line, status)
  if (est) return <span className="text-muted-foreground" title="Perkiraan dari stok sekarang, ditentukan sistem setelah approval">{fmtQty(est.ppb)}</span>
  const p = line.ppb
  if (!p) return <span className="text-muted-foreground">0</span>
  const tip = [p.po_number ?? p.no_ppb ?? `Draft ${p.draft_number}`, p.stage].join(' · ')
  return <span className={cn('font-semibold', p.stage.startsWith('Diterima') ? 'text-emerald-700' : 'text-indigo-700')} title={tip}>{fmtQty(p.qty)}</span>
}

/** The warehouse item (or the requester's words), with what was asked when it differs. */
/** An item's name (and what the requester typed), its images beside it: this item, these photos. */
function LineName({ line, requestId }: { line: ItemRequestLine; requestId: number }) {
  const matched = !!line.item_description
  // For Item Request (not PPB/PO), always show the original requester's description
  // For PPB/PO, show the matched item description from Master Produk
  const displayName = line.deskripsi_peminta ?? line.deskripsi_barang
  return (
    <div className="min-w-0">
      <div className="break-words font-medium">{displayName}</div>
      <div className="break-words text-xs text-muted-foreground">
        {[line.kode_barang, line.satuan, matched && line.item_description && line.item_description !== displayName ? `cocok: ${line.item_description}` : null, line.note].filter(Boolean).join(' · ')}
      </div>
      {/* phone (cards): the images under the name */}
      {(line.images?.length ?? 0) > 0 && <RequestImages requestId={requestId} lineId={line.id} slots={line.images} size="size-11" className="mt-1.5 gap-1 sm:hidden" />}
    </div>
  )
}

/**
 * The items: asked → approved → handed over → left, the stock now, the status (a table; cards on a phone).
 * Manager / Direktur do not change quantities here: they send the request back to admin gudang.
 */
function ItemsTable({ req, selectedLineId, onSelectLine }: { req: ItemRequestDetail; selectedLineId: number | null; onSelectLine: (id: number) => void }) {
  const decided = req.lines.some((l) => l.approved_qty != null)
  // during the Manager / Direktur approval nothing is handed over yet: no Diserahkan / Sisa columns
  const handing = decided && !['APPROVAL_MANAGER', 'APPROVAL_DIREKTUR'].includes(req.status)
  const left = (l: ItemRequestLine) => l.remaining
  const approvedCell = (l: ItemRequestLine) => (l.approved_qty != null ? fmtQty(l.approved_qty) : '')
  // the stock of Master Produk (it drops with the serah terima); what is held for prepared NPBG under it
  const stockOf = (l: ItemRequestLine) => {
    const stock = l.on_hand ?? l.available
    if (stock == null) return ''
    const held = l.on_hand != null && l.available != null ? Math.round((l.on_hand - l.available) * 100) / 100 : 0
    return <>{fmtQty(stock)}{held > 0 && <span className="block text-[11px] font-normal text-muted-foreground">dipesan {fmtQty(held)}</span>}</>
  }
  const stockTone = (l: ItemRequestLine) => {
    const need = l.remaining > 0 ? l.remaining : Number(l.approved_qty ?? l.qty)
    return l.available == null ? 'text-muted-foreground' : l.decision !== 'TOLAK' && l.available < need ? 'font-semibold text-red-600' : 'text-emerald-700'
  }

  return (
    <>
      {/* phone: one card per item */}
      <ul className="divide-y sm:hidden">
        {req.lines.map((l) => (
          <li key={l.id} className={cn('space-y-2 p-4 text-sm', l.decision === null && 'cursor-pointer active:bg-muted/50', selectedLineId === l.id && 'bg-blue-50 shadow-[inset_3px_0_0_theme(colors.blue.500)]')}
            onClick={() => l.decision === null && onSelectLine(l.id)}>
            <div className="flex items-start justify-between gap-3"><LineName line={l} requestId={req.id} /><DecisionCell line={l} /></div>
            <dl className="grid gap-1 text-center text-xs" style={{ gridTemplateColumns: `repeat(${1 + (decided ? 1 : 0) + (handing ? 2 : 0) + 1}, minmax(0, 1fr))` }}>
              {([['Diminta', fmtQty(l.qty)], ...(decided ? [['Disetujui', approvedCell(l)]] : []),
                ...(handing ? [['Diminta/Diserahkan', l.approved_qty != null && l.decision !== 'TOLAK' ? <QtyProgress total={Number(l.approved_qty)} done={l.fulfilled_qty} className="mx-auto w-fit" /> : ''], ['Sisa', l.approved_qty != null ? <span>{fmtQty(left(l))}</span> : '']] : []),
                ['Stok', <span className={stockTone(l)}>{stockOf(l)}</span>]] as [string, React.ReactNode][]).map(([k, v]) => (
                <div key={k} className="rounded bg-muted/40 px-1 py-1.5">
                  <dt className="text-muted-foreground">{k}</dt>
                  <dd className="mt-0.5 font-semibold tabular-nums [&_input]:mx-auto [&_input]:h-7 [&_input]:w-14">{v}</dd>
                </div>
              ))}
            </dl>
            <dl className="grid grid-cols-2 gap-1 text-center text-xs">
              <div className="rounded bg-emerald-50/60 px-1 py-1.5"><dt className="text-muted-foreground">NPBG</dt><dd className="mt-0.5 tabular-nums">{l.decision === 'TOLAK' ? 0 : <NpbgCell line={l} status={req.status} />}</dd></div>
              <div className="rounded bg-indigo-50/60 px-1 py-1.5"><dt className="text-muted-foreground">PPB</dt><dd className="mt-0.5 tabular-nums">{l.decision === 'TOLAK' ? 0 : <PpbCell line={l} status={req.status} />}</dd></div>
            </dl>
          </li>
        ))}
      </ul>

      <div className="hidden overflow-x-auto sm:block">
        <table className="w-full text-sm">
          <thead className="whitespace-nowrap border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
            <tr>
              <th className="w-10 px-4 py-2.5 font-medium">#</th>
              <th className="min-w-[14rem] px-3 py-2.5 font-medium">Barang</th>
              <th className="px-3 py-2.5 text-right font-medium">Diminta</th>
              {decided && <>
                <th className="px-3 py-2.5 text-right font-medium">Disetujui</th>
                {handing && <>
                  <th className="px-3 py-2.5 font-medium" title="Per barang: jumlah disetujui / sudah diserahkan, mis. 10/5 = baru diserahkan 5 dari 10">Diminta/Diserahkan</th>
                  <th className="px-3 py-2.5 text-right font-medium">Sisa</th>
                </>}
              </>}
              <th className="px-3 py-2.5 text-right font-medium">Stok</th>
              <th className="px-3 py-2.5 text-right font-medium" title="Dikeluarkan dari stok">NPBG</th>
              <th className="px-3 py-2.5 text-right font-medium" title="Stok tidak ada, dibeli sampai RI">PPB</th>
              <th className="min-w-[9rem] px-4 py-2.5 font-medium">Status</th>
              <th className="px-4 py-2.5 text-right font-medium">Gambar</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {req.lines.map((l, i) => (
              <tr key={l.id} className={cn('align-top', l.decision === null && 'cursor-pointer hover:bg-muted/50', selectedLineId === l.id && 'bg-blue-50 hover:bg-blue-50 [&>td:first-child]:shadow-[inset_3px_0_0_theme(colors.blue.500)]')}
                onClick={() => l.decision === null && onSelectLine(l.id)}>
                <td className="px-4 py-3 tabular-nums text-muted-foreground">{i + 1}</td>
                <td className="px-3 py-3"><LineName line={l} requestId={req.id} /></td>
                <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">{fmtQty(l.qty)}</td>
                {decided && <>
                  <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">{approvedCell(l)}</td>
                  {handing && <>
                    {/* per item: approved (asked) / handed over so far, e.g. 10/5 */}
                    <td className="px-3 py-3">{l.approved_qty != null && l.decision !== 'TOLAK' ? <QtyProgress total={Number(l.approved_qty)} done={l.fulfilled_qty} unit={l.satuan} /> : ''}</td>
                    <td className={cn('whitespace-nowrap px-3 py-3 text-right tabular-nums', left(l) > 0 && 'font-semibold')}>{l.approved_qty != null ? fmtQty(left(l)) : ''}</td>
                  </>}
                </>}
                <td className={cn('whitespace-nowrap px-3 py-3 text-right tabular-nums', stockTone(l))}>{stockOf(l)}</td>
                <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">{l.decision === 'TOLAK' ? 0 : <NpbgCell line={l} status={req.status} />}</td>
                <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">{l.decision === 'TOLAK' ? 0 : <PpbCell line={l} status={req.status} />}</td>
                <td className="px-4 py-3"><DecisionCell line={l} /></td>
                {/* the requester's images of this item, rightmost: from the request to its end */}
                <td className="px-4 py-3"><LineImagesCell requestId={req.id} lineId={l.id} slots={l.images} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

/**
 * Gudang: how much of each item it gives now, from the real stock (≤ what is left, ≤ the stock).
 * That becomes one NPBG; what cannot be given may go to a Draft PPB. Or the rest is closed.
 */
function FulfillPanel({ req, panelRef }: { req: ItemRequestDetail; panelRef: React.RefObject<HTMLDivElement | null> }) {
  const fulfill = useFulfill(req.id)
  const close = useCloseRemaining(req.id)
  const plan = req.plan ?? []
  const [qty, setQty] = useState<Record<number, string>>({})
  const [toPpb, setToPpb] = useState<Record<number, boolean>>({})
  const [closing, setClosing] = useState(false)
  const [reason, setReason] = useState('')
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => {
    setQty((q) => Object.fromEntries(plan.map((p) => [p.line_id, q[p.line_id] ?? String(p.suggested)])))
    setToPpb((t) => Object.fromEntries(plan.map((p) => [p.line_id, t[p.line_id] ?? !p.in_procurement])))
  }, [req.plan]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!req.can_fulfill && !req.can_close) return null
  const rows = plan.map((p) => {
    const v = num(qty[p.line_id] ?? '0')
    const left = Number.isFinite(v) ? Math.max(0, p.remaining - v) : p.remaining
    return { p, v, left }
  })
  const giving = rows.filter((r) => r.v > 0).length
  const shortage = rows.filter((r) => r.left > 0 && !r.p.in_procurement && toPpb[r.p.line_id]).length

  const submit = () => {
    setErr(null)
    for (const { p, v } of rows) {
      if (!Number.isFinite(v) || v < 0) return invalidTo(setErr, `Jumlah ${p.deskripsi} tidak valid.`)
      if (v > p.remaining) return invalidTo(setErr, `${p.deskripsi}: paling banyak sisa kebutuhan (${fmtQty(p.remaining)}).`)
      if (v > 0 && p.available != null && v > p.available) return invalidTo(setErr, `${p.deskripsi}: stok hanya ${fmtQty(p.available)}.`)
    }
    if (giving === 0 && shortage === 0) return invalidTo(setErr, 'Isi jumlah yang diserahkan, atau teruskan kekurangannya ke Draft PPB.')
    fulfill.mutate(rows.map(({ p, v }) => ({ line_id: p.line_id, qty: v, shortage_to_ppb: !!toPpb[p.line_id] && !p.in_procurement })), {
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }
  const doClose = () => {
    setErr(null)
    if (!reason.trim()) return invalidTo(setErr, 'Tulis alasan menutup sisa kebutuhan.')
    close.mutate({ reason: reason.trim() }, { onSuccess: () => { setClosing(false); setReason('') }, onError: (e) => setErr(apiErrorMessage(e)) })
  }

  return (
    <div ref={panelRef} id="siapkan" className="scroll-mt-4">
      <Panel title="Siapkan barang dari stok" className="border-primary/30">
        {req.can_fulfill && plan.length > 0 && (
          <ul className="divide-y">
            {rows.map(({ p, left }) => (
              <li key={p.line_id} className="grid gap-3 p-4 text-sm sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                <div className="min-w-0">
                  <div className="break-words font-medium">{p.deskripsi}</div>
                  <div className="text-xs text-muted-foreground">
                    {p.kode_barang && <span className="font-mono">{p.kode_barang} · </span>}
                    sisa kebutuhan <b className="text-foreground">{fmtQty(p.remaining)} {p.satuan ?? ''}</b> · stok tersedia{' '}
                    <b className={(p.available ?? 0) < p.remaining ? 'text-red-600' : 'text-emerald-700'}>{p.available != null ? fmtQty(p.available) : ''}</b>
                  </div>
                  {!p.item_id && <div className="mt-1 text-xs text-amber-700">Belum dicocokkan ke barang gudang, tidak bisa dikeluarkan dari stok.</div>}
                </div>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 sm:justify-end">
                  <label className="flex items-center gap-2 text-xs text-muted-foreground">
                    Diserahkan
                    <Input className="h-8 w-24 text-right text-sm tabular-nums text-foreground" inputMode="decimal" aria-label={`Diserahkan ${p.deskripsi}`} disabled={!p.item_id}
                      value={qty[p.line_id] ?? ''} onChange={(e) => setQty((q) => ({ ...q, [p.line_id]: e.target.value }))} />
                  </label>
                  {left > 0 && (p.in_procurement ? (
                    <span className="text-xs text-indigo-700">{fmtQty(left)} sedang dibeli</span>
                  ) : (
                    <label className="flex cursor-pointer items-center gap-2 text-xs">
                      <input type="checkbox" className="size-4 accent-primary" checked={!!toPpb[p.line_id]} onChange={(e) => setToPpb((t) => ({ ...t, [p.line_id]: e.target.checked }))} />
                      kekurangan {fmtQty(left)} ke Draft PPB
                    </label>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        )}
        <div className="space-y-3 border-t p-4">
          {closing && (
            <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
              <Label htmlFor="close-reason">Alasan menutup sisa kebutuhan *</Label>
              <Input id="close-reason" autoFocus maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. Tidak dibutuhkan lagi / diganti barang lain" />
              <div className="flex justify-end gap-2">
                <Button size="sm" variant="ghost" onClick={() => { setClosing(false); setErr(null) }}>Batal</Button>
                <Button size="sm" onClick={doClose} disabled={close.isPending}><Lock className="size-4" /> Tutup sisa</Button>
              </div>
            </div>
          )}
          {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <span className="text-sm text-muted-foreground">
              {req.can_fulfill ? `${giving} barang diserahkan${shortage ? ` · ${shortage} kekurangan ke Draft PPB` : ''} · stok langsung berkurang` : 'Masih ada sisa kebutuhan.'}
            </span>
            <div className="flex flex-wrap gap-2">
              {req.can_close && !closing && <Button variant="outline" onClick={() => setClosing(true)}><Lock className="size-4" /> Tutup sisa</Button>}
              {req.can_fulfill && plan.length > 0 && (
                <Button onClick={submit} disabled={fulfill.isPending}><PackageCheck className="size-4" /> {fulfill.isPending ? 'Menyimpan…' : 'Buat NPBG & siapkan'}</Button>
              )}
            </div>
          </div>
        </div>
      </Panel>
    </div>
  )
}

/** Every giving of goods: NPBG #1, #2 …, how much, and its serah terima proof. */
function Fulfillments({ req, draft, onDraft }: { req: ItemRequestDetail; draft: HandoverDraft; onDraft: (d: HandoverDraft) => void }) {
  const list = req.fulfillments ?? []
  if (list.length === 0) return null
  return (
    <Panel title={`NPBG & serah terima (${list.length})`}>
      <ul className="divide-y">
        {list.map((f) => req.can_handover && f.status !== 'DISERAHKAN' ? (
          // Gudang fixes here how much is handed over; the serah terima then only takes the photo and signature
          <li key={f.id} className="space-y-2 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2 px-4 text-sm">
              <span><span className="font-semibold">#{f.seq}</span> <span className="font-mono">{f.no_npbg}</span></span>
              <span className="rounded bg-violet-50 px-1.5 py-0.5 text-xs font-medium text-violet-800">Tetapkan jumlah, lalu serah terima</span>
            </div>
            <HandoverQty f={f} value={draft} onChange={onDraft} stock={Object.fromEntries(req.lines.map((l) => [l.id, l.on_hand ?? l.available]))}
              images={{ requestId: req.id, byLine: Object.fromEntries(req.lines.map((l) => [l.id, l.images ?? []])) }} />
          </li>
        ) : <FulfillmentRow key={f.id} reqId={req.id} f={f} />)}
      </ul>
    </Panel>
  )
}

const FULFIL_TONE: Record<Fulfillment['status'], [string, string]> = {
  DISIAPKAN: ['bg-violet-50 text-violet-800', 'Siap serah terima'],
  SIAP: ['bg-cyan-50 text-cyan-800', 'Siap serah terima'],
  DISERAHKAN: ['bg-emerald-50 text-emerald-800', 'Sudah diserahkan'],
}

function FulfillmentRow({ reqId, f }: { reqId: number; f: Fulfillment }) {
  const photo = useFulfillmentFile(reqId, f.id, 'handover', f.photo)
  const sign = useFulfillmentFile(reqId, f.id, 'signature', f.signature)
  const npbgId = f.lines.find((l) => l.npbg_id)?.npbg_id
  return (
    <li className="space-y-2 p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="font-semibold">#{f.seq}</span>
          {npbgId ? <Link to={`/npbg/${npbgId}`} className="font-mono text-primary hover:underline">{f.no_npbg}</Link> : <span className="font-mono">{f.no_npbg}</span>}
          {f.seq > 1 && <span className="text-xs text-muted-foreground">NPBG lanjutan</span>}
        </div>
        <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium', FULFIL_TONE[f.status][0])}>{FULFIL_TONE[f.status][1]}</span>
      </div>
      <ul className="space-y-1">
        {f.lines.map((l) => (
          <li key={l.id} className="flex items-center justify-between gap-3">
            <span className="min-w-0 break-words">{l.deskripsi}</span>
            <span className="shrink-0 tabular-nums">
              {l.handed_qty != null && l.handed_qty !== l.qty ? <><s className="text-muted-foreground">{fmtQty(l.qty)}</s> {fmtQty(l.handed_qty)}</> : fmtQty(l.handed_qty ?? l.qty)} {l.satuan ?? ''}
            </span>
          </li>
        ))}
      </ul>
      <div className="text-xs text-muted-foreground">
        Disiapkan {fmtDateTime(f.prepared_at)}{f.prepared_by ? ` · ${f.prepared_by}` : ''}
        {f.handed_at && <> · diserahkan {fmtDateTime(f.handed_at)}{f.handed_by ? ` · ${f.handed_by}` : ''} · diterima {f.received_by ?? '-'}</>}
      </div>
      {f.note && <div className="text-xs">{f.note}</div>}
      {f.status === 'DISERAHKAN' && (f.photo || f.signature) && (
        <div className="grid grid-cols-2 gap-2 pt-1">
          <a href={photo.data} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-md border bg-muted">
            {photo.data ? <img src={photo.data} alt={`Foto serah terima #${f.seq}`} className="h-28 w-full object-cover" /> : <div className="h-28" />}
          </a>
          <div className="flex flex-col rounded-md border bg-white p-1">
            <div className="flex flex-1 items-center justify-center">{sign.data && <img src={sign.data} alt={`Tanda tangan #${f.seq}`} className="max-h-24 w-full object-contain" />}</div>
            <div className="border-t pt-0.5 text-center text-xs text-slate-600">{f.received_by ?? '-'}</div>
          </div>
        </div>
      )}
    </li>
  )
}

/** The proof of the serah terima: photo and signature. */
function HandoverProof({ req }: { req: ItemRequestDetail }) {
  // the old flow's single serah terima (newer ones are listed per NPBG)
  const h = (req.fulfillments ?? []).length === 0 ? req.handover : null
  const photo = useHandoverFile(req.id, 'handover', !!h?.photo)
  const sign = useHandoverFile(req.id, 'signature', !!h?.signature)
  if (!h) return null
  return (
    <Panel title="Bukti serah terima" aside={<span className="text-xs text-muted-foreground">{fmtDateTime(h.at)}</span>}>
      <div className="grid grid-cols-2 gap-3 p-4">
        <a href={photo.data} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-md border bg-muted">
          {photo.data ? <img src={photo.data} alt="Foto serah terima" className="h-44 w-full object-cover" /> : <div className="h-44" />}
        </a>
        <div className="flex flex-col rounded-md border bg-white p-2">
          <div className="flex flex-1 items-center justify-center">{sign.data && <img src={sign.data} alt="Tanda tangan penerima" className="max-h-36 w-full object-contain" />}</div>
          <div className="border-t pt-1 text-center text-xs text-slate-600">{h.received_by ?? '-'}</div>
        </div>
      </div>
      <dl className="divide-y border-t px-4 text-sm">
        <Row label="Diterima oleh">{h.received_by ?? '-'}</Row>
        <Row label="Diserahkan oleh">{h.by ?? '-'}</Row>
      </dl>
    </Panel>
  )
}

/** A titled card (Classroom-like: rounded, a plain title). */
function Panel({ title, aside, children, className }: { title: string; aside?: React.ReactNode; children: React.ReactNode; className?: string }) {
  return (
    <Card className={cn('overflow-hidden rounded-2xl shadow-sm', className)}>
      <div className="flex items-center justify-between gap-2 border-b px-4 py-3 sm:px-5">
        <h2 className="text-[15px] font-medium">{title}</h2>
        {aside}
      </div>
      {children}
    </Card>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2">
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words text-right font-medium">{children}</dd>
    </div>
  )
}

const LOG_TEXT: Record<string, string> = {
  'ADMIN.VALIDASI': 'Diverifikasi admin gudang',
  'MANAGER.APPROVE': 'Disetujui Manager', 'MANAGER.REJECT': 'Ditolak Manager', 'MANAGER.RETURN': 'Dikembalikan Manager untuk perbaikan',
  'DIREKTUR.APPROVE': 'Disetujui Direktur', 'DIREKTUR.REJECT': 'Ditolak Direktur', 'DIREKTUR.RETURN': 'Dikembalikan Direktur untuk perbaikan',
  'GUDANG.APPROVE': 'Barang disiapkan gudang', 'GUDANG.REJECT': 'Ditolak gudang',
  'GUDANG.FULFILL': 'NPBG dibuat dari stok', 'GUDANG.CONFIRM': 'Jumlah diserahkan ditetapkan gudang', 'GUDANG.SHORTAGE': 'Kekurangan ke Draft PPB', 'GUDANG.CLOSE': 'Sisa kebutuhan ditutup',
  'SERAH_TERIMA.HANDOVER': 'Barang diserahkan',
}

/** Everything that happened to the request, oldest first. */
function Activity({ req }: { req: ItemRequestDetail }) {
  const items = [
    { at: req.created_at, text: 'Request dibuat', by: req.requester_name, note: null as string | null, bad: false },
    ...(req.approval_log.length === 0 && req.processed_at ? [{ at: req.processed_at, text: 'Diproses admin gudang', by: req.processed_by_name, note: null, bad: false }] : []),
    ...[...req.approval_log].reverse().map((a) => ({ at: a.at ?? '', text: isAutoApproval(a.note) ? BYPASS_TEXT : LOG_TEXT[`${a.stage}.${a.action}`] ?? a.action,
      by: isAutoApproval(a.note) ? `bypass ${a.user_name ?? 'Direktur'}` : a.user_name, note: isAutoApproval(a.note) ? null : clean(a.note) ?? null, bad: a.action === 'REJECT' || a.action === 'RETURN' })),
    ...(req.status === 'DIBATALKAN' ? [{ at: '', text: 'Request dibatalkan', by: null, note: null, bad: true }] : []),
  ]
  return (
    <ol className="space-y-3 p-4">
      {items.map((a, i) => (
        <li key={i} className="flex gap-3 text-sm">
          <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', a.bad ? 'bg-red-500' : i === items.length - 1 ? 'bg-primary' : 'bg-muted-foreground/40')} />
          <div className="min-w-0">
            <div className={cn('font-medium', a.bad && 'text-red-700')}>{a.text}</div>
            <div className="text-xs text-muted-foreground">{[a.at ? fmtDateTime(a.at) : null, a.by].filter(Boolean).join(' · ')}</div>
            {a.note && <div className="mt-0.5 text-xs">{a.note}</div>}
          </div>
        </li>
      ))}
    </ol>
  )
}

export function ItemRequestDetailPage() {
  const { id } = useParams()
  const { user, hasPermission } = useAuth()
  const { data: req, isLoading } = useItemRequest(Number(id))
  const cancel = useCancelItemRequest(Number(id))
  const proc = useProcessItemRequest(Number(id))
  const urgency = useSetUrgency(Number(id))
  const match = useMatchLine(Number(id))
  const share = useShareBase()
  const location = useLocation()
  const prepareRef = useRef<HTMLDivElement>(null)
  const confirmRef = useRef<HTMLDivElement>(null)

  // only while admin gudang still validates (or fixes what was sent back); afterwards the request is in approval
  const canProcess = !!req && hasPermission('item_request.process') && ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN'].includes(req.status)
  const open = useMemo(() => (canProcess && req ? req.lines.filter((l) => l.decision === null) : []), [req, canProcess])
  const [choices, setChoices] = useState<Record<number, Choice>>({})
  // the item being verified: picked in the table "Barang yang diminta" (the first one to verify by default)
  const [selectedLineId, setSelectedLineId] = useState<number | null>(null)
  useEffect(() => {
    setSelectedLineId((id) => (id !== null && open.some((l) => l.id === id) ? id : open[0]?.id ?? null))
  }, [open])
  const [pelanggan, setPelanggan] = useState('')
  const [tipe, setTipe] = useState<'Penjualan' | 'Non Penjualan'>('Non Penjualan')
  const [err, setErr] = useState<string | null>(null)

  // default per line: the best recommendation, the quantity asked
  useEffect(() => {
    // the item already saved on the line; nothing picked for the admin (no blue row before a click)
    setChoices((prev) => Object.fromEntries(open.map((l) => {
      if (prev[l.id]) return [l.id, prev[l.id]]
      const items = savedItem(l) ? [savedItem(l)!] : []
      return [l.id, { items, qtys: distribute(items, Number(l.qty)) }]
    })))
  }, [open])
  useEffect(() => { if (req) setPelanggan((p) => p || req.divisi || req.requester_name || '') }, [req])
  // opened from the Approval page's "Siapkan barang"
  const wantsPrepare = location.hash === '#siapkan' && (!!req?.can_fulfill || !!req?.can_handover)
  useEffect(() => {
    if (wantsPrepare) (req?.can_handover ? confirmRef : prepareRef).current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [wantsPrepare]) // eslint-disable-line react-hooks/exhaustive-deps
  const [draft, setDraft] = useState<HandoverDraft>({ qty: {}, drop: {} })
  useEffect(() => { setDraft({ qty: {}, drop: {} }) }, [req?.status])

  if (isLoading || !req) return <div className="space-y-4"><BackLink to="/item-requests" label="Request Barang" /><DetailFallback loading={isLoading} /></div>

  const canCancel = req.status === 'MENUNGGU' && (req.requester_id === user?.id || hasPermission('item_request.process'))
  const info = STAGE_INFO[req.status]
  const myTurn = !!info && (req.can_approve || req.can_handover || !!req.can_fulfill)
  const sentBack = req.status === 'PERLU_PERBAIKAN' ? req.approval_log.find((a) => a.action === 'RETURN') : undefined

  const submit = () => {
    setErr(null)
    const unmatched = open.find((l) => choices[l.id]?.items.length === 0)
    if (unmatched) return invalidTo(setErr, `"${unmatched.deskripsi_peminta ?? unmatched.deskripsi_barang}" belum dipilih dari Master Produk, kalau belum ada, tambahkan dulu.`)
    if (!pelanggan.trim()) return invalidTo(setErr, 'Isi Pelanggan untuk NPBG.')
    
    // the picked items together: exactly what was asked (the stock decides NPBG / Draft PPB after the approval)
    const empty = open.find((l) => choices[l.id]?.items.some((it) => qtyOf(choices[l.id].qtys[it.id]) <= 0))
    if (empty) return invalidTo(setErr, `Isi jumlah setiap barang untuk "${empty.deskripsi_peminta ?? empty.deskripsi_barang}", atau hapus centangnya.`)
    const badQty = open.find((l) => split(choices[l.id]).total !== Number(l.qty))
    if (badQty) return invalidTo(setErr, `Jumlah "${badQty.deskripsi_peminta ?? badQty.deskripsi_barang}" harus ${fmtQty(badQty.qty)} ${badQty.satuan ?? ''} (yang diminta). Saat ini: ${fmtQty(split(choices[badQty.id]).total)}. Stok kurang tidak apa-apa: sisanya dibeli lewat Draft PPB.`)

    proc.mutate({
      lines: Object.fromEntries(open.map((l) => [l.id, { items: choices[l.id].items.map((it) => ({ item_id: it.id, qty: qtyOf(choices[l.id].qtys[it.id]) })) }])),
      npbg: { pelanggan: pelanggan.trim(), tipe_npbg: tipe },
    }, { onError: (e) => setErr(apiErrorMessage(e)) })
  }

  const copyLink = async () => {
    const url = trackUrl(req.track_token, share.base)
    if (await copyText(url)) toast.success('Link status disalin. Kirim ke peminta untuk memantau statusnya.')
    else toast.error(`Tidak bisa menyalin otomatis. Salin manual: ${url}`, { duration: 15000 })
  }

  const overdue = !!req.needed_date && !['SELESAI', 'DITOLAK', 'DIBATALKAN'].includes(req.status) && new Date(req.needed_date) < new Date(new Date().toDateString())

  const matched = open.filter((l) => (choices[l.id]?.items.length ?? 0) > 0).length
  const selectedIdx = open.findIndex((l) => l.id === selectedLineId)
  const selected = selectedIdx >= 0 ? open[selectedIdx] : null
  // a click in the table: that item in the verification card (scrolled into view)
  const toLine = (lineId: number) => {
    setSelectedLineId(lineId)
    requestAnimationFrame(() => document.getElementById('verifikasi')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
  }
  // the round icon: green when done, red when rejected, grey when cancelled
  const dot = req.status === 'SELESAI' ? 'bg-emerald-500' : req.status === 'DITOLAK' ? 'bg-red-500' : req.status === 'DIBATALKAN' ? 'bg-slate-400' : 'bg-primary'
  const title = [...new Set(req.lines.map((l) => l.deskripsi_peminta ?? l.deskripsi_barang))]

  return (
    <div className="mx-auto max-w-6xl pb-8">
      <BackLink to="/item-requests" label="Request Barang" />

      {/*
        Classroom's assignment page: the left column is the request (title, what is asked, its progress,
        the activity); the right card is "your part" — what this user does now. On a phone the card comes
        right after the title, so the action is never far.
      */}
      <div className="mt-3 grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <header className="flex gap-3 border-b-2 border-primary/70 pb-4 sm:gap-4">
          <span className={cn('mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-full text-white sm:size-12', dot)}><ClipboardList className="size-6" /></span>
          <div className="min-w-0 flex-1 space-y-1">
            <h1 className="break-words text-2xl font-normal leading-tight sm:text-[28px]">
              {title.slice(0, 3).join(', ')}{title.length > 3 && <span className="text-muted-foreground"> +{title.length - 3} lainnya</span>}
            </h1>
            <div className="text-sm text-muted-foreground">
              {[req.requester_name, req.divisi].filter(Boolean).join(' · ')} · {fmtDateTime(req.created_at)}
            </div>
            <div className="flex flex-wrap items-center gap-2 pt-1">
              <span className="font-mono text-sm font-semibold">{req.number}</span>
              <RequestStatusBadge status={req.status} label={req.status_label} />
              {req.urgency === 'URGENT' && <span className="inline-flex items-center gap-1 rounded-md bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200"><AlertTriangle className="size-3.5" />Urgent</span>}
              <span className="text-xs text-muted-foreground">{req.form_label}{req.form_detail_label && req.form_detail ? ` · ${req.form_detail_label}: ${req.form_detail}` : ''}</span>
            </div>
          </div>
          {/* like Classroom's "Tenggat" */}
          <div className="hidden shrink-0 text-right sm:block">
            <div className="text-xs text-muted-foreground">Dibutuhkan</div>
            <div className={cn('text-sm font-medium', overdue && 'text-red-600')}>{req.needed_date ? fmtDate(req.needed_date) : 'Tanpa tanggal'}</div>
            {overdue && <div className="text-xs font-medium text-red-600">Terlambat</div>}
          </div>
        </header>

        {/* ---- your part (right, sticky) */}
        <aside className="space-y-4 lg:col-start-2 lg:row-span-2 lg:row-start-1">
          <div className="space-y-4 lg:sticky lg:top-20">
            <Card className="overflow-hidden rounded-2xl shadow-md">
              <div className="space-y-3 p-4">
                <div className="flex items-center justify-between gap-2">
                  <h2 className="text-lg font-normal">Tindakan Anda</h2>
                  {req.needed_date && <span className={cn('text-xs sm:hidden', overdue ? 'font-medium text-red-600' : 'text-muted-foreground')}>{overdue ? 'Terlambat · ' : 'Perlu '}{fmtDate(req.needed_date)}</span>}
                </div>

                {open.length > 0 ? (
                  // admin gudang: verify, then send to approval
                  <div className="space-y-3">
                    <div>
                      <div className="flex justify-between text-sm"><span>Barang dicocokkan</span><span className="tabular-nums font-medium">{matched} / {open.length}</span></div>
                      <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary transition-all" style={{ width: `${(matched / open.length) * 100}%` }} /></div>
                      <p className="mt-1.5 text-xs text-muted-foreground">Pilih barang Master Produk & jumlahnya di bagian Verifikasi barang. Stok kurang tidak apa-apa: setelah approval sisanya otomatis ke Draft PPB.</p>
                    </div>
                    <div className="space-y-1"><Label htmlFor="pr-pelanggan" className="text-xs">Pelanggan NPBG</Label><Input id="pr-pelanggan" value={pelanggan} maxLength={200} onChange={(e) => setPelanggan(e.target.value)} /></div>
                    <div className="space-y-1"><Label htmlFor="pr-tipe" className="text-xs">Tipe NPBG</Label>
                      <Select id="pr-tipe" value={tipe} onChange={(e) => setTipe(e.target.value as typeof tipe)}><option>Non Penjualan</option><option>Penjualan</option></Select>
                    </div>
                    {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
                    <Button className="w-full" onClick={submit} disabled={proc.isPending}>
                      <CheckCircle2 className="size-4" /> {proc.isPending ? 'Mengirim…' : 'Kirim ke approval'}
                    </Button>
                  </div>
                ) : myTurn ? (
                  <div className="space-y-3">
                    <div>
                      <div className="text-sm font-medium">{req.can_handover ? 'Siapkan & serah terima barang' : req.can_fulfill ? 'Siapkan barang dari stok' : req.approve_as === 'DIREKTUR' && req.status === 'APPROVAL_MANAGER' ? 'Menunggu Manager — Anda bisa langsung approve sebagai Direktur' : `Menunggu approval Anda (${info.who})`}</div>
                      <p className="mt-0.5 text-xs text-muted-foreground">{req.can_approve ? `${info.hint} Ada yang kurang sesuai? Klik Revisi, admin gudang yang memperbaiki.` : info.hint}</p>
                    </div>
                    <div className="[&_button]:w-full [&>div]:flex-col [&>div]:items-stretch">
                      <ApprovalActions req={req} handover={draft} onPrepare={() => prepareRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })} />
                    </div>
                  </div>
                ) : (
                  <div className="flex gap-3 rounded-xl bg-muted/50 p-3 text-sm">
                    <CheckCircle2 className={cn('mt-0.5 size-4 shrink-0', req.status === 'SELESAI' ? 'text-emerald-600' : 'text-muted-foreground')} />
                    <div>
                      <div className="font-medium">{req.status === 'SELESAI' ? 'Selesai — barang sudah diterima' : 'Tidak ada yang perlu Anda lakukan'}</div>
                      <p className="text-xs text-muted-foreground">{info ? `Sekarang: ${info.who ? `${info.who} — ` : ''}${req.status_label}` : req.status_label}</p>
                    </div>
                  </div>
                )}
              </div>

              {(req.track_token || canProcess || canCancel) && (
                <div className="flex flex-wrap gap-1 border-t bg-muted/20 px-2 py-2">
                  {req.track_token && <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={copyLink}><Copy className="size-3.5" /> Salin link status</Button>}
                  {canProcess && (
                    <Button size="sm" variant="ghost" className={cn('h-8 text-xs', req.urgency !== 'URGENT' && 'text-red-700 hover:text-red-800')} disabled={urgency.isPending}
                      onClick={() => urgency.mutate(req.urgency === 'URGENT' ? null : 'URGENT')}>
                      <AlertTriangle className="size-3.5" /> {req.urgency === 'URGENT' ? 'Lepas urgent' : 'Tandai urgent'}
                    </Button>
                  )}
                  {canCancel && (
                    <Button size="sm" variant="ghost" className="h-8 text-xs text-destructive hover:text-destructive" disabled={cancel.isPending}
                      onClick={async () => { if ((await confirmDialog('Batalkan request ini?'))) cancel.mutate() }}>
                      <Ban className="size-3.5" /> Batalkan
                    </Button>
                  )}
                </div>
              )}
            </Card>

            <Card className="hidden overflow-hidden rounded-2xl lg:block">
              <InfoList req={req} />
            </Card>
          </div>
        </aside>

        {/* ---- the request (left) */}
        <main className="min-w-0 space-y-5 lg:col-start-1 lg:row-start-2">
          {sentBack && (
            <div className="flex gap-3 rounded-2xl border border-orange-300 bg-orange-50 p-4 text-sm">
              <Undo2 className="mt-0.5 size-4 shrink-0 text-orange-700" />
              <div>
                <div className="font-semibold text-orange-900">Dikembalikan untuk perbaikan{sentBack.user_name ? ` oleh ${sentBack.user_name}` : ''}</div>
                <p className="text-orange-900/80">{clean(sentBack.note)}</p>
                {canProcess && <p className="mt-1 text-xs text-orange-900/70">Verifikasi ulang barang di bawah, lalu kirim. Request kembali ke persetujuan.</p>}
              </div>
            </div>
          )}

          {req.note && (
            <div className="whitespace-pre-wrap rounded-2xl bg-muted/40 px-4 py-3 text-sm"><span className="mb-0.5 block text-xs text-muted-foreground">Catatan peminta</span>{req.note}</div>
          )}

          <Card className="rounded-2xl px-4 py-4 shadow-sm sm:px-5"><StepsBar steps={req.timeline} /></Card>

          {/* what was asked → approved → given (NPBG) / bought (PPB); while verifying, a click picks the item below */}
          <Panel title={`Barang yang diminta (${req.lines.length})`}
            aside={open.length > 0 ? <span className="flex items-center gap-1 text-xs text-muted-foreground"><MousePointerClick className="size-3.5" /> Klik barang untuk diverifikasi</span> : undefined}>
            <ItemsTable req={req} selectedLineId={open.length > 0 ? selectedLineId : null} onSelectLine={toLine} />
            {(req.images?.length ?? 0) > 0 && (
              <div className="space-y-2 border-t px-4 py-3 sm:px-5">
                <div className="text-xs text-muted-foreground">Gambar dari peminta ({req.images!.length})</div>
                <RequestImages requestId={req.id} slots={req.images} size="size-24" />
              </div>
            )}
          </Panel>

          {/* admin gudang: ONE verification card — the item picked in the table, its recommendations and picks */}
          {open.length > 0 && (
            <section id="verifikasi" className="scroll-mt-20 space-y-2">
              <div className="flex items-center justify-between gap-2 px-1">
                <h2 className="text-[15px] font-medium">Verifikasi barang{selected ? <span className="font-normal text-muted-foreground"> · {selectedIdx + 1} dari {open.length}</span> : null}</h2>
                <div className="flex items-center gap-1">
                  <span className="mr-1 text-xs text-muted-foreground">{matched} dari {open.length} dicocokkan</span>
                  <Button variant="outline" size="sm" className="h-8 px-2" aria-label="Barang sebelumnya" disabled={selectedIdx <= 0} onClick={() => setSelectedLineId(open[selectedIdx - 1].id)}><ChevronLeft className="size-4" /></Button>
                  <Button variant="outline" size="sm" className="h-8 px-2" aria-label="Barang berikutnya" disabled={selectedIdx < 0 || selectedIdx >= open.length - 1} onClick={() => setSelectedLineId(open[selectedIdx + 1].id)}><ChevronRight className="size-4" /></Button>
                </div>
              </div>
              {selected && choices[selected.id] ? (
                <OpenLine key={selected.id} line={selected} requestId={req.id} choice={choices[selected.id]}
                  onChange={(c) => setChoices((x) => ({ ...x, [selected.id]: c }))}
                  saving={match.isPending && match.variables?.lineId === selected.id}
                  onPick={(itemId) => match.mutate({ lineId: selected.id, itemId }, { onError: (e) => setErr(apiErrorMessage(e)) })} />
              ) : (
                <Card className="rounded-2xl p-8 text-center text-sm text-muted-foreground">Klik barang di tabel "Barang yang diminta" untuk memverifikasinya.</Card>
              )}
            </section>
          )}

          <FulfillPanel req={req} panelRef={prepareRef} />
          <div id="konfirmasi" ref={confirmRef} className="scroll-mt-20"><Fulfillments req={req} draft={draft} onDraft={setDraft} /></div>
          <HandoverProof req={req} />

          {/* like Classroom's class comments: what happened, in order */}
          <Panel title="Aktivitas" aside={<MessageSquare className="size-4 text-muted-foreground" />}><Activity req={req} /></Panel>

          <Card className="overflow-hidden rounded-2xl lg:hidden"><InfoList req={req} /></Card>
        </main>
      </div>
    </div>
  )
}

/** The remaining details of the request (contact, how it came in, who verified). */
function InfoList({ req }: { req: ItemRequestDetail }) {
  return (
    <>
      <div className="border-b px-4 py-3 text-[15px] font-medium">Informasi</div>
      <dl className="divide-y px-4 text-sm">
        <Row label="Pemohon">{req.requester_name ?? '-'}</Row>
        {req.requester_contact && <Row label="Kontak">{req.requester_contact}</Row>}
        <Row label="Divisi">{req.divisi || '-'}</Row>
        <Row label="Dikirim via">{req.source === 'LINK' ? 'Link form' : 'Aplikasi'}</Row>
        {req.requester_location && <Row label="Jaringan"><span className={req.requester_location === 'Di Luar Kantor' ? 'text-amber-700' : ''}>{req.requester_location}</span></Row>}
        <Row label="Diverifikasi">{req.processed_at ? `${fmtDateTime(req.processed_at)}${req.processed_by_name ? ` · ${req.processed_by_name}` : ''}` : 'Belum'}</Row>
      </dl>
    </>
  )
}
