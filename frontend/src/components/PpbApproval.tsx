import { useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, Check, X, Undo2 } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { APPROVAL_STATUS, useDecidePpb, useResubmitPpb, type Ppb, type PpbApproval } from '@/features/ppb/api'
import { ResubmitPanel } from '@/components/approval/ResubmitPanel'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { BYPASS_TEXT, isAutoApproval } from '@/lib/approval'
import { invalidTo } from '@/lib/notify'

const fmt = (v: string | null) => (v ? new Date(v).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '')

/** Approval of a PPB before PO; red when Accurate made a PO before anyone approved. */
export function PpbApprovalBadge({ approval }: { approval?: PpbApproval | null }) {
  if (!approval) return <span className="text-muted-foreground">-</span>
  if (approval.po_without_approval) {
    return <Badge variant="danger" title="PO dibuat di Accurate sebelum PPB di-approve"><AlertTriangle className="mr-1 size-3" />PO tanpa approval</Badge>
  }
  // PPBs from before the approval rule: nothing to act on, so no badge competing for attention
  if (approval.status === 'TIDAK_PERLU') return <span className="text-muted-foreground" title="PPB dibuat sebelum aturan approval berlaku">-</span>
  const s = APPROVAL_STATUS[approval.status]
  return <Badge variant={s.variant} title={approval.by ? `${s.label} oleh ${approval.by}${approval.at ? `, ${fmt(approval.at)}` : ''}` : undefined}>{s.label}</Badge>
}

const LOG_LABEL: Record<string, { label: string; cls: string }> = {
  DIAJUKAN: { label: 'Masuk, menunggu approval', cls: 'bg-amber-500' },
  APPROVE_MANAGER: { label: 'Disetujui Manager', cls: 'bg-emerald-400' },
  APPROVE: { label: 'Disetujui', cls: 'bg-emerald-500' },
  RETURN: { label: 'Dikembalikan untuk revisi', cls: 'bg-orange-500' },
  DIAJUKAN_ULANG: { label: 'Diajukan ulang (sudah direvisi)', cls: 'bg-amber-500' },
  REJECT: { label: 'Ditolak', cls: 'bg-red-500' },
  PO_TANPA_APPROVAL: { label: 'PO dibuat tanpa approval', cls: 'bg-red-600' },
}

