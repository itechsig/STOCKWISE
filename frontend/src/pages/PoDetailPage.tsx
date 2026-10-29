import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { openPoProof, useCompletePo, usePo, usePoAction, useUploadPoProof, useVendors, type PoLine, type PurchaseOrder } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { DataTable, type Column } from '@/components/DataTable'
import { PrintDocButton } from '@/components/PrintDocButton'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import { BackLink } from '@/components/BackLink'
import { DetailFallback } from '@/components/DetailFallback'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { Select } from '@/components/ui/select'
import { invalidTo } from '@/lib/notify'
import { QtyProgress } from '@/components/QtyProgress'

const rupiah = (n: number) => 'Rp ' + Number(n).toLocaleString('id-ID')

const lineColumns: Column<PoLine>[] = [
  { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (l) => l.description },
  // one PO number may hold items of several vendors
  { key: 'vendor', header: 'Vendor', cell: (l) => <span className="whitespace-nowrap">{l.vendor_name ?? ''}</span> },
  { key: 'qty', header: 'Qty', cell: (l) => `${l.qty} ${l.unit ?? ''}` },
  { key: 'price', header: 'Harga', cell: (l) => rupiah(l.unit_price) },
  { key: 'total', header: 'Subtotal', cell: (l) => rupiah(l.line_total) },
  // ordered / received so far (STOCKWISE count or the Accurate RI lines): a partial delivery reads "10/5"
  {
    key: 'rcv', header: 'Dipesan/Diterima',
    cell: (l) => <QtyProgress total={l.qty} unit={l.unit}
      done={Math.max(Number(l.qty_received ?? 0), (l.received_via ?? []).reduce((n, r) => n + Number(r.kuantitas ?? 0), 0))} />,
  },
  { key: 'ls', header: 'Status', cell: (l) => <RequestStatusBadge status={l.line_status} /> },
  {
    key: 'ppb',
    header: 'Dari PPB',
    cell: (l) =>
      l.source_ppb ? (
        <Link to={`/ppb/${l.source_ppb.id}`} className="font-mono text-xs text-primary hover:underline">
          {l.source_ppb.no_ppb ?? '—'}
        </Link>
      ) : (
        '—'
      ),
  },
  {
    key: 'ri',
    header: 'Diterima via RI',
    cell: (l) =>
      l.received_via && l.received_via.length > 0 ? (
        <div className="flex flex-col gap-0.5">
          {l.received_via.map((r) => (
            <Link key={r.id} to={`/ri/${r.id}`} className="font-mono text-xs text-primary hover:underline">
              {r.no_ri ?? '—'}
            </Link>
          ))}
        </div>
      ) : (
        '—'
      ),
  },
]

