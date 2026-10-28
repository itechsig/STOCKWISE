import { Children, useState, type ReactNode } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * The filter toolbar above a list: search, dates, selects and the like on one white
 * panel. `end` sits on the right (counts, column picker, reset).
 * On a phone only the first filter (the search) shows; the others fold behind a
 * "Filter" button so the data is in sight at once.
 */
export function FilterBar({ children, end, className }: { children: ReactNode; end?: ReactNode; className?: string }) {
  const [open, setOpen] = useState(false)
  const [first, ...rest] = Children.toArray(children)
  return (
    <div className={cn('flex flex-wrap items-end gap-2 rounded-lg border bg-card p-3', className)}>
      {/* phone: the search takes the room beside the Filter button; wider screens: as laid out */}
      <div className="max-md:min-w-0 max-md:flex-1 md:contents">{first}</div>
      {rest.length > 0 && (
        <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open}
          className={cn('flex h-9 items-center gap-1.5 rounded-md border px-3 text-sm md:hidden', open ? 'border-primary bg-primary/5 text-primary' : 'bg-card text-foreground')}>
          <SlidersHorizontal className="size-4" /> Filter
        </button>
      )}
      {/* `contents` keeps the filters in the toolbar's own flex / grid layout on wider screens */}
      <div className={cn('contents', !open && 'max-md:hidden')}>{rest}</div>
      {end && <div className="ml-auto flex flex-wrap items-center gap-2">{end}</div>}
    </div>
  )
}

/** A labelled filter in a FilterBar. */
export function FilterField({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={cn('space-y-1', className)}>
      <span className="block text-xs font-medium text-muted-foreground">{label}</span>
      {children}
    </div>
  )
}
