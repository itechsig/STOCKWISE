import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Check, ChevronDown, Download, FileText, ImageIcon, Loader2, Paperclip, Plus, Printer, Trash2, Upload } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import {
  stockCardPdf, useStockCard, useStockCardMutations, useStockCardProjects,
  type StockCard, type StockCardEntryInput, type StockCardHeader, type StockCardRow,
} from '@/features/stock-card/api'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { balanceBefore, linesFromSisa, parseQty, round4 } from '@/features/stock-card/balance'
import { deleteEntryFile, openEntryFile, routePaths, uploadEntryFile, useStockCardScope } from '@/features/stock-card/scope'
import { ActionMenu, MobileBottomBar } from '@/components/stock-card/ActionMenu'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'
import { cn } from '@/lib/utils'
import { BackLink } from '@/components/BackLink'
import { confirmDialog } from '@/components/ui/confirm'

const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 })
const today = () => new Date().toLocaleDateString('sv-SE') // YYYY-MM-DD, local
const SAVE_DELAY = 900

type SaveState = 'idle' | 'dirty' | 'saving' | 'saved' | 'error'

function SaveBadge({ state, error, hint }: { state: SaveState; error?: string | null; hint?: string | null }) {
  if (state === 'saving') return <span className="inline-flex items-center gap-1 text-xs text-muted-foreground"><Loader2 className="size-3 animate-spin" /> Menyimpan…</span>
  if (state === 'saved') return <span className="inline-flex items-center gap-1 text-xs text-emerald-600"><Check className="size-3" /> Tersimpan</span>
  if (state === 'error') return <span className="text-xs text-destructive">{error ?? 'Gagal menyimpan'}</span>
  if (hint) return <span className="text-xs text-amber-700">{hint}</span>
  return null
}

// ---------------------------------------------------------------- attachments (camera on phones)

function readAsDataUrl(f: File): Promise<string> {
  return new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(String(r.result)); r.onerror = reject; r.readAsDataURL(f) })
}

/**
 * Lampiran of one line: a single small upload icon — on a phone it offers camera,
 * gallery or files, several at once, as often as needed — and a count that opens the list.
 */
