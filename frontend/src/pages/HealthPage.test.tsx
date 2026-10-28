import type { ReactElement } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import { HealthPage } from './HealthPage'

function renderWithClient(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>)
}

describe('HealthPage', () => {
  beforeEach(() => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        app: 'STOCKWISE',
        status: 'ok',
        time: '2026-09-08T00:00:00Z',
        version: 'test',
        database: 'connected',
      },
    })
  })

  afterEach(() => vi.restoreAllMocks())

  it('renders backend health data', async () => {
    renderWithClient(<HealthPage />)
    expect(await screen.findByText('connected')).toBeInTheDocument()
    expect(screen.getByText('STOCKWISE')).toBeInTheDocument()
  })
})
