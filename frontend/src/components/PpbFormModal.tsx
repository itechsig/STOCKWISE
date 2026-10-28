import { useEffect, useState } from 'react'
import { CheckCircle2, Printer } from 'lucide-react'
import { useCreatePpb, type Ppb, type PpbCreatePayload } from '@/features/ppb/api'
import { useItemLookup, type ItemLookupResult } from '@/features/inventory/api'
import { saveOnce } from '@/lib/duplicate'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { PrintDocButton } from '@/components/PrintDocButton'

interface Props {
  open: boolean
  onClose: () => void
}

/** Dropdown saran barang bersama — dirender di bawah Kode Barang atau Deskripsi Barang, siapa pun yang sedang fokus. */
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

/**
 * Form "Tambah PPB" — untuk permintaan pembelian yang dicatat manual di
 * Stockwise, bukan hasil Sync Accurate. Kode Barang & Deskripsi Barang saling
 * mengisi otomatis (ketik salah satu, pilih dari saran, keduanya terisi) —
 * kecuali saat "Barang belum ada di Master Barang" dicentang, lalu keduanya
 * jadi isian bebas tanpa pencarian.
 */
export function PpbFormModal({ open, onClose }: Props) {
  const create = useCreatePpb()

  const [noPpb, setNoPpb] = useState('')
  const [tglPpb, setTglPpb] = useState('')
  const [tglDiperlukan, setTglDiperlukan] = useState('')
  const [divisi, setDivisi] = useState('')
  const [itemBaru, setItemBaru] = useState(false)
  const [kodeBarang, setKodeBarang] = useState('')
  const [deskripsiBarang, setDeskripsiBarang] = useState('')
  const [activeField, setActiveField] = useState<'kode' | 'deskripsi' | null>(null)
  const [kuantitas, setKuantitas] = useState<number | ''>('')
  const [satuan, setSatuan] = useState('')
  const [peminta, setPeminta] = useState('')
  const [catatan, setCatatan] = useState('')
  const [keterangan, setKeterangan] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [createdDoc, setCreatedDoc] = useState<Ppb | null>(null)
  // success screen -> print preview (replaces it, never stacked on / inside it)
  const [printing, setPrinting] = useState(false)

  const lookupQuery = !itemBaru && activeField === 'kode' ? kodeBarang : !itemBaru && activeField === 'deskripsi' ? deskripsiBarang : ''
  const { data: suggestions, isFetching: searchingItems } = useItemLookup(lookupQuery)

  useEffect(() => {
    if (!open) return
    setNoPpb(''); setTglPpb(new Date().toISOString().slice(0, 10)); setTglDiperlukan(''); setDivisi('')
    setItemBaru(false); setKodeBarang(''); setDeskripsiBarang(''); setActiveField(null)
    setKuantitas(''); setSatuan(''); setPeminta(''); setCatatan(''); setKeterangan(''); setErr(null); setCreatedDoc(null); setPrinting(false)
  }, [open])

  function pickItem(item: ItemLookupResult) {
    setKodeBarang(item.code)
    setDeskripsiBarang(item.description)
    if (item.unit && !satuan) setSatuan(item.unit)
    setActiveField(null)
  }

  function submit() {
    setErr(null)
    if (!noPpb.trim() || !tglPpb || !deskripsiBarang.trim() || !kuantitas) {
      setErr('No PPB, Tanggal PPB, Deskripsi Barang, dan Kuantitas wajib diisi.')
      return
    }
    const payload: PpbCreatePayload = {
      no_ppb: noPpb.trim(),
      tgl_ppb: tglPpb,
      tgl_diperlukan: tglDiperlukan || null,
      divisi: divisi.trim() || null,
      kode_barang: kodeBarang.trim() || null,
      deskripsi_barang: deskripsiBarang.trim(),
      kuantitas: Number(kuantitas),
      satuan: satuan.trim() || null,
      peminta: peminta.trim() || null,
      catatan_baris: catatan.trim() || null,
      keterangan: keterangan.trim() || null,
    }
    // never the same document twice (two admins, one request): the server asks first
    saveOnce(create.mutate, payload, setCreatedDoc, setErr)
  }

  if (createdDoc && printing && open) {
    return <PrintDocButton url={`/api/ppb/${createdDoc.id}/print`} docType="ppb" autoOpen hideTrigger onClosePreview={onClose} />
  }

  if (createdDoc) {
    return (
      <Modal
        open={open}
        onClose={onClose}
        title="PPB Tersimpan"
        description={`No. PPB: ${createdDoc.no_ppb}`}
        className="max-w-md"
        footer={<Button size="sm" onClick={onClose}>Selesai</Button>}
      >
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          <CheckCircle2 className="size-10 text-emerald-600" />
          <p className="text-sm text-muted-foreground">
            PPB berhasil disimpan. Cetak sekarang untuk dicetak di kertas khusus PPB.
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
      title="Tambah PPB"
      description="PPB manual — dicatat langsung di Stockwise, bukan dari Sync Accurate."
      className="max-w-2xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
          <Button size="sm" disabled={create.isPending} onClick={submit}>
            {create.isPending ? 'Menyimpan…' : 'Simpan'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>No PPB</Label>
            <Input className="font-mono" value={noPpb} onChange={(e) => setNoPpb(e.target.value)} placeholder="mis. PPB/GDG/26/X/001" />
          </div>
          <div className="space-y-1.5">
            <Label>Divisi Peminta</Label>
            <Input value={divisi} onChange={(e) => setDivisi(e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Tanggal PPB</Label>
            <Input type="date" value={tglPpb} onChange={(e) => setTglPpb(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label>Tanggal Diperlukan</Label>
            <Input type="date" value={tglDiperlukan} min={tglPpb || undefined} onChange={(e) => setTglDiperlukan(e.target.value)} />
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={itemBaru} onChange={(e) => { setItemBaru(e.target.checked); setActiveField(null) }} />
          Barang belum ada di Master Barang (isi Kode &amp; Deskripsi Barang manual)
        </label>

        <div className="grid grid-cols-2 gap-3">
          <div className="relative space-y-1.5">
            <Label>Kode Barang</Label>
            <Input
              className="font-mono"
              value={kodeBarang}
              onChange={(e) => setKodeBarang(e.target.value)}
              onFocus={() => !itemBaru && setActiveField('kode')}
              onBlur={() => setTimeout(() => setActiveField((f) => (f === 'kode' ? null : f)), 150)}
              placeholder={itemBaru ? 'Kode barang baru (bebas)' : 'mis. PUI.0019'}
            />
            {!itemBaru && activeField === 'kode' && kodeBarang.trim().length >= 2 && (
              <ItemSuggestions items={suggestions} loading={searchingItems} onPick={pickItem} />
            )}
          </div>
          <div className="relative space-y-1.5">
            <Label>Deskripsi Barang</Label>
            <Input
              value={deskripsiBarang}
              onChange={(e) => setDeskripsiBarang(e.target.value)}
              onFocus={() => !itemBaru && setActiveField('deskripsi')}
              onBlur={() => setTimeout(() => setActiveField((f) => (f === 'deskripsi' ? null : f)), 150)}
            />
            {!itemBaru && activeField === 'deskripsi' && deskripsiBarang.trim().length >= 2 && (
              <ItemSuggestions items={suggestions} loading={searchingItems} onPick={pickItem} />
            )}
          </div>
        </div>

        <div className="grid grid-cols-3 gap-3">
          <div className="space-y-1.5">
            <Label>Kuantitas</Label>
            <Input type="number" min={0} step="0.01" value={kuantitas} onChange={(e) => setKuantitas(e.target.value ? Number(e.target.value) : '')} />
          </div>
          <div className="space-y-1.5">
            <Label>Satuan</Label>
            <Input value={satuan} onChange={(e) => setSatuan(e.target.value)} placeholder="PCS, SET, …" />
          </div>
          <div className="space-y-1.5">
            <Label>Peminta</Label>
            <Input value={peminta} onChange={(e) => setPeminta(e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Catatan</Label>
            <Input value={catatan} onChange={(e) => setCatatan(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label>Keterangan</Label>
            <Input value={keterangan} onChange={(e) => setKeterangan(e.target.value)} />
          </div>
        </div>

        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
