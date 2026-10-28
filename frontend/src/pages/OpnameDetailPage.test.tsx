import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { OpnameDetailPage } from './OpnameDetailPage'

function opname(overrides: Record<string, unknown> = {}) {
  return {
    id: 5, number: 'SO/SDA/26/IX/001', status: 'IN_PROGRESS', type: 'PARTIAL',
    warehouse: { id: 1, code: 'SDA' }, scheduled_date: '2026-09-28', scheduled_by: 'Admin', counter: 'Budi', reviewer: null,
    created_at: '2026-09-28T08:00:00+07:00', started_at: '2026-09-28T08:10:00+07:00', submitted_at: null, reviewed_at: null,
    review_note: null, accurate_itemadj_id: null, accurate_synced_at: null,
    items: [
      { id: 11, item_id: 1, item_code: 'BRG.001', description: 'Baut M10', physical_qty: 8, note: null, count_status: 'COUNTED', review_status: 'PENDING' },
      { id: 12, item_id: 2, item_code: 'BRG.002', description: 'Mur M10', physical_qty: null, note: null, count_status: 'PENDING', review_status: 'PENDING' },
    ],
    ...overrides,
  }
}

function mockApi(permissions: string[], data: ReturnType<typeof opname>) {
  vi.spyOn(api, 'get').mockImplementation((url: string) => {
    if (url === '/api/me') return Promise.resolve({ data: { user: { id: 1, roles: [], permissions } } }) as never
    return Promise.resolve({ data: { data } }) as never
  })
}

const render = () =>
  renderWithProviders(
    <Routes><Route path="/stock-opnames/:id" element={<OpnameDetailPage />} /></Routes>,
    { route: '/stock-opnames/5' },
  )

describe('OpnameDetailPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('guides the counter: progress, what is left, and filters to the uncounted rows (blind count)', async () => {
    mockApi(['opname.view', 'opname.count', 'opname.submit'], opname())
    render()

    expect(await screen.findByText(/Masih/)).toHaveTextContent('Masih 1 barang belum diisi')
    expect(screen.getByText('1/2 barang · 50%')).toBeInTheDocument()
    // counter never sees the system qty column
    expect(screen.queryByText('Stok sistem')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Tampilkan yang belum diisi' }))
    expect(screen.getAllByText('Mur M10').length).toBeGreaterThan(0)
    expect(screen.queryByText('Baut M10')).not.toBeInTheDocument()
  })

  it('blocks sending while rows are still empty', async () => {
    mockApi(['opname.view', 'opname.count', 'opname.submit'], opname())
    const post = vi.spyOn(api, 'post')
    render()

    await userEvent.click(await screen.findByRole('button', { name: 'Kirim untuk Review' }))
    expect(screen.getByText(/Isi semua dulu sebelum mengirim/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ya, kirim' })).toBeDisabled()
    expect(post).not.toHaveBeenCalled()
  })

  it('explains an Accurate mirror instead of showing an unusable review status', async () => {
    mockApi(['opname.view', 'opname.review', 'opname.approve'], opname({ status: 'PENDING_REVIEW', accurate_itemadj_id: 99 }))
    render()

    expect(await screen.findByText('Penyesuaian stok')).toBeInTheDocument()
    expect(screen.getByText(/berasal dari/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Selesaikan Review' })).not.toBeInTheDocument()
  })
})