function Attachments({ row, canEdit, canDelete, onChanged }: { row: StockCardRow; canEdit: boolean; canDelete: boolean; onChanged: () => void }) {
  const scope = useStockCardScope()
  const picker = useRef<HTMLInputElement>(null)
  const [progress, setProgress] = useState<string | null>(null)
  const [open, setOpen] = useState(false)
  const files = row.files

  async function upload(list: File[]) {
    let failed = 0
    for (const [i, f] of list.entries()) {
      setProgress(`${i + 1}/${list.length}`)
      try {
        const data = f.type === 'application/pdf' ? await readAsDataUrl(f) : await photoToDataUrl(f)
        await uploadEntryFile(scope, row.id, data, f.name.replace(/\.[^.]+$/, ''))
      } catch (e) {
        failed++
        if (list.length === 1) window.alert(apiErrorMessage(e))
      }
    }
    setProgress(null)
    if (failed && list.length > 1) window.alert(`${failed} dari ${list.length} file gagal diupload (hanya foto atau PDF).`)
    onChanged()
  }

  async function remove(id: number) {
    if (!(await confirmDialog('Hapus lampiran ini?'))) return
    await deleteEntryFile(scope, id)
    onChanged()
  }

  const iconBtn = 'inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-md border bg-card px-1.5 text-xs text-muted-foreground hover:border-primary/50 hover:text-primary md:h-7'
  return (
    <div className="flex items-center gap-1">
      {canEdit && (
        progress
          ? <span className={cn(iconBtn, 'text-primary')}><Loader2 className="size-3.5 animate-spin" />{progress}</span>
          : <button type="button" onClick={() => picker.current?.click()} className={iconBtn} title="Upload foto / file (bisa beberapa sekaligus)" aria-label="Upload lampiran"><Upload className="size-4" /></button>
      )}
      {files.length > 0 && (
        <button type="button" onClick={() => setOpen(true)} className={cn(iconBtn, 'border-primary/40 font-semibold text-primary')} title="Lihat lampiran" aria-label={`${files.length} lampiran`}>
          <Paperclip className="size-3.5" />{files.length}
        </button>
      )}
      <input ref={picker} type="file" multiple accept="image/*,application/pdf" className="hidden"
        onChange={(e) => { const list = Array.from(e.target.files ?? []); e.target.value = ''; if (list.length) void upload(list) }} />

      {open && (
        <Modal open onClose={() => setOpen(false)} title={`Lampiran (${files.length})`} description={[row.date.split('-').reverse().join('/'), row.doc_no, row.description].filter(Boolean).join(' · ')}
          footer={canEdit ? (
            <Button className="h-11 w-full sm:w-auto" disabled={!!progress} onClick={() => picker.current?.click()}>
              {progress ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />} {progress ? `Mengupload ${progress}` : 'Tambah foto / file'}
            </Button>
          ) : undefined}>
          {files.length === 0 ? <p className="py-4 text-center text-sm text-muted-foreground">Belum ada lampiran.</p> : (
            <ul className="divide-y">
              {files.map((f, i) => (
                <li key={f.id} className="flex items-center gap-3 py-2">
                  {f.mime === 'application/pdf' ? <FileText className="size-5 shrink-0 text-red-600" /> : <ImageIcon className="size-5 shrink-0 text-primary" />}
                  <span className="min-w-0 flex-1 truncate text-sm">{f.file_name || `Lampiran ${i + 1}`}</span>
                  <Button variant="outline" size="sm" onClick={() => void openEntryFile(scope, f.id)}>Lihat</Button>
                  {canDelete && (
                    <button type="button" className="rounded p-2 text-muted-foreground hover:text-destructive" aria-label="Hapus lampiran" onClick={() => void remove(f.id)}>
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Modal>
      )}
    </div>
  )
}

// ---------------------------------------------------------------- one line, edited in place with auto-save

type EntryForm = { entry_date: string; doc_no: string; description: string; qty_in: string; qty_out: string }
const fromRow = (r: StockCardRow): EntryForm => ({
  entry_date: r.date, doc_no: r.doc_no ?? '', description: r.description ?? '',
  qty_in: r.masuk ? String(r.masuk) : '', qty_out: r.keluar ? String(r.keluar) : '',
})
const toInput = (f: EntryForm): StockCardEntryInput => ({
  entry_date: f.entry_date,
  doc_no: f.doc_no.trim() || null,
  description: f.description.trim() || null,
  qty_in: f.qty_in === '' ? null : Number(f.qty_in.replace(',', '.')),
  qty_out: f.qty_out === '' ? null : Number(f.qty_out.replace(',', '.')),
})
/** Why a line can't be saved yet (null = ok). */
function invalid(f: EntryForm): string | null {
  if (!f.entry_date) return 'Isi tanggal'
  const i = toInput(f)
  if (!((i.qty_in ?? 0) > 0) && !((i.qty_out ?? 0) > 0)) return 'Isi Masuk atau Keluar'
  return null
}
const same = (a: EntryForm, b: EntryForm) => JSON.stringify(a) === JSON.stringify(b)

/**
 * Edit state of a line: typing marks it dirty, it saves itself SAVE_DELAY after the
 * last key (and when leaving the field). A new line (no id) is created when it is
 * valid and the user leaves it, so half-typed lines are not saved.
 */
function useEntryEditor(row: StockCardRow | null, save: (f: EntryForm) => Promise<unknown>, onCreated?: (result: unknown) => void) {
  const [form, setForm] = useState<EntryForm>(() => (row ? fromRow(row) : { entry_date: today(), doc_no: '', description: '', qty_in: '', qty_out: '' }))
  const [state, setState] = useState<SaveState>('idle')
  const [error, setError] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const latest = useRef(form)
  latest.current = form

  // the server's version (after any save) replaces the form unless the user is typing
  useEffect(() => {
    if (row && state !== 'dirty' && state !== 'saving') setForm(fromRow(row))
  }, [row, state])

  async function flush() {
    clearTimeout(timer.current)
    const f = latest.current
    if (row && same(f, fromRow(row))) { setState('idle'); return }
    if (invalid(f)) { setState('dirty'); return }
    setState('saving')
    setError(null)
    try {
      const result = await save(f)
      if (!row) {
        setForm((prev) => ({ entry_date: prev.entry_date, doc_no: '', description: '', qty_in: '', qty_out: '' }))
        onCreated?.(result)
      } else if (!same(latest.current, f)) {
        // typed on while it was saving: keep the newer text and save it too
        setState('dirty')
        timer.current = setTimeout(() => void flush(), SAVE_DELAY)
        return
      }
      setState('saved')
      setTimeout(() => setState((s) => (s === 'saved' ? 'idle' : s)), 2000)
    } catch (e) {
      setState('error')
      setError(apiErrorMessage(e))
    }
  }

  function change(k: keyof EntryForm, v: string) {
    setForm((f) => ({ ...f, [k]: v }))
    setState('dirty')
    clearTimeout(timer.current)
    if (row) timer.current = setTimeout(() => void flush(), SAVE_DELAY) // existing line: save while typing pauses
  }

  useEffect(() => () => clearTimeout(timer.current), [])
  const hint = state === 'dirty' ? invalid(form) : null
  return { form, change, flush, state, error, hint }
}

const cellInput = 'h-9 w-full rounded-md border border-transparent bg-transparent px-2 text-sm outline-none hover:border-border focus:border-primary focus:bg-card focus:ring-2 focus:ring-primary/20 disabled:cursor-default disabled:hover:border-transparent'

/** Desktop: the line as a table row, every cell editable in place. */
function EntryTableRow({ row, rows, cardId, canEdit, canDelete, onFiles }: {
  row: StockCardRow | null; rows: StockCardRow[]; cardId: number; canEdit: boolean; canDelete: boolean; onFiles: () => void
}) {
  const m = useStockCardMutations(cardId)
  const ed = useEntryEditor(row, (f) => (row ? m.updateEntry.mutateAsync({ entryId: row.id, ...toInput(f) }) : m.addEntry.mutateAsync(toInput(f))))
  // Sisa can be typed too: it becomes the Masuk / Keluar from the balance before this line
  // (only Masuk / Keluar are stored, so the Sisa of every line stays computed and valid)
  const before = balanceBefore(rows, ed.form.entry_date || row?.date || '', row?.id)
  const moved = parseQty(ed.form.qty_in) - parseQty(ed.form.qty_out)
  const computed = Number.isNaN(moved) ? null : round4(before + moved)
  const [sisaText, setSisaText] = useState<string | null>(null) // while typing in Sisa
  const typeSisa = (v: string) => {
    setSisaText(v)
    const n = parseQty(v)
    if (v.trim() === '' || Number.isNaN(n)) return
    const q = linesFromSisa(before, n)
    ed.change('qty_in', q.qty_in)
    ed.change('qty_out', q.qty_out)
  }
  const blank = !row && ed.form.qty_in === '' && ed.form.qty_out === ''
  const sisaShown = sisaText ?? (blank || computed == null ? '' : String(computed))
  const box = useRef<HTMLTableRowElement>(null)
  const leaveRow = (e: React.FocusEvent) => { if (!box.current?.contains(e.relatedTarget as Node)) void ed.flush() }
  const enter = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    void ed.flush()
    const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-sc-input]'))
    inputs[inputs.indexOf(e.currentTarget) + 1]?.focus()
  }
  const input = (k: keyof EntryForm, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input data-sc-input disabled={!canEdit} value={ed.form[k]} onChange={(e) => ed.change(k, e.target.value)} onKeyDown={enter} className={cellInput} {...props} />
  )
  const isNew = !row

  return (
    <tr ref={box} onBlur={leaveRow} id={isNew ? 'sc-new-line' : undefined} className={cn('group align-top', isNew ? 'bg-primary/5' : 'bg-card')}>
      <td className="overflow-hidden border px-1 py-1">{input('entry_date', { type: 'date', 'aria-label': 'Tanggal', className: cn(cellInput, 'min-w-0 px-1 text-[13px] md:text-sm') })}</td>
      <td className="overflow-hidden border px-1 py-1">{input('doc_no', { placeholder: isNew ? 'No. bukti' : '', 'aria-label': 'No. bukti', className: cn(cellInput, 'min-w-0 truncate max-md:text-base') })}</td>
      <td className="border px-1 py-1">
        {input('description', { placeholder: isNew ? 'Ketik transaksi baru di sini…' : '', 'aria-label': 'Keterangan', className: cn(cellInput, 'max-md:text-base') })}
        <div className="px-2"><SaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </td>
      <td className="border px-1 py-1">{input('qty_in', { inputMode: 'decimal', 'aria-label': 'Masuk', className: cn(cellInput, 'min-w-0 text-right tabular-nums max-md:text-base') })}</td>
      <td className="border px-1 py-1">{input('qty_out', { inputMode: 'decimal', 'aria-label': 'Keluar', className: cn(cellInput, 'min-w-0 text-right tabular-nums max-md:text-base') })}</td>
      <td className="border px-1 py-1">
        {canEdit ? (
          <input data-sc-input value={sisaShown} inputMode="decimal" aria-label="Sisa" placeholder={blank ? nf.format(before) : ''}
            title={`Ketik sisa: Masuk / Keluar dihitung dari sisa sebelumnya (${nf.format(before)})`}
            onFocus={() => setSisaText(sisaShown)} onBlur={() => setSisaText(null)}
            onChange={(e) => typeSisa(e.target.value)} onKeyDown={enter}
            className={cn(cellInput, 'min-w-0 text-right font-semibold tabular-nums placeholder:font-normal max-md:text-base', computed != null && computed < 0 && !blank && 'text-destructive')} />
        ) : (
          <div className={cn('px-1 py-1 text-right font-semibold tabular-nums', row && row.sisa < 0 && 'text-destructive')}>{row ? nf.format(row.sisa) : ''}</div>
        )}
      </td>
      <td className="border px-2 py-1">
        {row && <Attachments row={row} canEdit={canEdit} canDelete={canDelete} onChanged={onFiles} />}
      </td>
      {canDelete && (
        <td className="border px-1 py-1 text-center">
          {row && (
            <button type="button" className="rounded p-2 text-muted-foreground hover:bg-muted hover:text-destructive" aria-label="Hapus baris"
              onClick={async () => { if ((await confirmDialog('Hapus transaksi ini?'))) m.removeEntry.mutate(row.id) }}>
              <Trash2 className="size-4" />
            </button>
          )}
        </td>
      )}
    </tr>
  )
}

// ---------------------------------------------------------------- header, edited in place with auto-save

function HeaderInline({ card, canEdit }: { card: StockCard; canEdit: boolean }) {
  const m = useStockCardMutations(card.id)
  const shared = useStockCardScope().kind === 'share'
  const projects = useStockCardProjects('', !shared)
  const initial = (): StockCardHeader => ({
    project_id: card.project?.id ?? null, card_number: card.card_number, location_warehouse: card.location_warehouse,
    location_rack: card.location_rack, person_in_charge: card.person_in_charge, director_name: card.director_name,
  })
  const [form, setForm] = useState<StockCardHeader>(initial)
  const [state, setState] = useState<SaveState>('idle')
  const [error, setError] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const latest = useRef(form)
  latest.current = form

  async function save() {
    clearTimeout(timer.current)
    const f = latest.current
    if (!f.card_number.trim() || !f.project_id) { setState('dirty'); return }
    setState('saving')
    try {
      await m.update.mutateAsync({ ...f, card_number: f.card_number.trim() })
      if (JSON.stringify(latest.current) !== JSON.stringify(f)) { setState('dirty'); timer.current = setTimeout(() => void save(), SAVE_DELAY); return }
      setState('saved')
      setError(null)
      setTimeout(() => setState((s) => (s === 'saved' ? 'idle' : s)), 2000)
    } catch (e) {
      setState('error')
      setError(apiErrorMessage(e))
    }
  }
  function change<K extends keyof StockCardHeader>(k: K, v: StockCardHeader[K], now = false) {
    setForm((f) => ({ ...f, [k]: v }))
    setState('dirty')
    clearTimeout(timer.current)
    timer.current = setTimeout(() => void save(), now ? 0 : SAVE_DELAY)
  }
  useEffect(() => () => clearTimeout(timer.current), [])
  useEffect(() => {
    if (state !== 'dirty' && state !== 'saving') {
      setForm((f) => (f.director_name === card.director_name && f.person_in_charge === card.person_in_charge
        ? f : { ...f, director_name: card.director_name, person_in_charge: card.person_in_charge }))
    }
  }, [card.director_name, card.person_in_charge, state])

  // phone: label above a visible field; desktop: "Label : value" like the paper card
  const row = (label: string, el: React.ReactNode, desktopOnly = false) => (
    <div className={cn('items-center gap-1 py-1 md:grid md:grid-cols-[9.5rem_1fr] md:gap-2 md:border-b md:border-dotted md:py-0.5', desktopOnly ? 'hidden' : 'grid')}>
      <span className="text-xs text-muted-foreground md:text-sm">{label}</span>
      <div className="flex items-center gap-1"><span className="hidden md:inline">:</span>{el}</div>
    </div>
  )
  const text = (k: 'card_number' | 'location_warehouse' | 'location_rack' | 'person_in_charge' | 'director_name', placeholder: string) => (
    <input disabled={!canEdit} value={form[k] ?? ''} placeholder={canEdit ? placeholder : ''} onBlur={() => state === 'dirty' && void save()}
      onChange={(e) => change(k, k === 'card_number' ? e.target.value : e.target.value || null)}
      className={cn(cellInput, 'font-medium max-md:h-11 max-md:border-border max-md:bg-card max-md:text-base')} />
  )

  return (
    <div className="space-y-2">
      <div className="text-sm md:text-center">
        {canEdit && !shared ? (
          <label className="block">
            <span className="text-xs text-muted-foreground md:hidden">Proyek</span>
            <select value={form.project_id ?? ''} onChange={(e) => change('project_id', e.target.value ? Number(e.target.value) : null, true)}
              className="h-11 w-full rounded-md border bg-card px-2 text-base md:h-9 md:w-auto md:text-sm">
 <option value="">pilih proyek</option>
              {(projects.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>Proyek: {p.name}{p.code ? ` (${p.code})` : ''}</option>)}
            </select>
          </label>
        ) : (
          card.project && <span className="text-muted-foreground">Proyek: <b className="text-foreground">{card.project.name}</b></span>
        )}
      </div>
      <div className="grid gap-x-8 text-sm md:grid-cols-2">
        <div>
          {row('Nama Barang', <span className="px-2 py-1.5 font-medium">{card.item.description}</span>, true)}
          {row('Nomor Kartu', text('card_number', 'mis. EAE.0291'))}
 {row('Satuan', <span className="px-2 py-1.5 font-medium">{card.item.unit ?? ''}</span>, true)}
        </div>
        <div>
          {row('Kode Barang', <span className="px-2 py-1.5 font-mono font-medium">{card.item.code}</span>, true)}
          <div className="grid grid-cols-2 gap-x-3 md:block">
            {row('Letak Gudang', text('location_warehouse', 'mis. GUDANG 1'))}
            {row('Letak Rak', text('location_rack', 'mis. RAK A.11'))}
          </div>
          <div className="md:hidden">{row('Penanggung Jawab', text('person_in_charge', 'nama'))}</div>
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
        <div className="w-full md:hidden">{row('Direktur (TTD)', text('director_name', 'nama direktur'))}</div>
        <SaveBadge state={state} error={error} hint={state === 'dirty' ? (!form.project_id ? 'Pilih proyek' : !form.card_number.trim() ? 'Isi nomor kartu' : null) : null} />
      </div>
    </div>
  )
}

/** Desktop: a signer's name (Penanggung Jawab / Direktur) typed straight under the signature line, saved on leaving it. */
function SignName({ card, field }: { card: StockCard; field: 'person_in_charge' | 'director_name' }) {
  const m = useStockCardMutations(card.id)
  const [value, setValue] = useState(card[field] ?? '')
  const [state, setState] = useState<SaveState>('idle')
  const [error, setError] = useState<string | null>(null)
  useEffect(() => { if (state === 'idle') setValue(card[field] ?? '') }, [card, field, state])

  async function save() {
    const name = value.trim() || null
    if (name === (card[field] ?? null)) { setState('idle'); return }
    setState('saving')
    try {
      await m.update.mutateAsync({
        project_id: card.project?.id ?? null, card_number: card.card_number, location_warehouse: card.location_warehouse,
        location_rack: card.location_rack, person_in_charge: card.person_in_charge, director_name: card.director_name, [field]: name,
      })
      setState('saved')
      setTimeout(() => setState((s) => (s === 'saved' ? 'idle' : s)), 2000)
    } catch (e) {
      setState('error')
      setError(apiErrorMessage(e))
    }
  }

  return (
    <div className="mx-auto mt-1 w-48">
      <input value={value} placeholder={field === 'director_name' ? '( nama direktur )' : '( nama penanggung jawab )'} onChange={(e) => { setValue(e.target.value); setState('dirty') }}
        onBlur={() => void save()} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
        className={cn(cellInput, 'h-7 text-center text-xs')} />
      <SaveBadge state={state === 'dirty' ? 'idle' : state} error={error} />
    </div>
  )
}

// ---------------------------------------------------------------- page

export function StockCardPage() {
  const { id } = useParams()
  const cardId = Number(id)
  const navigate = useNavigate()
  const scope = useStockCardScope()
  const routes = routePaths(scope)
  const { data: card, isLoading, error, refetch } = useStockCard(cardId)
  const m = useStockCardMutations(cardId)
  const [pdfBusy, setPdfBusy] = useState<'print' | 'download' | null>(null)
  const [infoOpen, setInfoOpen] = useState(false)

  if (isLoading) return <p className="text-muted-foreground">Memuat kartu stock…</p>
  if (error || !card) return <p className="text-destructive">{error ? apiErrorMessage(error) : 'Kartu stock tidak ditemukan.'}</p>
  const canEdit = !!card.can_edit
  const canDelete = !!card.can_delete
  const files = () => void refetch()
  const back = card.project ? routes.project(card.project.id) : '/stock-card/project/none'
  const unit = card.item.unit ?? ''

  async function pdf(mode: 'print' | 'download') {
    setPdfBusy(mode)
    try {
      await stockCardPdf(scope, card!, mode)
    } catch (e) {
      window.alert(apiErrorMessage(e))
    } finally {
      setPdfBusy(null)
    }
  }
  const removeCard = async () => {
    if ((await confirmDialog(`Hapus kartu ${card.card_number} beserta semua transaksinya?`))) m.remove.mutate(undefined, { onSuccess: () => navigate(card.project ? back : routes.home) })
  }
  const stats: [string, number, string][] = [
    ['Masuk', card.total_masuk, 'text-emerald-700'],
    ['Keluar', card.total_keluar, 'text-red-700'],
    ['Sisa', card.sisa, card.sisa < 0 ? 'text-destructive' : 'text-foreground'],
  ]

  return (
    <div className="space-y-4 pb-24 md:pb-0">
      <div className="flex items-center justify-between gap-2">
        <BackLink to={back} label={card.project ? card.project.name : 'Kartu tanpa proyek'} className="min-w-0" />
        <div className="hidden flex-wrap gap-2 md:flex">
          <Button variant="outline" onClick={() => void pdf('print')} disabled={pdfBusy !== null}>
            {pdfBusy === 'print' ? <Loader2 className="size-4 animate-spin" /> : <Printer className="size-4" />} Cetak
          </Button>
          <Button variant="outline" onClick={() => void pdf('download')} disabled={pdfBusy !== null}>
            {pdfBusy === 'download' ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />} PDF
          </Button>
          {canDelete && <Button variant="outline" className="text-destructive" onClick={removeCard}><Trash2 className="size-4" /> Hapus kartu</Button>}
        </div>
        <div className="flex items-center gap-2 md:hidden">
          {pdfBusy && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
          <ActionMenu actions={[
            { label: 'Cetak kartu', icon: <Printer className="size-4" />, onClick: () => void pdf('print'), disabled: !!pdfBusy },
            { label: 'Download PDF', icon: <Download className="size-4" />, onClick: () => void pdf('download'), disabled: !!pdfBusy },
            ...(canDelete ? [{ label: 'Hapus kartu', icon: <Trash2 className="size-4" />, onClick: removeCard, danger: true }] : []),
          ]} />
        </div>
      </div>

      {/* phone: what the card is about, at a glance */}
      <div className="space-y-3 rounded-lg border bg-card p-4 md:hidden">
        <div>
          <div className="text-base font-semibold leading-snug">{card.item.description}</div>
          <div className="mt-1 text-xs text-muted-foreground">
            <span className="font-mono">{card.item.code}</span> · Kartu <span className="font-mono">{card.card_number}</span>{unit && ` · ${unit}`}
          </div>
        </div>
        <div className="grid grid-cols-3 divide-x rounded-lg bg-muted/50 py-2 text-center">
          {stats.map(([l, v, c]) => (
            <div key={l}>
              <div className="text-[11px] text-muted-foreground">{l}</div>
              <div className={cn('text-lg font-bold tabular-nums', c)}>{nf.format(v)}</div>
            </div>
          ))}
        </div>
        <button type="button" onClick={() => setInfoOpen((o) => !o)} className="flex w-full items-center justify-between border-t pt-3 text-sm">
          <span className="font-medium">Info kartu</span>
          <span className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
            <span className="truncate">{infoOpen ? 'Tutup' : [card.location_rack, card.person_in_charge].filter(Boolean).join(' · ') || 'Lokasi, penanggung jawab'}</span>
            <ChevronDown className={cn('size-4 shrink-0 transition-transform', infoOpen && 'rotate-180')} />
          </span>
        </button>
        <div className={infoOpen ? 'block' : 'hidden'}>
          <HeaderInline key={card.id} card={card} canEdit={canEdit} />
        </div>
      </div>

 {/* the card as on paper, every cell typed in place on a phone too (table scrolls sideways) */}
      <div className="space-y-4 md:rounded-lg md:border md:bg-card md:p-6">
        <h2 className="hidden text-center text-xl font-bold tracking-wide md:block">KARTU STOCK</h2>
        <div className="hidden md:block"><HeaderInline key={card.id} card={card} canEdit={canEdit} /></div>

        <div className="hidden grid-cols-3 gap-2 text-center md:grid">
          {stats.map(([l, v]) => (
            <div key={l} className={cn('rounded-md border p-2', l === 'Sisa' && 'border-primary/40 bg-primary/5')}>
              <div className="text-xs text-muted-foreground">{l}</div>
              <div className="text-lg font-semibold tabular-nums">{nf.format(v)}</div>
            </div>
          ))}
        </div>

        <div className="overflow-x-auto rounded-lg border bg-card md:rounded-none md:border-0 md:bg-transparent">
          {/* fixed column widths: a wide date picker can never run into No. Bukti */}
          <table className="w-full min-w-[47rem] table-fixed border-collapse text-sm md:min-w-[60rem]">
            <colgroup>
              <col className="w-[8.25rem] md:w-[10rem]" />
              <col className="w-[7.5rem] md:w-[9rem]" />
              <col className="w-[12rem] md:w-auto" />
              <col className="w-[4.5rem] md:w-[5.5rem]" />
              <col className="w-[4.5rem] md:w-[5.5rem]" />
              <col className="w-[4.5rem] md:w-[5rem]" />
              <col className="w-[5.5rem] md:w-[7.5rem]" />
              {canDelete && <col className="w-[2.75rem]" />}
            </colgroup>
            <thead>
              <tr className="bg-muted text-left">
                {['Tgl', 'No. Bukti', 'Keterangan', 'Masuk', 'Keluar', 'Sisa', 'Lampiran', ...(canDelete ? [''] : [])].map((h, i) => (
                  <th key={i} className={cn('border px-2 py-1.5 font-semibold', i >= 3 && i <= 5 && 'text-right')}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {card.rows.map((r) => <EntryTableRow key={r.id} row={r} rows={card.rows} cardId={card.id} canEdit={canEdit} canDelete={canDelete} onFiles={files} />)}
              {canEdit && <EntryTableRow key={`new-${card.rows.length}`} row={null} rows={card.rows} cardId={card.id} canEdit canDelete={canDelete} onFiles={files} />}
              {card.rows.length === 0 && !canEdit && (
                <tr><td colSpan={7} className="border px-2 py-4 text-center text-muted-foreground">Belum ada transaksi.</td></tr>
              )}
            </tbody>
          </table>
        </div>
        {canEdit && (
          <p className="px-1 text-xs text-muted-foreground">
 Ketik langsung di tabel tersimpan otomatis. Baris biru = transaksi baru: isi tanggal, keterangan dan Masuk / Keluar atau langsung ketik Sisa, Masuk / Keluar terhitung sendiri lalu Enter.
            <span className="md:hidden"> Geser tabel ke kanan untuk lampiran (ikon upload: foto / file, bisa beberapa) dan hapus.</span>
          </p>
        )}

        <div className="hidden grid-cols-2 gap-8 pt-4 text-center text-sm md:grid">
          {([['Penanggung Jawab', 'person_in_charge'], ['Direktur', 'director_name']] as const).map(([role, field]) => (
            <div key={role} className="space-y-14">
              <div className="font-medium">{role}</div>
              <div>
                <div className="mx-auto w-48 border-b border-foreground/60" />
                {canEdit
                  ? <SignName card={card} field={field} />
                  : <div className="mt-1 text-xs">{card[field] || '( ........................ )'}</div>}
              </div>
            </div>
          ))}
        </div>
      </div>
      {card.item.accurate_qty != null && (
        <p className="px-1 text-xs text-muted-foreground">Info: stok {card.item.code} di Accurate saat ini {nf.format(card.item.accurate_qty)} {unit} (kartu ini diisi manual, terpisah dari Accurate).</p>
      )}

      {canEdit && card.rows.length > 4 && (
        <MobileBottomBar>
          <Button className="h-12 w-full text-base" onClick={() => {
            const el = document.getElementById('sc-new-line')
            el?.scrollIntoView({ behavior: 'smooth', block: 'center' })
            setTimeout(() => el?.querySelector<HTMLInputElement>('input[data-sc-input]:not([type=date])')?.focus(), 350)
          }}><Plus className="size-5" /> Tulis transaksi baru</Button>
        </MobileBottomBar>
      )}
    </div>
  )
}
