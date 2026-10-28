import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Boxes, Camera, ExternalLink, ImageIcon, Loader2, Plus } from 'lucide-react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, apiErrorMessage } from '@/lib/api'
import { useTrackingList, type ManufacturingLine, type ManufacturingRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { photoToDataUrl } from '@/components/tracking/EvidencePhoto'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { CellList, InlineSheet, RowActions, SheetTabs, Td, rowClass, today, useSheetRow } from '@/components/tracking/InlineSheet'
import { NoteCell, type RowNote } from '@/components/RowNotes'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Manufaktur & Assembly — one feature, the two sheets of the Excel
 * "7. Tracking Manufaktur & Assembly": MA (kind ASSEMBLY) and MJ (kind JASA).
 * One table row = one Excel row = one unit. Same as STPP: typed straight into
 * the table, saved by itself; the blue row starts a new No. MA / MJ, "+" on a
 * row adds a unit to it (same Sub, or the next Sub for a new NPBG).
 */

type Kind = 'ASSEMBLY' | 'JASA'

// options from the Excel sheet "Dropdown List"
const SHEETS: Record<Kind, { code: 'MA' | 'MJ'; title: string; products: string[]; processes: string[]; locations: string[] }> = {
  ASSEMBLY: {
    code: 'MA',
    title: 'Manufaktur & Assembly',
    products: ['CRADLE CARBON STEEL 3x2', 'CRADLE CARBON STEEL 4x4', 'CRADLE VGL', 'CRADLE MANIFOLD 3x2', 'CRADLE MANIFOLD 4x4',
      'CRADLE MANIFOLD C2H2 4x4', 'MANIFOLD CRADLE 2x2', 'MANIFOLD CRADLE 3x2', 'MANIFOLD CRADLE 4x4', 'MANIFOLD CRADLE C2H2 4x4'],
    processes: ['CRA(1) - PEMBUATAN RANGKA', 'CRA(2) - PEMASANGAN KOMPONEN', 'ADD(1) - PENGECATAN CRADLE', 'MAN(1) - PEMBUATAN MANIFOLD',
      'CRM(1) - BUNDLE CRADLE & MANIFOLD'],
    locations: ['SIG'],
  },
  JASA: {
    code: 'MJ',
    title: 'Manufaktur & Jasa Lain-Lain',
    products: ['PIPA TEMBAGA 8mm x 2mm x 96cm', 'PENGUKUR TEKANAN TABUNG C2H2', 'SIKU CARBON STEEL - 40 x 40 x 4mm / P = 65cm',
      'PLAT STRIP CARBON STEEL - 40cm x 4mm / P = 90cm', '(VULKANISIR) BAN LUAR NYLON - GAJAH TUNGGAL / 7.50-16 TT / GT SUPER 88N',
      '(VULKANISIR) BAN LUAR STEEL - GITI / 11.00-R20 TT / GAZ892', '(REFURBISHED) PRINTER - EPSON / L3210'],
    processes: ['JASA FABRIKASI', 'JASA PEMOTONGAN', 'JASA VULKANISIR', 'JASA PERBAIKAN', 'JASA DRAD'],
    locations: ['SIG', 'PT. BANGKIT LAKSANA JAYA', 'PT. SURYA MAS AGUNG', 'CV. NURUL JAYA TEHNIK', 'CV. MAESTRO VULKANISIR KEDIRI',
      'CV. MAJU KAWAN TECHNINDO', 'PT. GLOBAL HOSINDO', 'INK CLINIQUE', 'CV. HARAPAN JAYA IMMANUEL', 'RAJA INVERTER SERVICES',
      'PT. INTERMEDIA COMPUTER INDONESIA', 'TEKAD JAYA MANDIRI', 'KREATIF KOMPUTER', 'CV. ANDALAN TATA JAYA', 'PT. CATU INTER GLOBAL'],
  },
}

/** Excel column "Status Hasil Pengerjaan". */
const STATUS: Record<string, { label: string; variant: 'neutral' | 'default' | 'success' | 'warning'; hint: string }> = {
  REQUESTED: { label: 'REQUESTED', variant: 'neutral', hint: 'Hasil produk, no. seri & proses sudah diisi' },
 ON_GOING: { label: 'ON-GOING', variant: 'default', hint: 'No. NPBG sudah ada material keluar, sedang dikerjakan' },
  COMPLETED: { label: 'COMPLETED', variant: 'success', hint: 'Nama file / foto hasil, tgl selesai & no. RI lengkap' },
  INCOMPLETE: { label: 'Belum lengkap', variant: 'warning', hint: 'Hasil produk / no. seri / proses belum diisi' },
}
const STATUS_LABELS = Object.fromEntries(Object.entries(STATUS).map(([k, v]) => [k, v.label]))

function StatusBadge({ status }: { status: string }) {
  const s = STATUS[status] ?? { label: status, variant: 'neutral' as const, hint: '' }
  return <Badge variant={s.variant} title={s.hint}>{s.label}</Badge>
}

const fmtDate = (v: string | null | undefined) => (v ? new Date(v).toLocaleDateString('id-ID') : '—')

/** NPBG to the workshop itself (MAINTENANCE (SDA) / GUDANG) = work done at SIG; otherwise the vendor. */
const lokasiFrom = (party: string | null) => (!party || /MAINTENANCE|GUDANG/i.test(party) ? 'SIG' : party)

/** Hasil produk of the sheet's dropdown list that the keterangan names (longest match wins). */
function productFrom(kind: Kind, note: string | null): string {
  const n = (note ?? '').toUpperCase()
  return [...SHEETS[kind].products].sort((a, b) => b.length - a.length).find((p) => n.includes(p.toUpperCase())) ?? ''
}

type OptionField = 'product' | 'serial' | 'process' | 'location'
type SheetOptions = { products: string[]; serials: string[]; processes: string[]; locations: string[] }

/** Values already used on the sheet (the Excel "Dropdown List" grows the same way). */
function useSheetOptions(kind: Kind) {
  return useQuery({
    queryKey: ['mfg-options', kind],
    staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: SheetOptions }>('/api/manufacturing-lines/options', { params: { kind } })).data.data,
  }).data
}

