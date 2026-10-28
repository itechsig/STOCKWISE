import { Link, Navigate, Outlet, useLocation } from 'react-router-dom'
import { Loader2, ShieldAlert } from 'lucide-react'
import { useAuth } from './AuthContext'

export function RequireAuth() {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />

  return <Outlet />
}

export function RequirePermission({ permission }: { permission: string | string[] }) {
  const { hasPermission } = useAuth()
  const needed = Array.isArray(permission) ? permission : [permission]

  if (!needed.some((p) => hasPermission(p))) {
    return (
      <div className="mx-auto mt-10 flex max-w-md flex-col items-center gap-2 rounded-lg border bg-card px-6 py-12 text-center">
        <ShieldAlert className="size-9 text-muted-foreground" />
        <p className="font-medium">Anda tidak punya akses ke halaman ini</p>
        <p className="text-sm text-muted-foreground">Hubungi admin bila Anda membutuhkannya. Hak akses: <span className="font-mono text-xs">{needed.join(' / ')}</span></p>
        <Link to="/" className="mt-2 text-sm font-medium text-primary hover:underline">Kembali ke Dashboard</Link>
      </div>
    )
  }
  return <Outlet />
}

function FullScreenLoader() {
  return (
    <div className="grid min-h-screen place-items-center bg-background text-muted-foreground">
      <span className="flex items-center gap-2 text-sm"><Loader2 className="size-4 animate-spin" /> Memuat STOCKWISE…</span>
    </div>
  )
}
