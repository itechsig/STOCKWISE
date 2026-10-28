import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Camera, CheckCircle2, HandHelping, Loader2, PackageCheck, RefreshCw, Undo2, XCircle } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { STAGE_INFO, isBypass, useDecideRequest, useHandover, type Fulfillment, type ItemRequestStatus } from '@/features/itemRequests/api'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { SignaturePad } from '@/components/itemRequests/SignaturePad'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { Textarea } from '@/components/ui/textarea'
import { invalidTo } from '@/lib/notify'
import { LineImagesCell } from '@/components/itemRequests/RequestImages'

interface Target {
  id: number
  number: string
  status: ItemRequestStatus
  requester_name: string | null
  can_approve: boolean
  can_handover: boolean
  can_fulfill?: boolean
  approve_as?: 'MANAGER' | 'DIREKTUR' | 'GUDANG' | null
  /** the NPBG waiting for its serah terima (detail and Approval page): its quantities are fixed in the dialog */
  fulfillments?: Pick<Fulfillment, 'id' | 'seq' | 'status' | 'no_npbg' | 'lines'>[]
}

const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })
const parse = (v: string) => Number(v.trim().replace(',', '.'))

type OpenFulfillment = Pick<Fulfillment, 'id' | 'seq' | 'no_npbg' | 'lines'>

/** Gudang's table before the serah terima: typed quantity per fulfillment line, and whether the rest is closed. */
export interface HandoverDraft {
  qty: Record<number, string>
  drop: Record<number, boolean>
  /** why less than the approved NPBG is handed over (required then) */
  reason?: Record<number, string>
}

/** The fixed quantities of a draft, or what is wrong with it. */
function readDraft(f: OpenFulfillment, d?: HandoverDraft): { handed: Record<number, number>; drop: number[]; reasons: Record<number, string> } | { error: string } {
  const handed: Record<number, number> = {}
  const drop: number[] = []
  const reasons: Record<number, string> = {}
  for (const l of f.lines) {
    const raw = d?.qty[l.id]
    const v = raw === undefined ? l.qty : parse(raw)
    if (!Number.isFinite(v) || v < 0 || v > l.qty) return { error: `Jumlah ${l.deskripsi ?? ''}: 0 sampai ${fmtQty(l.qty)}.` }
    handed[l.id] = v
    if (v < l.qty) {
      // the NPBG was made from stock that is there: handing over less needs a reason
      const why = (d?.reason?.[l.id] ?? '').trim()
      if (!why) return { error: `Isi alasan jumlah ${l.deskripsi ?? ''} dikurangi.` }
      reasons[l.id] = why
      if (d?.drop[l.id]) drop.push(l.id)
    }
  }
  if (Object.values(handed).every((v) => v <= 0)) return { error: 'Isi jumlah barang yang diserahkan.' }
  return { handed, drop, reasons }
}

/**
 * Gudang fixes here how much of the prepared NPBG is handed over, before the serah terima
 * (less than prepared goes back to stock; its rest is either still owed or closed).
 */
