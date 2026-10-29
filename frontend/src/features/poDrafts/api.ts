import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

/**
 * Draft PO: every item of a STOCKWISE PPB the Direktur approved waits here. Purchasing notes each
 * vendor's price (the comparison), picks items and one vendor → one PO (one PO = one vendor).
 */
export interface PoQuote {
  id: number
  vendor_id: number
  vendor_name: string | null
  unit_price: number
  note: string | null
  by: string | null
  updated_at: string | null
}

export interface PoDraftLine {
  id: number
  item_id: number | null
  item_code: string | null
  description: string
  qty: number
  unit: string | null
  ppb: { id: number; no_ppb: string; divisi: string | null; peminta: string | null; tgl_ppb: string | null; tgl_diperlukan: string | null } | null
  /** cheapest first */
  quotes: PoQuote[]
}

export function usePoDraftLines(params: { search?: string }) {
  return useQuery({
    queryKey: ['po-draft-lines', params],
    queryFn: async () => (await api.get<{ data: PoDraftLine[]; meta: { total: number } }>('/api/po-draft-lines', { params })).data,
  })
}

export function usePoDraftActions() {
  const qc = useQueryClient()
  const done = () => qc.invalidateQueries({ queryKey: ['po-draft-lines'] })
  return {
    quote: useMutation({
      mutationFn: async ({ id, ...body }: { id: number; vendor_id: number; unit_price: number; note?: string | null }) =>
        (await api.put<{ data: PoDraftLine }>(`/api/po-draft-lines/${id}/quotes`, body)).data.data,
      meta: { successMessage: 'Harga vendor disimpan.' },
      onSuccess: done,
    }),
    removeQuote: useMutation({
      mutationFn: async ({ id, quoteId }: { id: number; quoteId: number }) =>
        (await api.delete<{ data: PoDraftLine }>(`/api/po-draft-lines/${id}/quotes/${quoteId}`)).data.data,
      onSuccess: done,
    }),
    submit: useMutation({
      mutationFn: async (body: { line_ids: number[]; vendor_id: number; expected_date?: string | null; notes?: string | null; lines: { id: number; qty: number; unit_price: number }[] }) =>
        (await api.post<{ data: { id: number; number: string; total: number; lines: number } }>('/api/po-draft-lines/submit', body)).data.data,
      meta: { successMessage: 'PO dibuat dan diajukan ke Direktur.' },
      onSuccess: () => { done(); qc.invalidateQueries({ queryKey: ['purchase-orders'] }) },
    }),
  }
}
