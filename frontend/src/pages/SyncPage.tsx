import { useMemo, useState } from 'react'
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Clock, DatabaseZap, Loader2, RefreshCw, ScrollText, X } from 'lucide-react'
import { SYNC_SCHEDULE } from '@/components/AccurateDataNote'
import {
  useSyncBatchDetail, useSyncHistory, type SyncBatchRow, type SyncFieldChange, type SyncLogFilters, type SyncLogRow,
  useAgentLog, type AgentEvent, type AgentRun,
} from '@/features/sync/api'
import { useAgentRunStatus, useStartAgentRun, type AgentRunStatus } from '@/features/accurate/api'
import { useAuth } from '@/auth/AuthContext'
import { SyncStatusCard } from '@/components/SyncStatusCard'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { confirmDialog } from '@/components/ui/confirm'

function statusBadge(status: SyncBatchRow['status']) {
  const map: Record<SyncBatchRow['status'], 'success' | 'warning' | 'danger' | 'default' | 'neutral'> = {
    SUCCESS: 'success',
    PARTIAL: 'warning',
    FAILED: 'danger',
    RUNNING: 'default',
    PENDING: 'neutral',
  }
  const label: Record<SyncBatchRow['status'], string> = {
    SUCCESS: 'Berhasil', PARTIAL: 'Sebagian', FAILED: 'Gagal', RUNNING: 'Berjalan', PENDING: 'Menunggu',
  }
  return <Badge variant={map[status]}>{label[status] ?? status}</Badge>
}

/** SyncLog.action in plain words: INSERT = data baru, UPDATE = diubah, DELETE = sudah tidak ada di Accurate, ERROR = gagal. */
const actionBadge: Record<string, { label: string; variant: 'success' | 'default' | 'neutral' | 'danger' }> = {
  INSERT: { label: 'Baru', variant: 'success' },
  UPDATE: { label: 'Diubah', variant: 'default' },
  DELETE: { label: 'Dihapus', variant: 'danger' },
  SKIP: { label: 'Tidak berubah', variant: 'neutral' },
  ERROR: { label: 'Gagal', variant: 'danger' },
}

/** "Kolom: sebelum → sesudah" — the first few inline, all of them when the row is opened. */
function ChangeList({ changes, limit }: { changes: SyncFieldChange[]; limit?: number }) {
  const shown = limit ? changes.slice(0, limit) : changes
  return (
    <ul className="space-y-0.5">
      {shown.map((c) => (
        <li key={c.field} className="leading-snug">
          <span className="text-muted-foreground">{c.label}: </span>
          <span className="text-muted-foreground line-through decoration-muted-foreground/50">{c.from ?? '(kosong)'}</span>
          <span className="px-1 text-muted-foreground">→</span>
          <span className="font-medium">{c.to ?? '(kosong)'}</span>
        </li>
      ))}
 {limit && changes.length > limit && <li className="text-primary">+{changes.length - limit} perubahan lain klik untuk lihat semua</li>}
    </ul>
  )
}

function LogRow({ log }: { log: SyncLogRow }) {
  const [open, setOpen] = useState(false)
  const many = log.changes.length > 3

  return (
    <tr className={`border-t align-top ${many ? 'cursor-pointer hover:bg-muted/40' : ''}`} onClick={() => many && setOpen((v) => !v)}>
      <td className="px-2 py-1.5 whitespace-nowrap">
        <span className="inline-flex items-center gap-1">
          {many && (open ? <ChevronDown className="size-3" /> : <ChevronRight className="size-3" />)}
          {log.entity_label}
        </span>
      </td>
      <td className="px-2 py-1.5 font-mono whitespace-nowrap">
        {log.document_old ? (
          <div>
            <div className="text-muted-foreground line-through">{log.document_old}</div>
            <div className="font-semibold">{log.document}</div>
          </div>
 ) : (log.document ?? (log.entity === 'renumber' ? '' : ''))}
      </td>
      <td className="px-2 py-1.5">
        {log.item_code ? (
          <>
            <span className="font-mono">{log.item_code}</span>
            {log.item_name && <div className="text-muted-foreground">{log.item_name}</div>}
          </>
 ) : ''}
      </td>
      <td className="px-2 py-1.5">
        <Badge variant={actionBadge[log.action]?.variant ?? 'neutral'}>{actionBadge[log.action]?.label ?? log.action}</Badge>
      </td>
      <td className="px-2 py-1.5">
        {log.changes.length > 0 ? (
          <ChangeList changes={log.changes} limit={open ? undefined : 3} />
        ) : (
          <span className="text-muted-foreground">{log.message}</span>
        )}
        {log.changes.length > 0 && log.document_old && <div className="mt-0.5 text-muted-foreground">{log.message}</div>}
      </td>
    </tr>
  )
}

