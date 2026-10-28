import { useEffect, useState } from 'react'
import { useCreateItem, useItemOptions, useUpdateItem, useWarehouses, useWarehouseLocations } from '@/features/inventory/api'
import { apiErrorMessage } from '@/lib/api'
import { invalidTo } from '@/lib/notify'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import type { Item } from '@/types/inventory'

interface Props {
  open: boolean
  onClose: () => void
  item?: Item | null // null/undefined = mode tambah baru
  /** tambah baru: isi awal (mis. dari kata-kata peminta di Request Barang) */
  initial?: { description?: string; kind?: 'BARANG' | 'JASA' }
  /** tambah baru: barang yang baru dibuat (Request Barang langsung memilihnya) */
  onCreated?: (item: Item) => void
}

type CatField = 'induk' | 'anak_1' | 'anak_2' | 'anak_3'
const CAT_FIELDS: [CatField, string][] = [['induk', 'Kategori Induk'], ['anak_1', 'Kategori Anak 1'], ['anak_2', 'Kategori Anak 2'], ['anak_3', 'Kategori Anak 3']]
const NO_CATS: Record<CatField, string> = { induk: '', anak_1: '', anak_2: '', anak_3: '' }

/**
 * Form tambah/ubah Master Barang — kolomnya mengikuti DATA.xlsx DATABASE UTAMA
 * (Kode Barang, Kategori Induk/Anak 1/2/3, Deskripsi, UOM, Perlu Blueprint?,
 * LETAK GUDANG, LETAK RAK, BLUEPRINT 3D VIEW, LEAD TIME).
 */
