import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ChevronRight, Download, FolderKanban, Link2, Loader2, Pencil, Plus, Printer, Trash2 } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { stockCardPdf, useDeleteStockCardProject, useStockCardProject, useStockCards, type StockCardListRow } from '@/features/stock-card/api'
import { CreateStockCardModal, ProjectModal } from '@/components/stock-card/StockCardModals'
import { ActionMenu, MobileBottomBar } from '@/components/stock-card/ActionMenu'
import { ShareLinksModal } from '@/components/stock-card/ShareLinksModal'
import { routePaths, useRoutePaths, useStockCardScope } from '@/features/stock-card/scope'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { BackLink } from '@/components/BackLink'
import { confirmDialog } from '@/components/ui/confirm'

const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 })

const columns = (cardPath: (id: number) => string): Column<StockCardListRow>[] => [
  { key: 'no', header: 'Nomor Kartu', cell: (r) => <Link to={cardPath(r.id)} className="font-mono text-xs font-medium text-primary hover:underline">{r.card_number}</Link> },
  { key: 'nama', header: 'Nama Barang', cell: (r) => r.item.description },
 { key: 'satuan', header: 'Satuan', cell: (r) => r.item.unit ?? '' },
 { key: 'letak', header: 'Letak Gudang / Rak', cell: (r) => [r.location_warehouse, r.location_rack].filter(Boolean).join(' / ') || '' },
 { key: 'pj', header: 'Penanggung Jawab', cell: (r) => r.person_in_charge ?? '' },
  { key: 'masuk', header: 'Masuk', className: 'text-right', cell: (r) => <span className="tabular-nums">{nf.format(r.total_masuk)}</span> },
  { key: 'keluar', header: 'Keluar', className: 'text-right', cell: (r) => <span className="tabular-nums">{nf.format(r.total_keluar)}</span> },
  { key: 'sisa', header: 'Sisa', className: 'text-right', cell: (r) => <span className="font-semibold tabular-nums">{nf.format(r.sisa)}</span> },
]

/** Phone: one tappable row per card — code, name, and the balance big on the right. */
function CardList({ rows, empty }: { rows: StockCardListRow[]; empty: string }) {
  const routes = useRoutePaths()
  if (rows.length === 0) return <p className="rounded-lg border border-dashed py-10 text-center text-sm text-muted-foreground">{empty}</p>
  return (
    <div className="space-y-2">
      {rows.map((r) => (
        <Link key={r.id} to={routes.card(r.id)} className="flex items-center gap-3 rounded-lg border bg-card p-3 active:bg-muted">
          <div className="min-w-0 flex-1">
            <div className="font-mono text-xs font-semibold text-primary">{r.card_number}</div>
            <div className="line-clamp-2 text-sm font-medium leading-snug">{r.item.description}</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Masuk {nf.format(r.total_masuk)} · Keluar {nf.format(r.total_keluar)}{r.person_in_charge ? ` · ${r.person_in_charge}` : ''}
            </div>
          </div>
          <div className="shrink-0 text-right">
            <div className="text-xl font-bold tabular-nums leading-none">{nf.format(r.sisa)}</div>
            <div className="mt-1 text-[11px] text-muted-foreground">sisa {r.item.unit ?? ''}</div>
          </div>
          <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
        </Link>
      ))}
    </div>
  )
}

/** Cards made before projects existed: listed so each can be moved into a project. */
function UnassignedCards() {
  const navigate = useNavigate()
  const { data, isLoading } = useStockCards('', 1, 'none')
  const rows = data?.data ?? []
  return (
    <div className="space-y-4">
      <BackLink to="/stock-card" label="Daftar Proyek" />
      <div>
        <h1 className="text-lg font-semibold">Kartu yang belum masuk proyek</h1>
        <p className="text-sm text-muted-foreground">Buka kartunya → bagian "Info kartu" → pilih proyek.</p>
      </div>
      <div className="md:hidden">{isLoading ? <p className="text-sm text-muted-foreground">Memuat…</p> : <CardList rows={rows} empty="Tidak ada." />}</div>
      <div className="hidden md:block">
        <DataTable columns={columns(routePaths({ kind: 'auth' }).card)} rows={rows} rowKey={(r) => r.id} isLoading={isLoading} scrollMemoryKey="stock-card-project:none" onRowClick={(r) => navigate(`/stock-card/${r.id}`)} emptyText="Tidak ada." />
      </div>
    </div>
  )
}

export function StockCardProjectPage() {
  const { projectId } = useParams()
  if (projectId === 'none') return <UnassignedCards />
  return <ProjectView id={Number(projectId)} />
}

