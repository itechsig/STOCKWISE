import { Badge } from '@/components/ui/badge'
import type { Opname } from './api'

type Variant = 'default' | 'neutral' | 'success' | 'warning' | 'danger'

/** Internal (STOCKWISE) opname flow, in order. */
export const OPNAME_STEPS = [
  { status: 'SCHEDULED', label: 'Terjadwal' },
  { status: 'IN_PROGRESS', label: 'Dihitung' },
  { status: 'PENDING_REVIEW', label: 'Direview' },
  { status: 'COMPLETED', label: 'Selesai' },
] as const

const INTERNAL: Record<string, { label: string; variant: Variant; hint: string }> = {
  SCHEDULED: { label: 'Terjadwal', variant: 'neutral', hint: 'Belum mulai dihitung' },
  IN_PROGRESS: { label: 'Sedang Dihitung', variant: 'default', hint: 'Petugas sedang mengisi jumlah fisik' },
  PENDING_REVIEW: { label: 'Menunggu Review', variant: 'warning', hint: 'Hasil hitung menunggu keputusan admin gudang' },
  RECOUNT_REQUIRED: { label: 'Perlu Hitung Ulang', variant: 'danger', hint: 'Sebagian barang harus dihitung ulang' },
  COMPLETED: { label: 'Selesai', variant: 'success', hint: 'Sudah direview; stok disesuaikan' },
}

// Accurate ITEMADJ: ADJCHECK = 1 only on the 2.111 opening-balance documents (all dated
// 31/12/2024); every day-to-day adjustment has 0. It is NOT a "checked" flag — verified
// against live data 2026-09-29. Both kinds are finished documents in Accurate.
const ACCURATE: Record<string, { label: string; variant: Variant; hint: string }> = {
  PENDING_REVIEW: { label: 'Penyesuaian stok', variant: 'default', hint: 'Penyesuaian persediaan di Accurate — sudah tercatat' },
  COMPLETED: { label: 'Saldo awal', variant: 'neutral', hint: 'Saldo awal persediaan saat Accurate mulai dipakai (31/12/2024)' },
}

export const isFromAccurate = (o: Pick<Opname, 'accurate_itemadj_id'>) => o.accurate_itemadj_id != null

export function opnameStatus(o: Pick<Opname, 'status' | 'accurate_itemadj_id'>) {
  const map = isFromAccurate(o) ? ACCURATE : INTERNAL
  return map[o.status] ?? INTERNAL[o.status] ?? { label: o.status, variant: 'neutral' as Variant, hint: '' }
}

export function OpnameStatusBadge({ opname }: { opname: Pick<Opname, 'status' | 'accurate_itemadj_id'> }) {
  const s = opnameStatus(opname)
  return <Badge variant={s.variant} title={s.hint}>{s.label}</Badge>
}

/** Status filter options (internal flow). */
export const STATUS_FILTERS = Object.entries(INTERNAL).map(([value, s]) => ({ value, label: s.label }))

export const REVIEW_LABELS: Record<string, { label: string; variant: Variant }> = {
  PENDING: { label: 'Belum direview', variant: 'neutral' },
  APPROVED: { label: 'Disetujui', variant: 'success' },
  REJECTED: { label: 'Ditolak', variant: 'danger' },
  RECOUNT: { label: 'Hitung ulang', variant: 'warning' },
}

export function typeLabel(type: string) {
  return type === 'FULL' ? 'Semua barang' : 'Barang pilihan'
}
