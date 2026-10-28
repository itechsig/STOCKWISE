import { useState } from 'react'
import { Check, Copy, Eye, Link2, Loader2, Pencil, ShieldCheck, Trash2 } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { useStockCardShareLinkMutations, useStockCardShareLinks, type StockCardShareLink } from '@/features/stock-card/api'
import { shareUrl, type ShareAccess } from '@/features/stock-card/scope'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { confirmDialog } from '@/components/ui/confirm'

export const ACCESS: Record<ShareAccess, { label: string; hint: string; icon: React.ReactNode }> = {
  VIEW: { label: 'Lihat', hint: 'hanya melihat, cetak & PDF', icon: <Eye className="size-4" /> },
 EDIT: { label: 'Edit', hint: 'isi & ubah transaksi, upload foto / file tanpa hapus', icon: <Pencil className="size-4" /> },
  FULL: { label: 'Full akses', hint: 'juga hapus, dan buat kartu baru', icon: <ShieldCheck className="size-4" /> },
}

/** Opened on the server itself: a link made here points at localhost and won't open elsewhere. */
const LOCAL = ['localhost', '127.0.0.1', '::1', '[::1]'].includes(window.location.hostname)

const when = (d: string | null) => (d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'belum pernah')

async function copy(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    // http (no secure context): the old way
    const t = document.createElement('textarea')
    t.value = text
    document.body.appendChild(t)
    t.select()
    const ok = document.execCommand('copy')
    t.remove()
    return ok
  }
}

function AccessPicker({ value, onChange }: { value: ShareAccess; onChange: (a: ShareAccess) => void }) {
  return (
    <div className="grid grid-cols-3 gap-1 rounded-lg border bg-muted/40 p-1" role="radiogroup" aria-label="Akses">
      {(Object.keys(ACCESS) as ShareAccess[]).map((a) => (
        <button key={a} type="button" role="radio" aria-checked={value === a} onClick={() => onChange(a)}
          className={cn('flex items-center justify-center gap-1.5 rounded-md px-2 py-2 text-sm font-medium', value === a ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-foreground')}>
          {ACCESS[a].icon}{ACCESS[a].label}
        </button>
      ))}
    </div>
  )
}

function LinkRow({ link, projectId }: { link: StockCardShareLink; projectId: number }) {
  const m = useStockCardShareLinkMutations(projectId)
  const [copied, setCopied] = useState(false)
  const url = shareUrl(link.token)
  return (
    <li className="space-y-2 py-3">
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <div className="truncate text-sm font-medium">{link.label || 'Link tanpa nama'}</div>
 <div className="text-xs text-muted-foreground">dibuat {link.created_by ?? ''} · dibuka {when(link.last_used_at)}</div>
        </div>
        <button type="button" className="rounded p-2 text-muted-foreground hover:text-destructive" aria-label="Hapus link"
          onClick={async () => { if ((await confirmDialog('Hapus link ini? Orang yang memegangnya tidak bisa membuka lagi.'))) m.remove.mutate(link.id) }}>
          <Trash2 className="size-4" />
        </button>
      </div>
      <AccessPicker value={link.access} onChange={(access) => m.update.mutate({ id: link.id, access, label: link.label })} />
      <div className="flex gap-2">
        <Input readOnly value={url} className="h-10 min-w-0 flex-1 font-mono text-xs" onFocus={(e) => e.target.select()} />
        <Button variant={copied ? 'outline' : 'default'} className="h-10 shrink-0" onClick={async () => { if (await copy(url)) { setCopied(true); setTimeout(() => setCopied(false), 2000) } }}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Tersalin' : 'Salin'}
        </Button>
      </div>
    </li>
  )
}

/** Share a project's Kartu Stock by link: anyone with it can open the cards without an account. */
export function ShareLinksModal({ project, open, onClose }: { project: { id: number; name: string }; open: boolean; onClose: () => void }) {
  const links = useStockCardShareLinks(project.id, open)
  const m = useStockCardShareLinkMutations(project.id)
  const [access, setAccess] = useState<ShareAccess>('EDIT')
  const [label, setLabel] = useState('')
  const [err, setErr] = useState<string | null>(null)

  const create = () => {
    setErr(null)
    m.create.mutate({ access, label: label.trim() || null }, {
      onSuccess: (l) => { setLabel(''); void copy(shareUrl(l.token)) },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  return (
    <Modal open={open} onClose={onClose} title={<span className="inline-flex items-center gap-2"><Link2 className="size-4" />Bagikan link</span>}
 description={<>Proyek <b>{project.name}</b> siapa pun yang memegang link bisa membuka kartu stock proyek ini <b>tanpa login</b>, sesuai aksesnya.</>}>
      <div className="space-y-4">
        {LOCAL && (
          <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            STOCKWISE sedang dibuka lewat <b>{window.location.host}</b>, jadi link di bawah hanya bisa dibuka di komputer ini.
            Untuk dibagikan, buka STOCKWISE lewat alamat jaringannya (mis. <span className="font-mono">http://192.168.x.x:8001</span>) lalu salin link dari sana.
          </p>
        )}
        <div className="space-y-2 rounded-lg border p-3">
          <div className="text-sm font-medium">Link baru</div>
          <AccessPicker value={access} onChange={setAccess} />
          <p className="text-xs text-muted-foreground">{ACCESS[access].label}: {ACCESS[access].hint}.</p>
          <div className="flex gap-2">
            <Input className="h-10 min-w-0 flex-1" placeholder="Nama link (opsional), mis. Teknisi lapangan" value={label} onChange={(e) => setLabel(e.target.value)} maxLength={100} />
            <Button className="h-10 shrink-0" onClick={create} disabled={m.create.isPending}>
              {m.create.isPending ? <Loader2 className="size-4 animate-spin" /> : <Link2 className="size-4" />} Buat link
            </Button>
          </div>
 {m.create.isSuccess && <p className="text-xs text-emerald-600">Link dibuat dan disalin tinggal tempel (paste) ke WhatsApp / email.</p>}
          {err && <p className="text-xs text-destructive">{err}</p>}
        </div>

        <div>
          <div className="text-sm font-medium">Link aktif</div>
          {links.isLoading ? <p className="py-3 text-sm text-muted-foreground">Memuat…</p>
            : (links.data ?? []).length === 0 ? <p className="py-3 text-sm text-muted-foreground">Belum ada link.</p>
              : <ul className="divide-y">{links.data!.map((l) => <LinkRow key={l.id} link={l} projectId={project.id} />)}</ul>}
          <p className="pt-1 text-xs text-muted-foreground">Hapus link untuk mencabut aksesnya. Link hanya bisa dibuka dari jaringan yang bisa menjangkau server STOCKWISE.</p>
        </div>
      </div>
    </Modal>
  )
}
