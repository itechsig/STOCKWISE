import { Wrench } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, apiErrorMessage } from '@/lib/api'
import { useTrackingList, type MaintenanceLine, type MaintenanceRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { CompactPhotoSlot, ExcelStatusBadge } from '@/components/tracking/EvidencePhoto'
import { InlineSaveBadge } from '@/components/tracking/InlineSaveBadge'
import { InlineSheet, RowActions, Td, rowClass, today, useSheetRow } from '@/components/tracking/InlineSheet'
import { NoteCell, type RowNote } from '@/components/RowNotes'
import { PageHeader } from '@/components/PageHeader'
import { confirmDialog } from '@/components/ui/confirm'

/**
 * Maintenance Assets = the Excel "6. Tracking Maintenance Assets" (sheet
 * "Maintenance Kendaraan"): one table row = one Sub SPK, same columns, same
 * status formula. Same as STPP: typed straight into the table, saved by itself;
 * the blue row starts a new No. SPK, "+" on a row adds the next Sub SPK to it.
 */

const ENTITY = 'maintenance_sub'
const fmtDate = (v: string | null | undefined) => (v ? new Date(v).toLocaleDateString('id-ID') : '—')
const STATUS_HINT: Record<string, string> = {
  REQUESTED: 'Permintaan sudah ada',
 ON_GOING: 'No. NPBG sudah ada sedang dikerjakan',
  COMPLETED: 'No. NPBG, foto sesudah & tgl selesai lengkap',
  INCOMPLETE: 'Permintaan belum ada',
}
const STATUS_LABELS = { REQUESTED: 'REQUESTED', ON_GOING: 'ON-GOING', COMPLETED: 'COMPLETED', INCOMPLETE: 'Belum lengkap' }
// Excel "Dropdown List": Bengkel
const WORKSHOPS = ['SIG', 'CDO', '-', 'Bengkel Ambon', 'JAYA MAKMUR (PAK YUSUF)', 'KLINIK MOTOR']

type Options = { nopols: string[]; workshops: string[] }
function useOptions() {
  return useQuery({
    queryKey: ['maintenance-options'],
    staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: Options }>('/api/maintenance-lines/options')).data.data,
  }).data
}

/** Excel row 4: today's date and the next No. SPK. */
function useSheetHead() {
  const { data } = useTrackingList<MaintenanceLine>('maintenance-lines', { per_page: 1 })
  const meta = data?.meta as { today?: string; next_number?: string } | undefined
  return { today: meta?.today ?? today(), next: meta?.next_number }
}

type Form = {
  report_date: string; nopol_raw: string; problem_detail: string; workshop_raw: string; request_note: string; npbg_no: string
  finish_date: string; result_note: string
}
const emptyForm: Form = { report_date: today(), nopol_raw: '', problem_detail: '', workshop_raw: 'SIG', request_note: '', npbg_no: '', finish_date: '', result_note: '' }
const fromRow = (r: MaintenanceLine): Form => ({
  report_date: r.report_date ?? '', nopol_raw: r.nopol ?? '', problem_detail: r.problem_detail ?? '', workshop_raw: r.workshop ?? '',
  request_note: r.request_note ?? '', npbg_no: r.npbg_no ?? '', finish_date: r.finish_date ?? '', result_note: r.result_note ?? '',
})
const same = (a: Form, b: Form) => JSON.stringify(a) === JSON.stringify(b)
const invalid = (f: Form) => (f.nopol_raw.trim() ? null : 'Isi Nopol')
const toBody = (f: Form) => Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v.trim() || null]))

