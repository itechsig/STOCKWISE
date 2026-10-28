import { useEffect, useState } from 'react'
import { CheckCircle2, Plus, Trash2, Printer } from 'lucide-react'
import { useCreatePo, useSites, useVendors, type PoCreateLine, type PurchaseOrder } from '@/features/purchasing/api'
import { useItemLookup, useUnits, type ItemLookupResult } from '@/features/inventory/api'
import { saveOnce } from '@/lib/duplicate'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { PrintDocButton } from '@/components/PrintDocButton'

interface Props {
  open: boolean
  onClose: () => void
}

interface RowState {
  key: number
  itemBaru: boolean
  itemId: number | null
  kodeBarang: string
  deskripsiBarang: string
  unitId: number | ''
  qty: number | ''
  unitPrice: number | ''
}

let rowSeq = 0
function emptyRow(): RowState {
  return { key: ++rowSeq, itemBaru: false, itemId: null, kodeBarang: '', deskripsiBarang: '', unitId: '', qty: '', unitPrice: '' }
}

function ItemSuggestions({ items, loading, onPick }: { items: ItemLookupResult[] | undefined; loading: boolean; onPick: (item: ItemLookupResult) => void }) {
  return (
    <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-card text-sm shadow-md">
      {loading && <li className="px-3 py-2 text-muted-foreground">Mencari…</li>}
      {!loading && (items?.length ?? 0) === 0 && <li className="px-3 py-2 text-muted-foreground">Tidak ada hasil.</li>}
      {items?.map((it) => (
        <li key={it.id}>
          <button
            type="button"
            className="block w-full px-3 py-2 text-left hover:bg-muted"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => onPick(it)}
          >
            <span className="font-mono text-xs text-muted-foreground">{it.code}</span> {it.description}
          </button>
        </li>
      ))}
    </ul>
  )
}

