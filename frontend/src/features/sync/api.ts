import { useIsMutating, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '@/lib/notify'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** Shared key so any page's "Sync Accurate" button can be observed globally (see SyncProgressModal) without prop-drilling. */
export const TRIGGER_SYNC_MUTATION_KEY = ['trigger-sync']

/** Stable step keys written by AccurateSyncService::run() — translated here, not on the backend, so wording can change without touching sync logic. */
export const SYNC_STEP_LABELS: Record<string, string> = {
  staging: 'Menghubungkan ke Accurate & menyalin data staging',
  items: 'Sinkronisasi Master Barang',
  npbg: 'Sinkronisasi NPBG',
  npbg_renumber: 'Menyamakan nomor NPBG yang berubah ke modul tracking',
  stpp: 'Membuat STPP dari NPBG',
  ppb: 'Sinkronisasi PPB',
  po: 'Sinkronisasi Purchase Order',
  ppb_status: 'Menghitung status PPB dari PO',
  ppb_approval: 'Memeriksa approval PPB sebelum PO',
  ri: 'Sinkronisasi RI',
  ri_renumber: 'Menyamakan nomor RI yang berubah ke modul tracking',
  used_returns: 'Membuat Pengembalian Bekas dari RI',
  lend_borrow: 'Membuat Peminjaman & Pinjam Luar dari NPBG/RI',
  tyres: 'Membuat tracking Ban Luar dari NPBG/RI',
  stock_opname: 'Sinkronisasi Stock Opname',
  stockwise_purge: 'Menghapus data buatan STOCKWISE (data murni Accurate)',
}

export const SYNC_STEP_ORDER = ['staging', 'items', 'npbg', 'npbg_renumber', 'stpp', 'ppb', 'po', 'ppb_status', 'ri', 'ri_renumber', 'used_returns', 'lend_borrow', 'tyres', 'stock_opname', 'stockwise_purge']

/** One field that really changed (same rule as the sync — backend App\Support\SyncDiff). */
export interface SyncFieldChange {
  field: string
  /** e.g. "Nomor NPBG", "Baris 2 (MAI.0828) · Qty diterima" */
  label: string
  from: string | null
  to: string | null
}

export interface SyncLogRow {
  id: number
  entity: string
  /** NPBG / PPB / RI / PO / Barang / Stock Opname — or the tracking module for a renumber */
  entity_label: string
  source_id: string
  action: 'INSERT' | 'UPDATE' | 'DELETE' | 'SKIP' | 'ERROR'
  status: 'SUCCESS' | 'FAILED'
  message: string | null
  /** document number (NPBG/PPB/RI/PO/opname) */
  document: string | null
  /** the previous number when Accurate changed it */
  document_old: string | null
  item_code: string | null
  /** Master Barang id — only for logs about one item (item/npbg/ppb/ri) */
  item_id: number | null
  item_name: string | null
  changes: SyncFieldChange[]
  created_at: string
}

export interface SyncBatchRow {
  id: number
  sync_code: string
  source: string
  status: 'PENDING' | 'RUNNING' | 'SUCCESS' | 'PARTIAL' | 'FAILED'
  current_step: string | null
  started_at: string
  finished_at: string | null
  duration_seconds: number | null
  total_records: number
  inserted_records: number
  updated_records: number
  skipped_records: number
  deleted_records: number
  error_records: number
  error_message: string | null
}

export interface SyncBatchDetail extends SyncBatchRow {
  logs: SyncLogRow[]
  /** real changes per data type (updates without a real change are not counted) */
  log_summary: { entity: string; label: string; actions: Partial<Record<SyncLogRow['action'], number>> }[]
  log_total: number
  log_shown: number
  /** older logs: "updated" rows where nothing really changed — hidden */
  log_no_change: number
}

export interface SyncLogFilters {
  entity?: string
  action?: string
  search?: string
}

export function useSyncStatus(enabled = true) {
  // Poll as soon as ANY page's trigger button is pressed (mutation cache is
  // global, so this fires even if that button lives on a different page than
  // wherever useSyncStatus() is mounted) — not just once the batch already
  // shows RUNNING, so a fresh page load doesn't miss the very first second.
  const isTriggering = useIsMutating({ mutationKey: TRIGGER_SYNC_MUTATION_KEY }) > 0

  return useQuery({
    queryKey: ['sync', 'status'],
    queryFn: async () => (await api.get<{ data: SyncBatchRow | null }>('/api/sync/status')).data.data,
    // 5s while a batch is RUNNING: every open tab of every user with
    // sync.accurate.view polls this during a sync, on a single-threaded server.
    refetchInterval: (query) => (isTriggering ? 1500 : query.state.data?.status === 'RUNNING' ? 5000 : false),
    enabled,
  })
}

export function useSyncHistory(page: number) {
  return useQuery({
    queryKey: ['sync', 'history', page],
    queryFn: async () => (await api.get<Paginated<SyncBatchRow>>('/api/sync/history', { params: { page } })).data,
  })
}

/** One Sync Agent run from sync.log — includes runs that never reached the API (failed before a batch started, or snapshot-only). */
export interface AgentRun {
  time: string
  source: string
  status: string
  error: string | null
}

export interface AgentEvent {
  time: string
  level: 'INFO' | 'WARNING' | 'ERROR' | 'CRITICAL' | 'DEBUG'
  message: string
}

export interface AgentLog {
  runs: AgentRun[]
  events: AgentEvent[]
  log_available: boolean
}

export function useAgentLog() {
  return useQuery({
    queryKey: ['sync', 'agent-log'],
    queryFn: async () => (await api.get<{ data: AgentLog }>('/api/sync/agent-log')).data.data,
    refetchInterval: 60_000,
  })
}

export function useSyncBatchDetail(id: number | null, filters: SyncLogFilters = {}) {
  return useQuery({
    queryKey: ['sync', 'history', 'detail', id, filters],
    queryFn: async () => (await api.get<{ data: SyncBatchDetail }>(`/api/sync/history/${id}`, { params: filters })).data.data,
    enabled: id != null,
    placeholderData: (prev) => prev,
  })
}

export function useTriggerSync() {
  const qc = useQueryClient()
  return useMutation({
    mutationKey: TRIGGER_SYNC_MUTATION_KEY,
    mutationFn: async () => (await api.post<{ data: SyncBatchRow }>('/api/sync/accurate')).data.data,
    // Custom popup instead of the generic "Berhasil disimpan." — the pages
    // that show useTriggerSync() also render a rich inline result box, so
    // this keeps the popup a quick, useful summary rather than a duplicate.
    meta: { successMessage: false },
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['sync'] })
      // RUNNING (202): processing continues in a background process on the
      // server; SyncProgressModal follows it and announces the result.
      if (data.status !== 'RUNNING') announceSyncResult(data)
    },
  })
}

