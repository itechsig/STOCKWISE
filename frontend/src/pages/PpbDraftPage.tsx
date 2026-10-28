import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Ban, Loader2, Plus, Save, Send, Trash2 } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { DRAFT_STATUS, useDraftActions, useDraftLineActions, useDraftLines, usePpbDraft, usePpbDrafts, type DraftLineRow, type PpbDraftLine } from '@/features/ppbDrafts/api'
import type { ItemLookupResult } from '@/features/inventory/api'
import { BackLink } from '@/components/BackLink'
import { DataTable, type Column } from '@/components/DataTable'
import { DetailFallback } from '@/components/DetailFallback'
import { ItemPicker } from '@/components/ItemPicker'
import { PageHeader } from '@/components/PageHeader'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { confirmDialog } from '@/components/ui/confirm'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { invalidTo } from '@/lib/notify'

const fmtDateTime = (v: string | null) => (v ? new Date(v).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-')
const fmtQty = (n: number | null | undefined) => (n == null ? '—' : n.toLocaleString('id-ID', { maximumFractionDigits: 2 }))
const today = () => new Date().toISOString().slice(0, 10)

/** Draft PPB: the list of items to buy (from Request Barang or Safety Stock) — picked into one PPB. */
export function PpbDraftListPage() {
  const [tab, setTab] = useState<'lines' | 'docs'>('lines')
  return (
    <div className="space-y-4">
      <BackLink to="/ppb" label="PPB" />
      <PageHeader title="Draft PPB" subtitle="Daftar barang yang perlu dibeli — dari Request Barang (stok kurang / kosong) dan dari Safety Stock (Restock). Centang barang, lalu ajukan jadi satu PPB." />
      <div className="inline-flex rounded-lg border bg-card p-0.5">
        {([['lines', 'Barang perlu PPB'], ['docs', 'Riwayat draft']] as const).map(([k, label]) => (
          <button key={k} type="button" onClick={() => setTab(k)}
            className={tab === k ? 'rounded-md bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary' : 'rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted'}>
            {label}
          </button>
        ))}
      </div>
      {tab === 'lines' ? <DraftLinesList /> : <DraftDocsList />}
    </div>
  )
}

/** Where a line comes from: a Request Barang (number, requester) or Safety Stock. */
function SourceCell({ l }: { l: DraftLineRow }) {
  const s = l.source
  if (s.type === 'REQUEST') {
    return (
      <div className="leading-tight">
        <span className="inline-block rounded bg-blue-50 px-1.5 py-0.5 text-xs font-medium text-blue-800">Request</span>
        {s.urgency === 'URGENT' && <span className="ml-1 text-xs font-semibold text-red-600">Urgent</span>}
        <div className="mt-0.5 text-xs">
          {s.item_request_id ? <Link to={`/item-requests/${s.item_request_id}`} className="font-mono text-primary hover:underline">{s.item_request_number}</Link> : s.item_request_number}
          {s.requester && <span className="text-muted-foreground"> · {s.requester}</span>}
        </div>
        {(s.divisi || s.needed_date) && <div className="text-xs text-muted-foreground">{[s.divisi, s.needed_date ? `perlu ${new Date(s.needed_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })}` : null].filter(Boolean).join(' · ')}</div>}
      </div>
    )
  }
  return (
    <div className="leading-tight">
      <span className="inline-block rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800">Safety stock</span>
      <div className="mt-0.5 text-xs text-muted-foreground">Restock <span className="font-mono">{s.draft_number}</span>{s.created_by ? ` · ${s.created_by}` : ''}</div>
    </div>
  )
}

function DraftLinesList() {
  const [source, setSource] = useState('')
  const [search, setSearch] = useState('')
  const { data, isLoading } = useDraftLines({ source: source || undefined, search: search.trim() || undefined })
  const act = useDraftLineActions()
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [qty, setQty] = useState<Record<number, string>>({})
  const [open, setOpen] = useState(false)
  const [noPpb, setNoPpb] = useState('')
  const [tglPpb, setTglPpb] = useState(today())
  const [divisi, setDivisi] = useState('')
  const [keterangan, setKeterangan] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const rows = data?.data ?? []
  const by = data?.meta.by_source ?? {}
  const allOn = rows.length > 0 && rows.every((r) => picked.has(r.id))
  const toggle = (id: number) => setPicked((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n })
  const saveQty = (l: DraftLineRow) => {
    const v = Number((qty[l.id] ?? '').replace(',', '.'))
    if (qty[l.id] === undefined || v === l.qty) return
    if (!(v > 0)) { setErr('Qty harus lebih dari 0.'); setQty((q) => { const n = { ...q }; delete n[l.id]; return n }); return }
    act.update.mutate({ id: l.id, qty: v }, { onError: (e) => setErr(apiErrorMessage(e)) })
  }
  const submit = () => {
    setErr(null)
    if (!noPpb.trim()) return invalidTo(setErr, 'Isi No PPB.')
    act.submit.mutate({ line_ids: [...picked], no_ppb: noPpb.trim(), tgl_ppb: tglPpb, divisi: divisi.trim() || null, keterangan: keterangan.trim() || null }, {
      onSuccess: () => { setOpen(false); setPicked(new Set()); setNoPpb('') },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="inline-flex max-w-full overflow-x-auto rounded-lg border bg-card p-0.5">
          {([['', 'Semua', (by.REQUEST ?? 0) + (by.RESTOCK ?? 0)], ['REQUEST', 'Dari request', by.REQUEST ?? 0], ['RESTOCK', 'Safety stock', by.RESTOCK ?? 0]] as const).map(([k, label, n]) => (
            <button key={k || 'all'} type="button" onClick={() => { setSource(k); setPicked(new Set()) }}
              className={source === k ? 'rounded-md bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary' : 'rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted'}>
              {label}{!source || source === k ? ` (${n})` : ''}
            </button>
          ))}
        </div>
        <Input className="sm:w-72" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari barang / no. request / peminta" aria-label="Cari" />
      </div>

      {/* phone: one card per item */}
      <Card className="overflow-hidden sm:hidden">
        <ul className="divide-y">
          {rows.map((l) => (
            <li key={l.id} className={picked.has(l.id) ? 'space-y-2 bg-primary/5 p-3 text-sm' : 'space-y-2 p-3 text-sm'}>
              <label className="flex items-start gap-3">
                <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-primary" checked={picked.has(l.id)} onChange={() => toggle(l.id)} aria-label={`Pilih ${l.deskripsi_barang}`} />
                <span className="min-w-0">
                  <span className="block break-words font-medium">{l.deskripsi_barang}</span>
                  <span className="block text-xs text-muted-foreground">{l.kode_barang ?? 'Barang baru'} · stok {fmtQty(l.available)}{l.same_item_lines > 1 ? ` · ada ${l.same_item_lines}× di daftar` : ''}</span>
                </span>
              </label>
              <div className="flex items-end justify-between gap-3 pl-7">
                <SourceCell l={l} />
                <div className="flex shrink-0 items-center gap-1">
                  <Input className="h-8 w-16 text-right tabular-nums" inputMode="decimal" aria-label={`Qty ${l.deskripsi_barang}`}
                    value={qty[l.id] ?? String(l.qty)} onChange={(e) => setQty((q) => ({ ...q, [l.id]: e.target.value }))} onBlur={() => saveQty(l)} />
                  <span className="text-xs text-muted-foreground">{l.satuan ?? ''}</span>
                  <button type="button" className="rounded p-1.5 text-muted-foreground hover:text-destructive" aria-label={`Hapus ${l.deskripsi_barang}`}
                    onClick={async () => { if (await confirmDialog({ message: `Hapus "${l.deskripsi_barang}" dari Draft PPB? Barang ini tidak jadi dibeli.`, danger: true })) act.remove.mutate(l.id) }}>
                    <Trash2 className="size-4" />
                  </button>
                </div>
              </div>
            </li>
          ))}
          {!isLoading && rows.length === 0 && <li className="px-3 py-10 text-center text-muted-foreground">Tidak ada barang yang perlu di-PPB.</li>}
        </ul>
      </Card>

      <Card className="hidden overflow-hidden sm:block">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="whitespace-nowrap border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="w-10 px-3 py-2.5"><input type="checkbox" className="size-4 accent-primary" aria-label="Pilih semua" checked={allOn}
                  onChange={() => setPicked(allOn ? new Set() : new Set(rows.map((r) => r.id)))} /></th>
                <th className="min-w-[14rem] px-3 py-2.5 font-medium">Barang</th>
                <th className="px-3 py-2.5 text-right font-medium">Stok</th>
                <th className="w-28 px-3 py-2.5 text-right font-medium">Qty</th>
                <th className="min-w-[12rem] px-3 py-2.5 font-medium">Sumber</th>
                <th className="w-10 px-3 py-2.5"><span className="sr-only">Hapus</span></th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((l) => (
                <tr key={l.id} className={picked.has(l.id) ? 'bg-primary/5 align-top' : 'align-top'}>
                  <td className="px-3 py-3"><input type="checkbox" className="size-4 accent-primary" checked={picked.has(l.id)} onChange={() => toggle(l.id)} aria-label={`Pilih ${l.deskripsi_barang}`} /></td>
                  <td className="px-3 py-3">
                    <div className="font-medium">{l.deskripsi_barang}</div>
                    <div className="text-xs text-muted-foreground">
                      {[l.kode_barang ?? 'Barang baru (di luar master)', l.catatan].filter(Boolean).join(' · ')}
                      {l.same_item_lines > 1 && <span className="ml-1 font-medium text-amber-700">· ada {l.same_item_lines}× di daftar</span>}
                    </div>
                    {l.basis && <div className="text-xs text-muted-foreground">{l.basis}</div>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums text-muted-foreground">{fmtQty(l.available)}</td>
                  <td className="px-3 py-2">
                    <div className="flex items-center justify-end gap-1">
                      <Input className="h-8 w-20 text-right tabular-nums" inputMode="decimal" aria-label={`Qty ${l.deskripsi_barang}`}
                        value={qty[l.id] ?? String(l.qty)} onChange={(e) => setQty((q) => ({ ...q, [l.id]: e.target.value }))} onBlur={() => saveQty(l)} />
                      <span className="w-10 text-xs text-muted-foreground">{l.satuan ?? ''}</span>
                    </div>
                  </td>
                  <td className="px-3 py-3"><SourceCell l={l} /></td>
                  <td className="px-2 py-2">
                    <button type="button" className="rounded p-2 text-muted-foreground hover:bg-muted hover:text-destructive" aria-label={`Hapus ${l.deskripsi_barang}`}
                      onClick={async () => { if (await confirmDialog({ message: `Hapus "${l.deskripsi_barang}" dari Draft PPB? Barang ini tidak jadi dibeli.`, danger: true })) act.remove.mutate(l.id) }}>
                      <Trash2 className="size-4" />
                    </button>
                  </td>
                </tr>
              ))}
              {!isLoading && rows.length === 0 && <tr><td colSpan={6} className="px-3 py-12 text-center text-muted-foreground">Tidak ada barang yang perlu di-PPB.</td></tr>}
              {isLoading && <tr><td colSpan={6} className="px-3 py-12 text-center text-muted-foreground"><Loader2 className="mx-auto size-5 animate-spin" /></td></tr>}
            </tbody>
          </table>
        </div>
      </Card>

      {err && !open && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
      <div className="sticky bottom-3 flex flex-col gap-2 rounded-lg border bg-card p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <span className="text-sm text-muted-foreground">{picked.size ? `${picked.size} barang dipilih` : 'Centang barang yang mau diajukan dalam satu PPB.'}</span>
        <Button disabled={picked.size === 0} onClick={() => { setErr(null); setOpen(true) }}><Send className="size-4" /> Ajukan jadi PPB</Button>
      </div>

      <Modal open={open} onClose={() => setOpen(false)} title="Ajukan jadi PPB" description={`${picked.size} barang menjadi satu dokumen PPB, lalu menunggu approval Manager → Direktur.`}
        footer={<>
          <Button variant="outline" size="sm" onClick={() => setOpen(false)}>Batal</Button>
          <Button size="sm" disabled={act.submit.isPending} onClick={submit}>{act.submit.isPending && <Loader2 className="size-4 animate-spin" />} Ajukan</Button>
        </>}>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1"><Label htmlFor="sl-no">No PPB *</Label><Input id="sl-no" className="font-mono" autoFocus value={noPpb} maxLength={60} onChange={(e) => setNoPpb(e.target.value)} placeholder="PPB/NA/26/X/090" /></div>
          <div className="space-y-1"><Label htmlFor="sl-tgl">Tanggal PPB</Label><Input id="sl-tgl" type="date" value={tglPpb} onChange={(e) => setTglPpb(e.target.value)} /></div>
          <div className="space-y-1"><Label htmlFor="sl-div">Divisi</Label><Input id="sl-div" value={divisi} maxLength={30} onChange={(e) => setDivisi(e.target.value)} placeholder="kosong = ikut sumbernya" /></div>
          <div className="space-y-1"><Label htmlFor="sl-ket">Keterangan</Label><Input id="sl-ket" value={keterangan} maxLength={2000} onChange={(e) => setKeterangan(e.target.value)} /></div>
        </div>
        {err && <p className="mt-3 text-sm text-destructive">{err}</p>}
      </Modal>
    </div>
  )
}

/** The draft documents themselves (each Restock / each request's shortage), and what became of them. */
function DraftDocsList() {
  const navigate = useNavigate()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = usePpbDrafts({ status: status || undefined, page })
  const { rows, footer } = useInfiniteRows(data, page, setPage)
  const columns: Column<(typeof rows)[number]>[] = [
    { key: 'number', header: 'No. Draft', cell: (r) => <span className="font-mono text-xs font-semibold text-primary">{r.number}</span> },
    { key: 'lines', header: 'Barang', cell: (r) => `${r.lines_count} barang` },
    { key: 'src', header: 'Asal', cell: (r) => r.item_request_number ? <span className="whitespace-nowrap">Request <span className="font-mono text-xs">{r.item_request_number}</span></span> : <span className="text-muted-foreground">Safety stock</span> },
    { key: 'by', header: 'Dibuat', cell: (r) => <span className="whitespace-nowrap text-muted-foreground">{fmtDateTime(r.created_at)}{r.created_by_name ? ` · ${r.created_by_name}` : ''}</span> },
    { key: 'ppb', header: 'No PPB', cell: (r) => r.no_ppb ? <span className="font-mono text-xs">{r.no_ppb}</span> : <span className="text-muted-foreground">—</span> },
    { key: 'status', header: 'Status', cell: (r) => <Badge variant={DRAFT_STATUS[r.status].variant}>{DRAFT_STATUS[r.status].label}</Badge> },
  ]
  return (
    <div className="space-y-3">
      <div className="inline-flex rounded-lg border bg-card p-0.5">
        {([['', 'Semua'], ['DRAFT', 'Masih ada barang'], ['DIAJUKAN', 'Sudah jadi PPB'], ['DIBATALKAN', 'Dibatalkan']] as const).map(([k, label]) => (
          <button key={k || 'all'} type="button" onClick={() => { setStatus(k); setPage(1) }}
            className={status === k ? 'rounded-md bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary' : 'rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted'}>
            {label}
          </button>
        ))}
      </div>
      <DataTable columns={columns} rows={rows} footer={footer} rowKey={(r) => r.id} isLoading={isLoading}
        onRowClick={(r) => navigate(`/ppb/drafts/${r.id}`)} emptyText="Belum ada draft." />
    </div>
  )
}

type EditLine = PpbDraftLine & { key: string }

/** Review / edit one Draft PPB, then submit it as a PPB (same as Tambah PPB, then PPB approval). */
export function PpbDraftPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { data: d, isLoading } = usePpbDraft(Number(id))
  const act = useDraftActions(Number(id))
  const [lines, setLines] = useState<EditLine[]>([])
  const [divisi, setDivisi] = useState('')
  const [tglDiperlukan, setTglDiperlukan] = useState('')
  const [peminta, setPeminta] = useState('')
  const [keterangan, setKeterangan] = useState('')
  const [dirty, setDirty] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [noPpb, setNoPpb] = useState('')
  const [tglPpb, setTglPpb] = useState(today())

  useEffect(() => {
    if (!d) return
    setLines(d.lines.map((l) => ({ ...l, key: String(l.id) })))
    setDivisi(d.divisi ?? ''); setTglDiperlukan(d.tgl_diperlukan ?? ''); setPeminta(d.peminta ?? ''); setKeterangan(d.keterangan ?? '')
    setDirty(false)
  }, [d])

  if (isLoading || !d) return <div className="space-y-4"><BackLink to="/ppb/drafts" label="Draft PPB" /><DetailFallback loading={isLoading} /></div>
  const editable = d.status === 'DRAFT'
  const change = <K extends keyof EditLine>(key: string, k: K, v: EditLine[K]) => { setLines((ls) => ls.map((l) => (l.key === key ? { ...l, [k]: v } : l))); setDirty(true) }
  const addItem = (it: ItemLookupResult) => {
    setLines((ls) => [...ls, { key: `n${Date.now()}`, item_id: it.id, kode_barang: it.code, deskripsi_barang: it.description, qty: 1, satuan: it.unit ?? null, catatan: null, available: it.available }])
    setDirty(true)
  }
  const addFree = () => { setLines((ls) => [...ls, { key: `n${Date.now()}`, item_id: null, kode_barang: null, deskripsi_barang: '', qty: 1, satuan: null, catatan: null }]); setDirty(true) }
  const payload = () => ({
    divisi: divisi.trim() || null, tgl_diperlukan: tglDiperlukan || null, peminta: peminta.trim() || null, keterangan: keterangan.trim() || null,
    lines: lines.map((l) => ({ id: l.id, item_id: l.item_id, kode_barang: l.kode_barang, deskripsi_barang: l.deskripsi_barang.trim(), qty: Number(l.qty), satuan: l.satuan, catatan: l.catatan?.trim() || null })),
  })
  const check = () => {
    if (lines.length === 0) return 'Draft harus berisi minimal satu barang.'
    const bad = lines.find((l) => !l.deskripsi_barang.trim() || !(Number(l.qty) > 0))
    return bad ? 'Setiap baris perlu nama barang dan qty lebih dari 0.' : null
  }
  const save = () => {
    setErr(null)
    const e = check(); if (e) return invalidTo(setErr, e)
    act.save.mutate(payload(), { onSuccess: () => setDirty(false), onError: (x) => setErr(apiErrorMessage(x)) })
  }
  const submit = async () => {
    setErr(null)
    const e = check(); if (e) return invalidTo(setErr, e)
    if (!noPpb.trim()) return invalidTo(setErr, 'Isi No PPB.')
    try {
      await act.save.mutateAsync(payload())
      await act.submit.mutateAsync({ no_ppb: noPpb.trim(), tgl_ppb: tglPpb })
      setSubmitting(false)
    } catch (x) { setErr(apiErrorMessage(x)) }
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <BackLink to="/ppb/drafts" label="Draft PPB" />
      <PageHeader title={`Draft ${d.number}`}
        subtitle={`${d.lines_count} barang · ${d.item_request_number ? `dari request ${d.item_request_number}` : 'dari Restock'} · dibuat ${fmtDateTime(d.created_at)}${d.created_by_name ? ` oleh ${d.created_by_name}` : ''}`}
        actions={<>
          <Badge variant={DRAFT_STATUS[d.status].variant}>{DRAFT_STATUS[d.status].label}</Badge>
          {editable && (
            <Button size="sm" variant="outline" className="text-destructive hover:text-destructive" disabled={act.cancel.isPending}
              onClick={async () => { if (await confirmDialog({ message: `Batalkan draft ${d.number}?`, danger: true })) act.cancel.mutate(undefined, { onSuccess: () => navigate('/ppb/drafts') }) }}>
              <Ban className="size-4" /> Batalkan draft
            </Button>
          )}
        </>} />

      {d.status === 'DIAJUKAN' && (
        <Card className="flex flex-wrap items-center justify-between gap-2 border-emerald-200 bg-emerald-50/60 p-4 text-sm">
          <span>Sudah diajukan jadi PPB <b className="font-mono">{d.no_ppb}</b> · {fmtDateTime(d.submitted_at)}{d.submitted_by_name ? ` oleh ${d.submitted_by_name}` : ''}. Menunggu approval PPB.</span>
          {d.ppb_id && <Link to={`/ppb/${d.ppb_id}`} className="font-medium text-primary hover:underline">Buka PPB</Link>}
        </Card>
      )}

      <Card className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-1"><Label htmlFor="dr-divisi">Divisi</Label><Input id="dr-divisi" value={divisi} maxLength={30} disabled={!editable} onChange={(e) => { setDivisi(e.target.value); setDirty(true) }} placeholder="mis. GUDANG" /></div>
        <div className="space-y-1"><Label htmlFor="dr-tgl">Tgl diperlukan</Label><Input id="dr-tgl" type="date" value={tglDiperlukan} disabled={!editable} onChange={(e) => { setTglDiperlukan(e.target.value); setDirty(true) }} /></div>
        <div className="space-y-1"><Label htmlFor="dr-peminta">Peminta</Label><Input id="dr-peminta" value={peminta} maxLength={150} disabled={!editable} onChange={(e) => { setPeminta(e.target.value); setDirty(true) }} /></div>
        <div className="space-y-1"><Label htmlFor="dr-ket">Keterangan</Label><Input id="dr-ket" value={keterangan} maxLength={2000} disabled={!editable} onChange={(e) => { setKeterangan(e.target.value); setDirty(true) }} placeholder={`Restock dari ${d.number}`} /></div>
      </Card>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted text-left">
              <tr>
                <th className="w-10 px-3 py-2.5">#</th>
                <th className="px-3 py-2.5">Barang</th>
                <th className="px-3 py-2.5 text-right">Tersedia</th>
                <th className="w-28 px-3 py-2.5 text-right">Qty</th>
                <th className="w-24 px-3 py-2.5">Satuan</th>
                <th className="px-3 py-2.5">Catatan</th>
                {editable && <th className="w-10 px-3 py-2.5"><span className="sr-only">Hapus</span></th>}
              </tr>
            </thead>
            <tbody className="divide-y">
              {lines.map((l, i) => (
                <tr key={l.key} className="align-top">
                  <td className="px-3 py-2.5 tabular-nums text-muted-foreground">{i + 1}</td>
                  <td className="px-3 py-2">
                    {l.item_id ? (
                      <><div className="font-medium">{l.deskripsi_barang}</div><div className="font-mono text-xs text-muted-foreground">{l.kode_barang}</div></>
                    ) : (
                      <Input value={l.deskripsi_barang} disabled={!editable} maxLength={400} placeholder="Nama barang (belum ada di Master Produk)" aria-label={`Nama barang baris ${i + 1}`}
                        onChange={(e) => change(l.key, 'deskripsi_barang', e.target.value)} />
                    )}
                    {l.basis && <div className="mt-0.5 text-xs text-muted-foreground">{l.basis}</div>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-muted-foreground">{fmtQty(l.available)}</td>
                  <td className="px-3 py-2">
                    <Input type="number" inputMode="decimal" min={0} step="any" className="text-right" value={l.qty} disabled={!editable} aria-label={`Qty baris ${i + 1}`}
                      onChange={(e) => change(l.key, 'qty', e.target.value === '' ? 0 : Number(e.target.value))} />
                  </td>
                  <td className="px-3 py-2">
                    <Input value={l.satuan ?? ''} disabled={!editable || !!l.item_id} maxLength={30} aria-label={`Satuan baris ${i + 1}`} onChange={(e) => change(l.key, 'satuan', e.target.value || null)} />
                  </td>
                  <td className="px-3 py-2">
                    <Input value={l.catatan ?? ''} disabled={!editable} maxLength={255} placeholder="opsional" aria-label={`Catatan baris ${i + 1}`} onChange={(e) => change(l.key, 'catatan', e.target.value || null)} />
                  </td>
                  {editable && (
                    <td className="px-2 py-2">
                      <button type="button" className="rounded p-2 text-muted-foreground hover:bg-muted hover:text-destructive" aria-label={`Hapus baris ${i + 1}`}
                        onClick={() => { setLines((ls) => ls.filter((x) => x.key !== l.key)); setDirty(true) }}><Trash2 className="size-4" /></button>
                    </td>
                  )}
                </tr>
              ))}
              {lines.length === 0 && <tr><td colSpan={7} className="px-3 py-10 text-center text-muted-foreground">Belum ada barang.</td></tr>}
            </tbody>
          </table>
        </div>
        {editable && (
          <div className="flex flex-col gap-2 border-t bg-muted/30 p-3 sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1"><ItemPicker onPick={addItem} /></div>
            <Button variant="outline" size="sm" onClick={addFree}><Plus className="size-4" /> Barang di luar master</Button>
          </div>
        )}
      </Card>

      {err && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{err}</p>}
      {editable && (
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" disabled={act.save.isPending || !dirty} onClick={save}><Save className="size-4" /> {dirty ? 'Simpan draft' : 'Tersimpan'}</Button>
          <Button onClick={() => { setErr(null); setSubmitting(true) }}><Send className="size-4" /> Ajukan jadi PPB</Button>
        </div>
      )}

      <Modal open={submitting} onClose={() => setSubmitting(false)} title="Ajukan jadi PPB" description={`${lines.length} barang dari ${d.number} menjadi satu dokumen PPB, lalu menunggu approval PPB.`}
        footer={<>
          <Button variant="outline" size="sm" onClick={() => setSubmitting(false)}>Batal</Button>
          <Button size="sm" disabled={act.submit.isPending || act.save.isPending} onClick={submit}>
            {(act.submit.isPending || act.save.isPending) && <Loader2 className="size-4 animate-spin" />} Ajukan
          </Button>
        </>}>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1"><Label htmlFor="sb-no">No PPB *</Label><Input id="sb-no" className="font-mono" autoFocus value={noPpb} maxLength={60} onChange={(e) => setNoPpb(e.target.value)} placeholder="PPB/NA/26/X/090" /></div>
          <div className="space-y-1"><Label htmlFor="sb-tgl">Tanggal PPB</Label><Input id="sb-tgl" type="date" value={tglPpb} onChange={(e) => setTglPpb(e.target.value)} /></div>
        </div>
        {err && <p className="mt-3 text-sm text-destructive">{err}</p>}
      </Modal>
    </div>
  )
}
