import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Loader2, Scale, Send, Trash2 } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { usePoDraftActions, usePoDraftLines, type PoDraftLine } from '@/features/poDrafts/api'
import { useVendors } from '@/features/purchasing/api'
import { BackLink } from '@/components/BackLink'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { confirmDialog } from '@/components/ui/confirm'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { Select } from '@/components/ui/select'
import { invalidTo } from '@/lib/notify'

const rupiah = (n: number) => 'Rp ' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 })
const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })
/** "1.200.000" / "1200000" / "1200,5" / "1200.5" → a number */
const num = (v: string) => {
  const t = v.trim().replace(/\s/g, '')
  if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(t)) return Number(t.replace(/\./g, '').replace(',', '.'))
  return Number(t.replace(',', '.'))
}

/** Draft PO: items of approved PPBs — compare vendor prices, pick items, one PO per vendor. */
export function PoDraftPage() {
  const [search, setSearch] = useState('')
  const { data, isLoading } = usePoDraftLines({ search: search.trim() || undefined })
  const rows = data?.data ?? []
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [quoting, setQuoting] = useState<PoDraftLine | null>(null)
  const [creating, setCreating] = useState(false)
  const live = rows.filter((r) => picked.has(r.id))
  const allOn = rows.length > 0 && rows.every((r) => picked.has(r.id))
  const toggle = (id: number) => setPicked((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n })
  // the quoting modal follows the list (a saved price shows at once)
  const quoted = quoting ? rows.find((r) => r.id === quoting.id) ?? quoting : null

  return (
    <div className="space-y-4">
      <BackLink to="/purchase-orders" label="Purchase Order" />
      <PageHeader title="Draft PO" icon={<Scale className="size-5" />}
        subtitle="Barang dari PPB yang sudah disetujui Direktur. Catat harga dari beberapa vendor untuk dibandingkan, centang barang, lalu buat PO — satu PO untuk satu vendor." />
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <Input className="sm:w-80" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari barang / no. PPB / peminta / vendor" aria-label="Cari" />
        {data && <span className="text-xs text-muted-foreground">{data.meta.total} barang menunggu PO</span>}
      </div>

      {/* phone: one card per item */}
      <Card className="overflow-hidden sm:hidden">
        <ul className="divide-y">
          {rows.map((l) => (
            <li key={l.id} className={picked.has(l.id) ? 'space-y-2 bg-primary/5 p-3 text-sm' : 'space-y-2 p-3 text-sm'}>
              <label className="flex items-start gap-3">
                <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-primary" checked={picked.has(l.id)} onChange={() => toggle(l.id)} aria-label={`Pilih ${l.description}`} />
                <span className="min-w-0">
                  <span className="block break-words font-medium">{l.description}</span>
                  <span className="block text-xs text-muted-foreground">{fmtQty(l.qty)} {l.unit ?? ''} · {l.ppb?.no_ppb}</span>
                </span>
              </label>
              <div className="pl-7"><Quotes l={l} onOpen={() => setQuoting(l)} /></div>
            </li>
          ))}
          {!isLoading && rows.length === 0 && <li className="px-3 py-10 text-center text-muted-foreground">Tidak ada barang yang menunggu PO.</li>}
        </ul>
      </Card>

      <Card className="hidden overflow-hidden sm:block">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="whitespace-nowrap border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="w-10 px-3 py-2.5"><input type="checkbox" className="size-4 accent-primary" aria-label="Pilih semua" checked={allOn}
                  onChange={() => setPicked(allOn ? new Set() : new Set(rows.map((r) => r.id)))} /></th>
                <th className="min-w-[14rem] px-3 py-2.5 font-medium">Barang</th>
                <th className="px-3 py-2.5 text-right font-medium">Qty</th>
                <th className="min-w-[10rem] px-3 py-2.5 font-medium">PPB</th>
                <th className="min-w-[18rem] px-3 py-2.5 font-medium">Perbandingan harga vendor</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((l) => (
                <tr key={l.id} className={picked.has(l.id) ? 'bg-primary/5 align-top' : 'align-top'}>
                  <td className="px-3 py-3"><input type="checkbox" className="size-4 accent-primary" checked={picked.has(l.id)} onChange={() => toggle(l.id)} aria-label={`Pilih ${l.description}`} /></td>
                  <td className="px-3 py-3"><div className="font-medium">{l.description}</div><div className="font-mono text-xs text-muted-foreground">{l.item_code ?? 'Barang baru (di luar master)'}</div></td>
                  <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">{fmtQty(l.qty)} <span className="text-xs text-muted-foreground">{l.unit ?? ''}</span></td>
                  <td className="px-3 py-3 text-xs">
                    {l.ppb && <Link to={`/ppb/${l.ppb.id}`} className="font-mono text-primary hover:underline">{l.ppb.no_ppb}</Link>}
                    <div className="text-muted-foreground">{[l.ppb?.divisi, l.ppb?.peminta].filter(Boolean).join(' · ')}</div>
                  </td>
                  <td className="px-3 py-3"><Quotes l={l} onOpen={() => setQuoting(l)} /></td>
                </tr>
              ))}
              {!isLoading && rows.length === 0 && <tr><td colSpan={5} className="px-3 py-12 text-center text-muted-foreground">Tidak ada barang yang menunggu PO. Barang masuk ke sini setelah PPB disetujui Direktur.</td></tr>}
              {isLoading && <tr><td colSpan={5} className="px-3 py-12 text-center text-muted-foreground"><Loader2 className="mx-auto size-5 animate-spin" /></td></tr>}
            </tbody>
          </table>
        </div>
      </Card>

      <div className="sticky bottom-3 flex flex-col gap-2 rounded-lg border bg-card p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <span className="text-sm text-muted-foreground">{live.length ? `${live.length} barang dipilih` : 'Centang barang yang dibeli dari satu vendor.'}</span>
        <Button disabled={live.length === 0} onClick={() => setCreating(true)}><Send className="size-4" /> Buat PO</Button>
      </div>

      {quoted && <QuotesModal l={quoted} onClose={() => setQuoting(null)} />}
      {creating && <CreatePoModal lines={live} onClose={() => setCreating(false)} onDone={() => setPicked(new Set())} />}
    </div>
  )
}

