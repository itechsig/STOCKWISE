import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

/** Master Data · Data Pekerja: one person of the company (roster). */
export interface Employee {
  id: number
  name: string
  position: string | null
  phone: string | null
  department_id: number | null
  department: string | null
  /** the STOCKWISE account this person logs in with, if any */
  user_id: number | null
  user: { id: number; name: string; username: string } | null
  is_active: boolean
  needs_review: boolean
  source: string | null
}

export function useEmployees(params: { search?: string; department_id?: string; is_active?: string } = {}) {
  return useQuery({
    queryKey: ['employees', params],
    queryFn: async () => (await api.get<{ data: Employee[] }>('/api/employees', { params })).data.data,
  })
}

export function useEmployeeDepartments() {
  return useQuery({
    queryKey: ['employees', 'departments'],
    staleTime: 10 * 60_000,
    queryFn: async () => (await api.get<{ data: { id: number; name: string }[] }>('/api/employees/departments')).data.data,
  })
}

/** Accounts to link a worker to (only for who may manage the roster). */
export function useEmployeeUsers(enabled: boolean) {
  return useQuery({
    queryKey: ['employees', 'users'],
    enabled,
    staleTime: 10 * 60_000,
    queryFn: async () => (await api.get<{ data: { id: number; name: string; username: string }[] }>('/api/employees/users')).data.data,
  })
}

export type EmployeePayload = Partial<Pick<Employee, 'name' | 'position' | 'phone' | 'department_id' | 'user_id' | 'is_active'>> & { id?: number }

export function useSaveEmployee() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...body }: EmployeePayload) =>
      id ? (await api.put<{ data: Employee }>(`/api/employees/${id}`, body)).data.data : (await api.post<{ data: Employee }>('/api/employees', body)).data.data,
    meta: { successMessage: false },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['employees'] }),
  })
}
