import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { DashboardPage } from './DashboardPage'

const summary = {
  cards: [
    { key: 'aman', label: 'Status AMAN', value: 8912, tone: 'success' },
    { key: 'po_open', label: 'PO berjalan', value: 81, tone: 'default' },
    { key: 'ppb_accurate_open', label: 'PPB terbuka (Accurate)', value: 417, tone: 'warning' },
  ],
  charts: { po_status: [{ name: 'RECEIVED', value: 1222 }] },
  lists: {},
}

function mockApi(permissions: string[]) {
  vi.spyOn(api, 'get').mockImplementation((url: string) => {
    if (url === '/api/me') return Promise.resolve({ data: { user: { id: 1, name: 'Budi Santoso', roles: ['admin'], permissions } } }) as never
    if (url === '/api/dashboard') return Promise.resolve({ data: { data: summary } }) as never
    // the inventory visualisation now renders for every role; its data is not under test here
    if (url.startsWith('/api/dashboard/inventory')) return Promise.reject(new Error('not under test')) as never
    return Promise.resolve({ data: { data: [] } }) as never
  })
}

describe('DashboardPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('renders operational cards as links to their feature pages', async () => {
    mockApi([])
    renderWithProviders(<DashboardPage />)

    expect(await screen.findByText('PO berjalan')).toBeInTheDocument()
    // clearer wording than the API label: these are PPB lines still waiting for goods
    expect(screen.getByText('Baris PPB menunggu barang').closest('a')).toHaveAttribute('href', '/ppb')
    expect(screen.getByText('PO berjalan').closest('a')).toHaveAttribute('href', '/purchase-orders')
  })

  it('groups the dashboard into sections with jump links', async () => {
    mockApi([])
    renderWithProviders(<DashboardPage />)

    expect(await screen.findByText('PO berjalan')).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Bagian dashboard' })
    for (const label of ['Kondisi Stok', 'Pengadaan', 'Tracking']) {
      expect(nav).toHaveTextContent(label)
    }
    expect(screen.getByRole('heading', { name: 'Pengadaan' })).toBeInTheDocument()
  })

  it('hides the old inventory status cards for every role (the inventory section shows them correctly)', async () => {
    mockApi([])
    renderWithProviders(<DashboardPage />)

    expect(await screen.findByText('PO berjalan')).toBeInTheDocument()
    expect(screen.queryByText('Status AMAN')).not.toBeInTheDocument()
  })
})