/** The cards of a project — logged in, or through a share link (no account). */
export function ProjectView({ id }: { id: number | null }) {
  const navigate = useNavigate()
  const scope = useStockCardScope()
  const shared = scope.kind === 'share'
  const routes = routePaths(scope)
  const { data: p, isLoading, error } = useStockCardProject(id)
  const del = useDeleteStockCardProject(id ?? 0)
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState(false)
  const [sharing, setSharing] = useState(false)
  const [pdfBusy, setPdfBusy] = useState<'print' | 'download' | null>(null)

  if (isLoading) return <p className="text-muted-foreground">Memuat proyek…</p>
  if (error || !p) return <p className="text-destructive">{error ? apiErrorMessage(error) : 'Proyek tidak ditemukan.'}</p>

  async function pdf(mode: 'print' | 'download') {
    setPdfBusy(mode)
    try {
      await stockCardPdf(scope, { projectId: p!.id, name: p!.code || p!.name }, mode)
    } catch (e) {
      window.alert(apiErrorMessage(e))
    } finally {
      setPdfBusy(null)
    }
  }
  const remove = async () => {
    if ((await confirmDialog(`Hapus proyek ${p.name}?`))) del.mutate(undefined, { onSuccess: () => navigate('/stock-card'), onError: (e) => window.alert(apiErrorMessage(e)) })
  }
  const empty = p.cards.length === 0
  const canCreate = p.can_create_card ?? p.can_edit

  return (
    <div className="space-y-4 pb-24 md:pb-0">
      <div className="flex items-center justify-between gap-2">
        {shared ? <span /> : <BackLink to="/stock-card" label="Daftar Proyek" />}
        <ActionMenu className="md:hidden" actions={[
          ...(p.can_edit && !shared ? [{ label: 'Bagikan link', icon: <Link2 className="size-4" />, onClick: () => setSharing(true) }] : []),
          { label: pdfBusy === 'print' ? 'Menyiapkan…' : 'Cetak semua kartu', icon: <Printer className="size-4" />, onClick: () => void pdf('print'), disabled: empty || !!pdfBusy },
          { label: pdfBusy === 'download' ? 'Menyiapkan…' : 'Download PDF semua', icon: <Download className="size-4" />, onClick: () => void pdf('download'), disabled: empty || !!pdfBusy },
          ...(p.can_edit ? [{ label: 'Ubah proyek', icon: <Pencil className="size-4" />, onClick: () => setEditing(true) }] : []),
          ...(p.can_edit && empty ? [{ label: 'Hapus proyek', icon: <Trash2 className="size-4" />, onClick: remove, danger: true }] : []),
        ]} />
      </div>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="flex items-start gap-2 text-xl font-semibold leading-tight"><FolderKanban className="mt-0.5 size-5 shrink-0 text-primary" /> {p.name}</h1>
          <p className="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            {p.code && <span className="font-mono">{p.code}</span>}
            <Badge variant={p.status === 'ACTIVE' ? 'default' : 'neutral'}>{p.status === 'ACTIVE' ? 'Berjalan' : 'Selesai'}</Badge>
            <span>{p.cards.length} kartu stock</span>
          </p>
        </div>
        <div className="hidden flex-wrap gap-2 md:flex">
          {canCreate && <Button onClick={() => setCreating(true)}><Plus className="size-4" /> Buat Kartu Stock</Button>}
          {p.can_edit && !shared && <Button variant="outline" onClick={() => setSharing(true)}><Link2 className="size-4" /> Bagikan link</Button>}
          <Button variant="outline" onClick={() => void pdf('print')} disabled={pdfBusy !== null || empty}>
            {pdfBusy === 'print' ? <Loader2 className="size-4 animate-spin" /> : <Printer className="size-4" />} Cetak semua
          </Button>
          <Button variant="outline" onClick={() => void pdf('download')} disabled={pdfBusy !== null || empty}>
            {pdfBusy === 'download' ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />} Download PDF semua
          </Button>
          {p.can_edit && <Button variant="outline" onClick={() => setEditing(true)}><Pencil className="size-4" /> Ubah proyek</Button>}
          {p.can_edit && empty && <Button variant="outline" className="text-destructive" onClick={remove}><Trash2 className="size-4" /> Hapus</Button>}
        </div>
      </div>

      <div className="md:hidden">
 <CardList rows={p.cards} empty={canCreate ? 'Belum ada kartu. Ketuk "Kartu stock baru" di bawah satu kartu per barang.' : 'Belum ada kartu di proyek ini.'} />
      </div>
      <div className="hidden md:block">
        <DataTable columns={columns(routes.card)} rows={p.cards} rowKey={(r) => r.id} scrollMemoryKey={`stock-card-project:${p.id}`} onRowClick={(r) => navigate(routes.card(r.id))}
 emptyText={canCreate ? 'Belum ada kartu di proyek ini. Klik "Buat Kartu Stock" satu kartu per barang.' : 'Belum ada kartu di proyek ini.'} />
      </div>

      {canCreate && (
        <MobileBottomBar>
          <Button className="h-12 w-full text-base" onClick={() => setCreating(true)}><Plus className="size-5" /> Kartu stock baru</Button>
        </MobileBottomBar>
      )}
      {creating && <CreateStockCardModal project={p} open onClose={() => setCreating(false)} />}
      {editing && <ProjectModal project={p} open onClose={() => setEditing(false)} />}
      {sharing && <ShareLinksModal project={p} open onClose={() => setSharing(false)} />}
    </div>
  )
}
