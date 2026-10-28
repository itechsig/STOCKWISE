import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  AlertTriangle, Ban, CalendarClock, Check, CheckCircle2, ClipboardList, CornerDownRight, Loader2, MessageSquare, Pencil, Play, RotateCcw, Send, ShieldAlert, Trash2, Undo2, Upload,
} from 'lucide-react'
import {
  useDeleteIr, useIr, useIrAct, useIrActivities, useIrAddFiles, useIrCancel, useIrComment, useIrComments, useIrDeadline, useIrDeleteComment, useIrDeleteFile,
  useIrEditComment, useIrReopen, useIrReview, useSendIr, type IrComment, type IrDetail, type IrFile,
} from '@/features/importantRequests/api'
import { IrDeadline, IrFileList, IrFilePicker, IrPriorityBadge, IrStatusBadge, fmtDateTime, readyTokens, type PickedFile } from '@/components/importantRequests/IrParts'
import { BackLink } from '@/components/BackLink'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { Textarea } from '@/components/ui/textarea'
import { confirmDialog } from '@/components/ui/confirm'
import { apiErrorMessage } from '@/lib/api'
import { invalidTo, toast } from '@/lib/notify'
import { cn } from '@/lib/utils'

type Tab = 'overview' | 'files' | 'discussion' | 'review' | 'activity'

/** A mutation call with the app's popups: 😊 on success, 😆 / 😢 on an error. */
function run<T>(m: { mutateAsync: (b: T) => Promise<unknown> }, body: T, ok: string, after?: () => void) {
  return m.mutateAsync(body).then(() => { toast.success(ok); after?.() }).catch((e) => toast.fromError(e, apiErrorMessage(e)))
}

/** A reason (and optionally a date) asked before an action. */
function ReasonDialog({ open, title, label, withDate, dateLabel, required = true, confirm, onClose, onSubmit, busy }: {
  open: boolean; title: string; label: string; withDate?: boolean; dateLabel?: string; required?: boolean; confirm: string
  onClose: () => void; onSubmit: (reason: string, date: string) => void; busy?: boolean
}) {
  const [reason, setReason] = useState('')
  const [date, setDate] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const submit = () => {
    if (required && !reason.trim()) return invalidTo(setErr, `${label} wajib diisi.`)
    if (withDate && !date) return invalidTo(setErr, `${dateLabel ?? 'Tanggal'} wajib diisi.`)
    onSubmit(reason.trim(), date ? new Date(date).toISOString() : '')
  }
  return (
    <Modal open={open} onClose={onClose} title={title} className="max-w-md"
      footer={<><Button variant="outline" size="sm" onClick={onClose}>Batal</Button><Button size="sm" disabled={busy} onClick={submit}>{busy && <Loader2 className="size-4 animate-spin" />}{confirm}</Button></>}>
      <div className="space-y-3">
        {withDate && <div className="space-y-1.5"><Label htmlFor="rd-date">{dateLabel}</Label><Input id="rd-date" type="datetime-local" value={date} onChange={(e) => setDate(e.target.value)} /></div>}
        <div className="space-y-1.5"><Label htmlFor="rd-reason">{label}{required && ' *'}</Label><Textarea id="rd-reason" rows={3} value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} autoFocus /></div>
        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}

