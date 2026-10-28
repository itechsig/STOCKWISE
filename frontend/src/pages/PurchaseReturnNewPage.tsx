import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Camera, ChevronRight, Loader2, Search, X } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { RETURN_REASONS, useCreatePurchaseReturn, useRiForReturn, useRiSearch } from '@/features/purchaseReturns/api'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { BackLink } from '@/components/BackLink'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { invalidTo } from '@/lib/notify'

const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-')
const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })

/** Step 1: find the RI the goods came in on. */
function PickRi({ onPick }: { onPick: (noRi: string) => void }) {
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  useEffect(() => { const t = setTimeout(() => setDebounced(q.trim()), 300); return () => clearTimeout(t) }, [q])
  const { data, isFetching } = useRiSearch(debounced, true)

  return (
    <div className="space-y-3">
      <div className="relative">
        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input autoFocus className="pl-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari no RI, vendor, no PO, atau nama barang" />
      </div>
      <div className="divide-y rounded-lg border bg-card">
        {(data ?? []).map((r) => (
          <button key={r.no_ri} type="button" onClick={() => onPick(r.no_ri)} className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/50">
            <div className="min-w-0 flex-1">
              <div className="font-medium">{r.vendor ?? '-'}</div>
              <div className="text-xs text-muted-foreground"><span className="font-mono">{r.no_ri}</span> · {fmtDate(r.tgl_ri)} · {r.line_count} barang</div>
            </div>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
          </button>
        ))}
        {data && data.length === 0 && <p className="px-4 py-6 text-center text-sm text-muted-foreground">RI tidak ditemukan.</p>}
        {!data && isFetching && <p className="flex justify-center py-6"><Loader2 className="size-5 animate-spin text-muted-foreground" /></p>}
      </div>
    </div>
  )
}

/**
 * Buat Retur, simple: pick the RI, tick what goes back and how many (never more than is left of
 * what was received), why, and a photo of the goods. How the vendor settles it is not asked.
 */
