import { useState } from 'react'
import { AlertTriangle } from 'lucide-react'
import {
  fileToDataUrl,
  isVerificationOpen,
  useBosDecide,
  useEscalateVerification,
  useNpbgVerifications,
  useOfferAlternative,
  useOpenVerification,
  useProcessVerification,
  useRespondVerification,
  type NpbgVerification,
  type VerificationStatus,
} from '@/features/npbg/verification-api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Modal } from '@/components/ui/modal'

const STATUS_LABEL: Record<VerificationStatus, string> = {
  DIAJUKAN: 'Diajukan',
  DIPROSES: 'Diproses',
  ALTERNATIF_DITAWARKAN: 'Alternatif Ditawarkan',
  MENUNGGU_RESPON: 'Menunggu Respon',
  PERLU_VERIFIKASI_BOS: 'Perlu Verifikasi BOS',
  DISETUJUI: 'Disetujui',
  DITOLAK: 'Ditolak',
  SELESAI: 'Selesai',
}

function statusVariant(status: VerificationStatus): 'default' | 'warning' | 'success' | 'danger' | 'neutral' {
  if (status === 'SELESAI' || status === 'DISETUJUI') return 'success'
  if (status === 'DITOLAK') return 'danger'
  if (status === 'PERLU_VERIFIKASI_BOS') return 'warning'
  return 'default'
}

function StatusBadge({ status }: { status: VerificationStatus }) {
  return <Badge variant={statusVariant(status)}>{STATUS_LABEL[status]}</Badge>
}

function AttachmentInput({ onChange }: { onChange: (dataUrl: string | undefined) => void }) {
  return (
    <div className="space-y-1">
      <label className="text-xs text-muted-foreground">Lampiran (opsional — foto/dokumen bukti)</label>
      <input
        type="file"
        accept="image/*,.pdf"
        className="block w-full text-xs"
        onChange={async (e) => {
          const file = e.target.files?.[0]
          onChange(file ? await fileToDataUrl(file) : undefined)
        }}
      />
    </div>
  )
}

function OpenForm({ npbgId, onDone }: { npbgId: number; onDone: () => void }) {
  const open = useOpenVerification(npbgId)
  const [alasan, setAlasan] = useState('')
  const [attachment, setAttachment] = useState<string>()
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <Textarea placeholder="Alasan pengajuan klarifikasi — mis. barang yang diissue beda merk/spek dari yang diminta"
        value={alasan} onChange={(e) => setAlasan(e.target.value)} />
      <AttachmentInput onChange={setAttachment} />
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2">
        <Button size="sm" variant="outline" onClick={onDone}>Batal</Button>
        <Button size="sm" disabled={!alasan || open.isPending}
          onClick={() => open.mutate({ alasan_pengajuan: alasan, attachment },
            { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })}>
          Ajukan
        </Button>
      </div>
    </div>
  )
}

