import { useEffect, useRef, useState } from 'react'
import { Camera, CheckCircle2, FileText, Loader2, Printer, Upload, X } from 'lucide-react'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { RI_SOURCE, useCreateRi, useCreateRiFromPo, useRiPoOrders, useRiUsedReturnLines, riJenis, type Ri, type RiCreatePayload, type RiFromPoPayload, type RiPoOrder, type RiSource, type RiUsedReturnLine } from '@/features/ri/api'
import { cn } from '@/lib/utils'
import { useItemLookup, type ItemLookupResult } from '@/features/inventory/api'
import { saveOnce } from '@/lib/duplicate'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { PrintDocButton } from '@/components/PrintDocButton'
import { invalidTo } from '@/lib/notify'

interface Props {
  open: boolean
  onClose: () => void
}

const JENIS_RI: Array<'NA' | 'ATK' | 'NV'> = ['NA', 'ATK', 'NV']

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
 * Form "Tambah RI" — untuk penerimaan barang yang dicatat manual di Stockwise,
 * bukan hasil Sync Accurate. Kode Barang & Deskripsi Barang saling mengisi
 * otomatis (ketik salah satu, pilih dari saran, keduanya terisi).
 */
/** Proof photos of the goods received, per RI. */
const MAX_PHOTOS = 3
/** documents of the delivery (surat jalan / invoice) of a RI dari PO */
const MAX_DOCS = 3
const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })

