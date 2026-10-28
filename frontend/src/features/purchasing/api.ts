import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

// ---------------------------------------------------------------- Purchase Order

export interface PoLine {
  id: number
  item_id?: number | null
  item_code: string | null
  description: string
  /** the vendor of this item (one PO number may hold items of several vendors) */
  vendor_id?: number | null
  vendor_name?: string | null
  qty: number
  unit: string | null
  unit_price: number
  line_total: number
  qty_received: number
  line_status: string
  /** Accurate's own PODET.REQID/REQSEQ chain — the PPB (mirror) line this PO line was raised from, if any. */
  source_ppb?: { id: number; no_ppb: string | null } | null
  /** RI lines that received against this PO line. */
  received_via?: { id: number; no_ri: string | null; kuantitas: number | null; tgl_ri: string | null }[]
}

export interface PurchaseOrder {
  id: number
  number: string
  status: string
  date: string | null
  vendor_name?: string | null
  vendor?: { id: number; name: string } | null
  /** every vendor of its items */
  vendors?: { id: number; name: string }[]
  items_count?: number
  total: number
  expected_date?: string | null
  notes?: string | null
  created_at: string
  approved_at?: string | null
  items?: PoLine[]
  receivings?: { id: number; number: string; status: string }[]
  /** Non-null when this row was synced straight from Accurate PO/PODET — read-only, no internal approve/send/cancel. */
  accurate_po_id?: number | null
  /** true untuk PO yang dibuat manual di Stockwise (bukan Sync Accurate). */
  is_manual?: boolean
  accurate_synced_at?: string | null
  /** Chain PPB -> PO -> RI is per line (Accurate's own PODET/APITMDET) — a multi-item PO can span several, joined "A, B". */
  item_code?: string | null
  description?: string | null
  ppb_no?: string | null
  ppb_date?: string | null
  ri_no?: string | null
  ri_date?: string | null
  divisi?: string | null
  keterangan?: string | null
  /** STOCKWISE PO: the Direktur's approval and its log */
  approval_status?: 'MENUNGGU_DIREKTUR' | 'DIKEMBALIKAN' | 'DISETUJUI' | null
  approval_log?: { id: number; action: 'DIAJUKAN' | 'APPROVE' | 'REJECT' | 'DIBELI'; note: string | null; user_name: string | null; created_at: string | null }[]
  /** made from the approval of this PPB ("Menunggu PO" entry) */
  from_ppb_approval?: string | null
  /** purchasing can complete it (vendor / price) or fix it */
  can_complete?: boolean
  /** the Direktur can approve / send it back now */
  can_approve?: boolean
  /** the proof of purchase (nota / invoice) — uploaded once approved, the PO is then "Sudah dibeli" (DIBELI) */
  proof?: { file_name: string | null; mime: string | null; at: string | null } | null
  can_upload_proof?: boolean
}

/**
 * One row per item line (like PPB/RI) — a multi-item PO shows as several rows
 * sharing the same Nomor PO, each with its own kode barang/qty/status, instead
 * of one crowded row with every item joined into a single cell.
 */
export interface PoListRow {
  id: number
  /** approval of this line's PPB before PO; po_without_approval = Accurate made the PO first (red) */
  ppb_approval?: import('@/features/ppb/api').PpbApproval | null
  po_id: number
  po_number: string
  po_date: string | null
  vendor_name: string | null
  item_code: string | null
  description: string | null
  qty: number
  /** received so far by RI (a partial one: less than qty) */
  received?: number
  unit: string | null
  /** Status baris ini sendiri (PENDING/PARTIAL_RECEIVED/RECEIVED/CLOSED) — bukan status header PO. */
  status: string
  ppb_no: string | null
  ppb_date: string | null
  /** Baris ini bisa diterima lewat lebih dari satu RI seiring waktu — digabung "A, B". */
  ri_no: string | null
  ri_date: string | null
  divisi: string | null
  keterangan: string | null
  accurate_po_id: number | null
  is_manual?: boolean
}

