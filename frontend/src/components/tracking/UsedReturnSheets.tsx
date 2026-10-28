import { type ReactNode } from 'react'
import { Recycle } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { api, apiErrorMessage } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { CompactPhotoSlot } from '@/components/tracking/EvidencePhoto'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { InlineSheet, RowActions, Td, rowClass, today, useSheetRow } from '@/components/tracking/InlineSheet'
import { NoteCell, type RowNote } from '@/components/RowNotes'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import type { AccurateDocSummary } from '@/features/tracking/api'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Pengembalian Bekas — the tracking sheets of the Excel "8. Tracking Pengembalian
 * Bekas": "Spare Part" (component matrix, signed qty = surplus / shortage) and
 * "Spare Part Lain" (one item per row, Foto Keluar / Foto Terima). Same as STPP:
 * typed straight into the table, saved by itself.
 */

// Excel sheet "Spare Part" component columns, in its order
export const COMPONENTS: [string, string][] = [
  ['BONIT_BR', 'Bonit BR'], ['PEN_BR', 'Pen BR'], ['PEN_SS', 'Pen SS'], ['VALVE_BR', 'Valve BR'], ['CYL_CAP', 'Cyl Cap'],
  ['MUR_BR', 'Mur Br'], ['MUR_CS', 'Mur CS'], ['MUR_GI', 'Mur GI'], ['MUR_SS', 'Mur SS'], ['PER_CS', 'Per CS'],
  ['BAUT_BR', 'Baut BR'], ['BAUT_CS', 'Baut CS'], ['BAUT_GI', 'Baut GI'], ['BAUT_SS', 'Baut SS'],
]

interface Base {
  id: number
  no: number | null
  npbg_date: string | null
  npbg_no: string | null
  npbg_linked: boolean
  status: string
  ri_no: string | null
  ri_linked: boolean
  note: string | null
  npbg?: AccurateDocSummary | null
  ri?: AccurateDocSummary | null
}
export interface MatrixRow extends Base { ri_date: string | null; components: Record<string, number> }
export interface OtherRow extends Base {
  description: string | null; qty: number | null; unit: string | null; item_no: number | null; out_file_name: string | null; helper_out: string | null
  return_item_no: number | null; in_file_name: string | null; helper_in: string | null
  photos: Partial<Record<'out' | 'in', { id: number; file_name: string | null }>>
}

const STATUS: Record<string, { label: string; variant: 'success' | 'warning' | 'default' }> = {
  CLEAR: { label: 'CLEAR', variant: 'success' }, PENDING: { label: 'PENDING', variant: 'warning' }, INCOMPLETE: { label: 'Belum lengkap', variant: 'default' },
}
const STATUS_LABELS = Object.fromEntries(Object.entries(STATUS).map(([k, v]) => [k, v.label]))
const StatusBadge = ({ status }: { status: string }) => <Badge variant={STATUS[status]?.variant ?? 'default'}>{STATUS[status]?.label ?? status}</Badge>

const t = (v: string) => v.trim() || null
const d10 = (v: string | null | undefined) => (v ? String(v).slice(0, 10) : '')
const same = <F,>(a: F, b: F) => JSON.stringify(a) === JSON.stringify(b)
const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')))

