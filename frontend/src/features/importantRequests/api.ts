import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

export type IrStatus = 'DRAFT' | 'MENUNGGU_KONFIRMASI' | 'DITERIMA' | 'IN_PROGRESS' | 'TERKENDALA' | 'MENUNGGU_REVIEW' | 'REVISI' | 'SELESAI' | 'DIBATALKAN'
export type IrPriority = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT'
export type IrScope = 'mine' | 'tasks' | 'review' | 'done' | 'all'

export const IR_STATUS: Record<IrStatus, { label: string; variant: 'default' | 'neutral' | 'success' | 'warning' | 'danger' }> = {
  DRAFT: { label: 'Draft', variant: 'neutral' },
  MENUNGGU_KONFIRMASI: { label: 'Menunggu konfirmasi', variant: 'warning' },
  DITERIMA: { label: 'Diterima', variant: 'default' },
  IN_PROGRESS: { label: 'In progress', variant: 'default' },
  TERKENDALA: { label: 'Terkendala', variant: 'danger' },
  MENUNGGU_REVIEW: { label: 'Menunggu review', variant: 'warning' },
  REVISI: { label: 'Revisi', variant: 'warning' },
  SELESAI: { label: 'Selesai', variant: 'success' },
  DIBATALKAN: { label: 'Dibatalkan', variant: 'neutral' },
}

export const IR_PRIORITY: Record<IrPriority, { label: string; cls: string }> = {
  LOW: { label: 'Low', cls: 'bg-slate-100 text-slate-700 ring-slate-200' },
  MEDIUM: { label: 'Medium', cls: 'bg-blue-50 text-blue-700 ring-blue-200' },
  HIGH: { label: 'High', cls: 'bg-orange-50 text-orange-700 ring-orange-200' },
  URGENT: { label: 'Urgent', cls: 'bg-red-50 text-red-700 ring-red-200' },
}

export const IR_SCOPES: Record<Exclude<IrScope, 'all'>, { label: string; path: string; empty: string }> = {
  mine: { label: 'Permintaan Saya', path: '/important-requests/saya', empty: 'Anda belum membuat request.' },
  tasks: { label: 'Tugas Saya', path: '/important-requests/tugas', empty: 'Belum ada tugas untuk Anda.' },
  review: { label: 'Perlu Persetujuan', path: '/important-requests/persetujuan', empty: 'Tidak ada hasil yang menunggu persetujuan Anda.' },
  done: { label: 'Selesai', path: '/important-requests/selesai', empty: 'Belum ada request selesai.' },
}

export interface IrRow {
  id: number
  number: string
  title: string
  priority: IrPriority
  status: IrStatus
  status_label: string
  deadline: string | null
  overdue: boolean
  creator: string | null
  created_by: number
  created_at: string
  updated_at: string
  assignee_names: string[]
  assignees_done: number
  assignees_total: number
  my_status: IrStatus | null
  my_status_label: string | null
  unread_comments: number
}

export interface IrFile {
  id: number
  kind: 'ORIGINAL' | 'DISCUSSION' | 'SUBMISSION' | 'REVISION'
  name: string
  size: number
  mime: string | null
  extension: string | null
  uploader: string | null
  uploaded_by: number
  created_at: string
  previewable: boolean
}

export interface IrAssignee {
  id: number
  user_id: number
  name: string | null
  username: string | null
  position: string | null
  status: IrStatus
  status_label: string
  progress: number
  blocker_note: string | null
  confirmed_at: string | null
  started_at: string | null
  submitted_at: string | null
  completed_at: string | null
}

export interface IrSubmission {
  id: number
  round: number
  assignee_id: number
  user: string | null
  note: string | null
  status: 'MENUNGGU_REVIEW' | 'DISETUJUI' | 'REVISI' | 'OTOMATIS'
  reviewed_by: string | null
  reviewed_at: string | null
  review_note: string | null
  created_at: string
  files: IrFile[]
  can_review: boolean
}

