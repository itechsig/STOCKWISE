import { useState } from 'react'
import { useAssets, type AssetOption } from '@/features/tracking/api'
import { Input } from '@/components/ui/input'

export function AssetPicker({ onPick }: { onPick: (asset: AssetOption) => void }) {
  const [q, setQ] = useState('')
  const { data, isFetching } = useAssets(q)
  const open = q.trim().length >= 1

  return (
    <div className="relative">
      <Input placeholder="Cari nopol / nama kendaraan…" value={q} onChange={(e) => setQ(e.target.value)} />
      {open && (
        <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-card shadow-md">
          {isFetching && <li className="px-3 py-2 text-sm text-muted-foreground">Mencari…</li>}
          {!isFetching && (data?.length ?? 0) === 0 && (
            <li className="px-3 py-2 text-sm text-muted-foreground">Tidak ada aset.</li>
          )}
          {data?.map((a) => (
            <li key={a.id}>
              <button
                type="button"
                className="block w-full px-3 py-2 text-left text-sm hover:bg-muted"
                onClick={() => {
                  onPick(a)
                  setQ('')
                }}
              >
                <span className="font-mono text-xs text-muted-foreground">{a.code}</span> {a.name}
                {a.brand_model ? <span className="ml-1 text-xs text-muted-foreground">({a.brand_model})</span> : null}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
