import { useState } from 'react'
import { Truck } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, apiErrorMessage } from '@/lib/api'
import { useTrackingCreate, useTrackingUpdate, type TyreBpnRow, type TyreBpnTransferRow, type TyreRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { CompactPhotoSlot } from '@/components/tracking/EvidencePhoto'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { InlineSheet, RowActions, SheetTabs, Td, rowClass, today, useSheetRow } from '@/components/tracking/InlineSheet'
import { NoteCell, type RowNote } from '@/components/RowNotes'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Ban Luar = the Excel "5. Tracking Ban Luar": sheet "Ban Luar" (one tyre per
 * row, Foto Ban Out / In), "Ban Luar BPN" and "Deliver & Receive Ban SIG-BPN".
 * Same as STPP: typed straight into the table, saved by itself.
 */

const STATUS: Record<string, { label: string; variant: 'default' | 'success' | 'warning'; hint: string }> = {
  PENDING_RI: { label: 'PENDING RI', variant: 'warning', hint: 'Ban baru sudah keluar (NPBG + no. seri baru), ban lama belum kembali' },
  CLEAR: { label: 'CLEAR', variant: 'success', hint: 'Ban lama sudah kembali (RI + no. seri lama)' },
  INCOMPLETE: { label: 'Belum lengkap', variant: 'default', hint: 'No. NPBG / no. seri baru belum diisi' },
}
const STATUS_LABELS = Object.fromEntries(Object.entries(STATUS).map(([k, v]) => [k, v.label]))

function StatusBadge({ status }: { status: string }) {
  const s = STATUS[status] ?? { label: status, variant: 'default' as const, hint: '' }
  return <Badge variant={s.variant} title={s.hint}>{s.label}</Badge>
}

type Options = { nopols: string[]; new_descs: string[]; old_descs: string[]; serials: string[] }
function useOptions() {
  return useQuery({
    queryKey: ['tyre-options'],
    staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: Options }>('/api/tyre-changes/options')).data.data,
  }).data
}

const t = (v: string) => v.trim() || null
const d10 = (v: string | null | undefined) => (v ? String(v).slice(0, 10) : '')
const same = <F,>(a: F, b: F) => JSON.stringify(a) === JSON.stringify(b)

// ---------------------------------------------------------------- sheet "Ban Luar"

type Form = {
  nopol_raw: string; change_date: string; npbg_no: string; new_tyre_desc: string; new_serial_raw: string; tyre_no: string; replacement_no: string
  out_note: string; in_date: string; ri_no: string; old_tyre_desc: string; old_serial_raw: string; in_note: string
}
const emptyForm: Form = {
  nopol_raw: '', change_date: today(), npbg_no: '', new_tyre_desc: '', new_serial_raw: '', tyre_no: '', replacement_no: '', out_note: '',
  in_date: '', ri_no: '', old_tyre_desc: '', old_serial_raw: '', in_note: '',
}
const fromRow = (r: TyreRow): Form => ({
  nopol_raw: r.nopol ?? '', change_date: d10(r.change_date), npbg_no: r.out_npbg ?? '', new_tyre_desc: r.new_tyre_desc ?? '',
  new_serial_raw: r.new_serial_raw ?? '', tyre_no: r.tyre_no ? String(r.tyre_no) : '', replacement_no: r.replacement_no ? String(r.replacement_no) : '',
  out_note: r.out_note ?? '', in_date: d10(r.in_date), ri_no: r.in_ri ?? '', old_tyre_desc: r.old_tyre_desc ?? '', old_serial_raw: r.old_serial_raw ?? '',
  in_note: r.in_note ?? '',
})
const invalid = (f: Form) => (f.nopol_raw.trim() ? null : 'Isi Nopol')
function toBody(f: Form, isNew: boolean) {
  const body: Record<string, unknown> = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, t(v)]))
  body.change_date = f.change_date || null
  body.in_date = f.in_date || null
  body.tyre_no = f.tyre_no ? Number(f.tyre_no) : null
  body.replacement_no = f.replacement_no ? Number(f.replacement_no) : null
  // "ORIGIN" in No NPBG = pendataan awal (a tyre already fitted), as in the Excel
  if (isNew) body.is_opening = f.npbg_no.trim().toUpperCase() === 'ORIGIN'
  return body
}

