import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** Generic paginated list hook for a tracking module. */
export function useTrackingList<T>(base: string, params: Record<string, string | number | undefined>) {
  return useQuery({
    queryKey: [base, params],
    queryFn: async () => (await api.get<Paginated<T>>(`/api/${base}`, { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useTrackingItem<T>(base: string, id: number | null) {
  return useQuery({
    queryKey: [base, id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: T }>(`/api/${base}/${id}`)).data.data,
  })
}

export function useTrackingCreate<T>(base: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: unknown) => (await api.post<{ data: T }>(`/api/${base}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: [base] }),
  })
}

export function useTrackingAction<T>(base: string, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: T }>(`/api/${base}/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: [base] }),
  })
}

/** Ubah baris — hanya jalan selagi statusnya masih "open" (dicek di backend). */
export function useTrackingUpdate<T>(base: string, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: unknown) => (await api.put<{ data: T }>(`/api/${base}/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: [base] }),
  })
}

/** Hapus baris — hanya jalan selagi statusnya masih "open" (dicek di backend). */
export function useTrackingDelete(base: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete(`/api/${base}/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: [base] }),
  })
}

// ---- master lookups used by tracking forms ----

export interface AssetOption {
  id: number
  code: string
  name: string
  asset_type: string
  brand_model: string | null
}

export function useAssets(search = '') {
  return useQuery({
    queryKey: ['assets', search],
    queryFn: async () => (await api.get<{ data: AssetOption[] }>('/api/assets', { params: { search: search || undefined } })).data.data,
    staleTime: 60_000,
  })
}

export interface VendorOption {
  id: number
  name: string
}

export function useVendorOptions() {
  return useQuery({
    queryKey: ['vendors', {}],
    queryFn: async () => (await api.get<{ data: VendorOption[] }>('/api/vendors')).data.data,
    staleTime: 60_000,
  })
}

// ---- Accurate lookups for the tracking forms ----

/** Vehicles (nopol) as they appear on Accurate NPBG "Dikirim ke" / RI vendor. */
export function useVehicles(search = '') {
  return useQuery({
    queryKey: ['tracking-vehicles', search],
    queryFn: async () => (await api.get<{ data: { name: string; uses: number }[] }>('/api/tracking/vehicles', { params: { search: search || undefined } })).data.data,
    staleTime: 60_000,
  })
}

export interface AccurateDocSummary {
  number: string
  date: string | null
  party: string | null
  division?: string | null
  note: string | null
  /** Unit serials (SIG-…) written in the keterangan — only on search results. */
  serials?: string[]
  lines: { code: string | null; description: string | null; qty: number; unit: string | null }[]
}

