import { useState } from 'react'
import { Package, Plus, Trash2 } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import {
  useTrackingAction, useTrackingCreate, useTrackingDelete, useTrackingList, useTrackingUpdate,
  type StppMaintenanceRow, type StppRow,
} from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { useInlineRowEditor } from '@/components/tracking/useInlineRowEditor'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { CompactPhotoSlot } from '@/components/tracking/EvidencePhoto'
import { NoteCell, useRowNotes } from '@/components/RowNotes'
import { ExportBar } from '@/components/export/ExportBar'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { confirmDialog } from '@/components/ui/confirm'
import { FilterBar } from '@/components/FilterBar'

/**
 * STPP = the Excel "4. Tracking STPP": sheet "STPP" (one serial unit per row,
 * Bukti Keluar / Bukti Terima as photos) and sheet "Maintenance" (refill / repair
 * log per No Seri). Every cell is typed straight into the table and saves
 * itself — same input pattern as Kartu Stock — instead of a create/edit popup.
 */

const ENTITY = 'stpp'
const today = () => new Date().toISOString().slice(0, 10)
const STATUS: Record<string, { label: string; variant: 'default' | 'neutral' | 'warning'; hint: string }> = {
 ACTIVE: { label: 'ACTIVE', variant: 'default', hint: 'No. NPBG + Bukti Keluar alat sedang dipakai' },
 PASSIVE: { label: 'PASSIVE', variant: 'neutral', hint: 'Tanda Kembali (RI) + Bukti Terima alat sudah kembali' },
  INCOMPLETE: { label: 'Belum lengkap', variant: 'warning', hint: 'No. NPBG / Bukti Keluar belum ada' },
}

function StatusBadge({ status }: { status: string }) {
  const s = STATUS[status] ?? { label: status, variant: 'neutral' as const, hint: '' }
  return <Badge variant={s.variant} title={s.hint}>{s.label}</Badge>
}

const cellInput = 'h-9 w-full rounded-md border border-transparent bg-transparent px-2 text-sm outline-none hover:border-border focus:border-primary focus:bg-card focus:ring-2 focus:ring-primary/20 disabled:cursor-default disabled:hover:border-transparent'

function useOptions() {
  return useQuery({
    queryKey: ['stpp-options'],
    staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: { holders: string[]; placements: string[]; units: string[]; descriptions: string[] } }>('/api/stpp/options')).data.data,
  }).data
}

// Excel "Dropdown List": Peminta, Penempatan
const PLACEMENTS = ['MAINTENANCE TABUNG', 'MAINTENANCE KENDARAAN', 'DISTRIBUSI', 'GUDANG', 'GENERAL SERVICE', 'MAINTENANCE MA']

function ListInput({ id, value, onChange, options, placeholder, className }: { id: string; value: string; onChange: (v: string) => void; options: string[]; placeholder?: string; className?: string }) {
  return (
    <>
      <input list={id} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} autoComplete="off" className={className ?? cellInput} />
      <datalist id={id}>{[...new Set(options)].map((o) => <option key={o} value={o} />)}</datalist>
    </>
  )
}

// ---------------------------------------------------------------- sheet "STPP", one row = one table row

type Form = {
  out_date: string; serial_no: string; description_raw: string; qty: string; unit_raw: string
  holder_name_raw: string; placement_raw: string; out_npbg_no: string; out_item_no: string; out_note: string
  return_date: string; return_ri_no: string; return_item_no: string; return_note: string
}
const emptyForm: Form = {
  out_date: today(), serial_no: '', description_raw: '', qty: '1', unit_raw: 'PCS', holder_name_raw: '', placement_raw: '',
  out_npbg_no: '', out_item_no: '', out_note: '', return_date: '', return_ri_no: '', return_item_no: '', return_note: '',
}
const fromRow = (r: StppRow): Form => ({
  out_date: r.out_date ?? '', serial_no: r.serial_no ?? '', description_raw: r.description ?? '', qty: r.qty != null ? String(r.qty) : '',
  unit_raw: r.unit ?? '', holder_name_raw: r.holder ?? '', placement_raw: r.placement ?? '', out_npbg_no: r.out_npbg ?? '',
  out_item_no: r.out_item_no != null ? String(r.out_item_no) : '', out_note: r.out_note ?? '',
  return_date: r.return_date ?? '', return_ri_no: r.return_ri ?? '', return_item_no: r.return_item_no != null ? String(r.return_item_no) : '',
  return_note: r.return_note ?? '',
})
const sameForm = (a: Form, b: Form) => JSON.stringify(a) === JSON.stringify(b)
function toBody(f: Form): Record<string, unknown> {
  return {
    out_date: f.out_date || null, serial_no: f.serial_no.trim() || null, description_raw: f.description_raw.trim() || null,
    qty: f.qty === '' ? null : Number(f.qty.replace(',', '.')), unit_raw: f.unit_raw.trim() || null,
    holder_name_raw: f.holder_name_raw.trim() || null, placement_raw: f.placement_raw.trim() || null,
    out_npbg_no: f.out_npbg_no.trim() || null, out_item_no: f.out_item_no === '' ? null : Number(f.out_item_no),
    out_note: f.out_note.trim() || null,
    return_date: f.return_date || null, return_ri_no: f.return_ri_no.trim() || null,
    return_item_no: f.return_item_no === '' ? null : Number(f.return_item_no), return_note: f.return_note.trim() || null,
  }
}
function invalidForm(f: Form): string | null {
  if (!f.description_raw.trim() && !f.serial_no.trim()) return 'Isi No Seri atau Deskripsi'
  return null
}

