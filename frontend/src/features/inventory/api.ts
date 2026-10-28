import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type {
  AnalysisRow,
  AnalysisRun,
  CategoryNode,
  Item,
  NewItemPayload,
  ItemOptions,
  Paginated,
} from '@/types/inventory'

export interface ItemFilters {
  search?: string
  /** BARANG / JASA */
  product_kind?: string
  category_induk?: string
  accurate_category_induk?: string
  accurate_category_anak_1?: string
  accurate_category_anak_2?: string
  accurate_category_anak_3?: string
  unit_id?: number
  status?: string
  priority_level?: string
  page?: number
  per_page?: number
  sort?: string
}

export function useItems(filters: ItemFilters) {
  return useQuery({
    queryKey: ['items', filters],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Item>>('/api/items', { params: filters })
      return data
    },
    placeholderData: keepPreviousData,
  })
}

export interface AccurateCategoryBranch {
  accurate_category_anak_1: string
  accurate_category_anak_2: string | null
  accurate_category_anak_3: string | null
  /** Independen dari anak_1/2/3 — diturunkan dari 3 huruf depan kode barang, bukan rantai PARENTITEM Accurate. */
  accurate_category_induk: string | null
}

/** Kombinasi Kategori Anak 1/2/3 yang benar-benar ada di data (hasil sync Accurate) — dipakai untuk membangun filter bertingkat di Master Barang. Kategori Induk ikut disertakan per baris tapi diperlakukan sebagai filter independen (bukan bagian tingkatan anak_1/2/3), lihat accurate_category_induk. */
/** The fixed choices of the item form: Kategori Induk / Anak 1 / 2 / 3 and UOM. */
export function useItemOptions() {
  return useQuery({
    queryKey: ['items', 'options'],
    staleTime: 30 * 60_000,
    queryFn: async () => (await api.get<{ data: ItemOptions }>('/api/items/options')).data.data,
  })
}

export function useAccurateCategoryOptions() {
  return useQuery({
    queryKey: ['items', 'accurate-categories'],
    queryFn: async () => (await api.get<{ data: AccurateCategoryBranch[] }>('/api/items/accurate-categories')).data.data,
  })
}

export function useItem(id: number | null) {
  return useQuery({
    queryKey: ['item', id],
    enabled: id != null,
    queryFn: async () => {
      const { data } = await api.get<{ data: Item }>(`/api/items/${id}`)
      return data.data
    },
  })
}

/** One baris histori barang — MASUK (RI, vendor), KELUAR (NPBG, pelanggan/nopol), atau ADJUST (penyesuaian opname). */
export interface ItemHistoryRow {
  type: 'MASUK' | 'KELUAR' | 'ADJUST'
  date: string | null
  doc_no: string | null
  counterparty_label: string | null
  counterparty: string | null
  qty: number | null
  satuan: string | null
  /** Hanya terisi untuk barang yang memang dicatat per-serial di RI/NPBG (ban, aset, dll.) — kosong untuk barang biasa. */
  no_seri: string | null
  note: string | null
}

export interface ItemHistorySummary {
  masuk: number
  keluar: number
  adjust: number
}

export function useItemHistory(itemId: number | null, params: { page?: number; per_page?: number; type?: ItemHistoryRow['type'] } = {}) {
  return useQuery({
    queryKey: ['item', itemId, 'history', params],
    enabled: itemId != null,
    queryFn: async () => {
      const { data } = await api.get<Paginated<ItemHistoryRow> & { summary: ItemHistorySummary }>(`/api/items/${itemId}/history`, { params })
      return data
    },
    placeholderData: keepPreviousData,
  })
}

export interface ItemLookupResult {
  id: number
  code: string
  description: string
  unit: string | null
  unit_id: number | null
  default_warehouse_id: number | null
  available: number | null
  stock_known: boolean
}

export function useItemLookup(search: string) {
  return useQuery({
    queryKey: ['items', 'lookup', search],
    enabled: search.trim().length >= 2,
    queryFn: async () => {
      const { data } = await api.get<{ data: ItemLookupResult[] }>('/api/items/lookup', {
        params: { search },
      })
      return data.data
    },
    placeholderData: keepPreviousData,
  })
}

export function useWarehouses() {
  return useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ id: number; code: string; name: string }> }>('/api/warehouses')
      return data.data
    },
    staleTime: 5 * 60_000,
  })
}

export function useUnits() {
  return useQuery({
    queryKey: ['units'],
    queryFn: async () => (await api.get<{ data: Array<{ id: number; code: string; name: string }> }>('/api/units')).data.data,
    staleTime: 10 * 60_000,
  })
}

export function useCategoryTree() {
  return useQuery({
    queryKey: ['categories', 'tree'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CategoryNode[] }>('/api/categories/tree')
      return data.data
    },
    staleTime: 5 * 60_000,
  })
}

export function useWarehouseLocations(warehouseId: number | null) {
  return useQuery({
    queryKey: ['warehouse-locations', warehouseId],
    enabled: warehouseId != null,
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ id: number; code: string; description: string | null }> }>(
        '/api/warehouse-locations',
        { params: { warehouse_id: warehouseId } },
      )
      return data.data
    },
    staleTime: 60_000,
  })
}

export function useCreateItem() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: NewItemPayload) => (await api.post<{ data: Item }>('/api/items', payload)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

export function useUpdateItem(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: Partial<NewItemPayload>) => (await api.put<{ data: Item }>(`/api/items/${id}`, payload)).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['items'] })
      qc.invalidateQueries({ queryKey: ['item', id] })
    },
  })
}

export function useDeleteItem() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete(`/api/items/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

interface AnalysisResponse extends Paginated<AnalysisRow> {
  run: AnalysisRun | null
}

export function useInventoryAnalysis(filters: {
  search?: string
  status?: string
  priority_level?: string
  category_induk?: string
  page?: number
  per_page?: number
  sort?: string
}) {
  return useQuery({
    queryKey: ['inventory-analysis', filters],
    queryFn: async () => {
      const { data } = await api.get<AnalysisResponse>('/api/inventory/analysis', { params: filters })
      return data
    },
    placeholderData: keepPreviousData,
  })
}
