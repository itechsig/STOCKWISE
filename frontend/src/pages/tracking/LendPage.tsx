import { Handshake } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingCreate, useTrackingDelete, useTrackingUpdate, type LendRow } from '@/features/tracking/api'
import { LEND_STATUS_LABELS, LoanStatusBadge } from '@/components/tracking/LoanExcel'
import { CompactPhotoSlot } from '@/components/tracking/EvidencePhoto'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { InlineSheet, RowActions, Td, rowClass, today, useSheetRow } from '@/components/tracking/InlineSheet'
import { NoteCell, type RowNote } from '@/components/RowNotes'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Lend = the Excel "3. Tracking Borrow & Lend", sheet Lend (barang SIG yang
 * dipinjamkan). Same as STPP: typed straight into the table, saved by itself;
 * status follows the Excel: KEMBALI once Tanda / Tgl Kembali is there, DEADLINE
 * once Tgl Pinjam + Est. Pinjam has passed, else SEDANG DIPINJAM. Rows taken from
 * Accurate: only Keperluan / Est. Pinjam are filled in here.
 */

const ENTITY = 'lend'
const PURPOSES: [string, string][] = [['RELASI', 'RELASI'], ['PROJECT', 'PROJECT'], ['INTERNAL', 'INTERNAL']]

type Form = {
  out_date: string; description_raw: string; qty: string; unit_raw: string; requester_name: string; purpose: string; est_days: string
  out_doc_no: string; condition_out: string; return_doc_no: string; return_date: string; return_note: string
}
const emptyForm: Form = {
  out_date: today(), description_raw: '', qty: '1', unit_raw: 'PCS', requester_name: '', purpose: 'RELASI', est_days: '',
  out_doc_no: '', condition_out: '', return_doc_no: '', return_date: '', return_note: '',
}
const fromRow = (r: LendRow): Form => ({
  out_date: r.out_date ?? '', description_raw: r.description === '-' ? '' : r.description ?? '', qty: String(r.qty ?? ''), unit_raw: r.unit ?? '',
  requester_name: r.requester_name ?? '', purpose: r.purpose ?? 'RELASI', est_days: r.est_days ? String(r.est_days) : '',
  out_doc_no: r.out_doc_no ?? '', condition_out: r.condition_out ?? '', return_doc_no: r.return_doc_no ?? '', return_date: r.return_date ?? '',
  return_note: r.return_note ?? '',
})
const same = (a: Form, b: Form) => JSON.stringify(a) === JSON.stringify(b)
const invalid = (f: Form) => (!f.description_raw.trim() ? 'Isi Deskripsi Barang' : Number(f.qty.replace(',', '.')) > 0 ? null : 'Kuantitas harus > 0')
const t = (v: string) => v.trim() || null
const toBody = (f: Form) => ({
  out_date: f.out_date || null, description_raw: t(f.description_raw), qty: Number(f.qty.replace(',', '.')), unit_raw: t(f.unit_raw),
  requester_name: t(f.requester_name), purpose: f.purpose || 'RELASI', est_days: f.est_days ? Number(f.est_days) : null,
  out_doc_no: t(f.out_doc_no), condition_out: t(f.condition_out), return_doc_no: t(f.return_doc_no), return_date: f.return_date || null,
  return_note: t(f.return_note),
})

