import { useEffect, useRef, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { BookOpenCheck, ChevronRight, ClipboardList, CloudCog, FileText, LayoutDashboard, Loader2, Printer, ShoppingCart, Truck, Warehouse } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { APPROVAL_PERMISSIONS } from '@/features/itemRequests/api'
import { useDashboard } from '@/features/dashboard/api'
import { useSyncStatus } from '@/features/sync/api'
import { PageHeader } from '@/components/PageHeader'
import { InventoryDashboard } from '@/components/dashboard/InventoryDashboard'
import { TrackingDashboard } from '@/components/dashboard/OperationsDashboard'
import { ProcurementDashboard } from '@/components/dashboard/ProcurementDashboard'
import { StockCardReport } from '@/components/dashboard/StockCardReport'
import { KpiGroup, SectionHeading, type KpiItem } from '@/components/dashboard/DashboardParts'
import { exportElementToPdf } from '@/lib/exportDashboardPdf'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

const SYNC_LABEL: Record<string, string> = { SUCCESS: 'Berhasil', FAILED: 'Gagal', PARTIAL: 'Sebagian', RUNNING: 'Berjalan' }

function AccurateSyncWidget() {
  const { hasPermission } = useAuth()
  const canView = hasPermission('sync.accurate.view')
  const { data: sync } = useSyncStatus(canView)
  if (!canView) return null

  const badgeVariant =
    sync?.status === 'SUCCESS' ? 'success' : sync?.status === 'FAILED' ? 'danger' : sync?.status === 'PARTIAL' ? 'warning' : 'neutral'

  return (
    <Link to="/sync/history" className="card-surface flex items-center justify-between gap-3 px-4 py-3 transition hover:border-primary/40">
      <div className="flex min-w-0 items-center gap-3">
        <CloudCog className="size-5 shrink-0 text-muted-foreground" />
        <div className="min-w-0 text-sm">
          <span className="font-medium">Data Accurate terakhir disinkronkan</span>
          <span className="text-muted-foreground"> · {sync ? new Date(sync.started_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : 'belum pernah'}</span>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-1">
        <Badge variant={badgeVariant}>{sync ? SYNC_LABEL[sync.status] ?? sync.status : '—'}</Badge>
        <ChevronRight className="size-4 text-muted-foreground" />
      </div>
    </Link>
  )
}

/** Headline numbers grouped by business area (the stock counts live in the Kondisi Stok section itself). */
const KPI_GROUP: Record<string, 'gudang' | 'pengadaan' | 'tracking'> = {
  opname_active: 'gudang',
  ppb_accurate_open: 'pengadaan', po_open: 'pengadaan', ri_accurate_month: 'pengadaan',
  lend_open: 'tracking', lend_overdue: 'tracking', borrow_open: 'tracking', spk_open: 'tracking',
}
const KPI_ORDER = ['opname_active', 'ppb_accurate_open', 'po_open', 'ri_accurate_month', 'lend_open', 'lend_overdue', 'borrow_open', 'spk_open']
/** Clearer wording than the API's short labels. */
const KPI_LABEL: Record<string, string> = {
  ppb_accurate_open: 'Baris PPB menunggu barang',
  po_open: 'PO berjalan',
  ri_accurate_month: 'RI bulan ini',
  lend_open: 'Barang dipinjamkan',
  lend_overdue: 'Pinjaman lewat tempo',
  borrow_open: 'Pinjam dari luar',
  spk_open: 'SPK maintenance berjalan',
}
const KPI_LINK: Record<string, string> = {
  opname_active: '/stock-opnames',
  ppb_accurate_open: '/ppb',
  po_open: '/purchase-orders',
  ri_accurate_month: '/ri',
  lend_open: '/lend',
  lend_overdue: '/lend',
  borrow_open: '/borrow',
  spk_open: '/maintenance',
}

const SECTIONS = [
  { id: 'stok', label: 'Kondisi Stok' },
  { id: 'pengadaan', label: 'Pengadaan' },
  { id: 'tracking', label: 'Tracking' },
  { id: 'kartu-stock', label: 'Kartu Stock' },
] as const

/** Jump links to the sections, highlighting the one in view; sticks under the app header. */
function SectionNav({ ids }: { ids: readonly { id: string; label: string }[] }) {
  const [active, setActive] = useState(ids[0]?.id)
  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return // old browsers / tests: links still work, no highlight
    const obs = new IntersectionObserver(
      (entries) => {
        const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
        if (visible[0]) setActive(visible[0].target.id)
      },
      { rootMargin: '-120px 0px -60% 0px' },
    )
    ids.forEach(({ id }) => { const el = document.getElementById(id); if (el) obs.observe(el) })
    return () => obs.disconnect()
  }, [ids])

  return (
    <nav className="sticky top-14 z-20 -mx-3 border-b bg-background/95 px-3 py-2 backdrop-blur sm:-mx-6 sm:px-6 print:hidden" aria-label="Bagian dashboard">
      <div className="flex gap-1 overflow-x-auto">
        {ids.map(({ id, label }) => (
          <a key={id} href={`#${id}`}
            onClick={(e) => { e.preventDefault(); document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }}
            className={cn('whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition',
              active === id ? 'bg-card text-primary shadow-sm ring-1 ring-border' : 'text-muted-foreground hover:bg-card hover:text-foreground')}>
            {label}
          </a>
        ))}
      </div>
    </nav>
  )
}

