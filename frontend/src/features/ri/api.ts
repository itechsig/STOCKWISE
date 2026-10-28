import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** RI — mirror of Accurate APINV (header) + APITMDET (item lines), one row per line. Mostly read-only, bisa juga dibuat manual. */
export interface Ri {
  id: number
  no_ri: string | null
  tgl_ri: string | null
  /** true untuk RI yang dibuat manual di Stockwise (bukan Sync Accurate). */
  is_manual: boolean
  divisi: string | null
  /** where it comes from: PO, BEKAS (pengembalian bekas), MANUAL, ACCURATE (Accurate RI without PO) */
  sumber?: RiSource | 'ACCURATE'
  /** BEKAS: the Tracking Pengembalian Bekas row it brought back */
  used_return_row_id?: number | null
  vendor: string | null
  /** Resolved `vendors` row id — same one PO sync creates/uses for this Accurate vendor. */
  vendor_id?: number | null
  no_po: string | null
  shipdate: string | null
  kode_barang: string | null
  deskripsi_barang: string | null
  kuantitas: number | null
  satuan: string | null
  harga_satuan: number | null
  /** from the Accurate Department of the line (format since Sept 2026), else ITEMRESERVED3 */
  pemeriksa: string | null
  lokasi_gudang?: string | null
  lokasi_rak?: string | null
  /** template "RI [A5]": No. Surat Jalan [Invoice Number], Pengirim [Ship Via], FOB, No. PPB [Request No], No. Seri [SNLine] */
  no_surat_jalan?: string | null
  pengirim?: string | null
  fob?: string | null
  no_ppb?: string | null
  no_seri?: string | null
  keterangan: string | null
  /** Resolved one hop further back than source_po — the PPB date for the PO line this RI received against (full PPB -> PO -> RI chain). */
  tgl_ppb?: string | null
  /** Accurate's own APITMDET.POID/POSEQ chain — the PO line this RI line received against, if any (only ~46% of RI lines have one; the rest are internal stock-take style receipts with no PO). */
  source_po?: { purchase_order_id: number; purchase_order_item_id: number; number: string | null; date: string | null } | null
  accurate_synced_at: string | null
  /** proof photos of a manual RI (foto1..foto3), on its detail */
  photos?: string[]
  /** documents of the RI (surat jalan / invoice, slots doc1..doc3, photo or PDF), on its detail */
  documents?: { slot: string; file_name: string | null; mime: string | null }[]
  /** every line of the same RI document, on its detail */
  lines?: { id: number; kode_barang: string | null; deskripsi_barang: string | null; kuantitas: number | null; satuan: string | null; harga_satuan: number | null; no_seri: string | null; no_po: string | null; no_ppb: string | null; photos?: string[] }[]
  created_at: string
  updated_at: string
}

export type RiSource = 'PO' | 'BEKAS' | 'MANUAL'

export const RI_SOURCE: Record<RiSource | 'ACCURATE', string> = {
  PO: 'Dari PO', BEKAS: 'Pengembalian bekas', MANUAL: 'Manual', ACCURATE: 'Accurate (tanpa PO)',
}

export function useRiList(params: {
  search?: string
  divisi?: string
  sumber?: string
  vendor?: string
  date_from?: string
  date_to?: string
  page?: number
}) {
  return useQuery({
    queryKey: ['ri', params],
    queryFn: async () => (await api.get<Paginated<Ri>>('/api/ri', { params })).data,
    placeholderData: keepPreviousData,
  })
}

/** Distinct vendor text values actually in the RI data — not every row resolves to a `vendors` master row, so options come from the data itself. */
export function useRiVendorOptions() {
  return useQuery({
    queryKey: ['ri', 'vendor-options'],
    queryFn: async () => (await api.get<{ data: string[] }>('/api/ri/vendor-options')).data.data,
    staleTime: 5 * 60_000,
  })
}

/** Payload untuk membuat RI manual (penerimaan barang yang tidak lewat Accurate). */
export interface RiCreatePayload {
  /** confirmed: not the same as a recent look-alike (App\Support\DuplicateGuard) */
  allow_duplicate?: boolean
  divisi: 'NA' | 'ATK' | 'NV'
  vendor?: string | null
  no_po?: string | null
  no_surat_jalan?: string | null
  kode_barang?: string | null
  deskripsi_barang: string
  kuantitas: number
  satuan?: string | null
  harga_satuan?: number | null
  no_seri?: string | null
  pemeriksa?: string | null
  tgl_ri?: string | null
  keterangan?: string | null
  /** goods of a STOCKWISE PO line (maybe part of it) */
  purchase_order_item_id?: number | null
  sumber: RiSource
  /** BEKAS: the used goods coming back (Tracking Pengembalian Bekas row) */
  used_return_row_id?: number | null
  /** 1 to 3 proof photos (JPEG data URLs) */
  photos: string[]
}

