import { useState } from 'react'
import { BarChart3 } from 'lucide-react'
import { useIrAnalytics } from '@/features/importantRequests/api'
import { PageHeader } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Select } from '@/components/ui/select'

const MONTH = (ym: string) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('id-ID', { month: 'short', year: 'numeric' })

function Kpi({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4" title={hint}>
      <div className="text-2xl font-semibold tabular-nums">{value}</div>
      <div className="text-xs text-muted-foreground">{label}</div>
    </Card>
  )
}

/** Tracking & Analytic · Important Request: how requests are being handled (from the real data). */
export function IrAnalyticsPage() {
  const [months, setMonths] = useState(6)
  const { data: a, isLoading, isError } = useIrAnalytics(months)
  return (
    <div className="space-y-4">
      <PageHeader title="Analitik Important Request" icon={<BarChart3 className="size-5" />} subtitle="Penyelesaian, keterlambatan, beban tugas, dan tren request." />
      <div className="w-44">
        <Select aria-label="Periode" value={months} onChange={(e) => setMonths(Number(e.target.value))}>
          {[3, 6, 12].map((m) => <option key={m} value={m}>{m} bulan terakhir</option>)}
        </Select>
      </div>
      {isError && <p className="text-sm text-destructive">Gagal memuat analitik.</p>}
      {isLoading || !a ? <p className="py-10 text-center text-sm text-muted-foreground">Memuat…</p> : (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <Kpi label="Request terkirim" value={a.total.toLocaleString('id-ID')} />
            <Kpi label="Tingkat penyelesaian" value={a.completion_rate == null ? '-' : `${a.completion_rate}%`} hint={a.definitions.completion_rate} />
            <Kpi label="Rata-rata waktu selesai" value={a.avg_hours == null ? '-' : a.avg_hours >= 24 ? `${(a.avg_hours / 24).toFixed(1)} hari` : `${a.avg_hours} jam`} hint={a.definitions.avg_hours} />
            <Kpi label="Terlambat (belum selesai)" value={a.overdue_open.toLocaleString('id-ID')} hint={a.definitions.overdue_open} />
            <Kpi label="Selesai lewat deadline" value={a.late_done.toLocaleString('id-ID')} hint={a.definitions.late_done} />
            <Kpi label="Pernah diminta revisi" value={a.revised.toLocaleString('id-ID')} hint={a.definitions.revised} />
          </div>

          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="overflow-hidden">
              <div className="border-b px-4 py-3 text-sm font-semibold">Request per divisi penerima</div>
              <table className="w-full text-sm"><tbody className="divide-y">
                {a.by_divisi.length === 0 && <tr><td className="px-4 py-6 text-center text-muted-foreground">Belum ada data.</td></tr>}
                {a.by_divisi.map((d) => <tr key={d.divisi}><td className="px-4 py-2">{d.divisi}</td><td className="px-4 py-2 text-right tabular-nums">{d.n}</td></tr>)}
              </tbody></table>
            </Card>
            <Card className="overflow-hidden">
              <div className="border-b px-4 py-3 text-sm font-semibold">Beban tugas aktif per orang</div>
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-2 font-medium">Nama</th><th className="px-4 py-2 text-right font-medium">Aktif</th><th className="px-4 py-2 text-right font-medium">Terlambat</th></tr></thead>
                <tbody className="divide-y">
                  {a.workload.length === 0 && <tr><td colSpan={3} className="px-4 py-6 text-center text-muted-foreground">Tidak ada tugas aktif.</td></tr>}
                  {a.workload.map((w) => <tr key={w.name}><td className="px-4 py-2">{w.name}</td><td className="px-4 py-2 text-right tabular-nums">{w.open}</td><td className={`px-4 py-2 text-right tabular-nums ${Number(w.overdue) > 0 ? 'font-semibold text-red-600' : ''}`}>{w.overdue}</td></tr>)}
                </tbody>
              </table>
            </Card>
            <Card className="overflow-hidden">
              <div className="border-b px-4 py-3 text-sm font-semibold">Tren per bulan</div>
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-2 font-medium">Bulan</th><th className="px-4 py-2 text-right font-medium">Dibuat</th><th className="px-4 py-2 text-right font-medium">Selesai</th></tr></thead>
                <tbody className="divide-y">{a.trend.map((t) => <tr key={t.month}><td className="px-4 py-2">{MONTH(t.month)}</td><td className="px-4 py-2 text-right tabular-nums">{t.dibuat}</td><td className="px-4 py-2 text-right tabular-nums">{t.selesai}</td></tr>)}</tbody>
              </table>
            </Card>
          </div>
          <p className="text-xs text-muted-foreground">Definisi: {Object.values(a.definitions).join(' · ')}</p>
        </>
      )}
    </div>
  )
}