/**
 * An Excel dropdown column as a table cell: the sheet's list, and while typing
 * the database too (Accurate items for Hasil Produk, vendors for Lokasi, Ban Luar
 * / STPP serials for No. Seri).
 */
function OptionCell({ kind, field, value, onChange, base, disabled, placeholder, onKeyDown, className }: {
  kind: Kind; field: OptionField; value: string; onChange: (v: string) => void; base?: SheetOptions; disabled?: boolean
  placeholder?: string; onKeyDown?: React.KeyboardEventHandler<HTMLInputElement>; className?: string
}) {
  const [q, setQ] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setQ(value.trim()), 300)
    return () => clearTimeout(t)
  }, [value])
  const found = useQuery({
    queryKey: ['mfg-options', kind, field, q],
    enabled: !disabled && q.length >= 2 && field !== 'process',
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: string[] }>('/api/manufacturing-lines/options', { params: { kind, field, q } })).data.data,
  }).data ?? []
  const fixed = field === 'product' ? SHEETS[kind].products : field === 'process' ? SHEETS[kind].processes : field === 'location' ? SHEETS[kind].locations : []
  const used = base ? { product: base.products, serial: base.serials, process: base.processes, location: base.locations }[field] : []
  return <CellList value={value} onChange={onChange} options={[...found, ...fixed, ...used]} disabled={disabled} placeholder={placeholder} onKeyDown={onKeyDown} className={className} />
}

/** Foto Hasil: open the stored photo (the API needs the bearer token) or take / pick one. */
function PhotoCell({ row, canEdit, onChanged }: { row: ManufacturingLine; canEdit: boolean; onChanged: () => void }) {
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const btn = 'inline-flex size-7 shrink-0 items-center justify-center rounded-md border bg-card text-muted-foreground hover:border-primary/50 hover:text-primary'

  async function open() {
    const w = window.open('', '_blank')
    const res = await api.get(`/api/manufacturing-subs/${row.line_id}/photo`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
    if (w) w.location.href = url
    else window.location.href = url
  }
  async function upload(file: File) {
    setBusy(true)
    setErr(null)
    try {
      await api.post(`/api/manufacturing-subs/${row.line_id}/photo`, { photo: await photoToDataUrl(file) })
      onChanged()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex items-center gap-1" title={err ?? undefined}>
      {row.has_photo && (
        <button type="button" onClick={() => void open()} className={`${btn} border-primary/40 text-primary`} title="Lihat Foto Hasil" aria-label="Lihat Foto Hasil">
          <ImageIcon className="size-3.5" />
        </button>
      )}
      {canEdit && (busy
        ? <span className={btn}><Loader2 className="size-3.5 animate-spin" /></span>
        : <button type="button" onClick={() => input.current?.click()} className={btn} title={row.has_photo ? 'Ganti Foto Hasil' : 'Unggah Foto Hasil'} aria-label="Unggah Foto Hasil">
            <Camera className="size-3.5" />
          </button>)}
      <input ref={input} type="file" accept="image/*" capture="environment" className="hidden"
        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void upload(f) }} />
      {!row.has_photo && row.file_name && <span className="text-[11px] text-muted-foreground" title={`"${row.file_name}" ada di folder DATA BUKTI (Excel)`}>folder Excel</span>}
      {err && <span className="text-xs text-destructive">!</span>}
    </div>
  )
}

