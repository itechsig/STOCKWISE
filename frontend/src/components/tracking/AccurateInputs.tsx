import { useEffect, useId, useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, Loader2, XCircle } from 'lucide-react'
import { useAccurateDoc, useAccurateDocSearch, useVehicles, type AccurateDocSummary } from '@/features/tracking/api'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DocLink, DocText, docHref } from '@/components/DocLink'
import { cn } from '@/lib/utils'

/** Nopol typed like in the Excel, with the vehicles Accurate already knows as suggestions. */
export function NopolInput({ value, onChange, label = 'Nopol (Kendaraan)', required }: { value: string; onChange: (v: string) => void; label?: string; required?: boolean }) {
  const id = useId()
  const { data } = useVehicles(value.length >= 2 ? value : '')

  return (
    <div className="space-y-1">
      <Label htmlFor={id}>{label}{required && ' *'}</Label>
      <Input id={id} list={`${id}-list`} placeholder="mis. W 8985 QF (HINO DUTRO)" value={value} onChange={(e) => onChange(e.target.value)} />
      <datalist id={`${id}-list`}>
        {data?.map((v) => <option key={v.name} value={v.name} />)}
      </datalist>
    </div>
  )
}

export function DocSummary({ doc }: { doc: AccurateDocSummary }) {
  return (
    <div className="space-y-1 rounded-md border bg-muted/30 p-2 text-xs">
      <div className="flex flex-wrap gap-x-3">
        <DocLink no={doc.number} className="font-medium" />
        {doc.date && <span>{new Date(doc.date).toLocaleDateString('id-ID')}</span>}
        {doc.party && <span className="text-muted-foreground">{doc.party}</span>}
      </div>
      <ul className="space-y-0.5">
        {doc.lines.slice(0, 6).map((l, i) => (
          <li key={i} className="flex justify-between gap-2">
            <span className="truncate">{l.description}</span>
            <span className="shrink-0 tabular-nums">{l.qty} {l.unit}</span>
          </li>
        ))}
        {doc.lines.length > 6 && <li className="text-muted-foreground">+{doc.lines.length - 6} barang lainnya</li>}
      </ul>
      {doc.note && <p className="line-clamp-2 text-muted-foreground"><DocText text={doc.note} /></p>}
    </div>
  )
}

/** Value that settles `ms` after the last change (for search-as-you-type). */
function useDebounced<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms)
    return () => clearTimeout(t)
  }, [value, ms])
  return v
}

/**
 * NPBG / RI number — pick it from the Accurate list (type a number, keterangan,
 * customer/vendor or item to search, then click) or type it exactly as in the
 * Excel (NA/25/VIII/009, RI/NV/25/VIII/019). `onPick` hands the whole document
 * to forms that fill other columns from it. The chosen document is previewed
 * below the input — unless `compact` (an inline table row, where that preview
 * would make the row too wide/tall): then just the number shows, and its own
 * checkmark becomes a link to that NPBG/RI, same place `DocLink` opens it.
 */
export function DocNumberInput({ type, value, onChange, label, optional = true, onPick, compact = false }: {
  type: 'npbg' | 'ri'; value: string; onChange: (v: string) => void; label?: string; optional?: boolean
  onPick?: (doc: AccurateDocSummary) => void; compact?: boolean
}) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const q = useDebounced(value)
  const { data, isFetching, isError } = useAccurateDoc(type, value)
  const list = useAccurateDocSearch(type, q, open)
  const options = list.data ?? []
  const typed = value.trim().length >= 10

  function pick(doc: AccurateDocSummary) {
    onChange(doc.number)
    onPick?.(doc)
    setOpen(false)
  }

  return (
    <div className="space-y-1">
      {!compact && (
        <Label htmlFor={id}>
          {label ?? (type === 'npbg' ? 'No. NPBG' : 'No. RI')}
          {optional && <span className="font-normal text-muted-foreground"> (opsional)</span>}
        </Label>
      )}
      <div className="relative">
        <Input id={id} className="pr-8 font-mono uppercase" autoComplete="off" role="combobox" aria-expanded={open}
          placeholder={type === 'npbg' ? 'Ketik / pilih, mis. NA/25/VIII/009' : 'Ketik / pilih, mis. RI/NV/25/VIII/019'}
          value={value}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 150)}
          onChange={(e) => { onChange(e.target.value); setOpen(true); setActive(0) }}
          onKeyDown={(e) => {
            if (!open || options.length === 0) return
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, options.length - 1)) }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
            else if (e.key === 'Enter') { e.preventDefault(); pick(options[active]) }
            else if (e.key === 'Escape') setOpen(false)
          }} />
        <span className="absolute right-2 top-1/2 -translate-y-1/2">
          {(typed || open) && (isFetching || list.isFetching) && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
          {typed && !isFetching && !list.isFetching && data && (
            compact && docHref(value) ? (
              <Link to={docHref(value)!} onClick={(e) => e.stopPropagation()} title={`Buka ${value}`} className="text-emerald-600 hover:text-emerald-700">
                <CheckCircle2 className="size-4" />
              </Link>
            ) : (
              <CheckCircle2 className="size-4 text-emerald-600" />
            )
          )}
          {typed && !isFetching && !list.isFetching && isError && (
 <span title="Nomor ini tidak ada di data Accurate (dokumen lama / salah ketik) pilih dari daftar."><XCircle className="size-4 text-destructive" /></span>
          )}
        </span>
        {open && (
          <ul role="listbox" className="absolute z-50 mt-1 max-h-72 w-full min-w-[20rem] overflow-auto rounded-lg border bg-card p-1 text-sm shadow-lg"
            onMouseDown={(e) => e.preventDefault()}>
            {options.length === 0 && (
              <li className="px-2 py-1.5 text-xs text-muted-foreground">
                {list.isFetching ? 'Mencari…' : 'Tidak ada dokumen yang cocok.'}
              </li>
            )}
            {options.map((d, i) => (
              <li key={d.number} role="option" aria-selected={i === active}
                className={cn('cursor-pointer rounded px-2 py-1.5', i === active ? 'bg-muted' : 'hover:bg-muted/60')}
                onMouseEnter={() => setActive(i)} onClick={() => pick(d)}>
                <div className="flex flex-wrap items-center gap-x-2">
                  <span className="font-mono text-xs font-medium">{d.number}</span>
                  {d.date && <span className="text-xs text-muted-foreground">{new Date(d.date).toLocaleDateString('id-ID')}</span>}
                  {d.party && <span className="truncate text-xs text-muted-foreground">· {d.party}</span>}
                </div>
                {d.note && <div className="line-clamp-2 text-xs text-muted-foreground">{d.note}</div>}
                {d.lines[0] && (
                  <div className="truncate text-xs">
                    {d.lines[0].description}{d.lines.length > 1 && <span className="text-muted-foreground"> +{d.lines.length - 1} barang</span>}
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
 {!compact && typed && !isFetching && isError && !open && <p className="text-xs text-destructive">Nomor ini tidak ada di data Accurate pilih dari daftar.</p>}
      {!compact && data && !open && <DocSummary doc={data} />}
    </div>
  )
}
