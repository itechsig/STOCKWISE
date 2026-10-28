import { useEffect, useId, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Plus, Trash2 } from 'lucide-react'
import { useTrackingList } from '@/features/tracking/api'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { useRowNotes, type RowNote } from '@/components/RowNotes'
import { ExportBar } from '@/components/export/ExportBar'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { useInlineRowEditor } from './useInlineRowEditor'
import { FilterBar } from '@/components/FilterBar'

/** Header cell of every tracking sheet — the same look as DataTable's. */
const TH = 'whitespace-nowrap border border-border/70 px-2 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground'

/**
 * The STPP way of working (pages/tracking/StppPage.tsx), shared by every tracking
 * sheet: the Excel sheet as a table, every cell typed into directly and saved by
 * itself, a blue row at the bottom for a new entry — no create / edit popup.
 */

export const cellInput = 'h-9 w-full rounded-md border border-transparent bg-transparent px-2 text-sm outline-none hover:border-border focus:border-primary focus:bg-card focus:ring-2 focus:ring-primary/20 disabled:cursor-default disabled:opacity-100 disabled:hover:border-transparent'

export const today = () => new Date().toISOString().slice(0, 10)

/** Enter = save the row and go to the next cell of the table (like Excel). */
function enterToNext(e: React.KeyboardEvent<HTMLElement>, flush: () => void) {
  if (e.key !== 'Enter' || e.shiftKey) return
  e.preventDefault()
  flush()
  const inputs = Array.from(e.currentTarget.closest('table')?.querySelectorAll<HTMLElement>('[data-cell]:not(:disabled)') ?? [])
  inputs[inputs.indexOf(e.currentTarget) + 1]?.focus()
}

/** A text cell with the Excel "Dropdown List" as suggestions (values already used + fixed ones). */
export function CellList({ value, onChange, options, placeholder, disabled, className, onKeyDown }: {
  value: string; onChange: (v: string) => void; options: string[]; placeholder?: string; disabled?: boolean; className?: string
  onKeyDown?: React.KeyboardEventHandler<HTMLInputElement>
}) {
  const listId = useId()
  return (
    <>
      <input data-cell list={listId} value={value} onChange={(e) => onChange(e.target.value)} onKeyDown={onKeyDown} placeholder={placeholder}
        autoComplete="off" disabled={disabled} className={cn(cellInput, className)} />
      <datalist id={listId}>{[...new Set(options.filter(Boolean))].slice(0, 400).map((o) => <option key={o} value={o} />)}</datalist>
    </>
  )
}

type StrForm = Record<string, string>

/**
 * useInlineRowEditor + the bits every table row needs: save when focus leaves
 * the row, Enter to the next cell, and `input(key)` for a plain text / date /
 * number cell bound to the form.
 */