/** What I can do now on this request: as its recipient, and as its maker. */
function ActionBar({ r, onTab }: { r: IrDetail; onTab: (t: Tab) => void }) {
  const navigate = useNavigate()
  const act = useIrAct(r.id)
  const send = useSendIr(r.id)
  const del = useDeleteIr(r.id)
  const deadline = useIrDeadline(r.id)
  const cancel = useIrCancel(r.id)
  const reopen = useIrReopen(r.id)
  const [dialog, setDialog] = useState<null | 'blocker' | 'extension' | 'deadline' | 'cancel' | 'reopen' | 'progress'>(null)
  const [progress, setProgress] = useState(r.me.assignee?.progress ?? 0)
  const [progressNote, setProgressNote] = useState('')
  const mine = r.me.assignee
  const open = !['SELESAI', 'DIBATALKAN', 'DRAFT'].includes(r.status)
  const close = () => setDialog(null)

  const buttons: React.ReactNode[] = []
  if (mine && open) {
    if (mine.status === 'MENUNGGU_KONFIRMASI') buttons.push(<Button key="c" onClick={() => run(act, { action: 'confirm' }, 'Tugas dikonfirmasi.')}><Check className="size-4" /> Konfirmasi terima tugas</Button>)
    if (['DITERIMA', 'REVISI', 'TERKENDALA'].includes(mine.status)) buttons.push(<Button key="s" variant={mine.status === 'DITERIMA' ? 'default' : 'outline'} onClick={() => run(act, { action: 'start' }, mine.status === 'TERKENDALA' ? 'Pekerjaan dilanjutkan.' : 'Pekerjaan dimulai.')}><Play className="size-4" /> {mine.status === 'TERKENDALA' ? 'Kendala teratasi, lanjutkan' : mine.status === 'REVISI' ? 'Mulai revisi' : 'Mulai kerjakan'}</Button>)
    if (['DITERIMA', 'IN_PROGRESS', 'REVISI'].includes(mine.status)) {
      buttons.push(<Button key="p" variant="outline" onClick={() => setDialog('progress')}>Update progres</Button>)
      buttons.push(<Button key="sub" onClick={() => onTab('review')}><Upload className="size-4" /> Kirim hasil</Button>)
      buttons.push(<Button key="b" variant="outline" className="border-red-300 text-red-700 hover:bg-red-50" onClick={() => setDialog('blocker')}><ShieldAlert className="size-4" /> Laporkan kendala</Button>)
    }
    if (['DITERIMA', 'IN_PROGRESS', 'REVISI', 'TERKENDALA'].includes(mine.status)) buttons.push(<Button key="e" variant="ghost" onClick={() => setDialog('extension')}><CalendarClock className="size-4" /> Ajukan perpanjangan</Button>)
  }
  if (r.me.is_creator) {
    if (r.me.can_send) buttons.push(<Button key="send" onClick={async () => { if (await confirmDialog({ title: 'Kirim request?', message: `${r.number} dikirim ke ${r.assignees.length} penerima.`, confirmLabel: 'Kirim' })) run(send, undefined, 'Request dikirim.') }}><Send className="size-4" /> Kirim</Button>)
    if (r.me.can_edit) buttons.push(<Button key="edit" variant="outline" onClick={() => navigate(`/important-requests/${r.id}/ubah`)}><Pencil className="size-4" /> Ubah</Button>)
    if (r.me.can_cancel) buttons.push(<Button key="dl" variant="outline" onClick={() => setDialog('deadline')}><CalendarClock className="size-4" /> Ubah deadline</Button>)
    if (r.me.can_cancel) buttons.push(<Button key="cancel" variant="ghost" className="text-red-700 hover:text-red-800" onClick={() => setDialog('cancel')}><Ban className="size-4" /> Batalkan</Button>)
    if (r.me.can_delete) buttons.push(<Button key="del" variant="ghost" className="text-red-700" onClick={async () => { if (await confirmDialog({ message: 'Hapus draft ini?', danger: true })) run(del, undefined, 'Draft dihapus.', () => navigate('/important-requests/saya')) }}><Trash2 className="size-4" /> Hapus draft</Button>)
  }
  if (r.me.can_reopen) buttons.push(<Button key="reopen" variant="outline" onClick={() => setDialog('reopen')}><RotateCcw className="size-4" /> Buka kembali</Button>)
  if (buttons.length === 0) return null

  return (
    <Card className="flex flex-wrap items-center gap-2 border-primary/30 bg-primary/5 p-3">
      {buttons}
      <ReasonDialog open={dialog === 'blocker'} title="Laporkan kendala" label="Kendalanya" withDate={false} confirm="Laporkan" busy={act.isPending} onClose={close}
        onSubmit={(reason) => run(act, { action: 'blocker', reason }, 'Kendala dilaporkan.', close)} />
      <ReasonDialog open={dialog === 'extension'} title="Ajukan perpanjangan deadline" label="Alasan" withDate dateLabel="Deadline yang diusulkan" confirm="Ajukan" busy={act.isPending} onClose={close}
        onSubmit={(reason, date) => run(act, { action: 'extension', reason, proposed_deadline: date }, 'Usulan dikirim ke pembuat request.', close)} />
      <ReasonDialog open={dialog === 'deadline'} title="Ubah deadline" label="Alasan perubahan" withDate dateLabel="Deadline baru" confirm="Simpan" busy={deadline.isPending} onClose={close}
        onSubmit={(reason, date) => run(deadline, { deadline: date, reason }, 'Deadline diubah.', close)} />
      <ReasonDialog open={dialog === 'cancel'} title={`Batalkan ${r.number}?`} label="Alasan pembatalan" confirm="Batalkan request" busy={cancel.isPending} onClose={close}
        onSubmit={(reason) => run(cancel, { reason }, 'Request dibatalkan.', close)} />
      <ReasonDialog open={dialog === 'reopen'} title={`Buka kembali ${r.number}?`} label="Alasan" confirm="Buka kembali" busy={reopen.isPending} onClose={close}
        onSubmit={(reason) => run(reopen, { reason }, 'Request dibuka kembali.', close)} />
      <Modal open={dialog === 'progress'} onClose={close} title="Update progres" className="max-w-md"
        footer={<><Button variant="outline" size="sm" onClick={close}>Batal</Button><Button size="sm" disabled={act.isPending} onClick={() => run(act, { action: 'progress', progress, note: progressNote.trim() || null }, 'Progres diperbarui.', () => { setProgressNote(''); close() })}>Simpan</Button></>}>
        <div className="space-y-3">
          <div className="flex items-center gap-3"><input type="range" min={0} max={100} step={5} value={progress} onChange={(e) => setProgress(Number(e.target.value))} className="flex-1 accent-primary" aria-label="Progres" /><span className="w-12 text-right font-semibold tabular-nums">{progress}%</span></div>
          <Textarea rows={3} value={progressNote} maxLength={5000} onChange={(e) => setProgressNote(e.target.value)} placeholder="Catatan progres (opsional), tampil di diskusi" />
        </div>
      </Modal>
    </Card>
  )
}