export interface IrDetail extends IrRow {
  description: string
  category: string | null
  note: string | null
  requires_approval: boolean
  assignment_mode: 'INDIVIDUAL' | 'COLLECTIVE'
  sent_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  assignees: IrAssignee[]
  attachments: IrFile[]
  submissions: IrSubmission[]
  me: {
    is_creator: boolean
    assignee: { id: number; status: IrStatus; progress: number } | null
    can_edit: boolean; can_send: boolean; can_delete: boolean; can_cancel: boolean; can_reopen: boolean; can_comment: boolean
    max_mb: number
  }
}

export interface IrComment {
  id: number
  parent_id: number | null
  kind: 'COMMENT' | 'PROGRESS' | 'BLOCKER' | 'SYSTEM'
  user: string | null
  user_id: number
  body: string | null
  deleted: boolean
  edited: boolean
  created_at: string
  is_new: boolean
  can_edit: boolean
  can_delete: boolean
  files: IrFile[]
  replies?: IrComment[]
}

export interface IrSummary {
  badge: number
  action: { to_confirm: number; to_revise: number; to_review: number; my_open_tasks: number }
  stats: { total: number; unconfirmed: number; in_progress: number; blocked: number; overdue: number; waiting_review: number; done: number }
  latest: IrRow[]
  urgent: IrRow[]
  near_deadline: IrRow[]
  can_create: boolean
}

export interface IrRecipient { id: number; name: string; username: string; position: string | null; division: string | null }

export interface IrPayload {
  title?: string
  description?: string
  priority?: IrPriority
  category?: string | null
  deadline?: string | null
  requires_approval?: boolean
  assignment_mode?: 'INDIVIDUAL' | 'COLLECTIVE'
  note?: string | null
  assignee_ids?: number[]
  attachments?: string[]
  send?: boolean
}

const KEY = ['important-requests']

export function useIrSummary(enabled = true) {
  return useQuery({
    queryKey: [...KEY, 'summary'],
    enabled,
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
    queryFn: async () => (await api.get<{ data: IrSummary }>('/api/important-requests/summary')).data.data,
  })
}

export function useIrList(params: { scope: IrScope; status?: string; priority?: string; overdue?: boolean; search?: string; sort?: string; page?: number }) {
  return useQuery({
    queryKey: [...KEY, 'list', params],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get<{ data: IrRow[]; meta: { page: number; last_page: number; total: number } }>('/api/important-requests', {
      params: { ...params, overdue: params.overdue ? 1 : undefined },
    })).data,
  })
}

export function useIr(id: number) {
  return useQuery({
    queryKey: [...KEY, id],
    enabled: id > 0,
    queryFn: async () => (await api.get<{ data: IrDetail }>(`/api/important-requests/${id}`)).data.data,
  })
}

export function useIrRecipients(enabled: boolean) {
  return useQuery({
    queryKey: [...KEY, 'recipients'],
    enabled,
    staleTime: 5 * 60_000,
    queryFn: async () => (await api.get<{ data: IrRecipient[] }>('/api/important-requests/recipients')).data.data,
  })
}

export function useIrComments(id: number, page: number) {
  return useQuery({
    queryKey: [...KEY, id, 'comments', page],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get<{ data: IrComment[]; meta: { page: number; last_page: number; total: number } }>(`/api/important-requests/${id}/comments`, { params: { page } })).data,
  })
}

export function useIrActivities(id: number, enabled: boolean) {
  return useQuery({
    queryKey: [...KEY, id, 'activities'],
    enabled,
    queryFn: async () => (await api.get<{ data: { id: number; action: string; user: string; meta: Record<string, unknown> | null; created_at: string }[] }>(`/api/important-requests/${id}/activities`)).data.data,
  })
}

export function useIrAnalytics(months: number) {
  return useQuery({
    queryKey: [...KEY, 'analytics', months],
    queryFn: async () => (await api.get<{ data: IrAnalytics }>('/api/important-requests/analytics', { params: { months } })).data.data,
  })
}

export interface IrAnalytics {
  months: number
  definitions: Record<string, string>
  total: number
  done: number
  completion_rate: number | null
  avg_hours: number | null
  overdue_open: number
  late_done: number
  revised: number
  by_divisi: { divisi: string; n: number }[]
  workload: { name: string; open: number; overdue: number }[]
  trend: { month: string; dibuat: number; selesai: number }[]
}

