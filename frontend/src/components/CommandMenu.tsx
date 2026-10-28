import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { CornerDownLeft, Search } from 'lucide-react'
import type { NavGroup } from '@/lib/navigation'
import { cn } from '@/lib/utils'

/**
 * Menu search (Ctrl/⌘ K): type part of a menu name — or what it is about
 * ("barang keluar" finds NPBG) — and Enter opens it. Only the menus this user can open.
 */
export function CommandMenu({ groups, open, onOpenChange }: { groups: NavGroup[]; open: boolean; onOpenChange: (v: boolean) => void }) {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [at, setAt] = useState(0)
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        onOpenChange(!open)
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onOpenChange])

  useEffect(() => { if (open) { setQ(''); setAt(0); setTimeout(() => input.current?.focus(), 0) } }, [open])

  const items = useMemo(() => {
    const words = q.toLowerCase().split(/\s+/).filter(Boolean)
    return groups.flatMap((g) => g.items.flatMap((i) => [
      { ...i, group: g.label ?? '' },
      // sub menus are found too ("jasa" → Master Produk › Jasa)
      ...(i.children ?? []).map((c) => ({ ...i, to: c.to, label: `${i.label} › ${c.label}`, keywords: c.keywords, children: undefined, group: g.label ?? '' })),
    ]))
      .filter((i) => words.every((w) => `${i.label} ${i.group} ${i.keywords ?? ''}`.toLowerCase().includes(w)))
  }, [groups, q])

  if (!open) return null
  const go = (to: string) => { onOpenChange(false); navigate(to) }

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/30 p-4 pt-[12vh]" onMouseDown={() => onOpenChange(false)}>
      <div className="w-full max-w-lg overflow-hidden rounded-lg border bg-card shadow-2xl" role="dialog" aria-modal="true" aria-label="Cari menu" onMouseDown={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2 border-b px-3">
          <Search className="size-4 shrink-0 text-muted-foreground" />
          <input ref={input} value={q} placeholder="Cari menu, mis. NPBG, barang keluar, approval…"
            className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            onChange={(e) => { setQ(e.target.value); setAt(0) }}
            onKeyDown={(e) => {
              if (e.key === 'Escape') onOpenChange(false)
              if (e.key === 'ArrowDown') { e.preventDefault(); setAt((a) => Math.min(a + 1, items.length - 1)) }
              if (e.key === 'ArrowUp') { e.preventDefault(); setAt((a) => Math.max(a - 1, 0)) }
              if (e.key === 'Enter' && items[at]) go(items[at].to)
            }} />
          <kbd className="hidden rounded border bg-muted px-1.5 text-[10px] text-muted-foreground sm:block">ESC</kbd>
        </div>
        <ul className="max-h-80 overflow-y-auto p-1">
          {items.length === 0 && <li className="px-3 py-6 text-center text-sm text-muted-foreground">Menu tidak ditemukan.</li>}
          {items.map((i, idx) => {
            const Icon = i.icon
            return (
              <li key={i.to}>
                <button type="button" onMouseEnter={() => setAt(idx)} onClick={() => go(i.to)}
                  className={cn('flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm', idx === at ? 'bg-primary/10 text-primary' : 'text-foreground')}>
                  <Icon className="size-4 shrink-0 opacity-70" />
                  <span className="flex-1">{i.label}</span>
                  {i.group && <span className="text-xs text-muted-foreground">{i.group}</span>}
                  {idx === at && <CornerDownLeft className="size-3.5 opacity-60" />}
                </button>
              </li>
            )
          })}
        </ul>
      </div>
    </div>,
    document.body,
  )
}
