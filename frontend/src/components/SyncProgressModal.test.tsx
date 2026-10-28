import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { toast } from '@/lib/notify'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { SyncProgressModal } from './SyncProgressModal'
import { useTriggerSync } from '@/features/sync/api'

const batch = (status: string, step: string | null) => ({
  id: 5, sync_code: 'SYNC-X', source: 'accurate', status, current_step: step, started_at: '2026-09-26T05:00:00Z',
  finished_at: null, duration_seconds: null, total_records: 10, inserted_records: 0, updated_records: 2,
  skipped_records: 8, deleted_records: 0, error_records: 0, error_message: null,
})

function Trigger() {
  const t = useTriggerSync()
  return <button onClick={() => t.mutate()}>Sync Accurate</button>
}

describe('SyncProgressModal (background sync)', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('follows a RUNNING batch step by step and announces the result when it finishes', async () => {
    let current = batch('RUNNING', 'npbg')
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/api/me') return Promise.resolve({ data: { user: { id: 1, roles: [], permissions: ['sync.accurate.view', 'sync.accurate.trigger'] } } }) as never
      if (url === '/api/sync/status') return Promise.resolve({ data: { data: current } }) as never
      return Promise.resolve({ data: { data: [] } }) as never
    })
    vi.spyOn(api, 'post').mockResolvedValue({ data: { data: batch('RUNNING', 'queued') } } as never)
    const success = vi.spyOn(toast, 'success')

    renderWithProviders(<><Trigger /><SyncProgressModal /></>)
    await userEvent.click(await screen.findByRole('button', { name: 'Sync Accurate' }))

    expect(await screen.findByText('Sinkronisasi Accurate sedang berjalan')).toBeInTheDocument()
    expect(screen.getByText(/aplikasi tetap bisa dipakai/)).toBeInTheDocument()
    expect(success).not.toHaveBeenCalled() // RUNNING is not "done"

    current = batch('SUCCESS', null)
    // polls every 5s while RUNNING (see useSyncStatus)
    await waitFor(() => expect(success).toHaveBeenCalledWith('Sinkronisasi selesai — 2 diperbarui.'), { timeout: 8000 })
    await waitFor(() => expect(screen.queryByText('Sinkronisasi Accurate sedang berjalan')).not.toBeInTheDocument())
  }, 20000)
})
