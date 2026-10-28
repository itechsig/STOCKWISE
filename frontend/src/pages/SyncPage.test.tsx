import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { SyncPage } from './SyncPage'

const now = Date.now()
const iso = (minutesAgo: number) => new Date(now - minutesAgo * 60000).toISOString()

const log = {
  data: {
    log_available: true,
    runs: [
      { time: iso(30), source: 'LIVE-20260926_1030', status: 'FAILED', error: 'gbak restore failed (exit 1)' },
      { time: iso(600), source: 'GUDANGSIG2025 Friday.gbk', status: 'PARTIAL', error: null },
    ],
    events: [
      { time: iso(30), level: 'ERROR', message: 'Sync failed for X' },
      { time: iso(31), level: 'INFO', message: 'Candidate backup: Y' },
    ],
  },
}

function mockApi() {
  vi.spyOn(api, 'get').mockImplementation((url: string) => {
    if (url === '/api/me') return Promise.resolve({ data: { user: { permissions: ['sync.accurate.view'], roles: [] } } }) as never
    if (url === '/api/sync/status') return Promise.resolve({ data: { data: null } }) as never
    if (url === '/api/sync/history') return Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } } }) as never
    if (url === '/api/sync/agent-log') return Promise.resolve({ data: log }) as never
    return Promise.resolve({ data: { data: [] } }) as never
  })
}

describe('SyncPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('defaults to Riwayat Sync and switches to Log Agen to show runs/events', async () => {
    mockApi()
    renderWithProviders(<SyncPage />)

    // default tab: Riwayat Sync (SyncStatusCard's own status line proves it rendered)
    expect(await screen.findByText('Sync terakhir')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Log Agen' }))
    expect(await screen.findByText('gbak restore failed (exit 1)')).toBeInTheDocument()
    expect(screen.getByText('Gagal 7 hari terakhir')).toBeInTheDocument()
    expect(screen.getByText('1 run')).toBeInTheDocument()
    expect(screen.getByText(/Gagal · 30 menit lalu/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /Event Detail/ }))
    expect(screen.getByText('Candidate backup: Y')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByDisplayValue('Semua level'), 'ERROR')
    expect(screen.queryByText('Candidate backup: Y')).not.toBeInTheDocument()
    expect(screen.getByText('Sync failed for X')).toBeInTheDocument()
  })
})

describe('SyncPage single "Sinkron Sekarang" button', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  function mockRunApi(permissions: string[]) {
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/api/me') return Promise.resolve({ data: { user: { permissions, roles: [] } } }) as never
      if (url === '/api/sync/status') return Promise.resolve({ data: { data: null } }) as never
      if (url === '/api/sync/history') return Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } } }) as never
      if (url === '/api/agent-runs/status') return Promise.resolve({ data: { data: { running: false, mode: 'full', started_at: null, finished_at: '2026-09-26T21:00:05+07:00', result: 'ok' } } }) as never
      return Promise.resolve({ data: { data: { log_available: true, runs: [], events: [] } } }) as never
    })
    return vi.spyOn(api, 'post').mockResolvedValue({ data: { message: 'ok' } } as never)
  }

  it('copies the GDB and syncs with one button, visible without switching tabs', async () => {
    const post = mockRunApi(['sync.accurate.view', 'sync.accurate.trigger'])
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderWithProviders(<SyncPage />)

    await userEvent.click(await screen.findByRole('button', { name: /Sinkron Sekarang/ }))
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/agent-runs', { mode: 'full' }))
    expect(await screen.findByText(/Job terakhir/)).toBeInTheDocument()
  })

  it('hides the button without trigger permission', async () => {
    mockRunApi(['sync.accurate.view'])
    renderWithProviders(<SyncPage />)

    await screen.findByText(/Job terakhir/)
    expect(screen.queryByRole('button', { name: /Sinkron Sekarang/ })).not.toBeInTheDocument()
  })
})
