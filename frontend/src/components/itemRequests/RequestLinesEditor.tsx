import { useRef } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { LineImagesInput } from '@/components/itemRequests/RequestImages'

export interface DraftLine {
  key: string
  deskripsi_barang: string
  qty: string
  satuan: string
  note: string
  /** up to 3 images of this item (data URLs) */
  images: string[]
}

let seq = 0
export const emptyLine = (): DraftLine => ({ key: `l${++seq}`, deskripsi_barang: '', qty: '', satuan: '', note: '', images: [] })

/** Lines filled in by the requester; the ones left completely empty are ignored. */
export function filledLines(lines: DraftLine[]) {
  return lines.filter((l) => l.deskripsi_barang.trim() || l.qty.trim())
}

export function linesError(lines: DraftLine[]): string | null {
  const filled = filledLines(lines)
  if (filled.length === 0) return 'Isi minimal satu barang.'
  const bad = filled.findIndex((l) => !l.deskripsi_barang.trim() || !(Number(l.qty) > 0))
  return bad >= 0 ? `Barang ke-${bad + 1}: isi nama barang dan jumlah (lebih dari 0).` : null
}

export const toPayloadLines = (lines: DraftLine[]) => filledLines(lines).map((l) => ({
  deskripsi_barang: l.deskripsi_barang.trim(),
  qty: Number(l.qty),
  satuan: l.satuan.trim() || null,
  note: l.note.trim() || null,
  images: l.images,
}))

/** Units people usually type; any other is fine too. */
const UNITS = ['PCS', 'UNIT', 'SET', 'BOX', 'PACK', 'ROLL', 'LEMBAR', 'RIM', 'BOTOL', 'KALENG', 'METER', 'LITER', 'KG', 'PASANG', 'LUSIN']
const MAX = 30

/**
 * The items of a request, typed freely (the requester doesn't have to know the warehouse's
 * item names; admin gudang matches them to the stock later). A line table like an ERP order:
 * Enter on a line goes to the next one (adding it when it is the last).
 */
export function RequestLinesEditor({ lines, onChange }: { lines: DraftLine[]; onChange: (lines: DraftLine[]) => void }) {
  const root = useRef<HTMLDivElement>(null)
  const set = (key: string, field: keyof DraftLine, value: string | string[]) => onChange(lines.map((l) => (l.key === key ? { ...l, [field]: value } : l)))
  const remove = (key: string) => onChange(lines.length > 1 ? lines.filter((x) => x.key !== key) : [emptyLine()])
  const focusName = (i: number) => setTimeout(() => root.current?.querySelectorAll<HTMLInputElement>('[data-name]')[i]?.focus(), 0)
  const add = () => { if (lines.length < MAX) { onChange([...lines, emptyLine()]); focusName(lines.length) } }
  const onEnter = (i: number) => (e: React.KeyboardEvent) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    if (i < lines.length - 1) focusName(i + 1)
    else add()
  }
  const count = filledLines(lines).length

  return (
    <div ref={root} className="overflow-hidden rounded-lg border bg-card">
      <div className="flex items-center justify-between border-b bg-muted/40 px-3 py-2">
        <span className="text-sm font-semibold">Barang yang diminta</span>
        <span className="text-xs text-muted-foreground">{count} barang</span>
      </div>
      <datalist id="rq-units">{UNITS.map((u) => <option key={u} value={u} />)}</datalist>

      {/* header (wider screens) */}
      <div className="hidden grid-cols-[2rem_minmax(0,1.4fr)_6rem_6.5rem_minmax(0,0.8fr)_7.75rem_2.25rem] gap-2 border-b px-3 py-2 text-xs font-medium uppercase tracking-wide text-muted-foreground sm:grid">
        <span>#</span><span>Nama barang *</span><span className="text-right">Jumlah *</span><span>Satuan</span><span>Catatan</span><span title="Opsional, maksimal 3 per barang">Gambar (maks 3)</span><span />
      </div>

      <ul className="divide-y">
        {lines.map((l, i) => (
          <li key={l.key} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2.25rem] gap-2 px-3 py-2.5 sm:grid-cols-[2rem_minmax(0,1.4fr)_6rem_6.5rem_minmax(0,0.8fr)_7.75rem_2.25rem] sm:items-center">
            <span className="hidden text-sm tabular-nums text-muted-foreground sm:block">{i + 1}</span>
            <Input data-name aria-label={`Nama barang ${i + 1}`} className="col-span-3 sm:col-span-1" placeholder={i === 0 ? 'mis. mata gerinda potong besi 4 inch' : 'Nama barang'}
              value={l.deskripsi_barang} maxLength={400} onKeyDown={onEnter(i)} onChange={(e) => set(l.key, 'deskripsi_barang', e.target.value)} />
            <Input aria-label={`Jumlah ${i + 1}`} className="text-right tabular-nums" type="number" inputMode="decimal" min={0} step="any" placeholder="Jumlah"
              value={l.qty} onKeyDown={onEnter(i)} onChange={(e) => set(l.key, 'qty', e.target.value)} />
            <Input aria-label={`Satuan ${i + 1}`} list="rq-units" placeholder="PCS" value={l.satuan} maxLength={30}
              onKeyDown={onEnter(i)} onChange={(e) => set(l.key, 'satuan', e.target.value.toUpperCase())} />
            <Input aria-label={`Catatan ${i + 1}`} className="order-last col-span-3 sm:order-none sm:col-span-1" placeholder="Catatan (opsional)" value={l.note}
              maxLength={255} onKeyDown={onEnter(i)} onChange={(e) => set(l.key, 'note', e.target.value)} />
            {/* the images of this item, on its own row: rightmost (on a phone under the note) */}
            <div className="order-last col-span-3 flex items-center gap-2 sm:order-none sm:col-span-1">
              <span className="text-xs text-muted-foreground sm:hidden">Gambar (maks 3)</span>
              <LineImagesInput value={l.images} onChange={(v) => set(l.key, 'images', v)} label={l.deskripsi_barang || `barang ${i + 1}`} />
            </div>
            <button type="button" className="flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
              aria-label={`Hapus barang ${i + 1}`} title="Hapus baris" onClick={() => remove(l.key)}>
              <Trash2 className="size-4" />
            </button>
          </li>
        ))}
      </ul>

      <div className="flex items-center justify-between border-t px-3 py-2">
        <button type="button" onClick={add} disabled={lines.length >= MAX}
          className="inline-flex items-center gap-1.5 rounded-md px-1 py-1 text-sm font-medium text-primary hover:underline disabled:opacity-50">
          <Plus className="size-4" /> Tambah baris
        </button>
        <span className="hidden text-xs text-muted-foreground sm:inline">Tekan Enter untuk lanjut ke baris berikutnya</span>
      </div>
    </div>
  )
}