function StppTableRow({ row, canEdit, canDelete, note, opts }: {
  row: StppRow | null; canEdit: boolean; canDelete: boolean; note?: { note: string; by: string | null; at: string | null }
  opts?: { holders: string[]; placements: string[]; units: string[]; descriptions: string[] }
}) {
  const qc = useQueryClient()
  const create = useTrackingCreate<StppRow>(ENTITY)
  const update = useTrackingUpdate<StppRow>(ENTITY, row?.id ?? 0)
  const action = useTrackingAction<StppRow>(ENTITY, row?.id ?? 0)
  const del = useTrackingDelete(ENTITY)

  async function saveRow(f: Form) {
    const body = toBody(f)
    const wasOpen = row && row.status !== 'PASSIVE'
    const justReturned = wasOpen && !row!.return_ri && f.return_ri_no.trim()
    const result = row ? await update.mutateAsync(body) : await create.mutateAsync(body)
    if (justReturned) {
      await action.mutateAsync({
        action: 'withdraw',
        body: { return_ri_no: f.return_ri_no.trim() || null, return_date: f.return_date || null, return_item_no: body.return_item_no, return_note: body.return_note },
      })
    }
    return result
  }

  const ed = useInlineRowEditor<StppRow, Form>({ row, fromRow, emptyForm, isSame: sameForm, invalid: invalidForm, save: saveRow })
  const isNew = !row
  const box = { current: null as HTMLTableRowElement | null }
  const leaveRow = (e: React.FocusEvent<HTMLTableRowElement>) => { if (!e.currentTarget.contains(e.relatedTarget as Node)) void ed.flush() }
  const enter = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    void ed.flush()
    const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-stpp-input]'))
    inputs[inputs.indexOf(e.currentTarget) + 1]?.focus()
  }
  const input = (k: keyof Form, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input data-stpp-input disabled={!canEdit} value={ed.form[k]} onChange={(e) => ed.change(k, e.target.value)} onKeyDown={enter} className={cellInput} {...props} />
  )

  async function reissue() {
    if (!row) return
    await action.mutateAsync({ action: 'reissue', body: {} })
  }
  async function remove() {
    if (!row || !(await confirmDialog(`Hapus STPP ${row.serial_no ?? row.number}?`))) return
    await del.mutateAsync(row.id)
  }

  return (
    <tr ref={(el) => { box.current = el }} onBlur={leaveRow} id={isNew ? 'stpp-new-row' : undefined} className={cn('align-top', isNew ? 'bg-primary/5' : 'bg-card')}>
      <td className="border px-1 py-1">{input('out_date', { type: 'date', 'aria-label': 'Tgl NPBG', className: cn(cellInput, 'min-w-[8rem]') })}</td>
      <td className="border px-1 py-1">{input('serial_no', { placeholder: 'SN-0001', 'aria-label': 'No Seri', className: cn(cellInput, 'min-w-[6rem] font-mono') })}</td>
      <td className="border px-1 py-1 min-w-[14rem]">
        <ListInput id={`stpp-desc-${row?.id ?? 'new'}`} value={ed.form.description_raw} onChange={(v) => ed.change('description_raw', v)} options={opts?.descriptions ?? []} placeholder={isNew ? 'Ketik barang baru…' : ''} />
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </td>
      <td className="border px-1 py-1">{input('qty', { inputMode: 'decimal', 'aria-label': 'Kuantitas', className: cn(cellInput, 'min-w-[4rem] text-right tabular-nums') })}</td>
      <td className="border px-1 py-1 min-w-[5rem]"><ListInput id={`stpp-unit-${row?.id ?? 'new'}`} value={ed.form.unit_raw} onChange={(v) => ed.change('unit_raw', v)} options={['PCS', ...(opts?.units ?? [])]} /></td>
      <td className="border px-1 py-1 min-w-[8rem]"><ListInput id={`stpp-holder-${row?.id ?? 'new'}`} value={ed.form.holder_name_raw} onChange={(v) => ed.change('holder_name_raw', v)} options={opts?.holders ?? []} /></td>
      <td className="border px-1 py-1 min-w-[10rem]"><ListInput id={`stpp-place-${row?.id ?? 'new'}`} value={ed.form.placement_raw} onChange={(v) => ed.change('placement_raw', v)} options={[...PLACEMENTS, ...(opts?.placements ?? [])]} /></td>
      <td className="border px-1 py-1 min-w-[11rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.out_npbg_no} onChange={(v) => ed.change('out_npbg_no', v)}
            onPick={(d) => ed.setForm((s) => ({
              ...s, out_date: d.date ?? s.out_date, out_note: s.out_note || (d.note ?? ''),
              description_raw: s.description_raw || (d.lines[0]?.description ?? ''), unit_raw: d.lines[0]?.unit ?? s.unit_raw,
            }))} />
 ) : <span className="whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.out_npbg ?? ''}</span>}
      </td>
      <td className="border px-1 py-1">{input('out_item_no', { type: 'number', min: 1, 'aria-label': 'Item No', className: cn(cellInput, 'min-w-[4rem] text-right') })}</td>
      <td className="border px-1 py-1">{row && <CompactPhotoSlot entity={ENTITY} entityId={row.id} slot="out" label="Bukti Keluar" photo={row.photos.out} canEdit={canEdit} onChanged={() => qc.invalidateQueries({ queryKey: [ENTITY] })} />}</td>
      <td className="border px-1 py-1 min-w-[10rem]">{input('out_note', { 'aria-label': 'Keterangan Keluar' })}</td>
      <td className="border px-2 py-1 text-center">{row && <StatusBadge status={row.status} />}</td>
      <td className="border px-1 py-1">{input('return_date', { type: 'date', 'aria-label': 'Tgl RI', className: cn(cellInput, 'min-w-[8rem]') })}</td>
      <td className="border px-1 py-1 min-w-[11rem]">
        {canEdit ? (
          <DocNumberInput compact type="ri" value={ed.form.return_ri_no} onChange={(v) => ed.change('return_ri_no', v)}
            onPick={(d) => ed.setForm((s) => ({ ...s, return_date: d.date ?? s.return_date, return_note: s.return_note || (d.note ?? '') }))} />
 ) : <span className="whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.return_ri ?? ''}</span>}
      </td>
      <td className="border px-1 py-1">{input('return_item_no', { type: 'number', min: 1, 'aria-label': 'Item No2', className: cn(cellInput, 'min-w-[4rem] text-right') })}</td>
      <td className="border px-1 py-1">{row && <CompactPhotoSlot entity={ENTITY} entityId={row.id} slot="in" label="Bukti Terima" photo={row.photos.in} canEdit={canEdit} onChanged={() => qc.invalidateQueries({ queryKey: [ENTITY] })} />}</td>
      <td className="border px-1 py-1 min-w-[10rem]">{input('return_note', { 'aria-label': 'Keterangan Kembali' })}</td>
      <td className="border px-2 py-1">{row && <NoteCell entity={ENTITY} rowKey={row.id} note={note} />}</td>
      {canEdit && (
        <td className="border px-1 py-1 text-center">
          {row && (
            <div className="flex items-center justify-center gap-1">
              {row.status === 'PASSIVE' && (
                <button type="button" title="Serahkan lagi (baris baru)" onClick={() => void reissue()} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-primary">
                  <Plus className="size-4" />
                </button>
              )}
              {canDelete && row.status !== 'PASSIVE' && (
                <button type="button" title="Hapus baris" onClick={() => void remove()} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive">
                  <Trash2 className="size-4" />
                </button>
              )}
            </div>
          )}
        </td>
      )}
    </tr>
  )
}