/** One proof photo of a RI, fetched with the session (an object URL). */
export function useRiPhoto(id: number, slot: string) {
  return useQuery({
    queryKey: ['ri', id, 'photo', slot],
    staleTime: Infinity,
    queryFn: async () => URL.createObjectURL((await api.get(`/api/ri/${id}/photo/${slot}`, { responseType: 'blob' })).data as Blob),
  })
}

export function useCreateRi() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: RiCreatePayload) => (await api.post<{ data: Ri }>('/api/ri', payload)).data.data,
    onSuccess: () => Promise.all([qc.invalidateQueries({ queryKey: ['ri'] }), qc.invalidateQueries({ queryKey: ['purchase-orders'] }), qc.invalidateQueries({ queryKey: ['item-requests'] }),
      qc.invalidateQueries({ queryKey: ['npbg'] }), qc.invalidateQueries({ queryKey: ['used-returns'] })]),
  })
}

export function useRi(id: number | null) {
  return useQuery({
    queryKey: ['ri', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Ri }>(`/api/ri/${id}`)).data.data,
  })
}

/**
 * The middle segment of an RI number (RI/NA/.., RI/ATK/.., RI/NV/..) is the kind
 * of receipt, not a division (stored as `divisi` for historical reasons):
 * NA/ATK receipts always reference a PO; NV ones never do and are returns /
 * stock found in the warehouse (verified against live APINV data 2026-09-29).
 */
export function riJenis(code: string | null | undefined): string {
  switch ((code ?? '').toUpperCase()) {
    case 'NA': return 'Pembelian'
    case 'ATK': return 'Pembelian ATK'
    case 'NV': return 'Non-vendor (pengembalian)'
    default: return code || '—'
  }
}

/** A STOCKWISE PO line still waiting for goods — the "Dari PO" picker of a manual RI. */
export interface RiPoLine {
  id: number
  po_id: number
  po_number: string
  vendor: string | null
  kode_barang: string | null
  deskripsi: string
  satuan: string | null
  qty: number
  received: number
  remaining: number
  unit_price: number
}

/** Used goods still to come back (Tracking Pengembalian Bekas, no RI yet) — the "Pengembalian bekas" picker. */
export interface RiUsedReturnLine {
  id: number
  npbg_no: string
  npbg_date: string | null
  deskripsi: string | null
  qty: number | null
  satuan: string | null
  item_no: number | null
  kode_barang: string | null
  pelanggan: string | null
  peminta: string | null
  divisi: string | null
}

export function useRiUsedReturnLines(search: string, enabled: boolean) {
  return useQuery({
    queryKey: ['ri', 'used-return-lines', search],
    enabled,
    queryFn: async () => (await api.get<{ data: RiUsedReturnLine[] }>('/api/ri/used-return-lines', { params: { search: search || undefined } })).data.data,
  })
}

/** A STOCKWISE PO that can still receive goods, with ALL its lines — "RI dari PO". */
export interface RiPoOrder {
  id: number
  number: string
  date: string | null
  vendor: string | null
  status: string
  lines: { id: number; kode_barang: string | null; deskripsi: string; satuan: string | null; no_ppb: string | null; vendor?: string | null; qty: number; received: number; remaining: number; unit_price: number }[]
}

export function useRiPoOrders(search: string, enabled: boolean) {
  return useQuery({
    queryKey: ['ri', 'po-orders', search],
    enabled,
    queryFn: async () => (await api.get<{ data: RiPoOrder[] }>('/api/ri/po-orders', { params: { search: search || undefined } })).data.data,
  })
}

/** One RI for the goods of a PO that arrived: per line how much came (part is fine), photos and documents. */
export interface RiFromPoPayload {
  purchase_order_id: number
  divisi: 'NA' | 'ATK' | 'NV'
  no_surat_jalan?: string | null
  pemeriksa?: string | null
  tgl_ri?: string | null
  keterangan?: string | null
  /** each item received with its own proof photos (1 to 3) */
  lines: { purchase_order_item_id: number; qty: number; no_seri?: string | null; photos: string[] }[]
  documents?: { data: string; name: string }[]
  allow_duplicate?: boolean
}

export function useCreateRiFromPo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: RiFromPoPayload) => (await api.post<{ data: Ri }>('/api/ri/from-po', payload)).data.data,
    onSuccess: () => Promise.all([qc.invalidateQueries({ queryKey: ['ri'] }), qc.invalidateQueries({ queryKey: ['purchase-orders'] }), qc.invalidateQueries({ queryKey: ['item-requests'] })]),
  })
}

export function useRiPoLines(search: string, enabled: boolean) {
  return useQuery({
    queryKey: ['ri', 'po-lines', search],
    enabled,
    queryFn: async () => (await api.get<{ data: RiPoLine[] }>('/api/ri/po-lines', { params: { search: search || undefined } })).data.data,
  })
}
