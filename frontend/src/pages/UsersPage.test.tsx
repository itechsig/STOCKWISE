import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { UsersPage, generatePassword } from './UsersPage'
import { RolesPage } from './RolesPage'

const me = (roles: string[], permissions: string[]) => ({
  data: { user: { id: 99, name: 'Saya', username: 'saya', roles, permissions, site: null } },
})

const users = {
  data: [
    { id: 1, name: 'Super Admin', username: 'superadmin', email: null, phone: null, position: null, is_active: true, site: null, roles: [{ slug: 'super_admin', name: 'Super Admin' }], last_login_at: null, created_at: null },
    { id: 2, name: 'Budi', username: 'budi', email: 'b@x.test', phone: null, position: null, is_active: false, site: { id: 1, code: 'SDA', name: 'Sidoarjo' }, roles: [{ slug: 'karyawan', name: 'Karyawan' }], last_login_at: null, created_at: null },
  ],
  meta: { page: 1, per_page: 25, total: 2, last_page: 1 },
}

const options = { data: { roles: [{ id: 1, slug: 'super_admin', name: 'Super Admin', is_system: true }], sites: [] } }

function mockGet(meResponse: unknown) {
  return vi.spyOn(api, 'get').mockImplementation((url: string) => {
    if (url === '/api/me') return Promise.resolve(meResponse) as never
    if (url === '/api/users') return Promise.resolve({ data: users }) as never
    if (url === '/api/users/options') return Promise.resolve({ data: options }) as never
    if (url === '/api/roles') {
      return Promise.resolve({
        data: {
          data: [
            { id: 1, slug: 'super_admin', name: 'Super Admin', description: null, is_system: true, is_locked: true, users_count: 1, permissions: [] },
            { id: 2, slug: 'staf_x', name: 'Staf X', description: 'custom', is_system: false, is_locked: false, users_count: 0, permissions: ['item.view'] },
          ],
        },
      }) as never
    }
    return Promise.resolve({ data: { data: [] } }) as never
  })
}

describe('UsersPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('lists users and hides actions on super admin accounts for a non-super-admin', async () => {
    mockGet(me(['user_admin'], ['user.view', 'user.create', 'user.update', 'user.delete']))
    renderWithProviders(<UsersPage />)

    expect(await screen.findByText('budi')).toBeInTheDocument()
    expect(screen.getByText('Nonaktif', { selector: 'span' })).toBeInTheDocument() // badge, not the filter <option>
    expect(screen.getByText('khusus Super Admin')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Tambah Pengguna/ })).toBeInTheDocument()
  })

  it('super admin gets actions on every row', async () => {
    mockGet(me(['super_admin'], ['user.view', 'user.create', 'user.update', 'user.delete']))
    renderWithProviders(<UsersPage />)

    expect(await screen.findByText('budi')).toBeInTheDocument()
    expect(screen.queryByText('khusus Super Admin')).not.toBeInTheDocument()
    expect(screen.getAllByTitle('Reset password')).toHaveLength(2)
  })

  it('generated passwords always satisfy the backend rule (>=8, letters and digits)', () => {
    for (let i = 0; i < 200; i++) {
      const p = generatePassword()
      expect(p).toMatch(/^(?=.*[A-Za-z])(?=.*\d).{8,}$/)
    }
  })
})

describe('RolesPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('only custom roles can be deleted; super admin shows as all permissions', async () => {
    mockGet(me(['super_admin'], ['role.view', 'role.manage']))
    renderWithProviders(<RolesPage />)

    expect(await screen.findByText('Staf X')).toBeInTheDocument()
    expect(screen.getByText('Semua')).toBeInTheDocument()
    expect(screen.getAllByTitle('Hapus')).toHaveLength(1)
  })
})
