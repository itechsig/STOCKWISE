import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { Pager } from '@/components/InfiniteRows'
import { NoteCell, useRowNotes } from '@/components/RowNotes'
import { useScrollMemory } from '@/lib/listMemory'

export interface Column<T> {
  key: string
  header: ReactNode
  cell: (row: T) => ReactNode
  className?: string
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  isLoading?: boolean
  emptyText?: string
  onRowClick?: (row: T) => void
  /** Extra classes on the scroll container — e.g. `max-h-[65vh] overflow-y-auto` to cap a tall table instead of letting the whole page grow. */
  containerClassName?: string
  /** Keeps the header row pinned while the body scrolls (use together with a height-capped containerClassName). */
  stickyHeader?: boolean
  /** Rendered inside the scroll container, under the table (e.g. the infinite-scroll sentinel of a height-capped table). */
  footer?: ReactNode
  /**
   * Adds the "Catatan" column: a free note per row, stored under `entity` +
   * `key(row)` (default: rowKey). Use a stable key — the row's own id.
   */
  notes?: { entity: string; key?: (row: T) => string | number }
  /** Remember this table's scroll position under this key; restored when the user comes back with Back. */
  scrollMemoryKey?: string
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  isLoading,
  emptyText = 'Tidak ada data.',
  onRowClick,
  containerClassName,
  stickyHeader,
  footer,
  notes,
  scrollMemoryKey,
}: DataTableProps<T>) {
  const scrollRef = useScrollMemory(scrollMemoryKey, rows.length > 0)
  // rows already on screen (e.g. restored after Back) stay visible while fresh data loads
  const loading = isLoading && rows.length === 0
  const noteKey = notes?.key ?? rowKey
  const { data: noteMap } = useRowNotes(notes?.entity, notes ? rows.map(noteKey) : [])
  const cols: Column<T>[] = !notes ? columns : [
    ...columns,
    {
      key: '__note',
      header: 'Catatan',
      cell: (r: T) => <NoteCell entity={notes.entity} rowKey={noteKey(r)} note={noteMap?.[String(noteKey(r))]} />,
    },
  ]

  return (
    // Every table scrolls inside itself (fixed height, sticky header); more rows load at its bottom (footer).
    <div ref={scrollRef} className={cn('max-h-[70vh] overflow-auto rounded-lg border bg-card', containerClassName)}>
      <table className="w-full text-sm">
        <thead className={cn('sticky top-0 z-10 border-b text-left', stickyHeader === false ? 'bg-muted/70' : 'bg-muted')}>
          <tr>
            {cols.map((c) => (
              <th key={c.key} className={cn('whitespace-nowrap px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground', c.className)}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {loading && (
            <tr>
              <td colSpan={cols.length} className="px-3 py-10 text-center text-muted-foreground">
                Memuat…
              </td>
            </tr>
          )}
          {!loading && rows.length === 0 && (
            <tr>
              <td colSpan={cols.length} className="px-3 py-10 text-center text-muted-foreground">
                {emptyText}
              </td>
            </tr>
          )}
          {!loading &&
            rows.map((row) => (
              <tr
                key={rowKey(row)}
                className={cn('border-t transition-colors hover:bg-muted/40', onRowClick && 'cursor-pointer')}
                onClick={() => onRowClick?.(row)}
              >
                {cols.map((c) => (
                  <td key={c.key} className={cn('px-3 py-2.5', c.className)}>
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
        </tbody>
      </table>
      {footer && <div className="sticky bottom-0 left-0 z-10 border-t bg-card">{footer}</div>}
    </div>
  )
}

/** Previous / next pages (older pages call this; same look as the numbered Pager). */
export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (p: number) => void }) {
  return <Pager page={page} lastPage={lastPage} total={total} perPage={Math.max(1, Math.ceil(total / Math.max(lastPage, 1)))} onPage={onPage}
    className="rounded-lg border" />
}
