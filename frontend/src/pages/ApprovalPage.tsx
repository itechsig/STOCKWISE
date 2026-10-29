import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowLeft, CheckCheck, CheckCircle2, ClipboardCheck, ExternalLink, FileText, Inbox, Loader2, PackageCheck, Search, ShoppingCart, Undo2, XCircle } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { STAGE_INFO, useApprovals, usePurchasingApprovals, type ApprovalRow } from '@/features/itemRequests/api'
import { useDecidePpb, usePpb } from '@/features/ppb/api'
import { usePo, usePoAction } from '@/features/purchasing/api'
import { useDecideNpbg, useNpbg } from '@/features/npbg/api'
import { ApprovalActions, HandoverQty, type HandoverDraft } from '@/components/itemRequests/ApprovalActions'
import { isAutoApproval, stepText } from '@/lib/approval'
import { RequestStatusBadge } from '@/pages/ItemRequestListPage'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { toast } from '@/lib/notify'
import { LineImagesCell, RequestImages } from '@/components/itemRequests/RequestImages'

const fmtQty = (n: number | string | null | undefined) => (n == null ? '' : Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 }))
const rupiah = (n: number) => 'Rp ' + Number(n).toLocaleString('id-ID')
const fmtDate = (v: string | null | undefined) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '')

/** How long a task has waited — the inbox sorts and colours by it (SAP Fiori "My Inbox" style). */
function age(v: string | null | undefined): { text: string; late: boolean } {
  if (!v) return { text: '', late: false }
  const days = Math.floor((Date.now() - new Date(v).getTime()) / 86_400_000)
  return { text: days <= 0 ? 'hari ini' : `${days} hari`, late: days >= 3 }
}

type Kind = 'REQUEST' | 'GUDANG' | 'NPBG' | 'PPB' | 'PO'

/** One thing waiting for this user, whatever document it is. */
interface Task {
  key: string
  kind: Kind
  id: number
  number: string
  title: string
  subtitle: string
  stage: string
  date: string | null
  urgent: boolean
  amount?: number
  preview: string
  request?: ApprovalRow
}

const KIND: Record<Kind, { label: string; icon: typeof FileText; tone: string }> = {
  REQUEST: { label: 'Request Barang', icon: ClipboardCheck, tone: 'bg-blue-50 text-blue-700' },
  GUDANG: { label: 'Gudang', icon: PackageCheck, tone: 'bg-violet-50 text-violet-700' },
  NPBG: { label: 'NPBG', icon: PackageCheck, tone: 'bg-teal-50 text-teal-700' },
  PPB: { label: 'PPB', icon: FileText, tone: 'bg-amber-50 text-amber-700' },
  PO: { label: 'Purchase Order', icon: ShoppingCart, tone: 'bg-emerald-50 text-emerald-700' },
}

/**
 * Approval — ONE inbox for everything waiting for this user: Request Barang (Manajer / Direktur),
 * Gudang (siapkan barang / serah terima), PPB STOCKWISE (Manajer → Direktur) and PO STOCKWISE (Direktur).
 * List on the left, the document and its actions on the right (on a phone: one after the other).
 */