// ---------------------------------------------------------------- one unit row

type Form = {
  row_date: string; row_location: string; product_name: string; serial_no_raw: string; item_no: string; process: string; note_start: string
  npbg_no: string; file_name: string; finish_date: string; ri_no: string; note_end: string
}
const emptyFor = (kind: Kind): Form => ({
  row_date: today(), row_location: kind === 'ASSEMBLY' ? 'SIG' : '', product_name: '', serial_no_raw: kind === 'JASA' ? '-' : '', item_no: '',
  process: '', note_start: '', npbg_no: '', file_name: '', finish_date: '', ri_no: '', note_end: '',
})
const fromRow = (r: ManufacturingLine): Form => ({
  row_date: r.date ?? '', row_location: r.location ?? '', product_name: r.product_name ?? '', serial_no_raw: r.serial_no ?? '',
  item_no: r.item_no != null ? String(r.item_no) : '', process: r.process ?? '', note_start: r.note_start ?? '', npbg_no: r.npbg_no ?? '',
  file_name: r.file_name ?? '', finish_date: r.finish_date ?? '', ri_no: r.ri_no ?? '', note_end: r.note_end ?? '',
})
const same = (a: Form, b: Form) => JSON.stringify(a) === JSON.stringify(b)
function toBody(f: Form): Record<string, unknown> {
  const body: Record<string, unknown> = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v.trim() || null]))
  body.item_no = f.item_no ? Number(f.item_no) : null
  return body
}

