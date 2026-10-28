import { useState } from 'react'
import { Download, FileText, Printer, Sheet } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { api, apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { DropdownMenu } from '@/components/ui/dropdown'

type Filters = Record<string, string | number | boolean | undefined>

/**
 * Export/Cetak bar — same mechanic everywhere in STOCKWISE (first built for
 * Analisis Inventory): Excel / CSV / PDF (download) + Cetak (print, opens the
 * same PDF html straight in a new tab via window.print()). Always follows the
 * filters currently active on the page — empty filters = semua data.
 *
 * `dataset` must be a key in ExportController::DATASETS (backend); `filters`
 * is whatever that dataset's build() arm reads via $request->whenFilled(...).
 */
export function ExportBar({
  dataset, filters, filenameBase, viewPermission, className, columns, variant = 'menu',
}: {
  dataset: string
  filters: Filters
  filenameBase: string
  /** Permission that already gates viewing this list — export is hidden without it too. */
  viewPermission: string
  className?: string
  /** Comma-joined keys of the columns currently visible on screen (hide-kolom) — omit to export every column. */
  columns?: string
  /** `menu` (default): one "Export" button with the formats inside; `buttons`: all four side by side (Laporan page). */
  variant?: 'menu' | 'buttons'
}) {
  const { hasPermission } = useAuth()
  const [busy, setBusy] = useState<'xlsx' | 'csv' | 'pdf' | 'print' | null>(null)
  const [err, setErr] = useState<string | null>(null)

  const canView = hasPermission(viewPermission)
  const canExcel = canView && hasPermission('export.excel')
  const canCsv = canView && hasPermission('export.csv')
  const canPdf = canView && hasPermission('export.pdf')

  if (!canExcel && !canCsv && !canPdf) return null

  async function exportFile(format: 'xlsx' | 'csv' | 'pdf') {
    setBusy(format)
    setErr(null)
    try {
      const res = await api.get(`/api/export/${dataset}`, { params: { ...filters, format, columns }, responseType: 'blob' })
      const disposition = String(res.headers['content-disposition'] ?? '')
      const match = disposition.match(/filename="?([^"]+)"?/)
      const name = match?.[1] ?? `${filenameBase}.${format === 'pdf' ? 'html' : format}`
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

  async function printNow() {
    setBusy('print')
    setErr(null)
    const w = window.open('', '_blank')
    try {
      const res = await api.get(`/api/export/${dataset}`, { params: { ...filters, format: 'pdf', columns }, responseType: 'text' })
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

  if (variant === 'menu') {
    return (
      <div className={className}>
        <DropdownMenu label={busy ? 'Menyiapkan…' : 'Export'} icon={Download} busy={busy !== null} items={[
          { label: 'Excel (.xlsx)', icon: Download, onSelect: () => exportFile('xlsx'), hidden: !canExcel },
          { label: 'CSV', icon: Sheet, onSelect: () => exportFile('csv'), hidden: !canCsv },
          { label: 'PDF', icon: FileText, onSelect: () => exportFile('pdf'), hidden: !canPdf },
          { label: 'Cetak', icon: Printer, onSelect: () => printNow(), hidden: !canPdf },
        ]} />
        {err && <p className="mt-2 text-sm text-destructive">{err}</p>}
      </div>
    )
  }

  return (
    <div className={className}>
      <div className="flex flex-wrap items-center gap-2">
        {canExcel && (
          <Button variant="outline" size="sm" disabled={busy !== null} onClick={() => exportFile('xlsx')}>
            <Download className="size-4" /> {busy === 'xlsx' ? 'Menyiapkan…' : 'Excel'}
          </Button>
        )}
        {canCsv && (
          <Button variant="outline" size="sm" disabled={busy !== null} onClick={() => exportFile('csv')}>
            <Sheet className="size-4" /> {busy === 'csv' ? 'Menyiapkan…' : 'CSV'}
          </Button>
        )}
        {canPdf && (
          <Button variant="outline" size="sm" disabled={busy !== null} onClick={() => exportFile('pdf')}>
            <FileText className="size-4" /> {busy === 'pdf' ? 'Menyiapkan…' : 'PDF'}
          </Button>
        )}
        {canPdf && (
          <Button variant="outline" size="sm" disabled={busy !== null} onClick={() => printNow()}>
            <Printer className="size-4" /> {busy === 'print' ? 'Menyiapkan…' : 'Cetak'}
          </Button>
        )}
      </div>
      {err && <p className="mt-2 text-sm text-destructive">{err}</p>}
    </div>
  )
}
