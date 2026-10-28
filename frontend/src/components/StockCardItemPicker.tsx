import { useEffect, useRef, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search, X } from 'lucide-react'
import { api } from '@/lib/api'
import { scopeKey, useApiPaths, useStockCardScope } from '@/features/stock-card/scope'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

export interface StockCardItemOption {
  id: number
  code: string
  description: string
  unit: string | null
  is_active: boolean
  accurate_qty: number | null
  /** Kartu Stock this item already has, per project */
  cards: { project_id: number | null; project: string | null; card_number: string }[]
}

const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 })

/**
 * Pick the item of a Kartu Stock from Master Barang — type its code or its name
 * (GET /api/stock-card/items: exact code first, then code prefix, then name).
 * Shows the chosen item; ↑ ↓ Enter to choose from the keyboard.
 */
export function StockCardItemPicker({ selected, onPick, projectId }: {
  selected?: { code: string; description: string } | null
  onPick: (item: StockCardItemOption) => void
  /** the project the card is for: an item that already has a card there can't be picked again */
  projectId?: number
}) {
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => { const t = setTimeout(() => setDebounced(q.trim()), 250); return () => clearTimeout(t) }, [q])
  useEffect(() => {
    const close = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const scope = useStockCardScope()
  const itemsPath = useApiPaths().items
  const { data = [], isFetching } = useQuery({
    queryKey: ['stock-card', 'items', scopeKey(scope), debounced],
    enabled: debounced.length >= 2,
    queryFn: async () => (await api.get<{ data: StockCardItemOption[] }>(itemsPath, { params: { search: debounced } })).data.data,
    placeholderData: keepPreviousData,
  })
  useEffect(() => setActive(0), [debounced])

  const inProject = (it: StockCardItemOption) => projectId != null && it.cards.some((c) => c.project_id === projectId)
  const pick = (it: StockCardItemOption) => { if (inProject(it)) return; onPick(it); setQ(''); setOpen(false) }
  const showList = open && debounced.length >= 2

  return (
    <div ref={box} className="relative">
      <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        className="pl-9 pr-8"
 placeholder={selected ? `${selected.code} ${selected.description}` : 'Ketik kode atau nama barang (min 2 huruf)…'}
        value={q}
        onFocus={() => setOpen(true)}
        onChange={(e) => { setQ(e.target.value); setOpen(true) }}
        onKeyDown={(e) => {
          if (!showList || data.length === 0) return
          if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, data.length - 1)) }
          if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
          if (e.key === 'Enter') { e.preventDefault(); pick(data[active]) }
          if (e.key === 'Escape') setOpen(false)
        }}
      />
      {q && (
        <button type="button" onClick={() => setQ('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground" aria-label="Kosongkan">
          <X className="size-4" />
        </button>
      )}
      {showList && (
        <ul className="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-card shadow-lg">
          {isFetching && data.length === 0 && <li className="px-3 py-2 text-sm text-muted-foreground">Mencari…</li>}
          {!isFetching && data.length === 0 && <li className="px-3 py-2 text-sm text-muted-foreground">Tidak ada barang dengan kode / nama itu di Master Barang.</li>}
          {data.map((it, i) => (
            <li key={it.id}>
              <button
                type="button"
                onMouseEnter={() => setActive(i)}
                onClick={() => pick(it)}
                disabled={inProject(it)}
                className={cn('flex w-full items-start justify-between gap-3 px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:opacity-60', i === active ? 'bg-muted' : 'hover:bg-muted/60')}
              >
                <span className="min-w-0">
                  <span className="font-mono text-xs font-medium">{it.code}</span>
                  {!it.is_active && <span className="ml-1.5 rounded bg-muted px-1 text-[10px] text-muted-foreground">nonaktif</span>}
                  {inProject(it) && <span className="ml-1.5 rounded bg-amber-100 px-1 text-[10px] text-amber-800">sudah ada kartu di proyek ini</span>}
                  {!inProject(it) && it.cards.length > 0 && (
                    <span className="ml-1.5 rounded bg-primary/10 px-1 text-[10px] text-primary">kartu di proyek lain: {it.cards.map((c) => c.project ?? 'tanpa proyek').join(', ')}</span>
                  )}
                  <span className="block whitespace-normal leading-snug">{it.description}</span>
                </span>
                <span className="shrink-0 text-right text-xs text-muted-foreground tabular-nums">
 {it.accurate_qty != null ? nf.format(it.accurate_qty) : ''} {it.unit ?? ''}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