/** The vendors' prices of one item, cheapest first. */
function Quotes({ l, onOpen }: { l: PoDraftLine; onOpen: () => void }) {
  const low = l.quotes[0]?.unit_price
  return (
    <div className="space-y-1">
      {l.quotes.length > 0 && (
        <ul className="space-y-0.5">
          {l.quotes.map((q) => (
            <li key={q.id} className="flex items-baseline justify-between gap-3 text-xs">
              <span className="min-w-0 truncate">{q.vendor_name}{q.note ? <span className="text-muted-foreground"> · {q.note}</span> : null}</span>
              <span className="shrink-0 tabular-nums">
                {rupiah(q.unit_price)}
                {l.quotes.length > 1 && q.unit_price === low && <span className="ml-1 rounded bg-emerald-50 px-1 py-0.5 font-medium text-emerald-700">Termurah</span>}
              </span>
            </li>
          ))}
        </ul>
      )}
      <button type="button" onClick={onOpen} className="text-xs font-medium text-primary hover:underline">
        {l.quotes.length ? 'Ubah / tambah harga vendor' : '+ Catat harga vendor'}
      </button>
    </div>
  )
}

function QuotesModal({ l, onClose }: { l: PoDraftLine; onClose: () => void }) {
  const act = usePoDraftActions()
  const { data: vendors } = useVendors({ limit: 2000, is_active: '1' })
  const [vendorId, setVendorId] = useState<number | ''>('')
  const [price, setPrice] = useState('')
  const [note, setNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const low = l.quotes[0]?.unit_price
  const save = () => {
    setErr(null)
    if (!vendorId) return invalidTo(setErr, 'Pilih vendor.')
    if (price.trim() === '' || !(num(price) >= 0)) return invalidTo(setErr, 'Isi harga satuan.')
    act.quote.mutate({ id: l.id, vendor_id: Number(vendorId), unit_price: num(price), note: note.trim() || null }, {
      onSuccess: () => { setVendorId(''); setPrice(''); setNote('') },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }
  return (
    <Modal open onClose={onClose} title="Perbandingan harga vendor" className="max-w-2xl"
      description={`${l.description} · ${fmtQty(l.qty)} ${l.unit ?? ''}`}
      footer={<Button variant="outline" size="sm" onClick={onClose}>Selesai</Button>}>
      <div className="space-y-4">
        <div className="overflow-hidden rounded-lg border">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr><th className="px-3 py-2 font-medium">Vendor</th><th className="px-3 py-2 text-right font-medium">Harga satuan</th><th className="px-3 py-2 text-right font-medium">Total ({fmtQty(l.qty)})</th><th className="w-10" /></tr>
            </thead>
            <tbody className="divide-y">
              {l.quotes.map((q) => (
                <tr key={q.id} className={q.unit_price === low && l.quotes.length > 1 ? 'bg-emerald-50/50' : undefined}>
                  <td className="px-3 py-2">
                    <div className="font-medium">{q.vendor_name}{q.unit_price === low && l.quotes.length > 1 && <span className="ml-1.5 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800">Termurah</span>}</div>
                    <div className="text-xs text-muted-foreground">{[q.note, q.by].filter(Boolean).join(' · ')}</div>
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                    {rupiah(q.unit_price)}
                    {low !== undefined && q.unit_price > low && <div className="text-xs text-red-600">+{rupiah(q.unit_price - low)}</div>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{rupiah(q.unit_price * l.qty)}</td>
                  <td className="px-2 py-2">
                    <button type="button" className="rounded p-1.5 text-muted-foreground hover:text-destructive" aria-label={`Hapus harga ${q.vendor_name}`}
                      onClick={async () => { if (await confirmDialog({ message: `Hapus harga dari ${q.vendor_name}?`, danger: true })) act.removeQuote.mutate({ id: l.id, quoteId: q.id }) }}>
                      <Trash2 className="size-4" />
                    </button>
                  </td>
                </tr>
              ))}
              {l.quotes.length === 0 && <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">Belum ada harga vendor.</td></tr>}
            </tbody>
          </table>
        </div>
        <div className="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
          <div className="space-y-1"><Label htmlFor="q-vendor">Vendor</Label>
            <Select id="q-vendor" value={vendorId} onChange={(e) => {
              const v = e.target.value ? Number(e.target.value) : ''
              setVendorId(v)
              const had = l.quotes.find((q) => q.vendor_id === v) // editing a price already noted
              if (had) { setPrice(String(had.unit_price)); setNote(had.note ?? '') }
            }}>
              <option value="">Pilih vendor…</option>
              {(vendors ?? []).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </Select>
          </div>
          <div className="space-y-1"><Label htmlFor="q-price">Harga satuan (Rp)</Label>
            <Input id="q-price" className="text-right tabular-nums" inputMode="decimal" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="0" /></div>
          <div className="space-y-1 sm:col-span-2"><Label htmlFor="q-note">Catatan</Label>
            <Input id="q-note" value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} placeholder="mis. stok ready, garansi 1 th, ongkir termasuk" /></div>
          <div className="flex justify-end sm:col-span-2">
            <Button size="sm" disabled={act.quote.isPending} onClick={save}>{act.quote.isPending && <Loader2 className="size-4 animate-spin" />} Simpan harga</Button>
          </div>
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <p className="text-xs text-muted-foreground">Vendor yang sudah dicatat bisa dipilih lagi untuk mengubah harganya. Harga vendor terpilih otomatis dipakai saat membuat PO.</p>
      </div>
    </Modal>
  )
}

/** The picked items → one PO of one vendor; prices from that vendor's quotes (editable). */
function CreatePoModal({ lines, onClose, onDone }: { lines: PoDraftLine[]; onClose: () => void; onDone: () => void }) {
  const navigate = useNavigate()
  const act = usePoDraftActions()
  const { data: vendors } = useVendors({ limit: 2000, is_active: '1' })
  const [vendorId, setVendorId] = useState<number | ''>('')
  const [expected, setExpected] = useState('')
  const [notes, setNotes] = useState('')
  const [qty, setQty] = useState<Record<number, string>>({})
  const [price, setPrice] = useState<Record<number, string>>({})
  const [err, setErr] = useState<string | null>(null)
  // vendors that priced every picked item: the cheapest total first (the comparison for this PO)
  const offers = useMemo(() => {
    const by = new Map<number, { name: string; total: number; n: number }>()
    for (const l of lines) for (const q of l.quotes) {
      const o = by.get(q.vendor_id) ?? { name: q.vendor_name ?? '', total: 0, n: 0 }
      o.total += q.unit_price * l.qty
      o.n += 1
      by.set(q.vendor_id, o)
    }
    return [...by.entries()].map(([id, o]) => ({ id, ...o, full: o.n === lines.length })).sort((a, b) => Number(b.full) - Number(a.full) || a.total - b.total)
  }, [lines])
  const quoteOf = (l: PoDraftLine) => (vendorId ? l.quotes.find((q) => q.vendor_id === vendorId)?.unit_price : undefined)
  const qtyOf = (l: PoDraftLine) => qty[l.id] ?? String(l.qty)
  const priceOf = (l: PoDraftLine) => price[l.id] ?? (quoteOf(l) !== undefined ? String(quoteOf(l)) : '')
  const total = lines.reduce((t, l) => t + (num(qtyOf(l)) || 0) * (num(priceOf(l)) || 0), 0)
  const pick = (id: number | '') => { setVendorId(id); setPrice({}) } // prices follow the vendor's quotes

  const submit = () => {
    setErr(null)
    if (!vendorId) return invalidTo(setErr, 'Pilih vendor PO ini.')
    const bad = lines.find((l) => !(num(qtyOf(l)) > 0) || priceOf(l).trim() === '' || !(num(priceOf(l)) >= 0))
    if (bad) return invalidTo(setErr, `Isi qty (> 0) dan harga ${bad.description}.`)
    act.submit.mutate({
      line_ids: lines.map((l) => l.id), vendor_id: Number(vendorId), expected_date: expected || null, notes: notes.trim() || null,
      lines: lines.map((l) => ({ id: l.id, qty: num(qtyOf(l)), unit_price: num(priceOf(l)) })),
    }, {
      onSuccess: (po) => { onDone(); onClose(); navigate(`/purchase-orders/${po.id}`) },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }
  return (
    <Modal open onClose={onClose} title="Buat PO" className="max-w-4xl"
      description={`${lines.length} barang menjadi satu PO untuk satu vendor, lalu diajukan ke Direktur.`}
      footer={<>
        <span className="mr-auto text-sm font-medium">Total {rupiah(total)}</span>
        <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
        <Button size="sm" disabled={act.submit.isPending} onClick={submit}>{act.submit.isPending && <Loader2 className="size-4 animate-spin" />} Buat PO & ajukan</Button>
      </>}>
      <div className="space-y-4">
        {offers.length > 0 && (
          <div className="space-y-1.5">
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Penawaran vendor untuk barang terpilih</div>
            <div className="flex flex-wrap gap-2">
              {offers.map((o, i) => (
                <button key={o.id} type="button" onClick={() => pick(o.id)}
                  className={vendorId === o.id ? 'rounded-lg border border-primary bg-primary/5 px-3 py-2 text-left ring-1 ring-primary' : 'rounded-lg border bg-card px-3 py-2 text-left hover:bg-muted/40'}>
                  <div className="text-sm font-medium">{o.name}{o.full && i === 0 && offers.filter((x) => x.full).length > 1 && <span className="ml-1.5 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800">Termurah</span>}</div>
                  <div className="text-xs text-muted-foreground">{rupiah(o.total)} · {o.full ? 'semua barang' : `${o.n} dari ${lines.length} barang`}</div>
                </button>
              ))}
            </div>
          </div>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1"><Label htmlFor="cp-vendor">Vendor *</Label>
            <Select id="cp-vendor" value={vendorId} onChange={(e) => pick(e.target.value ? Number(e.target.value) : '')}>
              <option value="">Pilih vendor…</option>
              {(vendors ?? []).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </Select>
          </div>
          <div className="space-y-1"><Label htmlFor="cp-exp">Perkiraan tiba</Label><Input id="cp-exp" type="date" value={expected} onChange={(e) => setExpected(e.target.value)} /></div>
          <div className="space-y-1 sm:col-span-2"><Label htmlFor="cp-notes">Keterangan</Label><Input id="cp-notes" value={notes} maxLength={2000} onChange={(e) => setNotes(e.target.value)} placeholder="kosong = Dari Draft PO · no. PPB" /></div>
        </div>
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="whitespace-nowrap border-b bg-muted text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="min-w-[14rem] px-3 py-2.5 font-medium">Barang</th>
                <th className="px-3 py-2.5 text-right font-medium">Qty</th>
                <th className="px-3 py-2.5 text-right font-medium">Harga (Rp)</th>
                <th className="px-3 py-2.5 text-right font-medium">Subtotal</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {lines.map((l) => {
                const low = l.quotes[0]
                return (
                  <tr key={l.id} className="align-top">
                    <td className="px-3 py-2.5">
                      <div className="font-medium">{l.description}</div>
                      <div className="text-xs text-muted-foreground">
                        {[l.item_code, l.ppb?.no_ppb].filter(Boolean).join(' · ')}
                        {low && low.vendor_id !== vendorId && <span className="ml-1 text-emerald-700">· termurah {low.vendor_name} {rupiah(low.unit_price)}</span>}
                      </div>
                    </td>
                    <td className="px-3 py-2"><div className="flex items-center justify-end gap-1">
                      <Input className="h-9 w-20 text-right tabular-nums" inputMode="decimal" value={qtyOf(l)} aria-label={`Qty ${l.description}`} onChange={(e) => setQty((q) => ({ ...q, [l.id]: e.target.value }))} />
                      <span className="w-10 text-xs text-muted-foreground">{l.unit ?? ''}</span></div></td>
                    <td className="px-3 py-2"><Input className="ml-auto h-9 w-32 text-right tabular-nums" inputMode="decimal" value={priceOf(l)} placeholder={vendorId ? 'belum ada harga' : 'pilih vendor'} aria-label={`Harga ${l.description}`} onChange={(e) => setPrice((p) => ({ ...p, [l.id]: e.target.value }))} /></td>
                    <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{rupiah((num(qtyOf(l)) || 0) * (num(priceOf(l)) || 0))}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
