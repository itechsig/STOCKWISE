import { Badge } from '@/components/ui/badge'

/**
 * Borrow & Lend = the Excel "3. Tracking Borrow & Lend": its status words.
 */

const LEND_STATUS: Record<string, { label: string; variant: 'default' | 'success' | 'warning' | 'danger' | 'neutral' }> = {
  RETURNED: { label: 'KEMBALI', variant: 'success' },
  OVERDUE: { label: 'DEADLINE', variant: 'danger' },
  ON_LOAN: { label: 'SEDANG DIPINJAM', variant: 'default' },
  PARTIAL_RETURN: { label: 'Kembali sebagian', variant: 'warning' },
  INCOMPLETE: { label: 'Belum lengkap', variant: 'neutral' },
}
const BORROW_STATUS: Record<string, { label: string; variant: 'default' | 'success' | 'warning' | 'neutral' }> = {
  RETURNED: { label: 'LUNAS', variant: 'success' },
  BORROWED: { label: 'SEDANG DIPINJAM', variant: 'default' },
  PARTIAL: { label: 'Kembali sebagian', variant: 'warning' },
  INCOMPLETE: { label: 'Belum lengkap', variant: 'neutral' },
}
export const LEND_STATUS_LABELS = Object.fromEntries(Object.entries(LEND_STATUS).map(([k, v]) => [k, v.label]))
export const BORROW_STATUS_LABELS = Object.fromEntries(Object.entries(BORROW_STATUS).map(([k, v]) => [k, v.label]))

export function LoanStatusBadge({ kind, status }: { kind: 'lend' | 'borrow'; status: string }) {
  const s = (kind === 'lend' ? LEND_STATUS : BORROW_STATUS)[status] ?? { label: status, variant: 'neutral' as const }
  return <Badge variant={s.variant}>{s.label}</Badge>
}
