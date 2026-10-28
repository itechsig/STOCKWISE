import { Badge } from './badge'

const MAP: Record<string, { label: string; variant: 'default' | 'neutral' | 'success' | 'warning' | 'danger' }> = {
  DRAFT: { label: 'Draft', variant: 'neutral' },
  SUBMITTED: { label: 'Dikirim', variant: 'default' },
  UNDER_REVIEW: { label: 'Direview', variant: 'default' },
  READY: { label: 'Siap', variant: 'success' },
  PARTIAL: { label: 'Sebagian', variant: 'warning' },
  NEED_PURCHASE: { label: 'Perlu Pembelian', variant: 'warning' },
  RESERVED: { label: 'Direservasi', variant: 'success' },
  PREPARING: { label: 'Disiapkan', variant: 'default' },
  READY_TO_PICKUP: { label: 'Siap Diambil', variant: 'success' },
  PICKED_UP: { label: 'Diambil', variant: 'success' },
  COMPLETED: { label: 'Selesai', variant: 'success' },
  CANCELLED: { label: 'Dibatalkan', variant: 'danger' },
  // line statuses
  PENDING: { label: 'Menunggu', variant: 'neutral' },
  // PO table entry made by a PPB approval, until Accurate's real PO is synced
  MENUNGGU_PO: { label: 'Menunggu PO', variant: 'warning' },
  ISSUED: { label: 'Keluar', variant: 'success' },
  // ppb / po / receiving
  REVIEW: { label: 'Direview', variant: 'default' },
  APPROVED: { label: 'Disetujui', variant: 'success' },
  REJECTED: { label: 'Ditolak', variant: 'danger' },
  PURCHASING: { label: 'Pembelian', variant: 'default' },
  ORDERED: { label: 'Dipesan', variant: 'default' },
  SENT: { label: 'Dikirim ke Vendor', variant: 'default' },
  // STOCKWISE PO: approved and its proof of purchase uploaded
  DIBELI: { label: 'Sudah dibeli', variant: 'success' },
  PARTIAL_RECEIVED: { label: 'Diterima Sebagian', variant: 'warning' },
  RECEIVED: { label: 'Diterima', variant: 'success' },
  CHECKING: { label: 'Pemeriksaan', variant: 'default' },
  CONFIRMED: { label: 'Dikonfirmasi', variant: 'success' },
  CLOSED: { label: 'Ditutup', variant: 'neutral' },
  // lend / borrow
  ON_LOAN: { label: 'Dipinjamkan', variant: 'default' },
  PARTIAL_RETURN: { label: 'Kembali sebagian', variant: 'warning' },
  RETURNED: { label: 'Sudah kembali', variant: 'success' },
  OVERDUE: { label: 'Lewat jatuh tempo', variant: 'danger' },
  BORROWED: { label: 'Dipinjam', variant: 'default' },
  // ban luar / maintenance / manufaktur
  PENDING_RI: { label: 'Menunggu ban bekas kembali', variant: 'warning' },
  CLEAR: { label: 'Clear', variant: 'success' },
  OPEN: { label: 'Baru', variant: 'neutral' },
  ON_GOING: { label: 'Dikerjakan', variant: 'default' },
  REQUESTED: { label: 'Diajukan', variant: 'neutral' },
}

/** Plain-language label of a status code (for filters/dropdowns). */
export function statusLabel(status: string): string {
  return MAP[status]?.label ?? status
}

export function RequestStatusBadge({ status }: { status: string }) {
  const s = MAP[status] ?? { label: status, variant: 'neutral' as const }
  return <Badge variant={s.variant}>{s.label}</Badge>
}
