import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { apiPaths, scopeKey, useStockCardScope, type ShareAccess, type StockCardScope } from './scope'

/** One handwritten line of a Kartu Stock (backend StockCardService). */
export interface StockCardRow {
  id: number
  date: string
  doc_no: string | null
  description: string | null
  masuk: number
  keluar: number
  /** running balance in card order (date, then as written) */
  sisa: number
  /** uploaded files (photos / PDFs), any number, in upload order */
  files: { id: number; file_name: string | null; mime: string | null }[]
  /** when the line was last saved */
  updated_at: string | null
}

export interface StockCardProjectRef {
  id: number
  name: string
  code: string | null
  status?: string
}

export interface StockCardProject extends StockCardProjectRef {
  status: 'ACTIVE' | 'CLOSED'
  cards_count: number
}

export interface StockCardHeader {
  project_id?: number | null
  card_number: string
  location_warehouse: string | null
  location_rack: string | null
  person_in_charge: string | null
  director_name: string | null
}

export interface StockCard extends StockCardHeader {
  id: number
  project: StockCardProjectRef | null
  item: { id: number; code: string; description: string; unit: string | null; accurate_qty: number | null }
  rows: StockCardRow[]
  total_masuk: number
  total_keluar: number
  sisa: number
  can_edit?: boolean
  /** delete lines / attachments / the card (a share link needs FULL) */
  can_delete?: boolean
  updated_at: string | null
}

export interface StockCardListRow {
  id: number
  card_number: string
  project?: StockCardProjectRef | null
  item: { id: number; code: string; description: string; unit: string | null }
  location_warehouse: string | null
  location_rack: string | null
  person_in_charge: string | null
  entries_count: number
  total_masuk: number
  total_keluar: number
  sisa: number
  updated_at: string | null
}

export interface StockCardEntryInput {
  entry_date: string
  doc_no: string | null
  description: string | null
  qty_in: number | null
  qty_out: number | null
}

export function useStockCards(search: string, page: number, projectId?: number | 'none') {
  return useQuery({
    queryKey: ['stock-cards', search, page, projectId],
    queryFn: async () => (await api.get<{ data: StockCardListRow[]; meta: { page: number; last_page: number; total: number } }>('/api/stock-cards', {
      params: { search: search || undefined, page, project_id: projectId },
    })).data,
    placeholderData: keepPreviousData,
  })
}

export function useStockCard(id: number | null) {
  const scope = useStockCardScope()
  return useQuery({
    queryKey: ['stock-card', scopeKey(scope), id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: StockCard }>(apiPaths(scope).card(id!))).data.data,
  })
}

export async function nextStockCardNumber(scope: StockCardScope, itemId: number, projectId: number): Promise<string> {
  return (await api.get<{ data: string }>(apiPaths(scope).nextNumber, { params: { item_id: itemId, project_id: projectId } })).data.data
}

export function useCreateStockCard() {
  const qc = useQueryClient()
  const scope = useStockCardScope()
  return useMutation({
    mutationFn: async (body: StockCardHeader & { item_id: number; project_id: number }) => (await api.post<{ data: StockCard }>(apiPaths(scope).cards, body)).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stock-cards'] })
      qc.invalidateQueries({ queryKey: ['stock-card-projects'] })
    },
    meta: { successMessage: 'Kartu stock dibuat.' },
  })
}

/** Header / line changes all answer with the whole card -> straight into the cache. */
export function useStockCardMutations(id: number) {
  const qc = useQueryClient()
  const scope = useStockCardScope()
  const card = apiPaths(scope).card(id)
  const apply = (data: StockCard) => {
    qc.setQueryData(['stock-card', scopeKey(scope), id], data)
    qc.invalidateQueries({ queryKey: ['stock-cards'] })
    qc.invalidateQueries({ queryKey: ['stock-card-projects'] })
  }
  return {
    update: useMutation({
      mutationFn: async (body: StockCardHeader) => (await api.put<{ data: StockCard }>(card, body)).data.data,
      onSuccess: apply,
      meta: { successMessage: 'Data kartu disimpan.' },
    }),
    remove: useMutation({
      mutationFn: async () => api.delete(card),
      onSuccess: () => {
        qc.invalidateQueries({ queryKey: ['stock-cards'] })
        qc.invalidateQueries({ queryKey: ['stock-card-projects'] })
      },
      meta: { successMessage: 'Kartu stock dihapus.' },
    }),
    addEntry: useMutation({
      mutationFn: async (body: StockCardEntryInput) => (await api.post<{ data: StockCard }>(`${card}/entries`, body)).data.data,
      onSuccess: apply,
    }),
    updateEntry: useMutation({
      mutationFn: async ({ entryId, ...body }: StockCardEntryInput & { entryId: number }) =>
        (await api.put<{ data: StockCard }>(`${card}/entries/${entryId}`, body)).data.data,
      onSuccess: apply,
    }),
    removeEntry: useMutation({
      mutationFn: async (entryId: number) => (await api.delete<{ data: StockCard }>(`${card}/entries/${entryId}`)).data.data,
      onSuccess: apply,
    }),
  }
}