function MaintenanceTableRow({ row, canEdit, note, opts }: {
  row: MaintenanceLine | null; canEdit: boolean; note?: RowNote; opts?: Options
}) {
  const qc = useQueryClient()
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['maintenance-lines'] })
    qc.invalidateQueries({ queryKey: ['maintenance-orders'] })
    qc.invalidateQueries({ queryKey: ['maintenance-options'] })
  }
  const ed = useSheetRow<MaintenanceLine, Form>({
    row, fromRow, emptyForm, isSame: same, invalid, canEdit,
    save: async (f) => {
      const body = toBody(f)
      const r = row
        ? await api.put(`/api/maintenance-subs/${row.line_id}`, body)
        : await api.post('/api/maintenance-orders', { nopol_raw: body.nopol_raw, report_date: body.report_date, problem_summary: body.problem_detail, lines: [body] })
      refresh()
      return r.data
    },
  })
  const isNew = !row

  async function addSub() {
    if (!row) return
    try {
      await api.post(`/api/maintenance-orders/${row.id}/subs`, { nopol_raw: row.nopol, report_date: today(), workshop_raw: row.workshop })
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }
  async function remove() {
    if (!row || !(await confirmDialog(`Hapus ${row.number} ${row.sub_no}?`))) return
    try {
      const order = (await api.delete<{ data: MaintenanceRow }>(`/api/maintenance-subs/${row.line_id}`)).data.data
      // the last Sub SPK gone: the No. SPK itself goes too
      if ((order.subs ?? []).length === 0) await api.delete(`/api/maintenance-orders/${row.id}`)
      refresh()
    } catch (e) {
      window.alert(apiErrorMessage(e))
    }
  }

  return (
    <tr {...ed.rowProps} className={rowClass(isNew)}>
      <Td className="px-2 py-2 text-right tabular-nums text-muted-foreground">{row?.no ?? ''}</Td>
      <Td>{ed.input('report_date', { type: 'date', 'aria-label': 'Tgl Laporan', className: 'min-w-[8rem]' })}</Td>
      <Td className="whitespace-nowrap px-2 py-2 font-mono text-xs font-medium">{row?.number ?? <span className="text-muted-foreground">otomatis</span>}</Td>
      <Td className="whitespace-nowrap px-2 py-2 font-mono text-xs">{row?.sub_no ?? 'SUB-01'}</Td>
      <Td className="min-w-[12rem]">
        {ed.list('nopol_raw', opts?.nopols ?? [], { placeholder: isNew ? 'Nopol SPK baru…' : '', className: 'uppercase' })}
        <div className="px-2"><InlineSaveBadge state={ed.state} error={ed.error} hint={ed.hint} /></div>
      </Td>
      <Td className="min-w-[15rem]">{ed.area('problem_detail', { placeholder: isNew ? 'mis. REKONDISI ULANG BAK BELAKANG' : '' })}</Td>
      <Td>
        <div className="flex items-center gap-1">{row && <CompactPhotoSlot entity={ENTITY} entityId={row.line_id} slot="before" label="Foto Sebelum" photo={row.photos.before} canEdit={canEdit} onChanged={refresh} />}
        {row?.before_file_name && !row.photos.before && <span className="text-[11px] text-muted-foreground" title={row.before_file_name}>tanpa file</span>}</div>
      </Td>
      <Td className="min-w-[9rem]">{ed.list('workshop_raw', [...WORKSHOPS, ...(opts?.workshops ?? [])])}</Td>
      <Td className="min-w-[12rem]"><div className="flex items-center gap-1"><div className="min-w-0 flex-1">
        {ed.input('request_note', { 'aria-label': 'Permintaan', placeholder: 'teks / foto' })}
        </div>{row && <CompactPhotoSlot entity={ENTITY} entityId={row.line_id} slot="request" label="Permintaan" photo={row.photos.request} canEdit={canEdit} onChanged={refresh} />}</div>
      </Td>
      <Td className="whitespace-nowrap px-2 py-2 text-center">{row && <ExcelStatusBadge status={row.status} hint={STATUS_HINT[row.status]} />}</Td>
      <Td className="min-w-[12rem]">
        {canEdit ? (
          <DocNumberInput compact type="npbg" value={ed.form.npbg_no} onChange={(v) => ed.change('npbg_no', v)}
            onPick={(d) => ed.setForm((s) => ({ ...s, problem_detail: s.problem_detail || (d.note ?? ''), nopol_raw: s.nopol_raw || (d.party ?? '') }))} />
 ) : <span className="block whitespace-nowrap px-2 py-1.5 font-mono text-xs">{row?.npbg_no ?? ''}</span>}
      </Td>
      <Td>
        <div className="flex items-center gap-1">{row && <CompactPhotoSlot entity={ENTITY} entityId={row.line_id} slot="after" label="Foto Sesudah" photo={row.photos.after} canEdit={canEdit} onChanged={refresh} />}
        {row?.after_file_name && !row.photos.after && <span className="text-[11px] text-muted-foreground" title={row.after_file_name}>tanpa file</span>}</div>
      </Td>
      <Td>{ed.input('finish_date', { type: 'date', 'aria-label': 'Tgl Selesai Pengerjaan', className: 'min-w-[8rem]' })}</Td>
      <Td className="min-w-[14rem]">{ed.area('result_note')}</Td>
      <Td className="px-2">{row && <NoteCell entity={ENTITY} rowKey={row.line_id} note={note} />}</Td>
      {canEdit && (
        <Td className="text-center">
          {row && (
            <RowActions onAdd={() => void addSub()} addTitle={`Tambah Sub SPK ke ${row.number}`}
              onDelete={row.status !== 'COMPLETED' ? () => void remove() : undefined} deleteTitle="Hapus Sub SPK" />
          )}
        </Td>
      )}
    </tr>
  )
}