export function PoDetailPage() {
  const { id } = useParams()
  const poId = Number(id)
  const { hasPermission } = useAuth()
  const { data: po, isLoading } = usePo(poId)
  const action = usePoAction(poId)
  const [err, setErr] = useState<string | null>(null)

  if (isLoading || !po) return <div className="space-y-4"><BackLink to="/purchase-orders" label="Daftar Purchase Order" /><DetailFallback loading={isLoading} /></div>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  const isFromAccurate = po.accurate_po_id != null
  // STOCKWISE PO: Direktur approval (the internal approve button is the Direktur's)
  const stockwise = !isFromAccurate && (po.approval_status != null || po.can_complete)

  return (
    <div className="max-w-4xl space-y-4">
      <BackLink to="/purchase-orders" label="Daftar Purchase Order" />
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{po.number}</h1>
          <p className="text-sm text-muted-foreground">{(po.vendors?.length ?? 0) > 1 ? po.vendors!.map((v) => v.name).join(' · ') : po.vendor?.name ?? po.vendor_name ?? '—'}</p>
        </div>
        <div className="flex items-center gap-2">
          <PrintDocButton url={`/api/purchase-orders/${po.id}/print`} docType="po" />
          {isFromAccurate ? <Badge variant="default">Accurate</Badge> : <Badge variant="neutral">Manual</Badge>}
          <RequestStatusBadge status={po.status} />
        </div>
      </div>

      {isFromAccurate && (
        <p className="text-xs text-muted-foreground">
          PO ini mirror dari Accurate — read-only, tidak melalui alur approve/send/batalkan internal.
          {po.accurate_synced_at && ` Terakhir sync: ${new Date(po.accurate_synced_at).toLocaleString('id-ID')}.`}
        </p>
      )}

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Tanggal" value={po.date ? new Date(po.date).toLocaleDateString('id-ID') : '—'} />
          <Field label="Perkiraan Tiba" value={po.expected_date ?? '—'} />
          <Field label="Total" value={rupiah(po.total)} />
          <Field label="Dibuat" value={new Date(po.created_at).toLocaleString('id-ID')} />
          <Field label="Disetujui" value={po.approved_at ? new Date(po.approved_at).toLocaleString('id-ID') : '—'} />
          {po.notes && <Field label="Catatan" value={po.notes} />}
        </CardContent>
      </Card>

      {stockwise && <DirectorApproval po={po} onError={setErr} />}
      {!isFromAccurate && (po.can_upload_proof || po.proof) && <PurchaseProof po={po} onError={setErr} />}

      {!isFromAccurate && (
        <div className="flex flex-wrap items-center gap-2">
          {po.status === 'DRAFT' && !stockwise && hasPermission('po.approve') && (
            <Button size="sm" onClick={() => run('approve')}>
              Approve
            </Button>
          )}
          {po.status === 'APPROVED' && hasPermission('po.send') && (
            <Button size="sm" onClick={() => run('send')}>
              Kirim ke Vendor
            </Button>
          )}
          {!['RECEIVED', 'CLOSED', 'CANCELLED', 'PARTIAL_RECEIVED'].includes(po.status) && hasPermission('po.cancel') && (
            <Button size="sm" variant="destructive" onClick={() => run('cancel', { reason: 'Dibatalkan dari UI' })}>
              Batalkan
            </Button>
          )}
        </div>
      )}

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={po.items ?? []} rowKey={(l) => l.id} />
    </div>
  )
}

function Field({ label, value }: { label: string; value?: string | null }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}

const PO_APPROVAL: Record<string, { label: string; cls: string }> = {
  MENUNGGU_DIREKTUR: { label: 'Menunggu approval Direktur', cls: 'border-amber-300 bg-amber-50/60' },
  DIKEMBALIKAN: { label: 'Perlu revisi dari Direktur — perbaiki lalu ajukan lagi', cls: 'border-orange-300 bg-orange-50/60' },
  DISETUJUI: { label: 'Disetujui Direktur', cls: 'border-emerald-200 bg-emerald-50/50' },
}
const LOG_TEXT: Record<string, string> = { DIAJUKAN: 'Diajukan ke Direktur', APPROVE: 'Disetujui Direktur', REJECT: 'Dikembalikan Direktur', DIBELI: 'Sudah dibeli' }