export function RiFormModal({ open, onClose }: Props) {
  const create = useCreateRi()

  const [divisi, setDivisi] = useState<'NA' | 'ATK' | 'NV'>('NV')
  const [vendor, setVendor] = useState('')
  const [noPo, setNoPo] = useState('')
  const [noSuratJalan, setNoSuratJalan] = useState('')
  const [kodeBarang, setKodeBarang] = useState('')
  const [deskripsiBarang, setDeskripsiBarang] = useState('')
  const [activeField, setActiveField] = useState<'kode' | 'deskripsi' | null>(null)
  const [kuantitas, setKuantitas] = useState<number | ''>('')
  const [satuan, setSatuan] = useState('')
  const [hargaSatuan, setHargaSatuan] = useState<number | ''>('')
  const [noSeri, setNoSeri] = useState('')
  const [pemeriksa, setPemeriksa] = useState('')
  const [tglRi, setTglRi] = useState('')
  const [keterangan, setKeterangan] = useState('')
  const [err, setErr] = useState<string | null>(null)
  // proof of the goods received: 1 to 3 photos (required)
  const [photos, setPhotos] = useState<string[]>([])
  const [reading, setReading] = useState(false)
  const photoInput = useRef<HTMLInputElement>(null)
  const [createdDoc, setCreatedDoc] = useState<Ri | null>(null)
  // goods of a STOCKWISE PO: its lines count them as received (part or all)
  const [poSearch, setPoSearch] = useState('')
  // "Dari PO": the picked PO, per line how much arrived and its serial numbers, and the documents
  const [poOrder, setPoOrder] = useState<RiPoOrder | null>(null)
  const [poQty, setPoQty] = useState<Record<number, string>>({})
  const [poSeri, setPoSeri] = useState<Record<number, string>>({})
  // RI dari PO: the proof per item (1 to 3 photos of each item received)
  const [poPhotos, setPoPhotos] = useState<Record<number, string[]>>({})
  const linePhotoInput = useRef<HTMLInputElement>(null)
  const [photoFor, setPhotoFor] = useState<number | null>(null)
  const [docs, setDocs] = useState<{ data: string; name: string }[]>([])
  const docInput = useRef<HTMLInputElement>(null)
  const createFromPo = useCreateRiFromPo()
  // where the goods come from: a PO, used goods back (Pengembalian Bekas), or manual (tied to nothing)
  const [source, setSource] = useState<RiSource>('PO')
  const poOrders = useRiPoOrders(poSearch, open && source === 'PO' && !poOrder)
  const [usedLine, setUsedLine] = useState<RiUsedReturnLine | null>(null)
  const [usedSearch, setUsedSearch] = useState('')
  const usedLines = useRiUsedReturnLines(usedSearch, open && source === 'BEKAS' && !usedLine)
  // success screen -> print preview (replaces it, never stacked on / inside it)
  const [printing, setPrinting] = useState(false)

  const lookupQuery = activeField === 'kode' ? kodeBarang : activeField === 'deskripsi' ? deskripsiBarang : ''
  const { data: suggestions, isFetching: searchingItems } = useItemLookup(lookupQuery)

  useEffect(() => {
    if (!open) return
    setDivisi('NV'); setVendor(''); setNoPo(''); setNoSuratJalan('')
    setKodeBarang(''); setDeskripsiBarang(''); setActiveField(null)
    setKuantitas(''); setSatuan(''); setHargaSatuan(''); setNoSeri(''); setPemeriksa('')
    setTglRi(new Date().toISOString().slice(0, 10)); setKeterangan(''); setErr(null); setCreatedDoc(null); setPrinting(false)
    setPoSearch(''); setPhotos([]); setSource('PO'); setUsedLine(null); setUsedSearch('')
    setPoOrder(null); setPoQty({}); setPoSeri({}); setDocs([]); setPoPhotos({})
  }, [open])

  function pickSource(s: RiSource) {
    if (s === source) return
    setSource(s); setErr(null)
    // what a picked PO line / used goods filled in goes with it
    setPoOrder(null); setPoQty({}); setPoSeri({})
    if (usedLine) {
      setUsedLine(null)
      setNoPo(''); setVendor(''); setKodeBarang(''); setDeskripsiBarang(''); setSatuan(''); setHargaSatuan(''); setKuantitas(''); setKeterangan('')
    }
    if (s === 'BEKAS') setDivisi('NV')
  }

  function pickUsedLine(l: RiUsedReturnLine) {
    setUsedLine(l)
    setKodeBarang(l.kode_barang ?? ''); setDeskripsiBarang(l.deskripsi ?? ''); setSatuan(l.satuan ?? '')
    setKuantitas(l.qty ?? ''); setKeterangan(`Pengembalian bekas NPBG ${l.npbg_no}`); setDivisi('NV')
  }

  async function addPhotos(files: FileList | null) {
    if (!files?.length) return
    setErr(null)
    const room = MAX_PHOTOS - photos.length
    if (files.length > room) setErr(`Foto bukti maksimal ${MAX_PHOTOS}. ${room > 0 ? `Hanya ${room} foto pertama yang dipakai.` : ''}`)
    setReading(true)
    try {
      const made = await Promise.all([...files].slice(0, Math.max(0, room)).map((f) => photoToDataUrl(f, 1600)))
      setPhotos((p) => [...p, ...made].slice(0, MAX_PHOTOS))
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setReading(false)
    }
  }

  function pickItem(item: ItemLookupResult) {
    setKodeBarang(item.code)
    setDeskripsiBarang(item.description)
    if (item.unit && !satuan) setSatuan(item.unit)
    setActiveField(null)
  }

  function pickPoOrder(o: RiPoOrder) {
    setPoOrder(o); setPoQty({}); setPoSeri({}); setPoPhotos({}); setDivisi('NA')
  }

  // photos of one PO item (the camera / gallery opened from its row)
  async function addLinePhotos(lineId: number, files: FileList | null) {
    if (!files?.length) return
    setErr(null)
    const room = MAX_PHOTOS - (poPhotos[lineId]?.length ?? 0)
    setReading(true)
    try {
      const made = await Promise.all([...files].slice(0, Math.max(0, room)).map((f) => photoToDataUrl(f, 1600)))
      setPoPhotos((m) => ({ ...m, [lineId]: [...(m[lineId] ?? []), ...made].slice(0, MAX_PHOTOS) }))
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setReading(false)
    }
  }

  // documents of the delivery (surat jalan / invoice): a photo or a PDF, up to MAX_DOCS
  async function addDocs(files: FileList | null) {
    if (!files?.length) return
    setErr(null)
    const room = MAX_DOCS - docs.length
    const picked = [...files].slice(0, Math.max(0, room))
    const big = picked.find((f) => f.size > 10 * 1024 * 1024)
    if (big) return invalidTo(setErr, `${big.name} lebih dari 10 MB.`)
    setReading(true)
    try {
      const made = await Promise.all(picked.map(async (f) => ({
        name: f.name,
        data: f.type.startsWith('image/') ? await photoToDataUrl(f, 2000) : await new Promise<string>((ok, fail) => {
          const r = new FileReader(); r.onload = () => ok(String(r.result)); r.onerror = () => fail(new Error('File tidak terbaca.')); r.readAsDataURL(f)
        }),
      })))
      setDocs((d) => [...d, ...made].slice(0, MAX_DOCS))
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setReading(false)
    }
  }

  function submitFromPo() {
    if (!poOrder) return invalidTo(setErr, 'Cari dan pilih PO-nya dulu, atau ganti sumber ke Manual.')
    const lines = poOrder.lines.flatMap((l) => {
      const n = Number((poQty[l.id] ?? '').replace(',', '.'))
      return poQty[l.id] && n > 0 ? [{ purchase_order_item_id: l.id, qty: n, no_seri: poSeri[l.id]?.trim() || null, photos: poPhotos[l.id] ?? [] }] : []
    })
    if (Object.values(poQty).some((v) => v.trim() !== '' && !(Number(v.replace(',', '.')) >= 0))) return invalidTo(setErr, 'Jumlah diterima harus angka.')
    if (lines.length === 0) return invalidTo(setErr, 'Isi jumlah yang diterima minimal untuk satu barang.')
    // the proof is per item
    const noPhoto = lines.find((l) => l.photos.length === 0)
    if (noPhoto) return invalidTo(setErr, `Upload foto bukti untuk ${poOrder.lines.find((l) => l.id === noPhoto.purchase_order_item_id)?.deskripsi ?? 'barang yang diterima'}.`)
    const payload: RiFromPoPayload = {
      purchase_order_id: poOrder.id, divisi, no_surat_jalan: noSuratJalan.trim() || null, pemeriksa: pemeriksa.trim() || null,
      tgl_ri: tglRi || null, keterangan: keterangan.trim() || null, lines, documents: docs,
    }
    // never the same delivery twice (two admins, one delivery): the server asks first
    saveOnce(createFromPo.mutate, payload, setCreatedDoc, setErr)
  }

  function submit() {
    setErr(null)
    if (source === 'PO') return submitFromPo()
    if (source === 'BEKAS' && !usedLine) {
      invalidTo(setErr, 'Pilih barang bekas yang dikembalikan.')
      return
    }
    if (!deskripsiBarang.trim() || !kuantitas) {
      invalidTo(setErr, 'Deskripsi Barang dan Kuantitas wajib diisi.')
      return
    }
    if (photos.length === 0) {
      invalidTo(setErr, 'Upload minimal 1 foto bukti penerimaan barang.')
      return
    }
    const payload: RiCreatePayload = {
      divisi,
      vendor: vendor.trim() || null,
      no_po: noPo.trim() || null,
      no_surat_jalan: noSuratJalan.trim() || null,
      kode_barang: kodeBarang.trim() || null,
      deskripsi_barang: deskripsiBarang.trim(),
      kuantitas: Number(kuantitas),
      satuan: satuan.trim() || null,
      harga_satuan: hargaSatuan === '' ? null : Number(hargaSatuan),
      no_seri: noSeri.trim() || null,
      pemeriksa: pemeriksa.trim() || null,
      tgl_ri: tglRi || null,
      keterangan: keterangan.trim() || null,
      sumber: source,
      used_return_row_id: source === 'BEKAS' ? usedLine?.id ?? null : null,
      photos,
    }
    // never the same document twice (two admins, one request): the server asks first
    saveOnce(create.mutate, payload, setCreatedDoc, setErr)
  }

  if (createdDoc && printing && open) {
    return <PrintDocButton url={`/api/ri/${createdDoc.id}/print`} docType="ri" autoOpen hideTrigger onClosePreview={onClose} />
  }

  if (createdDoc) {
    return (
      <Modal
        open={open}
        onClose={onClose}
        title="RI Tersimpan"
        description={`No. RI: ${createdDoc.no_ri}`}
        className="max-w-md"
        footer={<Button size="sm" onClick={onClose}>Selesai</Button>}
      >
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          <CheckCircle2 className="size-10 text-emerald-600" />
          <p className="text-sm text-muted-foreground">
            RI berhasil disimpan. Cetak sekarang untuk dicetak di kertas khusus RI.
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
      title="Tambah RI"
      description="Penerimaan barang dicatat langsung di Stockwise: dari PO, pengembalian bekas, atau manual (barang apa saja)."
      // "Dari PO" holds the PO's whole item table: a wide popup
      className={source === 'PO' && poOrder ? 'max-w-6xl' : 'max-w-2xl'}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
          <Button size="sm" disabled={create.isPending || createFromPo.isPending || reading} onClick={submit}>
            {create.isPending || createFromPo.isPending ? 'Menyimpan…' : 'Simpan'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label>Sumber RI</Label>
          <div className="grid grid-cols-3 gap-1 rounded-lg border bg-muted/40 p-1" role="tablist" aria-label="Sumber RI">
            {(['PO', 'BEKAS', 'MANUAL'] as RiSource[]).map((s) => (
              <button key={s} type="button" role="tab" aria-selected={source === s} onClick={() => pickSource(s)}
                className={cn('rounded-md px-2 py-1.5 text-sm font-medium transition-colors', source === s ? 'bg-card text-primary shadow-sm ring-1 ring-border' : 'text-muted-foreground hover:text-foreground')}>
                {RI_SOURCE[s]}
              </button>
            ))}
          </div>
          <p className="text-xs text-muted-foreground">
            {source === 'PO' ? 'Barang dari PO STOCKWISE: cari PO-nya, isi jumlah yang datang per barang (boleh sebagian), lalu upload bukti.'
              : source === 'BEKAS' ? 'Barang bekas yang kembali dari NPBG bertanda "Kembali bekas". Tracking Pengembalian Bekas otomatis jadi Clear; barang bekas tidak menambah stok.'
              : 'Barang apa saja, tidak terikat PO atau pengembalian bekas. Kalau kodenya ada di Master Produk, stoknya bertambah.'}
          </p>
        </div>

        {source === 'BEKAS' && (
          <div className="space-y-1.5 rounded-lg border bg-muted/20 p-3">
            <Label>Barang bekas yang dikembalikan *</Label>
            {usedLine ? (
              <div className="flex items-center justify-between gap-2 text-sm">
                <span className="min-w-0"><span className="font-mono">{usedLine.npbg_no}</span> · {usedLine.deskripsi} · {usedLine.qty ?? ''} {usedLine.satuan ?? ''}{usedLine.pelanggan ? ` · ${usedLine.pelanggan}` : ''}</span>
                <Button size="sm" variant="ghost" onClick={() => setUsedLine(null)}>Lepas</Button>
              </div>
            ) : (
              <>
                <Input value={usedSearch} onChange={(e) => setUsedSearch(e.target.value)} placeholder="Cari no. NPBG, barang, pelanggan / nopol" />
                {usedLines.data && usedLines.data.length === 0 && <p className="text-xs text-muted-foreground">Tidak ada barang bekas yang menunggu dikembalikan.</p>}
                {(usedLines.data?.length ?? 0) > 0 && (
                  <ul className="max-h-40 divide-y overflow-auto rounded-md border bg-card text-sm">
                    {usedLines.data!.map((l) => (
                      <li key={l.id}>
                        <button type="button" className="block w-full px-3 py-2 text-left hover:bg-muted" onClick={() => pickUsedLine(l)}>
                          <span className="font-mono text-xs">{l.npbg_no}</span> {l.deskripsi}
                          <span className="text-muted-foreground"> · {l.qty ?? ''} {l.satuan ?? ''}{l.pelanggan ? ` · ${l.pelanggan}` : ''}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}
          </div>
        )}

        {/* from a STOCKWISE PO: search the PO, its whole item list, how much of each arrived (part is fine) */}
        {source === 'PO' && (
          <div className="space-y-2 rounded-lg border bg-muted/20 p-3">
            <Label>PO STOCKWISE *</Label>
            {poOrder ? (
              <>
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                  <span className="min-w-0"><span className="font-mono font-semibold">{poOrder.number}</span>{poOrder.vendor ? ` · ${poOrder.vendor}` : ''}{poOrder.date ? ` · ${new Date(poOrder.date).toLocaleDateString('id-ID')}` : ''}</span>
                  <Button size="sm" variant="ghost" onClick={() => { setPoOrder(null); setPoQty({}); setPoSeri({}) }}>Ganti PO</Button>
                </div>
                <div className="max-h-[55vh] overflow-auto rounded-lg border bg-card">
                  <table className="w-full min-w-[1200px] text-sm">
                    <thead className="sticky top-0 z-10 whitespace-nowrap border-b bg-muted text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <tr>
                        <th className="w-10 px-3 py-2.5 text-center font-medium">No</th>
                        <th className="px-3 py-2.5 font-medium">Kode</th>
                        <th className="min-w-[16rem] px-3 py-2.5 font-medium">Deskripsi Barang</th>
                        <th className="px-3 py-2.5 font-medium">Vendor</th>
                        <th className="px-3 py-2.5 font-medium">Satuan</th>
                        <th className="px-3 py-2.5 font-medium">No. PPB</th>
                        <th className="px-3 py-2.5 text-right font-medium">Qty PO</th>
                        <th className="px-3 py-2.5 text-right font-medium">Sudah Diterima</th>
                        <th className="px-3 py-2.5 text-right font-medium">Sisa</th>
                        <th className="px-3 py-2.5 text-right font-medium">Harga</th>
                        <th className="bg-primary/10 px-3 py-2.5 text-right font-semibold text-primary">Diterima Sekarang</th>
                        <th className="px-3 py-2.5 font-medium">No. Seri</th>
                        <th className="px-3 py-2.5 font-medium">Foto Bukti</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {poOrder.lines.map((l, i) => {
                        const done = l.remaining <= 0
                        const typed = poQty[l.id] ?? ''
                        const n = Number(typed.replace(',', '.'))
                        const partial = typed !== '' && n > 0 && n + 0.0001 < l.remaining
                        return (
                          <tr key={l.id} className={cn('align-top', done ? 'bg-muted/40 text-muted-foreground' : 'even:bg-muted/20 hover:bg-primary/5')}>
                            <td className="px-3 py-2.5 text-center tabular-nums text-muted-foreground">{i + 1}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 font-mono text-xs">{l.kode_barang ?? ''}</td>
                            <td className="px-3 py-2.5 font-medium">{l.deskripsi}</td>
                            <td className="px-3 py-2.5">{l.vendor ?? ''}</td>
                            <td className="whitespace-nowrap px-3 py-2.5">{l.satuan ?? ''}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 font-mono text-xs">{l.no_ppb ?? ''}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{fmtQty(l.qty)}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{fmtQty(l.received)}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums">
                              {done ? <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700">Selesai</span> : fmtQty(l.remaining)}
                            </td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{l.unit_price ? l.unit_price.toLocaleString('id-ID') : ''}</td>
                            <td className="bg-primary/5 px-3 py-2 text-right">
                              {!done && (
                                <>
                                  <Input className="ml-auto h-9 w-24 bg-card text-right font-semibold tabular-nums" inputMode="decimal" aria-label={`Diterima ${l.deskripsi}`}
                                    value={typed} placeholder="0" onChange={(e) => setPoQty((q) => ({ ...q, [l.id]: e.target.value }))} />
                                  {partial && <div className="mt-1 whitespace-nowrap text-[11px] text-amber-700">partial, sisa {fmtQty(Math.round((l.remaining - n) * 100) / 100)}</div>}
                                  {typed !== '' && n > l.remaining + 0.0001 && <div className="mt-1 whitespace-nowrap text-[11px] text-amber-700">lebih {fmtQty(Math.round((n - l.remaining) * 100) / 100)}, tetap masuk stok</div>}
                                </>
                              )}
                            </td>
                            <td className="px-3 py-2">
                              {!done && <Input className="h-9 w-36" value={poSeri[l.id] ?? ''} placeholder="opsional" onChange={(e) => setPoSeri((q) => ({ ...q, [l.id]: e.target.value }))} aria-label={`No seri ${l.deskripsi}`} />}
                            </td>
                            <td className="px-3 py-2">
                              {!done && (
                                <div className="flex items-center gap-1.5">
                                  {(poPhotos[l.id] ?? []).map((p, k) => (
                                    <div key={k} className="relative size-10 shrink-0 overflow-hidden rounded border bg-muted">
                                      <img src={p} alt={`Foto ${l.deskripsi} ${k + 1}`} className="size-full object-cover" />
                                      <button type="button" aria-label={`Hapus foto ${k + 1}`} onClick={() => setPoPhotos((m) => ({ ...m, [l.id]: (m[l.id] ?? []).filter((_, j) => j !== k) }))}
                                        className="absolute right-0 top-0 rounded-bl bg-black/60 p-0.5 text-white"><X className="size-3" /></button>
                                    </div>
                                  ))}
                                  {(poPhotos[l.id]?.length ?? 0) < MAX_PHOTOS && (
                                    <button type="button" disabled={reading} onClick={() => { setPhotoFor(l.id); linePhotoInput.current?.click() }}
                                      title={`Foto bukti ${l.deskripsi} (maks ${MAX_PHOTOS})`}
                                      className={cn('flex size-10 shrink-0 items-center justify-center rounded border-2 border-dashed text-muted-foreground hover:border-primary hover:text-primary',
                                        typed !== '' && n > 0 && !(poPhotos[l.id]?.length) && 'border-amber-400 text-amber-600')}>
                                      <Camera className="size-4" />
                                    </button>
                                  )}
                                </div>
                              )}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <input ref={linePhotoInput} type="file" accept="image/*" capture="environment" multiple className="hidden"
                    onChange={(e) => { if (photoFor != null) void addLinePhotos(photoFor, e.target.files); e.target.value = '' }} />
                  <p className="text-xs text-muted-foreground">Isi jumlah yang datang saja, lalu foto bukti tiap barang itu (ikon kamera). Barang yang belum datang biarkan kosong, nanti bisa di-RI berikutnya.</p>
                  <Button size="sm" variant="outline" className="h-7" onClick={() => setPoQty(Object.fromEntries(poOrder.lines.filter((l) => l.remaining > 0).map((l) => [l.id, String(l.remaining)])))}>Isi semua sisa</Button>
                </div>
              </>
            ) : (
              <>
                <Input value={poSearch} onChange={(e) => setPoSearch(e.target.value)} placeholder="Cari no. PO, vendor, atau barang yang belum diterima" />
                {poOrders.data && poOrders.data.length === 0 && <p className="text-xs text-muted-foreground">Tidak ada PO yang menunggu barang.</p>}
                {(poOrders.data?.length ?? 0) > 0 && (
                  <ul className="max-h-56 divide-y overflow-auto rounded-md border bg-card text-sm">
                    {poOrders.data!.map((o) => (
                      <li key={o.id}>
                        <button type="button" className="block w-full px-3 py-2 text-left hover:bg-muted" onClick={() => pickPoOrder(o)}>
                          <span className="font-mono text-xs font-semibold">{o.number}</span>{o.vendor ? ` · ${o.vendor}` : ''}
                          <span className="block truncate text-xs text-muted-foreground">
                            {o.lines.filter((l) => l.remaining > 0).length} barang menunggu: {o.lines.filter((l) => l.remaining > 0).map((l) => `${l.deskripsi} (${fmtQty(l.remaining)})`).join(', ')}
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}
          </div>
        )}
        <div className="grid grid-cols-3 gap-3">
          <div className="space-y-1.5">
            <Label>Jenis RI</Label>
            <Select value={divisi} onChange={(e) => setDivisi(e.target.value as 'NA' | 'ATK' | 'NV')}>
              {JENIS_RI.map((d) => <option key={d} value={d}>{riJenis(d)}</option>)}
            </Select>
          </div>
          {source !== 'PO' && (
          <div className="space-y-1.5">
            <Label>Vendor</Label>
            <Input value={vendor} onChange={(e) => setVendor(e.target.value)} />
          </div>
          )}
          <div className="space-y-1.5">
            <Label>Tanggal RI</Label>
            <Input type="date" value={tglRi} onChange={(e) => setTglRi(e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          {source !== 'PO' && (
          <div className="space-y-1.5">
            <Label>No. PO (referensi)</Label>
            <Input className="font-mono" value={noPo} onChange={(e) => setNoPo(e.target.value)} />
          </div>
          )}
          <div className="space-y-1.5">
            <Label>No. Surat Jalan</Label>
            <Input value={noSuratJalan} onChange={(e) => setNoSuratJalan(e.target.value)} />
          </div>
          {source === 'PO' && (
            <div className="space-y-1.5">
              <Label>Pemeriksa</Label>
              <Input value={pemeriksa} onChange={(e) => setPemeriksa(e.target.value)} />
            </div>
          )}
        </div>

        {source !== 'PO' && <>

        <div className="grid grid-cols-2 gap-3">
          <div className="relative space-y-1.5">
            <Label>Kode Barang</Label>
            <Input
              className="font-mono"
              value={kodeBarang}
              onChange={(e) => setKodeBarang(e.target.value)}
              onFocus={() => setActiveField('kode')}
              onBlur={() => setTimeout(() => setActiveField((f) => (f === 'kode' ? null : f)), 150)}
              placeholder="mis. PUI.0019"
            />
            {activeField === 'kode' && kodeBarang.trim().length >= 2 && (
              <ItemSuggestions items={suggestions} loading={searchingItems} onPick={pickItem} />
            )}
          </div>
          <div className="relative space-y-1.5">
            <Label>Deskripsi Barang</Label>
            <Input
              value={deskripsiBarang}
              onChange={(e) => setDeskripsiBarang(e.target.value)}
              onFocus={() => setActiveField('deskripsi')}
              onBlur={() => setTimeout(() => setActiveField((f) => (f === 'deskripsi' ? null : f)), 150)}
            />
            {activeField === 'deskripsi' && deskripsiBarang.trim().length >= 2 && (
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
            <Label>Harga Satuan</Label>
            <Input type="number" min={0} value={hargaSatuan} onChange={(e) => setHargaSatuan(e.target.value ? Number(e.target.value) : '')} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>No. Seri</Label>
            <Input value={noSeri} onChange={(e) => setNoSeri(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label>Pemeriksa</Label>
            <Input value={pemeriksa} onChange={(e) => setPemeriksa(e.target.value)} />
          </div>
        </div>

        </>}

        <div className="space-y-1.5">
          <Label>Keterangan</Label>
          <Input value={keterangan} onChange={(e) => setKeterangan(e.target.value)} />
        </div>

        {source !== 'PO' && (
        <div className="space-y-1.5">
          <Label>Foto bukti penerimaan * <span className="font-normal text-muted-foreground">(minimal 1, maksimal {MAX_PHOTOS})</span></Label>
          <input ref={photoInput} type="file" accept="image/*" capture="environment" multiple className="hidden"
            onChange={(e) => { void addPhotos(e.target.files); e.target.value = '' }} />
          <div className="grid grid-cols-3 gap-2">
            {photos.map((p, i) => (
              <div key={i} className="relative overflow-hidden rounded-lg border bg-muted">
                <img src={p} alt={`Foto bukti ${i + 1}`} className="aspect-square w-full object-cover" />
                <button type="button" aria-label={`Hapus foto ${i + 1}`} onClick={() => setPhotos((x) => x.filter((_, j) => j !== i))}
                  className="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white hover:bg-black/80"><X className="size-3.5" /></button>
              </div>
            ))}
            {photos.length < MAX_PHOTOS && (
              <button type="button" onClick={() => photoInput.current?.click()} disabled={reading}
                className={'flex aspect-square flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-xs text-muted-foreground transition-colors hover:border-primary hover:bg-primary/5 hover:text-primary'
                  + (photos.length === 0 ? ' border-amber-400' : '')}>
                {reading ? <Loader2 className="size-6 animate-spin" /> : <Camera className="size-6" />}
                <span className="font-medium">{reading ? 'Memproses…' : photos.length === 0 ? 'Ambil / pilih foto' : 'Tambah foto'}</span>
                <span>{photos.length}/{MAX_PHOTOS}</span>
              </button>
            )}
          </div>
        </div>
        )}

        {source === 'PO' && (
          <div className="space-y-1.5">
            <Label>Dokumen <span className="font-normal text-muted-foreground">(surat jalan / invoice, foto atau PDF, maksimal {MAX_DOCS})</span></Label>
            <input ref={docInput} type="file" accept="image/*,application/pdf" multiple className="hidden"
              onChange={(e) => { void addDocs(e.target.files); e.target.value = '' }} />
            <div className="flex flex-wrap gap-2">
              {docs.map((d, i) => (
                <span key={i} className="inline-flex items-center gap-1.5 rounded-md border bg-card px-2 py-1 text-xs">
                  <FileText className="size-3.5 text-muted-foreground" /> <span className="max-w-[12rem] truncate">{d.name}</span>
                  <button type="button" aria-label={`Hapus ${d.name}`} onClick={() => setDocs((x) => x.filter((_, j) => j !== i))} className="text-muted-foreground hover:text-destructive"><X className="size-3.5" /></button>
                </span>
              ))}
              {docs.length < MAX_DOCS && (
                <Button type="button" size="sm" variant="outline" className="h-8" disabled={reading} onClick={() => docInput.current?.click()}>
                  <Upload className="size-3.5" /> Upload dokumen
                </Button>
              )}
            </div>
          </div>
        )}

        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
