import { useState } from 'react'
import { Check, Copy, Link2, Loader2, Trash2 } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { requestFormUrl, useItemRequestLinkMutations, useItemRequestLinks, useRequestForms, useShareBase, type FormType, type ItemRequestLink } from '@/features/itemRequests/api'
import { Select } from '@/components/ui/select'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { confirmDialog } from '@/components/ui/confirm'


const when = (d: string | null) => (d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'belum pernah')

async function copy(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    const t = document.createElement('textarea')
    t.value = text
    document.body.appendChild(t)
    t.select()
    const ok = document.execCommand('copy')
    t.remove()
    return ok
  }
}

function Switch({ on, onChange, disabled, label }: { on: boolean; onChange: (v: boolean) => void; disabled?: boolean; label: string }) {
  return (
    <button type="button" role="switch" aria-checked={on} aria-label={label} disabled={disabled} onClick={() => onChange(!on)}
      className={cn('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60', on ? 'bg-emerald-500' : 'bg-muted-foreground/30')}>
      <span className={cn('inline-block size-5 rounded-full bg-white shadow transition-transform', on ? 'translate-x-5' : 'translate-x-0.5')} />
    </button>
  )
}

function LinkRow({ link }: { link: ItemRequestLink }) {
  const m = useItemRequestLinkMutations()
  const [copied, setCopied] = useState(false)
  const { base } = useShareBase()
  const url = requestFormUrl(link.token, base)
  const toggling = m.update.isPending && m.update.variables?.id === link.id
  return (
    <li className="space-y-2 py-3">
      <div className="flex items-center gap-3">
        <Switch on={link.is_active} disabled={toggling} label={link.is_active ? 'Matikan link' : 'Hidupkan link'}
          onChange={(v) => m.update.mutate({ id: link.id, is_active: v })} />
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-medium">
            {link.form_label}{link.label ? <span className="font-normal text-muted-foreground"> · {link.label}</span> : null}{' '}
            <span className={cn('ml-1 rounded-full px-2 py-0.5 text-xs', link.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-muted text-muted-foreground')}>
              {link.is_active ? 'Aktif' : 'Mati'}
            </span>
          </div>
          <div className="text-xs text-muted-foreground">{link.requests_count} request · terakhir dipakai {when(link.last_used_at)}</div>
        </div>
        <button type="button" className="rounded p-2 text-muted-foreground hover:text-destructive" aria-label="Hapus link"
          onClick={async () => { if ((await confirmDialog('Hapus link ini? Link lama tidak bisa dibuka lagi (request yang sudah masuk tetap ada).'))) m.remove.mutate(link.id) }}>
          <Trash2 className="size-4" />
        </button>
      </div>
      <div className="flex gap-2">
        <Input readOnly value={url} className={cn('h-10 min-w-0 flex-1 font-mono text-xs', !link.is_active && 'opacity-60')} onFocus={(e) => e.target.select()} />
        <Button variant={copied ? 'outline' : 'default'} className="h-10 shrink-0" onClick={async () => { if (await copy(url)) { setCopied(true); setTimeout(() => setCopied(false), 2000) } }}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Tersalin' : 'Salin'}
        </Button>
      </div>
    </li>
  )
}

/** Share the Request Barang form by link: karyawan send requests without an account; admin turns each link on / off. */
export function RequestLinksModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const share = useShareBase()
  const links = useItemRequestLinks(open)
  const m = useItemRequestLinkMutations()
  const forms = useRequestForms()
  const [label, setLabel] = useState('')
  const [formType, setFormType] = useState<FormType>('ATK')
  const [err, setErr] = useState<string | null>(null)

  const create = () => {
    setErr(null)
    m.create.mutate({ label: label.trim() || null, form_type: formType }, {
      onSuccess: (l) => { setLabel(''); void copy(requestFormUrl(l.token, share.base)) },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  return (
    <Modal open={open} onClose={onClose} title={<span className="inline-flex items-center gap-2"><Link2 className="size-4" />Link form request</span>}
      description={<>Siapa pun yang memegang link <b>aktif</b> bisa mengirim request barang <b>tanpa login</b>. Hanya bisa mengirim, tidak bisa melihat data apa pun. Matikan link untuk menutup form.</>}>
      <div className="space-y-4">
        {share.localOnly && (
          <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
            STOCKWISE sedang dibuka lewat <b>{window.location.host}</b>, jadi link di bawah hanya bisa dibuka di komputer ini.
            Buka STOCKWISE lewat alamat jaringan kantor (LAN) lalu salin link dari sana.
          </p>
        )}
        <p className="text-xs text-muted-foreground">Link dibuka lewat jaringan kantor: <span className="font-mono">{share.base}</span></p>
        <div className="space-y-2 rounded-lg border p-3">
          <div className="text-sm font-medium">Link baru</div>
          <Select className="h-10" aria-label="Jenis form" value={formType} onChange={(e) => setFormType(e.target.value as FormType)}>
            {(forms.data ?? []).filter((f) => f.type !== 'UMUM').map((f) => <option key={f.type} value={f.type}>{f.label}</option>)}
            <option value="UMUM">Request Umum</option>
          </Select>
          <div className="flex gap-2">
            <Input className="h-10 min-w-0 flex-1" placeholder="Keterangan link (opsional), mis. grup WA Gudang" value={label} onChange={(e) => setLabel(e.target.value)} maxLength={100} />
            <Button className="h-10 shrink-0" onClick={create} disabled={m.create.isPending}>
              {m.create.isPending ? <Loader2 className="size-4 animate-spin" /> : <Link2 className="size-4" />} Buat link
            </Button>
          </div>
          {m.create.isSuccess && <p className="text-xs text-emerald-600">Link dibuat dan disalin, tinggal tempel (paste) ke WhatsApp.</p>}
          {err && <p className="text-xs text-destructive">{err}</p>}
        </div>
        <div>
          <div className="text-sm font-medium">Daftar link</div>
          {links.isLoading ? <p className="py-3 text-sm text-muted-foreground">Memuat…</p>
            : (links.data ?? []).length === 0 ? <p className="py-3 text-sm text-muted-foreground">Belum ada link.</p>
              : <ul className="divide-y">{links.data!.map((l) => <LinkRow key={l.id} link={l} />)}</ul>}
          <p className="pt-1 text-xs text-muted-foreground">Link bocor ke orang yang tidak semestinya? Hapus, lalu buat link baru.</p>
        </div>
      </div>
    </Modal>
  )
}