const sttpHeaders = ['Tgl NPBG', 'No Seri', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Penempatan', 'No. NPBG', 'Item No', 'Bukti Keluar', 'Keterangan Keluar', 'Status', 'Tgl RI', 'Tanda Kembali', 'Item No2', 'Bukti Terima', 'Keterangan Kembali', 'Catatan']

function StppSheet() {
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('stpp.create')
  const canEdit = hasPermission('stpp.update')
  const canDelete = hasPermission('stpp.update')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const filters = { search: search || undefined, status: status || undefined }
  const { data, isLoading } = useTrackingList<StppRow>(ENTITY, { ...filters, page })
  const { rows, footer } = useInfiniteRows(data, page, setPage)
  const opts = useOptions()
  const { data: noteMap } = useRowNotes(ENTITY, rows.map((r) => r.id))

  return (
    <div className="space-y-3">
      <FilterBar className="items-center">
        <Input placeholder="Cari no seri, barang, peminta, penempatan, NPBG, RI…" className="max-w-xs" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <Select className="max-w-[12rem]" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">Semua status</option>
          {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </Select>
        <ExportBar dataset="stpp" filters={filters} filenameBase="stpp" viewPermission="stpp.view" />
      </FilterBar>
      {canEdit && (
        <p className="text-xs text-muted-foreground">
 Ketik langsung di tabel tersimpan otomatis. Baris biru = serah terima baru. Isi "Tanda Kembali" untuk otomatis menandai alat sudah kembali (PASSIVE).
        </p>
      )}
      <div className="max-h-[70vh] overflow-auto rounded-lg border">
        <table className="w-full table-auto border-collapse text-sm">
          <thead className="sticky top-0 z-10 bg-muted">
            <tr className="text-left">
              {sttpHeaders.map((h, i) => <th key={i} className="whitespace-nowrap border px-2 py-1.5 font-semibold">{h}</th>)}
              {canEdit && <th className="whitespace-nowrap border px-2 py-1.5 font-semibold">Aksi</th>}
            </tr>
          </thead>
          <tbody>
            {isLoading && rows.length === 0 && (
              <tr><td colSpan={sttpHeaders.length + 1} className="border px-2 py-6 text-center text-muted-foreground">Memuat…</td></tr>
            )}
            {rows.map((r) => <StppTableRow key={r.id} row={r} canEdit={canEdit} canDelete={canDelete} note={noteMap?.[r.id]} opts={opts} />)}
            {canCreate && <StppTableRow key="new" row={null} canEdit canDelete={false} opts={opts} />}
          </tbody>
        </table>
        <div className="border-t">{footer}</div>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- sheet "Maintenance"

type MForm = { serial_no: string; requester: string; npbg_date: string; npbg_no: string; note: string }
const mEmptyForm: MForm = { serial_no: '', requester: '', npbg_date: today(), npbg_no: '', note: '' }
const mFromRow = (r: StppMaintenanceRow): MForm => ({
  serial_no: r.serial_no ?? '', requester: r.requester ?? '', npbg_date: r.npbg_date ?? '', npbg_no: r.npbg_no ?? '', note: r.note ?? '',
})
const mSame = (a: MForm, b: MForm) => JSON.stringify(a) === JSON.stringify(b)
function mInvalid(f: MForm): string | null {
  return f.serial_no.trim() ? null : 'Isi No Seri'
}
function mToBody(f: MForm) {
  return { serial_no: f.serial_no.trim() || undefined, requester: f.requester.trim() || null, npbg_date: f.npbg_date || null, npbg_no: f.npbg_no.trim() || null, note: f.note.trim() || null }
}

function MaintenanceTableRow({ row, canEdit, opts }: { row: StppMaintenanceRow | null; canEdit: boolean; opts?: { holders: string[] } }) {
  const qc = useQueryClient()
  const invalidate = () => qc.invalidateQueries({ queryKey: ['stpp-maintenance'] })
  async function saveRow(f: MForm) {
    const body = mToBody(f)
    const result = row ? (await api.put(`/api/stpp-maintenance/${row.id}`, body)).data.data : (await api.post('/api/stpp-maintenance', body)).data.data
    invalidate()
    return result
  }
  const ed = useInlineRowEditor<StppMaintenanceRow, MForm>({ row, fromRow: mFromRow, emptyForm: mEmptyForm, isSame: mSame, invalid: mInvalid, save: saveRow })
  const isNew = !row
  const leaveRow = (e: React.FocusEvent<HTMLTableRowElement>) => { if (!e.currentTarget.contains(e.relatedTarget as Node)) void ed.flush() }
  const enter = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    void ed.flush()
    const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-stppm-input]'))
    inputs[inputs.indexOf(e.currentTarget) + 1]?.focus()
  }
  const input = (k: keyof MForm, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input data-stppm-input disabled={!canEdit} value={ed.form[k]} onChange={(e) => ed.change(k, e.target.value)} onKeyDown={enter} className={cellInput} {...props} />
  )
  async function remove() {
    if (!row || !(await confirmDialog('Hapus baris Maintenance ini?'))) return
    await api.delete(`/api/stpp-maintenance/${row.id}`)
    invalidate()
  }

  return (
    <tr onBlur={leaveRow} id={isNew ? 'stppm-new-row' : undefined} className={cn('align-top', isNew ? 'bg-primary/5' : 'bg-card')}>
      <td className="border px-1 py-1">{input('serial_no', { placeholder: 'SN-0396', 'aria-label': 'No Seri', className: cn(cellInput, 'min-w-[6rem] font-mono') })}</td>
      <td className="border px-1 py-1 min-w-[8rem]">
        <ListInput id={`stppm-req-${row?.id ?? 'new'}`} value={ed.form.requester} onChange={(v) => ed.change('requester', v)} options={opts?.holders ?? []} />
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </td>
      <td className="border px-1 py-1">{input('npbg_date', { type: 'date', 'aria-label': 'Tgl NPBG', className: cn(cellInput, 'min-w-[8rem]') })}</td>
      <td className="border px-1 py-1 min-w-[11rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((s) => ({ ...s, npbg_date: d.date ?? s.npbg_date, note: s.note || (d.note ?? '') }))} />
 ) : <span className="whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.npbg_no ?? ''}</span>}
      </td>
      <td className="border px-1 py-1 min-w-[12rem]">{input('note', { 'aria-label': 'Keterangan' })}</td>
      {canEdit && (
        <td className="border px-1 py-1 text-center">
          {row && (
            <button type="button" title="Hapus baris" onClick={() => void remove()} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive">
              <Trash2 className="size-4" />
            </button>
          )}
        </td>
      )}
    </tr>
  )
}

function MaintenanceSheet() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('stpp.update')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useTrackingList<StppMaintenanceRow>('stpp-maintenance', { search: search || undefined, page })
  const { rows, footer } = useInfiniteRows(data, page, setPage)
  const opts = useOptions()

  return (
    <div className="space-y-3">
      <Input placeholder="Cari no seri, peminta, NPBG, keterangan…" className="max-w-xs" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
      {canEdit && <p className="text-xs text-muted-foreground">Deskripsi, kuantitas dan satuan diambil otomatis dari data STPP No Seri ini (seperti rumus Excel).</p>}
      <div className="max-h-[70vh] overflow-auto rounded-lg border">
        <table className="w-full table-auto border-collapse text-sm">
          <thead className="sticky top-0 z-10 bg-muted">
            <tr className="text-left">
              {['No Seri', 'Peminta', 'Tgl NPBG', 'No NPBG', 'Keterangan'].map((h, i) => <th key={i} className="whitespace-nowrap border px-2 py-1.5 font-semibold">{h}</th>)}
              {canEdit && <th className="whitespace-nowrap border px-2 py-1.5 font-semibold">Aksi</th>}
            </tr>
          </thead>
          <tbody>
            {isLoading && rows.length === 0 && (
              <tr><td colSpan={6} className="border px-2 py-6 text-center text-muted-foreground">Memuat…</td></tr>
            )}
            {rows.map((r) => <MaintenanceTableRow key={r.id} row={r} canEdit={canEdit} opts={opts} />)}
            {canEdit && <MaintenanceTableRow key="new" row={null} canEdit opts={opts} />}
          </tbody>
        </table>
        <div className="border-t">{footer}</div>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- page

type Sheet = 'stpp' | 'maintenance'

export function StppPage() {
  const [sheet, setSheet] = useState<Sheet>('stpp')

  return (
    <div className="space-y-5">
      <PageHeader
        title="STPP"
 subtitle="Sesuai Excel Tracking STPP Serah Terima Pinjam Pakai. Ketik langsung di tabel, tersimpan otomatis."
        icon={<Package className="size-5" />}
      />
      <div className="flex gap-1 overflow-x-auto border-b" role="tablist">
        {([['stpp', 'STPP'], ['maintenance', 'Maintenance']] as const).map(([k, label]) => (
          <button key={k} type="button" role="tab" aria-selected={sheet === k} onClick={() => setSheet(k)}
            className={cn('-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
              sheet === k ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>
            {label}
          </button>
        ))}
      </div>
      {sheet === 'stpp' ? <StppSheet /> : <MaintenanceSheet />}
    </div>
  )
}
