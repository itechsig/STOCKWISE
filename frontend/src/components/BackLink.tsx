import { useNavigate } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { previousLocation } from '@/lib/navHistory'
import { cn } from '@/lib/utils'

/**
 * The "back" control of every sub page (a document detail, a project, a card):
 * an arrow plus where it goes ("Daftar NPBG"), always in the same place above
 * the page title. Came from that list? It steps back in history like the
 * browser's Back (the list comes back at about the same scroll position).
 * Came from anywhere else (dashboard, a link, a fresh tab)? It opens the list
 * itself, never the unrelated page before.
 */
export function BackLink({ to, label, className }: { to: string; label: string; className?: string }) {
  const navigate = useNavigate()

  function goBack(e: React.MouseEvent<HTMLAnchorElement>) {
    // let ctrl/cmd/middle-click open the list in a new tab as a normal link does
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
    e.preventDefault()
    const prev = previousLocation()
    const prevPath = prev?.split('?')[0]
    const canStepBack = (window.history.state?.idx ?? 0) > 0
    if (canStepBack && prevPath === to.split('?')[0]) navigate(-1)
    else navigate(to)
  }

  return (
    <a
      href={to}
      onClick={goBack}
      title={`Kembali ke ${label}`}
      className={cn(
        'group inline-flex max-w-full items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-primary',
        'focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 print:hidden',
        className,
      )}
    >
      <ArrowLeft className="size-4 shrink-0 transition-transform group-hover:-translate-x-0.5" />
      <span className="truncate">{label}</span>
    </a>
  )
}
