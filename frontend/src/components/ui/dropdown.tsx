import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export interface DropdownItem {
  label: string
  icon?: LucideIcon
  onSelect: () => void
  disabled?: boolean
  danger?: boolean
  hidden?: boolean
}

/**
 * A button that opens a short list of actions (Export, row actions…). Closes on
 * choosing, clicking outside or Escape; arrow keys move through the items.
 */
export function DropdownMenu({ label, icon: Icon, items, align = 'end', size = 'sm', variant = 'outline', className, busy }: {
  label: ReactNode
  icon?: LucideIcon
  items: DropdownItem[]
  align?: 'start' | 'end'
  size?: 'sm' | 'default'
  variant?: 'outline' | 'ghost' | 'default'
  className?: string
  busy?: boolean
}) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const shown = items.filter((i) => !i.hidden)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        const btns = [...(ref.current?.querySelectorAll<HTMLButtonElement>('[role=menuitem]:not(:disabled)') ?? [])]
        const at = btns.indexOf(document.activeElement as HTMLButtonElement)
        btns[(at + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length]?.focus()
      }
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey) }
  }, [open])

  if (shown.length === 0) return null
  return (
    <div ref={ref} className={cn('relative inline-block', className)}>
      <Button type="button" size={size} variant={variant} aria-haspopup="menu" aria-expanded={open} disabled={busy} onClick={() => setOpen((v) => !v)}>
        {Icon && <Icon className="size-4" />}{label}<ChevronDown className="size-3.5 opacity-60" />
      </Button>
      {open && (
        <div role="menu" className={cn('absolute z-40 mt-1 min-w-44 rounded-lg border bg-card p-1 shadow-lg', align === 'end' ? 'right-0' : 'left-0')}>
          {shown.map((i) => {
            const I = i.icon
            return (
              <button key={i.label} type="button" role="menuitem" disabled={i.disabled}
                onClick={() => { setOpen(false); i.onSelect() }}
                className={cn('flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm outline-none hover:bg-muted focus-visible:bg-muted disabled:opacity-50',
                  i.danger && 'text-destructive')}>
                {I && <I className="size-4 opacity-70" />}{i.label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