/** PPB detail: approve / reject the whole PPB document in one click, and its approval log. */
export function PpbApprovalCard({ ppb }: { ppb: Ppb }) {
  const { hasPermission } = useAuth()
  const decide = useDecidePpb(ppb.id)
  const resubmit = useResubmitPpb(ppb.id)
  // reject, or send back to admin for a revision: both need a note
  const [rejecting, setRejecting] = useState<false | 'REJECT' | 'RETURN'>(false)
  const [note, setNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const approval = ppb.approval
  if (!approval || !ppb.no_ppb) return null
  const before = approval.status === 'TIDAK_PERLU'
  // a PPB from before the rule: only worth a card when it lists several items
  if (before && (ppb.document_items?.length ?? 0) <= 1) return null

  // STOCKWISE PPB: Manager then Direktur (the server says whose step it is); Accurate's: one step
  const can = ppb.can_decide ?? (hasPermission('ppb.approve') && approval.status !== 'TIDAK_PERLU')
  const stepLabel = approval.stockwise ? (approval.status === 'MENUNGGU_DIREKTUR' || (approval.status === 'MENUNGGU' && !hasPermission('ppb.approve_manager') && hasPermission('ppb.approve_director')) ? ' (Direktur)' : approval.status === 'MENUNGGU' ? ' (Manager)' : '') : ''
  const send = (action: 'APPROVE' | 'REJECT' | 'RETURN') => {
    setErr(null)
    if (action === 'REJECT' && !note.trim()) return invalidTo(setErr, 'Isi alasan tolak.')
    if (action === 'RETURN' && !note.trim()) return invalidTo(setErr, 'Catatan wajib diisi: tulis kenapa direvisi.')
    decide.mutate({ action, note: note.trim() || null }, {
      onSuccess: () => { setRejecting(false); setNote('') },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  return (
    <Card className={approval.po_without_approval ? 'border-red-300' : approval.status === 'MENUNGGU' ? 'border-amber-300' : undefined}>
      <CardContent className="space-y-3 p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="space-y-0.5">
            <div className="flex items-center gap-2 font-medium">{before ? 'Dokumen PPB' : <>Approval sebelum PO <PpbApprovalBadge approval={approval} /></>}</div>
            <div className="text-xs text-muted-foreground">
              {approval.status === 'TIDAK_PERLU'
                ? 'PPB ini dibuat sebelum aturan approval berlaku.'
                : `Berlaku untuk seluruh PPB ${ppb.no_ppb}${ppb.document_lines && ppb.document_lines > 1 ? ` (${ppb.document_lines} barang)` : ''}.${approval.stockwise ? ' PPB STOCKWISE: disetujui Manager, lalu Direktur.' : ''}`}
              {approval.status === 'DISETUJUI' && ppb.approval_po && (
                <> Masuk ke tabel PO sebagai <Link to={`/purchase-orders/${ppb.approval_po.id}`} className="font-mono text-primary hover:underline">{ppb.approval_po.number}</Link>{approval.stockwise ? ' — lengkapi vendor & harga di sana, lalu disetujui Direktur.' : ' (Menunggu PO, sampai PO dibuat di Accurate).'}</>
              )}
            </div>
          </div>
          {can && !rejecting && (
            <div className="flex gap-2">
              {approval.stockwise && ['MENUNGGU', 'MENUNGGU_DIREKTUR'].includes(approval.status) && (
                <Button size="sm" variant="outline" className="border-orange-300 text-orange-700 hover:bg-orange-50" disabled={decide.isPending} onClick={() => { setRejecting('RETURN'); setErr(null) }}><Undo2 className="size-4" /> Revisi</Button>
              )}
              {approval.status !== 'DITOLAK' && (
                <Button size="sm" variant="outline" disabled={decide.isPending} onClick={() => { setRejecting('REJECT'); setErr(null) }}><X className="size-4" /> Tolak</Button>
              )}
              {approval.status !== 'DISETUJUI' && (
                <Button size="sm" disabled={decide.isPending} onClick={() => send('APPROVE')}><Check className="size-4" /> Approve{stepLabel}</Button>
              )}
            </div>
          )}
        </div>
        {rejecting && (
          <div className="flex flex-wrap gap-2">
            <Input className="min-w-0 flex-1" autoFocus placeholder={rejecting === 'RETURN' ? 'Kenapa direvisi? Apa yang perlu diubah? (wajib)' : 'Alasan tolak (wajib)'} value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} />
            <Button size="sm" variant="outline" className="h-10" onClick={() => { setRejecting(false); setErr(null) }}>Batal</Button>
            {rejecting === 'RETURN'
              ? <Button size="sm" className="h-10 bg-orange-600 hover:bg-orange-700" disabled={decide.isPending} onClick={() => send('RETURN')}>Kirim revisi ke admin</Button>
              : <Button size="sm" className="h-10 bg-red-600 hover:bg-red-700" disabled={decide.isPending} onClick={() => send('REJECT')}>Tolak PPB</Button>}
          </div>
        )}
        {err && <p className="text-xs text-destructive">{err}</p>}
        {approval.status === 'DIKEMBALIKAN' && (
          <ResubmitPanel reason={approval.note} by={approval.by} canResubmit={!!ppb.can_resubmit} busy={resubmit.isPending}
            rows={(ppb.document_items ?? []).map((it) => ({ id: it.id, label: it.deskripsi_barang ?? '-', code: it.kode_barang, qty: Number(it.kuantitas), unit: it.satuan }))}
            onResubmit={(quantities, n) => resubmit.mutate({ quantities: Object.keys(quantities).length ? quantities : undefined, note: n })} />
        )}

        {/* the approval covers every line of the document: show them all before anyone clicks */}
        {(ppb.document_items?.length ?? 0) > 1 && (
          <div>
            <div className="mb-1 text-xs font-medium text-muted-foreground">Isi PPB ({ppb.document_items!.length} barang)</div>
            <ul className="divide-y rounded-md border bg-card">
              {ppb.document_items!.map((it, i) => (
                <li key={it.id} className={`flex items-start gap-3 px-3 py-2 ${it.id === ppb.id ? 'bg-primary/5' : ''}`}>
                  <span className="w-5 shrink-0 pt-0.5 text-right text-xs text-muted-foreground">{i + 1}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block">{it.deskripsi_barang ?? '-'}</span>
                    <span className="block text-xs text-muted-foreground">
                      <span className="font-mono">{it.kode_barang ?? '-'}</span>{it.peminta ? ` · ${it.peminta}` : ''}{it.catatan_baris ? ` · ${it.catatan_baris}` : ''}
                    </span>
                  </span>
                  <span className="shrink-0 whitespace-nowrap font-medium tabular-nums">{it.kuantitas ?? '-'} {it.satuan ?? ''}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {(ppb.approval_log?.length ?? 0) > 0 && (
          <div>
            <div className="mb-1 text-xs font-medium text-muted-foreground">Log approval</div>
            <ol className="space-y-2 border-l pl-4">
              {ppb.approval_log!.map((l) => {
                const meta = l.action === 'APPROVE_MANAGER' && isAutoApproval(l.note) ? { label: BYPASS_TEXT, cls: 'bg-sky-400' } : LOG_LABEL[l.action] ?? { label: l.action, cls: 'bg-muted-foreground' }
                return (
                  <li key={l.id} className="relative">
                    <span className={`absolute -left-[1.3rem] top-1.5 size-2.5 rounded-full ${meta.cls}`} />
                    <div><span className="font-medium">{meta.label}</span> <span className="text-muted-foreground">· {l.user_name ?? 'Sistem'} · {fmt(l.created_at)}</span></div>
                    {l.note && <div className="text-muted-foreground">{l.note}</div>}
                  </li>
                )
              })}
            </ol>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