// ---- projects: a project holds many cards, one per item

export function useStockCardProjects(search = '', enabled = true) {
  return useQuery({
    queryKey: ['stock-card-projects', search],
    enabled,
    queryFn: async () => (await api.get<{ data: StockCardProject[]; unassigned_cards: number }>('/api/stock-card-projects', { params: { search: search || undefined } })).data,
    placeholderData: keepPreviousData,
  })
}

export interface StockCardProjectDetail extends StockCardProject {
  /** change / delete the project itself (never through a share link) */
  can_edit: boolean
  can_create_card?: boolean
  /** set when opened through a share link */
  share?: { access: ShareAccess; label: string | null }
  cards: StockCardListRow[]
}

export function useStockCardProject(id: number | null) {
  const scope = useStockCardScope()
  return useQuery({
    queryKey: ['stock-card-projects', 'detail', scopeKey(scope), id],
    enabled: id != null || scope.kind === 'share',
    queryFn: async () => (await api.get<{ data: StockCardProjectDetail }>(apiPaths(scope).project(id ?? 0))).data.data,
  })
}

export type StockCardProjectInput = { name: string; code: string | null; status?: 'ACTIVE' | 'CLOSED' }

export function useSaveStockCardProject(id?: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: StockCardProjectInput) => (id
      ? await api.put<{ data: StockCardProject }>(`/api/stock-card-projects/${id}`, body)
      : await api.post<{ data: StockCardProject }>('/api/stock-card-projects', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['stock-card-projects'] }),
    meta: { successMessage: id ? 'Proyek disimpan.' : 'Proyek dibuat.' },
  })
}

export function useDeleteStockCardProject(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => api.delete(`/api/stock-card-projects/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['stock-card-projects'] }),
    meta: { successMessage: 'Proyek dihapus.' },
  })
}

/** PDF of one card, or of every card of a project (one per page) — downloaded, or opened to print. */
export async function stockCardPdf(scope: StockCardScope, target: { id: number; card_number: string } | { projectId: number; name: string }, mode: 'download' | 'print') {
  const isProject = 'projectId' in target
  const w = mode === 'print' ? window.open('', '_blank') : null
  const paths = apiPaths(scope)
  const res = await api.get(isProject ? paths.projectPdf(target.projectId) : `${paths.card(target.id)}/pdf`, { responseType: 'blob' })
  const url = URL.createObjectURL(new Blob([res.data as BlobPart], { type: 'application/pdf' }))
  if (mode === 'print') {
    if (w) w.location.href = url
    else window.open(url, '_blank')
    return
  }
  const a = document.createElement('a')
  a.href = url
  a.download = `Kartu-Stock-${(isProject ? target.name : target.card_number).replace(/[^A-Za-z0-9._-]+/g, '-')}.pdf`
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10_000)
}

// ---- share links of a project (made by a user who may edit Kartu Stock)

export interface StockCardShareLink {
  id: number
  token: string
  access: ShareAccess
  label: string | null
  created_by: string | null
  created_at: string
  last_used_at: string | null
}

export function useStockCardShareLinks(projectId: number, enabled: boolean) {
  return useQuery({
    queryKey: ['stock-card-share-links', projectId],
    enabled,
    queryFn: async () => (await api.get<{ data: StockCardShareLink[] }>(`/api/stock-card-projects/${projectId}/share-links`)).data.data,
  })
}

export function useStockCardShareLinkMutations(projectId: number) {
  const qc = useQueryClient()
  const done = () => qc.invalidateQueries({ queryKey: ['stock-card-share-links', projectId] })
  return {
    create: useMutation({
      mutationFn: async (body: { access: ShareAccess; label: string | null }) =>
        (await api.post<{ data: StockCardShareLink }>(`/api/stock-card-projects/${projectId}/share-links`, body)).data.data,
      onSuccess: done,
    }),
    update: useMutation({
      mutationFn: async ({ id, ...body }: { id: number; access: ShareAccess; label: string | null }) =>
        (await api.put<{ data: StockCardShareLink }>(`/api/stock-card-share-links/${id}`, body)).data.data,
      onSuccess: done,
    }),
    remove: useMutation({
      mutationFn: async (id: number) => api.delete(`/api/stock-card-share-links/${id}`),
      onSuccess: done,
 meta: { successMessage: 'Link dihapus tidak bisa dibuka lagi.' },
    }),
  }
}