const ACTION_FILTERS: Array<[string, string]> = [['', 'Semua status'], ['UPDATE', 'Diubah'], ['INSERT', 'Baru'], ['DELETE', 'Dihapus'], ['ERROR', 'Gagal']]

function DetailPanel({ id, onClose }: { id: number; onClose: () => void }) {
  const [filters, setFilters] = useState<SyncLogFilters>({})
  const [search, setSearch] = useState('')
  const { data, isLoading, isFetching } = useSyncBatchDetail(id, filters)
  const entities = useMemo(() => data?.log_summary ?? [], [data?.log_summary])

  return (
    <Card className="border-primary/30 bg-muted/30">
      <CardContent className="space-y-3 p-4">
        <div className="flex items-center justify-between">
          <h3 className="font-medium">Detail Sync {data?.sync_code ?? ''}</h3>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground">
            <X className="size-4" />
          </button>
        </div>

        {isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}

        {data && (
          <>
            <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
              <div><dt className="text-muted-foreground">Status</dt><dd>{statusBadge(data.status)}</dd></div>
              <div><dt className="text-muted-foreground">Mulai</dt><dd>{new Date(data.started_at).toLocaleString('id-ID')}</dd></div>
 <div><dt className="text-muted-foreground">Selesai</dt><dd>{data.finished_at ? new Date(data.finished_at).toLocaleString('id-ID') : ''}</dd></div>
 <div><dt className="text-muted-foreground">Durasi</dt><dd>{data.duration_seconds != null ? `${data.duration_seconds.toFixed(1)}s` : ''}</dd></div>
            </dl>
            {data.error_message && (
              <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">{data.error_message}</p>
            )}

 {/* real changes per data type click one to filter */}
            <div className="flex flex-wrap gap-2 text-xs">
              {entities.length === 0 && !filters.entity && <span className="text-muted-foreground">Tidak ada perubahan data pada sync ini.</span>}
              {(filters.entity ? [{ entity: filters.entity, label: 'Semua jenis data', actions: {} }] : entities).map((e) => {
                const active = filters.entity === e.entity
                const parts = Object.entries(e.actions).map(([a, n]) => `${n} ${actionBadge[a]?.label.toLowerCase() ?? a}`)
                return (
                  <button
                    key={e.entity}
                    onClick={() => setFilters((f) => ({ ...f, entity: active ? undefined : e.entity }))}
                    className={`rounded-md border px-2 py-1 transition ${active ? 'border-primary bg-primary/10' : 'bg-card hover:border-primary/40'}`}
                  >
                    {active ? <>× Hapus filter jenis data</> : <><span className="font-medium">{e.label}</span> · {parts.join(', ')}</>}
                  </button>
                )
              })}
            </div>

            <form
              className="flex flex-wrap items-center gap-2"
              onSubmit={(ev) => { ev.preventDefault(); setFilters((f) => ({ ...f, search: search.trim() || undefined })) }}
            >
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nomor dokumen / kode barang…" className="h-9 w-64 text-xs" />
              <Select value={filters.action ?? ''} onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value || undefined }))} className="h-9 w-40 text-xs">
                {ACTION_FILTERS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </Select>
              <button type="submit" className="h-9 rounded-md border bg-card px-3 text-xs hover:border-primary/40">Cari</button>
              {isFetching && <span className="text-xs text-muted-foreground">Memuat…</span>}
            </form>

            <p className="text-xs text-muted-foreground">
              {data.log_shown < data.log_total
 ? `Menampilkan ${data.log_shown.toLocaleString('id-ID')} dari ${data.log_total.toLocaleString('id-ID')} perubahan gunakan filter / pencarian untuk mempersempit.`
                : `${data.log_total.toLocaleString('id-ID')} perubahan.`}
              {data.log_no_change > 0 && ` ${data.log_no_change.toLocaleString('id-ID')} baris tercatat "diubah" tanpa perubahan nyata (log lama) disembunyikan.`}
            </p>

            <div className="max-h-[28rem] overflow-y-auto rounded-md border bg-card">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-muted text-left">
                  <tr>
                    <th className="px-2 py-1.5">Data</th>
                    <th className="px-2 py-1.5">Dokumen</th>
                    <th className="px-2 py-1.5">Barang</th>
                    <th className="px-2 py-1.5">Status</th>
                    <th className="px-2 py-1.5">Yang berubah (sebelum → sesudah)</th>
                  </tr>
                </thead>
                <tbody>
                  {data.logs.length === 0 && (
                    <tr><td colSpan={5} className="px-2 py-4 text-center text-muted-foreground">Tidak ada log.</td></tr>
                  )}
                  {data.logs.map((l) => (
                    <LogRow key={l.id} log={l} />
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}

/** Tab 1 — riwayat sync per batch (sisi STOCKWISE: apa yang masuk ke database). */
function SyncHistoryTab() {
  const [page, setPage] = useState(1)
  const [detailId, setDetailId] = useState<number | null>(null)
  const { data, isLoading } = useSyncHistory(page)
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage)

  const columns: Column<SyncBatchRow>[] = [
    { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.sync_code}</span> },
    { key: 'date', header: 'Tanggal', cell: (r) => new Date(r.started_at).toLocaleString('id-ID') },
    { key: 'status', header: 'Status', cell: (r) => statusBadge(r.status) },
    { key: 'read', header: 'Dibaca', cell: (r) => <span className="tabular-nums">{r.total_records}</span> },
    { key: 'insert', header: 'Baru', cell: (r) => <span className="tabular-nums">{r.inserted_records}</span> },
    { key: 'update', header: 'Diubah', cell: (r) => <span className="tabular-nums">{r.updated_records}</span> },
    {
      key: 'delete',
      header: 'Dihapus',
      cell: (r) => <span className={`tabular-nums ${r.deleted_records > 0 ? 'text-destructive' : ''}`}>{r.deleted_records}</span>,
    },
    {
      key: 'error',
      header: 'Error',
      cell: (r) => <span className={`tabular-nums ${r.error_records > 0 ? 'text-destructive' : ''}`}>{r.error_records}</span>,
    },
  ]

  return (
    <div className="space-y-4">
      <SyncStatusCard />

      <div className="flex items-center justify-between pt-2">
        <h2 className="text-base font-semibold">Riwayat Sync</h2>
        <span className="text-xs text-muted-foreground">Klik baris untuk melihat detail</span>
      </div>

      {detailId != null && <DetailPanel id={detailId} onClose={() => setDetailId(null)} />}

      <DataTable
        columns={columns}
        rows={scrollRows}
        footer={scrollFooter}
        rowKey={(r) => r.id}
        isLoading={isLoading}
        onRowClick={(r) => setDetailId(r.id)}
        emptyText="Belum ada riwayat sync."
      />
    </div>
  )
}

/**
 * Status per run Sync Agent (dari sync.log):
 * SUCCESS / PARTIAL / FAILED sama artinya dengan status batch di Riwayat Sync;
 * STAGED = salinan database sudah masuk staging tanpa sync ke STOCKWISE
 * (jadwal malam, di-sync keesokan paginya).
 */
const RUN_STATUS: Record<string, { label: string; variant: 'success' | 'warning' | 'danger' | 'default' | 'neutral' }> = {
  SUCCESS: { label: 'Berhasil', variant: 'success' },
  PARTIAL: { label: 'Sebagian', variant: 'warning' },
  FAILED: { label: 'Gagal', variant: 'danger' },
  STAGED: { label: 'Staging saja', variant: 'default' },
}

const EVENT_LEVEL: Record<AgentEvent['level'], 'danger' | 'warning' | 'neutral'> = {
  CRITICAL: 'danger',
  ERROR: 'danger',
  WARNING: 'warning',
  INFO: 'neutral',
  DEBUG: 'neutral',
}

const selectClass = 'h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm'

function fmt(time: string) {
  return new Date(time).toLocaleString('id-ID')
}

function ago(time: string) {
  const minutes = Math.round((Date.now() - new Date(time).getTime()) / 60000)
  if (minutes < 1) return 'baru saja'
  if (minutes < 60) return `${minutes} menit lalu`
  const hours = Math.round(minutes / 60)
  if (hours < 48) return `${hours} jam lalu`
  return `${Math.round(hours / 24)} hari lalu`
}

function StatCard({ icon, label, value, hint, tone }: { icon: React.ReactNode; label: string; value: string; hint?: string; tone?: 'danger' | 'success' }) {
  return (
    <Card>
      <CardContent className="flex items-start gap-3 p-4">
        <span
          className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${
            tone === 'danger' ? 'bg-destructive/10 text-destructive' : tone === 'success' ? 'bg-green-600/10 text-green-600' : 'bg-primary/10 text-primary'
          }`}
        >
          {icon}
        </span>
        <div className="min-w-0">
          <div className="text-xs text-muted-foreground">{label}</div>
          <div className="truncate font-semibold">{value}</div>
          {hint && <div className="text-xs text-muted-foreground">{hint}</div>}
        </div>
      </CardContent>
    </Card>
  )
}

function AgentSummary({ runs }: { runs: AgentRun[] }) {
  const last = runs[0]
  const lastOk = runs.find((r) => r.status === 'SUCCESS' || r.status === 'PARTIAL' || r.status === 'STAGED')
  const failed7d = runs.filter((r) => r.status === 'FAILED' && Date.now() - new Date(r.time).getTime() < 7 * 86400_000).length

  return (
    <div className="grid gap-3 sm:grid-cols-3">
      <StatCard
        icon={<Clock className="size-5" />}
        label="Run terakhir"
        value={last ? `${RUN_STATUS[last.status]?.label ?? last.status} · ${ago(last.time)}` : 'Belum ada'}
        hint={last ? fmt(last.time) : undefined}
        tone={last?.status === 'FAILED' ? 'danger' : undefined}
      />
      <StatCard
        icon={<CheckCircle2 className="size-5" />}
        label="Terakhir berhasil"
        value={lastOk ? ago(lastOk.time) : 'Belum ada'}
        hint={lastOk ? `${fmt(lastOk.time)} · ${lastOk.source}` : undefined}
        tone="success"
      />
      <StatCard
        icon={<AlertTriangle className="size-5" />}
        label="Gagal 7 hari terakhir"
        value={`${failed7d} run`}
        tone={failed7d > 0 ? 'danger' : undefined}
      />
    </div>
  )
}

const RUN_MODE_LABEL: Record<string, string> = {
  snapshot: 'Salin GDB ke staging',
  full: 'Salin GDB + sync',
  sync_only: 'Sync staging',
  sync_now: 'Sync terjadwal (salin GDB + sync)',
}

/** Is a Sync Agent job running now (manual or scheduled), and how did the last one end. */
function AgentRunBanner({ status }: { status: AgentRunStatus | undefined }) {
  if (!status || (!status.running && !status.finished_at)) return null
  const mode = RUN_MODE_LABEL[status.mode ?? ''] ?? status.mode

  if (status.running) {
    return (
      <div className="flex items-center gap-2 rounded-md border border-primary/30 bg-primary/5 p-3 text-sm">
        <Loader2 className="size-4 animate-spin text-primary" />
 <span><b>Sync Agent sedang berjalan:</b> {mode} · mulai {status.started_at ? fmt(status.started_at) : ''}</span>
      </div>
    )
  }

  const good = status.result === 'ok'
  return (
    <div className={`flex items-center gap-2 rounded-md border p-3 text-sm ${good ? 'border-green-600/30 bg-green-600/5' : 'border-destructive/30 bg-destructive/5'}`}>
      {good ? <CheckCircle2 className="size-4 text-green-600" /> : <AlertTriangle className="size-4 text-destructive" />}
      <span>
 Job terakhir: <b>{mode}</b> {good ? 'berhasil' : status.result === 'interrupted' ? 'terhenti di tengah jalan' : 'gagal (lihat riwayat di bawah)'}
        {status.finished_at ? ` · selesai ${fmt(status.finished_at)}` : ''}
      </span>
    </div>
  )
}

/** Tab 2 — log Sync Agent (sisi agent: salin GDB, restore staging, kirim ke API — termasuk yang gagal sebelum masuk Riwayat Sync). */
function AgentLogTab() {
  const { data, isLoading, isFetching, refetch, dataUpdatedAt } = useAgentLog()
  const [tab, setTab] = useState<'runs' | 'events'>('runs')
  const [status, setStatus] = useState('')
  const [level, setLevel] = useState('')
  const [search, setSearch] = useState('')

  const q = search.trim().toLowerCase()
  const runs = useMemo(
    () =>
      (data?.runs ?? []).filter(
        (r) => (!status || r.status === status) && (!q || r.source.toLowerCase().includes(q) || (r.error ?? '').toLowerCase().includes(q))
      ),
    [data, status, q]
  )
  const events = useMemo(
    () =>
      (data?.events ?? []).filter(
        (e) => (!level || (level === 'ERROR' ? e.level === 'ERROR' || e.level === 'CRITICAL' : e.level === level)) && (!q || e.message.toLowerCase().includes(q))
      ),
    [data, level, q]
  )

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">Riwayat penyalinan data Accurate ke STOCKWISE, termasuk yang gagal sebelum masuk Riwayat Sync.</p>
        <Button size="sm" variant="outline" onClick={() => refetch()} disabled={isFetching}>
          <RefreshCw className={`size-4 ${isFetching ? 'animate-spin' : ''}`} /> Muat ulang
        </Button>
      </div>

      {isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}
      {data && !data.log_available && (
        <Card><CardContent className="p-4 text-sm text-muted-foreground">Folder log agent tidak ditemukan di server.</CardContent></Card>
      )}

      {data?.log_available && (
        <>
          <AgentSummary runs={data.runs} />

          <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-md border p-0.5">
              <button
                className={`rounded px-3 py-1.5 text-sm ${tab === 'runs' ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                onClick={() => setTab('runs')}
              >
                Riwayat Run ({data.runs.length})
              </button>
              <button
                className={`rounded px-3 py-1.5 text-sm ${tab === 'events' ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                onClick={() => setTab('events')}
              >
                Event Detail ({data.events.length})
              </button>
            </div>
            <Input placeholder="Cari…" className="max-w-xs" value={search} onChange={(e) => setSearch(e.target.value)} />
            {tab === 'runs' ? (
              <select className={selectClass} value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">Semua status</option>
                {Object.entries(RUN_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </select>
            ) : (
              <select className={selectClass} value={level} onChange={(e) => setLevel(e.target.value)}>
                <option value="">Semua level</option>
                <option value="ERROR">Error</option>
                <option value="WARNING">Warning</option>
                <option value="INFO">Info</option>
              </select>
            )}
            {dataUpdatedAt > 0 && (
              <span className="ml-auto text-xs text-muted-foreground">Diperbarui {new Date(dataUpdatedAt).toLocaleTimeString('id-ID')} · otomatis tiap 1 menit</span>
            )}
          </div>

          <div className="max-h-[70vh] overflow-auto rounded-md border">
            {tab === 'runs' ? (
              <table className="w-full text-sm">
                <thead className="sticky top-0 z-10 bg-muted/60 text-left">
                  <tr>
                    <th className="px-3 py-2">Waktu</th>
                    <th className="px-3 py-2">Sumber</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Keterangan</th>
                  </tr>
                </thead>
                <tbody>
                  {runs.length === 0 && <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">Tidak ada run yang cocok.</td></tr>}
                  {runs.map((r) => (
                    <tr key={`${r.time}-${r.source}`} className="border-t align-top">
                      <td className="whitespace-nowrap px-3 py-2">
                        {fmt(r.time)}
                        <div className="text-xs text-muted-foreground">{ago(r.time)}</div>
                      </td>
                      <td className="px-3 py-2 font-mono text-xs">{r.source}</td>
                      <td className="px-3 py-2">
                        <Badge variant={RUN_STATUS[r.status]?.variant ?? 'neutral'}>{RUN_STATUS[r.status]?.label ?? r.status}</Badge>
                      </td>
 <td className="max-w-xl whitespace-pre-wrap break-words px-3 py-2 text-xs text-muted-foreground">{r.error ?? ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <table className="w-full text-sm">
                <thead className="sticky top-0 z-10 bg-muted/60 text-left">
                  <tr>
                    <th className="px-3 py-2">Waktu</th>
                    <th className="px-3 py-2">Level</th>
                    <th className="px-3 py-2">Pesan</th>
                  </tr>
                </thead>
                <tbody>
                  {events.length === 0 && <tr><td colSpan={3} className="px-3 py-6 text-center text-muted-foreground">Tidak ada event yang cocok.</td></tr>}
                  {events.map((e, i) => (
                    <tr key={`${e.time}-${i}`} className="border-t align-top">
                      <td className="whitespace-nowrap px-3 py-2">{fmt(e.time)}</td>
                      <td className="px-3 py-2"><Badge variant={EVENT_LEVEL[e.level] ?? 'neutral'}>{e.level}</Badge></td>
                      <td className="break-words px-3 py-2 text-xs">{e.message}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
          <p className="text-xs text-muted-foreground">Menampilkan 50 run dan 100 event terakhir. Detail lengkap ada di file log agent di server (D:\01.STOCKWISE\Logs).</p>
        </>
      )}
    </div>
  )
}

const TOP_TABS = [
  { key: 'history' as const, label: 'Riwayat Sync', icon: RefreshCw },
  { key: 'agent' as const, label: 'Log Agen', icon: ScrollText },
]

/**
 * Sinkron & Log Accurate — satu halaman: riwayat sync per batch (apa yang masuk
 * ke STOCKWISE) + log Sync Agent (apa yang terjadi di server sebelum itu,
 * termasuk yang gagal). Digabung agar tidak perlu pindah halaman untuk
 * menelusuri satu masalah sync dari awal sampai akhir.
 *
 * Satu tombol saja ("Sinkron Sekarang") untuk memulai sync — klik langsung
 * menyalin database Accurate (.GDB) ke staging STOCKWISE lalu sync ke
 * STOCKWISE, selalu dua langkah itu sekaligus. Tombol & status "sedang
 * berjalan" ada di level halaman ini (bukan di dalam tab) supaya terlihat
 * dari tab mana pun.
 */
export function SyncPage() {
  const [tab, setTab] = useState<(typeof TOP_TABS)[number]['key']>('history')
  const { hasPermission } = useAuth()
  const canRun = hasPermission('sync.accurate.trigger')
  const { data: runStatus } = useAgentRunStatus()
  const startRun = useStartAgentRun()
  const busy = startRun.isPending || !!runStatus?.running

  async function syncNow() {
    if (!(await confirmDialog('Salin database Accurate ke staging STOCKWISE, lalu sync ke STOCKWISE?\n\nFile Accurate hanya dibaca; staging lama tetap disimpan sebagai cadangan. Salinan otomatis ditolak bila Accurate sedang menulis ke file saat disalin.'))) return
    startRun.mutate('full')
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title="Sinkron & Log Accurate"
        subtitle={`Data Accurate masuk otomatis jam ${SYNC_SCHEDULE}.`}
        icon={<DatabaseZap className="size-5" />}
        actions={
          canRun && (
            <Button disabled={busy} onClick={syncNow}>
              {busy ? <Loader2 className="size-4 animate-spin" /> : <DatabaseZap className="size-4" />}
              {busy ? 'Sedang sinkron…' : 'Sinkron Sekarang'}
            </Button>
          )
        }
      />

      <AgentRunBanner status={runStatus} />

      <div className="inline-flex rounded-md border bg-muted/30 p-1">
        {TOP_TABS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5 text-sm font-medium transition ${
              tab === key ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground'
            }`}
          >
            <Icon className="size-4" /> {label}
          </button>
        ))}
      </div>

      {tab === 'history' ? <SyncHistoryTab /> : <AgentLogTab />}
    </div>
  )
}
