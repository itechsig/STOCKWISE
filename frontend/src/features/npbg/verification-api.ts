import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

/** Klarifikasi/Verifikasi Barang — see backend App\Models\NpbgVerification for the status flow. */
export type VerificationStatus =
  | 'DIAJUKAN'
  | 'DIPROSES'
  | 'ALTERNATIF_DITAWARKAN'
  | 'MENUNGGU_RESPON'
  | 'PERLU_VERIFIKASI_BOS'
  | 'DISETUJUI'
  | 'DITOLAK'
  | 'SELESAI'

export interface VerificationLog {
  id: number
  actor: string | null
  action: string
  from_status: VerificationStatus | null
  to_status: VerificationStatus | null
  note: string | null
  has_attachment: boolean
  attachment_url: string | null
  created_at: string
}

export interface NpbgVerification {
  id: number
  npbg_id: number
  status: VerificationStatus
  alasan_pengajuan: string | null
  deskripsi_alternatif: string | null
  respon_maintenance: 'ACCEPT' | 'REJECT' | null
  alasan_penolakan: string | null
  keputusan_bos: 'DISETUJUI' | 'DITOLAK' | null
  catatan_bos: string | null
  opened_by: string | null
  responded_by: string | null
  decided_by: string | null
  escalated_at: string | null
  responded_at: string | null
  decided_at: string | null
  closed_at: string | null
  created_at: string
  logs?: VerificationLog[]
}

const OPEN_STATUSES: VerificationStatus[] = [
  'DIAJUKAN', 'DIPROSES', 'ALTERNATIF_DITAWARKAN', 'MENUNGGU_RESPON', 'PERLU_VERIFIKASI_BOS',
]
export function isVerificationOpen(status: VerificationStatus): boolean {
  return OPEN_STATUSES.includes(status)
}

export function useNpbgVerifications(npbgId: number) {
  return useQuery({
    queryKey: ['npbg', npbgId, 'verifications'],
    queryFn: async () => (await api.get<{ data: NpbgVerification[] }>(`/api/npbg/${npbgId}/verifications`)).data.data,
  })
}

function invalidate(qc: ReturnType<typeof useQueryClient>, npbgId: number) {
  qc.invalidateQueries({ queryKey: ['npbg', npbgId, 'verifications'] })
}

export function useOpenVerification(npbgId: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { alasan_pengajuan: string; attachment?: string }) =>
      (await api.post<{ data: NpbgVerification }>(`/api/npbg/${npbgId}/verifications`, body)).data.data,
    onSuccess: () => invalidate(qc, npbgId),
  })
}

export function useProcessVerification(npbgId: number, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { note?: string } = {}) =>
      (await api.post<{ data: NpbgVerification }>(`/api/npbg-verifications/${id}/process`, body)).data.data,
    onSuccess: () => invalidate(qc, npbgId),
  })
}

export function useOfferAlternative(npbgId: number, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { deskripsi_alternatif: string; note?: string; attachment?: string }) =>
      (await api.post<{ data: NpbgVerification }>(`/api/npbg-verifications/${id}/offer-alternative`, body)).data.data,
    onSuccess: () => invalidate(qc, npbgId),
  })
}

export function useRespondVerification(npbgId: number, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { decision: 'ACCEPT' | 'REJECT'; reason?: string; attachment?: string }) =>
      (await api.post<{ data: NpbgVerification }>(`/api/npbg-verifications/${id}/respond`, body)).data.data,
    onSuccess: () => invalidate(qc, npbgId),
  })
}

export function useEscalateVerification(npbgId: number, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { note?: string } = {}) =>
      (await api.post<{ data: NpbgVerification }>(`/api/npbg-verifications/${id}/escalate`, body)).data.data,
    onSuccess: () => invalidate(qc, npbgId),
  })
}

export function useBosDecide(npbgId: number, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { keputusan: 'DISETUJUI' | 'DITOLAK'; catatan?: string; attachment?: string }) =>
      (await api.post<{ data: NpbgVerification }>(`/api/npbg-verifications/${id}/bos-decide`, body)).data.data,
    onSuccess: () => invalidate(qc, npbgId),
  })
}

/** Reads a File as a `data:<mime>;base64,...` string for the attachment fields above. */
export function fileToDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result as string)
    reader.onerror = reject
    reader.readAsDataURL(file)
  })
}
