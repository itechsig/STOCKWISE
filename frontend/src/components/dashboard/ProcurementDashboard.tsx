import { apiErrorMessage } from '@/lib/api'
import { useInventoryDashboard, useOperationsDashboard } from '@/features/dashboard/api'
import { statusLabel } from '@/components/ui/request-badge'
import { ChartCard } from './ChartCard'
import { PpbAging, PpbOldestCard, PurchaseFlow } from './OperationsDashboard'
import { StatusBreakdown, type StateTone, type StatusRow } from './DashboardParts'

/**
 * Pengadaan in one place: where the PPB lines and the POs stand (Accurate), how
 * many lines flow PPB → PO → RI each month, and which PPB lines are still
 * waiting for their goods. Statuses are listed in workflow order with fixed
 * meaning colors (blue = berjalan, amber = sebagian, green = selesai, gray = ditutup).
 */

/** PPB per-line status (Excel "1. PPB - RI"), in workflow order. */
const PPB_ROWS: { key: string; label: string; hint: string; tone: StateTone }[] = [
  { key: 'REQUESTED', label: 'Requested', hint: 'Diminta, belum ada barang diterima', tone: 'running' },
  { key: 'SHORTAGE', label: 'Shortage', hint: 'Baru diterima sebagian, sisanya menyusul', tone: 'partial' },
  { key: 'COMPLETED', label: 'Completed', hint: 'Barang sudah diterima lengkap', tone: 'done' },
  { key: 'CLOSE', label: 'Close', hint: 'Ditutup di Accurate, sisanya tidak dikirim', tone: 'closed' },
  { key: 'AMEND', label: 'Amend', hint: 'Pembelian berubah / tidak jadi dibeli', tone: 'closed' },
  { key: 'ERROR', label: 'Perlu dicek', hint: 'Status tidak sesuai data Accurate', tone: 'attention' },
]

/** Purchase Order status (Accurate POs), in workflow order. */
const PO_ROWS: { key: string; hint: string; tone: StateTone }[] = [
  { key: 'SENT', hint: 'Sudah ke vendor, belum ada barang diterima', tone: 'running' },
  { key: 'DIBELI', hint: 'PO STOCKWISE dengan bukti pembelian, menunggu RI', tone: 'running' },
  { key: 'PARTIAL_RECEIVED', hint: 'Sebagian barang sudah diterima', tone: 'partial' },
  { key: 'RECEIVED', hint: 'Semua barang sudah diterima', tone: 'done' },
  { key: 'CLOSED', hint: 'Ditutup di Accurate', tone: 'closed' },
  { key: 'CANCELLED', hint: 'Dibatalkan', tone: 'closed' },
]

/** Known statuses in their fixed order; anything unexpected is still shown (gray), never dropped. */
function toRows(data: { name: string; value: number }[] | undefined, known: StatusRow[]): StatusRow[] {
  const counts = new Map((data ?? []).map((d) => [String(d.name).toUpperCase(), Number(d.value)]))
  const rows = known.filter((k) => counts.has(k.key) || ['done', 'running'].includes(k.tone)).map((k) => ({ ...k, value: counts.get(k.key) ?? 0 }))
  for (const [name, value] of counts) {
    if (!known.some((k) => k.key === name)) rows.push({ key: name, label: statusLabel(name), value, tone: 'closed' })
  }
  return rows
}

export function ProcurementDashboard({ poStatus }: { poStatus?: { name: string; value: number }[] }) {
  const ops = useOperationsDashboard()
  // PPB line status comes with the stock analysis (same cached request as the Kondisi Stok section when it has no filter)
  const inv = useInventoryDashboard({})

  const ppbRows = toRows(inv.data?.charts.ppb_status, PPB_ROWS.map((r) => ({ ...r, value: 0 })))
  const poRows = toRows(poStatus, PO_ROWS.map((r) => ({ ...r, label: statusLabel(r.key), value: 0 })))

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <ChartCard title="Status Baris PPB" subtitle="Setiap baris barang PPB di Accurate, urut sesuai alur pengadaan"
          empty={!inv.isLoading && ppbRows.every((r) => r.value === 0)}>
          {inv.isLoading ? <p className="text-sm text-muted-foreground">Memuat…</p> : <StatusBreakdown rows={ppbRows} unit="baris PPB" />}
        </ChartCard>
        <ChartCard title="Status Purchase Order" subtitle="Dokumen PO dari Accurate, urut sesuai alur pengadaan"
          empty={poRows.every((r) => r.value === 0)}>
          <StatusBreakdown rows={poRows} unit="dokumen PO" />
        </ChartCard>
      </div>

      {ops.isLoading && <p className="text-sm text-muted-foreground">Memuat alur pembelian…</p>}
      {ops.error && <p className="text-sm text-red-600">{apiErrorMessage(ops.error)}</p>}
      {ops.data && (
        <>
          <PurchaseFlow data={ops.data.purchase_flow} />
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <PpbAging data={ops.data.ppb_aging} />
            <PpbOldestCard ppb={ops.data.attention.ppb_oldest} />
          </div>
        </>
      )}
    </div>
  )
}
