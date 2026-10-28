import { useRef, useState } from 'react'
import { Camera, ImageIcon, Loader2, Trash2 } from 'lucide-react'
import { api, apiErrorMessage } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Evidence photos of the tracking modules (the Excel "DATA BUKTI" folder and the
 * pictures inside cells), stored in STOCKWISE: /api/tracking-photos.
 */
export interface PhotoRef {
  id: number
  file_name: string | null
}

/** Excel "Status Hasil Pengerjaan" of the tracking sheets. */
export const EXCEL_STATUS: Record<string, { label: string; variant: 'neutral' | 'default' | 'success' | 'warning' }> = {
  REQUESTED: { label: 'REQUESTED', variant: 'neutral' },
  ON_GOING: { label: 'ON-GOING', variant: 'default' },
  COMPLETED: { label: 'COMPLETED', variant: 'success' },
  INCOMPLETE: { label: 'Belum lengkap', variant: 'warning' },
}

export function ExcelStatusBadge({ status, hint }: { status: string; hint?: string }) {
  const s = EXCEL_STATUS[status] ?? { label: status, variant: 'neutral' as const }
  return <Badge variant={s.variant} title={hint}>{s.label}</Badge>
}

/** Opens a stored photo / video in a new tab (the API needs the bearer token, so it is fetched as a blob). */
export async function openTrackingPhoto(id: number) {
  const w = window.open('', '_blank')
  const res = await api.get(`/api/tracking-photos/${id}`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  if (w) w.location.href = url
  else window.location.href = url
}

/** Resize a picked photo to a JPEG data URL (keeps uploads small on phones). */
export async function photoToDataUrl(file: File, max = 1600): Promise<string> {
  const url = URL.createObjectURL(file)
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const i = new Image()
      i.onload = () => resolve(i)
      i.onerror = () => reject(new Error('File bukan gambar.'))
      i.src = url
    })
    const scale = Math.min(1, max / Math.max(img.width, img.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(img.width * scale)
    canvas.height = Math.round(img.height * scale)
    canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/jpeg', 0.85)
  } finally {
    URL.revokeObjectURL(url)
  }
}

/** Table cell: "Open File" like the Excel link, or the Nama File when the file itself is missing. */
export function PhotoOpen({ photo, fileName }: { photo?: PhotoRef | null; fileName?: string | null }) {
  if (photo) {
    return (
      <button type="button" className="inline-flex items-center gap-1 whitespace-nowrap text-primary hover:underline"
        onClick={(e) => { e.stopPropagation(); void openTrackingPhoto(photo.id) }} title={photo.file_name ?? undefined}>
        <ImageIcon className="size-3.5" /> Open File
      </button>
    )
  }
  if (fileName) return <span className="whitespace-nowrap text-xs text-muted-foreground" title="Nama File ada, file fotonya belum ada di STOCKWISE">tanpa file</span>
  return <span className="text-muted-foreground">—</span>
}

/**
 * Same slot as PhotoSlot, as a couple of small icon buttons instead of a
 * labelled card — for an inline table row, where every bit of width counts.
 * A photo: click it to open. canEdit: a small camera icon to upload/replace.
 */
export function CompactPhotoSlot({ entity, entityId, slot, label, photo, canEdit, onChanged }: {
  entity: string; entityId: number; slot: string; label: string; photo?: PhotoRef | null; canEdit?: boolean; onChanged?: () => void
}) {
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const iconBtn = 'inline-flex size-7 shrink-0 items-center justify-center rounded-md border bg-card text-muted-foreground hover:border-primary/50 hover:text-primary disabled:opacity-50'

  async function upload(file: File) {
    setBusy(true)
    setErr(null)
    try {
      await api.post('/api/tracking-photos', { entity, entity_id: entityId, slot, photo: await photoToDataUrl(file) })
      onChanged?.()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex items-center gap-1" title={err ?? undefined}>
      {photo && (
        <button type="button" onClick={(e) => { e.stopPropagation(); void openTrackingPhoto(photo.id) }}
          className={cn(iconBtn, 'border-primary/40 text-primary')} title={photo.file_name ?? `Lihat ${label}`} aria-label={`Lihat ${label}`}>
          <ImageIcon className="size-3.5" />
        </button>
      )}
      {canEdit && (
        busy ? (
          <span className={iconBtn}><Loader2 className="size-3.5 animate-spin" /></span>
        ) : (
          <button type="button" onClick={() => input.current?.click()} className={iconBtn} title={photo ? `Ganti ${label}` : `Unggah ${label}`} aria-label={photo ? `Ganti ${label}` : `Unggah ${label}`}>
            <Camera className="size-3.5" />
          </button>
        )
      )}
      <input ref={input} type="file" accept="image/*" capture="environment" className="hidden"
        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void upload(f) }} />
      {err && <span className="text-xs text-destructive">!</span>}
    </div>
  )
}

/**
 * One photo slot of a row in a detail view: open, take / choose a photo, remove.
 * `onChanged` refreshes the row (its status may follow the photo, as in the Excel).
 */
export function PhotoSlot({ entity, entityId, slot, label, photo, fileName, canEdit, onChanged }: {
  entity: string; entityId: number; slot: string; label: string; photo?: PhotoRef | null
  fileName?: string | null; canEdit?: boolean; onChanged?: () => void
}) {
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)

  async function upload(file: File) {
    setBusy(true)
    setErr(null)
    try {
      await api.post('/api/tracking-photos', { entity, entity_id: entityId, slot, photo: await photoToDataUrl(file), file_name: fileName ?? undefined })
      onChanged?.()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  async function remove() {
    if (!photo || !(await confirmDialog(`Hapus ${label.toLowerCase()}?`))) return
    setBusy(true)
    try {
      await api.delete(`/api/tracking-photos/${photo.id}`)
      onChanged?.()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-1 rounded-md border p-2 text-xs">
      <div className="font-medium text-muted-foreground">{label}</div>
      <div className="flex flex-wrap items-center gap-2">
        <PhotoOpen photo={photo} fileName={fileName} />
        {canEdit && (
          <>
            <button type="button" disabled={busy} onClick={() => input.current?.click()}
              className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-primary hover:bg-muted" title={photo ? 'Ganti foto' : 'Ambil / pilih foto'}>
              {busy ? <Loader2 className="size-3.5 animate-spin" /> : <Camera className="size-3.5" />} {photo ? 'Ganti' : 'Unggah'}
            </button>
            {photo && (
              <button type="button" disabled={busy} onClick={remove} className="rounded p-0.5 text-destructive hover:bg-muted" aria-label="Hapus foto">
                <Trash2 className="size-3.5" />
              </button>
            )}
            <input ref={input} type="file" accept="image/*" capture="environment" className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void upload(f) }} />
          </>
        )}
      </div>
      {photo?.file_name && <div className="truncate font-mono text-[11px] text-muted-foreground">{photo.file_name}</div>}
      {err && <p className="text-destructive">{err}</p>}
    </div>
  )
}
