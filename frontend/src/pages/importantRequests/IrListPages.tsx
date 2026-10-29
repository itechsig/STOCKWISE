import { useEffect, useState } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import { AlertTriangle, CheckCircle2, ChevronRight, ClipboardList, MessageSquare, Plus, Search, SlidersHorizontal, Undo2, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { IR_SCOPES, IR_STATUS, useIrList, useIrSummary, type IrRow, type IrScope } from '@/features/importantRequests/api'
import { IrPriorityBadge, fmtDateTime } from '@/components/importantRequests/IrParts'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { useRemembered } from '@/lib/listMemory'
import { cn } from '@/lib/utils'

const CLOSED = ['SELESAI', 'DIBATALKAN', 'DRAFT']
const fmtDue = (v: string) => new Date(v).toLocaleString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

/** The round icon of a request, colored by where it stands (like Classroom's assignment icon). */
function IrIcon({ r, className }: { r: IrRow; className?: string }) {
  const tone = r.status === 'SELESAI' ? 'bg-emerald-500' : r.status === 'DIBATALKAN' || r.status === 'DRAFT' ? 'bg-slate-400'
    : r.overdue ? 'bg-red-500' : r.status === 'MENUNGGU_REVIEW' ? 'bg-amber-500' : 'bg-primary'
  const Icon = r.status === 'SELESAI' ? CheckCircle2 : r.overdue ? AlertTriangle : ClipboardList
  return <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-full text-white', tone, className)}><Icon className="size-5" /></span>
}

/** Due-date groups of Classroom's to-do: Terlambat / Hari ini / Minggu ini / Minggu depan / Nanti / Tanpa tenggat. */
function dueGroup(r: IrRow): string {
  if (CLOSED.includes(r.status)) return 'Selesai & lainnya'
  if (!r.deadline) return 'Tanpa tenggat'
  if (r.overdue) return 'Terlambat'
  const today = new Date(new Date().toDateString()).getTime()
  const days = Math.floor((new Date(new Date(r.deadline).toDateString()).getTime() - today) / 86400000)
  if (days <= 0) return 'Hari ini'
  if (days < 7) return 'Minggu ini'
  if (days < 14) return 'Minggu depan'
  return 'Nanti'
}
const GROUP_ORDER = ['Terlambat', 'Hari ini', 'Minggu ini', 'Minggu depan', 'Nanti', 'Tanpa tenggat', 'Selesai & lainnya']

/** One request as a Classroom to-do row: icon, title, who, the due date and its state on the right. */
function IrItem({ r, scope }: { r: IrRow; scope?: IrScope }) {
  const mine = scope === 'tasks' && r.my_status
  const state = mine ? r.my_status_label : r.status_label
  const stateTone = (mine ? r.my_status : r.status) === 'SELESAI' ? 'text-emerald-700' : r.overdue && !CLOSED.includes(r.status) ? 'text-red-600'
    : (mine ? r.my_status : r.status)?.startsWith('DISERAHKAN') ? 'text-emerald-700' : (mine ? r.my_status : r.status) === 'REVISI' ? 'text-orange-700' : 'text-muted-foreground'
  return (
    <Link to={`/important-requests/${r.id}`} className="group flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-muted/40 sm:gap-4 sm:px-5">
      <IrIcon r={r} />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium group-hover:text-primary">{r.title}</span>
          {r.priority === 'URGENT' || r.priority === 'HIGH' ? <IrPriorityBadge priority={r.priority} /> : null}
          {r.unread_comments > 0 && <span className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground"><MessageSquare className="size-3" />{r.unread_comments}</span>}
        </div>
        <div className="mt-0.5 truncate text-xs text-muted-foreground">
          {scope === 'tasks' ? `Dari ${r.creator ?? '-'}` : `Untuk ${r.assignee_names.join(', ') || '-'}`}
          {r.assignees_total > 1 && scope !== 'tasks' && ` · ${r.assignees_done}/${r.assignees_total} selesai`} · <span className="font-mono">{r.number}</span>
        </div>
      </div>
      <div className="shrink-0 text-right">
        <div className={cn('text-xs', r.overdue && !CLOSED.includes(r.status) ? 'font-medium text-red-600' : 'text-muted-foreground')}>{r.deadline ? fmtDue(r.deadline) : 'Tanpa tenggat'}</div>
        <div className={cn('text-xs font-medium', stateTone)}>{state}</div>
      </div>
      <ChevronRight className="hidden size-4 shrink-0 text-muted-foreground sm:block" />
    </Link>
  )
}

/** The scopes as Classroom-like tabs (each still its own page / menu entry). */
function ScopeTabs() {
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('important_request.create')
  const tabs = (['tasks', 'mine', 'review', 'done'] as const).filter((k) => canCreate || k === 'tasks' || k === 'done')
  return (
    <nav className="flex overflow-x-auto border-b" aria-label="Important Request">
      <NavLink to="/important-requests" end className={({ isActive }) => cn('-mb-px shrink-0 border-b-[3px] px-4 pb-2.5 pt-1 text-sm font-medium', isActive ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>Beranda</NavLink>
      {tabs.map((k) => (
        <NavLink key={k} to={IR_SCOPES[k].path} className={({ isActive }) => cn('-mb-px shrink-0 border-b-[3px] px-4 pb-2.5 pt-1 text-sm font-medium', isActive ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>
          {IR_SCOPES[k].label}
        </NavLink>
      ))}
    </nav>
  )
}

/** The class header: a colored banner with the title and the create button. */
function Banner({ title, subtitle, canCreate }: { title: string; subtitle: string; canCreate?: boolean }) {
  const navigate = useNavigate()
  return (
    <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-700 px-5 py-6 text-white shadow-sm sm:px-8 sm:py-8">
      <ClipboardList aria-hidden className="pointer-events-none absolute -right-6 -top-6 size-40 opacity-10 sm:size-52" />
      <div className="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h1>
          <p className="mt-1 max-w-xl text-sm text-white/85">{subtitle}</p>
        </div>
        {canCreate && <Button size="sm" className="shrink-0 bg-white text-emerald-800 hover:bg-white/90" onClick={() => navigate('/important-requests/baru')}><Plus className="size-4" /> Buat request</Button>}
      </div>
    </section>
  )
}

/** Important Request home, like a Classroom class: the banner, "Mendatang" on the left, the stream on the right. */
export function IrDashboardPage() {
  const { data: s, isLoading, isError } = useIrSummary()
  if (isError) return <p className="text-sm text-destructive">Gagal memuat ringkasan Important Request.</p>
  if (isLoading || !s) return <p className="py-16 text-center text-sm text-muted-foreground">Memuat…</p>
  const actions = [
    { n: s.action.to_do, text: 'tugas belum diserahkan', to: IR_SCOPES.tasks.path, icon: ClipboardList, tone: 'bg-primary' },
    { n: s.action.to_revise, text: 'tugas dikembalikan untuk revisi', to: IR_SCOPES.tasks.path, icon: Undo2, tone: 'bg-orange-500' },
    { n: s.action.to_review, text: 'hasil menunggu Anda tinjau', to: IR_SCOPES.review.path, icon: CheckCircle2, tone: 'bg-amber-500' },
  ].filter((a) => a.n > 0)
  const upcoming = [...s.near_deadline, ...s.urgent].filter((r, i, all) => all.findIndex((x) => x.id === r.id) === i && !CLOSED.includes(r.status)).slice(0, 5)

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <Banner title="Important Request" subtitle="Instruksi dan tugas kerja: ditugaskan, diserahkan, lalu disetujui — seperti tugas di Google Classroom." canCreate={s.can_create} />
      <ScopeTabs />

      <div className="grid gap-5 md:grid-cols-[15rem_minmax(0,1fr)]">
        {/* left: like Classroom's "Mendatang" and counts */}
        <div className="space-y-4">
          <Card className="rounded-2xl p-4">
            <h2 className="text-sm font-medium">Mendatang</h2>
            {upcoming.length === 0 ? <p className="mt-2 text-xs text-muted-foreground">Hore, tidak ada tenggat dalam waktu dekat!</p> : (
              <ul className="mt-2 space-y-2">
                {upcoming.map((r) => (
                  <li key={r.id} className="text-xs">
                    <div className={cn(r.overdue ? 'font-medium text-red-600' : 'text-muted-foreground')}>{r.deadline ? `Tenggat ${fmtDue(r.deadline)}` : 'Tanpa tenggat'}</div>
                    <Link to={`/important-requests/${r.id}`} className="line-clamp-2 font-medium hover:text-primary hover:underline">{r.title}</Link>
                  </li>
                ))}
              </ul>
            )}
            <Link to={IR_SCOPES.tasks.path} className="mt-3 block text-right text-xs font-medium text-primary hover:underline">Lihat semua</Link>
          </Card>
          <Card className="grid grid-cols-2 gap-y-3 rounded-2xl p-4 text-center">
            {([[s.stats.assigned + s.stats.waiting_review, 'Aktif', ''], [s.stats.overdue, 'Terlambat', 'text-red-600'], [s.stats.done, 'Selesai', 'text-emerald-700'], [s.stats.total, 'Total', '']] as const).map(([n, label, tone]) => (
              <div key={label}><div className={cn('text-2xl font-light tabular-nums', tone)}>{n}</div><div className="text-xs text-muted-foreground">{label}</div></div>
            ))}
          </Card>
        </div>

        {/* right: the stream — what needs me first, then the latest requests as posts */}
        <div className="space-y-3">
          {actions.map((a) => (
            <Link key={a.text} to={a.to} className="flex items-center gap-4 rounded-2xl border bg-card px-4 py-3.5 shadow-sm transition-shadow hover:shadow-md sm:px-5">
              <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-full text-white', a.tone)}><a.icon className="size-5" /></span>
              <span className="flex-1 text-sm"><b className="text-base">{a.n}</b> {a.text}</span>
              <ChevronRight className="size-4 text-muted-foreground" />
            </Link>
          ))}
          {s.latest.length === 0 ? (
            <Card className="rounded-2xl px-6 py-14 text-center text-sm text-muted-foreground">Belum ada request.</Card>
          ) : s.latest.map((r) => (
            <Link key={r.id} to={`/important-requests/${r.id}`} className="flex items-center gap-4 rounded-2xl border bg-card px-4 py-3.5 shadow-sm transition-shadow hover:shadow-md sm:px-5">
              <IrIcon r={r} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm"><span className="font-medium">{r.creator ?? '-'}</span> memberi tugas: <span className="font-medium">{r.title}</span></div>
                <div className="truncate text-xs text-muted-foreground">{fmtDateTime(r.created_at)} · untuk {r.assignee_names.join(', ') || '-'}</div>
              </div>
              <span className={cn('hidden shrink-0 text-xs sm:block', r.overdue && !CLOSED.includes(r.status) ? 'font-medium text-red-600' : 'text-muted-foreground')}>{r.status_label}</span>
            </Link>
          ))}
        </div>
      </div>
    </div>
  )
}

/** Permintaan Saya / Tugas Saya / Perlu Persetujuan / Selesai — Classroom's to-do: grouped by due date. */
export function IrListPage({ scope }: { scope: Exclude<IrScope, 'all'> }) {
  const meta = IR_SCOPES[scope]
  const [typed, setTyped] = useState('')
  const [search, setSearch] = useRemembered(`ir-${scope}`, 'search', '')
  const [status, setStatus] = useRemembered(`ir-${scope}`, 'status', '')
  const [priority, setPriority] = useRemembered(`ir-${scope}`, 'priority', '')
  const [overdue, setOverdue] = useRemembered(`ir-${scope}`, 'overdue', false)
  const [sort, setSort] = useRemembered(`ir-${scope}`, 'sort', 'deadline')
  const [page, setPage] = useRemembered(`ir-${scope}`, 'page', 1)
  const [showFilters, setShowFilters] = useState(!!(status || priority || overdue))
  useEffect(() => { setTyped(search) }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setTimeout(() => { if (typed !== search) { setSearch(typed); setPage(1) } }, 300); return () => clearTimeout(t) }, [typed])
  const { data, isLoading, isError } = useIrList({ scope, status: status || undefined, priority: priority || undefined, overdue, search: search || undefined, sort, page })
  const summary = useIrSummary()

  const reset = () => { setTyped(''); setSearch(''); setStatus(''); setPriority(''); setOverdue(false); setPage(1) }
  const extra = [status, priority, overdue].filter(Boolean).length
  const filtered = !!search || extra > 0
  const rows = data?.data ?? []
  // sorted by due date: Classroom's groups; otherwise one plain list
  const groups: [string, IrRow[]][] = sort === 'deadline'
    ? GROUP_ORDER.map((g) => [g, rows.filter((r) => dueGroup(r) === g)] as [string, IrRow[]]).filter(([, l]) => l.length > 0)
    : rows.length ? [['', rows]] : []

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <Banner title={meta.label}
        subtitle={scope === 'mine' ? 'Tugas yang Anda berikan dan sejauh mana penerima mengerjakannya.' : scope === 'tasks' ? 'Tugas untuk Anda: kerjakan, lampirkan hasilnya, lalu serahkan sebelum tenggat.' : scope === 'review' ? 'Hasil yang diserahkan dan menunggu Anda setujui atau kembalikan.' : 'Tugas yang sudah selesai.'}
        canCreate={summary.data?.can_create && scope !== 'done'} />
      <ScopeTabs />

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-0 flex-1 sm:max-w-sm">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="h-9 rounded-full pl-9" placeholder="Cari nomor / judul" value={typed} onChange={(e) => setTyped(e.target.value)} aria-label="Cari" />
        </div>
        <Select className="h-9 w-auto rounded-full" aria-label="Urutkan" value={sort} onChange={(e) => setSort(e.target.value)}>
          <option value="deadline">Menurut tenggat</option>
          <option value="recent">Terbaru diubah</option>
          <option value="priority">Prioritas</option>
        </Select>
        <Button variant="outline" size="sm" className={cn('h-9 rounded-full', extra > 0 && 'border-primary text-primary')} onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>
          <SlidersHorizontal className="size-4" /> Filter{extra > 0 ? ` (${extra})` : ''}
        </Button>
        {filtered && <Button variant="ghost" size="sm" className="h-9" onClick={reset}><X className="size-4" /> Reset</Button>}
        {data && <span className="ml-auto text-xs text-muted-foreground">{data.meta.total.toLocaleString('id-ID')} request</span>}
      </div>

      {showFilters && (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3">
          {scope !== 'done' && (
            <Select className="h-9 w-full sm:w-48" aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
              <option value="">Semua status</option>
              {Object.entries(IR_STATUS).filter(([k]) => ['DITUGASKAN', 'MENUNGGU_REVIEW', 'DIBATALKAN', ...(scope === 'mine' ? ['DRAFT'] : [])].includes(k)).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
            </Select>
          )}
          <Select className="h-9 w-full sm:w-40" aria-label="Prioritas" value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1) }}>
            <option value="">Semua prioritas</option>
            {['URGENT', 'HIGH', 'MEDIUM', 'LOW'].map((p) => <option key={p} value={p}>{p.charAt(0) + p.slice(1).toLowerCase()}</option>)}
          </Select>
          {scope !== 'done' && <label className="flex h-9 items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={overdue} onChange={(e) => { setOverdue(e.target.checked); setPage(1) }} /> Terlambat saja</label>}
        </div>
      )}

      {isError && <p className="text-sm text-destructive">Gagal memuat daftar.</p>}
      {isLoading ? <p className="py-12 text-center text-sm text-muted-foreground">Memuat…</p> : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center">
          <div className="flex size-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-700"><CheckCircle2 className="size-7" /></div>
          <p className="text-sm text-muted-foreground">{filtered ? 'Tidak ada request untuk filter ini.' : meta.empty}</p>
        </div>
      ) : (
        <div className="space-y-5">
          {groups.map(([label, list]) => (
            <section key={label || 'all'} className="space-y-2">
              {label && <h2 className={cn('px-1 text-sm font-medium', label === 'Terlambat' ? 'text-red-600' : 'text-muted-foreground')}>{label} <span className="font-normal">({list.length})</span></h2>}
              <div className="divide-y overflow-hidden rounded-2xl border bg-card shadow-sm">
                {list.map((r) => <IrItem key={r.id} r={r} scope={scope} />)}
              </div>
            </section>
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
