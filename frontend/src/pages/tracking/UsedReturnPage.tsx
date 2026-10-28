import { useSearchParams } from 'react-router-dom'
import { useState } from 'react'
import { Pencil, Recycle, Trash2 } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import {
  useTrackingAction,
  useTrackingCreate,
  useTrackingDelete,
  useTrackingItem,
  useTrackingUpdate,
  type UsedReturnLineRow,
  type UsedReturnRow,
} from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { DocNumberInput } from '@/components/tracking/AccurateInputs'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { UsedReturnSheet } from '@/components/tracking/UsedReturnSheets'
import { cn } from '@/lib/utils'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import { useWarehouses } from '@/features/inventory/api'
import type { ItemLookupResult } from '@/features/inventory/api'
import { DocLink } from '@/components/DocLink'
import { confirmDialog } from '@/components/ui/confirm'
import { DetailFallback } from '@/components/DetailFallback'

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

// Column set/order mirrors RiListPage.tsx (features/ri/api.ts's Ri) as closely
// as the two domains allow, per explicit user instruction — RI has no
// condition/masuk-stok/status concept (it's a read-only mirror with no
// workflow) and UsedReturn has no vendor/harga/PO-link, so those swap in.
const columns: Column<UsedReturnLineRow>[] = [
  { key: 'number', header: 'No UR', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'tgl', header: 'Tgl', cell: (r) => fmtDate(r.return_date) },
  {
    key: 'sumber',
    header: 'Sumber',
    cell: (r) => (r.from_accurate ? <Badge variant="default">Accurate (RI/NV)</Badge> : <Badge variant="neutral">Manual</Badge>),
  },
  { key: 'kode', header: 'Kode Barang', cell: (r) => <span className="font-mono text-xs">{r.kode_barang ?? '—'}</span> },
  { key: 'barang', header: 'Deskripsi Barang', cell: (r) => r.deskripsi_barang ?? '—' },
  { key: 'qty', header: 'Kuantitas', cell: (r) => (r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—') },
  { key: 'cond', header: 'Kondisi', cell: (r) => <Badge variant="neutral">{r.condition}</Badge> },
  { key: 'stock', header: 'Masuk Stok', cell: (r) => (r.into_stock ? 'Ya' : 'Tidak') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

interface Line {
  key: string
  item_id?: number
  code?: string
  description: string
  qty: number
  condition: string
  into_stock: boolean
}

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useTrackingCreate<UsedReturnRow>('used-returns')
  const [npbgRef, setNpbgRef] = useState('')
  const [lines, setLines] = useState<Line[]>([])
  const [err, setErr] = useState<string | null>(null)

  const add = (it: ItemLookupResult) =>
    setLines((ls) => [
      ...ls,
      { key: crypto.randomUUID(), item_id: it.id, code: it.code, description: it.description, qty: 1, condition: 'USED', into_stock: false },
    ])

  return (
    <div className="space-y-3">
      <DocNumberInput type="npbg" value={npbgRef} onChange={setNpbgRef} label="No. NPBG asal" />
      <div>
        <Label>Barang bekas</Label>
        <ItemPicker onPick={add} />
      </div>
      {lines.map((l) => (
        <div key={l.key} className="space-y-2 rounded-md border p-2 text-sm">
          <div className="flex items-center justify-between">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{l.code}</span> {l.description}
            </span>
            <button className="text-xs text-destructive" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>
              hapus
            </button>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Input
              type="number"
              step="any"
              className="h-8 w-24"
              value={l.qty}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)))}
            />
            <Select
              className="h-8 w-36"
              value={l.condition}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, condition: e.target.value } : x)))}
            >
              <option value="REUSABLE">Reusable</option>
              <option value="USED">Used</option>
              <option value="DAMAGED">Damaged</option>
              <option value="SCRAP">Scrap</option>
            </Select>
            <label className="flex items-center gap-1 text-xs">
              <input
                type="checkbox"
                checked={l.into_stock}
                onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, into_stock: e.target.checked } : x)))}
              />
              masuk stok
            </label>
          </div>
        </div>
      ))}
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || lines.length === 0}
          onClick={() =>
            create.mutate(
              {
                npbg_ref_raw: npbgRef || undefined,
                items: lines.map((l) => ({
                  item_id: l.item_id,
                  qty: l.qty,
                  condition: l.condition,
                  into_stock: l.into_stock,
                })),
              },
              { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          Simpan
        </Button>
      </div>
    </div>
  )
}

function EditForm({ ur, onDone }: { ur: UsedReturnRow; onDone: () => void }) {
  const update = useTrackingUpdate<UsedReturnRow>('used-returns', ur.id)
  const [npbgRef, setNpbgRef] = useState(ur.npbg_ref ?? '')
  const [note, setNote] = useState(ur.note ?? '')
  const [lines, setLines] = useState<Line[]>(
    (ur.items ?? []).map((l) => ({
      key: crypto.randomUUID(),
      item_id: undefined,
      code: l.item_code ?? undefined,
      description: l.description ?? l.component_type ?? '',
      qty: l.qty,
      condition: l.condition,
      into_stock: l.into_stock,
    })),
  )
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
      <DocNumberInput type="npbg" value={npbgRef} onChange={setNpbgRef} label="No. NPBG asal" />
      <div>
        <Label>Catatan</Label>
        <Input className="mt-1" value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
      {lines.map((l) => (
        <div key={l.key} className="space-y-2 rounded-md border p-2 text-sm">
          <span>
            <span className="font-mono text-xs text-muted-foreground">{l.code}</span> {l.description}
          </span>
          <div className="flex flex-wrap items-center gap-2">
            <Input
              type="number"
              step="any"
              className="h-8 w-24"
              value={l.qty}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)))}
            />
            <Select
              className="h-8 w-36"
              value={l.condition}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, condition: e.target.value } : x)))}
            >
              <option value="REUSABLE">Reusable</option>
              <option value="USED">Used</option>
              <option value="DAMAGED">Damaged</option>
              <option value="SCRAP">Scrap</option>
            </Select>
            <label className="flex items-center gap-1 text-xs">
              <input
                type="checkbox"
                checked={l.into_stock}
                onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, into_stock: e.target.checked } : x)))}
              />
              masuk stok
            </label>
          </div>
        </div>
      ))}
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={update.isPending}
          onClick={() =>
            update.mutate(
              {
                npbg_ref_raw: npbgRef || undefined,
                note: note || undefined,
                items: lines.map((l) => ({ qty: l.qty, condition: l.condition, into_stock: l.into_stock })),
              },
              { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          Simpan
        </Button>
      </div>
    </div>
  )
}

function Detail({ id, onDone }: { id: number; onDone: () => void }) {
  const qc = useQueryClient()
  const { hasPermission } = useAuth()
  const { data: ur, isLoading, refetch } = useTrackingItem<UsedReturnRow>('used-returns', id)
  const { data: warehouses } = useWarehouses()
  const action = useTrackingAction<UsedReturnRow>('used-returns', id)
  const del = useTrackingDelete('used-returns')
  const [err, setErr] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [closing, setClosing] = useState(false)
  const [warehouseId, setWarehouseId] = useState<number | ''>('')

  if (isLoading || !ur) return <DetailFallback loading={isLoading} />

  if (editing)
    return (
      <EditForm
        ur={ur}
        onDone={() => {
          setEditing(false)
          refetch()
        }}
      />
    )

  async function remove() {
    if (!(await confirmDialog(`Hapus pengembalian ${ur!.number}?`))) return
    del.mutate(id, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
  }

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Nomor', <span className="font-mono text-xs">{ur.number}</span>],
          ['Status', <RequestStatusBadge status={ur.status} />],
          ['NPBG asal', ur.npbg_number ?? ur.npbg_ref],
          ['RI bekas (internal)', <DocLink no={ur.ri_number} />],
          ['Tanggal', ur.return_date],
        ]}
      />
      <DataTable
        columns={[
          { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? l.component_type ?? '—'}</span> },
          { key: 'desc', header: 'Deskripsi', cell: (l) => l.description ?? l.component_type ?? '—' },
          { key: 'qty', header: 'Qty', cell: (l) => l.qty },
          { key: 'cond', header: 'Kondisi', cell: (l) => <Badge variant="neutral">{l.condition}</Badge> },
          { key: 'stock', header: 'Masuk Stok', cell: (l) => (l.into_stock ? 'Ya' : 'Tidak') },
        ]}
        rows={ur.items ?? []}
        rowKey={(l) => l.id}
      />
      {ur.status === 'PENDING' && hasPermission('used_return.update') && (
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" /> Ubah
          </Button>
          <Button size="sm" variant="destructive" onClick={remove} disabled={del.isPending}>
            <Trash2 className="size-4" /> Hapus
          </Button>
        </div>
      )}
      {ur.status === 'PENDING' && hasPermission('used_return.close') && !closing && (
        <Button size="sm" onClick={() => setClosing(true)}>
          Tutup &amp; Masukkan ke Stok
        </Button>
      )}
      {ur.status === 'PENDING' && hasPermission('used_return.close') && closing && (
        <div className="space-y-2 rounded-md border bg-muted/30 p-3">
          <p className="text-sm text-muted-foreground">
            Menutup pengembalian ini akan langsung membuat &amp; mengonfirmasi RI dari baris barang di atas — barang
            dengan kondisi Reusable/Used dan &ldquo;masuk stok&rdquo; akan menambah stok gudang yang dipilih.
          </p>
          <div className="max-w-xs">
            <Label>Gudang tujuan</Label>
            <select
              className="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm"
              value={warehouseId}
              onChange={(e) => setWarehouseId(e.target.value ? Number(e.target.value) : '')}
            >
              <option value="">— pilih gudang —</option>
              {warehouses?.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code} — {w.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex gap-2 pt-1">
            <Button variant="outline" size="sm" onClick={() => setClosing(false)}>
              Batal
            </Button>
            <Button
              size="sm"
              disabled={action.isPending || !warehouseId}
              onClick={() =>
                action.mutate(
                  { action: 'close', body: { warehouse_id: warehouseId } },
                  {
                    onSuccess: () => {
                      qc.invalidateQueries({ queryKey: ['used-returns'] })
                      onDone()
                    },
                    onError: (e) => setErr(apiErrorMessage(e)),
                  },
                )
              }
            >
              {action.isPending ? 'Memproses…' : 'Konfirmasi Tutup'}
            </Button>
          </div>
        </div>
      )}
      {err && <p className="text-sm text-destructive">{err}</p>}
    </div>
  )
}