/** Every write refreshes the request, its lists and the dashboard. */
function useIrWrite<T, R = unknown>(fn: (body: T) => Promise<R>) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, meta: { successMessage: false }, onSuccess: () => qc.invalidateQueries({ queryKey: KEY }) })
}

export const useCreateIr = () => useIrWrite((b: IrPayload) => api.post<{ data: IrDetail }>('/api/important-requests', b).then((r) => r.data.data))
export const useUpdateIr = (id: number) => useIrWrite((b: IrPayload) => api.put<{ data: IrDetail }>(`/api/important-requests/${id}`, b).then((r) => r.data.data))
export const useDeleteIr = (id: number) => useIrWrite(() => api.delete(`/api/important-requests/${id}`))
export const useSendIr = (id: number) => useIrWrite(() => api.post(`/api/important-requests/${id}/send`))
export const useIrAct = (id: number) => useIrWrite((b: { action: string; progress?: number; note?: string | null; reason?: string | null; proposed_deadline?: string | null; attachments?: string[] }) =>
  api.post(`/api/important-requests/${id}/act`, b))
export const useIrReview = (id: number) => useIrWrite((b: { submission: number; approve: boolean; note?: string | null }) =>
  api.post(`/api/important-requests/${id}/submissions/${b.submission}/review`, { approve: b.approve, note: b.note }))
export const useIrDeadline = (id: number) => useIrWrite((b: { deadline: string; reason: string }) => api.post(`/api/important-requests/${id}/deadline`, b))
export const useIrCancel = (id: number) => useIrWrite((b: { reason: string }) => api.post(`/api/important-requests/${id}/cancel`, b))
export const useIrReopen = (id: number) => useIrWrite((b: { reason: string }) => api.post(`/api/important-requests/${id}/reopen`, b))
export const useIrAddFiles = (id: number) => useIrWrite((b: { attachments: string[] }) => api.post(`/api/important-requests/${id}/attachments`, b))
export const useIrDeleteFile = (id: number) => useIrWrite((fileId: number) => api.delete(`/api/important-requests/${id}/attachments/${fileId}`))
export const useIrComment = (id: number) => useIrWrite((b: { body: string; parent_id?: number | null; attachments?: string[] }) => api.post(`/api/important-requests/${id}/comments`, b))
export const useIrEditComment = (id: number) => useIrWrite((b: { comment: number; body: string }) => api.put(`/api/important-requests/${id}/comments/${b.comment}`, { body: b.body }))
export const useIrDeleteComment = (id: number) => useIrWrite((comment: number) => api.delete(`/api/important-requests/${id}/comments/${comment}`))

/**
 * Upload one file in chunks (the server takes at most 2 MB a request): returns its token, to be
 * attached by the next save. onProgress gets 0..1.
 */
export async function uploadIrFile(file: File, onProgress?: (p: number) => void): Promise<string> {
  const start = (await api.post<{ data: { token: string; chunk: number } }>('/api/important-request-uploads', { name: file.name, size: file.size })).data.data
  const size = Math.min(start.chunk, 1.5 * 1024 * 1024)
  for (let offset = 0; offset < file.size; offset += size) {
    const part = file.slice(offset, offset + size)
    await api.post(`/api/important-request-uploads/${start.token}`, part, { params: { offset }, headers: { 'Content-Type': 'application/octet-stream' } })
    onProgress?.(Math.min(1, (offset + part.size) / file.size))
  }
  return start.token
}

/** A file of a request, fetched with the session: opened (preview) or saved. */
export async function openIrFile(requestId: number, f: IrFile, preview: boolean) {
  const res = await api.get(`/api/important-requests/${requestId}/attachments/${f.id}`, { params: { inline: preview ? 1 : undefined }, responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  if (preview) {
    window.open(url, '_blank', 'noopener')
  } else {
    const a = document.createElement('a')
    a.href = url
    a.download = f.name
    a.click()
  }
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}
