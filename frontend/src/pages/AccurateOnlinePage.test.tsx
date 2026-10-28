import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { AccurateOnlinePage } from './AccurateOnlinePage'

const sessions = {
  generated_at: '2026-09-27T09:00:00+07:00',
  db_port: 3051,
  summary: { clients: 2, clients_using_database: 1, db_connections: 2, license_connections: 2 },
  clients: [
    {
      ip: '192.168.2.15', hostname: 'GUDANG-PC1', mac: '00-11-22-33-44-55', status: 'using_database',
      db_connections: 2, license_connections: 1, since: '2026-09-27T08:00:00+07:00',
      connections: [
        { kind: 'license', local_address: '192.168.2.41', local_port: 6688, remote_port: 50003, since: '2026-09-27T08:00:00+07:00' },
        { kind: 'database', local_address: '192.168.2.41', local_port: 3051, remote_port: 50001, since: '2026-09-27T08:01:00+07:00' },
        { kind: 'database', local_address: '192.168.2.41', local_port: 3051, remote_port: 50002, since: '2026-09-27T08:05:00+07:00' },
      ],
    },
    {
      ip: '192.168.2.22', hostname: null, mac: 'AA-BB-CC-DD-EE-FF', status: 'license_only',
      db_connections: 0, license_connections: 1, since: '2026-09-27T08:30:00+07:00', connections: [],
    },
  ],
}

function mockApi(permissions: string[]) {
  vi.spyOn(api, 'get').mockImplementation((url: string) => {
    if (url === '/api/me') return Promise.resolve({ data: { user: { id: 1, roles: [], permissions } } }) as never
    if (url === '/api/accurate/sessions') return Promise.resolve({ data: { data: sessions } }) as never
    if (url === '/api/agent-runs/status') return Promise.resolve({ data: { data: { running: false, mode: 'snapshot', started_at: null, finished_at: '2026-09-26T21:00:05+07:00', result: 'ok' } } }) as never
    return Promise.resolve({ data: { data: { log_available: true, runs: [], events: [] } } }) as never
  })
  return vi.spyOn(api, 'post').mockResolvedValue({ data: { message: 'ok', data: { terminated: 2 } } } as never)
}

describe('AccurateOnlinePage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('shows each PC with IP, hostname, MAC and status', async () => {
    mockApi(['accurate.session.view'])
    renderWithProviders(<AccurateOnlinePage />)

    expect(await screen.findByText('GUDANG-PC1')).toBeInTheDocument()
    expect(screen.getByText('192.168.2.15')).toBeInTheDocument()
    expect(screen.getByText('00-11-22-33-44-55')).toBeInTheDocument()
    expect(screen.getByText('Memakai Accurate')).toBeInTheDocument()
    expect(screen.getByText('Accurate terbuka (lisensi)')).toBeInTheDocument()
    // view-only: no disconnect buttons
    expect(screen.queryByRole('button', { name: /Putuskan/ })).not.toBeInTheDocument()
  })

  it('disconnects all database connections of a PC after confirmation', async () => {
    const post = mockApi(['accurate.session.view', 'accurate.session.terminate'])
    renderWithProviders(<AccurateOnlinePage />)

    await screen.findByText('GUDANG-PC1')
    const buttons = screen.getAllByRole('button', { name: /Putuskan/ })
    expect(buttons).toHaveLength(1) // only the PC that actually uses the database
    await userEvent.click(buttons[0])

    const dialog = await screen.findByText('Putuskan koneksi Accurate?')
    expect(screen.getByText(/belum disimpan/)).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText(/Alasan/), 'Accurate hang')
    const modal = dialog.closest('div[role="dialog"]') ?? document.body
    await userEvent.click(within(modal as HTMLElement).getAllByRole('button', { name: /Putuskan/ }).at(-1)!)

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith('/api/accurate/sessions/terminate', { remote_address: '192.168.2.15', remote_port: undefined, reason: 'Accurate hang' }),
    )
  })
})