function Overview({ r }: { r: IrDetail }) {
  return (
    <div className="space-y-4">
      <Card className="space-y-3 p-4">
        <div className="whitespace-pre-wrap text-sm leading-relaxed">{r.description}</div>
        {r.note && <div className="rounded-md bg-muted/50 px-3 py-2 text-sm"><span className="text-muted-foreground">Catatan: </span>{r.note}</div>}
        {r.cancel_reason && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">Dibatalkan: {r.cancel_reason}</div>}
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3 text-sm sm:grid-cols-4">
          {([['Dari', r.creator], ['Kategori', r.category ?? '-'], ['Persetujuan', r.requires_approval ? 'Wajib disetujui' : 'Tanpa persetujuan'],
            ['Jenis', r.assignees.length > 1 ? (r.assignment_mode === 'COLLECTIVE' ? 'Tugas bersama' : 'Tugas individu') : '-'],
            ['Dikirim', fmtDateTime(r.sent_at)], ['Selesai', fmtDateTime(r.completed_at)]] as [string, React.ReactNode][]).map(([k, v]) => (
            <div key={k}><dt className="text-xs text-muted-foreground">{k}</dt><dd className="font-medium">{v}</dd></div>
          ))}
        </dl>
      </Card>
      <Card className="overflow-hidden">
        <div className="border-b px-4 py-3 text-sm font-semibold">Penerima ({r.assignees.length})</div>
        <ul className="divide-y">
          {r.assignees.map((a) => (
            <li key={a.id} className="grid gap-2 px-4 py-3 text-sm sm:grid-cols-[minmax(0,1fr)_10rem_12rem] sm:items-center">
              <div className="min-w-0"><div className="font-medium">{a.name}</div><div className="text-xs text-muted-foreground">{[a.position, a.confirmed_at && `dikonfirmasi ${fmtDateTime(a.confirmed_at)}`].filter(Boolean).join(' · ')}</div>
                {a.status === 'TERKENDALA' && a.blocker_note && <div className="mt-1 text-xs text-red-700">Kendala: {a.blocker_note}</div>}
              </div>
              <IrStatusBadge status={a.status} label={a.status_label} />
              <div className="flex items-center gap-2"><div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"><div className={cn('h-full rounded-full', a.status === 'SELESAI' ? 'bg-emerald-500' : 'bg-primary')} style={{ width: `${a.progress}%` }} /></div><span className="w-10 text-right text-xs tabular-nums">{a.progress}%</span></div>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}

function FilesTab({ r }: { r: IrDetail }) {
  const add = useIrAddFiles(r.id)
  const del = useIrDeleteFile(r.id)
  const [files, setFiles] = useState<PickedFile[]>([])
  const remove = async (f: IrFile) => { if (await confirmDialog({ message: `Hapus ${f.name}?`, danger: true })) run(del, f.id, 'File dihapus.') }
  const subFiles = r.submissions.flatMap((s) => s.files)
  return (
    <div className="space-y-5">
      <section className="space-y-2">
        <h3 className="text-sm font-semibold">File awal (dari pembuat request)</h3>
        <IrFileList requestId={r.id} files={r.attachments} empty="Tidak ada file awal." onDelete={r.me.can_edit ? remove : undefined} />
        {r.me.can_edit && (
          <div className="space-y-2">
            <IrFilePicker files={files} onChange={setFiles} maxMb={r.me.max_mb} label="Tambah file awal" />
            {files.length > 0 && <Button size="sm" disabled={add.isPending} onClick={() => { const t = readyTokens(files); if (!t) return toast.invalid('Tunggu upload selesai.'); run(add, { attachments: t }, 'File ditambahkan.', () => setFiles([])) }}>Simpan file</Button>}
          </div>
        )}
      </section>
      <section className="space-y-2">
        <h3 className="text-sm font-semibold">File hasil & revisi</h3>
        <IrFileList requestId={r.id} files={subFiles} empty="Belum ada file hasil." />
      </section>
      <p className="text-xs text-muted-foreground">File dari diskusi ada di tab Diskusi, di komentarnya masing-masing.</p>
    </div>
  )
}

function CommentItem({ r, c, onReply }: { r: IrDetail; c: IrComment; onReply?: () => void }) {
  const edit = useIrEditComment(r.id)
  const del = useIrDeleteComment(r.id)
  const [editing, setEditing] = useState(false)
  const [body, setBody] = useState(c.body ?? '')
  return (
    <div className={cn('rounded-lg px-3 py-2', c.is_new && 'bg-amber-50 ring-1 ring-amber-200', c.kind === 'BLOCKER' && 'bg-red-50', c.kind === 'PROGRESS' && 'bg-blue-50/60')}>
      <div className="flex flex-wrap items-baseline gap-x-2 text-xs">
        <span className="font-semibold text-foreground">{c.user}</span>
        <span className="text-muted-foreground">{fmtDateTime(c.created_at)}{c.edited && ' · diedit'}</span>
        {c.is_new && <span className="rounded bg-amber-500 px-1.5 text-[10px] font-semibold text-white">baru</span>}
        {c.kind === 'PROGRESS' && <span className="text-blue-700">progres</span>}
        {c.kind === 'BLOCKER' && <span className="text-red-700">kendala</span>}
      </div>
      {c.deleted ? <p className="mt-1 text-sm italic text-muted-foreground">Pesan dihapus.</p> : editing ? (
        <div className="mt-1 space-y-2">
          <Textarea rows={2} value={body} onChange={(e) => setBody(e.target.value)} />
          <div className="flex gap-2"><Button size="sm" disabled={edit.isPending} onClick={() => run(edit, { comment: c.id, body }, 'Komentar diubah.', () => setEditing(false))}>Simpan</Button><Button size="sm" variant="ghost" onClick={() => setEditing(false)}>Batal</Button></div>
        </div>
      ) : <p className="mt-1 whitespace-pre-wrap text-sm">{c.body}</p>}
      {c.files.length > 0 && <div className="mt-2"><IrFileList requestId={r.id} files={c.files} /></div>}
      {!c.deleted && !editing && (
        <div className="mt-1 flex gap-3 text-xs">
          {onReply && r.me.can_comment && <button type="button" className="font-medium text-primary hover:underline" onClick={onReply}>Balas</button>}
          {c.can_edit && <button type="button" className="text-muted-foreground hover:underline" onClick={() => setEditing(true)}>Ubah</button>}
          {c.can_delete && <button type="button" className="text-muted-foreground hover:text-destructive hover:underline" onClick={async () => { if (await confirmDialog({ message: 'Hapus komentar ini?', danger: true })) run(del, c.id, 'Komentar dihapus.') }}>Hapus</button>}
        </div>
      )}
    </div>
  )
}

function Composer({ r, parentId, onDone, placeholder }: { r: IrDetail; parentId?: number; onDone?: () => void; placeholder: string }) {
  const add = useIrComment(r.id)
  const [body, setBody] = useState('')
  const [files, setFiles] = useState<PickedFile[]>([])
  const submit = () => {
    const tokens = readyTokens(files)
    if (tokens === null) return toast.invalid('Tunggu upload file selesai.')
    if (!body.trim() && tokens.length === 0) return toast.invalid('Tulis komentar atau lampirkan file.')
    run(add, { body: body.trim(), parent_id: parentId ?? null, attachments: tokens }, parentId ? 'Balasan terkirim.' : 'Komentar terkirim.', () => { setBody(''); setFiles([]); onDone?.() })
  }
  return (
    <div className="space-y-2">
      <Textarea rows={parentId ? 2 : 3} value={body} maxLength={5000} onChange={(e) => setBody(e.target.value)} placeholder={placeholder} />
      <div className="flex flex-wrap items-start justify-between gap-2">
        <IrFilePicker files={files} onChange={setFiles} maxMb={r.me.max_mb} label="File" compact />
        <Button size="sm" disabled={add.isPending} onClick={submit}><Send className="size-4" /> {parentId ? 'Balas' : 'Kirim'}</Button>
      </div>
    </div>
  )
}

function DiscussionTab({ r }: { r: IrDetail }) {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useIrComments(r.id, page)
  const [replyTo, setReplyTo] = useState<number | null>(null)
  return (
    <div className="space-y-4">
      {r.me.can_comment ? <Card className="p-3"><Composer r={r} placeholder="Tulis komentar, update, atau pertanyaan. Sebut orang dengan @username." /></Card>
        : <p className="text-sm text-muted-foreground">Diskusi dibuka setelah request dikirim.</p>}
      {isLoading && <p className="text-sm text-muted-foreground">Memuat diskusi…</p>}
      {data && data.data.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">Belum ada diskusi.</p>}
      <div className="space-y-3">
        {data?.data.map((c) => (
          <Card key={c.id} className="space-y-2 p-3">
            <CommentItem r={r} c={c} onReply={() => setReplyTo(replyTo === c.id ? null : c.id)} />
            {(c.replies?.length ?? 0) > 0 && (
              <div className="ml-4 space-y-2 border-l-2 pl-3">
                {c.replies!.map((x) => <div key={x.id} className="flex gap-1.5"><CornerDownRight className="mt-2 size-3.5 shrink-0 text-muted-foreground" /><div className="min-w-0 flex-1"><CommentItem r={r} c={x} /></div></div>)}
              </div>
            )}
            {replyTo === c.id && <div className="ml-4 border-l-2 pl-3"><Composer r={r} parentId={c.id} onDone={() => setReplyTo(null)} placeholder={`Balas ${c.user}…`} /></div>}
          </Card>
        ))}
      </div>
      {data && data.meta.last_page > 1 && (
        <div className="flex justify-center gap-2 text-sm">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Lebih baru</Button>
          <span className="self-center tabular-nums text-muted-foreground">{data.meta.page} / {data.meta.last_page}</span>
          <Button size="sm" variant="outline" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Lebih lama</Button>
        </div>
      )}
    </div>
  )
}

const SUB_STATUS: Record<string, { label: string; cls: string }> = {
  MENUNGGU_REVIEW: { label: 'Menunggu review', cls: 'bg-amber-50 text-amber-800' },
  DISETUJUI: { label: 'Disetujui', cls: 'bg-emerald-50 text-emerald-700' },
  REVISI: { label: 'Diminta revisi', cls: 'bg-orange-50 text-orange-800' },
  OTOMATIS: { label: 'Selesai (tanpa persetujuan)', cls: 'bg-emerald-50 text-emerald-700' },
}

function ReviewTab({ r }: { r: IrDetail }) {
  const act = useIrAct(r.id)
  const review = useIrReview(r.id)
  const [note, setNote] = useState('')
  const [files, setFiles] = useState<PickedFile[]>([])
  const [revising, setRevising] = useState<number | null>(null)
  const mine = r.me.assignee
  const canSubmit = !!mine && ['DITERIMA', 'IN_PROGRESS', 'REVISI'].includes(mine.status) && !['SELESAI', 'DIBATALKAN'].includes(r.status)
  const submit = async () => {
    const tokens = readyTokens(files)
    if (tokens === null) return toast.invalid('Tunggu upload file selesai.')
    if (!note.trim() && tokens.length === 0) return toast.invalid('Lampirkan file hasil atau tulis catatan hasil pekerjaan.')
    if (!(await confirmDialog({ title: 'Kirim hasil?', message: r.requires_approval ? 'Hasil dikirim ke pembuat request untuk ditinjau.' : 'Request ini tanpa persetujuan: bagian Anda langsung selesai.', confirmLabel: 'Kirim hasil' }))) return
    run(act, { action: 'submit', note: note.trim() || null, attachments: tokens }, 'Hasil dikirim.', () => { setNote(''); setFiles([]) })
  }
  return (
    <div className="space-y-4">
      {canSubmit && (
        <Card className="space-y-3 border-primary/30 p-4">
          <div className="text-sm font-semibold">{mine!.status === 'REVISI' ? 'Kirim hasil revisi' : 'Kirim hasil pekerjaan'}</div>
          <Textarea rows={3} value={note} maxLength={5000} onChange={(e) => setNote(e.target.value)} placeholder="Catatan hasil: apa yang sudah dikerjakan / diperbaiki" />
          <IrFilePicker files={files} onChange={setFiles} maxMb={r.me.max_mb} label="Lampirkan file hasil" />
          <div className="flex justify-end"><Button disabled={act.isPending} onClick={submit}><Upload className="size-4" /> Kirim hasil</Button></div>
        </Card>
      )}
      {r.submissions.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">Belum ada hasil yang dikirim.</p>}
      {r.submissions.map((s) => (
        <Card key={s.id} className="space-y-3 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="text-sm"><span className="font-semibold">{s.user}</span> · {s.round > 1 ? `Revisi ke-${s.round - 1}` : 'Hasil pertama'} · <span className="text-muted-foreground">{fmtDateTime(s.created_at)}</span></div>
            <span className={cn('rounded-md px-2 py-0.5 text-xs font-medium', SUB_STATUS[s.status]?.cls)}>{SUB_STATUS[s.status]?.label ?? s.status}</span>
          </div>
          {s.note && <p className="whitespace-pre-wrap text-sm">{s.note}</p>}
          <IrFileList requestId={r.id} files={s.files} />
          {s.reviewed_by && <div className="rounded-md bg-muted/50 px-3 py-2 text-sm"><span className="font-medium">{s.reviewed_by}</span> · {fmtDateTime(s.reviewed_at)}{s.review_note ? `: ${s.review_note}` : ''}</div>}
          {s.can_review && (
            <div className="flex flex-wrap justify-end gap-2 border-t pt-3">
              <Button variant="outline" className="border-orange-300 text-orange-700 hover:bg-orange-50" onClick={() => setRevising(s.id)}><Undo2 className="size-4" /> Minta revisi</Button>
              <Button className="bg-emerald-600 text-white hover:bg-emerald-700" disabled={review.isPending}
                onClick={async () => { if (await confirmDialog({ title: 'Setujui hasil?', message: `Hasil ${s.user} disetujui; bagiannya selesai.`, confirmLabel: 'Setujui' })) run(review, { submission: s.id, approve: true }, 'Hasil disetujui.') }}>
                <CheckCircle2 className="size-4" /> Setujui
              </Button>
            </div>
          )}
        </Card>
      ))}
      <ReasonDialog open={revising !== null} title="Minta revisi" label="Apa yang perlu direvisi" confirm="Minta revisi" busy={review.isPending} onClose={() => setRevising(null)}
        onSubmit={(reason) => run(review, { submission: revising!, approve: false, note: reason }, 'Revisi diminta.', () => setRevising(null))} />
    </div>
  )
}

const ACTION_LABEL: Record<string, string> = {
  CREATED: 'Membuat request', SENT: 'Mengirim request', EDITED: 'Mengubah request', ASSIGNEES_CHANGED: 'Mengubah penerima', CONFIRMED: 'Mengonfirmasi penerimaan',
  STARTED: 'Mulai mengerjakan', RESUMED: 'Melanjutkan (kendala teratasi)', PROGRESS: 'Update progres', BLOCKED: 'Melaporkan kendala', EXTENSION_REQUESTED: 'Mengajukan perpanjangan deadline',
  SUBMITTED: 'Mengirim hasil', APPROVED: 'Menyetujui hasil', REVISION_REQUESTED: 'Meminta revisi', DEADLINE_CHANGED: 'Mengubah deadline', CANCELLED: 'Membatalkan request',
  REOPENED: 'Membuka kembali', COMPLETED: 'Request selesai', OVERDUE: 'Melewati deadline', COMMENTED: 'Berkomentar', REPLIED: 'Membalas komentar',
  COMMENT_EDITED: 'Mengubah komentar', COMMENT_DELETED: 'Menghapus komentar', FILE_UPLOADED: 'Mengunggah file', FILE_DELETED: 'Menghapus file',
}

function metaText(meta: Record<string, unknown> | null): string {
  if (!meta) return ''
  const parts: string[] = []
  for (const [k, v] of Object.entries(meta)) {
    if (v == null || v === '' || (Array.isArray(v) && v.length === 0) || ['komentar', 'sebelumnya', 'isi'].includes(k)) continue
    if (k === 'perubahan' && typeof v === 'object') { parts.push(`mengubah ${Object.keys(v as object).join(', ')}`); continue }
    const val = Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'ya' : 'tidak') : /^\d{4}-\d{2}-\d{2}T/.test(String(v)) ? fmtDateTime(String(v)) : String(v)
    parts.push(`${k.replace(/_/g, ' ')}: ${val}`)
  }
  return parts.join(' · ')
}

function ActivityTab({ r }: { r: IrDetail }) {
  const { data, isLoading } = useIrActivities(r.id, true)
  if (isLoading) return <p className="text-sm text-muted-foreground">Memuat riwayat…</p>
  return (
    <ol className="relative space-y-3 border-l pl-5">
      {(data ?? []).map((a) => (
        <li key={a.id} className="text-sm">
          <span className="absolute -left-1.5 mt-1.5 size-3 rounded-full border-2 border-card bg-primary" />
          <div><span className="font-medium">{a.user}</span> · {ACTION_LABEL[a.action] ?? a.action}</div>
          <div className="text-xs text-muted-foreground">{fmtDateTime(a.created_at)}{metaText(a.meta) && ` · ${metaText(a.meta)}`}</div>
        </li>
      ))}
    </ol>
  )
}

/** Important Request detail: overview, files, discussion, results & review, activity. */
export function IrDetailPage() {
  const { id } = useParams()
  const { data: r, isLoading, isError } = useIr(Number(id))
  const [tab, setTab] = useState<Tab>('overview')
  if (isError) return <div className="space-y-4"><BackLink to="/important-requests" label="Important Request" /><p className="text-sm text-destructive">Request tidak ditemukan atau bukan untuk Anda.</p></div>
  if (isLoading || !r) return <p className="py-16 text-center text-sm text-muted-foreground">Memuat…</p>
  const closed = ['SELESAI', 'DIBATALKAN', 'DRAFT'].includes(r.status)
  const pending = r.submissions.filter((s) => s.can_review).length
  const tabs: [Tab, string, number?][] = [['overview', 'Ringkasan'], ['files', 'Lampiran', r.attachments.length + r.submissions.reduce((n, s) => n + s.files.length, 0)],
    ['discussion', 'Diskusi', r.unread_comments], ['review', 'Hasil & Review', pending || r.submissions.length], ['activity', 'Riwayat']]

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <BackLink to={r.me.is_creator ? '/important-requests/saya' : '/important-requests/tugas'} label={r.me.is_creator ? 'Permintaan Saya' : 'Tugas Saya'} />
      <Card className="space-y-3 p-4 sm:p-5">
        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"><ClipboardList className="size-4" /><span className="font-mono">{r.number}</span>
          {r.overdue && <span className="inline-flex items-center gap-1 rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700"><AlertTriangle className="size-3" /> Terlambat</span>}
        </div>
        <h1 className="text-xl font-semibold leading-snug">{r.title}</h1>
        <div className="flex flex-wrap items-center gap-2 text-sm"><IrStatusBadge status={r.status} label={r.status_label} /><IrPriorityBadge priority={r.priority} />
          <span className="text-muted-foreground">Deadline</span><IrDeadline deadline={r.deadline} overdue={r.overdue} closed={closed} />
          {r.me.assignee && r.me.assignee.status !== r.status && <span className="text-xs text-muted-foreground">· bagian saya: {r.me.assignee.status.replace(/_/g, ' ').toLowerCase()}</span>}
        </div>
      </Card>

      <ActionBar r={r} onTab={setTab} />

      <div className="flex overflow-x-auto border-b" role="tablist">
        {tabs.map(([k, label, n]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={cn('flex shrink-0 items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm', tab === k ? 'border-primary font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}>
            {k === 'discussion' && <MessageSquare className="size-4" />}{label}
            {!!n && <span className={cn('rounded-full px-1.5 text-xs tabular-nums', k === 'discussion' && r.unread_comments ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>{n}</span>}
          </button>
        ))}
      </div>

      {tab === 'overview' && <Overview r={r} />}
      {tab === 'files' && <FilesTab r={r} />}
      {tab === 'discussion' && <DiscussionTab r={r} />}
      {tab === 'review' && <ReviewTab r={r} />}
      {tab === 'activity' && <ActivityTab r={r} />}
      {r.me.is_creator && r.status === 'DRAFT' && <p className="text-xs text-muted-foreground">Draft: belum terlihat oleh penerima. <Link to={`/important-requests/${r.id}/ubah`} className="text-primary hover:underline">Lengkapi</Link> lalu kirim.</p>}
    </div>
  )
}
