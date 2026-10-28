import { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api, setToken } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { LoginPage } from './LoginPage'

const fakeUser = {
  id: 1,
  name: 'Admin Gudang',
  username: 'admingudang',
  email: 'admingudang@stockwise.local',
  is_active: true,
  site: { id: 1, code: 'SIG-SDA', name: 'SIG Sidoarjo' },
  roles: ['admin_gudang'],
  permissions: ['request.review', 'opname.approve'],
  last_login_at: null,
}

describe('LoginPage', () => {
  beforeEach(() => {
    setToken(null)
    vi.restoreAllMocks()
  })
  afterEach(() => setToken(null))

  it('shows and hides the password with the eye button', async () => {
    renderWithProviders(<LoginPage />, { route: '/login' })
    const field = screen.getByLabelText('Password')
    await userEvent.type(field, 'rahasia')
    expect(field).toHaveAttribute('type', 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Tampilkan password' }))
    expect(field).toHaveAttribute('type', 'text')
    expect(field).toHaveValue('rahasia')
    await userEvent.click(screen.getByRole('button', { name: 'Sembunyikan password' }))
    expect(field).toHaveAttribute('type', 'password')
  })

  it('logs in and stores the token', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({
      data: { token: 'tok-123', user: fakeUser },
    })

    renderWithProviders(<LoginPage />)

    await userEvent.type(screen.getByLabelText(/username/i), 'admingudang')
    await userEvent.type(screen.getByLabelText('Password'), 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/login', {
      username: 'admingudang',
      password: 'password',
      device_name: 'stockwise-web',
    }))
    expect(localStorage.getItem('stockwise_token')).toBe('tok-123')
  })

  it('shows the API error message on bad credentials', async () => {
    const err = new AxiosError('Request failed')
    // @ts-expect-error minimal shape for the test
    err.response = { status: 422, data: { message: 'Username atau password salah.' } }
    vi.spyOn(api, 'post').mockRejectedValue(err)

    renderWithProviders(<LoginPage />)

    await userEvent.type(screen.getByLabelText(/username/i), 'x')
    await userEvent.type(screen.getByLabelText('Password'), 'y')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Username atau password salah.')
    expect(localStorage.getItem('stockwise_token')).toBeNull()
  })
})