const headers = ['No', 'Tgl Laporan', 'No. SPK', 'Sub SPK', 'Nopol (Kendaraan)', 'Keterangan Awal', 'Foto Sebelum', 'Bengkel', 'Permintaan',
  'Status Hasil Pengerjaan', 'No. NPBG', 'Foto Sesudah', 'Tgl Selesai Pengerjaan', 'Keterangan Akhir']

export function MaintenancePage() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('maintenance.update')
  const canCreate = hasPermission('maintenance.create')
  const head = useSheetHead()
  const opts = useOptions()

  return (
    <div className="space-y-5">
      <PageHeader
        title="Maintenance Assets"
 subtitle="Sesuai Excel Tracking Maintenance Assets satu baris per Sub SPK. Ketik langsung di tabel, tersimpan otomatis."
        icon={<Wrench className="size-5" />}
      />
      <InlineSheet<MaintenanceLine>
        base="maintenance-lines"
        headers={headers}
        canEdit={canEdit}
        rowKey={(r) => r.line_id}
        notesEntity={ENTITY}
        statuses={['REQUESTED', 'ON_GOING', 'COMPLETED', 'INCOMPLETE']}
        statusLabels={STATUS_LABELS}
        searchPlaceholder="Cari no. SPK, nopol, keterangan, bengkel, NPBG…"
        exportDataset="maintenance"
        exportPermission="maintenance.view"
        toolbarExtra={
          <div className="flex flex-wrap gap-x-4 text-xs text-muted-foreground">
            <span>Hari ini: <b className="text-foreground">{fmtDate(head.today)}</b></span>
            {head.next && <span>No. SPK berikutnya: <b className="font-mono text-foreground">{head.next}</b></span>}
          </div>
        }
        hint={<>Status: Permintaan → <b>REQUESTED</b>, No. NPBG → <b>ON-GOING</b>, + Foto Sesudah & Tgl Selesai → <b>COMPLETED</b>. Tombol + = Sub SPK baru.</>}
        renderRow={(r, note) => <MaintenanceTableRow row={r} canEdit={canEdit} note={note} opts={opts} />}
        newRow={canCreate ? <MaintenanceTableRow key="new" row={null} canEdit opts={opts} /> : null}
      />
    </div>
  )
}