export function DashboardPage() {
  const { user, hasPermission } = useAuth()
  const { data } = useDashboard()
  const exportRootRef = useRef<HTMLDivElement>(null)
  const [exporting, setExporting] = useState(false)

  async function handleExportPdf() {
    if (!exportRootRef.current || exporting) return
    setExporting(true)
    try {
      await exportElementToPdf(exportRootRef.current, {
        title: 'Dashboard STOCKWISE',
        subtitle: 'PT Surya Inti Gas',
        filename: `Dashboard-STOCKWISE-PT-Surya-Inti-Gas-${new Date().toISOString().slice(0, 10)}.pdf`,
      })
    } finally {
      setExporting(false)
    }
  }

  if (!user) return null
  // Manager / Direktur without any dashboard: their home is the Approval page
  const dashboards = ['dashboard.karyawan', 'dashboard.gudang', 'dashboard.opname', 'dashboard.lapangan', 'dashboard.purchasing', 'dashboard.executive']
  if (!dashboards.some((p) => hasPermission(p)) && APPROVAL_PERMISSIONS.some((p) => hasPermission(p))) return <Navigate to="/approvals" replace />

  const showStockCard = hasPermission('item.view') || hasPermission('inventory.view')
  const sections = SECTIONS.filter((s) => s.id !== 'kartu-stock' || showStockCard)

  const kpis: Record<'gudang' | 'pengadaan' | 'tracking', KpiItem[]> = { gudang: [], pengadaan: [], tracking: [] }
  for (const c of [...(data?.cards ?? [])].sort((a, b) => KPI_ORDER.indexOf(a.key) - KPI_ORDER.indexOf(b.key))) {
    const group = KPI_GROUP[c.key]
    if (group) kpis[group].push({ key: c.key, label: KPI_LABEL[c.key] ?? c.label, value: c.value, tone: c.tone, to: KPI_LINK[c.key] })
  }
  const groupCount = [kpis.gudang, kpis.pengadaan, kpis.tracking].filter((g) => g.length > 0).length

  return (
    <div className="space-y-5">
      <PageHeader
        title={`Selamat datang, ${user.name.split(' ')[0]}`}
        subtitle={`${user.roles.join(', ')}${user.site ? ` · ${user.site.name}` : ''}`}
        icon={<LayoutDashboard className="size-5" />}
        actions={
          <div className="flex gap-2 print:hidden">
            <Button variant="outline" size="sm" onClick={handleExportPdf} disabled={exporting}>
              {exporting ? <Loader2 className="size-4 animate-spin" /> : <FileText className="size-4" />}
              {exporting ? 'Membuat PDF…' : 'Download PDF'}
            </Button>
            <Button variant="outline" size="sm" onClick={() => window.print()}>
              <Printer className="size-4" /> Cetak
            </Button>
          </div>
        }
      />

      <SectionNav ids={sections} />

      <div ref={exportRootRef} className="space-y-8">
        <div className="space-y-3">
          <AccurateSyncWidget />
          {groupCount > 0 && (
            <div className={cn('grid gap-3', groupCount === 3 && 'xl:grid-cols-[1fr_3fr_4fr]', groupCount === 2 && 'xl:grid-cols-2')}>
              <KpiGroup title="Gudang" icon={<Warehouse className="size-3.5" />} items={kpis.gudang} />
              <KpiGroup title="Pengadaan" icon={<ShoppingCart className="size-3.5" />} items={kpis.pengadaan} />
              <KpiGroup title="Tracking" icon={<Truck className="size-3.5" />} items={kpis.tracking} />
            </div>
          )}
        </div>

        <section className="space-y-4">
          <SectionHeading id="stok" icon={<Warehouse className="size-4" />} title="Kondisi Stok"
            description="Kesehatan stok terhadap safety stock, barang yang perlu dibeli, dan pemakaian barang (NPBG)."
            link={hasPermission('inventory.view_analysis') ? { to: '/inventory/analysis', label: 'Analisis & Safety Stock' } : undefined} />
          <InventoryDashboard />
        </section>

        <section className="space-y-4">
          <SectionHeading id="pengadaan" icon={<ShoppingCart className="size-4" />} title="Pengadaan"
            description="Posisi PPB dan Purchase Order dari Accurate, alur PPB → PO → RI per bulan, dan PPB yang belum lengkap."
            link={hasPermission('ppb.view') ? { to: '/ppb', label: 'Buka PPB' } : undefined} />
          <ProcurementDashboard poStatus={data?.charts.po_status} />
        </section>

        <section className="space-y-4">
          <SectionHeading id="tracking" icon={<ClipboardList className="size-4" />} title="Tracking"
            description="Status semua modul tracking, pinjaman yang lewat tempo, maintenance kendaraan, dan penggantian ban." />
          <TrackingDashboard />
        </section>

        {showStockCard && (
          <section className="space-y-4">
            <SectionHeading id="kartu-stock" icon={<BookOpenCheck className="size-4" />} title="Kartu Stock"
              description="Barang masuk dan keluar per hari, minggu, atau bulan."
              link={{ to: '/stock-card', label: 'Buka Kartu Stock' }} />
            <StockCardReport />
          </section>
        )}
      </div>
    </div>
  )
}
