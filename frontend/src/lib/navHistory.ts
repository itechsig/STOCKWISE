/**
 * The in-app location before the current one (pathname + search), so a page's
 * "back" control knows whether the user came from the list it points to — then
 * going back keeps that list's filters and scroll; otherwise it opens the list.
 * Fed by router.subscribe() in router.tsx.
 */
let current: string | null = null
let previous: string | null = null

export function recordLocation(pathname: string, search: string): void {
  const next = pathname + search
  if (next === current) return
  previous = current
  current = next
}

export function previousLocation(): string | null {
  return previous
}
