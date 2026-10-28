import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

// ---------- Accurate Online (who is connected to Accurate) ----------

export interface AccurateConnection {
  kind: 'database' | 'license'
  local_address: string
  local_port: number
  remote_port: number
  since: string | null
}

export interface AccurateClient {
  ip: string
  hostname: string | null
  mac: string | null
  /** Filled in on the Accurate Online page, saved per IP in the STOCKWISE database */
  user_name: string | null
  division: string | null
  note: string | null
  /** using_database = Accurate is connected to its database; license_only = Accurate open (license) but no DB connection */
  status: 'using_database' | 'license_only'
  db_connections: number
  license_connections: number
  since: string | null
  connections: AccurateConnection[]
}

export interface AccurateSessions {
  generated_at: string
  db_port: number
  summary: { clients: number; clients_using_database: number; db_connections: number; license_connections: number }
  clients: AccurateClient[]
}

export function useAccurateSessions() {
  return useQuery({
    queryKey: ['accurate', 'sessions'],
    queryFn: async () => (await api.get<{ data: AccurateSessions }>('/api/accurate/sessions')).data.data,
    refetchInterval: 15_000,
  })
}

export function useRefreshAccurateSessions() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => (await api.get<{ data: AccurateSessions }>('/api/accurate/sessions', { params: { fresh: 1 } })).data.data,
    onSuccess: (data) => qc.setQueryData(['accurate', 'sessions'], data),
    meta: { silent: true },
  })
}

export function useTerminateAccurateSession() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { remote_address: string; remote_port?: number; reason?: string }) =>
      (await api.post<{ message: string; data: { terminated: number } }>('/api/accurate/sessions/terminate', body)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['accurate', 'sessions'] }),
    meta: { successMessage: 'Koneksi diputus.' },
  })
}

export interface AccurateIpLabelInput {
  ip: string
  user_name?: string
  division?: string
  note?: string
}

/** Save user name / division / note for an IP (all empty = cleared). */
export function useSaveAccurateIpLabel() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: AccurateIpLabelInput) => (await api.put<{ message: string }>('/api/accurate/ip-labels', body)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['accurate', 'sessions'] }),
    meta: { successMessage: 'Data PC disimpan.' },
  })
}

// ---------- Manual Sync Agent run (copy GDB -> staging [-> sync]) ----------

export type AgentRunMode = 'snapshot' | 'full'

export interface AgentRunStatus {
  running: boolean
  mode: string | null
  started_at: string | null
  finished_at: string | null
  result: 'ok' | 'failed' | 'busy' | 'interrupted' | null
}

export function useAgentRunStatus() {
  return useQuery({
    queryKey: ['agent-run', 'status'],
    queryFn: async () => (await api.get<{ data: AgentRunStatus }>('/api/agent-runs/status')).data.data,
    refetchInterval: (q) => (q.state.data?.running ? 5_000 : 30_000),
  })
}

export function useStartAgentRun() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (mode: AgentRunMode) => (await api.post<{ message: string }>('/api/agent-runs', { mode })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['agent-run'] })
      qc.invalidateQueries({ queryKey: ['sync'] })
    },
    meta: { successMessage: 'Sync Agent dimulai. Pantau progresnya di halaman ini.' },
  })
}