export function useSheetRow<TRow, TForm extends StrForm>(opts: Parameters<typeof useInlineRowEditor<TRow, TForm>>[0] & { canEdit: boolean }) {
  const ed = useInlineRowEditor<TRow, TForm>(opts)
  const flush = () => void ed.flush()
  const rowProps = {
    onBlur: (e: React.FocusEvent<HTMLTableRowElement>) => { if (!e.currentTarget.contains(e.relatedTarget as Node)) flush() },
  }
  const onKeyDown = (e: React.KeyboardEvent<HTMLElement>) => enterToNext(e, flush)
  const input = (k: keyof TForm & string, props: React.InputHTMLAttributes<HTMLInputElement> & { locked?: boolean } = {}) => {
    const { locked, className, ...rest } = props
    return (
      <input data-cell disabled={!opts.canEdit || locked} value={ed.form[k]} onChange={(e) => ed.change(k, e.target.value as TForm[typeof k])}
        onKeyDown={onKeyDown} className={cn(cellInput, className)} {...rest} />
    )
  }
  const list = (k: keyof TForm & string, options: string[], props: { placeholder?: string; className?: string; locked?: boolean } = {}) => (
    <CellList value={ed.form[k]} onChange={(v) => ed.change(k, v as TForm[typeof k])} options={options} onKeyDown={onKeyDown}
      placeholder={props.placeholder} className={props.className} disabled={!opts.canEdit || props.locked} />
  )
  const select = (k: keyof TForm & string, options: [string, string][], props: { locked?: boolean; className?: string } = {}) => (
    <select data-cell disabled={!opts.canEdit || props.locked} value={ed.form[k]} onKeyDown={onKeyDown}
      onChange={(e) => { ed.change(k, e.target.value as TForm[typeof k]) }} className={cn(cellInput, 'pr-6', props.className)}>
      {options.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
    </select>
  )
  // longer text (keterangan): one line like the other cells — the full text shows on hover
  const area = (k: keyof TForm & string, props: { placeholder?: string; className?: string; locked?: boolean } = {}) => (
    <input data-cell disabled={!opts.canEdit || props.locked} value={ed.form[k]} placeholder={props.placeholder} title={ed.form[k] || undefined}
      onChange={(e) => ed.change(k, e.target.value as TForm[typeof k])} onKeyDown={onKeyDown} className={cn(cellInput, 'text-ellipsis', props.className)} />
  )
  return { ...ed, rowProps, input, list, select, area, onKeyDown }
}

/** Table cell wrapper with the sheet's border. */
export function Td({ children, className, title }: { children?: ReactNode; className?: string; title?: string }) {
  return <td title={title} className={cn('border border-border/70 px-1 py-1 align-middle', className)}>{children}</td>
}

/** Small icon buttons of the "Aksi" column. */
export function RowActions({ onAdd, addTitle, onDelete, deleteTitle = 'Hapus baris', children }: {
  onAdd?: () => void; addTitle?: string; onDelete?: () => void; deleteTitle?: string; children?: ReactNode
}) {
  return (
    <div className="flex items-center justify-center gap-1">
      {children}
      {onAdd && (
        <button type="button" title={addTitle} aria-label={addTitle} onClick={onAdd} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-primary">
          <Plus className="size-4" />
        </button>
      )}
      {onDelete && (
        <button type="button" title={deleteTitle} aria-label={deleteTitle} onClick={onDelete} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive">
          <Trash2 className="size-4" />
        </button>
      )}
    </div>
  )
}

/** Tabs for the sheets of one Excel workbook. */
export function SheetTabs<K extends string>({ tabs, value, onChange }: { tabs: readonly (readonly [K, string])[]; value: K; onChange: (k: K) => void }) {
  return (
    <div className="flex gap-1 overflow-x-auto border-b" role="tablist">
      {tabs.map(([k, label]) => (
        <button key={k} type="button" role="tab" aria-selected={value === k} onClick={() => onChange(k)}
          className={cn('-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
            value === k ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>
          {label}
        </button>
      ))}
    </div>
  )
}

/**
 * One Excel sheet as an inline-editable table: search, status filter, export,
 * sticky header, rows appended while scrolling, and the new-entry row last.
 */
export function InlineSheet<T>({
  base, params, headers, canEdit, rowKey, renderRow, newRow, statuses, statusLabels, searchPlaceholder = 'Cari…',
  exportDataset, exportPermission, hint, notesEntity, extraFilter, toolbarExtra,
}: {
  /** API list endpoint (/api/{base}) — also the react-query key. */
  base: string
  params?: Record<string, string | number | undefined>
  headers: ReactNode[]
  /** Shows the "Aksi" column. */
  canEdit: boolean
  rowKey: (row: T) => string | number
  renderRow: (row: T, note?: RowNote) => ReactNode
  /** The blue row for a new entry (null = no create permission). */
  newRow?: ReactNode
  statuses?: string[]
  statusLabels?: Record<string, string>
  searchPlaceholder?: string
  exportDataset?: string
  exportPermission?: string
  hint?: ReactNode
  /** "Catatan" column entity — the row notes are keyed by rowKey. */
  notesEntity?: string
  extraFilter?: ReactNode
  toolbarExtra?: ReactNode
}) {
  // ?search=… — opened from a link elsewhere (a No. Seri, a document number)
  const [urlParams] = useSearchParams()
  const urlSearch = urlParams.get('search') ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  useEffect(() => { setSearch(urlSearch); setPage(1) }, [urlSearch])
  const filters = { search: search || undefined, status: status || undefined, ...params }
  const { data, isLoading } = useTrackingList<T>(base, { ...filters, page })
  const { rows, footer } = useInfiniteRows(data, page, setPage)
  const { data: noteMap } = useRowNotes(notesEntity, notesEntity ? rows.map(rowKey) : [])
  const cols = headers.length + (notesEntity ? 1 : 0) + (canEdit ? 1 : 0)

  return (
    <div className="space-y-3">
      <FilterBar className="items-center">
        <Input placeholder={searchPlaceholder} className="max-w-xs" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        {statuses && statuses.length > 0 && (
          <Select className="max-w-[12rem]" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">Semua status</option>
            {statuses.map((s) => <option key={s} value={s}>{statusLabels?.[s] ?? s}</option>)}
          </Select>
        )}
        {extraFilter}
        {exportDataset && <ExportBar dataset={exportDataset} filters={filters} filenameBase={exportDataset} viewPermission={exportPermission ?? `${base}.view`} />}
        {toolbarExtra}
      </FilterBar>
      {canEdit && (
        <p className="text-xs text-muted-foreground">
 Ketik langsung di tabel tersimpan otomatis. Baris biru = data baru.{hint ? <> {hint}</> : null}
        </p>
      )}
      <div className="max-h-[70vh] overflow-auto rounded-lg border bg-card">
        <table className="w-full table-auto border-collapse text-sm">
          <thead className="sticky top-0 z-10 bg-muted">
            <tr className="text-left">
              {headers.map((h, i) => <th key={i} className={TH}>{h}</th>)}
              {notesEntity && <th className={TH}>Catatan</th>}
              {canEdit && <th className={TH}>Aksi</th>}
            </tr>
          </thead>
          <tbody>
            {isLoading && rows.length === 0 && (
              <tr><td colSpan={cols} className="border border-border/70 px-2 py-10 text-center text-muted-foreground">Memuat…</td></tr>
            )}
            {!isLoading && rows.length === 0 && !newRow && (
              <tr><td colSpan={cols} className="border border-border/70 px-2 py-10 text-center text-muted-foreground">Tidak ada data.</td></tr>
            )}
            {rows.map((r) => <RowFragment key={rowKey(r)}>{renderRow(r, noteMap?.[rowKey(r)])}</RowFragment>)}
            {newRow}
          </tbody>
        </table>
        <div className="sticky bottom-0 left-0 z-10 border-t bg-card">{footer}</div>
      </div>
    </div>
  )
}

function RowFragment({ children }: { children: ReactNode }) {
  return <>{children}</>
}

/** Row background: blue for the new-entry row, as in STPP. */
export const rowClass = (isNew: boolean) => cn('align-middle', isNew ? 'bg-primary/5' : 'bg-card hover:bg-muted/30')

/** A read-only cell (No, No. SPK, a locked document number…). */
export const textCell = 'whitespace-nowrap px-2 py-1.5 text-sm'

/** A document number input with its photo button(s) beside it, on one line. */
export function DocCell({ children, photo }: { children: ReactNode; photo?: ReactNode }) {
  return (
    <div className="flex items-center gap-1">
      <div className="min-w-0 flex-1">{children}</div>
      {photo}
    </div>
  )
}
