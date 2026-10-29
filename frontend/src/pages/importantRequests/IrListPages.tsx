import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AlertTriangle, CheckCircle2, ClipboardList, Clock, Inbox, MessageSquare, Plus, Search } from 'lucide-react'
import { IR_SCOPES, IR_STATUS, useIrList, useIrSummary, type IrRow, type IrScope } from '@/features/importantRequests/api'
import { IrDeadline, IrPriorityBadge, IrStatusBadge } from '@/components/importantRequests/IrParts'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { useRemembered } from '@/lib/listMemory'
import { cn } from '@/lib/utils'

const CLOSED = ['SELESAI', 'DIBATALKAN', 'DRAFT']

/** A compact row of a request (dashboard lists). */
function RowLink({ r }: { r: IrRow }) {
  return (
    <Link to={`/important-requests/${r.id}`} className="flex items-start gap-3 px-4 py-3 hover:bg-muted/50">
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{r.title}</span>
          {r.unread_comments > 0 && <span className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground"><MessageSquare className="size-3" />{r.unread_comments}</span>}
        </div>
        <div className="mt-0.5 text-xs text-muted-foreground"><span className="font-mono">{r.number}</span> · {r.creator} → {r.assignee_names.join(', ') || '-'}</div>
        <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs"><IrPriorityBadge priority={r.priority} /><IrStatusBadge status={r.status} label={r.status_label} /><IrDeadline deadline={r.deadline} overdue={r.overdue} closed={CLOSED.includes(r.status)} /></div>
      </div>
    </Link>
  )
}

function Stat({ label, value, icon: Icon, tone, to }: { label: string; value: number; icon: typeof Inbox; tone: string; to?: string }) {
  const body = (
    <Card className={cn('flex items-center gap-3 p-4', to && 'transition-colors hover:bg-muted/40')}>
      <span className={cn('flex size-10 items-center justify-center rounded-lg', tone)}><Icon className="size-5" /></span>
      <div><div className="text-2xl font-semibold tabular-nums">{value.toLocaleString('id-ID')}</div><div className="text-xs text-muted-foreground">{label}</div></div>
    </Card>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

/** Important Request — simplified dashboard: action items first, then essentials. */
export function IrDashboardPage() {
  const { data: s, isLoading, isError } = useIrSummary()
  const navigate = useNavigate()
  if (isError) return <p className="text-sm text-destructive">Gagal memuat ringkasan Important Request.</p>
  if (isLoading || !s) return <p className="py-16 text-center text-sm text-muted-foreground">Memuat…</p>

  const hasActions = s.action.to_do > 0 || s.action.to_revise > 0 || s.action.to_review > 0
  const activeCount = s.stats.assigned + s.stats.waiting_review

  return (
    <div className="space-y-6">
      <PageHeader title="Important Request" icon={<ClipboardList className="size-5" />} subtitle="Instruksi, tugas, dan tindak lanjut pekerjaan."
        actions={s.can_create && <Button size="sm" onClick={() => navigate('/important-requests/baru')}><Plus className="size-4" /> Buat request</Button>} />

      {/* Action items - what needs my attention */}
      {hasActions && (
        <Card className="border-primary/20 bg-primary/5 p-4">
          <h3 className="mb-3 text-sm font-semibold text-foreground">Perlu tindakan Anda</h3>
          <div className="space-y-2">
            {s.action.to_do > 0 && (
              <Link to={IR_SCOPES.tasks.path} className="flex items-center justify-between rounded-lg bg-white px-4 py-3 text-sm ring-1 ring-inset ring-amber-200 hover:bg-amber-50">
                <span className="font-medium text-amber-900">{s.action.to_do} tugas belum diserahkan</span>
                <span className="text-muted-foreground">→</span>
              </Link>
            )}
            {s.action.to_revise > 0 && (
              <Link to={IR_SCOPES.tasks.path} className="flex items-center justify-between rounded-lg bg-white px-4 py-3 text-sm ring-1 ring-inset ring-orange-200 hover:bg-orange-50">
                <span className="font-medium text-orange-900">{s.action.to_revise} tugas dikembalikan untuk revisi</span>
                <span className="text-muted-foreground">→</span>
              </Link>
            )}
            {s.action.to_review > 0 && (
              <Link to={IR_SCOPES.review.path} className="flex items-center justify-between rounded-lg bg-white px-4 py-3 text-sm ring-1 ring-inset ring-blue-200 hover:bg-blue-50">
                <span className="font-medium text-blue-900">{s.action.to_review} hasil diserahkan, menunggu Anda tinjau</span>
                <span className="text-muted-foreground">→</span>
              </Link>
            )}
          </div>
        </Card>
      )}

      {/* Simplified stats - only the essentials */}
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <Stat label="Aktif" value={activeCount} icon={Clock} tone="bg-blue-50 text-blue-700" to={IR_SCOPES.tasks.path} />
        <Stat label="Terlambat" value={s.stats.overdue} icon={AlertTriangle} tone="bg-red-50 text-red-700" to={IR_SCOPES.tasks.path} />
        <Stat label="Selesai" value={s.stats.done} icon={CheckCircle2} tone="bg-emerald-50 text-emerald-700" to={IR_SCOPES.done.path} />
        <Stat label="Total" value={s.stats.total} icon={Inbox} tone="bg-slate-100 text-slate-700" />
      </div>

      {/* Unified list with smart sections */}
      <Card className="overflow-hidden">
        <div className="border-b px-5 py-4">
          <h3 className="text-sm font-semibold">Request terbaru</h3>
        </div>
        {s.latest.length === 0 ? (
          <p className="px-5 py-12 text-center text-sm text-muted-foreground">Belum ada request.</p>
        ) : (
          <div className="divide-y">
            {s.latest.map((r) => <RowLink key={r.id} r={r} />)}
          </div>
        )}
        {s.latest.length > 0 && (
          <div className="border-t px-5 py-3">
            <Link to={IR_SCOPES.tasks.path} className="text-sm font-medium text-primary hover:underline">Lihat semua request →</Link>
          </div>
        )}
      </Card>
    </div>
  )
}

/** Permintaan Saya / Tugas Saya / Perlu Persetujuan / Selesai — card-based list with simplified filters. */
export function IrListPage({ scope }: { scope: Exclude<IrScope, 'all'> }) {
  const navigate = useNavigate()
  const meta = IR_SCOPES[scope]
  const [typed, setTyped] = useState('')
  const [search, setSearch] = useRemembered(`ir-${scope}`, 'search', '')
  const [showFilters, setShowFilters] = useState(false)
  const [status, setStatus] = useRemembered(`ir-${scope}`, 'status', '')
  const [priority, setPriority] = useRemembered(`ir-${scope}`, 'priority', '')
  const [overdue, setOverdue] = useRemembered(`ir-${scope}`, 'overdue', false)
  const [sort, setSort] = useRemembered(`ir-${scope}`, 'sort', 'recent')
  const [page, setPage] = useRemembered(`ir-${scope}`, 'page', 1)
  useEffect(() => { setTyped(search) }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setTimeout(() => { if (typed !== search) { setSearch(typed); setPage(1) } }, 300); return () => clearTimeout(t) }, [typed])
  const { data, isLoading, isError } = useIrList({ scope, status: status || undefined, priority: priority || undefined, overdue, search: search || undefined, sort, page })
  const summary = useIrSummary()

  const reset = () => { setTyped(''); setSearch(''); setStatus(''); setPriority(''); setOverdue(false); setPage(1) }
  const filtered = !!(search || status || priority || overdue)

  return (
    <div className="space-y-4">
      <PageHeader title={`Important Request · ${meta.label}`} icon={<ClipboardList className="size-5" />}
        subtitle={scope === 'mine' ? 'Request yang Anda buat.' : scope === 'tasks' ? 'Request yang ditugaskan kepada Anda.' : scope === 'review' ? 'Hasil pekerjaan yang menunggu persetujuan Anda.' : 'Request yang sudah selesai.'}
        actions={summary.data?.can_create && scope !== 'done' && <Button size="sm" onClick={() => navigate('/important-requests/baru')}><Plus className="size-4" /> Buat request</Button>} />

      {/* Simplified filter bar - only search by default, more on demand */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-[200px] sm:max-w-md">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="pl-9" placeholder="Cari nomor / judul" value={typed} onChange={(e) => setTyped(e.target.value)} />
        </div>
        <Button variant="outline" size="sm" onClick={() => setShowFilters(!showFilters)}>
          {showFilters ? 'Sembunyikan filter' : 'Filter'} {filtered && <span className="ml-1 rounded-full bg-primary px-1.5 text-[10px] text-primary-foreground">●</span>}
        </Button>
        {filtered && <Button variant="ghost" size="sm" onClick={reset}>Reset</Button>}
        {data && <span className="ml-auto text-xs text-muted-foreground">{data.meta.total.toLocaleString('id-ID')} request</span>}
      </div>

      {/* Progressive filters - shown only when needed */}
      {showFilters && (
        <Card className="p-4">
          <div className="flex flex-wrap gap-4">
            {scope !== 'done' && (
              <div className="w-full sm:w-48">
                <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
                <Select aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
                  <option value="">Semua status</option>
                  {Object.entries(IR_STATUS).filter(([k]) => ['DITUGASKAN', 'MENUNGGU_REVIEW', 'DIBATALKAN', ...(scope === 'mine' ? ['DRAFT'] : [])].includes(k)).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                </Select>
              </div>
            )}
            <div className="w-full sm:w-36">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">Prioritas</label>
              <Select aria-label="Prioritas" value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1) }}>
                <option value="">Semua prioritas</option>
                {['URGENT', 'HIGH', 'MEDIUM', 'LOW'].map((p) => <option key={p} value={p}>{p.charAt(0) + p.slice(1).toLowerCase()}</option>)}
              </Select>
            </div>
            <div className="w-full sm:w-40">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">Urutkan</label>
              <Select aria-label="Urutkan" value={sort} onChange={(e) => setSort(e.target.value)}>
                <option value="recent">Terbaru diubah</option>
                <option value="deadline">Deadline terdekat</option>
                <option value="priority">Prioritas</option>
              </Select>
            </div>
            {scope !== 'done' && (
              <label className="flex h-9 items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={overdue} onChange={(e) => { setOverdue(e.target.checked); setPage(1) }} /> Terlambat saja</label>
            )}
          </div>
        </Card>
      )}

      {isError && <p className="text-sm text-destructive">Gagal memuat daftar.</p>}

      {/* Card-based list instead of table */}
      {isLoading ? (
        <p className="py-12 text-center text-sm text-muted-foreground">Memuat…</p>
      ) : data?.data.length === 0 ? (
        <Card className="py-12 text-center">
          <p className="text-sm text-muted-foreground">{filtered ? 'Tidak ada request untuk filter ini.' : meta.empty}</p>
        </Card>
      ) : (
        <div className="grid gap-3">
          {data?.data.map((r) => (
            <Card key={r.id} className="cursor-pointer transition-colors hover:bg-muted/40" onClick={() => navigate(`/important-requests/${r.id}`)}>
              <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 flex-1">
                  <div className="flex items-start gap-2">
                    <span className="font-medium leading-tight">{r.title}</span>
                    {r.unread_comments > 0 && <span className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground"><MessageSquare className="size-3" />{r.unread_comments}</span>}
                  </div>
                  <div className="mt-1 text-xs text-muted-foreground">
                    <span className="font-mono">{r.number}</span>
                    {r.creator && <span className="mx-1">·</span>}
                    {r.creator && <span>Dari {r.creator}</span>}
                    {r.assignee_names.length > 0 && <span className="mx-1">·</span>}
                    {r.assignee_names.length > 0 && <span className="truncate">ke {r.assignee_names.join(', ')}</span>}
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs sm:flex-col sm:items-end sm:gap-1">
                  <IrPriorityBadge priority={r.priority} />
                  <IrStatusBadge status={r.status} label={r.status_label} />
                  {scope === 'tasks' && r.my_status && r.my_status !== r.status && <span className="text-muted-foreground">Bagian saya: {r.my_status_label}</span>}
                  <IrDeadline deadline={r.deadline} overdue={r.overdue} closed={CLOSED.includes(r.status)} />
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Sebelumnya</Button>
          <span className="tabular-nums text-muted-foreground">{data.meta.page} / {data.meta.last_page}</span>
          <Button size="sm" variant="outline" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Berikutnya</Button>
        </div>
      )}
    </div>
  )
}
