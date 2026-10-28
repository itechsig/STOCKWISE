import { Link, useParams } from 'react-router-dom'
import { usePpb, PPB_STATUS, PPB_LINE_STATUS, ppbDivisi } from '@/features/ppb/api'
import { PpbStatusBadges } from '@/components/PpbStatusBadges'
import { PrintDocButton } from '@/components/PrintDocButton'
import { Card, CardContent } from '@/components/ui/card'
import { DocText } from '@/components/DocLink'
import { BackLink } from '@/components/BackLink'
import { PpbApprovalCard } from '@/components/PpbApproval'
import { DetailFallback } from '@/components/DetailFallback'

function Field({ label, value, className }: { label: string; value?: string | number | null; className?: string }) {
  return (
    <div className={className}>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

export function PpbDetailPage() {
  const { id } = useParams()
  const ppbId = Number(id)
  const { data: ppb, isLoading } = usePpb(ppbId)

  if (isLoading || !ppb) return <div className="space-y-4"><BackLink to="/ppb" label="Daftar PPB" /><DetailFallback loading={isLoading} /></div>

  return (
    <div className="max-w-3xl space-y-4">
      <BackLink to="/ppb" label="Daftar PPB" />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 space-y-1">
          <h1 className="break-all font-mono text-lg font-semibold">{ppb.no_ppb ?? '—'}</h1>
          <p className="text-sm text-muted-foreground">{fmtDate(ppb.tgl_ppb)}</p>
          <PpbStatusBadges ppb={ppb} withHint />
        </div>
        <div className="shrink-0">
          <PrintDocButton url={`/api/ppb/${ppb.id}/print`} docType="ppb" />
        </div>
      </div>

      <PpbApprovalCard ppb={ppb} />

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Tgl PPB" value={fmtDate(ppb.tgl_ppb)} />
          <Field label="Status barang" value={[ppb.is_amend ? 'Amend' : null, ppb.line_status ? PPB_LINE_STATUS[ppb.line_status]?.label ?? ppb.line_status : null].filter(Boolean).join(' + ') || null} />
          <Field label="Dokumen PPB di Accurate" value={ppb.status ? PPB_STATUS[ppb.status]?.label ?? ppb.status : null} />
          <Field label="Divisi" value={ppbDivisi(ppb.divisi)} />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Kode Barang" value={ppb.kode_barang} />
          <Field label="Deskripsi Barang" value={ppb.deskripsi_barang} className="col-span-2 sm:col-span-1" />
          <Field label="Kuantitas" value={ppb.kuantitas != null ? `${ppb.kuantitas} ${ppb.satuan ?? ''}` : null} />
          <Field label="Qty Dipesan" value={ppb.qty_dipesan} />
          <Field label="Qty Diterima" value={ppb.qty_diterima} />
          <Field label="Peminta" value={ppb.peminta} />
          <div className="col-span-2 sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div className="whitespace-pre-wrap"><DocText text={ppb.keterangan} /></div>
          </div>
          {ppb.catatan_baris && (
            <div className="col-span-2 sm:col-span-3">
              <div className="text-muted-foreground">Catatan Baris</div>
              <div className="whitespace-pre-wrap">{ppb.catatan_baris}</div>
            </div>
          )}
        </CardContent>
      </Card>

      {(ppb.purchased_via?.length ?? 0) > 0 && (
        <Card>
          <CardContent className="space-y-1 p-4 text-sm">
            <div className="font-medium">Dipesan via PO</div>
            {ppb.purchased_via!.map((p) => (
              <div key={p.purchase_order_id} className="flex items-center justify-between">
                <Link to={`/purchase-orders/${p.purchase_order_id}`} className="font-mono text-xs text-primary hover:underline">
                  {p.number ?? '—'}
                </Link>
                <span className="text-xs text-muted-foreground">
                  {p.qty_received}/{p.qty} diterima
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {ppb.accurate_synced_at && (
        <p className="text-xs text-muted-foreground">
          Terakhir sync dari Accurate: {new Date(ppb.accurate_synced_at).toLocaleString('id-ID')}
        </p>
      )}
    </div>
  )
}
