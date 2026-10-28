import { Link, useParams } from 'react-router-dom'
import { FileText, Undo2 } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { Button } from '@/components/ui/button'
import { RI_SOURCE, useRi, useRiPhoto, riJenis } from '@/features/ri/api'
import { lokasiText } from '@/features/npbg/api'
import { PrintDocButton } from '@/components/PrintDocButton'
import { Card, CardContent } from '@/components/ui/card'
import { DocText } from '@/components/DocLink'
import { BackLink } from '@/components/BackLink'
import { DetailFallback } from '@/components/DetailFallback'

function Field({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

function fmtMoney(v: number | null) {
  return v != null ? `Rp ${v.toLocaleString('id-ID')}` : '—'
}

export function RiDetailPage() {
  const { id } = useParams()
  const riId = Number(id)
  const { data: ri, isLoading } = useRi(riId)
  const { hasPermission } = useAuth()

  if (isLoading || !ri) return <div className="space-y-4"><BackLink to="/ri" label="Daftar RI" /><DetailFallback loading={isLoading} /></div>

  return (
    <div className="max-w-3xl space-y-4">
      <BackLink to="/ri" label="Daftar RI" />
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{ri.no_ri ?? '—'}</h1>
          <p className="text-sm text-muted-foreground">{fmtDate(ri.tgl_ri)}</p>
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          {hasPermission('purchase_return.manage') && ri.no_ri && (
            <Button asChild size="sm" variant="outline"><Link to={`/purchase-returns/new?ri=${encodeURIComponent(ri.no_ri)}`}><Undo2 className="size-4" /> Retur barang</Link></Button>
          )}
          <PrintDocButton url={`/api/ri/${ri.id}/print`} docType="ri" />
        </div>
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Tgl RI" value={fmtDate(ri.tgl_ri)} />
          <Field label="Jenis RI" value={riJenis(ri.divisi)} />
          <Field label="Sumber" value={ri.sumber ? RI_SOURCE[ri.sumber] : null} />
          <Field label="Vendor" value={ri.vendor} />
          <div>
            <div className="text-muted-foreground">No PO</div>
            {ri.source_po ? (
              <Link to={`/purchase-orders/${ri.source_po.purchase_order_id}`} className="font-mono text-primary hover:underline">
                {ri.source_po.number ?? ri.no_po ?? '—'}
              </Link>
            ) : (
              <div>{ri.no_po ?? '—'}</div>
            )}
          </div>
          <Field label="Tgl Kirim" value={fmtDate(ri.shipdate)} />
          <Field label="No. Surat Jalan" value={ri.no_surat_jalan ?? null} />
          <Field label="Pengirim" value={ri.pengirim ?? null} />
          <Field label="FOB" value={ri.fob ?? null} />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Kode Barang" value={ri.kode_barang} />
          <Field label="Deskripsi Barang" value={ri.deskripsi_barang} />
          <Field label="Kuantitas" value={ri.kuantitas != null ? `${ri.kuantitas} ${ri.satuan ?? ''}` : null} />
          <Field label="Harga Satuan" value={fmtMoney(ri.harga_satuan)} />
          <Field label="Pemeriksa" value={ri.pemeriksa} />
          <Field label="Lokasi Gudang / Rak" value={lokasiText(ri)} />
          <Field label="No. PPB" value={ri.no_ppb ?? null} />
          <Field label="No. Seri" value={ri.no_seri ?? null} />
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div className="whitespace-pre-wrap"><DocText text={ri.keterangan} /></div>
          </div>
        </CardContent>
      </Card>

      {/* an RI of several items (e.g. from a PO): every line of it */}
      {(ri.lines?.length ?? 0) > 1 && (
        <Card>
          <CardContent className="space-y-2 p-4 text-sm">
            <div className="text-muted-foreground">Barang di RI ini ({ri.lines!.length})</div>
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead className="whitespace-nowrap bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <tr><th className="px-3 py-2 font-medium">Barang</th><th className="px-3 py-2 text-right font-medium">Kuantitas</th><th className="px-3 py-2 text-right font-medium">Harga</th><th className="px-3 py-2 font-medium">No. Seri</th><th className="px-3 py-2 font-medium">PPB</th><th className="px-3 py-2 font-medium">Foto Bukti</th></tr>
                </thead>
                <tbody className="divide-y">
                  {ri.lines!.map((l) => (
                    <tr key={l.id} className={l.id === ri.id ? 'bg-primary/5' : undefined}>
                      <td className="px-3 py-2"><div className="font-medium">{l.deskripsi_barang}</div><div className="font-mono text-xs text-muted-foreground">{l.kode_barang ?? ''}</div></td>
                      <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{l.kuantitas ?? ''} {l.satuan ?? ''}</td>
                      <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{fmtMoney(l.harga_satuan)}</td>
                      <td className="px-3 py-2">{l.no_seri ?? ''}</td>
                      <td className="px-3 py-2 font-mono text-xs">{l.no_ppb ?? ''}</td>
                      <td className="px-3 py-2"><div className="flex gap-1.5">{(l.photos ?? []).map((slot) => <div key={slot} className="w-12"><RiPhoto id={l.id} slot={slot} /></div>)}</div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}

      {(ri.documents?.length ?? 0) > 0 && (
        <Card>
          <CardContent className="space-y-2 p-4 text-sm">
            <div className="text-muted-foreground">Dokumen (surat jalan / invoice)</div>
            <div className="flex flex-wrap gap-2">
              {ri.documents!.map((d) => <RiDocument key={d.slot} id={ri.id} slot={d.slot} name={d.file_name ?? d.slot} />)}
            </div>
          </CardContent>
        </Card>
      )}

      {(ri.photos?.length ?? 0) > 0 && (
        <Card>
          <CardContent className="space-y-2 p-4 text-sm">
            <div className="text-muted-foreground">Foto bukti penerimaan</div>
            <div className="grid grid-cols-3 gap-2 sm:max-w-xl">
              {ri.photos!.map((slot) => <RiPhoto key={slot} id={ri.id} slot={slot} />)}
            </div>
          </CardContent>
        </Card>
      )}

      {ri.accurate_synced_at && (
        <p className="text-xs text-muted-foreground">
          Terakhir sync dari Accurate: {new Date(ri.accurate_synced_at).toLocaleString('id-ID')}
        </p>
      )}
    </div>
  )
}

/** One document of the RI (photo or PDF), opened in a new tab. */
function RiDocument({ id, slot, name }: { id: number; slot: string; name: string }) {
  const { data: url } = useRiPhoto(id, slot)
  return (
    <a href={url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-md border bg-card px-2.5 py-1.5 text-xs text-primary hover:bg-muted">
      <FileText className="size-3.5" /> {name}
    </a>
  )
}

function RiPhoto({ id, slot }: { id: number; slot: string }) {
  const { data: url } = useRiPhoto(id, slot)
  return (
    <a href={url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-lg border bg-muted">
      {url ? <img src={url} alt={`Foto bukti ${slot.replace('foto', '')}`} className="aspect-square w-full object-cover" /> : <div className="aspect-square" />}
    </a>
  )
}
