import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Pencil } from 'lucide-react'
import { lokasiText, useDecideNpbg, useResubmitNpbg, useNpbg, useUpdateNpbg } from '@/features/npbg/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { NpbgVerificationPanel } from '@/components/NpbgVerificationPanel'
import { KembaliBekasToggle } from '@/components/KembaliBekasToggle'
import { PrintDocButton } from '@/components/PrintDocButton'
import type { Npbg, NpbgEditableFields } from '@/features/npbg/api'
import { DocText } from '@/components/DocLink'
import { BackLink } from '@/components/BackLink'
import { DetailFallback } from '@/components/DetailFallback'
import { BYPASS_TEXT, isAutoApproval } from '@/lib/approval'
import { ResubmitPanel } from '@/components/approval/ResubmitPanel'
import { invalidTo } from '@/lib/notify'

function Field({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

function EditForm({ npbg, onDone }: { npbg: Npbg; onDone: () => void }) {
  const update = useUpdateNpbg(npbg.id)
  const [form, setForm] = useState<NpbgEditableFields>({
    tipe_npbg: npbg.tipe_npbg,
    deskripsi: npbg.deskripsi,
    nama_proyek: npbg.nama_proyek,
    dikeluarkan_oleh: npbg.dikeluarkan_oleh,
  })
  const [err, setErr] = useState<string | null>(null)

  const set = (k: keyof NpbgEditableFields) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value || null }))

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <p className="text-xs text-muted-foreground">
          Hanya field berikut yang bisa diubah manual — field lain mengikuti data Accurate dan akan diperbarui saat Sync Accurate.
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          {/* Tipe NPBG follows Accurate's Terms; only a manual NPBG sets its own */}
          {npbg.is_manual && (
            <div className="space-y-1.5">
              <label className="text-xs text-muted-foreground">Tipe NPBG</label>
              <Select value={form.tipe_npbg ?? ''} onChange={(e) => setForm((f) => ({ ...f, tipe_npbg: e.target.value || null }))}>
                <option value="">—</option>
                <option value="Penjualan">Penjualan</option>
                <option value="Non Penjualan">Non Penjualan</option>
              </Select>
            </div>
          )}
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">Nama Proyek</label>
            <Input value={form.nama_proyek ?? ''} onChange={set('nama_proyek')} />
          </div>
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">Dikeluarkan Oleh</label>
            <Input value={form.dikeluarkan_oleh ?? ''} onChange={set('dikeluarkan_oleh')} />
          </div>
          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs text-muted-foreground">Deskripsi</label>
            <Input value={form.deskripsi ?? ''} onChange={set('deskripsi')} />
          </div>
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="outline" onClick={onDone}>Batal</Button>
          <Button
            size="sm"
            disabled={update.isPending}
            onClick={() => update.mutate(form, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })}
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

export function NpbgDetailPage() {
  const { id } = useParams()
  const npbgId = Number(id)
  const { hasPermission } = useAuth()
  const { data: npbg, isLoading } = useNpbg(npbgId)
  const [editing, setEditing] = useState(false)

  useEffect(() => setEditing(false), [npbgId])

  if (isLoading || !npbg) return <div className="space-y-4"><BackLink to="/npbg" label="Daftar NPBG" /><DetailFallback loading={isLoading} /></div>
  if (editing) return <EditForm npbg={npbg} onDone={() => setEditing(false)} />

  return (
    <div className="max-w-3xl space-y-4">
      <BackLink to="/npbg" label="Daftar NPBG" />
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{npbg.no_npbg ?? '—'}</h1>
          <p className="text-sm text-muted-foreground">{fmtDate(npbg.tgl_npbg)}</p>
        </div>
        <div className="flex items-center gap-2">
          <PrintDocButton url={`/api/npbg/${npbg.id}/print`} docType="npbg" />
          {hasPermission('npbg.update') && (
            <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
              <Pencil className="size-4" /> Ubah
            </Button>
          )}
        </div>
      </div>

      {npbg.approval && <NpbgApprovalCard npbg={npbg} />}

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Tgl NPBG" value={fmtDate(npbg.tgl_npbg)} />
          <Field label="Shipdate" value={fmtDate(npbg.shipdate)} />
          <Field label="Taxdate" value={fmtDate(npbg.taxdate)} />
          <Field label="Tipe NPBG" value={npbg.tipe_npbg} />
          <Field label="Klasifikasi" value={npbg.klasifikasi} />
          <Field label="Divisi" value={npbg.divisi} />
          <Field label="Pelanggan" value={npbg.pelanggan} />
          <Field label="Nama Proyek" value={npbg.nama_proyek} />
          <Field label="Dikeluarkan Oleh" value={npbg.dikeluarkan_oleh} />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Kode Barang" value={npbg.kode_barang} />
          <Field label="Deskripsi Barang" value={npbg.deskripsi_barang} />
          <Field label="Kuantitas" value={npbg.kuantitas != null ? `${npbg.kuantitas} ${npbg.satuan ?? ''}` : null} />
          <Field label="Peminta" value={npbg.peminta} />
          <Field label="No. Seri" value={npbg.no_seri} />
          <Field label="Lokasi Gudang / Rak" value={lokasiText(npbg)} />
          <div className="sm:col-span-3 rounded-md border bg-muted/30 px-3 py-2">
            <KembaliBekasToggle npbg={npbg} showLink />
            <p className="mt-1 text-xs text-muted-foreground">Centang bila barang bekasnya harus dikembalikan ke gudang — baris ini masuk ke Tracking Pengembalian Bekas (Spare Part Lain) sampai RI kembalinya tercatat.</p>
          </div>
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Deskripsi</div>
            <div>{npbg.deskripsi ?? '—'}</div>
          </div>
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div className="whitespace-pre-wrap"><DocText text={npbg.keterangan} /></div>
          </div>
        </CardContent>
      </Card>

      {npbg.accurate_synced_at && (
        <p className="text-xs text-muted-foreground">
          Terakhir sync dari Accurate: {new Date(npbg.accurate_synced_at).toLocaleString('id-ID')}
        </p>
      )}

      <NpbgVerificationPanel npbgId={npbg.id} />
    </div>
  )
}

