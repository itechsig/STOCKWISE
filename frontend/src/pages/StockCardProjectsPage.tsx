import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { BookOpenCheck, ChevronRight, FolderKanban, Plus, Search } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { useStockCardProjects, type StockCardProject } from '@/features/stock-card/api'
import { ProjectModal } from '@/components/stock-card/StockCardModals'
import { MobileBottomBar } from '@/components/stock-card/ActionMenu'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useRemembered } from '@/lib/listMemory'

const statusBadge = (s: string) => <Badge variant={s === 'ACTIVE' ? 'default' : 'neutral'}>{s === 'ACTIVE' ? 'Berjalan' : 'Selesai'}</Badge>

export function StockCardProjectsPage() {
  const { hasPermission } = useAuth()
  const navigate = useNavigate()
  const [search, setSearch] = useRemembered('stock-card-projects', 'search', '')
  const [creating, setCreating] = useState(false)
  const { data, isLoading } = useStockCardProjects(search)
  const canEdit = hasPermission('item.update')
  const projects = data?.data ?? []

  const columns: Column<StockCardProject>[] = [
    { key: 'name', header: 'Proyek', cell: (r) => <Link to={`/stock-card/project/${r.id}`} className="font-medium text-primary hover:underline">{r.name}</Link> },
 { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.code ?? ''}</span> },
    { key: 'cards', header: 'Jumlah Kartu', className: 'text-right', cell: (r) => <span className="tabular-nums">{r.cards_count}</span> },
    { key: 'status', header: 'Status', cell: (r) => statusBadge(r.status) },
  ]

  return (
    <div className="space-y-4 pb-24 md:pb-0">
      <PageHeader
        title="Kartu Stock"
 subtitle="Per proyek satu proyek berisi banyak kartu, satu kartu per barang"
        icon={<BookOpenCheck className="size-5" />}
        actions={canEdit ? <Button className="hidden md:inline-flex" onClick={() => setCreating(true)}><Plus className="size-4" /> Buat Proyek</Button> : undefined}
      />
      <div className="relative md:max-w-md">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input className="h-11 pl-9 md:h-10" placeholder="Cari nama / kode proyek…" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      {(data?.unassigned_cards ?? 0) > 0 && (
        <Link to="/stock-card/project/none" className="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
 <FolderKanban className="size-4 shrink-0" /> <span className="flex-1">{data!.unassigned_cards} kartu belum masuk proyek ketuk untuk memindahkannya</span>
          <ChevronRight className="size-4" />
        </Link>
      )}

      {/* phone: one tappable card per project */}
      <div className="space-y-2 md:hidden">
        {isLoading && <p className="py-6 text-center text-sm text-muted-foreground">Memuat…</p>}
        {!isLoading && projects.length === 0 && (
          <p className="rounded-lg border border-dashed py-10 text-center text-sm text-muted-foreground">
            {search ? 'Tidak ada proyek yang cocok.' : 'Belum ada proyek. Ketuk "Proyek baru" di bawah.'}
          </p>
        )}
        {projects.map((p) => (
          <Link key={p.id} to={`/stock-card/project/${p.id}`} className="flex items-center gap-3 rounded-lg border bg-card p-3.5 active:bg-muted">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><FolderKanban className="size-5" /></div>
            <div className="min-w-0 flex-1">
              <div className="truncate font-semibold">{p.name}</div>
              <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                {p.code && <span className="font-mono">{p.code}</span>}
                <span>{p.cards_count} kartu</span>
                {statusBadge(p.status)}
              </div>
            </div>
            <ChevronRight className="size-5 shrink-0 text-muted-foreground" />
          </Link>
        ))}
      </div>

      {/* desktop: table */}
      <div className="hidden md:block">
        <DataTable columns={columns} rows={projects} rowKey={(r) => r.id} isLoading={isLoading} scrollMemoryKey="stock-card-projects"
          onRowClick={(r) => navigate(`/stock-card/project/${r.id}`)}
          emptyText={search ? 'Tidak ada proyek yang cocok.' : 'Belum ada proyek. Klik "Buat Proyek", lalu buat kartu stock tiap barangnya.'} />
      </div>

      {canEdit && (
        <MobileBottomBar>
          <Button className="h-12 w-full text-base" onClick={() => setCreating(true)}><Plus className="size-5" /> Proyek baru</Button>
        </MobileBottomBar>
      )}
      {creating && <ProjectModal open onClose={() => setCreating(false)} onSaved={(p) => navigate(`/stock-card/project/${p.id}`)} />}
    </div>
  )
}