type Sheet = 'matrix' | 'other' | 'ri'

export function UsedReturnPage() {
  const { hasPermission } = useAuth()
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  // the Excel "8. Tracking Pengembalian Bekas" sheets + the RI/NV documents from Accurate
  // ?sheet=other — opened from an NPBG line's "Kembali Bekas" link
  const [urlParams] = useSearchParams()
  const [sheet, setSheet] = useState<Sheet>(() => (['matrix', 'other', 'ri'] as const).find((k) => k === urlParams.get('sheet')) ?? 'matrix')
  const tabs = (
    <div className="flex gap-1 overflow-x-auto border-b" role="tablist">
      {([['matrix', 'Spare Part'], ['other', 'Spare Part Lain'], ['ri', 'RI NV (Accurate)']] as const).map(([k, label]) => (
        <button key={k} type="button" role="tab" aria-selected={sheet === k} onClick={() => setSheet(k)}
          className={cn('-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
            sheet === k ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}>
          {label}
        </button>
      ))}
    </div>
  )
  if (sheet !== 'ri') return <UsedReturnSheet sheet={sheet} headerExtra={tabs} />

  return (
    <TrackingModule<UsedReturnLineRow>
      key="ri"
      base="used-returns"
      exportDataset="used-returns"
      exportPermission="used_return.view"
      title="Pengembalian Bekas"
      headerExtra={tabs}
 subtitle="Dokumen RI/NV dari Accurate sisa / bekas / rusak dari pemakaian yang dikembalikan"
      icon={<Recycle className="size-5" />}
      columns={columns}
      statuses={['PENDING', 'CLEAR']}
      canCreate={hasPermission('used_return.create')}
      createLabel="Catat Pengembalian"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail id={row.id} onDone={close} />}
      detailTitle={(row) => row.number}
      rowKey={(row) => row.line_id}
      searchPlaceholder="Cari no UR, kode barang, deskripsi…"
      extraFilterParams={{ date_from: dateFrom || undefined, date_to: dateTo || undefined }}
      extraFilter={
        <>
          <div className="space-y-1">
            <label className="text-xs text-muted-foreground">Dari tanggal</label>
            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          </div>
          <div className="space-y-1">
            <label className="text-xs text-muted-foreground">Sampai tanggal</label>
            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          </div>
        </>
      }
    />
  )
}