const NPBG_APPROVAL: Record<string, { label: string; cls: string }> = {
  MENUNGGU: { label: 'Menunggu persetujuan Manajer', cls: 'border-amber-300 bg-amber-50/60' },
  MENUNGGU_DIREKTUR: { label: 'Menunggu persetujuan Direktur', cls: 'border-amber-300 bg-amber-50/60' },
  DISETUJUI: { label: 'Disetujui — barang sudah dikeluarkan dari stok', cls: 'border-emerald-200 bg-emerald-50/50' },
  DITOLAK: { label: 'Ditolak', cls: 'border-red-200 bg-red-50/50' },
  DIKEMBALIKAN: { label: 'Dikembalikan untuk revisi', cls: 'border-orange-300 bg-orange-50/40' },
}
const NPBG_LOG: Record<string, string> = {
  APPROVE_MANAGER: 'Disetujui Manajer', APPROVE: 'Disetujui Direktur', REJECT: 'Ditolak',
  RETURN: 'Dikembalikan untuk revisi', DIAJUKAN_ULANG: 'Diajukan ulang (sudah direvisi)',
}

/** Manual NPBG: Manager → Direktur approve it before the goods leave the stock. */
function NpbgApprovalCard({ npbg }: { npbg: Npbg }) {
  const decide = useDecideNpbg(npbg.id)
  const resubmit = useResubmitNpbg(npbg.id)
  // reject, or send back to admin for a revision: both need a note
  const [rejecting, setRejecting] = useState<false | 'REJECT' | 'RETURN'>(false)
  const [note, setNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const a = npbg.approval!
  const meta = NPBG_APPROVAL[a.status]
  const run = (action: 'APPROVE' | 'REJECT' | 'RETURN') => {
    setErr(null)
    if (action !== 'APPROVE' && !note.trim()) return invalidTo(setErr, action === 'REJECT' ? 'Alasan penolakan wajib diisi.' : 'Catatan wajib diisi: tulis kenapa direvisi.')
    decide.mutate({ action, note: note.trim() || null }, {
      onSuccess: () => { setRejecting(false); setNote('') }, onError: (e) => setErr(apiErrorMessage(e)),
    })
  }
  return (
    <Card className={meta.cls}>
      <CardContent className="space-y-3 p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div className="font-medium">{meta.label}</div>
            <div className="text-xs text-muted-foreground">NPBG manual (bukan dari Request Barang) disetujui Manajer lalu Direktur sebelum barang keluar dari stok.</div>
          </div>
          {npbg.can_decide && !rejecting && (
            <div className="flex gap-2">
              <Button size="sm" variant="outline" className="border-orange-300 text-orange-700 hover:bg-orange-50" disabled={decide.isPending} onClick={() => setRejecting('RETURN')}>Revisi</Button>
              <Button size="sm" variant="outline" disabled={decide.isPending} onClick={() => setRejecting('REJECT')}>Tolak</Button>
              <Button size="sm" disabled={decide.isPending} onClick={() => run('APPROVE')}>Approve ({a.step === 'DIREKTUR' ? 'Direktur' : 'Manajer'})</Button>
            </div>
          )}
        </div>
        {rejecting && (
          <div className="flex flex-wrap gap-2">
            <input className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm" autoFocus placeholder={rejecting === 'RETURN' ? 'Kenapa direvisi? Apa yang perlu diubah? (wajib)' : 'Alasan tolak (wajib)'} value={note} onChange={(e) => setNote(e.target.value)} />
            <Button size="sm" variant="outline" onClick={() => setRejecting(false)}>Batal</Button>
            {rejecting === 'RETURN'
              ? <Button size="sm" className="bg-orange-600 hover:bg-orange-700" disabled={decide.isPending} onClick={() => run('RETURN')}>Kirim revisi ke admin</Button>
              : <Button size="sm" disabled={decide.isPending} onClick={() => run('REJECT')}>Tolak NPBG</Button>}
          </div>
        )}
        {err && <p className="text-xs text-destructive">{err}</p>}
        {a.status === 'DIKEMBALIKAN' && (
          <ResubmitPanel reason={a.note} by={a.by} canResubmit={!!npbg.can_resubmit} busy={resubmit.isPending}
            rows={[{ id: npbg.id, label: npbg.deskripsi_barang ?? '-', code: npbg.kode_barang, qty: Number(npbg.kuantitas), unit: npbg.satuan }]}
            onResubmit={(quantities, n) => resubmit.mutate({ quantities: Object.keys(quantities).length ? quantities : undefined, note: n })} />
        )}
        {(npbg.approval_log?.length ?? 0) > 0 && (
          <ol className="space-y-1 border-l pl-4 text-xs">
            {npbg.approval_log!.map((l) => (
              <li key={l.id}><span className="font-medium">{l.action === 'APPROVE_MANAGER' && isAutoApproval(l.note) ? BYPASS_TEXT : NPBG_LOG[l.action] ?? l.action}</span>{!isAutoApproval(l.note) && <span className="text-muted-foreground"> · {l.user_name ?? '-'}{l.note ? `, ${l.note}` : ''}</span>}</li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  )
}
