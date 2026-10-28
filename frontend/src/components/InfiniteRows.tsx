import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'

interface PageData<T> {
  data: T[]
  meta: { page: number; last_page: number; total: number; per_page?: number }
}

/**
 * Numbered pages for a server-paged list (ERP style). The list keeps its server paging
 * (big tables such as NPBG stay light); the page state stays in the page — filters still
 * reset it to 1, and with useRemembered it is back after Back.
 *
 *   const { rows, footer } = useInfiniteRows(data, page, setPage)
 *   <DataTable rows={rows} footer={footer} … />
 *
 * (The name is kept from when the list loaded more rows while scrolling, so every page using it
 * moved to pages at once.)
 */
export function useInfiniteRows<T>(data: PageData<T> | undefined, page: number, setPage: (updater: (p: number) => number) => void, _memoryKey?: string) {
  const rows: T[] = data?.data ?? []
  const footer = data && data.meta.total > 0 ? (
    <Pager page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} perPage={data.meta.per_page ?? Math.max(rows.length, 1)}
      loading={data.meta.page !== page} onPage={(p) => setPage(() => p)} />
  ) : null

  return { rows, footer }
}

/** Client-side list (already fully loaded): the same numbered pages over slices of it. */
export function useInfiniteSlice<T>(items: T[], chunk = 50, resetKey?: unknown) {
  const [page, setPage] = useState(1)
  useEffect(() => setPage(1), [resetKey, chunk])
  const lastPage = Math.max(1, Math.ceil(items.length / chunk))
  const current = Math.min(page, lastPage)
  const rows = items.slice((current - 1) * chunk, current * chunk)
  const footer = items.length > 0
    ? <Pager page={current} lastPage={lastPage} total={items.length} perPage={chunk} onPage={setPage} />
    : null
  return { rows, footer }
}

/**
 * "21–40 dari 9.384 · ‹ 1 … 2 [3] 4 … 470 ›". Turning a page brings the table's own
 * scroll area back to the top.
 */
export function Pager({ page, lastPage, total, perPage, onPage, loading, onPerPage, className }: {
  page: number
  lastPage: number
  total: number
  perPage: number
  onPage: (p: number) => void
  loading?: boolean
  onPerPage?: (n: number) => void
  className?: string
}) {
  const from = total === 0 ? 0 : (page - 1) * perPage + 1
  const to = Math.min(page * perPage, total)
  const pages = [...new Set([1, page - 1, page, page + 1, lastPage])].filter((p) => p >= 1 && p <= lastPage).sort((a, b) => a - b)
  const go = (p: number, el: HTMLElement) => {
    el.closest('.overflow-auto')?.scrollTo({ top: 0 })
    onPage(p)
  }

  return (
    <div className={cn('flex flex-col gap-2 bg-card px-3 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between', loading && 'opacity-70', className)}>
      <div className="flex items-center gap-3 text-muted-foreground">
        <span><b className="font-medium tabular-nums text-foreground">{from.toLocaleString('id-ID')}–{to.toLocaleString('id-ID')}</b> dari <span className="tabular-nums">{total.toLocaleString('id-ID')}</span></span>
        {onPerPage && (
          <label className="hidden items-center gap-1.5 sm:flex">
            · Baris
            <select className="h-7 rounded-md border bg-card px-1 text-sm text-foreground" value={perPage} onChange={(e) => onPerPage(Number(e.target.value))}>
              {[10, 20, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
            </select>
          </label>
        )}
      </div>
      {lastPage > 1 && (
        <div className="flex items-center gap-1" role="navigation" aria-label="Halaman">
          <button type="button" className="flex h-7 w-7 items-center justify-center rounded-md border bg-card hover:bg-muted disabled:opacity-40"
            disabled={page <= 1} onClick={(e) => go(page - 1, e.currentTarget)} aria-label="Halaman sebelumnya"><ChevronLeft className="size-4" /></button>
          {pages.map((p, i) => (
            <span key={p} className="flex items-center">
              {i > 0 && p - pages[i - 1] > 1 && <span className="px-1 text-muted-foreground">…</span>}
              <button type="button" onClick={(e) => go(p, e.currentTarget)} aria-current={p === page ? 'page' : undefined}
                className={cn('h-7 min-w-7 rounded-md px-1.5 text-sm tabular-nums', p === page ? 'bg-primary font-semibold text-primary-foreground' : 'hover:bg-muted')}>
                {p.toLocaleString('id-ID')}
              </button>
            </span>
          ))}
          <button type="button" className="flex h-7 w-7 items-center justify-center rounded-md border bg-card hover:bg-muted disabled:opacity-40"
            disabled={page >= lastPage} onClick={(e) => go(page + 1, e.currentTarget)} aria-label="Halaman berikutnya"><ChevronRight className="size-4" /></button>
        </div>
      )}
    </div>
  )
}
