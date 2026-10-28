import { useState } from 'react'
import { Archive, Download, FileSpreadsheet, FileText, Printer, Sheet } from 'lucide-react'
import { api, apiErrorMessage } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'

interface Dataset {
  key: string
  label: string
  description: string
  permission: string
}

const DATASETS: Dataset[] = [
  { key: 'inventory', label: 'Inventory', description: 'Stok aktual, reserved & tersedia per gudang', permission: 'report.inventory' },
  { key: 'inventory_analysis', label: 'Analisis Inventory', description: 'Status stok vs. safety stock & rekomendasi pembelian (real-time)', permission: 'inventory.view_analysis' },
  { key: 'npbg', label: 'NPBG', description: 'Mirror ARINV/ARINVDET dari Accurate', permission: 'report.npbg' },
  { key: 'ppb', label: 'PPB', description: 'Mirror REQUISITION/REQUISITIONDET dari Accurate', permission: 'report.ppb' },
  { key: 'purchase-orders', label: 'Purchase Order', description: 'Pesanan pembelian ke vendor (Accurate & internal)', permission: 'po.view' },
  { key: 'ri', label: 'RI', description: 'Mirror APINV/APITMDET dari Accurate', permission: 'report.ri' },
  { key: 'stock-opnames', label: 'Stock Opname', description: 'Riwayat opname & selisih', permission: 'report.opname' },
  { key: 'stock-movements', label: 'Pergerakan Stok', description: 'Kartu stok / ledger lengkap', permission: 'report.stock_movement' },
]

interface LegacyFile {
  key: string
  label: string
  filename: string
  description: string
  anyOf: string[]
}

/** 9 file Excel lama PT Surya Inti Gas — direplika sama persis (sheet/kolom/rumus), diisi data live. */
const LEGACY_FILES: LegacyFile[] = [
  { key: 'data', label: 'Master Barang & Safety Stock', filename: 'DATA.xlsx', description: 'DATABASE UTAMA + 12 sheet SAFETY STOCK (formula asli, ~1 menit untuk dibuat)', anyOf: ['report.inventory'] },
  { key: 'npbg', label: 'NPBG', filename: '2. NPBG.xlsx', description: 'Nota Pengeluaran Barang Gudang', anyOf: ['report.npbg'] },
  { key: 'borrow-lend', label: 'Tracking Borrow & Lend', filename: '3. Tracking Borrow & Lend.xlsx', description: 'Sheet Lend, Borrow', anyOf: ['lend.view', 'borrow.view'] },
  { key: 'stpp', label: 'Tracking STPP', filename: '4. Tracking STPP.xlsx', description: 'Serah Terima Pinjam Pakai', anyOf: ['stpp.view'] },
  { key: 'ban-luar', label: 'Tracking Ban Luar', filename: '5. Tracking Ban Luar.xlsx', description: 'Penggantian ban kendaraan', anyOf: ['tyre.view'] },
  { key: 'maintenance-assets', label: 'Tracking Maintenance Assets', filename: '6. Tracking Maintenance Assets.xlsx', description: 'SPK perbaikan aset/kendaraan', anyOf: ['maintenance.view'] },
  { key: 'manufaktur-assembly', label: 'Tracking Manufaktur & Assembly', filename: '7. Tracking Manufaktur & Assembly.xlsx', description: 'Sheet Manufaktur & Assembly, Manufaktur & Jasa', anyOf: ['manufacturing.view'] },
  { key: 'pengembalian-bekas', label: 'Tracking Pengembalian Bekas', filename: '8. Tracking Pengembalian Bekas.xlsx', description: 'Sheet Spare Part (matriks), Spare Part Lain', anyOf: ['used_return.view'] },
]

const FORMATS = [
  { key: 'xlsx', label: 'Excel', icon: FileSpreadsheet, perm: 'export.excel' },
  { key: 'csv', label: 'CSV', icon: Sheet, perm: 'export.csv' },
  { key: 'pdf', label: 'PDF', icon: FileText, perm: 'export.pdf' },
] as const

