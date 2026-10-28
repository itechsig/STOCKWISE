import { useCallback, useContext, useEffect, useRef, useState } from 'react'
import { UNSAFE_LocationContext } from 'react-router-dom'

/**
 * Lets a list page come back exactly as it was left — search, filters, the
 * rows already loaded while scrolling, and the table's scroll position — when
 * the user returns to it with Back (the sub page's back button or the
 * browser's). Opening the list fresh (sidebar, a link) starts clean as before.
 *
 * Kept in memory for this tab only (a full reload starts clean), keyed per list.
 */
const store = new Map<string, unknown>()

/** True while this list is being re-entered through Back / Forward. */
export function useIsReturning(): boolean {
  // read from the router's context directly so this also works outside a router (e.g. unit tests): never "returning" there
  const type = (useContext(UNSAFE_LocationContext) as { navigationType?: string } | null)?.navigationType
  // decided once, when the page mounts
  const [returning] = useState(type === 'POP')
  return returning
}

/**
 * useState that remembers its value under `list:field`. Restored on Back;
 * otherwise starts from `initial` (and forgets what was remembered).
 */
export function useRemembered<T>(list: string, field: string, initial: T | (() => T)): [T, (v: T | ((prev: T) => T)) => void] {
  const key = `${list}:${field}`
  const returning = useIsReturning()
  const [value, setValue] = useState<T>(() => {
    if (returning && store.has(key)) return store.get(key) as T
    const v = typeof initial === 'function' ? (initial as () => T)() : initial
    store.set(key, v)
    return v
  })
  const set = useCallback((v: T | ((prev: T) => T)) => {
    setValue((prev) => {
      const next = typeof v === 'function' ? (v as (p: T) => T)(prev) : v
      store.set(key, next)
      return next
    })
  }, [key])
  return [value, set]
}

/** Read / write a remembered value without a state hook (e.g. the loaded pages of a list). */
export function recall<T>(key: string): T | undefined {
  return store.get(key) as T | undefined
}
export function remember(key: string, value: unknown): void {
  store.set(key, value)
}

/**
 * Remembers the scrollTop of a scrolling element (a table's own scroll box)
 * under `key`, and puts it back on Back once `ready` (the rows are on screen).
 * Returns a ref callback for the element.
 */
export function useScrollMemory(key: string | undefined, ready: boolean) {
  const returning = useIsReturning()
  const el = useRef<HTMLElement | null>(null)
  const restored = useRef(false)

  useEffect(() => {
    if (!key || restored.current || !ready || !el.current) return
    restored.current = true
    const top = returning ? recall<number>(`${key}:scroll`) : undefined
    if (top) el.current.scrollTop = top
    else remember(`${key}:scroll`, 0)
  }, [key, ready, returning])

  return useCallback((node: HTMLElement | null) => {
    if (el.current === node) return
    el.current = node
    if (node && key) {
      node.addEventListener('scroll', () => { if (restored.current) remember(`${key}:scroll`, node.scrollTop) }, { passive: true })
    }
  }, [key])
}
