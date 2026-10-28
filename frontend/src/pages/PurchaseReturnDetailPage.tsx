import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Camera, CheckCircle2, Loader2, Truck, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { usePurchaseReturn, useReturnPhoto, useReturnStep, type PurchaseReturnDetail } from '@/features/purchaseReturns/api'
import type { TimelineStep } from '@/features/itemRequests/api'
import { ReturnStatusBadge } from '@/pages/PurchaseReturnListPage'
import { RequestTimeline } from '@/components/itemRequests/RequestTimeline'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { BackLink } from '@/components/BackLink'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { confirmDialog } from '@/components/ui/confirm'
import { DetailFallback } from '@/components/DetailFallback'

const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-')
const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })
const fmtRp = (n: number) => `Rp ${n.toLocaleString('id-ID', { maximumFractionDigits: 0 })}`

function steps(r: PurchaseReturnDetail): TimelineStep[] {
  const cancelled = r.status === 'DIBATALKAN'
  return [
    { key: 'DIBUAT', label: 'Retur dibuat', state: 'done', at: r.created_at, by: r.created_by_name, note: null },
    { key: 'DIKIRIM', label: 'Dikirim ke vendor', state: r.sent_at ? 'done' : cancelled ? 'cancelled' : 'current', at: r.sent_at, by: r.sent_by_name, note: null },
    {
      key: 'SELESAI', label: r.status === 'SELESAI' ? 'Selesai' : 'Diselesaikan vendor',
      state: r.resolved_at ? 'done' : cancelled ? 'skipped' : r.sent_at ? 'current' : 'todo', at: r.resolved_at, by: r.resolved_by_name, note: null,
    },
  ]
}

/** The next step of an open return: send it, or settle it. */
function NextStep({ r }: { r: PurchaseReturnDetail }) {
  const step = useReturnStep(r.id)
  const file = useRef<HTMLInputElement>(null)
  const [photo, setPhoto] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const [doneNote, setDoneNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const onError = (e: unknown) => setErr(apiErrorMessage(e))

  if (r.status === 'DIBUAT') {
    return (
      <Card className="space-y-3 border-primary/40 bg-primary/5 p-4">
        <div className="text-sm font-semibold">Kirim barang ke vendor</div>
        <input ref={file} type="file" accept="image/*" capture="environment" className="hidden"
          onChange={async (e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) setPhoto(await photoToDataUrl(f, 1600)) }} />
        <div className="flex flex-wrap items-center gap-2">
          {photo ? (
            <span className="relative inline-block overflow-hidden rounded-md border">
              <img src={photo} alt="Bukti kirim" className="h-16 w-24 object-cover" />
              <button type="button" onClick={() => setPhoto(null)} className="absolute right-0.5 top-0.5 rounded-full bg-card/90 p-0.5" aria-label="Hapus foto"><X className="size-3" /></button>
            </span>
          ) : (
            <Button type="button" variant="outline" size="sm" onClick={() => file.current?.click()}><Camera className="size-4" /> Foto bukti kirim</Button>
          )}
          <Input className="min-w-0 flex-1" value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} placeholder="Catatan, mis. via ekspedisi / no surat jalan (opsional)" />
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <Button className="w-full" disabled={step.send.isPending} onClick={() => step.send.mutate({ note: note.trim() || null, photo }, { onError })}>
          {step.send.isPending ? <Loader2 className="size-4 animate-spin" /> : <Truck className="size-4" />} Tandai sudah dikirim
        </Button>
      </Card>
    )
  }
  if (r.status === 'DIKIRIM') {
    return (
      <Card className="space-y-3 border-primary/40 bg-primary/5 p-4">
        <div className="text-sm font-semibold">Retur sudah diselesaikan vendor?</div>
        <Input value={doneNote} maxLength={255} onChange={(e) => setDoneNote(e.target.value)} placeholder="Catatan (opsional), mis. diganti barang baru / dipotong tagihan" aria-label="Catatan" />
        {err && <p className="text-sm text-destructive">{err}</p>}
        <Button className="w-full bg-emerald-600 text-white hover:bg-emerald-700" disabled={step.resolve.isPending}
          onClick={() => step.resolve.mutate({ ref: null, note: doneNote.trim() || null }, { onError })}>
          {step.resolve.isPending ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />} Selesai
        </Button>
      </Card>
    )
  }
  return null
}

