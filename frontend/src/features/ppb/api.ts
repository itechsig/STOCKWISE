import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '@/lib/notify'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** PPB — mirror of Accurate REQUISITION (header) + REQUISITIONDET (line), one row per line. Mostly read-only, bisa juga dibuat manual. */
export interface Ppb {
  id: number
  no_ppb: string | null
  tgl_ppb: string | null
  tgl_diperlukan: string | null
  /** true untuk PPB yang dibuat manual di Stockwise (bukan Sync Accurate). */
  is_manual: boolean
  status: string | null
  /** Per-line status (Excel "1. PPB - RI"): REQUESTED / SHORTAGE / COMPLETED / CLOSE / AMEND / ERROR. */
  line_status: string | null
  /** Excel "Amend", shown next to the status (Amend + Requested …) */
  is_amend: boolean
  /** NOTE = listed in "PPB AMEND (…)", CLOSED = PPB closed with nothing received */
  amend_source: 'NOTE' | 'CLOSED' | null
  divisi: string | null
  kode_barang: string | null
  deskripsi_barang: string | null
  kuantitas: number | null
  satuan: string | null
  qty_dipesan: number | null
  qty_diterima: number | null
  peminta: string | null
  keterangan: string | null
  catatan_baris: string | null
  accurate_synced_at: string | null
  created_at: string
  updated_at: string
  /** Accurate's own PODET.REQID/REQSEQ chain — PO lines raised from this PPB line (only on the detail response). */
  purchased_via?: { purchase_order_id: number; number: string | null; qty: number; qty_received: number }[]
  /** Same chain, carried all the way to RI, joined "A, B" for the list — a PPB line can be split across more than one PO/RI. */
  /** Vendor of the PO(s) raised from this line, joined "A, B" — null while no PO exists yet. */
  vendor?: string | null
  po_no?: string | null
  po_date?: string | null
  ri_no?: string | null
  ri_date?: string | null
  /** approval before PO, for the whole PPB document (PpbApprovalService) */
  approval?: PpbApproval | null
  /** detail only: the approval log, oldest first */
  /** sent back: admin revises it and sends it again */
  can_resubmit?: boolean
  /** the viewer can approve / reject it now (their step) */
  can_decide?: boolean
  approval_log?: PpbApprovalLog[]
  /** detail only: lines in this PPB document (approval covers all of them) */
  document_lines?: number
  /** detail only: the "Menunggu PO" entry the approval put in the PO table (null once Accurate's PO replaced it) */
  approval_po?: { id: number; number: string } | null
  /** detail only: every line of the PPB document (the approval covers all of them) */
  document_items?: { id: number; kode_barang: string | null; deskripsi_barang: string | null; kuantitas: number | null; satuan: string | null; peminta: string | null; catatan_baris: string | null }[]
}

/** MENUNGGU_DIREKTUR: a STOCKWISE PPB the Manager approved, waiting for the Direktur */
export type PpbApprovalStatus = 'MENUNGGU' | 'MENUNGGU_DIREKTUR' | 'DISETUJUI' | 'DITOLAK' | 'DIKEMBALIKAN' | 'TIDAK_PERLU'

export interface PpbApproval {
  status: PpbApprovalStatus
  at: string | null
  by: string | null
  note: string | null
  /** Accurate made a PO before anyone approved (shown in red) */
  po_without_approval: boolean
  /** made in STOCKWISE: two steps, Manager then Direktur */
  stockwise?: boolean
  /** whose step it is now (STOCKWISE PPB) */
  step?: 'MANAGER' | 'DIREKTUR' | null
}

export interface PpbApprovalLog {
  id: number
  /** DIAJUKAN / APPROVE / REJECT / PO_TANPA_APPROVAL */
  action: string
  note: string | null
  user_name: string | null
  created_at: string
}

export const APPROVAL_STATUS: Record<PpbApprovalStatus, { label: string; variant: 'warning' | 'success' | 'danger' | 'neutral' }> = {
  MENUNGGU: { label: 'Menunggu approval', variant: 'warning' },
  MENUNGGU_DIREKTUR: { label: 'Menunggu Direktur', variant: 'warning' },
  DISETUJUI: { label: 'Disetujui', variant: 'success' },
  DITOLAK: { label: 'Ditolak', variant: 'danger' },
  DIKEMBALIKAN: { label: 'Dikembalikan untuk revisi', variant: 'warning' },
  TIDAK_PERLU: { label: 'Sebelum approval', variant: 'neutral' },
}

/** Admin revised a PPB sent back: to the approval again. */
export function useResubmitPpb(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { quantities?: Record<number, number>; note?: string | null }) => (await api.post<{ data: Ppb }>(`/api/ppb/${id}/resubmit`, body)).data.data,
    meta: { successMessage: 'PPB diajukan ulang ke approval.' },
    onSuccess: () => Promise.all([qc.invalidateQueries({ queryKey: ['ppb'] }), qc.invalidateQueries({ queryKey: ['item-requests', 'approvals'] })]),
  })
}