/** Toast for a finished batch — used right away (already finished) or by SyncProgressModal once it finishes. */
export function announceSyncResult(data: SyncBatchRow) {
  if (data.status === 'FAILED') {
    // The raw error_message is a backend exception string (SQL errors,
    // connection failures, etc.) — not something a warehouse/purchasing
    // user should see raw. Point them at Sync History instead of dumping it.
    toast.error('Sinkronisasi Accurate gagal. Coba lagi sebentar lagi, atau buka Sync & Histori Accurate untuk detailnya.')
    return
  }

  // Only mention what actually happened — a wall of "Baru 0, Diperbarui 0,
  // Dilewati 29238" for a routine no-op sync reads as noise, not information.
  const changes = [
    data.inserted_records > 0 && `${data.inserted_records} data baru`,
    data.updated_records > 0 && `${data.updated_records} diperbarui`,
    data.deleted_records > 0 && `${data.deleted_records} dihapus`,
  ].filter(Boolean)

  if (data.status === 'PARTIAL') {
    toast.warning(
      `Sinkronisasi selesai dengan ${data.error_records} error` +
        (changes.length ? ` (${changes.join(', ')})` : '') + '. Buka Sync & Histori Accurate untuk detailnya.'
    )
  } else if (changes.length === 0) {
    toast.success('Sinkronisasi selesai — semua data sudah sesuai dengan Accurate, tidak ada perubahan.')
  } else {
    toast.success(`Sinkronisasi selesai — ${changes.join(', ')}.`)
  }
}
