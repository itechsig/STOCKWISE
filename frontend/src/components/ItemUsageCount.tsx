import { Link } from 'react-router-dom'
import type { UsagePeriod } from '@/features/opname/api'

/**
 * How many NPBG / PPB lines an item has (stock opname "NPBG" / "PPB" columns).
 * Clicking opens that list filtered on the item code and the same period; it
 * doesn't open the row it sits in.
 */
export function ItemUsageCount({ kind, code, count, period }: { kind: 'npbg' | 'ppb'; code: string | null; count: number | undefined; period?: UsagePeriod }) {
  const n = count ?? 0
  if (!code || n === 0) return <span className="tabular-nums text-muted-foreground">0</span>
  const q = new URLSearchParams({ search: code })
  if (period?.from) q.set('date_from', period.from)
  if (period?.to) q.set('date_to', period.to)
  return (
    <Link
      to={`/${kind}?${q.toString()}`}
      onClick={(e) => e.stopPropagation()}
      title={`Lihat ${n} baris ${kind.toUpperCase()} untuk ${code}`}
      className="tabular-nums font-medium text-primary hover:underline"
    >
      {n.toLocaleString('id-ID')}
    </Link>
  )
}