/** NPBG / RI documents to pick from (newest first), searched by number, keterangan, customer/vendor or item. */
export function useAccurateDocSearch(type: 'npbg' | 'ri', q: string, enabled: boolean) {
  return useQuery({
    queryKey: ['tracking-docs', type, q.trim().toUpperCase()],
    enabled,
    queryFn: async () => (await api.get<{ data: AccurateDocSummary[] }>('/api/tracking/documents', { params: { type, q: q.trim() || undefined } })).data.data,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
}

/** An NPBG / RI number typed like in the Excel, looked up in the Accurate data. */
export function useAccurateDoc(type: 'npbg' | 'ri', no: string) {
  const n = no.trim()
  return useQuery({
    queryKey: ['tracking-doc', type, n.toUpperCase()],
    enabled: n.length >= 10,
    retry: false,
    queryFn: async () => (await api.get<{ data: AccurateDocSummary }>('/api/tracking/document', { params: { type, no: n } })).data.data,
    staleTime: 60_000,
  })
}

// ---- row types ----

export interface LendRow {
  id: number
  number: string
  /** Excel "No" */
  no: number | null
  status: string
  /** Excel "Tanda Keluar" (NPBG no.) / "Tanda Kembali" (RI no.) — or a picture in photos.out / photos.in */
  out_doc_no: string | null
  return_doc_no: string | null
  unit_raw: string | null
  photos: Partial<Record<'out' | 'in', { id: number; file_name: string | null }>>
  item_code: string | null
  description: string
  qty: number
  qty_returned: number
  unit: string | null
  purpose: string
  borrower_name: string | null
  out_date: string | null
  due_date: string | null
  return_date: string | null
  out_npbg: string | null
  return_ri: string | null
  /** ACCURATE = recognised from an NPBG line on sync (read-only); MANUAL = entered in STOCKWISE; EXCEL = from the Excel */
  source: 'MANUAL' | 'ACCURATE' | 'EXCEL'
  /** RI numbers that brought it back */
  return_refs: string | null
  /** Excel "Peminta" */
  requester_name: string | null
  est_days: number | null
  /** Excel "Keterangan Kembali" */
  return_note: string | null
  condition_out: string | null
  condition_in: string | null
}

export interface BorrowRow {
  id: number
  number: string
  no: number | null
  status: string
  /** Excel "Tanda Terima" (RI no.) / "Tanda Keluar" (NPBG no.) — or a picture in photos.in / photos.out */
  in_doc_no: string | null
  out_doc_no: string | null
  unit_raw: string | null
  photos: Partial<Record<'out' | 'in', { id: number; file_name: string | null }>>
  item_code: string | null
  description: string
  qty: number
  qty_returned: number
  unit: string | null
  lender_name: string | null
  receipt_ref: string | null
  borrowed_at: string | null
  returned_at: string | null
  return_npbg: string | null
  condition_note: string | null
  /** ACCURATE = recognised from an RI line on sync (read-only); MANUAL = entered in STOCKWISE; EXCEL = from the Excel */
  source: 'MANUAL' | 'ACCURATE' | 'EXCEL'
  /** NPBG numbers that sent it back */
  return_refs: string | null
  /** Excel "Keterangan Barang Kembali" */
  return_note: string | null
}

/** One row of the Excel sheet "STPP" (one serial unit handed out). */
export interface StppRow {
  id: number
  number: string
  /** Excel "No" */
  no: number | null
  /** NPBG number when the STPP came from an NPBG line, else the STPP's own number */
  display_number: string
  status: string
  serial_no: string | null
  item_code: string | null
  description: string
  qty: number
  unit: string | null
  holder: string | null
  placement: string | null
  out_date: string | null
  out_npbg: string | null
  npbg_linked: boolean
  out_item_no: number | null
  out_file_name: string | null
  helper_out: string | null
  out_note: string | null
  return_date: string | null
  /** Excel "Tanda Kembali" (No. RI) */
  return_ri: string | null
  ri_linked: boolean
  return_item_no: number | null
  return_file_name: string | null
  helper_in: string | null
  return_note: string | null
  source: string | null
  photos: Partial<Record<'out' | 'in', { id: number; file_name: string | null }>>
  npbg?: AccurateDocSummary | null
  ri?: AccurateDocSummary | null
  history?: { id: number; number: string; out_npbg_no: string | null; out_date: string | null; status: string; holder_name_raw: string | null; return_ri_no: string | null; return_date: string | null }[]
  maintenance?: { id: number; requester: string | null; npbg_date: string | null; npbg_no: string | null; note: string | null }[]
}

/** One row of the Excel sheet "Maintenance" of STPP. */
export interface StppMaintenanceRow {
  id: number
  no: number | null
  serial_no: string
  description: string
  qty: number | null
  unit: string | null
  requester: string | null
  npbg_date: string | null
  npbg_no: string | null
  npbg_linked: boolean
  note: string | null
}

/** One row of the Excel sheet "Ban Luar" (one tyre). */
export interface TyreRow {
  id: number
  /** Excel "No" */
  no: number | null
  status: string
  source: 'MANUAL' | 'ACCURATE' | 'EXCEL'
  nopol: string | null
  asset_code: string | null
  change_date: string | null
  position: string | null
  change_seq: number | null
  /** Excel "Ban" / "Pergantian" */
  tyre_no: number | null
  replacement_no: number | null
  new_tyre_desc: string | null
  new_serial_raw: string | null
  /** Excel "Column1" = Nama File of Foto Ban (Out) */
  new_file_name: string | null
  helper_out: string | null
  old_tyre_desc: string | null
  old_serial_raw: string | null
  old_file_name: string | null
  helper_in: string | null
  is_opening: boolean
  reason: string | null
  out_note: string | null
  in_note: string | null
  in_date: string | null
  out_npbg: string | null
  npbg_linked: boolean
  in_ri: string | null
  ri_linked: boolean
  photos: Partial<Record<'out' | 'in', { id: number; file_name: string | null }>>
  npbg?: AccurateDocSummary | null
  ri?: AccurateDocSummary | null
  serial_history?: { id: number; nopol_raw: string | null; change_date: string | null; out_npbg_no: string | null; new_serial_raw: string | null; old_serial_raw: string | null; status: string }[]
}

/** Sheet "Ban Luar BPN" row. */
export interface TyreBpnRow {
  id: number
  no: number | null
  cutoff_date: string | null
  nopol_raw: string | null
  tyre_desc: string | null
  serial_raw: string | null
  note: string | null
  photos: Partial<Record<'photo', { id: number; file_name: string | null }>>
}

/** Sheet "Deliver & Receive Ban SIG-BPN" row. */
export interface TyreBpnTransferRow {
  id: number
  no: number | null
  nopol_raw: string | null
  npbg_date: string | null
  npbg_no: string | null
  npbg_id: number | null
  new_tyre_desc: string | null
  new_serial_raw: string | null
  out_note: string | null
  ri_date: string | null
  ri_no: string | null
  old_tyre_desc: string | null
  old_serial_raw: string | null
  in_note: string | null
  photos: Partial<Record<'out' | 'in', { id: number; file_name: string | null }>>
}

/** One row of the Excel sheet "Maintenance Kendaraan" (a Sub SPK). */
export interface MaintenanceSub {
  id: number
  no: number | null
  report_date: string | null
  number: string
  sub_no: string
  nopol: string | null
  problem_detail: string | null
  /** Excel "Nama File" (Foto Sebelum) as entered / helper value. */
  before_file_name: string | null
  helper_before: string | null
  workshop: string | null
  /** Permintaan typed as text ("-" etc.); the picture is photos.request. */
  request_note: string | null
  status: string
  npbg_no: string | null
  npbg_linked: boolean
  npbg?: AccurateDocSummary | null
  after_file_name: string | null
  helper_after: string | null
  finish_date: string | null
  result_note: string | null
  source: string | null
  photos: Partial<Record<'request' | 'before' | 'after', { id: number; file_name: string | null }>>
}

/** Sheet list row: `id` = the SPK (opens its detail), `line_id` = the Sub SPK. */
export type MaintenanceLine = MaintenanceSub & { line_id: number }

export interface MaintenanceRow {
  id: number
  number: string
  status: string
  nopol: string | null
  asset_code: string | null
  asset_name: string | null
  report_date: string | null
  completed_at: string | null
  problem_summary: string | null
  subs_count?: number
  subs?: MaintenanceSub[]
}

export interface ManufacturingSub {
  id: number
  sub_no: string
  item_no: number | null
  product_name: string | null
  process: string | null
  serial_no: string | null
  status: string
  npbg_no: string | null
  npbg: AccurateDocSummary | null
  ri_no: string | null
  ri: AccurateDocSummary | null
  finish_date: string | null
  note_start: string | null
  note_end: string | null
  /** Excel "Nama File (#.ext)" as entered (with it the Excel shows the Foto Hasil link). */
  file_name: string | null
  /** Excel "Helper Nama File" — what Nama File should be. */
  helper_file_name: string | null
  has_photo: boolean
  no: number | null
  date: string | null
  location: string | null
  /** Hasil Produk is this Accurate item (link to Barang). */
  item_code: string | null
  /** Page listing this No. Seri (Tracking Ban Luar / STPP), when found. */
  serial_link: string | null
  npbg_linked: boolean
  ri_linked: boolean
  source: string | null
}

/** One Excel row (one unit) of sheet MA or MJ. `id` = the No. MA/MJ (opens its detail), `line_id` = the row. */
export interface ManufacturingLine {
  id: number
  line_id: number
  /** Excel "No" — row position in the sheet (oldest = 1). */
  no: number
  date: string | null
  number: string
  kind: string
  sub_no: string
  location: string | null
  product_name: string | null
  serial_no: string | null
  item_no: number | null
  process: string | null
  note_start: string | null
  status: string
  npbg_no: string | null
  file_name: string | null
  helper_file_name: string | null
  has_photo: boolean
  finish_date: string | null
  ri_no: string | null
  note_end: string | null
  item_code: string | null
  serial_link: string | null
  npbg_linked: boolean
  ri_linked: boolean
  source: string | null
}

export interface ManufacturingRow {
  id: number
  number: string
  kind: string
  status: string
  product_name: string | null
  location: string | null
  date: string | null
  completed_at: string | null
  vendor_name: string | null
  subs_count?: number
  subs?: ManufacturingSub[]
}

export interface UsedReturnRow {
  id: number
  number: string
  status: string
  format: string
  npbg_ref: string | null
  npbg_number?: string | null
  /** The internal Receiving created once this is actually closed (see UsedReturnService::close()) — for a derived row, `number` itself is already the source RI's own number, so there's no separate "accurate RI" field. */
  ri_number?: string | null
  return_date: string | null
  items_count?: number
  note?: string | null
  from_accurate?: boolean
  items?: { id: number; item_code: string | null; component_type: string | null; description: string | null; qty: number; condition: string; into_stock: boolean }[]
}

/** Flat list row — one per item line, same shape/spirit as `Ri` (features/ri/api.ts): the list itself mirrors RI's format, per explicit user instruction. `id` is the HEADER id (opens the same detail/close modal as UsedReturnRow); `line_id` uniquely keys this rendered row. */
export interface UsedReturnLineRow {
  id: number
  line_id: number
  number: string
  return_date: string | null
  status: string
  kode_barang: string | null
  deskripsi_barang: string | null
  kuantitas: number | null
  satuan: string | null
  condition: string
  into_stock: boolean
  from_accurate: boolean
}
