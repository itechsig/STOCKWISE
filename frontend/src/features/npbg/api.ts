import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '@/lib/notify'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** NPBG — mirror of Accurate ARINV (header) + ARINVDET (line), one row per line. */
export interface Npbg {
  id: number
  no_npbg: string | null
  tgl_npbg: string | null
  shipdate: string | null
  taxdate: string | null
  tipe_npbg: string | null
  /** Accurate "Klasifikasi [Ship Via]" (SHIPVIA → SHIPMENT): UMUM, MAINTENANCE …, STPP, PROYEK */
  klasifikasi: string | null
  kode_barang: string | null
  deskripsi_barang: string | null
  deskripsi: string | null
  kuantitas: number | null
  satuan: string | null
  /** from the Accurate Department of the line (format since Sept 2026), else ITEMRESERVED1 */
  peminta: string | null
  divisi: string | null
  lokasi_gudang?: string | null
  lokasi_rak?: string | null
  pelanggan: string | null
  nama_proyek: string | null
  /** Accurate "No. Seri [SNLine]" */
  no_seri: string | null
  dikeluarkan_oleh: string | null
  keterangan: string | null
  alamat_kirim: string | null
  /** true untuk NPBG yang dibuat manual di Stockwise (bukan Sync Accurate). */
  is_manual: boolean
  accurate_synced_at: string | null
  created_at: string
  updated_at: string
  verifications_count?: number
  latest_verification_status?: string | null
  /** "Kembali Bekas" ticked = the line is in Tracking Pengembalian Bekas (sheet "Spare Part Lain") */
  kembali_bekas: boolean
  /** status of that tracking row: PENDING / CLEAR / …, null = not ticked */
  kembali_bekas_status: string | null
  /** manual NPBG (Tambah NPBG): approval Manager → Direktur before the goods leave the stock (detail only) */
  /** sent back: admin revises it and sends it again */
  can_resubmit?: boolean
  approval?: { status: 'MENUNGGU' | 'MENUNGGU_DIREKTUR' | 'DISETUJUI' | 'DITOLAK' | 'DIKEMBALIKAN'; step: 'MANAGER' | 'DIREKTUR' | 'ADMIN' | null; at: string | null; by: string | null; note: string | null }
  approval_log?: { id: number; action: string; note: string | null; user_name: string | null; created_at: string | null }[]
  can_decide?: boolean
}

/** The Manager / Direktur step of a manual NPBG. */
/** Admin revised a manual NPBG sent back: to the approval again. */
export function useResubmitNpbg(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { quantities?: Record<number, number>; note?: string | null }) => (await api.post<{ data: Npbg }>(`/api/npbg/${id}/resubmit`, body)).data.data,
    meta: { successMessage: 'NPBG diajukan ulang ke approval.' },
    onSuccess: () => Promise.all([qc.invalidateQueries({ queryKey: ['npbg'] }), qc.invalidateQueries({ queryKey: ['item-requests', 'approvals'] })]),
  })
}

export function useDecideNpbg(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { action: 'APPROVE' | 'REJECT' | 'RETURN'; note?: string | null; quantities?: Record<number, number> }) => (await api.post<{ data: Npbg }>(`/api/npbg/${id}/approval`, body)).data.data,
    meta: { successMessage: false },
    onSuccess: (d, v) => {
      toast.success(v.action === 'RETURN' ? 'NPBG dikembalikan ke admin untuk revisi.' : v.action === 'REJECT' ? 'NPBG ditolak.' : d.approval?.status === 'DISETUJUI' ? 'NPBG disetujui — barang dikeluarkan dari stok.' : 'Disetujui Manager — menunggu Direktur.')
      return Promise.all([qc.invalidateQueries({ queryKey: ['npbg'] }), qc.invalidateQueries({ queryKey: ['item-requests', 'approvals'] })])
    },
  })
}

/** Tick / untick "Kembali Bekas" on one NPBG line (PUT /api/npbg/{id}/kembali-bekas). */
export function useSetKembaliBekas() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, checked }: { id: number; checked: boolean }) =>
      (await api.put<{ data: { kembali_bekas: boolean; kembali_bekas_status: string | null } }>(`/api/npbg/${id}/kembali-bekas`, { checked })).data.data,
    meta: { successMessage: false },
    // returned promise keeps the mutation pending until the list has the new tick (no flicker back)
    onSuccess: async (_d, { checked }) => {
      toast.success(checked ? 'Masuk ke Tracking Pengembalian Bekas.' : 'Dikeluarkan dari Tracking Pengembalian Bekas.')
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['npbg'] }),
        qc.invalidateQueries({ queryKey: ['used-return-sheets/other'] }),
      ])
    },
  })
}

/** Only these fields have no Accurate source — the only ones the API accepts on update. */
export interface NpbgEditableFields {
  tipe_npbg?: string | null
  deskripsi?: string | null
  nama_proyek?: string | null
  dikeluarkan_oleh?: string | null
}

/** "GUDANG 1 · RAK B.30" — Accurate ITEMRESERVED1/2 in the format since Sept 2026 */
export function lokasiText(r: { lokasi_gudang?: string | null; lokasi_rak?: string | null }): string | null {
  return [r.lokasi_gudang, r.lokasi_rak].filter(Boolean).join(' · ') || null
}

/** Klasifikasi values in use, for the filter */
export function useNpbgKlasifikasi() {
  return useQuery({
    queryKey: ['npbg', 'klasifikasi-options'],
    queryFn: async () => (await api.get<{ data: string[] }>('/api/npbg/klasifikasi')).data.data,
    staleTime: 10 * 60_000,
  })
}

export function useNpbgList(params: {
  search?: string
  tipe_npbg?: string
  klasifikasi?: string
  divisi?: string
  pelanggan?: string
  /** '1' = only lines ticked "Kembali Bekas", '0' = only not ticked */
  kembali_bekas?: string
  date_from?: string
  date_to?: string
  page?: number
}) {
  return useQuery({
    queryKey: ['npbg', params],
    queryFn: async () => (await api.get<Paginated<Npbg>>('/api/npbg', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useNpbg(id: number | null) {
  return useQuery({
    queryKey: ['npbg', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Npbg }>(`/api/npbg/${id}`)).data.data,
  })
}

export function useUpdateNpbg(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: NpbgEditableFields) => (await api.patch<{ data: Npbg }>(`/api/npbg/${id}`, body)).data.data,
    meta: { successMessage: 'NPBG dibuat — menunggu approval Manager, lalu Direktur.' },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['npbg'] }),
  })
}

/** Payload untuk membuat NPBG manual (goods-out yang tidak lewat Accurate). */
export interface NpbgCreatePayload {
  /** confirmed: not the same as a recent look-alike (App\Support\DuplicateGuard) */
  allow_duplicate?: boolean
  pelanggan: string
  alamat_kirim?: string | null
  divisi?: string | null
  tipe_npbg: 'Penjualan' | 'Non Penjualan'
  klasifikasi?: string | null
  kode_barang?: string | null
  deskripsi_barang: string
  kuantitas: number
  satuan?: string | null
  no_seri?: string | null
  peminta?: string | null
  deskripsi?: string | null
  keterangan?: string | null
  tgl_npbg?: string | null
  /** ticked "Kembali bekas": the new line goes straight into Tracking Pengembalian Bekas */
  kembali_bekas?: boolean
}

export function useCreateNpbg() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: NpbgCreatePayload) => (await api.post<{ data: Npbg }>('/api/npbg', payload)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['npbg'] }),
  })
}
