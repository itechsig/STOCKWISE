import { ArrowLeftRight } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingCreate, useTrackingDelete, useTrackingUpdate, useVendorOptions, type BorrowRow } from '@/features/tracking/api'
import { BORROW_STATUS_LABELS, LoanStatusBadge } from '@/components/tracking/LoanExcel'
import { CompactPhotoSlot } from '@/components/tracking/EvidencePhoto'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { InlineSheet, RowActions, Td, rowClass, today, useSheetRow } from '@/components/tracking/InlineSheet'
import { NoteCell, type RowNote } from '@/components/RowNotes'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { cn } from '@/lib/utils'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Borrow = the Excel "3. Tracking Borrow & Lend", sheet Borrow (barang pihak luar
 * yang dipinjam SIG). Same as STPP: typed straight into the table, saved by itself;
 * status follows the Excel: LUNAS once Tanda Keluar is there, SEDANG DIPINJAM once
 * Tanda Terima is there. Rows taken from Accurate follow Accurate (read-only here).
 */

const ENTITY = 'borrow'

type Form = {
  borrowed_at: string; description_raw: string; qty: string; unit_raw: string; lender_name: string; condition_note: string
  in_doc_no: string; out_doc_no: string; return_note: string
}
const emptyForm: Form = {
  borrowed_at: today(), description_raw: '', qty: '1', unit_raw: 'PCS', lender_name: '', condition_note: '', in_doc_no: '', out_doc_no: '', return_note: '',
}
const fromRow = (r: BorrowRow): Form => ({
  borrowed_at: r.borrowed_at ?? '', description_raw: r.description === '-' ? '' : r.description ?? '', qty: String(r.qty ?? ''), unit_raw: r.unit ?? '',
  lender_name: r.lender_name ?? '', condition_note: r.condition_note ?? '', in_doc_no: r.in_doc_no ?? '', out_doc_no: r.out_doc_no ?? '',
  return_note: r.return_note ?? '',
})
const same = (a: Form, b: Form) => JSON.stringify(a) === JSON.stringify(b)
const invalid = (f: Form) => (!f.description_raw.trim() ? 'Isi Deskripsi Barang' : Number(f.qty.replace(',', '.')) > 0 ? null : 'Kuantitas harus > 0')
const t = (v: string) => v.trim() || null
const toBody = (f: Form) => ({
  borrowed_at: f.borrowed_at || null, description_raw: t(f.description_raw), qty: Number(f.qty.replace(',', '.')), unit_raw: t(f.unit_raw),
  lender_name: t(f.lender_name), condition_note: t(f.condition_note), in_doc_no: t(f.in_doc_no), out_doc_no: t(f.out_doc_no), return_note: t(f.return_note),
})

