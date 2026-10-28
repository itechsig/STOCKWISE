import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { ItemsPage } from './ItemsPage'

const page1 = {
  data: [
    {
      id: 1,
      code: 'BAN.001',
      description: 'BAN LUAR STEEL 750',
      item_type: 'CONSUMABLE',
      needs_blueprint: false,
      lead_time_days: 7,
      is_active: true,
      category: { id: 1, name: 'Automotive', path: 'Automotive' },
      unit: { id: 1, code: 'PCS', name: 'Pieces' },
      analysis: {
        actual: 3, reserved: 0, available: 3, stock_known: true,
        safety_stock: 5, selisih: -2, status: 'TIDAK_AMAN', deficit: 2,
        priority_score: 11, priority_level: 'HIGH', recommendation: 'Buat PPB', recommended_qty: 2,
      },
    },
  ],
  meta: { page: 1, per_page: 25, total: 1, last_page: 1 },
  links: { next: null, prev: null },
}

describe('ItemsPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('renders items and their STOCKWISE status, and searches', async () => {
    const get = vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/api/me') return Promise.resolve({ data: { user: { permissions: [] } } }) as never
      if (url === '/api/items/options') return Promise.resolve({ data: { data: { induk: ['Office Needs'], anak_1: [], anak_2: [], anak_3: [], uom: [] } } }) as never
      return Promise.resolve({ data: page1 }) as never
    })

    renderWithProviders(<ItemsPage />)

    expect(await screen.findByText('BAN LUAR STEEL 750')).toBeInTheDocument()
    // the table cell (the shared mock also feeds the UOM <select>, which then lists BAN.001 too)
    expect(screen.getByText('BAN.001', { selector: 'span' })).toBeInTheDocument()
    // status badge in the row (the <option> also says "Tidak Aman")
    expect(screen.getAllByText('Tidak Aman').length).toBeGreaterThanOrEqual(2)
    expect(screen.getByText('HIGH')).toBeInTheDocument()

    await userEvent.type(screen.getByPlaceholderText('Cari kode / deskripsi…'), 'BAN')

    await waitFor(() =>
      expect(get).toHaveBeenCalledWith('/api/items', expect.objectContaining({
        params: expect.objectContaining({ search: 'BAN' }),
      })),
    )
  })
})
