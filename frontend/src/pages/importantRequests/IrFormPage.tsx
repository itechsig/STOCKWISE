import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ClipboardList, Save, Search, Send } from 'lucide-react'
import { useCreateIr, useIr, useIrRecipients, useSendIr, useUpdateIr, type IrPayload, type IrPriority } from '@/features/importantRequests/api'
import { IrFilePicker, IrFileList, readyTokens, type PickedFile } from '@/components/importantRequests/IrParts'
import { BackLink } from '@/components/BackLink'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { confirmDialog } from '@/components/ui/confirm'
import { apiErrorMessage } from '@/lib/api'
import { invalidTo, toast } from '@/lib/notify'
import { cn } from '@/lib/utils'

const toLocal = (v: string | null | undefined) => (v ? new Date(new Date(v).getTime() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 16) : '')

/** Buat / ubah Important Request: a draft is all editable; once sent, the content and more recipients. */
export function IrFormPage() {
  const { id } = useParams()
  const editId = Number(id) || 0
  const navigate = useNavigate()
  const existing = useIr(editId)
  const r = existing.data
  const sent = !!r && r.status !== 'DRAFT'
  const recipients = useIrRecipients(true)
  const create = useCreateIr()
  const update = useUpdateIr(editId)
  const sendIt = useSendIr(editId)

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [priority, setPriority] = useState<IrPriority>('MEDIUM')
  const [deadline, setDeadline] = useState('')
  const [picked, setPicked] = useState<number[]>([])
  const [who, setWho] = useState('')
  const [files, setFiles] = useState<PickedFile[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [showAdvanced, setShowAdvanced] = useState(false)

  useEffect(() => {
    if (!r) return
    setTitle(r.title); setDescription(r.description); setPriority(r.priority); setDeadline(toLocal(r.deadline))
    setPicked(r.assignees.map((a) => a.user_id))
  }, [r])

  const locked = new Set(sent ? r!.assignees.map((a) => a.user_id) : [])
  const people = useMemo(() => {
    const q = who.trim().toLowerCase()
    return (recipients.data ?? []).filter((p) => !q || `${p.name} ${p.username} ${p.division ?? ''} ${p.position ?? ''}`.toLowerCase().includes(q))
  }, [recipients.data, who])
  const toggle = (uid: number) => !locked.has(uid) && setPicked((p) => (p.includes(uid) ? p.filter((x) => x !== uid) : [...p, uid]))

  const save = async (send: boolean) => {
    setErr(null)
    if (!title.trim()) return invalidTo(setErr, 'Judul wajib diisi.')
    if (!description.trim()) return invalidTo(setErr, 'Deskripsi / instruksi wajib diisi.')
    if (send && picked.length === 0) return invalidTo(setErr, 'Pilih minimal satu penerima.')
    if (send && !deadline) return invalidTo(setErr, 'Isi deadline sebelum mengirim.')
    if (deadline && !sent && new Date(deadline).getTime() <= Date.now()) return invalidTo(setErr, 'Deadline harus setelah sekarang.')
    const tokens = readyTokens(files)
    if (tokens === null) return invalidTo(setErr, 'Tunggu upload file selesai (atau hapus yang gagal).')
    if (send && !(await confirmDialog({
      title: 'Kirim request?',
      message: `"${title.trim()}" dikirim ke ${picked.length} penerima. Mereka mendapat notifikasi dan request tidak bisa kembali menjadi draft.`,
      confirmLabel: 'Kirim',
    }))) return
    const body: IrPayload = {
      title: title.trim(), description: description.trim(), priority,
      assignee_ids: picked, attachments: tokens,
      ...(sent ? {} : { deadline: deadline ? new Date(deadline).toISOString() : null }),
    }
    try {
      if (editId) {
        await update.mutateAsync(body)
        if (send) await sendIt.mutateAsync(undefined)
        toast.success(send ? 'Request dikirim.' : 'Perubahan disimpan.')
        navigate(`/important-requests/${editId}`)
      } else {
        const made = await create.mutateAsync({ ...body, send })
        toast.success(send ? 'Request dikirim ke penerima.' : 'Draft disimpan.')
        navigate(`/important-requests/${made.id}`)
      }
    } catch (e) {
      setErr(apiErrorMessage(e))
      toast.fromError(e, apiErrorMessage(e))
    }
  }

  if (editId && existing.isLoading) return <p className="py-16 text-center text-sm text-muted-foreground">Memuat…</p>
  if (editId && r && !r.me.can_edit) return <p className="text-sm text-destructive">Request ini tidak bisa diubah.</p>
  const busy = create.isPending || update.isPending

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <BackLink to={editId ? `/important-requests/${editId}` : '/important-requests'} label={editId ? (r?.number ?? 'Request') : 'Important Request'} />
      <PageHeader title={editId ? `Ubah ${r?.number ?? ''}` : 'Buat Important Request'} icon={<ClipboardList className="size-5" />}
        subtitle={sent ? 'Request sudah dikirim: perubahan dicatat di riwayat dan penerima diberi tahu. Deadline diubah dari halaman detail.' : 'Nomor request dibuat otomatis saat disimpan.'} />

      <Card className="space-y-6 p-4 sm:p-6">
        {/* Essential fields */}
        <div className="space-y-4">
          <div className="space-y-1.5"><Label htmlFor="ir-title">Judul *</Label><Input id="ir-title" value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} placeholder="mis. Perbaiki formula penjualan Oktober" /></div>
          <div className="space-y-1.5"><Label htmlFor="ir-desc">Deskripsi & instruksi pekerjaan *</Label>
            <Textarea id="ir-desc" rows={5} value={description} maxLength={20000} onChange={(e) => setDescription(e.target.value)} placeholder="Apa yang harus dikerjakan, acuan yang dipakai, dan hasil yang diharapkan." />
          </div>
        </div>

        {/* Recipients - always important */}
        <div className="space-y-3">
          <div className="flex flex-wrap items-end justify-between gap-2">
            <Label>Penerima * <span className="font-normal text-muted-foreground">({picked.length} dipilih)</span></Label>
            <div className="relative w-full sm:w-64">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input className="h-8 pl-8" placeholder="Cari nama / divisi" value={who} onChange={(e) => setWho(e.target.value)} />
            </div>
          </div>
          <div className="max-h-56 overflow-y-auto rounded-lg border">
            {recipients.isLoading && <p className="p-3 text-sm text-muted-foreground">Memuat…</p>}
            {people.map((p) => (
              <label key={p.id} className={cn('flex cursor-pointer items-center gap-3 border-b px-3 py-2 text-sm last:border-b-0 hover:bg-muted/40', picked.includes(p.id) && 'bg-primary/5', locked.has(p.id) && 'cursor-not-allowed opacity-70')}>
                <input type="checkbox" className="size-4 accent-primary" checked={picked.includes(p.id)} disabled={locked.has(p.id)} onChange={() => toggle(p.id)} />
                <span className="min-w-0 flex-1"><span className="font-medium">{p.name}</span> <span className="text-xs text-muted-foreground">@{p.username}</span></span>
                <span className="shrink-0 text-xs text-muted-foreground">{[p.position, p.division].filter(Boolean).join(' · ')}</span>
              </label>
            ))}
            {recipients.data && people.length === 0 && <p className="p-3 text-sm text-muted-foreground">Tidak ada yang cocok.</p>}
          </div>
          {sent && <p className="text-xs text-muted-foreground">Penerima yang sudah ditugaskan tidak bisa dihapus; penerima baru bisa ditambahkan.</p>}
        </div>

        {/* Advanced options - progressive disclosure */}
        <div className="border-t pt-4">
          <button type="button" onClick={() => setShowAdvanced(!showAdvanced)} className="flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground">
            {showAdvanced ? 'Sembunyikan opsi lanjutan' : 'Tampilkan opsi lanjutan'}
          </button>
          {showAdvanced && (
            <div className="mt-4 space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label>Prioritas</Label>
                  <div className="grid grid-cols-4 gap-1 rounded-lg border bg-muted/40 p-1" role="radiogroup" aria-label="Prioritas">
                    {(['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as IrPriority[]).map((p) => (
                      <button key={p} type="button" role="radio" aria-checked={priority === p} onClick={() => setPriority(p)}
                        className={cn('rounded-md px-2 py-1.5 text-xs font-medium sm:text-sm', priority === p ? (p === 'URGENT' ? 'bg-red-600 text-white' : 'bg-card text-primary shadow-sm ring-1 ring-border') : 'text-muted-foreground hover:text-foreground')}>
                        {p.charAt(0) + p.slice(1).toLowerCase()}
                      </button>
                    ))}
                  </div>
                </div>
                <div className="space-y-1.5"><Label htmlFor="ir-deadline">Deadline {!sent && '*'}</Label>
                  <Input id="ir-deadline" type="datetime-local" value={deadline} disabled={sent} onChange={(e) => setDeadline(e.target.value)} />
                </div>
              </div>

              <div className="space-y-1.5">
                <Label>Lampiran (file awal)</Label>
                {r && r.attachments.length > 0 && <IrFileList requestId={r.id} files={r.attachments} />}
                <IrFilePicker files={files} onChange={setFiles} maxMb={r?.me.max_mb ?? 20} />
              </div>
            </div>
          )}
        </div>

        {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
        <div className="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:justify-end">
          {sent
            ? <Button disabled={busy} onClick={() => save(false)}><Save className="size-4" /> Simpan perubahan</Button>
            : <Button disabled={busy} onClick={() => save(true)}><Send className="size-4" /> Kirim request</Button>}
        </div>
      </Card>
    </div>
  )
}
