import { Fragment, useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, ScrollRestoration, useLocation } from 'react-router-dom'
import { ChevronDown, ChevronRight, KeyRound, LogOut, Menu, Search, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { APPROVAL_PERMISSIONS, useApprovalCount } from '@/features/itemRequests/api'
import { useIrSummary } from '@/features/importantRequests/api'
import { ChangePasswordModal } from '@/components/ChangePasswordModal'
import { CommandMenu } from '@/components/CommandMenu'
import { Logo } from '@/components/Logo'
import { NotificationBell } from '@/components/NotificationBell'
import { crumbsFor, visibleNav } from '@/lib/navigation'
import { cn } from '@/lib/utils'

function initials(name?: string) {
  if (!name) return '?'
  return name
    .split(' ')
    .slice(0, 2)
    .map((w) => w[0])
    .join('')
    .toUpperCase()
}

/** Avatar button → name, role, Ganti Password, Keluar. One compact control on every screen size. */
function UserMenu({ onChangePassword }: { onChangePassword: () => void }) {
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const roles = `${user?.roles.join(', ') ?? ''}${user?.site ? ` · ${user.site.code}` : ''}`

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        className="flex items-center gap-2 rounded-lg p-1 hover:bg-muted sm:pr-2"
        onClick={() => setOpen((v) => !v)}
        aria-label="Menu akun"
        aria-expanded={open}
      >
        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
          {initials(user?.name)}
        </span>
        <span className="hidden max-w-[12rem] text-left sm:block">
          <span className="block truncate text-sm font-medium leading-tight">{user?.name}</span>
          <span className="block truncate text-xs text-muted-foreground">{roles}</span>
        </span>
        <ChevronDown className="hidden size-4 text-muted-foreground sm:block" />
      </button>

      {open && (
        <div className="absolute right-0 z-50 mt-2 w-60 rounded-lg border bg-card p-1 shadow-lg">
          <div className="border-b px-3 py-2 sm:hidden">
            <div className="truncate text-sm font-medium">{user?.name}</div>
            <div className="truncate text-xs text-muted-foreground">{roles}</div>
          </div>
          <button
            type="button"
            className="flex w-full items-center gap-2 rounded-md px-3 py-2.5 text-left text-sm hover:bg-muted"
            onClick={() => {
              setOpen(false)
              onChangePassword()
            }}
          >
            <KeyRound className="size-4" /> Ganti Password
          </button>
          <button
            type="button"
            className="flex w-full items-center gap-2 rounded-md px-3 py-2.5 text-left text-sm text-destructive hover:bg-destructive/10"
            onClick={() => logout()}
          >
            <LogOut className="size-4" /> Keluar
          </button>
        </div>
      )}
    </div>
  )
}

/** Where the page sits: group › menu › detail (lib/navigation). */
function Breadcrumb({ pathname }: { pathname: string }) {
  const crumbs = crumbsFor(pathname)
  if (crumbs.length === 0) return null
  return (
    <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1 text-sm">
      {crumbs.map((c, i) => (
        <Fragment key={i}>
          {i > 0 && <ChevronRight className="size-3.5 shrink-0 text-muted-foreground/60" />}
          {c.to
            ? <Link to={c.to} className="truncate text-muted-foreground hover:text-foreground">{c.label}</Link>
            : <span className={cn('truncate', i === crumbs.length - 1 ? 'font-medium text-foreground' : 'text-muted-foreground')}>{c.label}</span>}
        </Fragment>
      ))}
    </nav>
  )
}

/**
 * The ERP shell: a light sidebar (menus by group, only what the role may open),
 * a header with the breadcrumb, menu search (Ctrl K), notifications and the account.
 */
