import { createContext, useContext } from 'react'
import { api } from '@/lib/api'

export type ShareAccess = 'VIEW' | 'EDIT' | 'FULL'

/**
 * Where the Kartu Stock pages talk to: the logged-in API, or a project's share
 * link (no account — /api/public/kartu-stock/{token}). The same pages serve both.
 */
export type StockCardScope = { kind: 'auth' } | { kind: 'share'; token: string }

const ScopeContext = createContext<StockCardScope>({ kind: 'auth' })
export const StockCardScopeProvider = ScopeContext.Provider
export const useStockCardScope = () => useContext(ScopeContext)

/** Part of every query key, so a link's data never mixes with the logged-in cache. */
export const scopeKey = (s: StockCardScope) => (s.kind === 'auth' ? 'auth' : `share:${s.token}`)

/** API paths. */
export function apiPaths(s: StockCardScope) {
  if (s.kind === 'auth') {
    return {
      project: (id: number) => `/api/stock-card-projects/${id}`,
      projectPdf: (id: number) => `/api/stock-card-projects/${id}/pdf`,
      card: (id: number) => `/api/stock-cards/${id}`,
      cards: '/api/stock-cards',
      items: '/api/stock-card/items',
      nextNumber: '/api/stock-cards/next-number',
      files: '/api/tracking-photos',
      file: (id: number) => `/api/tracking-photos/${id}`,
    }
  }
  const base = `/api/public/kartu-stock/${encodeURIComponent(s.token)}`
  return {
    project: () => base,
    projectPdf: () => `${base}/pdf`,
    card: (id: number) => `${base}/cards/${id}`,
    cards: `${base}/cards`,
    items: `${base}/items`,
    nextNumber: `${base}/next-number`,
    files: `${base}/files`,
    file: (id: number) => `${base}/files/${id}`,
  }
}

/** Page paths. */
export function routePaths(s: StockCardScope) {
  if (s.kind === 'auth') {
    return {
      home: '/stock-card',
      project: (id: number) => `/stock-card/project/${id}`,
      card: (id: number) => `/stock-card/${id}`,
    }
  }
  const base = `/share/kartu-stock/${encodeURIComponent(s.token)}`
  return { home: base, project: () => base, card: (id: number) => `${base}/kartu/${id}` }
}

export const useApiPaths = () => apiPaths(useStockCardScope())
export const useRoutePaths = () => routePaths(useStockCardScope())

/** Attach a photo / PDF to a line. */
export async function uploadEntryFile(s: StockCardScope, entryId: number, dataUrl: string, fileName: string) {
  const body = s.kind === 'auth'
    ? { entity: 'stock_card_entry', entity_id: entryId, slot: 'auto', photo: dataUrl, file_name: fileName }
    : { entity_id: entryId, photo: dataUrl, file_name: fileName }
  await api.post(apiPaths(s).files, body)
}

/** Open an attachment in a new tab (fetched with the right access, then shown as a blob). */
export async function openEntryFile(s: StockCardScope, id: number) {
  const w = window.open('', '_blank')
  const res = await api.get(apiPaths(s).file(id), { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  if (w) w.location.href = url
  else window.location.href = url
}

export const deleteEntryFile = (s: StockCardScope, id: number) => api.delete(apiPaths(s).file(id))

/** The full share URL of a token, to copy and send. */
export const shareUrl = (token: string) => `${window.location.origin}/share/kartu-stock/${token}`