function VerificationCase({ npbgId, v }: { npbgId: number; v: NpbgVerification }) {
  const { hasPermission } = useAuth()
  const canManage = hasPermission('npbg.verification.manage')
  const canRespond = hasPermission('npbg.verification.respond')
  const canBosDecide = hasPermission('npbg.verification.bos_decide')

  const process = useProcessVerification(npbgId, v.id)
  const offer = useOfferAlternative(npbgId, v.id)
  const respond = useRespondVerification(npbgId, v.id)
  const escalate = useEscalateVerification(npbgId, v.id)
  const bosDecide = useBosDecide(npbgId, v.id)

  const [modal, setModal] = useState<'offer' | 'reject' | 'bos' | null>(null)
  const [text, setText] = useState('')
  const [attachment, setAttachment] = useState<string>()
  const [err, setErr] = useState<string | null>(null)

  const closeModal = () => { setModal(null); setText(''); setAttachment(undefined); setErr(null) }

  return (
    <Card>
      <CardContent className="space-y-3 p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <StatusBadge status={v.status} />
          <span className="text-xs text-muted-foreground">
            Diajukan {v.opened_by ? `oleh ${v.opened_by}` : ''} · {new Date(v.created_at).toLocaleString('id-ID')}
          </span>
        </div>
        <p><span className="text-muted-foreground">Alasan pengajuan:</span> {v.alasan_pengajuan ?? '—'}</p>
        {v.deskripsi_alternatif && <p><span className="text-muted-foreground">Barang alternatif:</span> {v.deskripsi_alternatif}</p>}
        {v.alasan_penolakan && <p><span className="text-muted-foreground">Alasan tolak Maintenance:</span> {v.alasan_penolakan}</p>}
        {v.keputusan_bos && (
          <p>
            <span className="text-muted-foreground">Keputusan BOS:</span> {v.keputusan_bos}
            {v.catatan_bos ? ` — ${v.catatan_bos}` : ''}
          </p>
        )}

        {err && <p className="text-sm text-destructive">{err}</p>}

        <div className="flex flex-wrap gap-2">
          {v.status === 'DIAJUKAN' && canManage && (
            <Button size="sm" variant="outline" disabled={process.isPending}
              onClick={() => process.mutate({}, { onError: (e) => setErr(apiErrorMessage(e)) })}>
              Proses
            </Button>
          )}
          {v.status === 'DIPROSES' && canManage && (
            <Button size="sm" variant="outline" onClick={() => setModal('offer')}>Tawarkan Alternatif</Button>
          )}
          {v.status === 'MENUNGGU_RESPON' && canRespond && (
            <>
              <Button size="sm" disabled={respond.isPending}
                onClick={() => respond.mutate({ decision: 'ACCEPT' }, { onError: (e) => setErr(apiErrorMessage(e)) })}>
                Terima Alternatif
              </Button>
              <Button size="sm" variant="destructive" onClick={() => setModal('reject')}>Tolak</Button>
            </>
          )}
          {isVerificationOpen(v.status) && v.status !== 'PERLU_VERIFIKASI_BOS' && canManage && (
            <Button size="sm" variant="outline" disabled={escalate.isPending}
              onClick={() => escalate.mutate({}, { onError: (e) => setErr(apiErrorMessage(e)) })}>
              Eskalasi ke BOS
            </Button>
          )}
          {v.status === 'PERLU_VERIFIKASI_BOS' && canBosDecide && (
            <Button size="sm" onClick={() => setModal('bos')}>Keputusan BOS</Button>
          )}
        </div>

        {v.logs && v.logs.length > 0 && (
          <details className="pt-1">
            <summary className="cursor-pointer text-xs text-muted-foreground">Riwayat audit trail ({v.logs.length})</summary>
            <ul className="mt-2 space-y-1.5 border-l pl-3">
              {v.logs.map((l) => (
                <li key={l.id} className="text-xs">
                  <span className="font-medium">{l.actor ?? 'Sistem'}</span> — {l.action}
                  {l.to_status && <> → <span className="font-mono">{l.to_status}</span></>}
                  {l.note && <>: {l.note}</>}
                  {l.has_attachment && l.attachment_url && (
                    <> · <a className="text-primary underline" href={l.attachment_url} target="_blank" rel="noreferrer">lampiran</a></>
                  )}
                  <span className="text-muted-foreground"> · {new Date(l.created_at).toLocaleString('id-ID')}</span>
                </li>
              ))}
            </ul>
          </details>
        )}
      </CardContent>

      <Modal open={modal === 'offer'} onClose={closeModal} title="Tawarkan Barang Alternatif">
        <div className="space-y-3">
          <Textarea placeholder="Deskripsi barang alternatif yang ditawarkan" value={text} onChange={(e) => setText(e.target.value)} />
          <AttachmentInput onChange={setAttachment} />
          {err && <p className="text-sm text-destructive">{err}</p>}
          <div className="flex justify-end gap-2">
            <Button size="sm" variant="outline" onClick={closeModal}>Batal</Button>
            <Button size="sm" disabled={!text || offer.isPending}
              onClick={() => offer.mutate({ deskripsi_alternatif: text, attachment },
                { onSuccess: closeModal, onError: (e) => setErr(apiErrorMessage(e)) })}>
              Kirim ke Maintenance
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={modal === 'reject'} onClose={closeModal} title="Tolak Barang Alternatif" description="Alasan wajib diisi.">
        <div className="space-y-3">
          <Textarea placeholder="Alasan penolakan" value={text} onChange={(e) => setText(e.target.value)} />
          <AttachmentInput onChange={setAttachment} />
          {err && <p className="text-sm text-destructive">{err}</p>}
          <div className="flex justify-end gap-2">
            <Button size="sm" variant="outline" onClick={closeModal}>Batal</Button>
            <Button size="sm" variant="destructive" disabled={!text || respond.isPending}
              onClick={() => respond.mutate({ decision: 'REJECT', reason: text, attachment },
                { onSuccess: closeModal, onError: (e) => setErr(apiErrorMessage(e)) })}>
              Tolak &amp; Eskalasi ke BOS
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={modal === 'bos'} onClose={closeModal} title="Keputusan BOS">
        <div className="space-y-3">
          <Textarea placeholder="Catatan (opsional)" value={text} onChange={(e) => setText(e.target.value)} />
          <AttachmentInput onChange={setAttachment} />
          {err && <p className="text-sm text-destructive">{err}</p>}
          <div className="flex justify-end gap-2">
            <Button size="sm" variant="destructive" disabled={bosDecide.isPending}
              onClick={() => bosDecide.mutate({ keputusan: 'DITOLAK', catatan: text, attachment },
                { onSuccess: closeModal, onError: (e) => setErr(apiErrorMessage(e)) })}>
              Tolak
            </Button>
            <Button size="sm" disabled={bosDecide.isPending}
              onClick={() => bosDecide.mutate({ keputusan: 'DISETUJUI', catatan: text, attachment },
                { onSuccess: closeModal, onError: (e) => setErr(apiErrorMessage(e)) })}>
              Setujui
            </Button>
          </div>
        </div>
      </Modal>
    </Card>
  )
}

export function NpbgVerificationPanel({ npbgId }: { npbgId: number }) {
  const { hasPermission } = useAuth()
  const { data: verifications, isLoading } = useNpbgVerifications(npbgId)
  const [showOpenForm, setShowOpenForm] = useState(false)

  if (!hasPermission('npbg.verification.view')) return null

  const hasActive = (verifications ?? []).some((v) => isVerificationOpen(v.status))

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <AlertTriangle className="size-4" /> Klarifikasi / Verifikasi Barang
        </h2>
        {!hasActive && hasPermission('npbg.verification.manage') && !showOpenForm && (
          <Button size="sm" variant="outline" onClick={() => setShowOpenForm(true)}>Ajukan Klarifikasi</Button>
        )}
      </div>

      {showOpenForm && (
        <Card><CardContent className="p-4"><OpenForm npbgId={npbgId} onDone={() => setShowOpenForm(false)} /></CardContent></Card>
      )}

      {isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}
      {!isLoading && (verifications ?? []).length === 0 && !showOpenForm && (
        <p className="text-sm text-muted-foreground">
          Belum ada klarifikasi untuk NPBG ini — dipakai kalau barang yang diissue ternyata beda type/spesifikasi.
        </p>
      )}

      <div className="space-y-3">
        {verifications?.map((v) => <VerificationCase key={v.id} npbgId={npbgId} v={v} />)}
      </div>
    </div>
  )
}
