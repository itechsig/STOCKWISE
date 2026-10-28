import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

// ---------- Users ----------

export interface AdminUserRow {
  id: number
  name: string
  username: string
  email: string | null
  phone: string | null
  position: string | null
  is_active: boolean
  site: { id: number; code: string; name: string } | null
  roles: { slug: string; name: string }[]
  last_login_at: string | null
  created_at: string | null
}

export interface UserFilters {
  search?: string
  role?: string
  is_active?: '0' | '1'
  page?: number
}

export interface UserPayload {
  name?: string
  username?: string
  email?: string | null
  phone?: string | null
  position?: string | null
  site_id?: number | null
  is_active?: boolean
  roles?: string[]
  password?: string
  password_confirmation?: string
}

export interface UserOptions {
  roles: { id: number; slug: string; name: string; is_system: boolean }[]
  sites: { id: number; code: string; name: string }[]
}

export function useUsers(filters: UserFilters) {
  return useQuery({
    queryKey: ['admin', 'users', filters],
    queryFn: async () => (await api.get<Paginated<AdminUserRow>>('/api/users', { params: filters })).data,
    placeholderData: keepPreviousData,
  })
}

export function useUserOptions(enabled = true) {
  return useQuery({
    queryKey: ['admin', 'user-options'],
    queryFn: async () => (await api.get<{ data: UserOptions }>('/api/users/options')).data.data,
    enabled,
  })
}

export function useCreateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: UserPayload) => (await api.post<{ data: AdminUserRow }>('/api/users', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }),
    meta: { successMessage: 'Pengguna berhasil dibuat.' },
  })
}

export function useUpdateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UserPayload }) =>
      (await api.put<{ data: AdminUserRow }>(`/api/users/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }),
    meta: { successMessage: 'Pengguna berhasil diperbarui.' },
  })
}

export function useResetUserPassword() {
  return useMutation({
    mutationFn: async ({ id, password }: { id: number; password: string }) =>
      (await api.post<{ message: string }>(`/api/users/${id}/password`, { password, password_confirmation: password })).data,
    meta: { successMessage: 'Password berhasil direset. Semua sesi login pengguna ini sudah diakhiri.' },
  })
}

export function useDeleteUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete<{ message: string }>(`/api/users/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }),
    meta: { successMessage: 'Pengguna dihapus.' },
  })
}

// ---------- Roles ----------

export interface RoleRow {
  id: number
  slug: string
  name: string
  description: string | null
  is_system: boolean
  is_locked: boolean
  users_count: number
  permissions: string[]
}

export interface PermissionItem {
  id: number
  slug: string
  name: string
  group: string
}

export interface RolePayload {
  name?: string
  slug?: string
  description?: string | null
  permissions?: string[]
}

export function useRoles(enabled = true) {
  return useQuery({
    queryKey: ['admin', 'roles'],
    queryFn: async () => (await api.get<{ data: RoleRow[] }>('/api/roles')).data.data,
    enabled,
  })
}

export function usePermissions(enabled = true) {
  return useQuery({
    queryKey: ['admin', 'permissions'],
    queryFn: async () => (await api.get<{ data: Record<string, PermissionItem[]> }>('/api/permissions')).data.data,
    enabled,
  })
}

export function useCreateRole() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: RolePayload) => (await api.post<{ data: RoleRow }>('/api/roles', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }),
    meta: { successMessage: 'Role berhasil dibuat.' },
  })
}

export function useUpdateRole() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: RolePayload }) =>
      (await api.put<{ data: RoleRow }>(`/api/roles/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }),
    meta: { successMessage: 'Role berhasil diperbarui.' },
  })
}

export function useDeleteRole() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete<{ message: string }>(`/api/roles/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }),
    meta: { successMessage: 'Role dihapus.' },
  })
}

// ---------- Own password ----------

export function useChangeOwnPassword() {
  return useMutation({
    mutationFn: async (body: { current_password: string; password: string; password_confirmation: string }) =>
      (await api.post<{ message: string }>('/api/me/password', body)).data,
    meta: { successMessage: 'Password berhasil diganti.' },
  })
}