export function HandoverQty({ f, value, onChange, stock, images }: {
  f: OpenFulfillment; value: HandoverDraft; onChange: (d: HandoverDraft) => void
  /** request line id → the item's stock (Master Produk; it drops when the serah terima is done) */
  stock?: Record<number, number | null | undefined>
  /** the requester's images per item: Gudang sees what was asked */
  images?: { requestId: number; byLine: Record<number, string[]> }
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="whitespace-nowrap bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th className="px-4 py-2 font-medium">Barang</th>
            {stock && <th className="px-3 py-2 text-right font-medium" title="Stok Master Produk, berkurang saat serah terima selesai">Stok</th>}
            <th className="px-3 py-2 text-right font-medium">Disetujui</th>
            <th className="px-4 py-2 text-right font-medium">Diserahkan</th>
            {images && <th className="px-4 py-2 text-right font-medium">Gambar</th>}
          </tr>
        </thead>
        <tbody className="divide-y">
          {f.lines.map((l) => {
            // default: the approved NPBG quantity; it can only be lowered
            const raw = value.qty[l.id] ?? String(l.qty)
            const v = parse(raw)
            const bad = !Number.isFinite(v) || v < 0 || v > l.qty
            const short = !bad && v < l.qty ? Math.round((l.qty - v) * 100) / 100 : 0
            return (
              <tr key={l.id} className="align-top">
                <td className="px-4 py-2.5"><div className="font-medium">{l.deskripsi}</div></td>
                {stock && (
                  <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums" title="Stok Master Produk, berkurang saat serah terima selesai">
                    {stock[l.line_id] != null ? <span className={(stock[l.line_id] ?? 0) > 0 ? 'text-emerald-700' : 'text-muted-foreground'}>{fmtQty(Number(stock[l.line_id]))} {l.satuan ?? ''}</span> : <span className="text-muted-foreground">?</span>}
                  </td>
                )}
                <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{fmtQty(l.qty)} {l.satuan ?? ''}</td>
                <td className="px-4 py-2 text-right">
                  <div className="flex items-center justify-end gap-1.5">
                    <Input className={'h-8 w-24 text-right tabular-nums' + (bad ? ' border-destructive' : '')} inputMode="decimal" aria-label={`Diserahkan ${l.deskripsi ?? ''}`}
                      value={raw} onChange={(e) => onChange({ ...value, qty: { ...value.qty, [l.id]: e.target.value } })} />
                    <span className="w-12 text-left text-xs text-muted-foreground">{l.satuan ?? ''}</span>
                  </div>
                  {bad && <div className="mt-1 text-xs text-destructive">Maksimal {fmtQty(l.qty)}</div>}
                  {short > 0 && (
                    // less than the approved NPBG while the stock was there: why (required)
                    <Input className={'ml-auto mt-1.5 h-8 w-full min-w-[12rem] max-w-xs text-xs' + (!(value.reason?.[l.id] ?? '').trim() ? ' border-amber-400' : '')}
                      maxLength={200} placeholder={`Alasan dikurangi ${fmtQty(short)} (wajib)`} aria-label={`Alasan ${l.deskripsi ?? ''} dikurangi`}
                      value={value.reason?.[l.id] ?? ''} onChange={(e) => onChange({ ...value, reason: { ...value.reason, [l.id]: e.target.value } })} />
                  )}
                  {short > 0 && (
                    // the rest goes back to stock; still owed unless closed
                    <label className="mt-1 inline-flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
                      <input type="checkbox" className="size-3.5 accent-primary" checked={!!value.drop[l.id]}
                        onChange={(e) => onChange({ ...value, drop: { ...value.drop, [l.id]: e.target.checked } })} />
                      Sisa {fmtQty(short)} tidak dibutuhkan lagi
                    </label>
                  )}
                </td>
                {/* the requester's images of this item, rightmost */}
                {images && <td className="px-4 py-2.5"><LineImagesCell requestId={images.requestId} lineId={l.line_id} slots={images.byLine[l.line_id]} /></td>}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

/**
 * The one thing the current user does: Approve (one tap; the approver does not change quantities),
 * Revisi (back to admin gudang to revise it, with a required note) or Tolak (with a reason);
 * for Gudang: prepare the goods from stock, or the serah terima of the prepared NPBG.
 */
export function ApprovalActions({ req, onDone, onPrepare, handover }: {
  req: Target
  onDone?: () => void
  /** detail page: jump to its "Siapkan barang" panel instead of opening the request */
  onPrepare?: () => void
  /** Gudang: what is handed over, fixed in the table first (HandoverQty) */
  handover?: HandoverDraft
}) {
  const decide = useDecideRequest(req.id)
  const [mode, setMode] = useState<'REJECT' | 'RETURN' | null>(null)
  const [reason, setReason] = useState('')
  // a note with the approval: admin gudang, the requester and the next approver all see it
  const [approveNote, setApproveNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [handing, setHanding] = useState(false)
  const info = STAGE_INFO[req.status]

  if (!info || (!req.can_approve && !req.can_handover && !req.can_fulfill)) return null

  const send = (action: 'APPROVE' | 'REJECT' | 'RETURN') => {
    setErr(null)
    if (action !== 'APPROVE' && !reason.trim()) return invalidTo(setErr, action === 'REJECT' ? 'Alasan penolakan wajib diisi.' : 'Catatan wajib diisi: tulis kenapa direvisi.')
    decide.mutate({ action, note: action === 'APPROVE' ? (approveNote.trim() || null) : reason.trim() }, {
      onSuccess: () => { setMode(null); setReason(''); setApproveNote(''); onDone?.() },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  const openF = req.fulfillments?.find((f) => f.status !== 'DISERAHKAN') ?? null
  const draft = openF ? readDraft(openF, handover) : null
  const draftError = draft && 'error' in draft ? draft.error : null
  const fixed = draft && !('error' in draft) ? draft : null

  if (req.can_handover) {
    return (
      <>
        <div className="flex flex-col gap-1 sm:items-end">
          <Button className="w-full sm:w-auto" disabled={!!draftError} onClick={() => setHanding(true)}><HandHelping className="size-4" /> Serah terima</Button>
          {draftError && <span className="text-xs text-destructive">{draftError}</span>}
        </div>
        {/* mounted only while open: every serah terima starts empty */}
        {handing && <HandoverDialog open onClose={() => setHanding(false)} req={req} onDone={onDone}
          fulfillment={openF} handed={fixed?.handed} drop={fixed?.drop} reasons={fixed?.reasons} />}
      </>
    )
  }

  // Gudang: goods in stock but no NPBG yet, give them from the stock first
  if (req.can_fulfill && req.status === 'DISIAPKAN') {
    return onPrepare
      ? <Button className="w-full sm:w-auto" onClick={onPrepare}><PackageCheck className="size-4" /> Siapkan barang</Button>
      : <Button asChild className="w-full sm:w-auto"><Link to={`/item-requests/${req.id}#siapkan`}><PackageCheck className="size-4" /> Siapkan barang</Link></Button>
  }

  if (req.can_fulfill) {
    return onPrepare
      ? <Button className="w-full sm:w-auto" onClick={onPrepare}><PackageCheck className="size-4" /> Siapkan barang</Button>
      : <Button asChild className="w-full sm:w-auto"><Link to={`/item-requests/${req.id}#siapkan`}><PackageCheck className="size-4" /> Siapkan barang</Link></Button>
  }

  if (mode) {
    const reject = mode === 'REJECT'
    return (
      <div className={reject ? 'w-full space-y-2 rounded-lg border border-red-200 bg-red-50/60 p-3' : 'w-full space-y-2 rounded-lg border border-orange-200 bg-orange-50/60 p-3'}>
        <Label htmlFor={`rj-${req.id}`} className={reject ? 'text-red-800' : 'text-orange-800'}>{reject ? 'Alasan ditolak *' : 'Kenapa direvisi? *'}</Label>
        <Textarea id={`rj-${req.id}`} autoFocus rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)}
          placeholder={reject ? 'mis. Pakai stok lama dulu' : 'mis. Cek ulang barang / jumlahnya'} />
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={() => { setMode(null); setErr(null) }} disabled={decide.isPending}>Batal</Button>
          <Button size="sm" className={reject ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-orange-600 text-white hover:bg-orange-700'} onClick={() => send(mode)} disabled={decide.isPending}>
            {decide.isPending ? <Loader2 className="size-4 animate-spin" /> : reject ? <XCircle className="size-4" /> : <Undo2 className="size-4" />}
            {reject ? 'Tolak request' : 'Kirim revisi ke admin'}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="w-full space-y-1.5 sm:w-auto">
      <Textarea rows={2} maxLength={500} value={approveNote} onChange={(e) => setApproveNote(e.target.value)} className="min-h-0 text-sm"
        placeholder="Catatan untuk admin gudang & peminta (opsional)" aria-label="Catatan approval" />
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" className="flex-1 border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800 sm:flex-none" onClick={() => setMode('REJECT')} disabled={decide.isPending}>
          <XCircle className="size-4" /> Tolak
        </Button>
        <Button variant="outline" className="flex-1 sm:flex-none" onClick={() => setMode('RETURN')} disabled={decide.isPending}>
          <Undo2 className="size-4" /> Revisi
        </Button>
        <Button className="flex-[2] bg-emerald-600 text-white hover:bg-emerald-700 sm:flex-none" onClick={() => send('APPROVE')} disabled={decide.isPending}>
          {decide.isPending ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />} {isBypass(req) ? 'Approve sebagai Direktur' : info.approve}
        </Button>
      </div>
      {isBypass(req) && <p className="text-xs text-muted-foreground">Manager ikut disetujui otomatis.</p>}
      {err && <p className="text-sm text-destructive">{err}</p>}
    </div>
  )
}

/**
 * Siapkan & serah terima, one step on the gudang's phone: 1 how much is really handed over (less than
 * prepared goes back to stock), 2 photo of the goods being handed over, 3 who receives it, 4 the receiver signs.
 */
export function HandoverDialog({ open, onClose, req, onDone, fulfillment, handed: fixed, drop, reasons }: {
  open: boolean; onClose: () => void; req: Target; onDone?: () => void; fulfillment?: OpenFulfillment | null
  /** fixed in the table before (default: everything prepared) */
  handed?: Record<number, number>
  drop?: number[]
  reasons?: Record<number, string>
}) {
  const handover = useHandover(req.id, fulfillment?.id)
  const file = useRef<HTMLInputElement>(null)
  const [photo, setPhoto] = useState<string | null>(null)
  const [reading, setReading] = useState(false)
  // the requester receives it unless another name is typed
  const [receiver, setReceiver] = useState(req.requester_name ?? '')
  const [editingReceiver, setEditingReceiver] = useState(!req.requester_name)
  const [signature, setSignature] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)

  const pick = async (f: File | undefined) => {
    if (!f) return
    setErr(null)
    setReading(true)
    try { setPhoto(await photoToDataUrl(f, 1600)) } catch (e) { setErr((e as Error).message) } finally { setReading(false) }
  }

  const ready = !!photo && !!signature && receiver.trim() !== ''
  const qtyOf: Record<number, number> = Object.fromEntries((fulfillment?.lines ?? []).map((l) => [l.id, fixed?.[l.id] ?? l.qty]))
  const submit = () => {
    setErr(null)
    if (!photo) return invalidTo(setErr, 'Ambil foto bukti serah terima dulu.')
    if (!receiver.trim()) return invalidTo(setErr, 'Isi nama penerima.')
    if (!signature) return invalidTo(setErr, 'Minta penerima tanda tangan.')
    handover.mutate({ photo, signature, received_by: receiver.trim(), handed: fulfillment ? qtyOf : undefined, drop: drop?.length ? drop : undefined, reasons: reasons && Object.keys(reasons).length ? reasons : undefined }, {
      onSuccess: () => { onClose(); onDone?.() },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  let n = 0
  return (
    <Modal open={open} onClose={onClose} title="Serah terima" description={fulfillment ? `${req.number} · NPBG ${fulfillment.no_npbg ?? ''}${fulfillment.seq > 1 ? ` (#${fulfillment.seq})` : ''} · ${fulfillment.lines.filter((l) => (qtyOf[l.id] ?? 0) > 0).length} barang sesuai tabel` : req.number} className="max-w-lg"
      footer={
        <Button className="w-full" size="lg" onClick={submit} disabled={handover.isPending || !ready}>
          {handover.isPending ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />}
          {handover.isPending ? 'Menyimpan…' : 'Selesai'}
        </Button>
      }>
      <div className="space-y-5">
        <section className="space-y-2">
          <StepTitle n={++n} done={!!photo}>Foto bukti</StepTitle>
          <input ref={file} type="file" accept="image/*" capture="environment" className="hidden" onChange={(e) => { pick(e.target.files?.[0]); e.target.value = '' }} />
          {photo ? (
            <div className="relative overflow-hidden rounded-lg border">
              <img src={photo} alt="Bukti serah terima" className="max-h-64 w-full bg-muted object-contain" />
              <Button type="button" size="sm" variant="outline" className="absolute right-2 top-2 bg-card/90" onClick={() => file.current?.click()}><RefreshCw className="size-4" /> Ganti</Button>
            </div>
          ) : (
            <button type="button" onClick={() => file.current?.click()} disabled={reading}
              className="flex w-full flex-col items-center justify-center gap-1.5 rounded-lg border-2 border-dashed px-4 py-8 text-sm text-muted-foreground transition-colors hover:border-primary hover:bg-primary/5 hover:text-primary">
              {reading ? <Loader2 className="size-7 animate-spin" /> : <Camera className="size-7" />}
              <span className="font-medium">{reading ? 'Memproses…' : 'Ambil foto'}</span>
            </button>
          )}
        </section>

        <section className="space-y-2">
          <StepTitle n={++n} done={!!signature}>Tanda tangan penerima</StepTitle>
          {open && <SignaturePad onChange={setSignature} />}
          {editingReceiver ? (
            <Input value={receiver} maxLength={150} autoFocus={!req.requester_name} onChange={(e) => setReceiver(e.target.value)} placeholder="Nama penerima" aria-label="Nama penerima" />
          ) : (
            <p className="text-center text-xs text-muted-foreground">
              Penerima: <span className="font-medium text-foreground">{receiver}</span>{' '}
              <button type="button" className="text-primary hover:underline" onClick={() => setEditingReceiver(true)}>ubah</button>
            </p>
          )}
        </section>

        {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}

function StepTitle({ n, done, children }: { n: number; done: boolean; children: React.ReactNode }) {
  return (
    <div className="flex items-center gap-2 text-sm font-semibold">
      <span className={done ? 'flex size-6 items-center justify-center rounded-full bg-emerald-600 text-xs text-white' : 'flex size-6 items-center justify-center rounded-full bg-primary/10 text-xs text-primary'}>
        {done ? <CheckCircle2 className="size-4" /> : n}
      </span>
      {children}
    </div>
  )
}
