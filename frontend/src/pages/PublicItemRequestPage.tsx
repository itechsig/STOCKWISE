import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { CheckCircle2, ListChecks, Loader2, Lock, Send } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { trackedTokens, usePublicRequestForm, useSubmitPublicRequest } from '@/features/itemRequests/api'
import { FormExtraFields } from '@/components/itemRequests/FormExtraFields'
import { RequestLinesEditor, emptyLine, filledLines, linesError, toPayloadLines, type DraftLine } from '@/components/itemRequests/RequestLinesEditor'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { invalidTo } from '@/lib/notify'

/** Header of the pages opened without an account (request form, Cek Permintaan). */
export function PublicShell({ children, label, title = 'Request Barang', action }: { children: React.ReactNode; label?: string | null; title?: string; action?: React.ReactNode }) {
  return (
    <div className="min-h-dvh bg-background">
      <header className="sticky top-0 z-30 border-b bg-card/95 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
          <img src="/logo.png" alt="" className="size-8 shrink-0" />
          <span className="min-w-0 flex-1">
            <span className="block text-sm font-semibold leading-tight">{title}</span>
            <span className="block truncate text-xs text-muted-foreground">{label || 'Gudang PT Surya Inti Gas'}</span>
          </span>
          {action}
        </div>
      </header>
      <main className="mx-auto max-w-3xl p-4">{children}</main>
    </div>
  )
}

/** The small "Permintaan saya" button: the requests sent from this device and their status. */
function MyRequestsLink() {
  const n = trackedTokens().length
  return (
    <Link to="/cek-permintaan" className="inline-flex shrink-0 items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 text-xs font-medium hover:bg-muted">
      <ListChecks className="size-4 text-primary" /> Cek permintaan
      {n > 0 && <span className="rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground">{n}</span>}
    </Link>
  )
}

function Shell(props: { children: React.ReactNode; label?: string | null; title?: string }) {
  return <PublicShell {...props} action={<MyRequestsLink />} />
}

/**
 * Request Barang opened through a share link (/form/request/:token): no account —
 * a karyawan types what they need (any name), admin gudang gets it in STOCKWISE.
 */