/** Satu baris barang PO — kode/deskripsi saling mengisi lewat pencarian Master Barang, kecuali "Barang Baru" dicentang (lalu keduanya isian bebas + pilih satuan manual). */
function PoItemRow({ row, onChange, onRemove, canRemove, units }: {
  row: RowState
  onChange: (next: RowState) => void
  onRemove: () => void
  canRemove: boolean
  units: { id: number; code: string; name: string }[] | undefined
}) {
  const [activeField, setActiveField] = useState<'kode' | 'deskripsi' | null>(null)
  const lookupQuery = !row.itemBaru && activeField === 'kode' ? row.kodeBarang : !row.itemBaru && activeField === 'deskripsi' ? row.deskripsiBarang : ''
  const { data: suggestions, isFetching: searching } = useItemLookup(lookupQuery)

  function pickItem(item: ItemLookupResult) {
    onChange({ ...row, itemId: item.id, kodeBarang: item.code, deskripsiBarang: item.description, unitId: item.unit_id ?? row.unitId })
    setActiveField(null)
  }

  const lineTotal = (Number(row.qty) || 0) * (Number(row.unitPrice) || 0)

  return (
    <div className="space-y-2 rounded-md border p-3">
      <div className="flex items-center justify-between">
        <label className="flex items-center gap-2 text-xs">
          <input
            type="checkbox"
            checked={row.itemBaru}
            onChange={(e) => onChange({ ...row, itemBaru: e.target.checked, itemId: null })}
          />
          Barang belum ada di Master Barang (isi manual)
        </label>
        {canRemove && (
          <button type="button" className="text-muted-foreground hover:text-destructive" onClick={onRemove} aria-label="Hapus baris">
            <Trash2 className="size-4" />
          </button>
        )}
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div className="relative space-y-1">
          <Label className="text-xs">Kode Barang</Label>
          <Input
            className="font-mono"
            value={row.kodeBarang}
            onChange={(e) => onChange({ ...row, kodeBarang: e.target.value, itemId: row.itemBaru ? row.itemId : null })}
            onFocus={() => !row.itemBaru && setActiveField('kode')}
            onBlur={() => setTimeout(() => setActiveField((f) => (f === 'kode' ? null : f)), 150)}
            placeholder={row.itemBaru ? 'Kode barang baru (bebas)' : 'mis. PUI.0019'}
          />
          {!row.itemBaru && activeField === 'kode' && row.kodeBarang.trim().length >= 2 && (
            <ItemSuggestions items={suggestions} loading={searching} onPick={pickItem} />
          )}
        </div>
        <div className="relative space-y-1">
          <Label className="text-xs">Deskripsi Barang</Label>
          <Input
            value={row.deskripsiBarang}
            onChange={(e) => onChange({ ...row, deskripsiBarang: e.target.value, itemId: row.itemBaru ? row.itemId : null })}
            onFocus={() => !row.itemBaru && setActiveField('deskripsi')}
            onBlur={() => setTimeout(() => setActiveField((f) => (f === 'deskripsi' ? null : f)), 150)}
          />
          {!row.itemBaru && activeField === 'deskripsi' && row.deskripsiBarang.trim().length >= 2 && (
            <ItemSuggestions items={suggestions} loading={searching} onPick={pickItem} />
          )}
        </div>
      </div>

      <div className="grid grid-cols-4 gap-2">
        <div className="space-y-1">
          <Label className="text-xs">Qty</Label>
          <Input type="number" min={0} step="0.01" value={row.qty} onChange={(e) => onChange({ ...row, qty: e.target.value ? Number(e.target.value) : '' })} />
        </div>
        <div className="space-y-1">
          <Label className="text-xs">Satuan</Label>
          {row.itemBaru ? (
            <Select value={row.unitId} onChange={(e) => onChange({ ...row, unitId: e.target.value ? Number(e.target.value) : '' })}>
              <option value="">— pilih —</option>
              {units?.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
            </Select>
          ) : (
            <Input value={units?.find((u) => u.id === row.unitId)?.code ?? ''} disabled placeholder="otomatis dari barang" />
          )}
        </div>
        <div className="space-y-1">
          <Label className="text-xs">Harga Satuan</Label>
          <Input type="number" min={0} value={row.unitPrice} onChange={(e) => onChange({ ...row, unitPrice: e.target.value ? Number(e.target.value) : '' })} />
        </div>
        <div className="space-y-1">
          <Label className="text-xs">Total</Label>
          <Input value={`Rp ${lineTotal.toLocaleString('id-ID')}`} disabled />
        </div>
      </div>
    </div>
  )
}

/**
 * Form "Tambah PO" — PO manual (DRAFT) untuk pembelian yang tidak lewat
 * Accurate. Mengikuti struktur asli purchase_orders: pilih Vendor + Site,
 * lalu beberapa baris barang dengan harga satuan masing-masing.
 */
export function PoFormModal({ open, onClose }: Props) {
  const create = useCreatePo()
  const { data: vendors } = useVendors()
  const { data: sites } = useSites()
  const { data: units } = useUnits()

  const [vendorId, setVendorId] = useState<number | ''>('')
  const [siteId, setSiteId] = useState<number | ''>('')
  const [date, setDate] = useState('')
  const [expectedDate, setExpectedDate] = useState('')
  const [notes, setNotes] = useState('')
  const [rows, setRows] = useState<RowState[]>([emptyRow()])
  const [err, setErr] = useState<string | null>(null)
  const [createdDoc, setCreatedDoc] = useState<PurchaseOrder | null>(null)
  // success screen -> print preview (replaces it, never stacked on / inside it)
  const [printing, setPrinting] = useState(false)

  useEffect(() => {
    if (!open) return
    setVendorId(''); setSiteId(''); setDate(new Date().toISOString().slice(0, 10)); setExpectedDate('')
    setNotes(''); setRows([emptyRow()]); setErr(null); setCreatedDoc(null); setPrinting(false)
  }, [open])

  function updateRow(key: number, next: RowState) {
    setRows((rs) => rs.map((r) => (r.key === key ? next : r)))
  }

  const grandTotal = rows.reduce((sum, r) => sum + (Number(r.qty) || 0) * (Number(r.unitPrice) || 0), 0)

  function submit() {
    setErr(null)
    if (!vendorId || !siteId || !date) {
      setErr('Vendor, Site, dan Tanggal PO wajib diisi.')
      return
    }
    const items: PoCreateLine[] = []
    for (const r of rows) {
      if (!r.deskripsiBarang.trim() || !r.qty) {
        setErr('Setiap baris barang wajib punya Deskripsi Barang dan Qty.')
        return
      }
      items.push({
        item_id: r.itemBaru ? null : r.itemId,
        description_raw: r.deskripsiBarang.trim(),
        qty: Number(r.qty),
        unit_id: r.unitId || null,
        unit_price: Number(r.unitPrice) || 0,
      })
    }
    if (items.length === 0) {
      setErr('Minimal satu baris barang.')
      return
    }
    // never the same PO twice (two admins, one need): the server asks first
    saveOnce(create.mutate,
      { vendor_id: Number(vendorId), site_id: Number(siteId), date, expected_date: expectedDate || null, notes: notes.trim() || null, items },
      setCreatedDoc, setErr)
  }

  if (createdDoc && printing && open) {
    return <PrintDocButton url={`/api/purchase-orders/${createdDoc.id}/print`} docType="po" autoOpen hideTrigger onClosePreview={onClose} />
  }

  if (createdDoc) {
    return (
      <Modal
        open={open}
        onClose={onClose}
        title="PO Tersimpan"
        description={`No. PO: ${createdDoc.number}`}
        className="max-w-md"
        footer={<Button size="sm" onClick={onClose}>Selesai</Button>}
      >
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          <CheckCircle2 className="size-10 text-emerald-600" />
          <p className="text-sm text-muted-foreground">
            PO berhasil disimpan sebagai DRAFT. Cetak sekarang untuk dicetak di kertas khusus PO.
          </p>
          <Button size="sm" variant="outline" onClick={() => setPrinting(true)}>
            <Printer className="size-4" /> Cetak
          </Button>
        </div>
      </Modal>
    )
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tambah PO"
      description="PO manual (DRAFT) — dicatat langsung di Stockwise, bukan dari Sync Accurate."
      className="max-w-3xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
          <Button size="sm" disabled={create.isPending} onClick={submit}>
            {create.isPending ? 'Menyimpan…' : 'Simpan sebagai DRAFT'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Vendor</Label>
            <Select value={vendorId} onChange={(e) => setVendorId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">— pilih vendor —</option>
              {vendors?.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>Site</Label>
            <Select value={siteId} onChange={(e) => setSiteId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">— pilih site —</option>
              {sites?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Tanggal PO</Label>
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label>Expected Date</Label>
            <Input type="date" value={expectedDate} min={date || undefined} onChange={(e) => setExpectedDate(e.target.value)} />
          </div>
        </div>

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label>Barang</Label>
            <Button type="button" variant="outline" size="sm" onClick={() => setRows((rs) => [...rs, emptyRow()])}>
              <Plus className="size-4" /> Tambah Baris
            </Button>
          </div>
          <div className="space-y-2">
            {rows.map((row) => (
              <PoItemRow
                key={row.key}
                row={row}
                units={units}
                canRemove={rows.length > 1}
                onChange={(next) => updateRow(row.key, next)}
                onRemove={() => setRows((rs) => rs.filter((r) => r.key !== row.key))}
              />
            ))}
          </div>
          <p className="text-right text-sm font-medium">Total: Rp {grandTotal.toLocaleString('id-ID')}</p>
        </div>

        <div className="space-y-1.5">
          <Label>Catatan</Label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