export function ItemFormModal({ open, onClose, item, initial, onCreated }: Props) {
  const isEdit = !!item
  const create = useCreateItem()
  const update = useUpdateItem(item?.id ?? 0)
  const { data: options } = useItemOptions()
  const { data: warehouses } = useWarehouses()

  const [code, setCode] = useState('')
  const [description, setDescription] = useState('')
  // Kategori Induk / Anak 1 / 2 / 3: each picked from its own fixed list
  const [cats, setCats] = useState<Record<CatField, string>>(NO_CATS)
  const [unitId, setUnitId] = useState<number | ''>('')
  const [needsBlueprint, setNeedsBlueprint] = useState(false)
  const [leadTime, setLeadTime] = useState<number | ''>('')
  const [warehouseId, setWarehouseId] = useState<number | ''>('')
  const [locationId, setLocationId] = useState<number | ''>('')
  const [blueprint3d, setBlueprint3d] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [kind, setKind] = useState<'BARANG' | 'JASA'>('BARANG')
  const [err, setErr] = useState<string | null>(null)

  const { data: locations } = useWarehouseLocations(warehouseId || null)

  useEffect(() => {
    if (!open) return
    setCode(item?.code ?? '')
    setDescription(item?.description ?? initial?.description ?? '')
    const b = item?.category_breakdown
    setCats({ induk: b?.induk ?? '', anak_1: b?.anak_1 ?? '', anak_2: b?.anak_2 ?? '', anak_3: b?.anak_3 ?? '' })
    setUnitId(item?.unit?.id ?? '')
    setNeedsBlueprint(item?.needs_blueprint ?? false)
    setLeadTime(item?.lead_time_days ?? '')
    setWarehouseId(item?.default_warehouse?.id ?? '')
    setLocationId(item?.default_location?.id ?? '')
    setBlueprint3d(item?.blueprint_3d_ref ?? '')
    setIsActive(item?.is_active ?? true)
    setKind(item?.product_kind ?? initial?.kind ?? 'BARANG')
    setErr(null)
  }, [open, item]) // eslint-disable-line react-hooks/exhaustive-deps

  const pending = create.isPending || update.isPending

  function submit() {
    setErr(null)
    if (!code.trim() || !description.trim()) {
      invalidTo(setErr, 'Kode dan Deskripsi Produk wajib diisi.')
      return
    }
    const payload = {
      code: code.trim(),
      description: description.trim(),
      accurate_category_induk: cats.induk || null,
      accurate_category_anak_1: cats.anak_1 || null,
      accurate_category_anak_2: cats.anak_2 || null,
      accurate_category_anak_3: cats.anak_3 || null,
      unit_id: unitId || null,
      needs_blueprint: needsBlueprint,
      lead_time_days: leadTime === '' ? null : Number(leadTime),
      default_warehouse_id: warehouseId || null,
      default_location_id: locationId || null,
      blueprint_3d_ref: blueprint3d.trim() || null,
      is_active: isActive,
      product_kind: kind,
    }
    const mutation = isEdit ? update.mutate(payload, { onSuccess: onClose, onError: (e) => setErr(apiErrorMessage(e)) })
      : create.mutate(payload, { onSuccess: (made) => { onCreated?.(made); onClose() }, onError: (e) => setErr(apiErrorMessage(e)) })
    return mutation
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? `Ubah Produk — ${item?.code}` : 'Tambah Produk Baru'}
      description="Master Produk: barang (punya stok) atau jasa (tanpa stok)."
      className="max-w-2xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Batal
          </Button>
          <Button size="sm" disabled={pending} onClick={submit}>
            {pending ? 'Menyimpan…' : 'Simpan'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label>Jenis produk</Label>
          <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Jenis produk">
            {([['BARANG', 'Barang', 'Punya stok di gudang'], ['JASA', 'Jasa', 'Layanan, tanpa stok']] as const).map(([k, label, hint]) => (
              <button key={k} type="button" role="radio" aria-checked={kind === k} onClick={() => setKind(k)}
                className={kind === k ? 'rounded-lg border border-primary bg-primary/5 p-2.5 text-left ring-1 ring-primary' : 'rounded-lg border bg-card p-2.5 text-left hover:bg-muted/50'}>
                <div className="text-sm font-semibold">{label}</div>
                <div className="text-xs text-muted-foreground">{hint}</div>
              </button>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Kode Produk</Label>
            <Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="mis. PUI.0019" />
          </div>
          <div className="space-y-1.5">
            <Label>UOM</Label>
            <Select value={unitId} onChange={(e) => setUnitId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">— pilih —</option>
              {/* an Accurate item may have a UOM outside the list: it stays selectable for that item */}
              {item?.unit && !options?.uom?.some((u) => u.id === item.unit!.id) && <option value={item.unit.id}>{item.unit.code}</option>}
              {options?.uom?.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.code} — {u.name}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label>Deskripsi Produk</Label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>

        <div className="grid grid-cols-2 gap-3">
          {CAT_FIELDS.map(([field, label]) => {
            const choices = options?.[field] ?? []
            const current = cats[field]
            return (
              <div key={field} className="space-y-1.5">
                <Label>{label}</Label>
                <Select value={current} onChange={(e) => setCats((c) => ({ ...c, [field]: e.target.value }))}>
                  <option value="">— pilih —</option>
                  {current && !choices.includes(current) && <option value={current}>{current}</option>}
                  {choices.map((o) => <option key={o} value={o}>{o}</option>)}
                </Select>
              </div>
            )
          })}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>LETAK GUDANG</Label>
            <Select
              value={warehouseId}
              onChange={(e) => {
                setWarehouseId(e.target.value ? Number(e.target.value) : '')
                setLocationId('')
              }}
            >
              <option value="">— pilih —</option>
              {warehouses?.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>LETAK RAK</Label>
            <Select value={locationId} onChange={(e) => setLocationId(e.target.value ? Number(e.target.value) : '')} disabled={!warehouseId}>
              <option value="">— pilih —</option>
              {locations?.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.code}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>LEAD TIME (hari)</Label>
            <Input type="number" min={0} value={leadTime} onChange={(e) => setLeadTime(e.target.value ? Number(e.target.value) : '')} />
          </div>
          <div className="space-y-1.5">
            <Label>BLUEPRINT 3D VIEW</Label>
            <Input value={blueprint3d} onChange={(e) => setBlueprint3d(e.target.value)} placeholder="ref / kode rak" />
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-5 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={needsBlueprint} onChange={(e) => setNeedsBlueprint(e.target.checked)} />
            Perlu Blueprint?
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
            Aktif
          </label>
        </div>

        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