export function AppLayout() {
  const { hasPermission } = useAuth()
  const location = useLocation()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [searching, setSearching] = useState(false)
  const [changingPassword, setChangingPassword] = useState(false)

  // Drawer (phone / tablet): close on navigation and Escape, and freeze the page behind it.
  useEffect(() => setMobileOpen(false), [location.pathname])
  useEffect(() => {
    if (!mobileOpen) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setMobileOpen(false)
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [mobileOpen])

  const approvals = useApprovalCount(APPROVAL_PERMISSIONS.some((p) => hasPermission(p)))
  // Important Request: tasks to confirm / revise and results to review (what needs my action)
  const important = useIrSummary(hasPermission('important_request.access'))
  const badges = { approvals: approvals.data ?? 0, important: important.data?.badge ?? 0 }
  const groups = visibleNav(hasPermission)
  // sub menus: open on their own page, or when toggled (supports nested levels)
  const [openSubs, setOpenSubs] = useState<Record<string, boolean>>({})
  const subOpen = (to: string, children?: { to: string; children?: { to: string }[] }[]) => openSubs[to]
    ?? (location.pathname.startsWith(to) || !!children?.some((c) => {
      const cPath = c.to.split('?')[0]
      return location.pathname.startsWith(cPath) || (c.children && c.children.some((gc) => location.pathname.startsWith(gc.to.split('?')[0])))
    }))

  const sidebar = (
    <nav className="flex h-full flex-col gap-5 overflow-y-auto overscroll-contain px-3 py-4" aria-label="Menu utama">
      {groups.map((g, i) => (
        <div key={g.label ?? i} className="space-y-0.5">
          {g.label && <div className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/80">{g.label}</div>}
          {g.items.map((n) => {
            const Icon = n.icon
            const count = n.badge ? badges[n.badge] : 0
            return (
              <Fragment key={n.to}>
              <div className="relative">
              <NavLink to={n.to} end={n.to === '/' || !!n.children} onClick={() => setMobileOpen(false)}
                className={({ isActive }) => cn(
                  'relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors', n.children && 'pr-8',
                  isActive && !(n.children && location.search)
                    ? 'bg-primary/10 font-medium text-primary before:absolute before:inset-y-1.5 before:left-0 before:w-0.5 before:rounded-full before:bg-primary'
                    : 'text-sidebar-foreground hover:bg-muted hover:text-foreground',
                )}>
                <Icon className="size-4 shrink-0 opacity-80" />
                <span className="flex-1 truncate">{n.label}</span>
                {count > 0 && (
                  <span className="min-w-5 rounded-full bg-primary px-1.5 text-center text-[11px] font-semibold leading-5 text-primary-foreground" aria-label={`${count} menunggu`}>{count}</span>
                )}
              </NavLink>
                {/* sub menus (e.g. Master Data › Barang / Jasa / Vendor & Supplier): open while on one of their pages or toggled */}
                {n.children && (
                  <button type="button" onClick={() => setOpenSubs((o) => ({ ...o, [n.to]: !subOpen(n.to, n.children) }))}
                    className="absolute right-1 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    aria-label={subOpen(n.to, n.children) ? `Tutup sub menu ${n.label}` : `Buka sub menu ${n.label}`} aria-expanded={subOpen(n.to, n.children)}>
                    <ChevronDown className={cn('size-4 transition-transform', subOpen(n.to, n.children) && 'rotate-180')} />
                  </button>
                )}
              </div>
              {n.children && subOpen(n.to, n.children) && (
                <div className="ml-5 mt-0.5 space-y-0.5 border-l pl-2">
                  {n.children.map((c) => {
                    const hasChildren = c.children && c.children.length > 0
                    const on = location.pathname + location.search === c.to
                    const cOpen = subOpen(c.to, c.children)
                    return (
                      <div key={c.to} className="relative">
                        <div className="flex items-center gap-2">
                          <Link to={c.to} onClick={() => setMobileOpen(false)}
                            className={cn('flex-1 rounded-md px-3 py-1.5 text-sm transition-colors', on ? 'bg-primary/10 font-medium text-primary' : 'text-sidebar-foreground hover:bg-muted hover:text-foreground')}>
                            {c.label}
                          </Link>
                          {hasChildren && (
                            <button
                              type="button"
                              onClick={() => setOpenSubs((o) => ({ ...o, [c.to]: !cOpen }))}
                              className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                              aria-label={cOpen ? `Tutup sub menu ${c.label}` : `Buka sub menu ${c.label}`}
                              aria-expanded={cOpen}
                            >
                              <ChevronDown className={cn('size-3 transition-transform', cOpen && 'rotate-180')} />
                            </button>
                          )}
                        </div>
                        {hasChildren && cOpen && (
                          <div className="ml-4 mt-0.5 space-y-0.5 border-l pl-2">
                            {c.children!.map((gc) => {
                              const gcOn = location.pathname + location.search === gc.to
                              return (
                                <Link key={gc.to} to={gc.to} onClick={() => setMobileOpen(false)}
                                  className={cn('block rounded-md px-3 py-1.5 text-sm transition-colors', gcOn ? 'bg-primary/10 font-medium text-primary' : 'text-sidebar-foreground hover:bg-muted hover:text-foreground')}>
                                  {gc.label}
                                </Link>
                              )
                            })}
                          </div>
                        )}
                      </div>
                    )
                  })}
                </div>
              )}
              </Fragment>
            )
          })}
        </div>
      ))}
    </nav>
  )

  return (
    <div className="flex min-h-screen">
      {/* desktop sidebar */}
      <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r bg-sidebar lg:flex print:hidden">
        <div className="flex h-14 shrink-0 items-center border-b px-5"><Link to="/" aria-label="Dashboard"><Logo /></Link></div>
        <div className="min-h-0 flex-1">{sidebar}</div>
      </aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-sidebar shadow-xl">
            <div className="flex h-14 shrink-0 items-center justify-between border-b px-4">
              <Logo />
              <button className="rounded-md p-2 hover:bg-muted" onClick={() => setMobileOpen(false)} aria-label="Tutup menu"><X className="size-5" /></button>
            </div>
            <div className="min-h-0 flex-1">{sidebar}</div>
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 border-b bg-card/95 backdrop-blur print:hidden">
          <div className="flex h-14 items-center gap-2 px-3 sm:px-6">
            <button className="rounded-md p-2 hover:bg-muted lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Buka menu"><Menu className="size-5" /></button>
            <Link to="/" className="lg:hidden" aria-label="Dashboard"><Logo withText={false} /></Link>
            <div className="hidden min-w-0 flex-1 sm:block"><Breadcrumb pathname={location.pathname} /></div>
            <div className="ml-auto flex items-center gap-1 sm:gap-2">
              <button type="button" onClick={() => setSearching(true)}
                className="flex h-9 items-center gap-2 rounded-md border bg-background px-2.5 text-sm text-muted-foreground hover:bg-muted sm:w-56"
                aria-label="Cari menu">
                <Search className="size-4" />
                <span className="hidden flex-1 text-left sm:inline">Cari menu…</span>
                <kbd className="hidden rounded border bg-card px-1.5 text-[10px] sm:inline">Ctrl K</kbd>
              </button>
              {hasPermission('notification.view_own') && <NotificationBell />}
              <UserMenu onChangePassword={() => setChangingPassword(true)} />
              {changingPassword && <ChangePasswordModal onClose={() => setChangingPassword(false)} />}
            </div>
          </div>
        </header>

        <main className="min-w-0 flex-1 p-3 sm:p-6">
          <div className="mx-auto w-full max-w-[1600px] space-y-4 sm:space-y-5">
            <div className="sm:hidden"><Breadcrumb pathname={location.pathname} /></div>
            <Outlet />
            {/* Back / Forward: the page returns where it was left; a new page opens at the top */}
            <ScrollRestoration />
          </div>
        </main>
      </div>
      <CommandMenu groups={groups} open={searching} onOpenChange={setSearching} />
    </div>
  )
}
