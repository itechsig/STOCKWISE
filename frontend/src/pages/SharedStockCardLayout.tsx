import { Link, Outlet, useParams } from 'react-router-dom'
import { Eye, Pencil, ShieldCheck } from 'lucide-react'
import { useStockCardProject } from '@/features/stock-card/api'
import { StockCardScopeProvider, routePaths, type ShareAccess, type StockCardScope } from '@/features/stock-card/scope'
import { ProjectView } from '@/pages/StockCardProjectPage'

const BADGE: Record<ShareAccess, { label: string; icon: React.ReactNode; cls: string }> = {
  VIEW: { label: 'Hanya lihat', icon: <Eye className="size-3.5" />, cls: 'bg-muted text-muted-foreground' },
  EDIT: { label: 'Bisa mengisi', icon: <Pencil className="size-3.5" />, cls: 'bg-primary/10 text-primary' },
  FULL: { label: 'Akses penuh', icon: <ShieldCheck className="size-3.5" />, cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
}

function ShareHeader({ scope }: { scope: StockCardScope }) {
  const { data } = useStockCardProject(null)
  const badge = data?.share ? BADGE[data.share.access] : null
  return (
    <header className="sticky top-0 z-30 border-b bg-card/95 backdrop-blur">
      <div className="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4">
        <Link to={routePaths(scope).home} className="flex min-w-0 items-center gap-2">
          <img src="/logo.png" alt="" className="size-8 shrink-0" />
          <span className="min-w-0">
            <span className="block text-sm font-semibold leading-tight">Kartu Stock</span>
            <span className="block truncate text-xs text-muted-foreground">{data?.name ?? 'STOCKWISE'}</span>
          </span>
        </Link>
        {badge && <span className={`ml-auto inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${badge.cls}`}>{badge.icon}{badge.label}</span>}
      </div>
    </header>
  )
}

/**
 * Kartu Stock opened through a share link (/share/kartu-stock/:token) — no account,
 * no menu: just the project's cards, with the rights of the link.
 */
export function SharedStockCardLayout() {
  const { token = '' } = useParams()
  const scope: StockCardScope = { kind: 'share', token }
  return (
    <StockCardScopeProvider value={scope}>
      <div className="min-h-dvh bg-background">
        <ShareHeader scope={scope} />
        <main className="mx-auto max-w-6xl p-4">
          <Outlet />
        </main>
      </div>
    </StockCardScopeProvider>
  )
}

export function SharedStockCardProject() {
  return <ProjectView id={null} />
}
