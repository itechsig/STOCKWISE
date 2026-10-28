import { useState } from 'react'
import { Loader2, Send, Undo2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { invalidTo } from '@/lib/notify'

export interface ResubmitRow { id: number; label: string; code?: string | null; qty: number; unit?: string | null }

const fmtQty = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 2 })

/**
 * A document the Manager / Direktur sent back: why (for everyone), and for admin the revision —
 * its quantities (a note is required when one changed), then "Ajukan ulang" to the approval again.
 */
export function ResubmitPanel({ reason, by, rows, canResubmit, busy, onResubmit }: {
  reason: string | null
  by: string | null
  rows: ResubmitRow[]
  canResubmit: boolean
  busy: boolean
  onResubmit: (quantities: Record<number, number>, note: string | null) => void
}) {
  const [qty, setQty] = useState<Record<number, string>>({})
  const [note, setNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const changed: Record<number, number> = {}
  let bad: ResubmitRow | null = null
  for (const r of rows) {
    const v = qty[r.id]
    if (v === undefined) continue
    const q = Number(v.trim().replace(',', '.'))
    if (!Number.isFinite(q) || q <= 0) bad ??= r
    else if (Math.abs(q - r.qty) > 0.0001) changed[r.id] = q
  }
  const anyChange = Object.keys(changed).length > 0
  const send = () => {
    setErr(null)
    if (bad) return invalidTo(setErr, `Jumlah ${bad.label} harus lebih dari 0.`)
    if (anyChange && !note.trim()) return invalidTo(setErr, 'Jumlah diubah: tulis catatan revisinya.')
    onResubmit(changed, note.trim() || null)
  }

  return (
    <div className="space-y-3 rounded-lg border border-orange-300 bg-orange-50/70 p-3 text-sm">
      <div className="flex gap-2">
        <Undo2 className="mt-0.5 size-4 shrink-0 text-orange-700" />
        <div>
          <div className="font-semibold text-orange-900">Dikembalikan untuk revisi{by ? ` oleh ${by}` : ''}</div>
          {reason && <p className="text-orange-900/80">{reason}</p>}
          {!canResubmit && <p className="mt-1 text-xs text-orange-900/70">Menunggu admin merevisi lalu mengajukan ulang.</p>}
        </div>
      </div>
      {canResubmit && (
        <>
          <div className="overflow-x-auto rounded-md border bg-card">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr><th className="px-3 py-2 font-medium">Barang</th><th className="px-3 py-2 text-right font-medium">Jumlah</th></tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((r) => {
                  const v = qty[r.id] ?? String(r.qty)
                  const diff = changed[r.id] !== undefined
                  return (
                    <tr key={r.id}>
                      <td className="px-3 py-2"><div>{r.label}</div>{r.code && <div className="font-mono text-xs text-muted-foreground">{r.code}</div>}</td>
                      <td className="px-3 py-2 text-right">
                        <span className="inline-flex items-center gap-1.5">
                          <Input className={'h-8 w-24 text-right tabular-nums' + (diff ? ' border-amber-400' : '')} inputMode="decimal" aria-label={`Jumlah ${r.label}`}
                            value={v} onChange={(e) => setQty((x) => ({ ...x, [r.id]: e.target.value }))} />
                          <span className="w-10 text-left text-xs text-muted-foreground">{r.unit ?? ''}</span>
                        </span>
                        {diff && <div className="text-xs text-amber-700">semula {fmtQty(r.qty)}</div>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input className={'bg-card' + (anyChange && !note.trim() ? ' border-amber-400' : '')} value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)}
              placeholder={anyChange ? 'Catatan revisi (wajib, jumlah diubah)' : 'Catatan revisi (opsional)'} aria-label="Catatan revisi" />
            <Button className="shrink-0" disabled={busy} onClick={send}>
              {busy ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />} Ajukan ulang
            </Button>
          </div>
          {err && <p className="text-xs text-destructive">{err}</p>}
        </>
      )}
    </div>
  )
}
