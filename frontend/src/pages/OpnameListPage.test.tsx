import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { OpnameListPage } from './OpnameListPage'

const meta = { page: 1, last_page: 1, total: 1 }
const line = {
  id: 1, item_id: 9, item_code: 'EAE.0257', description: 'KABEL TIES NYLON 3.6 x 200mm', unit: 'PCS',
  system_qty: 10, physical_qty: 14, difference: 4, diff_label: '+4', match_status: 'INVALID',
  note: null, count_status: 'COUNTED', review_status: 'PENDING',
  opname: { id: 77, number: '3554', status: 'PENDING_REVIEW', date: '2026-09-20', warehouse: 'SDA', accurate_itemadj_id: 2600 },
}
const doc = {
  id: 77, number: '3554', status: 'PENDING_REVIEW', type: 'PARTIAL', warehouse: { id: 1, code: 'SDA' }, scheduled_date: '2026-09-20',
  items_count: 3, counted_count: 3, diff_count: 1, accurate_itemadj_id: 2600,
  first_item: { code: 'EAE.0257', description: 'KABEL TIES NYLON 3.6 x 200mm' },
}

function mockApi(permissions: string[]) {
  return vi.spyOn(api, 'get').mockImplementation((url: string) => {
    if (url === '/api/me') return Promise.resolve({ data: { user: { id: 1, roles: [], permissions } } }) as never
    if (url === '/api/stock-opnames/lines') return Promise.resolve({ data: { data: [line], meta } }) as never
    return Promise.resolve({ data: { data: [doc], meta } }) as never
  })
}

describe('OpnameListPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('shows Accurate adjustments per item: item, before/after and a signed difference', async () => {
    mockApi(['opname.view', 'opname.review'])
    renderWithProviders(<OpnameListPage />)

    expect((await screen.findAllByText('KABEL TIES NYLON 3.6 x 200mm')).length).toBeGreaterThan(0)
    expect(screen.getByRole('columnheader', { name: 'Qty sebelum' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Qty sesudah' })).toBeInTheDocument()
    expect(screen.getAllByText('+4').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Penyesuaian stok').length).toBeGreaterThan(0)
  })

  it('per-document view names the items and how many more', async () => {
    const get = mockApi(['opname.view', 'opname.review'])
    renderWithProviders(<OpnameListPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Per dokumen' }))
    expect(await screen.findByText('+2 barang lainnya')).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith('/api/stock-opnames', expect.objectContaining({ params: expect.objectContaining({ source: 'accurate' }) }))
  })

  it('hides quantities that reveal the system stock from a counter', async () => {
    mockApi(['opname.view', 'opname.count'])
    renderWithProviders(<OpnameListPage />)

    expect((await screen.findAllByText('KABEL TIES NYLON 3.6 x 200mm')).length).toBeGreaterThan(0)
    expect(screen.queryByText('Qty sebelum')).not.toBeInTheDocument()
    expect(screen.queryByText('Selisih')).not.toBeInTheDocument()
  })
})