function LendTableRow({ row, canEdit, canDelete, note }: { row: LendRow | null; canEdit: boolean; canDelete: boolean; note?: RowNote }) {
  const qc = useQueryClient()
  const create = useTrackingCreate<LendRow>(ENTITY)
  const update = useTrackingUpdate<LendRow>(ENTITY, row?.id ?? 0)
  const del = useTrackingDelete(ENTITY)
  const fromAccurate = row?.source === 'ACCURATE'
  const ed = useSheetRow<LendRow, Form>({
    row, fromRow, emptyForm, isSame: same, invalid, canEdit,
    save: (f) => (row ? update.mutateAsync(toBody(f)) : create.mutateAsync(toBody(f))),
  })
  const isNew = !row
  const refresh = () => qc.invalidateQueries({ queryKey: [ENTITY] })
  const lock = fromAccurate // Accurate holds everything except Keperluan / Est. Pinjam
  const editable = canEdit && !lock

  async function remove() {
    if (!row || !(await confirmDialog(`Hapus baris Lend "${row.description}"?`))) return
    try { await del.mutateAsync(row.id) } catch (e) { window.alert(apiErrorMessage(e)) }
  }

  return (
 <tr {...ed.rowProps} className={rowClass(isNew)} title={fromAccurate ? 'Dari NPBG Accurate hanya Keperluan & Est. Pinjam diisi di sini' : undefined}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td>{ed.input('out_date', { type: 'date', 'aria-label': 'Tgl Pinjam', className: 'min-w-[8rem]', locked: lock })}</Td>
      <Td className="min-w-[15rem]" title={row?.item_code ? `Kode barang ${row.item_code}` : undefined}>
        {ed.area('description_raw', { placeholder: isNew ? 'Ketik barang yang dipinjamkan…' : '', locked: lock })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td>{ed.input('qty', { inputMode: 'decimal', 'aria-label': 'Kuantitas', className: 'min-w-[4rem] text-right tabular-nums', locked: lock })}</Td>
      <Td className="min-w-[5rem]">{ed.list('unit_raw', ['PCS', 'UNIT', 'SET', 'BH', 'KG', 'M'], { locked: lock })}</Td>
      <Td className="min-w-[9rem]">{ed.input('requester_name', { 'aria-label': 'Peminta', locked: lock })}</Td>
      <Td className="min-w-[8rem]">{ed.select('purpose', PURPOSES)}</Td>
      <Td>{ed.input('est_days', { type: 'number', min: 1, 'aria-label': 'Est. Pinjam (hari)', className: 'min-w-[5rem] text-right' })}</Td>
      <Td className="min-w-[12rem]">
        {editable ? (
          <DocNumberInput compact type="npbg" value={ed.form.out_doc_no} onChange={(v) => ed.change('out_doc_no', v)}
            onPick={(d) => ed.setForm((s) => ({
              ...s, out_date: d.date ?? s.out_date, condition_out: s.condition_out || (d.note ?? ''),
              description_raw: s.description_raw || (d.lines[0]?.description ?? ''), unit_raw: d.lines[0]?.unit ?? s.unit_raw,
            }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.out_doc_no ?? ''}</span>}
      </Td>
      <Td className="min-w-[12rem]">{ed.area('condition_out', { locked: lock })}</Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <LoanStatusBadge kind="lend" status={row.status} />}</Td>
      <Td className="min-w-[14rem]"><div className="flex items-center gap-1"><div className="min-w-0 flex-1">
        {editable ? (
          <DocNumberInput compact type="ri" value={ed.form.return_doc_no} onChange={(v) => ed.change('return_doc_no', v)}
            onPick={(d) => ed.setForm((s) => ({ ...s, return_date: d.date ?? s.return_date, return_note: s.return_note || (d.note ?? '') }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.return_doc_no ?? ''}</span>}
        </div>{row && <CompactPhotoSlot entity={ENTITY} entityId={row.id} slot="in" label="Tanda Kembali" photo={row.photos?.in} canEdit={canEdit} onChanged={refresh} />}</div>
      </Td>
      <Td>
        {ed.input('return_date', { type: 'date', 'aria-label': 'Tgl Kembali', className: 'min-w-[8rem]', locked: lock })}
        {row?.condition_in && <div className="px-2 text-[11px] font-medium text-amber-700">{row.condition_in}</div>}
      </Td>
      <Td className="min-w-[12rem]">{ed.area('return_note', { locked: lock })}</Td>
      <Td className="px-2">{row && <NoteCell entity={ENTITY} rowKey={row.id} note={note} />}</Td>
      {canEdit && (
        <Td className="text-center">
          {row && canDelete && !fromAccurate && row.status !== 'RETURNED' && row.qty_returned === 0 && <RowActions onDelete={() => void remove()} />}
          {fromAccurate && <span className="text-[11px] text-muted-foreground">Accurate</span>}
        </Td>
      )}
    </tr>
  )
}

const headers = ['No', 'Tgl Pinjam', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Keperluan', 'Est. Pinjam (hari)', 'Tanda Keluar (No. NPBG)',
  'Keterangan Keluar', 'Status', 'Tanda Kembali', 'Tgl Kembali', 'Keterangan Kembali']

export function LendPage() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('lend.update')
  const canCreate = hasPermission('lend.create')

  return (
    <div className="space-y-5">
      <PageHeader
        title="Peminjaman (Lend)"
 subtitle="Sesuai Excel Tracking Borrow & Lend sheet Lend: barang SIG yang dipinjamkan. Ketik langsung di tabel, tersimpan otomatis."
        icon={<Handshake className="size-5" />}
      />
      <InlineSheet<LendRow>
        base={ENTITY}
        headers={headers}
        canEdit={canEdit}
        rowKey={(r) => r.id}
        notesEntity={ENTITY}
        statuses={['ON_LOAN', 'OVERDUE', 'RETURNED', 'PARTIAL_RETURN', 'INCOMPLETE']}
        statusLabels={LEND_STATUS_LABELS}
        searchPlaceholder="Cari barang, peminta, NPBG, RI, keterangan…"
        exportDataset="lend"
        exportPermission="lend.view"
        hint={<>Status: Est. Pinjam / Tanda Keluar → <b>SEDANG DIPINJAM</b>, Tanda / Tgl Kembali → <b>KEMBALI</b>.</>}
        renderRow={(r, note) => <LendTableRow row={r} canEdit={canEdit} canDelete={canEdit} note={note} />}
        newRow={canCreate ? <LendTableRow key="new" row={null} canEdit canDelete={false} /> : null}
      />
    </div>
  )
}
