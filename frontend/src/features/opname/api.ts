import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface OpnameLine {
  id: number
  item_id: number
  item_code: string | null
  description: string | null
  physical_qty: number | null
  note: string | null
  count_status: string
  review_status: string
  /** NPBG / PPB lines of this item (by item code, all time) */
  npbg_count?: number
  ppb_count?: number
  /**
   * Blind count: system_qty and everything derived from it (difference/match_status/diff_label)
   * are only present in the API response for a reviewer (opname.review permission) — the counter
   * (opname.count only, e.g. admin lapangan) gets these keys omitted entirely, not just null'd,
   * so they can never back-calculate system_qty from their own physical_qty.
   */
  system_qty?: number
  difference?: number
  match_status?: 'VALID' | 'INVALID' | null
  diff_label?: string | null
}

export interface Opname {
  id: number
  number: string
  status: string
  type: string
  warehouse?: { id: number; code: string; name?: string } | null
  scheduled_date: string | null
  scheduled_by: string | null
  counter: string | null
  reviewer: string | null
  items_count?: number
  counted_count?: number
  diff_count?: number
  created_at: string | null
  started_at: string | null
  submitted_at: string | null
  reviewed_at: string | null
  review_note: string | null
  items?: OpnameLine[]
  /** Non-null when this row was synced straight from Accurate ITEMADJ/ITADJDET — read-only, no internal start/count/submit/review. */
  accurate_itemadj_id?: number | null
  accurate_synced_at?: string | null
  /** List only: first item of the document (Accurate adjustments are mostly one item). */
  first_item?: { code: string; description: string } | null
}

/** One item line across documents — "Per barang" view (GET /api/stock-opnames/lines). */
export interface OpnameLineRow extends OpnameLine {
  unit: string | null
  opname: {
    id: number
    number: string
    status: string
    date: string | null
    warehouse: string | null
    accurate_itemadj_id: number | null
  }
}

export interface OpnameListFilters {
  source?: 'internal' | 'accurate'
  status?: string
  search?: string
  date_from?: string
  date_to?: string
  /** "Per barang" only: period of the NPBG / PPB counts (date_from/date_to are the opname date) */
  usage_from?: string
  usage_to?: string
  page?: number
}

/** Period of the NPBG / PPB counts — both optional, none = all time. */
export interface UsagePeriod {
  from?: string
  to?: string
}

/** NPBG / PPB lines per item code of one opname, for a period (GET /api/stock-opnames/{id}/usage). */
export function useOpnameUsage(id: number | null, period: UsagePeriod) {
  const on = !!(period.from || period.to)
  return useQuery({
    queryKey: ['opname', id, 'usage', period],
    enabled: id != null && on,
    queryFn: async () => (await api.get<{ data: { counts: Record<string, { npbg: number; ppb: number }> } }>(
      `/api/stock-opnames/${id}/usage`, { params: { date_from: period.from || undefined, date_to: period.to || undefined } },
    )).data.data.counts,
    placeholderData: keepPreviousData,
  })
}

export function useOpnameLines(params: OpnameListFilters & { only_diff?: boolean }, enabled = true) {
  return useQuery({
    queryKey: ['opnames', 'lines', params],
    queryFn: async () => (await api.get<{ data: OpnameLineRow[]; meta: { page: number; last_page: number; total: number } }>(
      '/api/stock-opnames/lines', { params: { ...params, only_diff: params.only_diff ? 1 : undefined } },
    )).data,
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useOpnames(params: OpnameListFilters, enabled = true) {
  return useQuery({
    queryKey: ['opnames', params],
    queryFn: async () => (await api.get<{ data: Opname[]; meta: { page: number; last_page: number; total: number } }>(
      '/api/stock-opnames', { params },
    )).data,
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useOpname(id: number | null) {
  return useQuery({
    queryKey: ['opname', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Opname }>(`/api/stock-opnames/${id}`)).data.data,
  })
}

export function useOpnameMutations(id: number) {
  const qc = useQueryClient()
  // Every action returns the full opname (show()) -> put it straight into the cache
  // instead of re-fetching thousands of lines after each count.
  const apply = (res: { data: { data: Opname } }) => {
    qc.setQueryData(['opname', id], res.data.data)
    qc.invalidateQueries({ queryKey: ['opnames'] })
  }
  return {
    start: useMutation({
      mutationFn: () => api.post<{ data: Opname }>(`/api/stock-opnames/${id}/start`),
      onSuccess: apply,
      meta: { successMessage: 'Penghitungan dimulai. Isi jumlah fisik tiap barang.' },
    }),
    submit: useMutation({
      mutationFn: () => api.post<{ data: Opname }>(`/api/stock-opnames/${id}/submit`),
      onSuccess: apply,
      meta: { successMessage: 'Hasil hitung dikirim — menunggu review admin gudang.' },
    }),
    // saved per row on leaving the field; the row itself shows "Tersimpan" / the error
    count: useMutation({
      mutationFn: (p: { lineId: number; physical_qty: number; note?: string }) =>
        api.put<{ data: Opname }>(`/api/stock-opnames/${id}/items/${p.lineId}`, { physical_qty: p.physical_qty, note: p.note }),
      onSuccess: apply,
      meta: { silent: true },
    }),
    review: useMutation({
      mutationFn: (p: { decisions: Array<{ id: number; decision: string }>; note?: string }) =>
        api.post<{ data: Opname }>(`/api/stock-opnames/${id}/review`, p),
      onSuccess: apply,
      meta: { successMessage: 'Review selesai.' },
    }),
  }
}

export function useCreateOpname() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (p: { warehouse_id: number; scheduled_date: string; type: string; item_ids?: number[] }) =>
      (await api.post<{ data: Opname }>('/api/stock-opnames', p)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['opnames'] }),
  })
}

/** All active item IDs matching a Master Barang filter — for bulk-adding a whole category etc. to a custom opname. */
export async function fetchItemIds(filters: {
  search?: string
  accurate_category_anak_1?: string
  accurate_category_anak_2?: string
  accurate_category_anak_3?: string
  unit_id?: number
}): Promise<number[]> {
  return (await api.get<{ data: number[] }>('/api/items/ids', { params: filters })).data.data
}