function Photo({ id, slot, label }: { id: number; slot: 'barang' | 'kirim'; label: string }) {
  const p = useReturnPhoto(id, slot, true)
  return (
    <a href={p.data} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-lg border bg-muted">
      {p.data ? <img src={p.data} alt={label} className="h-36 w-full object-cover" /> : <div className="h-36" />}
      <div className="bg-card px-2 py-1 text-xs text-muted-foreground">{label}</div>
    </a>
  )
}

export function PurchaseReturnDetailPage() {
  const { id } = useParams()
  const { hasPermission } = useAuth()
  const { data: r, isLoading } = usePurchaseReturn(Number(id))
  const step = useReturnStep(Number(id))

  if (isLoading || !r) return <div className="space-y-4"><BackLink to="/purchase-returns" label="Retur Pembelian" /><DetailFallback loading={isLoading} /></div>
  const canManage = hasPermission('purchase_return.manage')
  const total = r.lines.reduce((s, l) => s + l.qty * (l.harga_satuan ?? 0), 0)

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <BackLink to="/purchase-returns" label="Retur Pembelian" />

      <header className="space-y-1">
        <div className="flex items-start justify-between gap-3">
          <h1 className="text-xl font-semibold">{r.vendor ?? '-'}</h1>
          <ReturnStatusBadge status={r.status} />
        </div>
        <p className="text-sm text-muted-foreground">
          <span className="font-mono">{r.number}</span> · dari {r.ri_id ? <Link to={`/ri/${r.ri_id}`} className="font-mono text-primary hover:underline">{r.no_ri}</Link> : <span className="font-mono">{r.no_ri}</span>}
          {r.no_po && <> · <span className="font-mono">{r.no_po}</span></>} · {fmtDate(r.tgl_retur)}
        </p>
        <p className="text-sm"><span className="text-muted-foreground">Alasan: </span><span className="font-medium">{r.reason}</span>{r.note ? ` — ${r.note}` : ''}</p>
      </header>

      {canManage && <NextStep r={r} />}

      <section>
        <h2 className="text-sm font-semibold">Barang <span className="font-normal text-muted-foreground">({r.lines.length})</span></h2>
        <ul className="divide-y">
          {r.lines.map((l) => (
            <li key={l.id} className="flex items-start justify-between gap-3 py-3 text-sm">
              <div className="min-w-0">
                <div className="break-words font-medium">{l.deskripsi_barang}</div>
                <div className="font-mono text-xs text-muted-foreground">{l.kode_barang}</div>
              </div>
              <div className="shrink-0 text-right">
                <div className="tabular-nums">{fmtQty(l.qty)} <span className="text-muted-foreground">{l.satuan ?? ''}</span></div>
                {!!l.harga_satuan && <div className="text-xs tabular-nums text-muted-foreground">{fmtRp(l.qty * l.harga_satuan)}</div>}
              </div>
            </li>
          ))}
        </ul>
        {total > 0 && <div className="flex justify-between border-t pt-2 text-sm font-semibold"><span>Total</span><span className="tabular-nums">{fmtRp(total)}</span></div>}
      </section>

      {r.photos.length > 0 && (
        <section className="grid grid-cols-2 gap-2">
          {r.photos.includes('barang') && <Photo id={r.id} slot="barang" label="Foto barang" />}
          {r.photos.includes('kirim') && <Photo id={r.id} slot="kirim" label="Bukti kirim" />}
        </section>
      )}

      <section className="space-y-3">
        <h2 className="text-sm font-semibold">Status</h2>
        <RequestTimeline steps={steps(r)} />
        {(r.sent_note || r.resolution_ref || r.resolution_note) && (
          <div className="space-y-0.5 text-sm text-muted-foreground">
            {r.sent_note && <p>Pengiriman: {r.sent_note}</p>}
            {r.resolution_ref && <p>Referensi: <span className="font-mono text-foreground">{r.resolution_ref}</span></p>}
            {r.resolution_note && <p>{r.resolution_note}</p>}
          </div>
        )}
      </section>

      {canManage && r.status === 'DIBUAT' && (
        <div className="border-t pt-4 text-sm">
          <button type="button" disabled={step.cancel.isPending} className="text-muted-foreground hover:text-destructive"
            onClick={async () => { if ((await confirmDialog('Batalkan retur ini?'))) step.cancel.mutate() }}>Batalkan retur</button>
        </div>
      )}
    </div>
  )
}