/** Save / delete of one row of a sheet (/api/used-return-sheets/{sheet}). */
function useSheetApi(sheet: 'matrix' | 'other', id: number | undefined) {
  const qc = useQueryClient()
  const refresh = () => qc.invalidateQueries({ queryKey: [`used-return-sheets/${sheet}`] })
  const save = async (body: Record<string, unknown>) => {
    const r = id ? await api.put(`/api/used-return-sheets/${sheet}/${id}`, body) : await api.post(`/api/used-return-sheets/${sheet}`, body)
    refresh()
    return r.data
  }
  const remove = async () => {
    if (!id || !(await confirmDialog('Hapus baris ini?'))) return
    try {
      await api.delete(`/api/used-return-sheets/${sheet}/${id}`)
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }
  return { save, remove, refresh }
}

// ---------------------------------------------------------------- sheet "Spare Part"

type MForm = Record<string, string>
const mEmpty: MForm = { npbg_date: today(), npbg_no: '', ri_date: '', ri_no: '', note: '', ...Object.fromEntries(COMPONENTS.map(([c]) => [c, ''])) }
const mFrom = (r: MatrixRow): MForm => ({
  npbg_date: d10(r.npbg_date), npbg_no: r.npbg_no ?? '', ri_date: d10(r.ri_date), ri_no: r.ri_no ?? '', note: r.note ?? '',
  ...Object.fromEntries(COMPONENTS.map(([c]) => [c, r.components?.[c] != null ? String(r.components[c]) : ''])),
})

function MatrixTableRow({ row, canEdit, note }: { row: MatrixRow | null; canEdit: boolean; note?: RowNote }) {
  const s = useSheetApi('matrix', row?.id)
  const ed = useSheetRow<MatrixRow, MForm>({
    row, fromRow: mFrom, emptyForm: mEmpty, isSame: same, canEdit,
    invalid: (f) => (f.npbg_no.trim() || f.ri_no.trim() ? null : 'Isi No NPBG atau No RI'),
    save: (f) => s.save({
      npbg_date: f.npbg_date || null, npbg_no: t(f.npbg_no), ri_date: f.ri_date || null, ri_no: t(f.ri_no), note: t(f.note),
      components: Object.fromEntries(COMPONENTS.filter(([c]) => f[c].trim() !== '').map(([c]) => [c, num(f[c])])),
    }),
  })
  const isNew = !row
  return (
    <tr {...ed.rowProps} className={rowClass(isNew)}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td>{ed.input('npbg_date', { type: 'date', 'aria-label': 'Tgl NPBG', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((x) => ({ ...x, npbg_date: d.date ?? x.npbg_date }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.npbg_no ?? ''}</span>}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <StatusBadge status={row.status} />}</Td>
      <Td>{ed.input('ri_date', { type: 'date', 'aria-label': 'Tgl RI', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="ri" value={ed.form.ri_no} onChange={(v) => ed.change('ri_no', v)}
            onPick={(d) => ed.setForm((x) => ({ ...x, ri_date: d.date ?? x.ri_date, note: x.note || (d.note ?? '') }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.ri_no ?? ''}</span>}
      </Td>
      {COMPONENTS.map(([c, label]) => (
        <Td key={c}>
          {ed.input(c, { inputMode: 'decimal', 'aria-label': label, className: `min-w-[3.5rem] text-right tabular-nums ${Number(ed.form[c]) < 0 ? 'text-destructive' : ''}` })}
        </Td>
      ))}
      <Td className="min-w-[16rem]">{ed.area('note')}</Td>
      <Td className="px-2">{row && <NoteCell entity="used_return_matrix" rowKey={row.id} note={note} />}</Td>
      {canEdit && <Td className="text-center">{row && <RowActions onDelete={() => void s.remove()} />}</Td>}
    </tr>
  )
}

// ---------------------------------------------------------------- sheet "Spare Part Lain"

type OForm = {
  npbg_date: string; npbg_no: string; description: string; qty: string; unit: string; item_no: string; out_file_name: string
  ri_no: string; return_item_no: string; in_file_name: string; note: string
}
const oEmpty: OForm = {
  npbg_date: today(), npbg_no: '', description: '', qty: '', unit: 'PCS', item_no: '', out_file_name: '', ri_no: '', return_item_no: '', in_file_name: '', note: '',
}
const oFrom = (r: OtherRow): OForm => ({
  npbg_date: d10(r.npbg_date), npbg_no: r.npbg_no ?? '', description: r.description ?? '', qty: r.qty != null ? String(r.qty) : '', unit: r.unit ?? '',
  item_no: r.item_no != null ? String(r.item_no) : '', out_file_name: r.out_file_name ?? '', ri_no: r.ri_no ?? '',
  return_item_no: r.return_item_no != null ? String(r.return_item_no) : '', in_file_name: r.in_file_name ?? '', note: r.note ?? '',
})

function OtherTableRow({ row, canEdit, note }: { row: OtherRow | null; canEdit: boolean; note?: RowNote }) {
  const s = useSheetApi('other', row?.id)
  const ed = useSheetRow<OtherRow, OForm>({
    row, fromRow: oFrom, emptyForm: oEmpty, isSame: same, canEdit,
    invalid: (f) => (f.description.trim() || f.npbg_no.trim() ? null : 'Isi Deskripsi Barang atau No NPBG'),
    save: (f) => s.save({
      npbg_date: f.npbg_date || null, npbg_no: t(f.npbg_no), description: t(f.description), qty: num(f.qty), unit: t(f.unit),
      item_no: f.item_no ? Number(f.item_no) : null, out_file_name: t(f.out_file_name), ri_no: t(f.ri_no),
      return_item_no: f.return_item_no ? Number(f.return_item_no) : null, in_file_name: t(f.in_file_name), note: t(f.note),
    }),
  })
  const isNew = !row
  return (
    <tr {...ed.rowProps} className={rowClass(isNew)}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td>{ed.input('npbg_date', { type: 'date', 'aria-label': 'Tgl NPBG', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((x) => ({
              ...x, npbg_date: d.date ?? x.npbg_date, description: x.description || (d.lines[0]?.description ?? ''),
              unit: d.lines[0]?.unit ?? x.unit, qty: x.qty || String(d.lines[0]?.qty ?? ''),
            }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.npbg_no ?? ''}</span>}
      </Td>
      <Td className="min-w-[15rem]">
        {ed.area('description', { placeholder: isNew ? 'Ketik barang…' : '' })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td>{ed.input('qty', { inputMode: 'decimal', 'aria-label': 'Kuantitas', className: 'min-w-[4rem] text-right tabular-nums' })}</Td>
      <Td>{ed.input('unit', { 'aria-label': 'Satuan', className: 'min-w-[4.5rem]' })}</Td>
      <Td>{ed.input('item_no', { type: 'number', min: 1, 'aria-label': 'Item No', className: 'min-w-[4rem] text-right' })}</Td>
      <Td className="min-w-[11rem]">{ed.list('out_file_name', row?.helper_out ? [row.helper_out] : [], { placeholder: row?.helper_out ?? '', className: 'text-xs' })}</Td>
      <Td>{row && <CompactPhotoSlot entity="used_return_line" entityId={row.id} slot="out" label="Foto Keluar" photo={row.photos?.out} canEdit={canEdit} onChanged={s.refresh} />}</Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <StatusBadge status={row.status} />}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="ri" value={ed.form.ri_no} onChange={(v) => ed.change('ri_no', v)}
            onPick={(d) => ed.setForm((x) => ({ ...x, note: x.note || (d.note ?? '') }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.ri_no ?? ''}</span>}
      </Td>
      <Td>{ed.input('return_item_no', { type: 'number', min: 1, 'aria-label': 'Item No2', className: 'min-w-[4rem] text-right' })}</Td>
      <Td className="min-w-[11rem]">{ed.list('in_file_name', row?.helper_in ? [row.helper_in] : [], { placeholder: row?.helper_in ?? '', className: 'text-xs' })}</Td>
      <Td>{row && <CompactPhotoSlot entity="used_return_line" entityId={row.id} slot="in" label="Foto Terima" photo={row.photos?.in} canEdit={canEdit} onChanged={s.refresh} />}</Td>
      <Td className="min-w-[15rem]">{ed.area('note')}</Td>
      <Td className="px-2">{row && <NoteCell entity="used_return_other" rowKey={row.id} note={note} />}</Td>
      {canEdit && <Td className="text-center">{row && <RowActions onDelete={() => void s.remove()} />}</Td>}
    </tr>
  )
}

/** One sheet as a page section (used by UsedReturnPage's tabs). */
export function UsedReturnSheet({ sheet, headerExtra }: { sheet: 'matrix' | 'other'; headerExtra: ReactNode }) {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('used_return.update')
  const common = { canEdit, rowKey: (r: { id: number }) => r.id, statuses: ['PENDING', 'CLEAR', 'INCOMPLETE'], statusLabels: STATUS_LABELS }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Pengembalian Bekas"
        subtitle={sheet === 'matrix'
 ? 'Sesuai Excel Tracking Pengembalian Bekas sheet Spare Part (surplus / shortage per komponen, minus = kurang). Ketik langsung di tabel, tersimpan otomatis.'
 : 'Sesuai Excel Tracking Pengembalian Bekas sheet Spare Part Lain (per barang, foto keluar / terima). Ketik langsung di tabel, tersimpan otomatis.'}
        icon={<Recycle className="size-5" />}
      />
      {headerExtra}
      {sheet === 'matrix' ? (
        <InlineSheet<MatrixRow> key="matrix" base="used-return-sheets/matrix" notesEntity="used_return_matrix" {...common}
          headers={['No', 'Tgl NPBG', 'No NPBG', 'Status', 'Tgl RI', 'No RI', ...COMPONENTS.map(([, l]) => l), 'Keterangan']}
          searchPlaceholder="Cari NPBG, RI, keterangan…"
          hint={<>Status ikut Excel: <b>PENDING</b> = No NPBG ada; <b>CLEAR</b> = No RI diisi.</>}
          renderRow={(r, note) => <MatrixTableRow row={r} canEdit={canEdit} note={note} />}
          newRow={canEdit ? <MatrixTableRow key="new" row={null} canEdit /> : null} />
      ) : (
        <InlineSheet<OtherRow> key="other" base="used-return-sheets/other" notesEntity="used_return_other" {...common}
          headers={['No', 'Tgl NPBG', 'No NPBG', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Item No', 'Nama File (#.ext)', 'Foto Keluar', 'Status',
            'No RI', 'Item No2', 'Nama File (#.ext2)', 'Foto Terima', 'Keterangan']}
          searchPlaceholder="Cari NPBG, RI, barang, keterangan…"
          hint={<>Status ikut Excel: <b>PENDING</b> = No NPBG ada; <b>CLEAR</b> = No RI + Foto Terima.</>}
          renderRow={(r, note) => <OtherTableRow row={r} canEdit={canEdit} note={note} />}
          newRow={canEdit ? <OtherTableRow key="new" row={null} canEdit /> : null} />
      )}
    </div>
  )
}