function BorrowTableRow({ row, canEdit, canDelete, note, vendors }: {
  row: BorrowRow | null; canEdit: boolean; canDelete: boolean; note?: RowNote; vendors: string[]
}) {
  const qc = useQueryClient()
  const create = useTrackingCreate<BorrowRow>(ENTITY)
  const update = useTrackingUpdate<BorrowRow>(ENTITY, row?.id ?? 0)
  const del = useTrackingDelete(ENTITY)
  const fromAccurate = row?.source === 'ACCURATE'
  const ed = useSheetRow<BorrowRow, Form>({
    row, fromRow, emptyForm, isSame: same, invalid, canEdit: canEdit && !fromAccurate,
    save: (f) => (row ? update.mutateAsync(toBody(f)) : create.mutateAsync(toBody(f))),
  })
  const isNew = !row
  const refresh = () => qc.invalidateQueries({ queryKey: [ENTITY] })
  const editable = canEdit && !fromAccurate

  async function remove() {
    if (!row || !(await confirmDialog(`Hapus baris Borrow "${row.description}"?`))) return
    try { await del.mutateAsync(row.id) } catch (e) { window.alert(apiErrorMessage(e)) }
  }

  return (
 <tr {...ed.rowProps} className={rowClass(isNew)} title={fromAccurate ? 'Dari RI Accurate ubah di Accurate' : undefined}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td>{ed.input('borrowed_at', { type: 'date', 'aria-label': 'Tgl Pinjam', className: 'min-w-[8rem]', locked: fromAccurate })}</Td>
      <Td className="min-w-[15rem]" title={row?.item_code ? `Kode barang ${row.item_code}` : undefined}>
        {ed.area('description_raw', { placeholder: isNew ? 'Ketik barang yang dipinjam…' : '' })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td>{ed.input('qty', { inputMode: 'decimal', 'aria-label': 'Kuantitas', className: 'min-w-[4rem] text-right tabular-nums' })}</Td>
      <Td className="min-w-[5rem]">{ed.list('unit_raw', ['PCS', 'UNIT', 'SET', 'BH', 'KG', 'M'])}</Td>
      <Td className="min-w-[10rem]">{ed.list('lender_name', vendors, { placeholder: 'Vendor' })}</Td>
      <Td className="min-w-[12rem]">{ed.area('condition_note')}</Td>
      <Td className="min-w-[14rem]"><div className="flex items-center gap-1"><div className="min-w-0 flex-1">
        {editable ? (
          <DocNumberInput compact type="ri" value={ed.form.in_doc_no} onChange={(v) => ed.change('in_doc_no', v)}
            onPick={(d) => ed.setForm((s) => ({
              ...s, borrowed_at: d.date ?? s.borrowed_at, lender_name: s.lender_name || (d.party ?? ''),
              description_raw: s.description_raw || (d.lines[0]?.description ?? ''), unit_raw: d.lines[0]?.unit ?? s.unit_raw,
            }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.in_doc_no ?? ''}</span>}
        </div>{row && <CompactPhotoSlot entity={ENTITY} entityId={row.id} slot="in" label="Tanda Terima" photo={row.photos?.in} canEdit={canEdit} onChanged={refresh} />}</div>
      </Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <LoanStatusBadge kind="borrow" status={row.status} />}</Td>
      <Td className="min-w-[14rem]"><div className="flex items-center gap-1"><div className="min-w-0 flex-1">
        {editable ? (
          <DocNumberInput compact type="npbg" value={ed.form.out_doc_no} onChange={(v) => ed.change('out_doc_no', v)} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.out_doc_no ?? ''}</span>}
        </div>{row && <CompactPhotoSlot entity={ENTITY} entityId={row.id} slot="out" label="Tanda Keluar" photo={row.photos?.out} canEdit={canEdit} onChanged={refresh} />}</div>
      </Td>
      <Td className="min-w-[12rem]">{ed.area('return_note')}</Td>
      <Td className="px-2">{row && <NoteCell entity={ENTITY} rowKey={row.id} note={note} />}</Td>
      {canEdit && (
        <Td className="text-center">
          {row && canDelete && !fromAccurate && row.status !== 'RETURNED' && row.qty_returned === 0 && <RowActions onDelete={() => void remove()} />}
          {fromAccurate && <span className={cn('text-[11px] text-muted-foreground')}>Accurate</span>}
        </Td>
      )}
    </tr>
  )
}

const headers = ['No', 'Tgl Pinjam', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Vendor', 'Keterangan', 'Tanda Terima', 'Status', 'Tanda Keluar', 'Keterangan Barang Kembali']

export function BorrowPage() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('borrow.update')
  const canCreate = hasPermission('borrow.create')
  const vendors = (useVendorOptions().data ?? []).map((v) => v.name)

  return (
    <div className="space-y-5">
      <PageHeader
        title="Pinjam Luar (Borrow)"
 subtitle="Sesuai Excel Tracking Borrow & Lend sheet Borrow: barang pihak luar yang dipinjam SIG. Ketik langsung di tabel, tersimpan otomatis."
        icon={<ArrowLeftRight className="size-5" />}
      />
      <InlineSheet<BorrowRow>
        base={ENTITY}
        headers={headers}
        canEdit={canEdit}
        rowKey={(r) => r.id}
        notesEntity={ENTITY}
        statuses={['BORROWED', 'RETURNED', 'PARTIAL', 'INCOMPLETE']}
        statusLabels={BORROW_STATUS_LABELS}
        searchPlaceholder="Cari barang, vendor, RI, NPBG, keterangan…"
        exportDataset="borrow"
        exportPermission="borrow.view"
        hint={<>Status: Tanda Terima → <b>SEDANG DIPINJAM</b>, Tanda Keluar → <b>LUNAS</b>.</>}
        renderRow={(r, note) => <BorrowTableRow row={r} canEdit={canEdit} canDelete={canEdit} note={note} vendors={vendors} />}
        newRow={canCreate ? <BorrowTableRow key="new" row={null} canEdit canDelete={false} vendors={vendors} /> : null}
      />
    </div>
  )
}
