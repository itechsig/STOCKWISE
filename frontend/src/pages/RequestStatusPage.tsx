import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ChevronDown, Inbox, Loader2, Search } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import {
  REQUEST_STATUS, forgetTrack, rememberTrack, trackedTokens, useLookupRequest, usePublicRequestStatus,
  type PublicRequestStatus,
} from '@/features/itemRequests/api'
import { RequestTimeline, TimelineBar } from '@/components/itemRequests/RequestTimeline'
import { RequestImages } from '@/components/itemRequests/RequestImages'
import { PublicShell } from '@/pages/PublicItemRequestPage'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { invalidTo } from '@/lib/notify'

const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) : '-')
const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })

function StatusCard({ r, open, onToggle }: { r: PublicRequestStatus; open: boolean; onToggle: () => void }) {
  return (
    <div className="rounded-lg border bg-card">
      <button type="button" onClick={onToggle} aria-expanded={open} className="block w-full space-y-3 p-4 text-left">
        <div className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <div className="font-mono font-semibold">{r.number}</div>
            <div className="text-xs text-muted-foreground">{fmtDate(r.created_at)} · {r.lines.length} barang</div>
          </div>
          <div className="flex items-center gap-1">
            <Badge variant={r.ready_to_pick ? 'warning' : REQUEST_STATUS[r.status]?.variant ?? 'neutral'}>{r.ready_to_pick ? 'Siap diambil' : r.status_label || REQUEST_STATUS[r.status]?.label}</Badge>
            <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
          </div>
        </div>
        <TimelineBar steps={r.timeline} />
        {/* what the requester should do / expect now, in plain words */}
        {r.ready_to_pick ? (
          <p className="rounded-md bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">Barang sudah disiapkan gudang. Silakan ambil di gudang dan tanda tangan saat serah terima.</p>
        ) : r.status === 'SELESAI' ? (
          <p className="text-sm font-medium text-emerald-700">Semua barang sudah diserahkan{r.received_by ? ` (diterima ${r.received_by})` : ''}.</p>
        ) : ['DIPENUHI_SEBAGIAN', 'MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG', 'DISIAPKAN'].includes(r.status) && r.lines.some((l) => (l.fulfilled_qty ?? 0) > 0) ? (
          <p className="text-sm font-medium text-teal-700">Sebagian barang sudah diserahkan, sisanya sedang diproses.</p>
        ) : ['MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG'].includes(r.status) ? (
          <p className="text-sm text-indigo-700">Barang belum ada di stok dan sedang dibeli. Anda akan dikabari saat barang datang.</p>
        ) : r.status === 'PERLU_PERBAIKAN' ? (
          <p className="text-sm text-orange-700">Request sedang diperbaiki admin gudang.</p>
        ) : null}
      </button>
      {open && (
        <div className="space-y-4 border-t p-4">
          <RequestTimeline steps={r.timeline} />
          {(r.images?.length ?? 0) > 0 && <div className="space-y-1.5 border-t pt-3"><div className="text-xs font-medium text-muted-foreground">Gambar</div><RequestImages slots={r.images} token={r.token} /></div>}
          <ul className="space-y-1 border-t pt-3 text-sm">
            {r.lines.map((l, i) => (
              <li key={i} className="flex justify-between gap-3">
                <span className="min-w-0">
                  <span className={cn('break-words', l.decision === 'TOLAK' && 'text-muted-foreground line-through')}>{l.deskripsi_barang}</span>
                  {l.line_status_label && <span className="block text-xs text-muted-foreground">{l.line_status_label}</span>}
                  {(l.images?.length ?? 0) > 0 && <RequestImages slots={l.images} token={r.token} lineId={l.id} size="size-12" className="mt-1 gap-1" />}
                </span>
                <span className="shrink-0 text-right tabular-nums text-muted-foreground">
                  {l.approved_qty != null && (l.fulfilled_qty ?? 0) > 0 ? `${fmtQty(Number(l.approved_qty))}/${fmtQty(l.fulfilled_qty ?? 0)}` : fmtQty(l.qty)} {l.satuan ?? ''}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

/**
 * Cek Permintaan — no login. /cek-permintaan lists the requests sent from this device;
 * /cek-permintaan/:token opens one. Others are found by number + name. Live.
 */
export function RequestStatusPage() {
  const { token } = useParams()
  const [tokens, setTokens] = useState<string[]>(() => trackedTokens())
  useEffect(() => {
    if (token) { rememberTrack(token); setTokens(trackedTokens()) }
  }, [token])
  const shown = token ? [token] : tokens
  const status = usePublicRequestStatus(shown)
  const [openId, setOpenId] = useState<string | null>(token ?? null)
  const lookup = useLookupRequest()
  const [name, setName] = useState('')
  const [err, setErr] = useState<string | null>(null)

  // requests that no longer exist drop off this device's list
  useEffect(() => {
    if (!status.data || token) return
    const alive = new Set(status.data.map((r) => r.token))
    const gone = tokens.filter((t) => !alive.has(t))
    if (gone.length) { gone.forEach(forgetTrack); setTokens(trackedTokens()) }
  }, [status.data, token, tokens])

  const find = () => {
    setErr(null)
    if (name.trim().length < 3) return invalidTo(setErr, 'Tulis nama Anda (minimal 3 huruf).')
    lookup.mutate(name.trim(), {
      onSuccess: (found) => { setTokens(trackedTokens()); setOpenId(found[0] ?? null); setName('') },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  const rows = status.data ?? []

  return (
    <PublicShell title="Cek Permintaan">
      <div className="space-y-3">
        {!token && (
          <div className="space-y-1.5">
            <div className="flex gap-2">
              <Input value={name} maxLength={150} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && find()}
                placeholder="Ketik nama Anda" aria-label="Nama Anda" autoComplete="name" />
              <Button onClick={find} disabled={lookup.isPending} aria-label="Cari">
                {lookup.isPending ? <Loader2 className="size-4 animate-spin" /> : <Search className="size-4" />} Cari
              </Button>
            </div>
            {err && <p className="text-sm text-destructive">{err}</p>}
          </div>
        )}
        {status.isLoading && shown.length > 0 && <p className="flex justify-center py-10"><Loader2 className="size-5 animate-spin text-muted-foreground" /></p>}
        {!status.isLoading && rows.length === 0 && (
          <div className="flex flex-col items-center gap-2 py-12 text-center text-muted-foreground">
            <Inbox className="size-10" />
            <p>{token ? 'Permintaan tidak ditemukan' : 'Cari permintaan Anda dengan nama'}</p>
          </div>
        )}
        {rows.map((r) => <StatusCard key={r.token} r={r} open={openId === r.token} onToggle={() => setOpenId((o) => (o === r.token ? null : r.token))} />)}

        {token && <p className="text-center text-sm"><Link to="/cek-permintaan" className="text-muted-foreground hover:text-foreground">Semua permintaan saya</Link></p>}
      </div>
    </PublicShell>
  )
}
