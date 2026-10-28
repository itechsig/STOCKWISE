import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

export interface SafetyStockRow {
  id: number
  item_id: number
  item_code: string | null
  item_description: string | null
  source_category: string | null
  period_label: string | null
  avg_usage_1m: number | null
  avg_usage_3m: number | null
  avg_usage_6m: number | null
  avg_usage_12m: number | null
  lead_time_days: number | null
  sqrt_lt: number | null
  safety_stock: number
  min_pr: number | null
  effective_date: string | null
  is_effective: boolean
  needs_review: boolean
  note: string | null
}

export interface SafetyStockFilters {
  search?: string
  source_category?: string
  is_effective?: '0' | '1'
  needs_review?: '0' | '1'
  page?: number
  per_page?: number
}

export function useSafetyStockList(filters: SafetyStockFilters) {
  return useQuery({
    queryKey: ['safety-stocks', filters],
    queryFn: async () => (await api.get<Paginated<SafetyStockRow>>('/api/safety-stocks', { params: filters })).data,
    placeholderData: keepPreviousData,
  })
}

export interface SafetyStockPayload {
  item_id: number
  source_category?: string
  period_label?: string
  // avg_usage_3m (bukan _1m) yang masuk rumus Safety Stock/MIN PR di backend
  // (SafetyStockController@store mewajibkan field ini) — avg_usage_1m/6m/12m
  // hanya informasi. Lihat SafetyStockService untuk alasan pemilihan 3 bulan.
  avg_usage_3m: number
  avg_usage_1m?: number
  avg_usage_6m?: number
  avg_usage_12m?: number
  lead_time_days: number
  effective_date?: string
  note?: string
}

export function useCreateSafetyStock() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: SafetyStockPayload) => (await api.post<{ data: SafetyStockRow }>('/api/safety-stocks', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['safety-stocks'] }),
  })
}

export function useUpdateSafetyStock(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: Partial<SafetyStockPayload>) =>
      (await api.put<{ data: SafetyStockRow }>(`/api/safety-stocks/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['safety-stocks'] }),
  })
}

export function useDeleteSafetyStock() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete(`/api/safety-stocks/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['safety-stocks'] }),
  })
}

export function useResolveSafetyStockConflict() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.post<{ data: SafetyStockRow }>(`/api/safety-stocks/${id}/resolve-conflict`)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['safety-stocks'] }),
  })
}