export function useDecidePpb(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { action: 'APPROVE' | 'REJECT' | 'RETURN'; note?: string | null; quantities?: Record<number, number> }) =>
      (await api.post<{ data: Ppb }>(`/api/ppb/${id}/approval`, body)).data.data,
    meta: { successMessage: false },
    onSuccess: (_d, v) => {
      toast.success(v.action === 'RETURN' ? 'PPB dikembalikan ke admin untuk revisi.' : v.action === 'REJECT' ? 'PPB ditolak.' : _d.approval?.status === 'MENUNGGU_DIREKTUR' ? 'Disetujui Manager — menunggu Direktur.' : 'PPB disetujui, masuk ke tabel PO.')
      return Promise.all([qc.invalidateQueries({ queryKey: ['ppb'] }), qc.invalidateQueries({ queryKey: ['item-requests', 'approvals'] }), qc.invalidateQueries({ queryKey: ['purchase-orders'] })])
    },
  })
}

export function usePpbList(params: {
  search?: string
  status?: string
  line_status?: string
  divisi?: string
  vendor_id?: string
  /** MENUNGGU / DISETUJUI / DITOLAK / PO_TANPA_APPROVAL */
  approval?: string
  date_from?: string
  date_to?: string
  page?: number
}) {
  return useQuery({
    queryKey: ['ppb', params],
    queryFn: async () => (await api.get<Paginated<Ppb>>('/api/ppb', { params })).data,
    placeholderData: keepPreviousData,
  })
}

/** Payload untuk membuat PPB manual (permintaan yang tidak lewat Accurate). */
export interface PpbCreatePayload {
  /** confirmed: not the same as a recent look-alike (App\Support\DuplicateGuard) */
  allow_duplicate?: boolean
  no_ppb: string
  tgl_ppb: string
  tgl_diperlukan?: string | null
  divisi?: string | null
  kode_barang?: string | null
  deskripsi_barang: string
  kuantitas: number
  satuan?: string | null
  peminta?: string | null
  catatan_baris?: string | null
  keterangan?: string | null
}

export function useCreatePpb() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: PpbCreatePayload) => (await api.post<{ data: Ppb }>('/api/ppb', payload)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ppb'] }),
  })
}

export function usePpb(id: number | null) {
  return useQuery({
    queryKey: ['ppb', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Ppb }>(`/api/ppb/${id}`)).data.data,
  })
}

/** Real, evolving list (Accurate department names) for the Divisi filter dropdown — not a fixed enum. */
export function usePpbDivisiOptions() {
  return useQuery({
    queryKey: ['ppb', 'divisi-options'],
    queryFn: async () => (await api.get<{ data: string[] }>('/api/ppb/divisi-options')).data.data,
    staleTime: 5 * 60_000,
  })
}

/** Vendor filter options — only vendors that have a PO raised from a PPB line. */
export function usePpbVendorOptions() {
  return useQuery({
    queryKey: ['ppb', 'vendor-options'],
    queryFn: async () => (await api.get<{ data: { id: number; name: string }[] }>('/api/ppb/vendor-options')).data.data,
    staleTime: 5 * 60_000,
  })
}

/** Accurate requisition status: OPEN = masih berjalan, CLOSED = sudah selesai/ditutup. */
export const PPB_STATUS: Record<string, { label: string; variant: 'success' | 'warning' }> = {
  OPEN: { label: 'Terbuka', variant: 'warning' },
  CLOSED: { label: 'Ditutup', variant: 'success' },
}

type BadgeVariant = 'success' | 'warning' | 'danger' | 'neutral' | 'default'

/** Per-line PPB status as Accurate holds it (backend App\Support\PpbLineStatus). */
export const PPB_LINE_STATUS: Record<string, { label: string; variant: BadgeVariant; hint: string }> = {
  REQUESTED: { label: 'Requested', variant: 'default', hint: 'Diminta, barang belum diterima (belum ada RI)' },
 SHORTAGE: { label: 'Shortage', variant: 'warning', hint: 'Baru diterima sebagian sisanya menyusul' },
  COMPLETED: { label: 'Completed', variant: 'success', hint: 'Barang sudah diterima lengkap' },
 CLOSE: { label: 'Close', variant: 'neutral', hint: 'PPB ditutup di Accurate sisanya tidak dikirim' },
}

/** Excel "Amend" — a second status next to the one above (e.g. Amend + Requested). */
export const PPB_AMEND = {
  label: 'Amend',
 hint: { NOTE: 'Tercantum di "PPB AMEND (…)" pembelian berubah / tidak jadi dibeli', CLOSED: 'PPB ditutup tanpa ada barang diterima tidak jadi dibeli' } as Record<string, string>,
}

/**
 * "NA" / "ATK" come from the PPB number (PPB/NA/.., PPB/ATK/..) — a numbering
 * code, not a division. Only a real Accurate department name is shown.
 */
export function ppbDivisi(divisi: string | null | undefined): string | null {
  return divisi && !['NA', 'ATK'].includes(divisi.toUpperCase()) ? divisi : null
}
