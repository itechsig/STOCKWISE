import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AlertTriangle, CheckCircle2, ClipboardList, Clock, Hourglass, Inbox, MessageSquare, Plus, Search, ShieldAlert } from 'lucide-react'
import { IR_SCOPES, IR_STATUS, useIrList, useIrSummary, type IrRow, type IrScope } from '@/features/importantRequests/api'
import { IrDeadline, IrPriorityBadge, IrStatusBadge } from '@/components/importantRequests/IrParts'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { FilterBar } from '@/components/FilterBar'
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

/** Important Request — the summary: what needs my action first, then the counts and the lists. */
export function IrDashboardPage() {
  const { data: s, isLoading, isError } = useIrSummary()
  const navigate = useNavigate()
  if (isError) return <p className="text-sm text-destructive">Gagal memuat ringkasan Important Request.</p>
  if (isLoading || !s) return <p className="py-16 text-center text-sm text-muted-foreground">Memuat…</p>
  const actions = [
    s.action.to_confirm > 0 && { text: `${s.action.to_confirm} tugas baru menunggu konfirmasi Anda`, to: IR_SCOPES.tasks.path, tone: 'bg-amber-50 text-amber-900 ring-amber-200' },
    s.action.to_revise > 0 && { text: `${s.action.to_revise} tugas diminta revisi`, to: IR_SCOPES.tasks.path, tone: 'bg-orange-50 text-orange-900 ring-orange-200' },
    s.action.to_review > 0 && { text: `${s.action.to_review} hasil pekerjaan menunggu persetujuan Anda`, to: IR_SCOPES.review.path, tone: 'bg-blue-50 text-blue-900 ring-blue-200' },
  ].filter(Boolean) as { text: string; to: string; tone: string }[]

  return (
    <div className="space-y-5">
      <PageHeader title="Important Request" icon={<ClipboardList className="size-5" />} subtitle="Instruksi, tugas, dan tindak lanjut pekerjaan."
        actions={s.can_create && <Button size="sm" onClick={() => navigate('/important-requests/baru')}><Plus className="size-4" /> Buat request</Button>} />

      {actions.length > 0 && (
        <div className="space-y-2">
          {actions.map((a) => <Link key={a.text} to={a.to} className={cn('block rounded-lg px-4 py-3 text-sm font-medium ring-1 ring-inset hover:opacity-90', a.tone)}>{a.text} →</Link>)}
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
        <Stat label="Total request" value={s.stats.total} icon={Inbox} tone="bg-slate-100 text-slate-700" />
        <Stat label="Belum dikonfirmasi" value={s.stats.unconfirmed} icon={Hourglass} tone="bg-amber-50 text-amber-700" />
        <Stat label="Sedang dikerjakan" value={s.stats.in_progress} icon={Clock} tone="bg-blue-50 text-blue-700" />
        <Stat label="Terkendala" value={s.stats.blocked} icon={ShieldAlert} tone="bg-red-50 text-red-700" />
        <Stat label="Terlambat" value={s.stats.overdue} icon={AlertTriangle} tone="bg-red-50 text-red-700" />
        <Stat label="Menunggu persetujuan" value={s.stats.waiting_review} icon={Hourglass} tone="bg-violet-50 text-violet-700" />
        <Stat label="Selesai" value={s.stats.done} icon={CheckCircle2} tone="bg-emerald-50 text-emerald-700" to={IR_SCOPES.done.path} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {([['Mendesak', s.urgent, 'Tidak ada request mendesak.'], ['Mendekati deadline (3 hari)', s.near_deadline, 'Tidak ada yang mendekati deadline.'], ['Terbaru', s.latest, 'Belum ada request.']] as const).map(([title, rows, empty]) => (
          <Card key={title} className="overflow-hidden">
            <div className="border-b px-4 py-3 text-sm font-semibold">{title}</div>
            {rows.length === 0 ? <p className="px-4 py-8 text-center text-sm text-muted-foreground">{empty}</p> : <div className="divide-y">{rows.map((r) => <RowLink key={r.id} r={r} />)}</div>}
          </Card>
        ))}
      </div>
    </div>
  )
}

/** Permintaan Saya / Tugas Saya / Perlu Persetujuan / Selesai — one list, the status is a filter. */
export function IrListPage({ scope }: { scope: Exclude<IrScope, 'all'> }) {
  const navigate = useNavigate()
  const meta = IR_SCOPES[scope]
  const [typed, setTyped] = useState('')
  const [search, setSearch] = useRemembered(`ir-${scope}`, 'search', '')
  const [status, setStatus] = useRemembered(`ir-${scope}`, 'status', '')
  const [priority, setPriority] = useRemembered(`ir-${scope}`, 'priority', '')
  const [overdue, setOverdue] = useRemembered(`ir-${scope}`, 'overdue', false)
  const [sort, setSort] = useRemembered(`ir-${scope}`, 'sort', 'recent')
  const [page, setPage] = useRemembered(`ir-${scope}`, 'page', 1)
  useEffect(() => { setTyped(search) }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setTimeout(() => { if (typed !== search) { setSearch(typed); setPage(1) } }, 300); return () => clearTimeout(t) }, [typed])
  const { data, isLoading, isError } = useIrList({ scope, status: status || undefined, priority: priority || undefined, overdue, search: search || undefined, sort, page })
  const summary = useIrSummary()

  const columns: Column<IrRow>[] = [
    {
      key: 'title', header: 'Request',
      cell: (r) => (
        <div className="min-w-[14rem] leading-tight">
          <div className="flex items-center gap-2"><span className="font-medium">{r.title}</span>
            {r.unread_comments > 0 && <span className="inline-flex items-center gap-0.5 rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground" title="Komentar baru"><MessageSquare className="size-3" />{r.unread_comments}</span>}
          </div>
          <div className="mt-0.5 font-mono text-xs text-muted-foreground">{r.number}</div>
        </div>
      ),
    },
    { key: 'priority', header: 'Prioritas', cell: (r) => <IrPriorityBadge priority={r.priority} /> },
    { key: 'status', header: 'Status', cell: (r) => <div className="space-y-1"><IrStatusBadge status={r.status} label={r.status_label} />{scope === 'tasks' && r.my_status && r.my_status !== r.status && <div className="text-[11px] text-muted-foreground">Bagian saya: {r.my_status_label}</div>}</div> },
    { key: 'deadline', header: 'Deadline', cell: (r) => <IrDeadline deadline={r.deadline} overdue={r.overdue} closed={CLOSED.includes(r.status)} /> },
    { key: 'creator', header: 'Dari', cell: (r) => <span className="whitespace-nowrap">{r.creator ?? '-'}</span> },
    {
      key: 'assignees', header: 'Penerima',
      cell: (r) => <div className="max-w-[16rem] leading-tight"><div className="truncate" title={r.assignee_names.join(', ')}>{r.assignee_names.join(', ') || '-'}</div>{r.assignees_total > 1 && <div className="text-[11px] text-muted-foreground">{r.assignees_done}/{r.assignees_total} selesai</div>}</div>,
    },
  ]
  const reset = () => { setTyped(''); setSearch(''); setStatus(''); setPriority(''); setOverdue(false); setPage(1) }
  const filtered = !!(search || status || priority || overdue)

  return (
    <div className="space-y-4">
      <PageHeader title={`Important Request · ${meta.label}`} icon={<ClipboardList className="size-5" />}
        subtitle={scope === 'mine' ? 'Request yang Anda buat.' : scope === 'tasks' ? 'Request yang ditugaskan kepada Anda.' : scope === 'review' ? 'Hasil pekerjaan yang menunggu persetujuan Anda.' : 'Request yang sudah selesai.'}
        actions={summary.data?.can_create && scope !== 'done' && <Button size="sm" onClick={() => navigate('/important-requests/baru')}><Plus className="size-4" /> Buat request</Button>} />

      <FilterBar end={data && <span className="text-xs text-muted-foreground">{data.meta.total.toLocaleString('id-ID')} request</span>}>
        <div className="relative w-full sm:max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="pl-9" placeholder="Cari nomor / judul" value={typed} onChange={(e) => setTyped(e.target.value)} />
        </div>
        {scope !== 'done' && (
          <div className="w-full sm:w-48">
            <Select aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
              <option value="">Semua status</option>
              {Object.entries(IR_STATUS).filter(([k]) => k !== 'SELESAI' && (scope === 'mine' || k !== 'DRAFT')).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
            </Select>
          </div>
        )}
        <div className="w-full sm:w-36">
          <Select aria-label="Prioritas" value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1) }}>
            <option value="">Semua prioritas</option>
            {['URGENT', 'HIGH', 'MEDIUM', 'LOW'].map((p) => <option key={p} value={p}>{p.charAt(0) + p.slice(1).toLowerCase()}</option>)}
          </Select>
        </div>
        <div className="w-full sm:w-40">
          <Select aria-label="Urutkan" value={sort} onChange={(e) => setSort(e.target.value)}>
            <option value="recent">Terbaru diubah</option>
            <option value="deadline">Deadline terdekat</option>
            <option value="priority">Prioritas</option>
          </Select>
        </div>
        {scope !== 'done' && (
          <label className="flex h-9 items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={overdue} onChange={(e) => { setOverdue(e.target.checked); setPage(1) }} /> Terlambat saja</label>
        )}
        {filtered && <Button variant="ghost" size="sm" onClick={reset}>Reset</Button>}
      </FilterBar>

      {isError && <p className="text-sm text-destructive">Gagal memuat daftar.</p>}
      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading} onRowClick={(r) => navigate(`/important-requests/${r.id}`)}
        emptyText={filtered ? 'Tidak ada request untuk filter ini.' : meta.empty} />
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
