import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, Ban, CheckCircle2, ChevronLeft, ChevronRight, ClipboardList, Hourglass, Link2, PackageCheck, Plus, Search, Send, ShieldCheck, SlidersHorizontal, Truck, X, XCircle } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import {
  REQUEST_STATUS, useCreateItemRequest, useItemRequests, useRequestForms,
  type FormType, type ItemRequest, type ItemRequestStatus,
} from '@/features/itemRequests/api'
import { FormExtraFields } from '@/components/itemRequests/FormExtraFields'
import { RequestLinesEditor, emptyLine, filledLines, linesError, toPayloadLines, type DraftLine } from '@/components/itemRequests/RequestLinesEditor'
import { RequestLinksModal } from '@/components/itemRequests/RequestLinksModal'
import { useRemembered } from '@/lib/listMemory'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { Select } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { invalidTo } from '@/lib/notify'

const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '-')

/** Needed by a date already past while the request is still running. */
const isLate = (r: ItemRequest) => !!r.needed_date && !['SELESAI', 'DITOLAK', 'DIBATALKAN'].includes(r.status) && new Date(r.needed_date) < new Date(new Date().toDateString())

/** One color per stage, used everywhere a request status shows (list, detail, approval): [pill, dot]. */
const STATUS_TONE: Record<ItemRequestStatus, [string, string]> = {
  MENUNGGU: ['bg-amber-50 text-amber-800 ring-amber-200', 'bg-amber-500'],
  DIPROSES: ['bg-amber-50 text-amber-800 ring-amber-200', 'bg-amber-500'],
  PERLU_PERBAIKAN: ['bg-orange-50 text-orange-800 ring-orange-200', 'bg-orange-500'],
  APPROVAL_MANAGER: ['bg-blue-50 text-blue-800 ring-blue-200', 'bg-blue-500'],
  APPROVAL_DIREKTUR: ['bg-blue-50 text-blue-800 ring-blue-200', 'bg-blue-500'],
  DISIAPKAN: ['bg-violet-50 text-violet-800 ring-violet-200', 'bg-violet-500'],
  SIAP_DISERAHKAN: ['bg-cyan-50 text-cyan-800 ring-cyan-200', 'bg-cyan-500'],
  DIPENUHI_SEBAGIAN: ['bg-teal-50 text-teal-800 ring-teal-200', 'bg-teal-500'],
  MENUNGGU_PENGADAAN: ['bg-indigo-50 text-indigo-800 ring-indigo-200', 'bg-indigo-500'],
  MENUNGGU_BARANG: ['bg-indigo-50 text-indigo-800 ring-indigo-200', 'bg-indigo-500'],
  SELESAI: ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'bg-emerald-500'],
  DITOLAK: ['bg-red-50 text-red-800 ring-red-200', 'bg-red-500'],
  DIBATALKAN: ['bg-slate-100 text-slate-600 ring-slate-200', 'bg-slate-400'],
}

/** label: the server's clearer wording when given (e.g. "NPBG partial & menunggu pengadaan") */
export function RequestStatusBadge({ status, label }: { status: ItemRequest['status']; label?: string | null }) {
  const [pill, dot] = STATUS_TONE[status] ?? STATUS_TONE.DIBATALKAN
  return (
    <span className={cn('inline-flex max-w-[18rem] items-start gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', pill)}>
      <span className={cn('mt-[5px] size-1.5 shrink-0 rounded-full', dot)} />
      {label || REQUEST_STATUS[status]?.label || status}
    </span>
  )
}

const RUNNING: ItemRequestStatus[] = ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN', 'APPROVAL_MANAGER', 'APPROVAL_DIREKTUR', 'DISIAPKAN', 'SIAP_DISERAHKAN',
  'DIPENUHI_SEBAGIAN', 'MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG']

/** The main tabs (like Classroom's Ditugaskan / Selesai): few and plain. */
const TABS: { key: string; label: string; statuses: ItemRequestStatus[] }[] = [
  { key: RUNNING.join(','), label: 'Berjalan', statuses: RUNNING },
  { key: 'SELESAI', label: 'Selesai', statuses: ['SELESAI'] },
  { key: 'DITOLAK,DIBATALKAN', label: 'Ditolak & batal', statuses: ['DITOLAK', 'DIBATALKAN'] },
  { key: '', label: 'Semua', statuses: [] },
]

