import { useState } from 'react'
import { useItemLookup, type ItemLookupResult } from '@/features/inventory/api'
import { Input } from '@/components/ui/input'

export function ItemPicker({ onPick }: { onPick: (item: ItemLookupResult) => void }) {
  const [q, setQ] = useState('')
  const { data, isFetching } = useItemLookup(q)
  const open = q.trim().length >= 2

  return (
    <div className="relative">
      <Input
        placeholder="Cari barang (min 2 huruf)…"
        value={q}
        onChange={(e) => setQ(e.target.value)}
      />
      {open && (
        <ul className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-card shadow-md">
          {isFetching && <li className="px-3 py-2 text-sm text-muted-foreground">Mencari…</li>}
          {!isFetching && (data?.length ?? 0) === 0 && (
            <li className="px-3 py-2 text-sm text-muted-foreground">Tidak ada hasil.</li>
          )}
          {data?.map((it) => (
            <li key={it.id}>
              <button
                type="button"
                className="block w-full px-3 py-2 text-left text-sm hover:bg-muted"
                onClick={() => {
                  onPick(it)
                  setQ('')
                }}
              >
                <span className="font-mono text-xs text-muted-foreground">{it.code}</span>{' '}
                {it.description}
                <span className="ml-2 text-xs text-muted-foreground">
                  (tersedia {it.stock_known ? it.available : '?'})
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