export function ApprovalPage() {
  const [params, setParams] = useSearchParams()
  const { hasPermission } = useAuth()
  const tab = params.get('tab') === 'done' ? 'done' : 'todo'
  const [kind, setKind] = useState<Kind | ''>('')
  const [search, setSearch] = useState('')
  const requests = useApprovals(tab)
  const buying = usePurchasingApprovals(tab === 'todo' && ['ppb.approve_manager', 'ppb.approve_director', 'po.approve_director', 'npbg.approve_manager', 'npbg.approve_director'].some((p) => hasPermission(p)))

  // a Direktur without the Manager right sees the Manager step too and may approve it straight away
  const bypassNpbg = hasPermission('npbg.approve_director') && !hasPermission('npbg.approve_manager')
  const bypassPpb = hasPermission('ppb.approve_director') && !hasPermission('ppb.approve_manager')
  const tasks = useMemo<Task[]>(() => {
    const out: Task[] = []
    for (const r of requests.data?.data ?? []) {
      const gudang = r.status === 'DISIAPKAN' || r.status === 'SIAP_DISERAHKAN'
      const lines = gudang ? r.lines.filter((l) => l.remaining > 0) : r.lines.filter((l) => l.decision === 'NPBG')
      out.push({
        key: `REQUEST-${r.id}`, kind: tab === 'todo' && gudang ? 'GUDANG' : 'REQUEST', id: r.id, number: r.number,
        title: r.requester_name ?? '-', subtitle: [r.form_label, r.divisi].filter(Boolean).join(' · '),
        stage: tab === 'todo' && r.status === 'APPROVAL_MANAGER' && r.approve_as === 'DIREKTUR' ? 'Persetujuan Direktur (lewati Manajer)' : r.status_label,
        date: r.processed_at ?? r.created_at, urgent: r.urgency === 'URGENT',
        preview: lines.map((l) => `${l.deskripsi_barang} ${fmtQty(gudang ? l.remaining : l.approved_qty ?? l.qty)}${l.satuan ? ` ${l.satuan}` : ''}`).join(', '),
        request: r,
      })
    }
    for (const n of buying.data?.npbg ?? []) {
      out.push({ key: `NPBG-${n.id}`, kind: 'NPBG', id: n.id, number: n.no_npbg, title: n.peminta ?? n.pelanggan ?? 'NPBG', subtitle: [n.pelanggan, n.divisi].filter(Boolean).join(' · '),
        stage: n.step === 'DIREKTUR' ? 'Persetujuan Direktur' : bypassNpbg ? 'Persetujuan Direktur (lewati Manajer)' : 'Persetujuan Manajer', date: n.tgl_npbg, urgent: false, preview: n.items.join(', ') })
    }
    for (const d of buying.data?.ppb ?? []) {
      out.push({ key: `PPB-${d.id}`, kind: 'PPB', id: d.id, number: d.no_ppb, title: d.peminta ?? 'PPB', subtitle: [`${d.lines} barang`, d.divisi].filter(Boolean).join(' · '),
        stage: d.step === 'DIREKTUR' ? 'Persetujuan Direktur' : bypassPpb ? 'Persetujuan Direktur (lewati Manajer)' : 'Persetujuan Manajer', date: d.tgl_ppb, urgent: false, preview: d.items.join(', ') })
    }
    for (const p of buying.data?.po ?? []) {
      out.push({ key: `PO-${p.id}`, kind: 'PO', id: p.id, number: p.number, title: p.vendor ?? 'PO', subtitle: `${p.items_count} barang${p.from_ppb ? ` · dari PPB ${p.from_ppb}` : ''}`,
        stage: 'Persetujuan Direktur', date: p.date, urgent: false, amount: p.total, preview: rupiah(p.total) })
    }
    // urgent first, then the oldest
    return out.sort((a, b) => Number(b.urgent) - Number(a.urgent) || (tab === 'done' ? -1 : 1) * String(a.date ?? '').localeCompare(String(b.date ?? '')))
  }, [requests.data, buying.data, tab, bypassNpbg, bypassPpb])

  const counts = useMemo(() => tasks.reduce((m, t) => ({ ...m, [t.kind]: (m[t.kind] ?? 0) + 1 }), {} as Partial<Record<Kind, number>>), [tasks])
  const shown = tasks.filter((t) => (!kind || t.kind === kind)
    && (!search.trim() || `${t.number} ${t.title} ${t.subtitle} ${t.preview}`.toLowerCase().includes(search.trim().toLowerCase())))

  // the open task: ?sel=KIND-id (or ?focus=<request id> from a notification)
  const focus = params.get('focus')
  const selKey = params.get('sel') ?? (focus ? `REQUEST-${focus}` : null)
  const selected = tasks.find((t) => t.key === selKey) ?? null
  const select = (key: string | null) => setParams((p) => { const n = new URLSearchParams(p); n.delete('focus'); if (key) n.set('sel', key); else n.delete('sel'); return n }, { replace: true })
  // desktop: keep something open
  useEffect(() => {
    if (!selected && shown.length && window.matchMedia('(min-width: 1024px)').matches) select(shown[0].key)
  }, [selected, shown.length]) // eslint-disable-line react-hooks/exhaustive-deps

  const loading = (requests.isLoading && !requests.data) || (buying.isLoading && !buying.data && buying.fetchStatus !== 'idle')

  return (
    <div className="space-y-4">
      <PageHeader title="Approval" icon={<ClipboardCheck className="size-5" />}
        subtitle="Semua yang menunggu tindakan Anda (Request Barang, serah terima, PPB, dan PO) di satu tempat." />

      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-lg border bg-card p-0.5" role="tablist">
            {([['todo', 'Perlu tindakan'], ['done', 'Riwayat']] as const).map(([k, label]) => (
              <button key={k} type="button" role="tab" aria-selected={tab === k}
                onClick={() => { setParams(k === 'todo' ? {} : { tab: k }, { replace: true }); setKind('') }}
                className={cn('rounded-md px-3 py-1.5 text-sm font-medium', tab === k ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted')}>
                {label}{k === 'todo' && tab === 'todo' && tasks.length > 0 ? ` (${tasks.length})` : ''}
              </button>
            ))}
          </div>
          {/* Odoo-style filter chips with counts */}
          {tab === 'todo' && (['', 'REQUEST', 'GUDANG', 'NPBG', 'PPB', 'PO'] as const).filter((k) => !k || counts[k]).map((k) => (
            <button key={k || 'all'} type="button" onClick={() => setKind(k)}
              className={cn('rounded-full border px-3 py-1 text-xs font-medium transition-colors', kind === k ? 'border-primary bg-primary/10 text-primary' : 'bg-card text-muted-foreground hover:bg-muted')}>
              {k ? KIND[k].label : 'Semua'} <span className="tabular-nums">{k ? counts[k] : tasks.length}</span>
            </button>
          ))}
        </div>
        <div className="relative lg:w-72">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nomor, nama, barang…" aria-label="Cari" />
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)] lg:items-start">
        {/* the inbox */}
        <Card className={cn('overflow-hidden', selected && 'hidden lg:block')}>
          {loading && <div className="flex justify-center py-16"><Loader2 className="size-6 animate-spin text-muted-foreground" /></div>}
          {!loading && shown.length === 0 && (
            <div className="flex flex-col items-center gap-2 px-4 py-16 text-center">
              <CheckCheck className="size-10 text-emerald-600" />
              <p className="font-medium">{tab === 'todo' ? 'Semua beres, tidak ada yang menunggu Anda' : 'Belum ada riwayat'}</p>
            </div>
          )}
          <ul className="max-h-[calc(100vh-260px)] divide-y overflow-y-auto">
            {shown.map((t) => {
              const K = KIND[t.kind]
              const a = age(t.date)
              return (
                <li key={t.key}>
                  <button type="button" onClick={() => select(t.key)}
                    className={cn('flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50', selected?.key === t.key && 'bg-primary/5 shadow-[inset_3px_0_0_hsl(var(--primary))]')}>
                    <span className={cn('mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md', K.tone)}><K.icon className="size-4" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-baseline justify-between gap-2">
                        <span className="truncate font-medium">{t.title}</span>
                        {tab === 'todo' && a.text && <span className={cn('shrink-0 text-xs', a.late ? 'font-semibold text-red-600' : 'text-muted-foreground')}>{a.text}</span>}
                      </span>
                      <span className="block truncate font-mono text-xs text-muted-foreground">{t.number}</span>
                      <span className="mt-1 block truncate text-xs text-muted-foreground">{t.preview}</span>
                      <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium text-foreground/80">{t.stage}</span>
                        {t.urgent && <span className="rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-semibold text-red-700">Urgent</span>}
                      </span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        </Card>

        {/* the open document */}
        <div className={cn(!selected && 'hidden lg:block')}>
          {selected ? (
            <TaskDetail key={selected.key} task={selected} history={tab === 'done'} onBack={() => select(null)} onDone={() => select(null)} />
          ) : (
            <Card className="flex flex-col items-center gap-2 py-24 text-center text-muted-foreground">
              <Inbox className="size-10" />
              <p>Pilih tugas di sebelah kiri.</p>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

/** Header of a document (SAP object header): what, who, the stage, and the actions. */
function DetailHeader({ task, onBack, link, actions }: { task: Task; onBack: () => void; link: string; actions?: React.ReactNode }) {
  const K = KIND[task.kind]
  return (
    <div className="space-y-3 border-b p-4">
      <button type="button" onClick={onBack} className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground lg:hidden"><ArrowLeft className="size-4" /> Kembali</button>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium', K.tone)}>{K.label}</span>
            <h2 className="font-mono text-lg font-semibold">{task.number}</h2>
            {task.urgent && <span className="rounded bg-red-50 px-1.5 py-0.5 text-xs font-semibold text-red-700">Urgent</span>}
          </div>
          <p className="mt-0.5 text-sm text-muted-foreground">{task.title}{task.subtitle ? ` · ${task.subtitle}` : ''}</p>
          <p className="mt-1 text-xs font-medium text-amber-700">{task.stage}</p>
        </div>
        <Link to={link} className="inline-flex shrink-0 items-center gap-1 text-sm text-primary hover:underline">Buka dokumen <ExternalLink className="size-3.5" /></Link>
      </div>
      {actions}
    </div>
  )
}

/** The "Field: value" grid of an object page. */
function Facts({ items }: { items: [string, React.ReactNode][] }) {
  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 p-4 text-sm sm:grid-cols-4">
      {items.map(([k, v]) => <div key={k}><dt className="text-xs text-muted-foreground">{k}</dt><dd className="font-medium">{v ?? ''}</dd></div>)}
    </dl>
  )
}

/** numericFrom: the first column of numbers (right-aligned); default the last two */
function LinesTable({ head, rows, numericFrom, rowClass }: { head: string[]; rows: React.ReactNode[][]; numericFrom?: number; rowClass?: (i: number) => string | false | undefined }) {
  const from = numericFrom ?? head.length - 2
  return (
    <div className="overflow-x-auto border-t">
      <table className="w-full text-sm">
        <thead className="whitespace-nowrap bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
          <tr>{head.map((h, i) => <th key={h} className={cn('px-4 py-2 font-medium', i > 0 && i >= from && 'text-right')}>{h}</th>)}</tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((r, i) => <tr key={i} className={cn('align-top transition-colors', rowClass?.(i))}>{r.map((c, j) => <td key={j} className={cn('px-4 py-2.5', j > 0 && j >= from && 'whitespace-nowrap text-right tabular-nums')}>{c}</td>)}</tr>)}
        </tbody>
      </table>
    </div>
  )
}

function TaskDetail({ task, history, onBack, onDone }: { task: Task; history: boolean; onBack: () => void; onDone: () => void }) {
  if (task.kind === 'PPB') return <PpbDetail task={task} onBack={onBack} onDone={onDone} />
  if (task.kind === 'NPBG') return <NpbgDetail task={task} onBack={onBack} onDone={onDone} />
  if (task.kind === 'PO') return <PoDetail task={task} onBack={onBack} onDone={onDone} />
  const r = task.request!
  const gudang = task.kind === 'GUDANG'
  const lines = gudang ? r.lines.filter((l) => l.remaining > 0) : r.lines.filter((l) => l.decision === 'NPBG' || history)
  return <RequestDetail task={task} r={r} gudang={gudang} lines={lines} history={history} onBack={onBack} onDone={onDone} />
}

function RequestDetail({ task, r, gudang, lines, history, onBack, onDone }: {
  task: Task; r: ApprovalRow; gudang: boolean; lines: ApprovalRow['lines']; history: boolean; onBack: () => void; onDone: () => void
}) {
  // the inbox refreshes itself (stock included): when it last did
  const live = useApprovals(history ? 'done' : 'todo')
  // Gudang: what is handed over, fixed in the table before the serah terima
  const [draft, setDraft] = useState<HandoverDraft>({ qty: {}, drop: {} })
  const openF = gudang && !history ? r.fulfillments?.find((f) => f.status !== 'DISERAHKAN') ?? null : null
  const waiting = openF ? lines.filter((l) => !openF.lines.some((fl) => fl.line_id === l.id)) : lines
  // Manager / Direktur do not change quantities: "Revisi" sends it to admin gudang to revise them
  const approvedOf = (l: ApprovalRow['lines'][number]) => Number(l.approved_qty ?? l.qty)
  // what the requester asked, in their own words: picking one lights up its verified items on the right
  const askedOf = (l: ApprovalRow['lines'][number]) => (l.deskripsi_peminta ?? l.deskripsi_barang ?? '').trim()
  const keyOf = (l: ApprovalRow['lines'][number]) => askedOf(l).toLowerCase()
  const asked = Object.values(r.lines.reduce<Record<string, { key: string; name: string; qty: number; unit: string | null; n: number }>>((m, l) => {
    const k = keyOf(l)
    m[k] ??= { key: k, name: askedOf(l), qty: 0, unit: l.satuan, n: 0 }
    m[k].qty += Number(l.qty)
    m[k].n += 1
    return m
  }, {}))
  const [picked, setPicked] = useState<string | null>(null)
  const [hovered, setHovered] = useState<string | null>(null)
  const lit = hovered ?? picked
  const litClass = (l: ApprovalRow['lines'][number]) => lit !== null && (keyOf(l) === lit ? 'bg-blue-50 ring-1 ring-inset ring-blue-300' : 'opacity-60')
  const itemCell = (l: ApprovalRow['lines'][number]) => (
    <div>
      <div className="font-medium">{l.item_description ?? l.deskripsi_barang}</div>
      <div className="text-xs text-muted-foreground">{[l.kode_barang, l.satuan, l.item_description && l.item_description !== askedOf(l) ? `diminta: ${askedOf(l)}` : null, l.note].filter(Boolean).join(' · ')}</div>
    </div>
  )
  return (
    <div className="grid gap-4 xl:grid-cols-[15rem_minmax(0,1fr)] xl:items-start">
    <Card className="overflow-hidden xl:sticky xl:top-20">
      <div className="border-b px-4 py-3">
        <div className="text-sm font-semibold">Permintaan</div>
        <div className="text-xs text-muted-foreground">Nama dari peminta · klik untuk menyorot itemnya</div>
      </div>
      <ul className="divide-y">
        {asked.map((a) => (
          <li key={a.key}>
            <button type="button" onClick={() => setPicked(picked === a.key ? null : a.key)} onMouseEnter={() => setHovered(a.key)} onMouseLeave={() => setHovered(null)}
              aria-pressed={picked === a.key}
              className={cn('flex w-full items-start justify-between gap-2 px-4 py-2.5 text-left text-sm transition-colors hover:bg-blue-50',
                picked === a.key && 'bg-blue-100 font-semibold text-blue-900 shadow-[inset_3px_0_0_theme(colors.blue.500)]')}>
              <span className="min-w-0 break-words">{a.name}</span>
              <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{fmtQty(a.qty)} {a.unit ?? ''}{a.n > 1 ? ` · ${a.n} item` : ''}</span>
            </button>
          </li>
        ))}
      </ul>
      {picked && <button type="button" className="w-full border-t px-4 py-2 text-left text-xs text-primary hover:underline" onClick={() => setPicked(null)}>Tampilkan semua</button>}
    </Card>
    <Card className="overflow-hidden">
      <DetailHeader task={task} onBack={onBack} link={`/item-requests/${r.id}`}
        actions={!history && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted/40 p-3">
            <p className="text-xs text-muted-foreground">{STAGE_INFO[r.status]?.hint}{r.can_approve ? ' Ada yang kurang sesuai? Klik Revisi, admin gudang yang memperbaiki.' : ''}</p>
            <ApprovalActions req={r} onDone={onDone} handover={openF ? draft : undefined} />
          </div>
        )} />
      <Facts items={[['Pemohon', r.requester_name], ['Divisi', r.divisi], ['Dibutuhkan', fmtDate(r.needed_date)], ['Status', <RequestStatusBadge status={r.status} label={r.status_label} />]]} />
      {r.note && <p className="border-t px-4 py-3 text-sm"><span className="text-muted-foreground">Keperluan: </span>{r.note}</p>}
      {/* the requester's images: Manager, Direktur and Gudang see what is asked */}
      {(r.images?.length ?? 0) > 0 && (
        <div className="space-y-1.5 border-t px-4 py-3">
          <div className="text-xs text-muted-foreground">Gambar dari peminta</div>
          <RequestImages requestId={r.id} slots={r.images} />
        </div>
      )}
      {openF && (
        // Gudang: only what is handed over (default: the approved NPBG quantity, can be lowered)
        <div className="border-t">
          <div className="px-4 pb-1 pt-3 text-xs text-muted-foreground">NPBG <span className="font-mono">{openF.no_npbg}</span>{openF.seq > 1 ? ` (#${openF.seq})` : ''}</div>
          <HandoverQty f={openF} value={draft} onChange={setDraft} stock={Object.fromEntries(r.lines.map((l) => [l.id, l.on_hand ?? l.available]))}
            images={{ requestId: r.id, byLine: Object.fromEntries(r.lines.map((l) => [l.id, l.images ?? []])) }} />
        </div>
      )}
      {gudang && !history && (
        <p className="border-t px-4 py-2 text-right text-xs text-muted-foreground">
          Stok realtime{live.dataUpdatedAt ? `, diperbarui ${new Date(live.dataUpdatedAt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}` : ''}
        </p>
      )}
      {gudang ? (!openF && waiting.length > 0 && (
        <>
          <LinesTable head={['Item terverifikasi', 'Stok', 'Disetujui', 'Sisa', 'Gambar']} numericFrom={1} rowClass={(i) => litClass(waiting[i])}
          rows={waiting.map((l) => [
            itemCell(l),
            <StockCell onHand={l.on_hand} free={l.available} />,
            fmtQty(l.approved_qty), fmtQty(l.remaining),
            <LineImagesCell requestId={r.id} lineId={l.id} slots={l.images} />,
          ])} />
        </>
      )) : (
        // what the stock can give now (NPBG) and what has to be bought (PPB) — decided by the system after the Direktur
        <LinesTable head={['Item terverifikasi', 'Diminta', 'Disetujui', 'Stok', 'NPBG', 'PPB', 'Gambar']} numericFrom={1} rowClass={(i) => litClass(lines[i])}
          rows={lines.map((l) => {
            const need = approvedOf(l)
            const stock = Math.max(0, l.available ?? 0)
            const npbg = l.item_id ? Math.min(need, stock) : 0
            return [
              itemCell(l),
              fmtQty(l.qty),
              fmtQty(need),
              <StockCell onHand={l.on_hand} free={l.available} tone={l.available == null ? 'text-muted-foreground' : stock >= need ? 'text-emerald-700' : 'text-red-600'} />,
              <span className="font-semibold text-emerald-700" title="Dikeluarkan dari stok (perkiraan)">{fmtQty(npbg)}</span>,
              <span className="font-semibold text-indigo-700" title="Dibeli lewat PPB sampai RI (perkiraan)">{fmtQty(Math.round((need - npbg) * 100) / 100)}</span>,
              <LineImagesCell requestId={r.id} lineId={l.id} slots={l.images} />,
            ]
          })} />
      )}
      {!gudang && !history && <p className="border-t px-4 py-2 text-xs text-muted-foreground">NPBG = bisa dikeluarkan dari stok sekarang · PPB = stok tidak ada, dibeli sampai RI. Angka final ditentukan sistem saat Direktur menyetujui.</p>}
      {r.last_step && (
        <p className="border-t px-4 py-3 text-xs text-muted-foreground">Langkah terakhir: {stepText(r.last_step.action, r.last_step.note, r.last_step.stage)}{isAutoApproval(r.last_step.note) ? '' : ` · ${r.last_step.user_name ?? 'Sistem'}`}{r.last_step.note && !isAutoApproval(r.last_step.note) ? ` · ${r.last_step.note}` : ''}</p>
      )}
    </Card>
    </div>
  )
}

/**
 * Approve / Revisi (back to the admin who made it, with a required note) / Tolak — the same bar for PPB,
 * NPBG and PO. Every change goes through Revisi: the approver does not edit the document.
 */
function DecisionBar({ approveLabel, rejectLabel, busy, onApprove, onReject, onReturn, returnTo = 'admin', hint, withNote }: {
  approveLabel: string; rejectLabel?: string; busy: boolean; onApprove: (note?: string) => void; onReject?: (note: string) => void; hint?: string
  /** Revisi: send it back for a revision (a note is required) */
  onReturn?: (note: string) => void
  /** who revises it (PO: purchasing) */
  returnTo?: string
  /** a note goes with the approval (everyone sees it) */
  withNote?: boolean
}) {
  const [rejecting, setRejecting] = useState<false | 'reject' | 'return'>(false)
  const [note, setNote] = useState('')
  const [approveNote, setApproveNote] = useState('')
  if (rejecting) {
    const back = rejecting === 'return'
    // an empty note is a validation (😆), not a greyed-out button
    const submit = () => {
      if (!note.trim()) return toast.invalid(back ? 'Catatan wajib diisi: tulis kenapa direvisi.' : 'Alasan penolakan wajib diisi.')
      if (back) onReturn?.(note.trim())
      else onReject?.(note.trim())
    }
    return (
      <div className={back ? 'space-y-2 rounded-lg border border-orange-200 bg-orange-50/60 p-3' : 'space-y-2 rounded-lg border border-red-200 bg-red-50/60 p-3'}>
        <Input autoFocus value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} aria-label="Catatan"
          onKeyDown={(e) => { if (e.key === 'Enter') submit() }}
          placeholder={back ? 'Kenapa direvisi? Apa yang perlu diubah? (wajib)' : 'Alasan ditolak (wajib)'} />
        {back && <p className="text-xs text-orange-900/80">Dikembalikan ke {returnTo} untuk direvisi, lalu diajukan ulang ke approval.</p>}
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="ghost" onClick={() => setRejecting(false)}>Batal</Button>
          {back ? (
            <Button size="sm" className="bg-orange-600 text-white hover:bg-orange-700" disabled={busy} onClick={submit}><Undo2 className="size-4" /> Kirim revisi ke {returnTo}</Button>
          ) : (
            <Button size="sm" className="bg-red-600 text-white hover:bg-red-700" disabled={busy} onClick={submit}><XCircle className="size-4" /> {rejectLabel}</Button>
          )}
        </div>
      </div>
    )
  }
  return (
    <div className="space-y-2 rounded-lg bg-muted/40 p-3">
      {withNote && (
        <Input value={approveNote} maxLength={1000} onChange={(e) => setApproveNote(e.target.value)} aria-label="Catatan persetujuan"
          className="bg-card" placeholder="Catatan (opsional), terlihat oleh semua" />
      )}
      <div className="flex flex-wrap items-center justify-between gap-3">
      <p className="text-xs text-muted-foreground">{hint}</p>
      <div className="flex gap-2">
        {onReturn && <Button size="sm" variant="outline" className="border-orange-300 text-orange-700 hover:bg-orange-50" disabled={busy} onClick={() => setRejecting('return')}><Undo2 className="size-4" /> Revisi</Button>}
        {onReject && <Button size="sm" variant="outline" className="border-red-300 text-red-700 hover:bg-red-50" disabled={busy} onClick={() => setRejecting('reject')}><XCircle className="size-4" /> {rejectLabel}</Button>}
        <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" disabled={busy} onClick={() => onApprove(withNote ? approveNote.trim() || undefined : undefined)}>
          {busy ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />} {approveLabel}
        </Button>
      </div>
      </div>
    </div>
  )
}

/** Stock of Master Produk; what prepared NPBG hold for other requests is said under it (it leaves with their serah terima). */
function StockCell({ onHand, free, tone }: { onHand?: number | null; free?: number | null; tone?: string }) {
  const stock = onHand ?? free
  if (stock == null) return <span className="text-muted-foreground">?</span>
  const held = free != null && onHand != null ? Math.round((onHand - free) * 100) / 100 : 0
  return (
    <span className="inline-flex flex-col items-end">
      <span className={tone ?? (stock > 0 ? 'text-emerald-700' : 'text-muted-foreground')}>{fmtQty(stock)}</span>
      {held > 0 && <span className="text-[11px] text-muted-foreground">dipesan {fmtQty(held)}</span>}
    </span>
  )
}

// Manager / Direktur do not change anything (PPB, NPBG): what is not right goes back to admin with "Revisi"
const QTY_HINT = 'Ada yang kurang sesuai? Klik Revisi, admin yang memperbaiki.'

function PpbDetail({ task, onBack, onDone }: { task: Task; onBack: () => void; onDone: () => void }) {
  const { data: ppb, isLoading } = usePpb(task.id)
  const decide = useDecidePpb(task.id)
  const [err, setErr] = useState<string | null>(null)
  if (isLoading || !ppb) return <Card className="flex justify-center py-24"><Loader2 className="size-6 animate-spin text-muted-foreground" /></Card>
  const items = ppb.document_items ?? []
  const run = (action: 'APPROVE' | 'REJECT' | 'RETURN', note?: string) =>
    decide.mutate({ action, note: note ?? null }, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
  return (
    <Card className="overflow-hidden">
      <DetailHeader task={task} onBack={onBack} link={`/ppb/${ppb.id}`}
        actions={ppb.can_decide && <>
          <DecisionBar approveLabel={task.stage.includes('Direktur') ? 'Approve (Direktur)' : 'Approve (Manajer)'} rejectLabel="Tolak" busy={decide.isPending}
            hint={`${task.stage.includes('Direktur') ? 'Setelah disetujui, PPB masuk tabel PO untuk dilengkapi vendor & harga.' : 'Setelah disetujui Manajer, PPB menunggu Direktur.'} ${QTY_HINT}`}
            withNote
            onApprove={(n) => run('APPROVE', n)} onReject={(n) => run('REJECT', n)} onReturn={(n) => run('RETURN', n)} />
          {err && <p className="text-sm text-destructive">{err}</p>}
        </>} />
      <Facts items={[['Tanggal PPB', fmtDate(ppb.tgl_ppb)], ['Diperlukan', fmtDate(ppb.tgl_diperlukan)], ['Divisi', ppb.divisi], ['Barang', `${items.length} baris`]]} />
      <LinesTable head={['Barang', 'Peminta', 'Qty']}
        rows={items.map((it) => [
          <div><div className="font-medium">{it.deskripsi_barang}</div><div className="font-mono text-xs text-muted-foreground">{it.kode_barang ?? ''}</div>{it.catatan_baris && <div className="text-xs text-muted-foreground">{it.catatan_baris}</div>}</div>,
          it.peminta ?? '',
          `${fmtQty(it.kuantitas)} ${it.satuan ?? ''}`,
        ])} />
      {(ppb.approval_log?.length ?? 0) > 0 && (
        <ol className="space-y-1 border-t px-4 py-3 text-xs text-muted-foreground">
          {ppb.approval_log!.map((l) => <li key={l.id}>{fmtDate(l.created_at)} · <span className="font-medium text-foreground">{stepText(l.action, l.note)}</span>{isAutoApproval(l.note) ? '' : ` · ${l.user_name ?? 'Sistem'}${l.note ? `, ${l.note}` : ''}`}</li>)}
        </ol>
      )}
    </Card>
  )
}

function NpbgDetail({ task, onBack, onDone }: { task: Task; onBack: () => void; onDone: () => void }) {
  const { data: n, isLoading } = useNpbg(task.id)
  const decide = useDecideNpbg(task.id)
  const [err, setErr] = useState<string | null>(null)
  if (isLoading || !n) return <Card className="flex justify-center py-24"><Loader2 className="size-6 animate-spin text-muted-foreground" /></Card>
  const run = (action: 'APPROVE' | 'REJECT' | 'RETURN', note?: string) =>
    decide.mutate({ action, note: note ?? null }, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
  return (
    <Card className="overflow-hidden">
      <DetailHeader task={task} onBack={onBack} link={`/npbg/${n.id}`}
        actions={n.can_decide && <>
          <DecisionBar approveLabel={task.stage.includes('Direktur') ? 'Approve (Direktur)' : 'Approve (Manajer)'} rejectLabel="Tolak" busy={decide.isPending}
            hint={`${task.stage.includes('Direktur') ? 'Setelah disetujui, barang langsung dikeluarkan dari stok.' : 'Setelah disetujui Manajer, NPBG menunggu Direktur.'} ${QTY_HINT}`}
            withNote
            onApprove={(note) => run('APPROVE', note)} onReject={(note) => run('REJECT', note)} onReturn={(note) => run('RETURN', note)} />
          {err && <p className="text-sm text-destructive">{err}</p>}
        </>} />
      <Facts items={[['Tanggal', fmtDate(n.tgl_npbg)], ['Pelanggan', n.pelanggan], ['Peminta', n.peminta], ['Tipe', n.tipe_npbg]]} />
      <LinesTable head={['Barang', 'Keterangan', 'Qty']}
        rows={[[
          <div><div className="font-medium">{n.deskripsi_barang}</div><div className="font-mono text-xs text-muted-foreground">{n.kode_barang ?? ''}</div></div>,
          n.deskripsi ?? n.keterangan ?? '',
          `${fmtQty(n.kuantitas)} ${n.satuan ?? ''}`,
        ]]} />
    </Card>
  )
}

function PoDetail({ task, onBack, onDone }: { task: Task; onBack: () => void; onDone: () => void }) {
  const { data: po, isLoading } = usePo(task.id)
  const action = usePoAction(task.id)
  const [err, setErr] = useState<string | null>(null)
  if (isLoading || !po) return <Card className="flex justify-center py-24"><Loader2 className="size-6 animate-spin text-muted-foreground" /></Card>
  const run = (name: string, body?: unknown) => action.mutate({ action: name, body }, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
  return (
    <Card className="overflow-hidden">
      <DetailHeader task={task} onBack={onBack} link={`/purchase-orders/${po.id}`}
        actions={po.can_approve && <>
          <DecisionBar approveLabel="Approve (Direktur)" busy={action.isPending} returnTo="purchasing"
            hint="Setelah disetujui, purchasing mengirim PO ke vendor. Ada yang kurang sesuai? Klik Revisi, purchasing yang memperbaiki."
            onApprove={() => run('approve')} onReturn={(n) => run('reject', { note: n })} />
          {err && <p className="text-sm text-destructive">{err}</p>}
        </>} />
      <Facts items={[['Vendor', (po.vendors?.length ?? 0) > 1 ? po.vendors!.map((v) => v.name).join(' · ') : po.vendor?.name ?? po.vendor_name], ['Tanggal', fmtDate(po.date)], ['Perkiraan tiba', fmtDate(po.expected_date)], ['Total', <span className="tabular-nums">{rupiah(po.total)}</span>]]} />
      <LinesTable head={['Barang', 'Qty', 'Harga', 'Subtotal']}
        rows={(po.items ?? []).map((l) => [
          <div><div className="font-medium">{l.description}</div><div className="font-mono text-xs text-muted-foreground">{[l.item_code, (po.vendors?.length ?? 0) > 1 ? l.vendor_name : null].filter(Boolean).join(' · ')}</div></div>,
          `${fmtQty(l.qty)} ${l.unit ?? ''}`, rupiah(l.unit_price), rupiah(l.line_total),
        ])} />
      {po.notes && <p className="border-t px-4 py-3 text-xs text-muted-foreground">{po.notes}</p>}
    </Card>
  )
}
