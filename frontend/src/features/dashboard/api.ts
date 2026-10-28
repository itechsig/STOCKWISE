import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface DashboardCard {
  key: string
  label: string
  value: number
  tone: 'default' | 'success' | 'warning' | 'danger'
}

export interface DashboardData {
  role: string[]
  generated_at: string
  cards: DashboardCard[]
  charts: {
    stock_movement_14d?: { name: string; in: number; out: number }[]
    /** PO status breakdown — Accurate-synced rows only (accurate_po_id set). */
    po_status?: { name: string; value: number }[]
  }
  lists: Record<string, never>
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<{ data: DashboardData }>('/api/dashboard')).data.data,
    staleTime: 30_000,
  })
}

// ---------------------------------------------------------------------------
// Inventory Dashboard — GET /api/dashboard/inventory (KPI/health/charts/notes)
// ---------------------------------------------------------------------------

export interface InventoryDashboardFilters {
  search?: string
  accurate_category_induk?: string
  accurate_category_anak_1?: string
  accurate_category_anak_2?: string
  accurate_category_anak_3?: string
  unit_id?: number
  warehouse_id?: number
  status?: string
  needs_blueprint?: boolean
  has_npbg?: boolean
  lead_time_min?: number
  lead_time_max?: number
  selisih_min?: number
  selisih_max?: number
  high_lead_time_threshold?: number
}

export interface InventoryDashboardKpis {
  total_barang: number
  barang_aman: number
  perlu_dibeli: number
  stok_habis: number
  barang_bep: number
  total_stok: number
  total_batas_aman: number
  total_kekurangan: number
}

export interface InventoryDashboardData {
  generated_at: string
  run: { lead_time_threshold: number; median_deficit: number; item_count: number; computed_at: string | null } | null
  kpis: InventoryDashboardKpis
  health_score: { value: number; category: 'Sehat' | 'Perlu Perhatian' | 'Kritis' }
  charts: {
    status_distribution: { name: string; value: number }[]
    top_deficit: { code: string; description: string; deficit: number }[]
    stock_vs_safety: { code: string; description: string; sisa_stok: number; safety_stock: number }[]
    per_warehouse: { name: string; AMAN: number; TIDAK_AMAN: number; BEP: number }[]
    per_category: { name: string; AMAN: number; TIDAK_AMAN: number; BEP: number }[]
    stock_vs_safety_per_warehouse: { name: string; total_stok: number; total_batas_aman: number }[]
    ppb_status: { name: string; value: number }[]
    ppb_per_divisi: { name: string; value: number }[]
    npbg_per_month: { name: string; qty: number; count: number }[]
    npbg_top_usage: { name: string; count: number; qty: number }[]
    npbg_top_divisi: { name: string; count: number; qty: number }[]
  }
  notes: string[]
  priority_items: {
    code: string
    description: string
    deficit: number
    lead_time_days: number
    priority_score: number
    priority_level: string
  }[]
}

/** The dashboard's own filter choices — available to every role that sees the dashboard. */
export function useInventoryDashboardFilters() {
  return useQuery({
    queryKey: ['dashboard', 'inventory', 'filters'],
    queryFn: async () =>
      (await api.get<{ data: { categories: string[]; units: { id: number; code: string }[]; warehouses: { id: number; code: string; name: string }[] } }>(
        '/api/dashboard/inventory/filters',
      )).data.data,
    staleTime: 5 * 60_000,
  })
}

export function useInventoryDashboard(filters: InventoryDashboardFilters) {
  return useQuery({
    queryKey: ['dashboard', 'inventory', filters],
    queryFn: async () =>
      (await api.get<{ data: InventoryDashboardData }>('/api/dashboard/inventory', { params: filters })).data.data,
    staleTime: 30_000,
  })
}

// ---------------------------------------------------------------------------
// Tracking & Pembelian — GET /api/dashboard/operations
// ---------------------------------------------------------------------------

export interface TrackingSummaryRow {
  key: string
  label: string
  to: string
  done: number
  running: number
  attention: number
  total: number
  /** the module's own Excel status words for done / running / attention */
  words: [string, string, string]
}

export interface OperationsDashboardData {
  generated_at: string
  tracking: TrackingSummaryRow[]
  ppb_aging: { name: string; requested: number; shortage: number }[]
  purchase_flow: { month: string; ppb: number; po: number; ri: number }[]
  maintenance_top_vehicles: { name: string; completed: number; open: number; total: number }[]
  tyre_per_month: { month: string; count: number }[]
  /** only the lists the user may open */
  attention: {
    ppb_oldest?: { id: number; no_ppb: string; date: string; item: string; line_status: string; qty: number; received: number; unit: string | null; age_days: number }[]
    lend_overdue?: { id: number; number: string; item: string; borrower: string | null; due_date: string | null; qty: number; unit: string | null; late_days: number | null }[]
  }
}

export function useOperationsDashboard() {
  return useQuery({
    queryKey: ['dashboard', 'operations'],
    queryFn: async () => (await api.get<{ data: OperationsDashboardData }>('/api/dashboard/operations')).data.data,
    staleTime: 60_000,
  })
}

// ---------------------------------------------------------------- Kartu Stock: barang masuk / keluar

export type StockCardPeriod = 'daily' | 'weekly' | 'monthly'
export interface StockCardReport {
  period: StockCardPeriod
  from: string
  to: string
  /** 'qty' when one item is chosen (one unit), otherwise counts of transaction lines */
  measure: 'qty' | 'lines'
  unit: string | null
  series: { key: string; start: string; end: string; qty_in: number; qty_out: number; lines_in: number; lines_out: number }[]
  totals: { lines_in: number; lines_out: number; qty_in: number | null; qty_out: number | null; items_moved: number; negative_items: number }
  items: { item_id: number; code: string; description: string; unit: string | null; qty_in: number; qty_out: number; lines: number; sisa: number }[]
  filters: {
    projects: { id: number; name: string; code: string | null; status: string }[]
    items: { id: number; code: string; description: string; unit: string | null }[]
  }
}

export function useStockCardReport(period: StockCardPeriod, projectId: number | null, itemId: number | null, enabled = true) {
  return useQuery({
    queryKey: ['dashboard', 'stock-cards', period, projectId, itemId],
    queryFn: async () => (await api.get<{ data: StockCardReport }>('/api/dashboard/stock-cards', {
      params: { period, project_id: projectId ?? undefined, item_id: itemId ?? undefined },
    })).data.data,
    enabled,
    staleTime: 60_000,
    placeholderData: (prev) => prev,
  })
}