/** Inside "Berjalan": where each one stands. */
const STAGES: { key: string; label: string; statuses: ItemRequestStatus[] }[] = [
  { key: 'MENUNGGU,DIPROSES,PERLU_PERBAIKAN', label: 'Menunggu verifikasi', statuses: ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN'] },
  { key: 'APPROVAL_MANAGER,APPROVAL_DIREKTUR', label: 'Dalam approval', statuses: ['APPROVAL_MANAGER', 'APPROVAL_DIREKTUR'] },
  { key: 'DISIAPKAN', label: 'Disiapkan gudang', statuses: ['DISIAPKAN'] },
  { key: 'SIAP_DISERAHKAN', label: 'Siap diambil', statuses: ['SIAP_DISERAHKAN'] },
  { key: 'DIPENUHI_SEBAGIAN,MENUNGGU_PENGADAAN,MENUNGGU_BARANG', label: 'Menunggu barang', statuses: ['DIPENUHI_SEBAGIAN', 'MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG'] },
]

/** One icon per stage, in the stage's color (the round icon of each card). */
const STATUS_ICON: Record<ItemRequestStatus, typeof ClipboardList> = {
  MENUNGGU: ClipboardList, DIPROSES: ClipboardList, PERLU_PERBAIKAN: AlertTriangle,
  APPROVAL_MANAGER: ShieldCheck, APPROVAL_DIREKTUR: ShieldCheck,
  DISIAPKAN: PackageCheck, SIAP_DISERAHKAN: PackageCheck,
  DIPENUHI_SEBAGIAN: Truck, MENUNGGU_PENGADAAN: Hourglass, MENUNGGU_BARANG: Truck,
  SELESAI: CheckCircle2, DITOLAK: XCircle, DIBATALKAN: Ban,
}

/** "Hari ini" / "Kemarin" / "Minggu ini" / "Bulan ini" / "Lebih lama" — the list is newest first */
function dayGroup(v: string): string {
  const d = new Date(v), today = new Date(new Date().toDateString())
  const days = Math.floor((today.getTime() - new Date(d.toDateString()).getTime()) / 86400000)
  if (days <= 0) return 'Hari ini'
  if (days === 1) return 'Kemarin'
  if (days < 7) return 'Minggu ini'
  if (d.getMonth() === today.getMonth() && d.getFullYear() === today.getFullYear()) return 'Bulan ini'
  return 'Lebih lama'
}

function Pager({ page, lastPage, total, perPage, onPage, onPerPage }: {
  page: number; lastPage: number; total: number; perPage: number; onPage: (p: number) => void; onPerPage: (n: number) => void
}) {
  const from = total === 0 ? 0 : (page - 1) * perPage + 1
  const to = Math.min(page * perPage, total)
  // 1 … 4 5 [6] 7 8 … 20
  const pages = [...new Set([1, page - 2, page - 1, page, page + 1, page + 2, lastPage])].filter((p) => p >= 1 && p <= lastPage).sort((a, b) => a - b)
  return (
    <div className="flex flex-col gap-2 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-center gap-3 text-muted-foreground">
        <span>{from}–{to} dari {total.toLocaleString('id-ID')}</span>
        <span className="hidden sm:inline">·</span>
        <label className="hidden items-center gap-1.5 sm:flex">
          Baris
          <select className="h-8 rounded-md border bg-card px-1.5 text-sm text-foreground" value={perPage} onChange={(e) => onPerPage(Number(e.target.value))}>
            {[10, 20, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </label>
      </div>
      <div className="flex items-center gap-1">
        <Button variant="outline" size="sm" className="h-8 px-2" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Sebelumnya"><ChevronLeft className="size-4" /></Button>
        {pages.map((p, i) => (
          <span key={p} className="flex items-center">
            {i > 0 && p - pages[i - 1] > 1 && <span className="px-1 text-muted-foreground">…</span>}
            <button type="button" onClick={() => onPage(p)} aria-current={p === page ? 'page' : undefined}
              className={cn('h-8 min-w-8 rounded-md px-2 text-sm tabular-nums', p === page ? 'bg-primary font-semibold text-primary-foreground' : 'hover:bg-muted')}>{p}</button>
          </span>
        ))}
        <Button variant="outline" size="sm" className="h-8 px-2" disabled={page >= lastPage} onClick={() => onPage(page + 1)} aria-label="Berikutnya"><ChevronRight className="size-4" /></Button>
      </div>
    </div>
  )
}

function NewRequestModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const create = useCreateItemRequest()
  const [formType, setFormType] = useState<FormType>('ATK')
  const forms = useRequestForms()
  const [detail, setDetail] = useState('')
  const form = forms.data?.find((f) => f.type === formType)
  const [divisi, setDivisi] = useState('')
  // the form's own divisi as a starting point (only while the field is still empty or another form's default)
  useEffect(() => {
    const defaults = (forms.data ?? []).map((f) => f.default_divisi).filter(Boolean)
    setDivisi((d) => (!d || defaults.includes(d) ? form?.default_divisi ?? '' : d))
  }, [form, forms.data])
  const [neededDate, setNeededDate] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [err, setErr] = useState<string | null>(null)

  const submit = () => {
    setErr(null)
    const lineErr = linesError(lines)
    if (lineErr) return invalidTo(setErr, lineErr)
    create.mutate({
      form_type: formType, form_detail: form?.detail_label ? detail.trim() || null : null,
      divisi: divisi.trim() || null, needed_date: neededDate || null, lines: toPayloadLines(lines),
    }, {
      onSuccess: (r) => { onClose(); setLines([emptyLine()]); setDetail(''); navigate(`/item-requests/${r.id}`) },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  return (
    <Modal open={open} onClose={onClose} title="Buat Request Barang" className="max-w-4xl"
      description="Tulis nama barang sebebasnya. Admin gudang yang mencocokkan dengan stok, lalu sistem menyiapkan NPBG atau pembelian (PPB)."
      footer={<>
        <span className="mr-auto hidden text-xs text-muted-foreground sm:inline">{filledLines(lines).length} barang · * wajib diisi</span>
        <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
        <Button size="sm" onClick={submit} disabled={create.isPending}><Send className="size-4" /> {create.isPending ? 'Mengirim…' : 'Kirim request'}</Button>
      </>}>
      <div className="space-y-4">
        <section className="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-1.5">
            <Label htmlFor="new-form">Jenis form</Label>
            <Select id="new-form" value={formType} onChange={(e) => setFormType(e.target.value as FormType)}>
              {(forms.data ?? []).map((f) => <option key={f.type} value={f.type}>{f.label}</option>)}
            </Select>
          </div>
          <div className="space-y-1.5"><Label htmlFor="new-divisi">Divisi</Label><Input id="new-divisi" value={divisi} maxLength={100} onChange={(e) => setDivisi(e.target.value)} placeholder="mis. Maintenance" /></div>
          <div className="space-y-1.5"><Label htmlFor="new-date">Dibutuhkan tanggal</Label><Input id="new-date" type="date" value={neededDate} onChange={(e) => setNeededDate(e.target.value)} /></div>
          {form?.detail_label && <FormExtraFields form={form} detail={detail} onDetail={setDetail} idPrefix="new" />}
        </section>
        <RequestLinesEditor lines={lines} onChange={setLines} />
        {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}

/**
 * Request Barang, laid out like Google Classroom's to-do: a banner, a few plain tabs, then one card per
 * request grouped by day — what was asked, who asked, when it is needed, where it stands and one action.
 * A karyawan sees only their own requests; admin gudang all of them.
 */
export function ItemRequestListPage() {
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const [search, setSearch] = useRemembered('item-requests', 'search', '')
  const [status, setStatus] = useRemembered('item-requests', 'flow', RUNNING.join(','))
  const [dateFrom, setDateFrom] = useRemembered('item-requests', 'dateFrom', '')
  const [dateTo, setDateTo] = useRemembered('item-requests', 'dateTo', '')
  const [requester, setRequester] = useRemembered('item-requests', 'requester', '')
  const [formType, setFormType] = useRemembered('item-requests', 'formType', '')
  const [page, setPage] = useRemembered('item-requests', 'page', 1)
  const [perPage, setPerPage] = useRemembered('item-requests', 'perPage', 20)
  const [creating, setCreating] = useState(false)
  const [sharing, setSharing] = useState(false)
  const forms = useRequestForms()
  // typing waits a moment before it asks the server
  const [typed, setTyped] = useState(search)
  useEffect(() => { const t = setTimeout(() => { if (typed !== search) { setSearch(typed); setPage(1) } }, 350); return () => clearTimeout(t) }, [typed])

  const { data, isLoading, isFetching } = useItemRequests({
    search: search || undefined, status: status || undefined, form_type: formType || undefined, requester: requester || undefined,
    date_from: dateFrom || undefined, date_to: dateTo || undefined, page, per_page: perPage,
  })
  const rows = data?.data ?? []
  const counts = data?.meta.status_counts ?? {}
  const countOf = (statuses: ItemRequestStatus[]) => (statuses.length ? statuses : (Object.keys(counts) as ItemRequestStatus[])).reduce((n, x) => n + (counts[x] ?? 0), 0)
  const canProcess = hasPermission('item_request.process')
  const extraFilters = [dateFrom || dateTo, requester, formType].filter(Boolean).length
  const [showFilters, setShowFilters] = useState(extraFilters > 0)
  const filtered = !!search || extraFilters > 0
  const reset = () => { setTyped(''); setSearch(''); setDateFrom(''); setDateTo(''); setRequester(''); setFormType(''); setPage(1) }
  const set = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1) }
  const pick = (key: string) => { setStatus(key); setPage(1) }
  // the tab a stage belongs to stays lit
  const tabOn = (t: (typeof TABS)[number]) => status === t.key || (t.statuses === RUNNING && STAGES.some((x) => x.key === status))
  const inRunning = status === RUNNING.join(',') || STAGES.some((x) => x.key === status)
  const toVerify = (r: ItemRequest) => canProcess && ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN'].includes(r.status)

  // the cards, grouped by the day they were made
  const groups: [string, ItemRequest[]][] = []
  for (const r of rows) {
    const g = dayGroup(r.created_at)
    if (groups.at(-1)?.[0] === g) groups.at(-1)![1].push(r)
    else groups.push([g, [r]])
  }

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      {/* banner, like a Classroom class header */}
      <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary to-blue-700 px-5 py-6 text-primary-foreground shadow-sm sm:px-8 sm:py-8">
        <ClipboardList aria-hidden className="pointer-events-none absolute -right-6 -top-6 size-40 opacity-10 sm:size-52" />
        <div className="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div className="min-w-0">
            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Request Barang</h1>
            <p className="mt-1 max-w-xl text-sm text-primary-foreground/85">
              {canProcess ? 'Cocokkan permintaan karyawan dengan Master Produk, lalu kirim ke approval. Stok kurang otomatis dibeli lewat Draft PPB.' : 'Minta barang ke gudang dan pantau sampai barangnya Anda terima.'}
            </p>
          </div>
          <div className="flex shrink-0 flex-wrap gap-2">
            {canProcess && <Button size="sm" variant="secondary" className="bg-white/15 text-white hover:bg-white/25" onClick={() => setSharing(true)}><Link2 className="size-4" /> Link form</Button>}
            {hasPermission('item_request.create') && <Button size="sm" className="bg-white text-primary hover:bg-white/90" onClick={() => setCreating(true)}><Plus className="size-4" /> Buat Request</Button>}
          </div>
        </div>
      </section>

      {/* tabs + search */}
      <div className="space-y-3">
        <div className="flex flex-col gap-3 border-b md:flex-row md:items-end md:justify-between">
          <div className="-mb-px flex overflow-x-auto" role="tablist" aria-label="Request">
            {TABS.map((t) => (
              <button key={t.label} type="button" role="tab" aria-selected={tabOn(t)} onClick={() => pick(t.key)}
                className={cn('flex shrink-0 items-center gap-2 border-b-[3px] px-4 pb-2.5 pt-1 text-sm font-medium transition-colors',
                  tabOn(t) ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>
                {t.label}
                <span className={cn('rounded-full px-1.5 text-xs tabular-nums', tabOn(t) ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground')}>{countOf(t.statuses)}</span>
              </button>
            ))}
          </div>
          <div className="flex gap-2 pb-2">
            <div className="relative min-w-0 flex-1 md:w-72">
              <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input className="h-9 rounded-full bg-card pl-9" placeholder="Cari no., pemohon, barang…" value={typed} onChange={(e) => setTyped(e.target.value)} aria-label="Cari" />
            </div>
            <Button variant="outline" size="sm" className={cn('h-9 rounded-full', extraFilters > 0 && 'border-primary text-primary')} onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>
              <SlidersHorizontal className="size-4" /> Filter{extraFilters > 0 ? ` (${extraFilters})` : ''}
            </Button>
          </div>
        </div>

        {showFilters && (
          <div className="grid grid-cols-2 gap-2 rounded-xl border bg-card p-3 sm:grid-cols-4 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
            <Input type="date" className="h-9" value={dateFrom} onChange={(e) => set(setDateFrom)(e.target.value)} aria-label="Dari tanggal" title="Dari tanggal" />
            <Input type="date" className="h-9" value={dateTo} onChange={(e) => set(setDateTo)(e.target.value)} aria-label="Sampai tanggal" title="Sampai tanggal" />
            <Select className="h-9" value={requester} onChange={(e) => set(setRequester)(e.target.value)} aria-label="Pemohon">
              <option value="">Semua pemohon</option>
              {(data?.meta.requesters ?? []).map((n) => <option key={n} value={n}>{n}</option>)}
            </Select>
            <Select className="h-9" value={formType} onChange={(e) => set(setFormType)(e.target.value)} aria-label="Jenis form">
              <option value="">Semua form</option>
              {(forms.data ?? []).map((f) => <option key={f.type} value={f.type}>{f.label}</option>)}
            </Select>
            <Button variant="ghost" size="sm" className="col-span-2 h-9 sm:col-span-4 lg:col-span-1" onClick={reset} disabled={!filtered}><X className="size-4" /> Reset</Button>
          </div>
        )}

        {/* inside "Berjalan": where they stand */}
        {inRunning && (
          <div className="flex gap-2 overflow-x-auto pb-1">
            {[{ key: RUNNING.join(','), label: 'Semua berjalan', statuses: RUNNING }, ...STAGES].map((x) => {
              const on = status === x.key
              return (
                <button key={x.key} type="button" onClick={() => pick(x.key)}
                  className={cn('flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                    on ? 'border-primary bg-primary/10 text-primary' : 'bg-card text-muted-foreground hover:bg-muted hover:text-foreground')}>
                  {x.label}<span className="tabular-nums opacity-70">{countOf(x.statuses)}</span>
                </button>
              )
            })}
          </div>
        )}
      </div>

      {/* the cards */}
      <div className={cn('space-y-6', isFetching && !isLoading && 'opacity-70 transition-opacity')}>
        {isLoading && rows.length === 0 && <p className="py-12 text-center text-sm text-muted-foreground">Memuat…</p>}
        {!isLoading && rows.length === 0 && (
          <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center">
            <div className="flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary"><ClipboardList className="size-7" /></div>
            <div>
              <div className="font-medium">{filtered ? 'Tidak ada request untuk pencarian ini' : 'Belum ada request di sini'}</div>
              <p className="mt-1 text-sm text-muted-foreground">{filtered ? 'Coba kata lain atau hapus filter.' : 'Request baru akan muncul di sini.'}</p>
            </div>
            {filtered ? <Button variant="outline" size="sm" onClick={reset}><X className="size-4" /> Hapus filter</Button>
              : hasPermission('item_request.create') && <Button size="sm" onClick={() => setCreating(true)}><Plus className="size-4" /> Buat Request</Button>}
          </div>
        )}
        {groups.map(([label, list]) => (
          <section key={label} className="space-y-2">
            <h2 className="px-1 text-sm font-medium text-muted-foreground">{label}</h2>
            <ul className="divide-y overflow-hidden rounded-2xl border bg-card shadow-sm">
              {list.map((r) => {
                const Icon = STATUS_ICON[r.status] ?? ClipboardList
                const [, dot] = STATUS_TONE[r.status] ?? STATUS_TONE.DIBATALKAN
                const late = isLate(r)
                const p = r.progress ?? []
                const asked = p.reduce((n, x) => n + x.qty, 0), given = p.reduce((n, x) => n + x.done, 0)
                return (
                  <li key={r.id}>
                    <div role="link" tabIndex={0} onClick={() => navigate(`/item-requests/${r.id}`)} onKeyDown={(e) => e.key === 'Enter' && navigate(`/item-requests/${r.id}`)}
                      className="group flex cursor-pointer items-start gap-3 px-4 py-3.5 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none sm:items-center sm:gap-4 sm:px-5">
                      <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-full text-white', dot)}><Icon className="size-5" /></span>
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-x-2">
                          <span className="truncate font-medium group-hover:text-primary">
                            {r.items_preview?.slice(0, 2).join(', ') || '-'}{r.lines_count > 2 && <span className="text-muted-foreground"> +{r.lines_count - 2} lainnya</span>}
                          </span>
                          {r.urgency === 'URGENT' && <span className="inline-flex items-center gap-1 rounded bg-red-50 px-1.5 text-[11px] font-semibold text-red-700"><AlertTriangle className="size-3" />Urgent</span>}
                        </div>
                        <div className="mt-0.5 truncate text-xs text-muted-foreground">
                          {[r.requester_name, r.divisi, r.form_label].filter(Boolean).join(' · ')} · <span className="font-mono">{r.number}</span>
                        </div>
                        {/* phone: the status under the text */}
                        <div className="mt-1.5 flex flex-wrap items-center gap-2 sm:hidden">
                          <RequestStatusBadge status={r.status} label={r.status_label} />
                          {r.needed_date && <span className={cn('text-xs', late ? 'font-semibold text-red-600' : 'text-muted-foreground')}>{late ? 'Terlambat · ' : 'Perlu '}{fmtDate(r.needed_date)}</span>}
                        </div>
                      </div>
                      {/* wider: due date, status, progress and the one action on the right */}
                      <div className="hidden shrink-0 flex-col items-end gap-1 text-right sm:flex">
                        <span className={cn('text-xs', late ? 'font-semibold text-red-600' : 'text-muted-foreground')}>
                          {r.needed_date ? <>{late ? 'Terlambat · ' : 'Dibutuhkan '}{fmtDate(r.needed_date)}</> : 'Tanpa tanggal'}
                        </span>
                        <RequestStatusBadge status={r.status} label={r.status_label} />
                        {asked > 0 && given > 0 && <span className="text-[11px] tabular-nums text-muted-foreground">diserahkan {given.toLocaleString('id-ID')} / {asked.toLocaleString('id-ID')}</span>}
                      </div>
                      {toVerify(r) && (
                        <Button size="sm" className="hidden h-8 shrink-0 rounded-full md:inline-flex" onClick={(e) => { e.stopPropagation(); navigate(`/item-requests/${r.id}`) }}>Verifikasi</Button>
                      )}
                      <ChevronRight className="mt-2.5 size-4 shrink-0 text-muted-foreground sm:mt-0" />
                    </div>
                  </li>
                )
              })}
            </ul>
          </section>
        ))}
      </div>

      {data && data.meta.total > 0 && (
        <div className="overflow-hidden rounded-2xl border bg-card">
          <Pager page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} perPage={perPage}
            onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1) }} />
        </div>
      )}

      <NewRequestModal open={creating} onClose={() => setCreating(false)} />
      {canProcess && <RequestLinksModal open={sharing} onClose={() => setSharing(false)} />}
    </div>
  )
}
