import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  AlertTriangle, Ban, CalendarClock, CheckCircle2, ClipboardList, CornerDownRight, Loader2, MessageSquare, Pencil, RotateCcw, Send, Trash2, Undo2, Upload, Users,
} from 'lucide-react'
import {
  useDeleteIr, useIr, useIrAct, useIrAddFiles, useIrCancel, useIrComment, useIrComments, useIrDeadline, useIrDeleteComment, useIrDeleteFile,
  useIrEditComment, useIrReopen, useIrReview, useSendIr, type IrComment, type IrDetail, type IrFile,
} from '@/features/importantRequests/api'
import { IrFileList, IrFilePicker, IrPriorityBadge, IrStatusBadge, fmtDateTime, readyTokens, type PickedFile } from '@/components/importantRequests/IrParts'
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

type Tab = 'instructions' | 'results'

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

/** A person's round initials (like Classroom's avatars). */
function Avatar({ name, className }: { name: string | null; className?: string }) {
  const initials = (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
  return <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary', className)}>{initials || '?'}</span>
}

/** A recipient's part, worded and colored like Classroom ("Ditugaskan", "Diserahkan", "Terlambat"…). */
function partTone(status: string, late?: boolean): string {
  if (late) return 'text-red-600'
  return status === 'SELESAI' ? 'text-emerald-700' : status === 'REVISI' ? 'text-orange-700' : status.startsWith('DISERAHKAN') ? 'text-emerald-700' : 'text-muted-foreground'
}

/** The creator's other actions (edit, deadline, cancel, delete draft, reopen) — compact, with their dialogs. */
function CreatorActions({ r }: { r: IrDetail }) {
  const navigate = useNavigate()
  const del = useDeleteIr(r.id)
  const deadline = useIrDeadline(r.id)
  const cancel = useIrCancel(r.id)
  const reopen = useIrReopen(r.id)
  const [dialog, setDialog] = useState<null | 'deadline' | 'cancel' | 'reopen'>(null)
  const close = () => setDialog(null)
  const show = (r.me.is_creator && (r.me.can_edit || r.me.can_cancel || r.me.can_delete)) || r.me.can_reopen
  if (!show) return null
  return (
    <>
      <div className="flex flex-wrap gap-1 border-t bg-muted/20 px-2 py-2">
        {r.me.is_creator && r.me.can_edit && <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={() => navigate(`/important-requests/${r.id}/ubah`)}><Pencil className="size-3.5" /> Ubah</Button>}
        {r.me.is_creator && r.me.can_cancel && <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={() => setDialog('deadline')}><CalendarClock className="size-3.5" /> Ubah tenggat</Button>}
        {r.me.can_reopen && <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={() => setDialog('reopen')}><RotateCcw className="size-3.5" /> Buka kembali</Button>}
        {r.me.is_creator && r.me.can_cancel && <Button size="sm" variant="ghost" className="h-8 text-xs text-red-700 hover:text-red-800" onClick={() => setDialog('cancel')}><Ban className="size-3.5" /> Batalkan</Button>}
        {r.me.is_creator && r.me.can_delete && (
          <Button size="sm" variant="ghost" className="h-8 text-xs text-red-700" onClick={async () => { if (await confirmDialog({ message: 'Hapus draft ini?', danger: true })) run(del, undefined, 'Draft dihapus.', () => navigate('/important-requests/saya')) }}>
            <Trash2 className="size-3.5" /> Hapus draft
          </Button>
        )}
      </div>
      <ReasonDialog open={dialog === 'deadline'} title="Ubah tenggat" label="Alasan perubahan" withDate dateLabel="Tenggat baru" confirm="Simpan" busy={deadline.isPending} onClose={close}
        onSubmit={(reason, date) => run(deadline, { deadline: date, reason }, 'Tenggat diubah.', close)} />
      <ReasonDialog open={dialog === 'cancel'} title={`Batalkan ${r.number}?`} label="Alasan pembatalan" confirm="Batalkan request" busy={cancel.isPending} onClose={close}
        onSubmit={(reason) => run(cancel, { reason }, 'Request dibatalkan.', close)} />
      <ReasonDialog open={dialog === 'reopen'} title={`Buka kembali ${r.number}?`} label="Alasan" confirm="Buka kembali" busy={reopen.isPending} onClose={close}
        onSubmit={(reason) => run(reopen, { reason }, 'Request dibuka kembali.', close)} />
    </>
  )
}

/** Classroom's "Tugas Anda": my part — add the work and hand it in, or withdraw it while not reviewed. */
function MyWork({ r }: { r: IrDetail }) {
  const act = useIrAct(r.id)
  const [note, setNote] = useState('')
  const [files, setFiles] = useState<PickedFile[]>([])
  const mine = r.me.assignee!
  const open = !['SELESAI', 'DIBATALKAN', 'DRAFT'].includes(r.status)
  const toDo = open && ['DITUGASKAN', 'REVISI'].includes(mine.status)
  const handedIn = open && ['DISERAHKAN', 'DISERAHKAN_TERLAMBAT'].includes(mine.status)
  const lateNow = !!r.deadline && new Date(r.deadline).getTime() < Date.now()
  const myWork = r.submissions.filter((s) => s.assignee_id === mine.id)
  const last = myWork[0]
  const returned = mine.status === 'REVISI' ? myWork.find((s) => s.status === 'REVISI') : undefined
  const state = mine.status === 'SELESAI' ? 'Selesai' : handedIn ? (mine.status === 'DISERAHKAN_TERLAMBAT' ? 'Diserahkan terlambat' : 'Diserahkan')
    : mine.status === 'REVISI' ? 'Perlu revisi' : mine.late ? 'Terlambat' : 'Ditugaskan'

  const submit = async () => {
    const tokens = readyTokens(files)
    if (tokens === null) return toast.invalid('Tunggu upload file selesai.')
    if (!note.trim() && tokens.length === 0) return toast.invalid('Tambahkan file hasil atau tulis catatan pekerjaan.')
    if (!(await confirmDialog({ title: 'Serahkan tugas?', message: `Hasil dikirim ke ${r.creator ?? 'pembuat request'} untuk ditinjau.${lateNow ? ' Tenggat sudah lewat: tercatat diserahkan terlambat.' : ''} Selama belum ditinjau, Anda masih bisa menariknya.`, confirmLabel: 'Serahkan' }))) return
    run(act, { action: 'submit', note: note.trim() || null, attachments: tokens }, lateNow ? 'Tugas diserahkan (terlambat).' : 'Tugas diserahkan.', () => { setNote(''); setFiles([]) })
  }

  return (
    <Card className="overflow-hidden rounded-2xl shadow-md">
      <div className="space-y-3 p-4">
        <div className="flex items-baseline justify-between gap-2">
          <h2 className="text-lg font-normal">Tugas Anda</h2>
          <span className={cn('text-sm font-medium', partTone(mine.status, mine.late && toDo))}>{state}</span>
        </div>
        {returned?.review_note && (
          <div className="rounded-lg bg-orange-50 px-3 py-2 text-sm text-orange-900"><span className="block text-xs font-medium">Dikembalikan {returned.reviewed_by ? `oleh ${returned.reviewed_by}` : ''}</span>{returned.review_note}</div>
        )}

        {toDo && (
          <div className="space-y-2.5">
            <IrFilePicker files={files} onChange={setFiles} maxMb={r.me.max_mb} label="+ Tambahkan file" />
            <Textarea rows={2} value={note} maxLength={5000} onChange={(e) => setNote(e.target.value)} placeholder="Catatan untuk pemberi tugas (opsional)" />
            <Button className="w-full" disabled={act.isPending} onClick={submit}><Upload className="size-4" /> {mine.status === 'REVISI' ? 'Serahkan revisi' : 'Serahkan'}</Button>
            {lateNow && <p className="text-center text-xs text-red-600">Tenggat sudah lewat: akan tercatat terlambat.</p>}
          </div>
        )}

        {!toDo && last && (
          <div className="space-y-2">
            {last.note && <p className="whitespace-pre-wrap rounded-lg bg-muted/50 px-3 py-2 text-sm">{last.note}</p>}
            <IrFileList requestId={r.id} files={last.files} />
            <p className="text-xs text-muted-foreground">Diserahkan {fmtDateTime(last.created_at)}{last.reviewed_by ? ` · ditinjau ${last.reviewed_by}` : ''}</p>
          </div>
        )}

        {handedIn && (
          <Button variant="outline" className="w-full" disabled={act.isPending}
            onClick={async () => { if (await confirmDialog({ title: 'Tarik penyerahan?', message: 'Hasil ditarik kembali supaya bisa diperbaiki, lalu diserahkan lagi.', confirmLabel: 'Tarik' })) run(act, { action: 'unsubmit' }, 'Penyerahan ditarik.') }}>
            <Undo2 className="size-4" /> Tarik penyerahan
          </Button>
        )}
        {mine.status === 'SELESAI' && <p className="flex items-center gap-1.5 text-sm text-emerald-700"><CheckCircle2 className="size-4" /> Hasil Anda disetujui.</p>}
      </div>
    </Card>
  )
}

/** The creator's card: how many handed in / assigned (like Classroom's counts), the send button for a draft. */
function RecipientsCard({ r, onResults }: { r: IrDetail; onResults: () => void }) {
  const send = useSendIr(r.id)
  const handed = r.assignees.filter((a) => a.status.startsWith('DISERAHKAN')).length
  const done = r.assignees.filter((a) => a.status === 'SELESAI').length
  const todo = r.assignees.length - handed - done
  const toReview = r.submissions.filter((s) => s.can_review).length
  return (
    <Card className="overflow-hidden rounded-2xl shadow-md">
      <div className="space-y-4 p-4">
        <div className="flex items-baseline justify-between gap-2">
          <h2 className="text-lg font-normal">Penerima</h2>
          <span className="text-xs text-muted-foreground">{r.assignees.length} orang</span>
        </div>
        {r.status === 'DRAFT' ? (
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">Masih draft: belum terlihat oleh penerima.</p>
            {r.me.can_send && <Button className="w-full" onClick={async () => { if (await confirmDialog({ title: 'Kirim request?', message: `${r.number} dikirim ke ${r.assignees.length} penerima.`, confirmLabel: 'Kirim' })) run(send, undefined, 'Request dikirim.') }}><Send className="size-4" /> Tugaskan</Button>}
          </div>
        ) : (
          <>
            <div className="grid grid-cols-3 divide-x text-center">
              {([[handed, 'Diserahkan'], [todo, 'Ditugaskan'], [done, 'Selesai']] as const).map(([n, label]) => (
                <div key={label}><div className="text-3xl font-light tabular-nums">{n}</div><div className="text-xs text-muted-foreground">{label}</div></div>
              ))}
            </div>
            <Button variant={toReview ? 'default' : 'outline'} className="w-full" onClick={onResults}>
              {toReview ? `Tinjau ${toReview} hasil` : 'Lihat hasil penerima'}
            </Button>
          </>
        )}
        <ul className="space-y-2">
          {r.assignees.map((a) => (
            <li key={a.id} className="flex items-center gap-2.5 text-sm">
              <Avatar name={a.name} />
              <span className="min-w-0 flex-1 truncate">{a.name}</span>
              <span className={cn('shrink-0 text-xs', partTone(a.status, a.late))}>{a.late ? 'Terlambat' : a.status_label}</span>
            </li>
          ))}
        </ul>
      </div>
      <CreatorActions r={r} />
    </Card>
  )
}

/** Classroom's class comments: everyone on the request talks here. */
function Comments({ r }: { r: IrDetail }) {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useIrComments(r.id, page)
  const [replyTo, setReplyTo] = useState<number | null>(null)
  return (
    <section className="space-y-3">
      <h2 className="flex items-center gap-2 text-[15px] font-medium"><Users className="size-4 text-muted-foreground" /> Komentar{data?.meta.total ? ` (${data.meta.total})` : ''}</h2>
      {isLoading && <p className="text-sm text-muted-foreground">Memuat komentar…</p>}
      <div className="space-y-3">
        {data?.data.map((c) => (
          <div key={c.id} className="flex gap-3">
            <Avatar name={c.user} />
            <div className="min-w-0 flex-1 space-y-2">
              <CommentItem r={r} c={c} onReply={() => setReplyTo(replyTo === c.id ? null : c.id)} />
              {(c.replies?.length ?? 0) > 0 && (
                <div className="space-y-2 border-l-2 pl-3">
                  {c.replies!.map((x) => <div key={x.id} className="flex gap-1.5"><CornerDownRight className="mt-2 size-3.5 shrink-0 text-muted-foreground" /><div className="min-w-0 flex-1"><CommentItem r={r} c={x} /></div></div>)}
                </div>
              )}
              {replyTo === c.id && <div className="border-l-2 pl-3"><Composer r={r} parentId={c.id} onDone={() => setReplyTo(null)} placeholder={`Balas ${c.user}…`} /></div>}
            </div>
          </div>
        ))}
      </div>
      {data && data.meta.last_page > 1 && (
        <div className="flex justify-center gap-2 text-sm">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Lebih baru</Button>
          <span className="self-center tabular-nums text-muted-foreground">{data.meta.page} / {data.meta.last_page}</span>
          <Button size="sm" variant="outline" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Lebih lama</Button>
        </div>
      )}
      {r.me.can_comment ? (
        <div className="flex gap-3"><Avatar name="Saya" className="bg-muted text-muted-foreground" /><div className="min-w-0 flex-1"><Composer r={r} placeholder="Tambahkan komentar… sebut orang dengan @username" /></div></div>
      ) : <p className="text-sm text-muted-foreground">Komentar dibuka setelah request ditugaskan.</p>}
    </section>
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

const SUB_STATUS: Record<string, { label: string; cls: string }> = {
  MENUNGGU_REVIEW: { label: 'Menunggu ditinjau', cls: 'bg-amber-50 text-amber-800' },
  DISETUJUI: { label: 'Disetujui', cls: 'bg-emerald-50 text-emerald-700' },
  REVISI: { label: 'Dikembalikan untuk revisi', cls: 'bg-orange-50 text-orange-800' },
  OTOMATIS: { label: 'Selesai', cls: 'bg-emerald-50 text-emerald-700' },
  DITARIK: { label: 'Ditarik', cls: 'bg-slate-100 text-slate-600' },
}

/** "Petunjuk": what to do — instructions, files, comments (left of the page). */
function Instructions({ r }: { r: IrDetail }) {
  const add = useIrAddFiles(r.id)
  const del = useIrDeleteFile(r.id)
  const [files, setFiles] = useState<PickedFile[]>([])
  const remove = async (f: IrFile) => { if (await confirmDialog({ message: `Hapus ${f.name}?`, danger: true })) run(del, f.id, 'File dihapus.') }
  return (
    <div className="space-y-6">
      {r.cancel_reason && <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">Dibatalkan: {r.cancel_reason}</div>}
      <div className="whitespace-pre-wrap text-[15px] leading-relaxed">{r.description}</div>
      {(r.attachments.length > 0 || r.me.can_edit) && (
        <div className="space-y-2">
          <IrFileList requestId={r.id} files={r.attachments} onDelete={r.me.can_edit ? remove : undefined} cards />
          {r.me.can_edit && (
            <div className="space-y-2">
              <IrFilePicker files={files} onChange={setFiles} maxMb={r.me.max_mb} label="+ Tambah lampiran" />
              {files.length > 0 && <Button size="sm" disabled={add.isPending} onClick={() => { const t = readyTokens(files); if (!t) return toast.invalid('Tunggu upload selesai.'); run(add, { attachments: t }, 'File ditambahkan.', () => setFiles([])) }}>Simpan file</Button>}
            </div>
          )}
        </div>
      )}
      <div className="border-t pt-5"><Comments r={r} /></div>
    </div>
  )
}

/** "Hasil penerima" (Classroom's student work): the people on the left, the picked one's work and its review. */
function Results({ r }: { r: IrDetail }) {
  const review = useIrReview(r.id)
  const [revising, setRevising] = useState<number | null>(null)
  const first = r.assignees.find((a) => r.submissions.some((s) => s.assignee_id === a.id && s.can_review)) ?? r.assignees[0]
  const [picked, setPicked] = useState<number | null>(first?.id ?? null)
  const who = r.assignees.find((a) => a.id === picked)
  const work = r.submissions.filter((s) => s.assignee_id === picked)
  return (
    <div className="grid gap-4 md:grid-cols-[16rem_minmax(0,1fr)]">
      <Card className="h-fit overflow-hidden rounded-2xl">
        <ul className="divide-y">
          {r.assignees.map((a) => {
            const waiting = r.submissions.some((s) => s.assignee_id === a.id && s.can_review)
            return (
              <li key={a.id}>
                <button type="button" onClick={() => setPicked(a.id)}
                  className={cn('flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm hover:bg-muted/50', picked === a.id && 'bg-blue-50 shadow-[inset_3px_0_0_theme(colors.blue.500)]')}>
                  <Avatar name={a.name} />
                  <span className="min-w-0 flex-1"><span className="block truncate font-medium">{a.name}</span>
                    <span className={cn('block text-xs', partTone(a.status, a.late))}>{a.late ? 'Terlambat' : a.status_label}</span></span>
                  {waiting && <span className="size-2 shrink-0 rounded-full bg-amber-500" title="Menunggu ditinjau" />}
                </button>
              </li>
            )
          })}
        </ul>
      </Card>

      <div className="space-y-3">
        {who && (
          <div className="flex items-center gap-3 px-1">
            <Avatar name={who.name} className="size-10 text-sm" />
            <div><div className="font-medium">{who.name}</div><div className="text-xs text-muted-foreground">{[who.position, who.submitted_at && `diserahkan ${fmtDateTime(who.submitted_at)}`].filter(Boolean).join(' · ')}</div></div>
          </div>
        )}
        {work.length === 0 && <Card className="rounded-2xl p-8 text-center text-sm text-muted-foreground">Belum ada hasil yang diserahkan.</Card>}
        {work.map((s) => (
          <Card key={s.id} className="space-y-3 rounded-2xl p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="text-sm">{s.round > 1 ? `Revisi ke-${s.round - 1}` : 'Hasil pertama'} · <span className="text-muted-foreground">{fmtDateTime(s.created_at)}</span></div>
              <span className={cn('rounded-md px-2 py-0.5 text-xs font-medium', SUB_STATUS[s.status]?.cls)}>{SUB_STATUS[s.status]?.label ?? s.status}</span>
            </div>
            {s.note && <p className="whitespace-pre-wrap text-sm">{s.note}</p>}
            <IrFileList requestId={r.id} files={s.files} cards />
            {s.reviewed_by && <div className="rounded-lg bg-muted/50 px-3 py-2 text-sm"><span className="font-medium">{s.reviewed_by}</span> · {fmtDateTime(s.reviewed_at)}{s.review_note ? `: ${s.review_note}` : ''}</div>}
            {s.can_review && (
              <div className="flex flex-wrap justify-end gap-2 border-t pt-3">
                <Button variant="outline" className="border-orange-300 text-orange-700 hover:bg-orange-50" onClick={() => setRevising(s.id)}><Undo2 className="size-4" /> Kembalikan</Button>
                <Button className="bg-emerald-600 text-white hover:bg-emerald-700" disabled={review.isPending}
                  onClick={async () => { if (await confirmDialog({ title: 'Setujui hasil?', message: `Hasil ${s.user} disetujui: bagiannya selesai.`, confirmLabel: 'Setujui' })) run(review, { submission: s.id, approve: true }, 'Hasil disetujui.') }}>
                  <CheckCircle2 className="size-4" /> Setujui
                </Button>
              </div>
            )}
          </Card>
        ))}
      </div>
      <ReasonDialog open={revising !== null} title="Kembalikan untuk revisi" label="Apa yang perlu direvisi" confirm="Kembalikan" busy={review.isPending} onClose={() => setRevising(null)}
        onSubmit={(reason) => run(review, { submission: revising!, approve: false, note: reason }, 'Dikembalikan untuk revisi.', () => setRevising(null))} />
    </div>
  )
}

/**
 * Important Request, laid out like a Google Classroom assignment: the instructions on the left (title,
 * from whom, the due date, files, comments) and "Tugas Anda" on the right; the creator also gets
 * "Hasil penerima" — each recipient's work to approve or return.
 */
export function IrDetailPage() {
  const { id } = useParams()
  const { data: r, isLoading, isError } = useIr(Number(id))
  const [tab, setTab] = useState<Tab>('instructions')
  if (isError) return <div className="space-y-4"><BackLink to="/important-requests" label="Important Request" /><p className="text-sm text-destructive">Request tidak ditemukan atau bukan untuk Anda.</p></div>
  if (isLoading || !r) return <p className="py-16 text-center text-sm text-muted-foreground">Memuat…</p>
  const closed = ['SELESAI', 'DIBATALKAN', 'DRAFT'].includes(r.status)
  const toReview = r.submissions.filter((s) => s.can_review).length
  const dot = r.status === 'SELESAI' ? 'bg-emerald-500' : r.status === 'DIBATALKAN' ? 'bg-slate-400' : r.status === 'DRAFT' ? 'bg-slate-400' : r.overdue ? 'bg-red-500' : 'bg-primary'

  return (
    <div className="mx-auto max-w-6xl space-y-4 pb-8">
      <BackLink to={r.me.is_creator ? '/important-requests/saya' : '/important-requests/tugas'} label={r.me.is_creator ? 'Permintaan Saya' : 'Tugas Saya'} />

      {r.me.is_creator && r.status !== 'DRAFT' && (
        // like Classroom's "Petunjuk | Tugas siswa"
        <div className="flex justify-center border-b" role="tablist">
          {([['instructions', 'Petunjuk'], ['results', 'Hasil penerima']] as const).map(([k, label]) => (
            <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
              className={cn('-mb-px flex items-center gap-1.5 border-b-[3px] px-5 pb-2.5 pt-1 text-sm font-medium', tab === k ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>
              {label}{k === 'results' && toReview > 0 && <span className="rounded-full bg-primary px-1.5 text-xs text-primary-foreground">{toReview}</span>}
            </button>
          ))}
        </div>
      )}

      {tab === 'results' && r.me.is_creator ? <Results r={r} /> : (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="min-w-0 space-y-5">
            <header className="flex gap-3 border-b-2 border-primary/70 pb-4 sm:gap-4">
              <span className={cn('mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-full text-white sm:size-12', dot)}><ClipboardList className="size-6" /></span>
              <div className="min-w-0 flex-1 space-y-1">
                <h1 className="break-words text-2xl font-normal leading-tight sm:text-[28px]">{r.title}</h1>
                <div className="text-sm text-muted-foreground">{r.creator ?? '-'} · {fmtDateTime(r.sent_at ?? r.created_at)}</div>
                <div className="flex flex-wrap items-center gap-2 pt-1 text-sm">
                  <span className="font-mono text-xs text-muted-foreground">{r.number}</span>
                  <IrPriorityBadge priority={r.priority} />
                  <IrStatusBadge status={r.status} label={r.status_label} />
                  {r.unread_comments > 0 && <span className="inline-flex items-center gap-1 text-xs text-primary"><MessageSquare className="size-3.5" />{r.unread_comments} komentar baru</span>}
                </div>
              </div>
              {/* the due date, top right like Classroom's "Tenggat" */}
              <div className="shrink-0 text-right">
                <div className="text-xs text-muted-foreground">Tenggat</div>
                <div className={cn('text-sm font-medium', r.overdue && !closed && 'text-red-600')}>{r.deadline ? fmtDateTime(r.deadline) : 'Tanpa tenggat'}</div>
                {r.overdue && !closed && <div className="inline-flex items-center gap-1 text-xs font-medium text-red-600"><AlertTriangle className="size-3" /> Terlambat</div>}
              </div>
            </header>
            {/* phone: my part right under the title */}
            <div className="space-y-4 lg:hidden">
              {r.me.assignee && <MyWork r={r} />}
              {r.me.is_creator && <RecipientsCard r={r} onResults={() => setTab('results')} />}
            </div>
            <Instructions r={r} />
          </div>
          <aside className="hidden lg:block">
            <div className="sticky top-20 space-y-4">
              {r.me.assignee && <MyWork r={r} />}
              {r.me.is_creator && <RecipientsCard r={r} onResults={() => setTab('results')} />}
              {!r.me.assignee && !r.me.is_creator && <CreatorActions r={r} />}
            </div>
          </aside>
        </div>
      )}
      {r.me.is_creator && r.status === 'DRAFT' && <p className="text-xs text-muted-foreground">Draft: belum terlihat oleh penerima. <Link to={`/important-requests/${r.id}/ubah`} className="text-primary hover:underline">Lengkapi</Link> lalu tugaskan.</p>}
    </div>
  )
}