/** Approved PO: upload the proof of purchase (nota / invoice, photo or PDF) → status "Sudah dibeli". */
function PurchaseProof({ po, onError }: { po: PurchaseOrder; onError: (m: string | null) => void }) {
  const upload = useUploadPoProof(po.id)
  const input = useRef<HTMLInputElement>(null)
  const [opening, setOpening] = useState(false)
  const pick = (file?: File) => {
    if (!file) return
    if (file.size > 15 * 1024 * 1024) return onError('Ukuran file maksimal 15 MB.')
    onError(null)
    upload.mutate(file, { onError: (e) => onError(apiErrorMessage(e)), onSettled: () => { if (input.current) input.current.value = '' } })
  }
  const view = () => {
    setOpening(true)
    openPoProof(po.id).catch((e) => onError(apiErrorMessage(e))).finally(() => setOpening(false))
  }

  return (
    <Card className={po.proof ? 'border-emerald-200 bg-emerald-50/50' : 'border-primary/40 bg-primary/5'}>
      <CardContent className="flex flex-col gap-3 p-4 text-sm sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <div className="font-medium">{po.proof ? 'Sudah dibeli' : 'Upload bukti pembelian'}</div>
          <div className="break-words text-xs text-muted-foreground">
            {po.proof
              ? `Bukti: ${po.proof.file_name ?? 'file'}${po.proof.at ? ` · ${new Date(po.proof.at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}` : ''} — barang diterima lewat RI.`
              : 'PO sudah disetujui. Unggah nota / invoice pembelian (foto atau PDF), status otomatis menjadi "Sudah dibeli".'}
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          {po.proof && <Button size="sm" variant="outline" disabled={opening} onClick={view}>{opening ? 'Membuka…' : 'Lihat bukti'}</Button>}
          {po.can_upload_proof && <>
            <input ref={input} type="file" className="hidden" accept="image/png,image/jpeg,image/webp,application/pdf"
              onChange={(e) => pick(e.target.files?.[0])} />
            <Button size="sm" variant={po.proof ? 'outline' : 'default'} disabled={upload.isPending} onClick={() => input.current?.click()}>
              {upload.isPending ? 'Mengunggah…' : po.proof ? 'Ganti bukti' : 'Upload bukti pembelian'}
            </Button>
          </>}
        </div>
      </CardContent>
    </Card>
  )
}

/** STOCKWISE PO: purchasing completes it (vendor / price) → the Direktur approves or sends it back. */
function DirectorApproval({ po, onError }: { po: PurchaseOrder; onError: (m: string | null) => void }) {
  const action = usePoAction(po.id)
  const [completing, setCompleting] = useState(false)
  const [rejecting, setRejecting] = useState(false)
  const [note, setNote] = useState('')
  const waitingEntry = po.status === 'MENUNGGU_PO'
  const meta = po.approval_status ? PO_APPROVAL[po.approval_status] : null
  const run = (name: string, body?: unknown) => action.mutate({ action: name, body }, { onSuccess: () => { setRejecting(false); setNote('') }, onError: (e) => onError(apiErrorMessage(e)) })

  return (
    <Card className={meta?.cls ?? (waitingEntry ? 'border-primary/40 bg-primary/5' : undefined)}>
      <CardContent className="space-y-3 p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div className="font-medium">{waitingEntry ? 'Menunggu PO — barangnya ada di Draft PO' : meta?.label ?? 'PO STOCKWISE'}</div>
            <div className="text-xs text-muted-foreground">
              {po.from_ppb_approval ? `Dari PPB ${po.from_ppb_approval} yang sudah disetujui. ` : ''}PO STOCKWISE disetujui oleh Direktur sebelum dikirim ke vendor.
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {po.can_complete && (waitingEntry
              ? <Button size="sm" asChild><Link to="/purchase-orders/draft">Buka Draft PO</Link></Button>
              : <Button size="sm" variant="outline" onClick={() => setCompleting(true)}>Perbaiki & ajukan lagi</Button>)}
            {po.can_approve && !rejecting && <>
              <Button size="sm" variant="outline" className="border-orange-300 text-orange-700 hover:bg-orange-50" disabled={action.isPending} onClick={() => setRejecting(true)}>Revisi</Button>
              <Button size="sm" disabled={action.isPending} onClick={() => run('approve')}>Approve (Direktur)</Button>
            </>}
          </div>
        </div>
        {rejecting && (
          <div className="flex flex-wrap gap-2">
            <Input className="min-w-0 flex-1" autoFocus placeholder="Kenapa direvisi? Apa yang perlu diubah? (wajib)" value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} />
            <Button size="sm" variant="outline" className="h-10" onClick={() => setRejecting(false)}>Batal</Button>
            <Button size="sm" className="h-10 bg-orange-600 hover:bg-orange-700" disabled={action.isPending}
              onClick={() => (note.trim() ? run('reject', { note: note.trim() }) : invalidTo(onError, 'Catatan wajib diisi: tulis kenapa direvisi.'))}>Kirim revisi ke purchasing</Button>
          </div>
        )}
        {(po.approval_log?.length ?? 0) > 0 && (
          <ol className="space-y-1.5 border-l pl-4">
            {po.approval_log!.map((l) => (
              <li key={l.id}>
                <span className="font-medium">{LOG_TEXT[l.action] ?? l.action}</span>
                <span className="text-muted-foreground"> · {l.user_name ?? '-'} · {l.created_at ? new Date(l.created_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : ''}</span>
                {l.note && <div className="text-muted-foreground">{l.note}</div>}
              </li>
            ))}
          </ol>
        )}
      </CardContent>
      {completing && <CompletePoModal po={po} onClose={() => setCompleting(false)} />}
    </Card>
  )
}

/** Purchasing fixes a STOCKWISE PO the Direktur sent back: its vendor (one PO = one vendor), qty and prices. */
function CompletePoModal({ po, onClose }: { po: PurchaseOrder; onClose: () => void }) {
  const complete = useCompletePo(po.id)
  const { data: vendors } = useVendors({ limit: 2000, is_active: '1' })
  const [vendorId, setVendorId] = useState<number | ''>(po.vendor?.id ?? '')
  const [expected, setExpected] = useState(po.expected_date ?? '')
  const [lines, setLines] = useState(() => (po.items ?? []).map((l) => ({
    id: l.id, code: l.item_code, description: l.description, unit: l.unit, qty: String(Number(l.qty)), price: String(Number(l.unit_price) || ''),
  })))
  const [err, setErr] = useState<string | null>(null)
  const num = (v: string) => Number(v.trim().replace(',', '.'))
  const total = lines.reduce((t, l) => t + (num(l.qty) || 0) * (num(l.price) || 0), 0)
  const set = (i: number, patch: Partial<(typeof lines)[number]>) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, ...patch } : x)))
  const submit = () => {
    setErr(null)
    if (!vendorId) return invalidTo(setErr, 'Pilih vendor PO ini.')
    if (lines.some((l) => !(num(l.qty) > 0) || !(num(l.price) >= 0) || l.price.trim() === '')) return invalidTo(setErr, 'Isi qty (> 0) dan harga setiap barang.')
    complete.mutate({
      vendor_id: Number(vendorId), expected_date: expected || null,
      lines: lines.map((l) => ({ id: l.id, qty: num(l.qty), unit_price: num(l.price) })),
    }, { onSuccess: onClose, onError: (e) => setErr(apiErrorMessage(e)) })
  }
  return (
    <Modal open onClose={onClose} title={`Lengkapi ${po.number}`} className="max-w-5xl"
      description="Vendor dan harga tiap barang, lalu PO diajukan lagi ke Direktur. Satu PO untuk satu vendor."
      footer={<>
        <span className="mr-auto text-sm font-medium">Total {rupiah(total)}</span>
        <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
        <Button size="sm" disabled={complete.isPending} onClick={submit}>Ajukan ke Direktur</Button>
      </>}>
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1"><Label htmlFor="cp-vendor">Vendor *</Label>
            <Select id="cp-vendor" value={vendorId} onChange={(e) => setVendorId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">Pilih vendor…</option>
              {(vendors ?? []).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </Select>
          </div>
          <div className="space-y-1"><Label htmlFor="cp-exp">Perkiraan tiba</Label><Input id="cp-exp" type="date" value={expected} onChange={(e) => setExpected(e.target.value)} /></div>
        </div>
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="whitespace-nowrap border-b bg-muted text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="w-10 px-3 py-2.5 text-center font-medium">No</th>
                <th className="min-w-[14rem] px-3 py-2.5 font-medium">Barang</th>
                <th className="px-3 py-2.5 text-right font-medium">Qty</th>
                <th className="px-3 py-2.5 text-right font-medium">Harga (Rp)</th>
                <th className="px-3 py-2.5 text-right font-medium">Subtotal</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {lines.map((l, i) => (
                <tr key={l.id} className="align-top even:bg-muted/20">
                  <td className="px-3 py-2.5 text-center tabular-nums text-muted-foreground">{i + 1}</td>
                  <td className="px-3 py-2.5"><div className="font-medium">{l.description}</div><div className="font-mono text-xs text-muted-foreground">{[l.code, l.unit].filter(Boolean).join(' · ')}</div></td>
                  <td className="px-3 py-2"><Input className="ml-auto h-9 w-24 text-right tabular-nums" inputMode="decimal" value={l.qty} aria-label={`Qty ${l.description}`} onChange={(e) => set(i, { qty: e.target.value })} /></td>
                  <td className="px-3 py-2"><Input className="ml-auto h-9 w-32 text-right tabular-nums" inputMode="decimal" value={l.price} placeholder="harga" aria-label={`Harga ${l.description}`} onChange={(e) => set(i, { price: e.target.value })} /></td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{rupiah((num(l.qty) || 0) * (num(l.price) || 0))}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