function UnitRow({ kind, row, canEdit, note, opts }: { kind: Kind; row: ManufacturingLine | null; canEdit: boolean; note?: RowNote; opts?: SheetOptions }) {
  const qc = useQueryClient()
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['manufacturing-lines'] })
    qc.invalidateQueries({ queryKey: ['manufacturing-orders'] })
    qc.invalidateQueries({ queryKey: ['mfg-options', kind] })
  }
  const ed = useSheetRow<ManufacturingLine, Form>({
    row, fromRow, emptyForm: emptyFor(kind), isSame: same, canEdit,
    invalid: (f) => (!f.product_name.trim() ? 'Isi Hasil Produk' : kind === 'JASA' && !f.row_location.trim() ? 'Isi Lokasi (vendor)' : null),
    save: async (f) => {
      const body = toBody(f) // Item No left empty on a new row = next on the sheet
      const r = row
        ? await api.put(`/api/manufacturing-subs/${row.line_id}`, body)
        : await api.post('/api/manufacturing-orders', { kind, date: body.row_date, location: body.row_location, product_name: body.product_name, lines: [body] })
      refresh()
      return r.data
    },
  })
  const isNew = !row
  const opt = (field: OptionField, k: keyof Form, placeholder?: string) => (
    <OptionCell kind={kind} field={field} value={ed.form[k]} onChange={(v) => ed.change(k, v)} base={opts} disabled={!canEdit}
      placeholder={placeholder} onKeyDown={ed.onKeyDown} />
  )

  async function addUnit(newSub: boolean) {
    if (!row) return
    try {
      await api.post(`/api/manufacturing-orders/${row.id}/subs`, {
        product_name: row.product_name, process: row.process, row_location: row.location, row_date: today(), new_sub: newSub,
        serial_no_raw: kind === 'JASA' ? '-' : null,
      })
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }
  async function remove() {
    if (!row || !(await confirmDialog(`Hapus ${row.number} ${row.sub_no} item ${row.item_no ?? ''}?`))) return
    try {
      const order = (await api.delete<{ data: ManufacturingRow }>(`/api/manufacturing-subs/${row.line_id}`)).data.data
      // the last unit gone: the No. MA / MJ itself goes too
      if ((order.subs ?? []).length === 0) await api.delete(`/api/manufacturing-orders/${row.id}`)
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }

  const cells: Record<string, ReactNode> = {
    no: <Td key="no" className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>,
    date: <Td key="date">{ed.input('row_date', { type: 'date', 'aria-label': 'Tanggal', className: 'min-w-[8rem]' })}</Td>,
    number: <Td key="number" className="whitespace-nowrap px-2 py-2 font-mono text-xs font-medium">{row?.number ?? <span className="text-muted-foreground">otomatis</span>}</Td>,
    sub: <Td key="sub" className="whitespace-nowrap px-2 py-2 font-mono text-xs">{row?.sub_no ?? 'SUB-01'}</Td>,
    loc: <Td key="loc" className="min-w-[10rem]">{opt('location', 'row_location', kind === 'JASA' ? 'Vendor / bengkel' : 'SIG')}</Td>,
    product: (
      <Td key="product" className="min-w-[16rem]">
        <div className="flex items-center gap-1">
          <div className="min-w-0 flex-1">{opt('product', 'product_name', isNew ? 'Ketik hasil produk…' : '')}</div>
          {row?.item_code && (
            <Link to={`/items?search=${encodeURIComponent(row.item_code)}`} className="shrink-0 rounded p-1 text-primary hover:bg-muted" title={`Buka barang Accurate ${row.item_code}`}>
              <ExternalLink className="size-3.5" />
            </Link>
          )}
        </div>
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
    ),
    serial: (
      <Td key="serial" className="min-w-[9rem]">
        <div className="flex items-center gap-1">
          <div className="min-w-0 flex-1">{opt('serial', 'serial_no_raw', kind === 'JASA' ? '-' : 'SIG-58')}</div>
          {row?.serial_link && (
            <Link to={row.serial_link} className="shrink-0 rounded p-1 text-primary hover:bg-muted" title="Buka riwayat no. seri ini">
              <ExternalLink className="size-3.5" />
            </Link>
          )}
        </div>
      </Td>
    ),
    item: <Td key="item">{ed.input('item_no', { type: 'number', min: 1, placeholder: 'auto', 'aria-label': 'Item No', className: 'min-w-[4.5rem] text-right' })}</Td>,
    process: <Td key="process" className="min-w-[13rem]">{opt('process', 'process')}</Td>,
  }
  const head = kind === 'JASA'
    ? ['no', 'date', 'number', 'sub', 'item', 'loc', 'product', 'serial', 'process']
    : ['no', 'date', 'number', 'sub', 'loc', 'product', 'serial', 'item', 'process']

  return (
    <tr {...ed.rowProps} className={rowClass(isNew)}>
      {head.map((k) => cells[k])}
      <Td className="min-w-[15rem]">{ed.area('note_start', { placeholder: isNew ? 'mis. UNTUK FABRIKASI / PERAKITAN CRADLE 3X2 …' : '' })}</Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <StatusBadge status={row.status} />}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((s) => ({
              ...s, row_date: d.date ?? s.row_date, note_start: s.note_start || (d.note ?? ''),
              product_name: s.product_name || productFrom(kind, d.note),
              row_location: kind === 'JASA' && !s.row_location ? lokasiFrom(d.party) : s.row_location,
              serial_no_raw: (!s.serial_no_raw || s.serial_no_raw === '-') && d.serials?.[0] ? d.serials[0] : s.serial_no_raw,
            }))} />
        ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.npbg_no ?? '—'}</span>}
      </Td>
      <Td className="min-w-[13rem]">{ed.list('file_name', row?.helper_file_name ? [row.helper_file_name] : [], { placeholder: row?.helper_file_name ?? '', className: 'text-xs' })}</Td>
      <Td>{row && <PhotoCell row={row} canEdit={canEdit} onChanged={refresh} />}</Td>
      <Td>{ed.input('finish_date', { type: 'date', 'aria-label': 'Tgl Selesai Pengerjaan', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="ri" value={ed.form.ri_no} onChange={(v) => ed.change('ri_no', v)}
            onPick={(d) => ed.setForm((s) => ({ ...s, finish_date: d.date ?? s.finish_date, note_end: s.note_end || (d.note ?? '') }))} />
        ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.ri_no ?? '—'}</span>}
      </Td>
      <Td className="min-w-[14rem]">{ed.area('note_end')}</Td>
      <Td className="px-2">{row && <NoteCell entity="manufacturing-lines" rowKey={row.line_id} note={note} />}</Td>
      {canEdit && (
        <Td className="text-center">
          {row && (
            <RowActions onAdd={() => void addUnit(false)} addTitle={`Tambah unit ke ${row.number} ${row.sub_no}`}
              onDelete={row.status !== 'COMPLETED' ? () => void remove() : undefined}>
              <button type="button" onClick={() => void addUnit(true)} title={`Sub berikutnya (NPBG baru) untuk ${row.number}`}
                className="inline-flex items-center rounded px-1 py-1.5 text-[11px] font-medium text-muted-foreground hover:bg-muted hover:text-primary">
                <Plus className="size-3" />Sub
              </button>
            </RowActions>
          )}
        </Td>
      )}
    </tr>
  )
}