export function usePoList(params: { status?: string; search?: string; page?: number; date_from?: string; date_to?: string; source?: string }) {
  return useQuery({
    queryKey: ['purchase-orders', params],
    queryFn: async () => (await api.get<Paginated<PoListRow>>('/api/purchase-orders', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function usePo(id: number | null) {
  return useQuery({
    queryKey: ['purchase-orders', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: PurchaseOrder }>(`/api/purchase-orders/${id}`)).data.data,
  })
}

export function usePoAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: PurchaseOrder }>(`/api/purchase-orders/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-orders'] }),
  })
}

/** Real, evolving list (Accurate department names) for the Divisi filter dropdown — not a fixed enum. */
export function usePoDivisiOptions() {
  return useQuery({
    queryKey: ['purchase-orders', 'divisi-options'],
    queryFn: async () => (await api.get<{ data: string[] }>('/api/purchase-orders/divisi-options')).data.data,
    staleTime: 5 * 60_000,
  })
}

/** Payload untuk membuat PO manual (header + banyak baris barang). */
export interface PoCreateLine {
  item_id?: number | null
  description_raw: string
  qty: number
  unit_id?: number | null
  unit_price: number
}

export interface PoCreatePayload {
  /** confirmed: not the same as a recent look-alike (App\Support\DuplicateGuard) */
  allow_duplicate?: boolean
  vendor_id: number
  site_id: number
  date: string
  expected_date?: string | null
  notes?: string | null
  items: PoCreateLine[]
}

export function useCreatePo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: PoCreatePayload) => (await api.post<{ data: PurchaseOrder }>('/api/purchase-orders', payload)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-orders'] }),
  })
}

export interface Site {
  id: number
  code: string
  name: string
}

export function useSites() {
  return useQuery({
    queryKey: ['sites'],
    queryFn: async () => (await api.get<{ data: Site[] }>('/api/sites')).data.data,
    staleTime: 10 * 60_000,
  })
}

// ---------------------------------------------------------------- Vendors

export interface Vendor {
  id: number
  name: string
  code: string | null
  phone: string | null
  email: string | null
  /** Master Vendor & Supplier */
  address?: string | null
  /** 'accurate' = from the Accurate sync */
  source?: string | null
  is_active: boolean
  needs_review: boolean
}

export function useVendors(params: { search?: string; limit?: number; is_active?: string } = {}) {
  return useQuery({
    queryKey: ['vendors', params],
    queryFn: async () => (await api.get<{ data: Vendor[] }>('/api/vendors', { params })).data.data,
  })
}

export function useSaveVendor() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...body }: Partial<Vendor> & { id?: number }) =>
      id
        ? (await api.put<{ data: Vendor }>(`/api/vendors/${id}`, body)).data.data
        : (await api.post<{ data: Vendor }>('/api/vendors', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vendors'] }),
  })
}

/** STOCKWISE PO approved: upload the proof of purchase (photo / PDF) → "Sudah dibeli". */
export function useUploadPoProof(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.append('file', file)
      return (await api.post<{ data: PurchaseOrder }>(`/api/purchase-orders/${id}/proof`, form)).data.data
    },
    meta: { successMessage: 'Bukti pembelian diunggah — PO sudah dibeli.' },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-orders'] }),
  })
}

/** Opens the proof of purchase in a new tab (fetched with the session token). */
export async function openPoProof(id: number) {
  const tab = window.open('', '_blank')
  const blob = (await api.get(`/api/purchase-orders/${id}/proof`, { responseType: 'blob' })).data as Blob
  const url = URL.createObjectURL(blob)
  if (tab) tab.location.href = url
  else window.location.href = url
}

/** STOCKWISE PO: vendor, prices (and quantities) — then it waits for the Direktur. */
export function useCompletePo(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { vendor_id: number | null; expected_date?: string | null; notes?: string | null; lines: { id: number; qty: number; unit_price: number; vendor_id?: number | null }[] }) =>
      (await api.put<{ data: PurchaseOrder }>(`/api/purchase-orders/${id}/complete`, body)).data.data,
    meta: { successMessage: 'PO diajukan ke Direktur.' },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-orders'] }),
  })
}
