import { useEffect, useRef, useState } from 'react'
import { MoreVertical } from 'lucide-react'
import { cn } from '@/lib/utils'

export interface MenuAction {
  label: string
  icon?: React.ReactNode
  onClick: () => void
  danger?: boolean
  disabled?: boolean
}

/** "⋯" menu for the secondary actions on a phone (Cetak, PDF, Ubah, Hapus …). */
export function ActionMenu({ actions, className }: { actions: MenuAction[]; className?: string }) {
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent | TouchEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', close)
    document.addEventListener('touchstart', close)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('touchstart', close) }
  }, [open])
  const shown = actions.filter(Boolean)
  if (shown.length === 0) return null

  return (
    <div ref={box} className={cn('relative', className)}>
      <button type="button" onClick={() => setOpen((o) => !o)} aria-label="Menu lainnya"
        className="inline-flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground active:bg-muted">
        <MoreVertical className="size-5" />
      </button>
      {open && (
        <div className="absolute right-0 z-40 mt-1 w-56 overflow-hidden rounded-lg border bg-card py-1 shadow-xl">
          {shown.map((a) => (
            <button key={a.label} type="button" disabled={a.disabled}
              onClick={() => { setOpen(false); a.onClick() }}
              className={cn('flex w-full items-center gap-3 px-4 py-3 text-left text-sm active:bg-muted disabled:opacity-50', a.danger ? 'text-destructive' : 'text-foreground')}>
              {a.icon}{a.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

/** Fixed bottom bar for the main action on a phone (hidden from md up). */
export function MobileBottomBar({ children }: { children: React.ReactNode }) {
  return (
    <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur md:hidden">
      {children}
    </div>
  )
}