function TyreTableRow({ row, canEdit, canDelete, note, opts }: { row: TyreRow | null; canEdit: boolean; canDelete: boolean; note?: RowNote; opts?: Options }) {
  const qc = useQueryClient()
  const create = useTrackingCreate<TyreRow>('tyre-changes')
  const update = useTrackingUpdate<TyreRow>('tyre-changes', row?.id ?? 0)
  const fromAccurate = row?.source === 'ACCURATE'
  const ed = useSheetRow<TyreRow, Form>({
    row, fromRow, emptyForm, isSame: same, invalid, canEdit,
    save: async (f) => {
      const r = row ? await update.mutateAsync(toBody(f, false)) : await create.mutateAsync(toBody(f, true))
      qc.invalidateQueries({ queryKey: ['tyre-options'] })
      return r
    },
  })
  const isNew = !row
  const lock = fromAccurate // NPBG / RI / keterangan / nopol / tanggal follow Accurate
  const refresh = () => qc.invalidateQueries({ queryKey: ['tyre-changes'] })

  async function remove() {
    if (!row || !(await confirmDialog(`Hapus data ban ${row.nopol ?? ''} ${row.new_serial_raw ?? ''}?`))) return
    try {
      await api.delete(`/api/tyre-changes/${row.id}`)
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }

  return (
 <tr {...ed.rowProps} className={rowClass(isNew)} title={fromAccurate ? 'Dari Accurate yang bisa dilengkapi: ban, pergantian, deskripsi & no. seri' : undefined}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td className="min-w-[12rem]">
        {ed.list('nopol_raw', opts?.nopols ?? [], { placeholder: isNew ? 'DE 1234 AB' : '', className: 'uppercase', locked: lock })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td>{ed.input('change_date', { type: 'date', 'aria-label': 'Tgl NPBG', className: 'min-w-[8rem]', locked: lock })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit && !lock ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((s) => ({
              ...s, change_date: d.date ?? s.change_date, nopol_raw: s.nopol_raw || (d.party ?? ''), out_note: s.out_note || (d.note ?? ''),
              new_tyre_desc: s.new_tyre_desc || (d.lines.find((l) => /BAN LUAR/i.test(l.description ?? ''))?.description ?? ''),
            }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.out_npbg ?? ''}</span>}
      </Td>
      <Td className="min-w-[14rem]">{ed.list('new_tyre_desc', opts?.new_descs ?? [])}</Td>
      <Td className="min-w-[8rem]">{ed.list('new_serial_raw', opts?.serials ?? [], { className: 'font-mono' })}</Td>
      <Td><div className="flex items-center gap-1">{row && <CompactPhotoSlot entity="tyre" entityId={row.id} slot="out" label="Foto Ban (Out)" photo={row.photos.out} canEdit={canEdit} onChanged={refresh} />}
        {row?.new_file_name && !row.photos.out && <span className="text-[11px] text-muted-foreground" title={row.new_file_name}>tanpa file</span>}</div></Td>
      <Td>{ed.input('tyre_no', { type: 'number', min: 1, 'aria-label': 'Ban', className: 'min-w-[3.5rem] text-right' })}</Td>
      <Td>{ed.input('replacement_no', { type: 'number', min: 1, 'aria-label': 'Pergantian', className: 'min-w-[3.5rem] text-right' })}</Td>
      <Td className="min-w-[12rem]">{ed.area('out_note', { locked: lock })}</Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <StatusBadge status={row.status} />}</Td>
      <Td>{ed.input('in_date', { type: 'date', 'aria-label': 'Tgl RI', className: 'min-w-[8rem]', locked: lock })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit && !lock ? (
          <DocNumberInput compact type="ri" value={ed.form.ri_no} onChange={(v) => ed.change('ri_no', v)}
            onPick={(d) => ed.setForm((s) => ({
              ...s, in_date: d.date ?? s.in_date, in_note: s.in_note || (d.note ?? ''), old_tyre_desc: s.old_tyre_desc || (d.lines[0]?.description ?? ''),
            }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.in_ri ?? ''}</span>}
      </Td>
      <Td className="min-w-[14rem]">{ed.list('old_tyre_desc', opts?.old_descs ?? [])}</Td>
      <Td className="min-w-[8rem]">{ed.list('old_serial_raw', opts?.serials ?? [], { className: 'font-mono' })}</Td>
      <Td><div className="flex items-center gap-1">{row && <CompactPhotoSlot entity="tyre" entityId={row.id} slot="in" label="Foto Ban (In)" photo={row.photos.in} canEdit={canEdit} onChanged={refresh} />}
        {row?.old_file_name && !row.photos.in && <span className="text-[11px] text-muted-foreground" title={row.old_file_name}>tanpa file</span>}</div></Td>
      <Td className="min-w-[12rem]">{ed.area('in_note', { locked: lock })}</Td>
      <Td className="px-2">{row && <NoteCell entity="tyre-changes" rowKey={row.id} note={note} />}</Td>
      {canEdit && (
        <Td className="text-center">
          {row && canDelete && !fromAccurate && row.status !== 'CLEAR' && <RowActions onDelete={() => void remove()} />}
          {fromAccurate && <span className="text-[11px] text-muted-foreground">Accurate</span>}
        </Td>
      )}
    </tr>
  )
}

const banHeaders = ['No', 'Nopol (Kendaraan)', 'Tgl NPBG', 'No NPBG', 'Deskripsi Ban Baru', 'No Seri Baru', 'Foto Ban (Out)', 'Ban', 'Pergantian',
  'Keterangan Keluar', 'Status', 'Tgl RI', 'No. RI', 'Deskripsi Ban Lama', 'No Seri Lama', 'Foto Ban (In)', 'Keterangan Kembali']

function BanSheet() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('tyre.update')
  const canCreate = hasPermission('tyre.create')
  const opts = useOptions()
  return (
    <InlineSheet<TyreRow>
      base="tyre-changes"
      headers={banHeaders}
      canEdit={canEdit}
      rowKey={(r) => r.id}
      notesEntity="tyre-changes"
      statuses={['PENDING_RI', 'CLEAR', 'INCOMPLETE']}
      statusLabels={STATUS_LABELS}
      searchPlaceholder="Cari nopol, ban, no seri, NPBG, RI…"
      exportDataset="tyre"
      exportPermission="tyre.view"
      hint={<>No NPBG <b>ORIGIN</b> = pendataan awal. Isi No. RI / Tgl RI → <b>CLEAR</b>.</>}
      renderRow={(r, note) => <TyreTableRow row={r} canEdit={canEdit} canDelete={canEdit} note={note} opts={opts} />}
      newRow={canCreate ? <TyreTableRow key="new" row={null} canEdit canDelete={false} opts={opts} /> : null}
    />
  )
}

// ---------------------------------------------------------------- sheets "Ban Luar BPN" & "Deliver & Receive Ban SIG-BPN"

type BpnForm = { cutoff_date: string; nopol_raw: string; tyre_desc: string; serial_raw: string; note: string }
const bpnEmpty: BpnForm = { cutoff_date: today(), nopol_raw: '', tyre_desc: '', serial_raw: '', note: '' }
const bpnFrom = (r: TyreBpnRow): BpnForm => ({
  cutoff_date: d10(r.cutoff_date), nopol_raw: r.nopol_raw ?? '', tyre_desc: r.tyre_desc ?? '', serial_raw: r.serial_raw ?? '', note: r.note ?? '',
})

type TrfForm = {
  nopol_raw: string; npbg_date: string; npbg_no: string; new_tyre_desc: string; new_serial_raw: string; out_note: string
  ri_date: string; ri_no: string; old_tyre_desc: string; old_serial_raw: string; in_note: string
}
const trfEmpty: TrfForm = {
  nopol_raw: '', npbg_date: today(), npbg_no: '', new_tyre_desc: '', new_serial_raw: '', out_note: '', ri_date: '', ri_no: '', old_tyre_desc: '', old_serial_raw: '', in_note: '',
}
const trfFrom = (r: TyreBpnTransferRow): TrfForm => ({
  nopol_raw: r.nopol_raw ?? '', npbg_date: d10(r.npbg_date), npbg_no: r.npbg_no ?? '', new_tyre_desc: r.new_tyre_desc ?? '', new_serial_raw: r.new_serial_raw ?? '',
  out_note: r.out_note ?? '', ri_date: d10(r.ri_date), ri_no: r.ri_no ?? '', old_tyre_desc: r.old_tyre_desc ?? '', old_serial_raw: r.old_serial_raw ?? '', in_note: r.in_note ?? '',
})

/** Save / delete of one BPN sheet row (both sheets share /api/tyre-bpn/{sheet}). */
function useBpnSave(sheet: 'inventory' | 'transfers', id: number | undefined) {
  const qc = useQueryClient()
  const refresh = () => qc.invalidateQueries({ queryKey: [`tyre-bpn/${sheet}`] })
  const save = async (f: Record<string, string>) => {
    const body = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, t(v)]))
    const r = id ? await api.put(`/api/tyre-bpn/${sheet}/${id}`, body) : await api.post(`/api/tyre-bpn/${sheet}`, body)
    refresh()
    return r.data
  }
  const remove = async () => {
    if (!id || !(await confirmDialog('Hapus baris ini?'))) return
    try {
      await api.delete(`/api/tyre-bpn/${sheet}/${id}`)
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }
  return { save, remove, refresh }
}

function BpnRow({ row, canEdit, note, opts }: { row: TyreBpnRow | null; canEdit: boolean; note?: RowNote; opts?: Options }) {
  const s = useBpnSave('inventory', row?.id)
  const ed = useSheetRow<TyreBpnRow, BpnForm>({
    row, fromRow: bpnFrom, emptyForm: bpnEmpty, isSame: same, canEdit, save: s.save,
    invalid: (f) => (f.nopol_raw.trim() || f.serial_raw.trim() ? null : 'Isi Nopol atau No Seri'),
  })
  const isNew = !row
  return (
    <tr {...ed.rowProps} className={rowClass(isNew)}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td>{ed.input('cutoff_date', { type: 'date', 'aria-label': 'Tanggal Cut Off', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {ed.list('nopol_raw', opts?.nopols ?? [], { placeholder: isNew ? 'Nopol baru…' : '', className: 'uppercase' })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td className="min-w-[14rem]">{ed.list('tyre_desc', opts?.new_descs ?? [])}</Td>
      <Td className="min-w-[8rem]">{ed.list('serial_raw', opts?.serials ?? [], { className: 'font-mono' })}</Td>
      <Td>{row && <CompactPhotoSlot entity="tyre_bpn" entityId={row.id} slot="photo" label="Foto" photo={row.photos.photo} canEdit={canEdit} onChanged={s.refresh} />}</Td>
      <Td className="min-w-[14rem]">{ed.area('note')}</Td>
      <Td className="px-2">{row && <NoteCell entity="tyre_bpn" rowKey={row.id} note={note} />}</Td>
      {canEdit && <Td className="text-center">{row && <RowActions onDelete={() => void s.remove()} />}</Td>}
    </tr>
  )
}

function TransferRow({ row, canEdit, note, opts }: { row: TyreBpnTransferRow | null; canEdit: boolean; note?: RowNote; opts?: Options }) {
  const s = useBpnSave('transfers', row?.id)
  const ed = useSheetRow<TyreBpnTransferRow, TrfForm>({
    row, fromRow: trfFrom, emptyForm: trfEmpty, isSame: same, canEdit, save: s.save,
    invalid: (f) => (f.nopol_raw.trim() || f.npbg_no.trim() ? null : 'Isi Nopol atau No NPBG'),
  })
  const isNew = !row
  return (
    <tr {...ed.rowProps} className={rowClass(isNew)}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td className="min-w-[12rem]">
        {ed.list('nopol_raw', opts?.nopols ?? [], { placeholder: isNew ? 'Nopol baru…' : '', className: 'uppercase' })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td>{ed.input('npbg_date', { type: 'date', 'aria-label': 'Tgl NPBG', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((x) => ({ ...x, npbg_date: d.date ?? x.npbg_date, out_note: x.out_note || (d.note ?? ''),
              new_tyre_desc: x.new_tyre_desc || (d.lines[0]?.description ?? '') }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.npbg_no ?? ''}</span>}
      </Td>
      <Td className="min-w-[14rem]">{ed.list('new_tyre_desc', opts?.new_descs ?? [])}</Td>
      <Td className="min-w-[8rem]">{ed.list('new_serial_raw', opts?.serials ?? [], { className: 'font-mono' })}</Td>
      <Td>{row && <CompactPhotoSlot entity="tyre_bpn_transfer" entityId={row.id} slot="out" label="Foto Ban (kirim)" photo={row.photos.out} canEdit={canEdit} onChanged={s.refresh} />}</Td>
      <Td className="min-w-[12rem]">{ed.area('out_note')}</Td>
      <Td>{ed.input('ri_date', { type: 'date', 'aria-label': 'Tgl RI', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="ri" value={ed.form.ri_no} onChange={(v) => ed.change('ri_no', v)}
            onPick={(d) => ed.setForm((x) => ({ ...x, ri_date: d.date ?? x.ri_date, in_note: x.in_note || (d.note ?? ''),
              old_tyre_desc: x.old_tyre_desc || (d.lines[0]?.description ?? '') }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.ri_no ?? ''}</span>}
      </Td>
      <Td className="min-w-[14rem]">{ed.list('old_tyre_desc', opts?.old_descs ?? [])}</Td>
      <Td className="min-w-[8rem]">{ed.list('old_serial_raw', opts?.serials ?? [], { className: 'font-mono' })}</Td>
      <Td>{row && <CompactPhotoSlot entity="tyre_bpn_transfer" entityId={row.id} slot="in" label="Foto Ban (bekas)" photo={row.photos.in} canEdit={canEdit} onChanged={s.refresh} />}</Td>
      <Td className="min-w-[12rem]">{ed.area('in_note')}</Td>
      <Td className="px-2">{row && <NoteCell entity="tyre_bpn_transfer" rowKey={row.id} note={note} />}</Td>
      {canEdit && <Td className="text-center">{row && <RowActions onDelete={() => void s.remove()} />}</Td>}
    </tr>
  )
}

// ---------------------------------------------------------------- page

type Sheet = 'ban' | 'bpn' | 'transfer'
const TABS = [['ban', 'Ban Luar'], ['bpn', 'Ban Luar BPN'], ['transfer', 'Deliver & Receive Ban SIG-BPN']] as const
const SUBTITLE: Record<Sheet, string> = {
 ban: 'Sesuai Excel Tracking Ban Luar satu baris per ban; data baru dari Accurate masuk otomatis.',
 bpn: 'Sesuai Excel Tracking Ban Luar sheet Ban Luar BPN (data utama ban armada SIG-BPN).',
 transfer: 'Sesuai Excel Tracking Ban Luar sheet Deliver & Receive Ban SIG-BPN.',
}

export function TyrePage() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('tyre.update')
  const opts = useOptions()
  const [sheet, setSheet] = useState<Sheet>('ban')

  return (
    <div className="space-y-5">
      <PageHeader title="Ban Luar" subtitle={`${SUBTITLE[sheet]} Ketik langsung di tabel, tersimpan otomatis.`} icon={<Truck className="size-5" />} />
      <SheetTabs tabs={TABS} value={sheet} onChange={setSheet} />
      {sheet === 'ban' && <BanSheet />}
      {sheet === 'bpn' && (
        <InlineSheet<TyreBpnRow> key="bpn" base="tyre-bpn/inventory" notesEntity="tyre_bpn" canEdit={canEdit} rowKey={(r) => r.id}
          headers={['NO', 'TANGGAL CUT OFF', 'NOPOL', 'DESKRIPSI BAN', 'NO SERI', 'Foto', 'KETERANGAN']}
          searchPlaceholder="Cari nopol, ban, no seri…"
          renderRow={(r, note) => <BpnRow row={r} canEdit={canEdit} note={note} opts={opts} />}
          newRow={canEdit ? <BpnRow key="new" row={null} canEdit opts={opts} /> : null} />
      )}
      {sheet === 'transfer' && (
        <InlineSheet<TyreBpnTransferRow> key="transfer" base="tyre-bpn/transfers" notesEntity="tyre_bpn_transfer" canEdit={canEdit} rowKey={(r) => r.id}
          headers={['No', 'NOPOL', 'Tgl NPBG', 'NO NPBG', 'Deskripsi Barang', 'No seri Ban Baru', 'Foto Ban', 'Ket', 'Tgl RI', 'NO RI',
            'Deskripsi Barang', 'No seri Ban Bekas', 'Foto Ban', 'Ket']}
          searchPlaceholder="Cari nopol, NPBG, RI, no seri…"
          renderRow={(r, note) => <TransferRow row={r} canEdit={canEdit} note={note} opts={opts} />}
          newRow={canEdit ? <TransferRow key="new" row={null} canEdit opts={opts} /> : null} />
      )}
    </div>
  )
}
