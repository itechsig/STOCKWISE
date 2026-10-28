import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ImagePlus, Loader2, X } from 'lucide-react'
import { api } from '@/lib/api'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { cn } from '@/lib/utils'

/** Images the requester may attach to a request (shown from the request to its end). */
export const MAX_REQUEST_IMAGES = 3

/** Request form: pick up to 3 images (photo of the broken part, an example of what is needed...). */
export function RequestImagesInput({ value, onChange, onError }: { value: string[]; onChange: (v: string[]) => void; onError?: (msg: string) => void }) {
  const input = useRef<HTMLInputElement>(null)
  const [reading, setReading] = useState(false)
  const add = async (files: FileList | null) => {
    if (!files?.length) return
    const room = MAX_REQUEST_IMAGES - value.length
    if (files.length > room) onError?.(`Gambar maksimal ${MAX_REQUEST_IMAGES}.`)
    setReading(true)
    try {
      const made = await Promise.all([...files].slice(0, Math.max(0, room)).map((f) => photoToDataUrl(f, 1600)))
      onChange([...value, ...made].slice(0, MAX_REQUEST_IMAGES))
    } catch (e) {
      onError?.((e as Error).message)
    } finally {
      setReading(false)
    }
  }
  return (
    <div className="space-y-1.5">
      <div className="text-sm font-medium">Gambar <span className="font-normal text-muted-foreground">(opsional, maksimal {MAX_REQUEST_IMAGES}: contoh barang, kondisi yang rusak, dll)</span></div>
      <input ref={input} type="file" accept="image/*" multiple className="hidden" onChange={(e) => { void add(e.target.files); e.target.value = '' }} />
      <div className="flex flex-wrap gap-2">
        {value.map((src, i) => (
          <div key={i} className="relative size-20 overflow-hidden rounded-lg border bg-muted">
            <img src={src} alt={`Gambar ${i + 1}`} className="size-full object-cover" />
            <button type="button" aria-label={`Hapus gambar ${i + 1}`} onClick={() => onChange(value.filter((_, j) => j !== i))}
              className="absolute right-1 top-1 rounded-full bg-black/60 p-0.5 text-white hover:bg-black/80"><X className="size-3.5" /></button>
          </div>
        ))}
        {value.length < MAX_REQUEST_IMAGES && (
          <button type="button" onClick={() => input.current?.click()} disabled={reading}
            className="flex size-20 flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-xs text-muted-foreground transition-colors hover:border-primary hover:bg-primary/5 hover:text-primary">
            {reading ? <Loader2 className="size-5 animate-spin" /> : <ImagePlus className="size-5" />}
            <span>{value.length}/{MAX_REQUEST_IMAGES}</span>
          </button>
        )}
      </div>
    </div>
  )
}

/** One image of a request, fetched with the session; opens full size in a new tab. */
function SessionImage({ requestId, lineId, slot, size }: { requestId: number; lineId?: number; slot: string; size: string }) {
  const path = lineId ? `/api/item-requests/${requestId}/lines/${lineId}/image/${slot}` : `/api/item-requests/${requestId}/image/${slot}`
  const { data: url } = useQuery({
    queryKey: ['item-requests', requestId, 'image', lineId ?? 0, slot],
    staleTime: Infinity,
    queryFn: async () => URL.createObjectURL((await api.get(path, { responseType: 'blob' })).data as Blob),
  })
  return (
    <a href={url} target="_blank" rel="noreferrer" className={cn('block shrink-0 overflow-hidden rounded-lg border bg-muted', size)} title="Buka gambar">
      {url ? <img src={url} alt={`Gambar ${slot.replace('img', '')}`} className="size-full object-cover" /> : <div className="size-full animate-pulse" />}
    </a>
  )
}

/**
 * The images attached to a request — on its detail, the Approval page (Manager, Direktur, Gudang) and
 * the requester's status page (by track token, no account), so everyone handling it sees them.
 */
export function RequestImages({ requestId, lineId, slots, token, size = 'size-20', className }: {
  requestId?: number
  /** the images of one item (beside its name) instead of the whole request's */
  lineId?: number
  slots?: string[] | null; token?: string; size?: string; className?: string
}) {
  if (!slots?.length) return null
  const pub = (slot: string) => `${api.defaults.baseURL ?? ''}/api/public/request-status/image?token=${token}&slot=${slot}${lineId ? `&line=${lineId}` : ''}`
  return (
    <div className={cn('flex flex-wrap gap-2', className)}>
      {slots.map((slot) => token
        ? (
          <a key={slot} href={pub(slot)} target="_blank" rel="noreferrer"
            className={cn('block shrink-0 overflow-hidden rounded-lg border bg-muted', size)} title="Buka gambar">
            <img src={pub(slot)} alt={`Gambar ${slot.replace('img', '')}`} className="size-full object-cover" />
          </a>
        )
        : requestId ? <SessionImage key={slot} requestId={requestId} lineId={lineId} slot={slot} size={size} /> : null)}
    </div>
  )
}

/** Request form, one item: a small camera button beside its name, up to 3 images of that item. */
export function LineImagesInput({ value, onChange, label }: { value: string[]; onChange: (v: string[]) => void; label: string }) {
  const input = useRef<HTMLInputElement>(null)
  const [reading, setReading] = useState(false)
  const add = async (files: FileList | null) => {
    if (!files?.length) return
    setReading(true)
    try {
      const made = await Promise.all([...files].slice(0, Math.max(0, MAX_REQUEST_IMAGES - value.length)).map((f) => photoToDataUrl(f, 1600)))
      onChange([...value, ...made].slice(0, MAX_REQUEST_IMAGES))
    } finally {
      setReading(false)
    }
  }
  return (
    <div className="flex shrink-0 items-center gap-1">
      <input ref={input} type="file" accept="image/*" multiple className="hidden" onChange={(e) => { void add(e.target.files); e.target.value = '' }} />
      {value.map((src, i) => (
        <div key={i} className="relative size-9 overflow-hidden rounded-md border bg-muted">
          <img src={src} alt={`Gambar ${label} ${i + 1}`} className="size-full object-cover" />
          <button type="button" aria-label={`Hapus gambar ${label} ${i + 1}`} onClick={() => onChange(value.filter((_, j) => j !== i))}
            className="absolute inset-0 flex items-center justify-center bg-black/50 text-white opacity-0 transition-opacity hover:opacity-100"><X className="size-3.5" /></button>
        </div>
      ))}
      {value.length < MAX_REQUEST_IMAGES && (
        <button type="button" onClick={() => input.current?.click()} disabled={reading} title={`Tambah gambar ${label} (maks ${MAX_REQUEST_IMAGES})`} aria-label={`Tambah gambar ${label}`}
          className="flex size-9 items-center justify-center rounded-md border border-dashed text-muted-foreground transition-colors hover:border-primary hover:bg-primary/5 hover:text-primary">
          {reading ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-4" />}
        </button>
      )}
    </div>
  )
}

/** The "Gambar" column of an item (rightmost in every request table): its images, or a dash. */
export function LineImagesCell({ requestId, lineId, slots }: { requestId: number; lineId: number; slots?: string[] | null }) {
  return slots?.length
    ? <RequestImages requestId={requestId} lineId={lineId} slots={slots} size="size-10" className="justify-end gap-1" />
    : <div className="text-right text-xs text-muted-foreground">-</div>
}