export function PurchaseReturnNewPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const noRi = params.get('ri')
  const ri = useRiForReturn(noRi)
  const create = useCreatePurchaseReturn()
  const file = useRef<HTMLInputElement>(null)
  const [qty, setQty] = useState<Record<number, string>>({})
  const [reason, setReason] = useState('')
  const [photo, setPhoto] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => { setQty({}) }, [noRi])

  const lines = ri.data?.lines ?? []
  const picked = lines.filter((l) => Number(qty[l.ri_id] || 0) > 0)
  const toggle = (id: number, max: number) => setQty((q) => ({ ...q, [id]: q[id] ? '' : String(max) }))

  const submit = () => {
    setErr(null)
    if (picked.length === 0) return invalidTo(setErr, 'Centang barang yang diretur.')
    const over = picked.find((l) => Number(qty[l.ri_id]) > l.returnable)
    if (over) return invalidTo(setErr, `Jumlah ${over.deskripsi_barang} maksimal ${fmtQty(over.returnable)}.`)
    if (!reason.trim()) return invalidTo(setErr, 'Pilih atau tulis alasan retur.')
    if (!photo) return invalidTo(setErr, 'Upload foto barang yang diretur.')
    create.mutate({
      no_ri: noRi!, reason: reason.trim(), photo,
      lines: picked.map((l) => ({ ri_id: l.ri_id, qty: Number(qty[l.ri_id]) })),
    }, { onSuccess: (d) => navigate(`/purchase-returns/${d.id}`, { replace: true }), onError: (e) => setErr(apiErrorMessage(e)) })
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <BackLink to="/purchase-returns" label="Retur Pembelian" />
      <h1 className="text-xl font-semibold">Buat retur</h1>

      {!noRi ? (
        <section className="space-y-2">
          <h2 className="text-sm font-semibold">Dari RI mana?</h2>
          <PickRi onPick={(n) => setParams({ ri: n })} />
        </section>
      ) : (
        <>
          <div className="flex items-start justify-between gap-3">
            <div>
              <div className="font-semibold">{ri.data?.vendor ?? '…'}</div>
              <div className="text-sm text-muted-foreground"><span className="font-mono">{noRi}</span>{ri.data?.no_po ? ` · ${ri.data.no_po}` : ''} · {fmtDate(ri.data?.tgl_ri ?? null)}</div>
            </div>
            <button type="button" className="text-sm text-primary" onClick={() => setParams({})}>Ganti RI</button>
          </div>

          <section className="space-y-2">
            <h2 className="text-sm font-semibold">Barang yang diretur</h2>
            {ri.isLoading && <p className="flex justify-center py-6"><Loader2 className="size-5 animate-spin text-muted-foreground" /></p>}
            <div className="divide-y rounded-lg border bg-card">
              {lines.map((l) => {
                const on = qty[l.ri_id] !== undefined && qty[l.ri_id] !== ''
                const none = l.returnable <= 0
                return (
                  <div key={l.ri_id} className={cn('flex items-center gap-3 px-4 py-3', none && 'opacity-50')}>
                    <input type="checkbox" className="size-4 shrink-0 accent-primary" checked={on} disabled={none} onChange={() => toggle(l.ri_id, l.returnable)}
                      aria-label={`Retur ${l.deskripsi_barang}`} />
                    <div className="min-w-0 flex-1">
                      <div className="break-words text-sm font-medium">{l.deskripsi_barang}</div>
                      <div className="text-xs text-muted-foreground">
                        diterima {fmtQty(l.received)} {l.satuan ?? ''}{l.returned > 0 ? ` · sudah diretur ${fmtQty(l.returned)}` : ''}
                      </div>
                    </div>
                    {on && (
                      <Input type="number" inputMode="decimal" min={0} max={l.returnable} step="any" className="w-24 text-right" aria-label={`Jumlah retur ${l.deskripsi_barang}`}
                        value={qty[l.ri_id]} onChange={(e) => setQty((q) => ({ ...q, [l.ri_id]: e.target.value }))} />
                    )}
                    {none && <span className="text-xs text-muted-foreground">habis diretur</span>}
                  </div>
                )
              })}
            </div>
          </section>

          <section className="space-y-2">
            <h2 className="text-sm font-semibold">Alasan</h2>
            <div className="flex flex-wrap gap-1.5">
              {RETURN_REASONS.map((r) => (
                <button key={r} type="button" onClick={() => setReason(r)}
                  className={cn('rounded-md border px-3 py-1 text-sm', reason === r ? 'border-primary bg-primary/10 font-medium text-primary' : 'bg-card hover:bg-muted')}>{r}</button>
              ))}
            </div>
            <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} placeholder="atau tulis alasan lain" />
          </section>

          <section className="space-y-2">
            <h2 className="text-sm font-semibold">Foto barang *</h2>
            <input ref={file} type="file" accept="image/*" capture="environment" className="hidden"
              onChange={async (e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) setPhoto(await photoToDataUrl(f, 1600)) }} />
            {photo ? (
              <div className="relative w-40 overflow-hidden rounded-lg border">
                <img src={photo} alt="Foto barang retur" className="h-28 w-full object-cover" />
                <button type="button" onClick={() => setPhoto(null)} className="absolute right-1 top-1 rounded-full bg-card/90 p-1" aria-label="Hapus foto"><X className="size-3.5" /></button>
              </div>
            ) : (
              <Button type="button" variant="outline" size="sm" className="border-amber-400" onClick={() => file.current?.click()}><Camera className="size-4" /> Ambil / pilih foto</Button>
            )}
          </section>

          {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
          <Button className="w-full" size="lg" onClick={submit} disabled={create.isPending || picked.length === 0}>
            {create.isPending && <Loader2 className="size-4 animate-spin" />}
            {picked.length === 0 ? 'Centang barang dulu' : `Buat retur (${picked.length} barang)`}
          </Button>
        </>
      )}
    </div>
  )
}
