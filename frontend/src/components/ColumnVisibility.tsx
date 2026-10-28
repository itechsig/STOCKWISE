import { useEffect, useState } from 'react'
import { Columns3 } from 'lucide-react'
import type { Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'

function load(storageKey: string): Set<string> {
  try {
    const raw = localStorage.getItem(storageKey)
    return raw ? new Set(JSON.parse(raw) as string[]) : new Set()
  } catch {
    return new Set()
  }
}

/** Persisted (localStorage) set of hidden column keys — same mechanic as Master Barang / Analisis Inventory's "Kolom" menu. */
export function useHiddenColumns(storageKey: string) {
  const [hiddenCols, setHiddenCols] = useState<Set<string>>(() => load(storageKey))
  useEffect(() => {
    try {
      localStorage.setItem(storageKey, JSON.stringify([...hiddenCols]))
    } catch {
      // localStorage tidak tersedia (mode privat, dll) — abaikan, cukup tidak persisten.
    }
  }, [storageKey, hiddenCols])

  function toggle(key: string) {
    setHiddenCols((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  return [hiddenCols, toggle] as const
}

/** Columns array filtered down to what's actually visible — pass straight to DataTable. */
export function visibleColumns<T>(columns: Column<T>[], hiddenCols: Set<string>, alwaysVisible: string[] = []): Column<T>[] {
  return columns.filter((c) => alwaysVisible.includes(c.key) || !hiddenCols.has(c.key))
}

/** "Kolom" button + checkbox dropdown to hide/show columns — also drives what's sent to export/cetak (see ExportBar's `columns` prop). */
export function ColumnMenu<T>({
  columns, hiddenCols, onToggle, alwaysVisible = [], hint = 'Kolom tersembunyi tidak ikut di export/cetak.',
}: {
  columns: Column<T>[]
  hiddenCols: Set<string>
  onToggle: (key: string) => void
  alwaysVisible?: string[]
  /** the line above the checkboxes (pages whose export does not follow the picked columns say so) */
  hint?: string
}) {
  const [open, setOpen] = useState(false)
  const toggleable = columns.filter((c) => !alwaysVisible.includes(c.key))

  return (
    <div className="relative">
      <Button size="sm" variant="outline" onClick={() => setOpen((v) => !v)}>
        <Columns3 className="size-4" /> Kolom
      </Button>
      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div className="absolute right-0 z-20 mt-1 max-h-80 w-64 overflow-y-auto rounded-lg border bg-card p-1.5 shadow-lg">
            <p className="px-2 py-1 text-xs text-muted-foreground">{hint}</p>
            {toggleable.map((c) => (
              <label key={c.key} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted/60">
                <input type="checkbox" checked={!hiddenCols.has(c.key)} onChange={() => onToggle(c.key)} />
                {c.header}
              </label>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
