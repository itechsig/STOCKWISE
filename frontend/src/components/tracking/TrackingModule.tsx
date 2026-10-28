import { useEffect, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { useTrackingList } from '@/features/tracking/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { ExportBar } from '@/components/export/ExportBar'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { statusLabel } from '@/components/ui/request-badge'
import { Modal } from '@/components/ui/modal'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { FilterBar } from '@/components/FilterBar'

interface TrackingModuleProps<T extends { id: number }> {
  base: string
  title: string
  subtitle?: string
  icon?: ReactNode
  columns: Column<T>[]
  statuses?: string[]
  extraFilter?: ReactNode
  extraFilterParams?: Record<string, string | number | undefined>
  canCreate?: boolean
  createLabel?: string
  renderCreate?: (close: () => void) => ReactNode
  renderDetail?: (row: T, close: () => void) => ReactNode
  detailTitle?: (row: T) => string
  /**
   * Key for each rendered row — defaults to `r.id`. Override when a module
   * lists flat per-line rows that share one header `id` (opening the same
   * detail modal for several rows), e.g. Pengembalian Bekas's RI-style list;
   * `id` itself still has to stay the header id for renderDetail to work.
   */
  rowKey?: (row: T) => string | number
  searchPlaceholder?: string
  /** Rendered between the header and the filters — e.g. sheet tabs. */
  headerExtra?: ReactNode
  /** Own labels for the status filter (default: the shared request-badge labels). */
  statusLabels?: Record<string, string>
  /** "Catatan" column entity — defaults to `base`; notes are keyed by rowKey. */
  notesEntity?: string
  /**
   * Export/Cetak bar (Excel/CSV/PDF/Print), same mechanic as Analisis Inventory —
   * a key in ExportController::DATASETS (backend), not necessarily `base` itself
   * (lines-style bases like "maintenance-lines" export under a shorter key, e.g.
   * "maintenance"). Omit to not show the bar at all.
   */
  exportDataset?: string
  exportPermission?: string
}

export function TrackingModule<T extends { id: number }>({
  base,
  title,
  subtitle,
  icon,
  columns,
  statuses,
  extraFilter,
  extraFilterParams,
  canCreate,
  createLabel = 'Buat Baru',
  renderCreate,
  renderDetail,
  detailTitle,
  rowKey = (r) => r.id,
  searchPlaceholder = 'Cari…',
  headerExtra,
  statusLabels,
  notesEntity,
  exportDataset,
  exportPermission,
}: TrackingModuleProps<T>) {
  // ?search=… — opened from a link elsewhere (a No. Seri, a document number)
  const [params] = useSearchParams()
  const urlSearch = params.get('search') ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])
  const [creating, setCreating] = useState(false)
  const [selected, setSelected] = useState<T | null>(null)

  const activeFilters = {
    search: search || undefined,
    status: status || undefined,
    ...extraFilterParams,
  }
  const { data, isLoading } = useTrackingList<T>(base, { ...activeFilters, page })
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage)

  return (
    <div className="space-y-5">
      <PageHeader
        title={title}
        subtitle={subtitle}
        icon={icon}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {exportDataset && (
              <ExportBar dataset={exportDataset} filters={activeFilters} filenameBase={exportDataset} viewPermission={exportPermission ?? `${base}.view`} />
            )}
            {canCreate && renderCreate && (
              <Button size="sm" onClick={() => setCreating(true)}>
                <Plus className="size-4" />
                {createLabel}
              </Button>
            )}
          </div>
        }
      />

      {headerExtra}

      <FilterBar className="items-center">
        <Input
          placeholder={searchPlaceholder}
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        {statuses && statuses.length > 0 && (
          <Select
            className="max-w-[12rem]"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value)
              setPage(1)
            }}
          >
            <option value="">Semua status</option>
            {statuses.map((s) => (
              <option key={s} value={s}>
                {statusLabels?.[s] ?? statusLabel(s)}
              </option>
            ))}
          </Select>
        )}
        {extraFilter}
      </FilterBar>

      <DataTable
        columns={columns}
        rows={scrollRows}
        footer={scrollFooter}
        rowKey={rowKey}
        notes={{ entity: notesEntity ?? base }}
        isLoading={isLoading}
        onRowClick={renderDetail ? (r) => setSelected(r) : undefined}
      />

      {renderCreate && (
        <Modal open={creating} onClose={() => setCreating(false)} title={createLabel} className="max-w-xl">
          {renderCreate(() => setCreating(false))}
        </Modal>
      )}

      {renderDetail && selected && (
        <Modal
          open
          onClose={() => setSelected(null)}
          title={detailTitle ? detailTitle(selected) : 'Detail'}
          className="max-w-2xl"
        >
          {renderDetail(selected, () => setSelected(null))}
        </Modal>
      )}
    </div>
  )
}

/** Small definition list used inside detail modals. */
export function DetailGrid({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
      {rows.map(([k, v]) => (
        <div key={k} className="flex flex-col">
          <dt className="text-xs text-muted-foreground">{k}</dt>
          <dd className="font-medium">{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}