export function ReportsPage() {
  const { hasPermission } = useAuth()
  const [busy, setBusy] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)

  const datasets = DATASETS.filter((d) => hasPermission(d.permission))
  const legacyFiles = LEGACY_FILES.filter((f) => hasPermission('export.excel') && f.anyOf.some((p) => hasPermission(p)))

  async function downloadLegacy(file: LegacyFile) {
    const id = `legacy:${file.key}`
    setBusy(id)
    setErr(null)
    try {
      const res = await api.get(`/api/legacy-export/${file.key}`, { responseType: 'blob' })
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = file.filename
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  async function download(dataset: string, format: string) {
    setBusy(`${dataset}:${format}`)
    setErr(null)
    try {
      const res = await api.get(`/api/export/${dataset}`, { params: { format }, responseType: 'blob' })
      const disposition = String(res.headers['content-disposition'] ?? '')
      const match = disposition.match(/filename="?([^"]+)"?/)
      const name = match?.[1] ?? `${dataset}.${format === 'pdf' ? 'html' : format}`
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  async function print(dataset: string) {
    const id = `${dataset}:print`
    setBusy(id)
    setErr(null)
    const w = window.open('', '_blank')
    try {
      const res = await api.get(`/api/export/${dataset}`, { params: { format: 'pdf' }, responseType: 'text' })
      if (w) {
        w.document.write(res.data as string)
        w.document.close()
      }
    } catch (e) {
      w?.close()
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Laporan & Export"
        subtitle="Unduh data STOCKWISE dalam format Excel, CSV, atau PDF"
        icon={<Download className="size-5" />}
      />

      {err && <p className="text-sm text-destructive">{err}</p>}
      <p className="text-xs text-muted-foreground">
        Format PDF diunduh sebagai halaman cetak — buka file lalu gunakan “Simpan sebagai PDF” di dialog cetak browser.
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        {datasets.map((d) => (
          <Card key={d.key}>
            <CardContent className="space-y-3 p-4">
              <div>
                <div className="font-medium">{d.label}</div>
                <div className="text-sm text-muted-foreground">{d.description}</div>
              </div>
              <div className="flex flex-wrap gap-2">
                {FORMATS.filter((f) => hasPermission(f.perm)).map((f) => {
                  const Icon = f.icon
                  const id = `${d.key}:${f.key}`
                  return (
                    <Button
                      key={f.key}
                      size="sm"
                      variant="outline"
                      disabled={busy === id}
                      onClick={() => download(d.key, f.key)}
                    >
                      <Icon className="size-4" />
                      {busy === id ? 'Menyiapkan…' : f.label}
                    </Button>
                  )
                })}
                {hasPermission('export.pdf') && (
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={busy === `${d.key}:print`}
                    onClick={() => print(d.key)}
                  >
                    <Printer className="size-4" />
                    {busy === `${d.key}:print` ? 'Menyiapkan…' : 'Cetak'}
                  </Button>
                )}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {datasets.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Anda tidak memiliki akses laporan.
          </CardContent>
        </Card>
      )}

      {legacyFiles.length > 0 && (
        <>
          <PageHeader
            title="Excel Klasik"
            subtitle="Replika 9 file Excel lama — sheet, kolom & rumus sama persis, diisi data live STOCKWISE"
            icon={<Archive className="size-5" />}
          />
          <div className="grid gap-3 sm:grid-cols-2">
            {legacyFiles.map((f) => {
              const id = `legacy:${f.key}`
              return (
                <Card key={f.key}>
                  <CardContent className="flex items-center justify-between gap-3 p-4">
                    <div>
                      <div className="font-medium">{f.label}</div>
                      <div className="font-mono text-xs text-muted-foreground">{f.filename}</div>
                      <div className="text-xs text-muted-foreground">{f.description}</div>
                    </div>
                    <Button size="sm" variant="outline" disabled={busy === id} onClick={() => downloadLegacy(f)}>
                      <FileSpreadsheet className="size-4" />
                      {busy === id ? 'Menyiapkan…' : 'Unduh'}
                    </Button>
                  </CardContent>
                </Card>
              )
            })}
          </div>
        </>
      )}
    </div>
  )
}