// ---------------------------------------------------------------- page

/** Excel row 4: today's date and the MA/MJ number the next entry gets. */
function useSheetHead(kind: Kind) {
  const { data } = useTrackingList<ManufacturingLine>('manufacturing-lines', { kind, per_page: 1 })
  const meta = data?.meta as { today?: string; next_number?: string } | undefined
  return { today: meta?.today ?? today(), next: meta?.next_number }
}

function headersFor(kind: Kind): string[] {
  const code = SHEETS[kind].code
  const no = kind === 'JASA' ? 'No. Manufaktur & Jasa' : 'No. Manufaktur & Assembly'
  const head = kind === 'JASA'
    ? ['No', 'Tanggal', no, `Sub ${code}`, 'Item No', 'Lokasi', 'Hasil Produk', 'No. Seri', 'Proses']
    : ['No', 'Tanggal', no, `Sub ${code}`, 'Lokasi', 'Hasil Produk', 'No. Seri', 'Item No', 'Proses']
  return [...head, 'Keterangan Awal', 'Status Hasil Pengerjaan', 'No. NPBG', 'Nama File (#.ext)', 'Foto Hasil', 'Tgl Selesai Pengerjaan', 'No. RI', 'Keterangan Akhir']
}

export function ManufacturingPage() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('manufacturing.update')
  const canCreate = hasPermission('manufacturing.create')
  const [kind, setKind] = useState<Kind>('ASSEMBLY')
  const sheet = SHEETS[kind]
  const head = useSheetHead(kind)
  const opts = useSheetOptions(kind)

  return (
    <div className="space-y-5">
      <PageHeader
        title="Manufaktur & Assembly"
 subtitle="Sesuai Excel Tracking Manufaktur & Assembly satu baris per unit. Ketik langsung di tabel, tersimpan otomatis."
        icon={<Boxes className="size-5" />}
      />
      <SheetTabs tabs={(Object.keys(SHEETS) as Kind[]).map((k) => [k, `${SHEETS[k].title} (${SHEETS[k].code})`] as const)} value={kind} onChange={setKind} />
      <InlineSheet<ManufacturingLine>
        key={kind}
        base="manufacturing-lines"
        params={{ kind }}
        headers={headersFor(kind)}
        canEdit={canEdit}
        rowKey={(r) => r.line_id}
        notesEntity="manufacturing-lines"
        statuses={['REQUESTED', 'ON_GOING', 'COMPLETED', 'INCOMPLETE']}
        statusLabels={STATUS_LABELS}
        searchPlaceholder="Cari no., produk, no. seri, proses, NPBG, RI…"
        exportDataset="manufacturing"
        exportPermission="manufacturing.view"
        toolbarExtra={
          <div className="flex flex-wrap gap-x-4 text-xs text-muted-foreground">
            <span>Hari ini: <b className="text-foreground">{fmtDate(head.today)}</b></span>
            {head.next && <span>No. {sheet.code} berikutnya: <b className="font-mono text-foreground">{head.next}</b></span>}
          </div>
        }
        hint={<>Status: produk + seri + proses → <b>REQUESTED</b>, No. NPBG → <b>ON-GOING</b>, foto + Tgl Selesai + No. RI → <b>COMPLETED</b>. + = unit, +Sub = Sub baru.</>}
        renderRow={(r, note) => <UnitRow kind={kind} row={r} canEdit={canEdit} note={note} opts={opts} />}
        newRow={canCreate ? <UnitRow key={`new-${kind}`} kind={kind} row={null} canEdit opts={opts} /> : null}
      />
    </div>
  )
}
