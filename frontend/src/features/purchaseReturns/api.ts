import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** Retur Pembelian: goods of an RI back to the vendor — DIBUAT → DIKIRIM → SELESAI (or DIBATALKAN). */
export type ReturnStatus = 'DIBUAT' | 'DIKIRIM' | 'SELESAI' | 'DIBATALKAN'
export type ReturnResolution = 'GANTI_BARANG' | 'POTONG_TAGIHAN'

export const RETURN_STATUS: Record<ReturnStatus, { label: string; variant: 'warning' | 'default' | 'success' | 'neutral' }> = {
  DIBUAT: { label: 'Belum dikirim', variant: 'warning' },
  DIKIRIM: { label: 'Dikirim ke vendor', variant: 'default' },
  SELESAI: { label: 'Selesai', variant: 'success' },
  DIBATALKAN: { label: 'Dibatalkan', variant: 'neutral' },
}

export const RESOLUTION: Record<ReturnResolution, { label: string; hint: string; refLabel: string }> = {
  GANTI_BARANG: { label: 'Ganti barang', hint: 'Vendor mengirim barang pengganti', refLabel: 'No RI barang pengganti' },
  POTONG_TAGIHAN: { label: 'Potong tagihan', hint: 'Dipotong dari tagihan / dikembalikan uangnya', refLabel: 'No nota kredit / bukti' },
}

/** Common reasons, one tap; anything else is typed. */
export const RETURN_REASONS = ['Rusak / cacat', 'Tidak sesuai spesifikasi', 'Salah barang', 'Kelebihan kirim']

export interface PurchaseReturn {
  id: number
  number: string
  tgl_retur: string
  no_ri: string
  no_po: string | null
  vendor: string | null
  status: ReturnStatus
  status_label: string
  resolution?: ReturnResolution
  resolution_label: string
  reason: string
  lines_count: number
  total_qty: number
  /** the returned items with their qty (list) */
  items?: { deskripsi_barang: string | null; qty: number; satuan: string | null }[]
  total_value: number | null
}

export interface PurchaseReturnLine {
  id: number
  ri_id: number | null
  kode_barang: string | null
  deskripsi_barang: string | null
  qty: number
  satuan: string | null
  harga_satuan: number | null
  ri_qty: number | null
}

export interface PurchaseReturnDetail extends PurchaseReturn {
  note: string | null
  lines: PurchaseReturnLine[]
  ri_id: number | null
  created_by_name: string | null
  created_at: string
  sent_at: string | null
  sent_by_name: string | null
  sent_note: string | null
  resolved_at: string | null
  resolved_by_name: string | null
  resolution_ref: string | null
  resolution_note: string | null
  cancelled_at: string | null
  photos: ('barang' | 'kirim')[]
}

export interface RiOption { no_ri: string; tgl_ri: string | null; vendor: string | null; no_po: string | null; line_count: number }

export interface RiReturnLine {
  ri_id: number
  kode_barang: string | null
  deskripsi_barang: string | null
  satuan: string | null
  harga_satuan: number
  received: number
  returned: number
  returnable: number
}

export interface RiForReturn { no_ri: string; tgl_ri: string | null; vendor: string | null; no_po: string | null; lines: RiReturnLine[] }

export function usePurchaseReturns(params: { search?: string; status?: string; page?: number }) {
  return useQuery({
    queryKey: ['purchase-returns', params],
    queryFn: async () => (await api.get<Paginated<PurchaseReturn>>('/api/purchase-returns', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function usePurchaseReturn(id: number) {
  return useQuery({
    queryKey: ['purchase-returns', id],
    queryFn: async () => (await api.get<{ data: PurchaseReturnDetail }>(`/api/purchase-returns/${id}`)).data.data,
  })
}

export function useRiSearch(q: string, enabled: boolean) {
  return useQuery({
    queryKey: ['purchase-returns', 'ri-search', q],
    enabled,
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get<{ data: RiOption[] }>('/api/purchase-returns/ri-search', { params: { q } })).data.data,
  })
}

export function useRiForReturn(noRi: string | null) {
  return useQuery({
    queryKey: ['purchase-returns', 'ri-lines', noRi],
    enabled: !!noRi,
    queryFn: async () => (await api.get<{ data: RiForReturn }>('/api/purchase-returns/ri-lines', { params: { no_ri: noRi } })).data.data,
  })
}

export interface CreateReturnPayload {
  no_ri: string
  tgl_retur?: string | null
  resolution?: ReturnResolution
  reason: string
  note?: string | null
  photo?: string | null
  lines: { ri_id: number; qty: number }[]
}

export function useCreatePurchaseReturn() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: CreateReturnPayload) => (await api.post<{ data: PurchaseReturnDetail }>('/api/purchase-returns', body)).data.data,
    meta: { successMessage: 'Retur dibuat.' },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-returns'] }),
  })
}

/** send / resolve / cancel — one step of the return. */
export function useReturnStep(id: number) {
  const qc = useQueryClient()
  const done = () => qc.invalidateQueries({ queryKey: ['purchase-returns'] })
  return {
    send: useMutation({
      mutationFn: async (body: { note?: string | null; photo?: string | null }) => (await api.post<{ data: PurchaseReturnDetail }>(`/api/purchase-returns/${id}/send`, body)).data.data,
      meta: { successMessage: 'Ditandai dikirim ke vendor.' },
      onSuccess: done,
    }),
    resolve: useMutation({
      mutationFn: async (body: { ref?: string | null; note?: string | null }) => (await api.post<{ data: PurchaseReturnDetail }>(`/api/purchase-returns/${id}/resolve`, body)).data.data,
      meta: { successMessage: 'Retur selesai.' },
      onSuccess: done,
    }),
    cancel: useMutation({
      mutationFn: async () => (await api.post<{ data: PurchaseReturnDetail }>(`/api/purchase-returns/${id}/cancel`)).data.data,
      meta: { successMessage: 'Retur dibatalkan.' },
      onSuccess: done,
    }),
  }
}

/** A return photo as an object URL (the API needs the bearer token). */
export function useReturnPhoto(id: number, slot: 'barang' | 'kirim', enabled: boolean) {
  return useQuery({
    queryKey: ['purchase-returns', id, 'photo', slot],
    enabled,
    staleTime: Infinity,
    queryFn: async () => URL.createObjectURL((await api.get(`/api/purchase-returns/${id}/photo/${slot}`, { responseType: 'blob' })).data as Blob),
  })
}