export function PublicItemRequestPage() {
  const { token = '' } = useParams()
  const form = usePublicRequestForm(token)
  const submit = useSubmitPublicRequest(token)
  const [name, setName] = useState('')
  const [contact, setContact] = useState('')
  const [divisi, setDivisi] = useState('')
  const [neededDate, setNeededDate] = useState('')
  const [website, setWebsite] = useState('') // honeypot
  const [detail, setDetail] = useState('')
  // the form's own divisi (Maintenance General -> MAINTENANCE, …) as a starting point
  const defaultDivisi = form.data?.form.default_divisi
  useEffect(() => { if (defaultDivisi) setDivisi((d) => d || defaultDivisi) }, [defaultDivisi])
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [err, setErr] = useState<string | null>(null)
  const [sent, setSent] = useState<{ number: string; track_token: string } | null>(null)

  if (form.isLoading) return <Shell><p className="py-10 text-center text-muted-foreground">Memuat…</p></Shell>
  if (form.isError || !form.data) {
    return <Shell><Closed title="Link tidak ditemukan" text="Link ini sudah dihapus atau salah ketik. Minta link terbaru ke admin gudang." /></Shell>
  }
  if (!form.data.is_active) {
    return <Shell label={form.data.label} title={form.data.form.label}><Closed title="Form request sedang ditutup" text="Admin gudang sedang menutup form ini. Coba lagi nanti atau hubungi admin gudang." /></Shell>
  }

  const f = form.data.form

  if (sent) {
    return (
      <Shell label={form.data.label} title={f.label}>
        <div className="flex flex-col items-center gap-3 rounded-lg border bg-card px-6 py-10 text-center">
          <CheckCircle2 className="size-12 text-emerald-600" />
          <h1 className="text-lg font-semibold">Request terkirim</h1>
          <p className="text-sm text-muted-foreground">Nomor request Anda</p>
          <p className="font-mono text-xl font-semibold">{sent.number}</p>
          <p className="max-w-sm text-sm text-muted-foreground">Admin gudang akan mengecek barangnya. Statusnya bisa Anda pantau kapan saja lewat tombol di bawah (tersimpan di perangkat ini).</p>
          <div className="flex w-full max-w-xs flex-col gap-2">
            <Button asChild size="lg"><Link to={`/cek-permintaan/${sent.track_token}`}><ListChecks className="size-4" /> Cek status permintaan</Link></Button>
            <Button variant="outline" onClick={() => { setSent(null); setLines([emptyLine()]); setNeededDate(''); setDetail('') }}>Kirim request lain</Button>
          </div>
        </div>
      </Shell>
    )
  }

  const send = () => {
    setErr(null)
    if (!name.trim()) return invalidTo(setErr, 'Isi nama Anda.')
    const lineErr = linesError(lines)
    if (lineErr) return invalidTo(setErr, lineErr)
    submit.mutate({
      requester_name: name.trim(), requester_contact: contact.trim() || null, divisi: divisi.trim() || null,
      form_detail: f.detail_label ? detail.trim() || null : null,
      needed_date: neededDate || null, lines: toPayloadLines(lines), website,
    }, { onSuccess: (d) => setSent(d), onError: (e) => setErr(apiErrorMessage(e)) })
  }

  return (
    <Shell label={form.data.label} title={f.label}>
      <div className="space-y-4">
        <div>
          <h1 className="text-lg font-semibold">{f.label}</h1>
          <p className="text-sm text-muted-foreground">Tulis nama barang sebebasnya, tidak harus sama dengan nama di gudang. Admin gudang yang mencocokkan dengan stok.</p>
        </div>
        <ol className="flex gap-2 text-xs text-muted-foreground" aria-label="Langkah">
          {['Data pemohon', 'Barang', 'Kirim'].map((t, i) => (
            <li key={t} className="flex items-center gap-1.5"><span className="flex size-5 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">{i + 1}</span>{t}{i < 2 && <span className="mx-1 text-border">›</span>}</li>
          ))}
        </ol>
        <div className="grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-2">
          <div className="text-sm font-semibold sm:col-span-2">Data pemohon</div>
          <div className="space-y-1.5">
            <Label htmlFor="rq-name">Nama Anda *</Label>
            <Input id="rq-name" value={name} maxLength={150} onChange={(e) => setName(e.target.value)} autoComplete="name" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="rq-contact">No. HP / Ext (opsional)</Label>
            <Input id="rq-contact" value={contact} maxLength={60} onChange={(e) => setContact(e.target.value)} inputMode="tel" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="rq-divisi">Divisi</Label>
            <Input id="rq-divisi" value={divisi} maxLength={100} onChange={(e) => setDivisi(e.target.value)} placeholder="mis. Maintenance" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="rq-date">Dibutuhkan tanggal</Label>
            <Input id="rq-date" type="date" value={neededDate} onChange={(e) => setNeededDate(e.target.value)} />
          </div>
          <div className="sm:col-span-2">
            <FormExtraFields form={f} detail={detail} onDetail={setDetail} />
          </div>
          {/* honeypot: hidden from people */}
          <input type="text" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
        </div>
        <RequestLinesEditor lines={lines} onChange={setLines} />
        {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
        {/* the send button stays in reach while scrolling a long list */}
        <div className="sticky bottom-0 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur">
          <Button className="w-full" size="lg" onClick={send} disabled={submit.isPending}>
            {submit.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />} Kirim request ({filledLines(lines).length} barang)
          </Button>
        </div>
      </div>
    </Shell>
  )
}

function Closed({ title, text }: { title: string; text: string }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border bg-card px-6 py-12 text-center">
      <Lock className="size-10 text-muted-foreground" />
      <h1 className="text-lg font-semibold">{title}</h1>
      <p className="max-w-sm text-sm text-muted-foreground">{text}</p>
    </div>
  )
}
