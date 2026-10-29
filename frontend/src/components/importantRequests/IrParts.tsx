import { useRef, useState } from 'react'
import { AlertTriangle, Download, Eye, FileText, Loader2, Paperclip, Trash2, X } from 'lucide-react'
import { IR_PRIORITY, IR_STATUS, openIrFile, uploadIrFile, type IrFile, type IrPriority, type IrStatus } from '@/features/importantRequests/api'
import { apiErrorMessage } from '@/lib/api'
import { toast } from '@/lib/notify'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export const fmtDateTime = (v: string | null | undefined) => (v ? new Date(v).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-')
export const fmtSize = (n: number) => (n >= 1024 * 1024 ? `${(n / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

export function IrStatusBadge({ status, label }: { status: IrStatus; label?: string | null }) {
  const s = IR_STATUS[status] ?? IR_STATUS.DRAFT
  return <Badge variant={s.variant} className="whitespace-nowrap text-xs">{label || s.label}</Badge>
}

export function IrPriorityBadge({ priority }: { priority: IrPriority }) {
  const p = IR_PRIORITY[priority] ?? IR_PRIORITY.MEDIUM
  return <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', p.cls)}>{priority === 'URGENT' && <AlertTriangle className="size-3" />}{p.label}</span>
}

/** The deadline and, beside it, how close / how late it is (overdue is an indicator, not a status). */
export function IrDeadline({ deadline, overdue, closed }: { deadline: string | null; overdue: boolean; closed?: boolean }) {
  if (!deadline) return <span className="text-muted-foreground">-</span>
  const ms = new Date(deadline).getTime() - Date.now()
  const days = Math.ceil(ms / 86_400_000)
  const note = closed ? null : overdue ? `terlambat ${Math.max(1, -days + 1)} hari` : days <= 0 ? 'hari ini' : days <= 3 ? `${days} hari lagi` : null
  return (
    <span className="whitespace-nowrap">
      <span className={cn('tabular-nums', overdue && !closed && 'font-semibold text-red-600')}>{fmtDateTime(deadline)}</span>
      {note && <span className={cn('ml-1.5 rounded px-1.5 py-0.5 text-[11px] font-medium', overdue ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800')}>{note}</span>}
    </span>
  )
}

export interface PickedFile { key: string; name: string; size: number; progress: number; token?: string; error?: string }

/**
 * Pick files and upload them at once (in chunks, with a progress bar); the parent keeps the tokens
 * of the finished ones and sends them with its save. maxMb: the server's limit per file.
 */
export function IrFilePicker({ files, onChange, maxMb, label = 'Lampirkan file', compact }: {
  files: PickedFile[]; onChange: (f: PickedFile[] | ((prev: PickedFile[]) => PickedFile[])) => void; maxMb: number; label?: string; compact?: boolean
}) {
  const input = useRef<HTMLInputElement>(null)
  const add = (list: FileList | null) => {
    if (!list?.length) return
    for (const file of [...list].slice(0, 10)) {
      const key = `${file.name}-${file.size}-${Math.random()}`
      if (file.size > maxMb * 1024 * 1024) {
        toast.invalid(`${file.name} lebih dari ${maxMb} MB.`)
        continue
      }
      onChange((prev) => [...prev, { key, name: file.name, size: file.size, progress: 0 }])
      uploadIrFile(file, (p) => onChange((prev) => prev.map((x) => (x.key === key ? { ...x, progress: p } : x))))
        .then((token) => onChange((prev) => prev.map((x) => (x.key === key ? { ...x, token, progress: 1 } : x))))
        .catch((e) => onChange((prev) => prev.map((x) => (x.key === key ? { ...x, error: apiErrorMessage(e) } : x))))
    }
  }
  return (
    <div className="space-y-2">
      <input ref={input} type="file" multiple className="hidden" onChange={(e) => { add(e.target.files); e.target.value = '' }}
        accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.7z" />
      <Button type="button" variant="outline" size="sm" className={compact ? 'h-8' : undefined} onClick={() => input.current?.click()}>
        <Paperclip className="size-4" /> {label}
      </Button>
      {!compact && <p className="text-xs text-muted-foreground">PDF, Word, Excel, PowerPoint, CSV, TXT, gambar, ZIP/RAR/7Z · maks {maxMb} MB per file</p>}
      {files.length > 0 && (
        <ul className="space-y-1.5">
          {files.map((f) => (
            <li key={f.key} className="flex items-center gap-2 rounded-md border bg-card px-2.5 py-1.5 text-sm">
              <FileText className="size-4 shrink-0 text-muted-foreground" />
              <div className="min-w-0 flex-1">
                <div className="truncate">{f.name} <span className="text-xs text-muted-foreground">· {fmtSize(f.size)}</span></div>
                {f.error ? <div className="text-xs text-destructive">{f.error}</div> : !f.token && (
                  <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted"><div className="h-full bg-primary transition-all" style={{ width: `${Math.round(f.progress * 100)}%` }} /></div>
                )}
              </div>
              {!f.token && !f.error ? <Loader2 className="size-4 animate-spin text-muted-foreground" /> : null}
              <button type="button" aria-label={`Hapus ${f.name}`} onClick={() => onChange((prev) => prev.filter((x) => x.key !== f.key))} className="text-muted-foreground hover:text-destructive"><X className="size-4" /></button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** Tokens of the finished uploads, or null while one is still uploading / failed (the save waits). */
export function readyTokens(files: PickedFile[]): string[] | null {
  if (files.some((f) => !f.token)) return null
  return files.map((f) => f.token!)
}

/** Files of a request: name, size, type, who and when; preview (pdf / images) and download, after the server's check. */
export function IrFileList({ requestId, files, onDelete, empty }: { requestId: number; files: IrFile[]; onDelete?: (f: IrFile) => void; empty?: string }) {
  const [busy, setBusy] = useState<number | null>(null)
  const open = async (f: IrFile, preview: boolean) => {
    setBusy(f.id)
    try { await openIrFile(requestId, f, preview) } catch (e) { toast.fromError(e, apiErrorMessage(e)) } finally { setBusy(null) }
  }
  if (files.length === 0) return empty ? <p className="text-sm text-muted-foreground">{empty}</p> : null
  return (
    <ul className="divide-y rounded-lg border bg-card">
      {files.map((f) => (
        <li key={f.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-[10px] font-semibold uppercase text-muted-foreground">{f.extension ?? 'file'}</span>
          <div className="min-w-0 flex-1">
            <div className="truncate font-medium" title={f.name}>{f.name}</div>
            <div className="text-xs text-muted-foreground">{fmtSize(f.size)} · {f.uploader ?? '-'} · {fmtDateTime(f.created_at)}</div>
          </div>
          <div className="flex shrink-0 gap-1">
            {f.previewable && <Button size="sm" variant="ghost" className="h-8" disabled={busy === f.id} onClick={() => open(f, true)}><Eye className="size-4" /> Lihat</Button>}
            <Button size="sm" variant="ghost" className="h-8" disabled={busy === f.id} onClick={() => open(f, false)}>{busy === f.id ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />} Unduh</Button>
            {onDelete && <Button size="sm" variant="ghost" className="h-8 text-muted-foreground hover:text-destructive" onClick={() => onDelete(f)} aria-label={`Hapus ${f.name}`}><Trash2 className="size-4" /></Button>}
          </div>
        </li>
      ))}
    </ul>
  )
}
