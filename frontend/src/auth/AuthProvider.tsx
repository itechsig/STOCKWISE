import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { api, AUTH_EXPIRED_EVENT, getToken, setToken } from '@/lib/api'
import type { AuthUser, LoginResponse } from '@/types'
import { AuthContext, type AuthContextValue } from './AuthContext'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  // Only "loading" if there is a stored token we still need to validate.
  const [isLoading, setIsLoading] = useState(() => !!getToken())
  const bootstrapped = useRef(false)

  const clearSession = useCallback(() => {
    setToken(null)
    setUser(null)
  }, [])

  const loadMe = useCallback(async () => {
    const { data } = await api.get<{ user: AuthUser }>('/api/me')
    setUser(data.user)
  }, [])

  // Restore session from a stored token on first mount.
  useEffect(() => {
    if (bootstrapped.current || !getToken()) return
    bootstrapped.current = true

    loadMe()
      .catch(() => clearSession())
      .finally(() => setIsLoading(false))
  }, [loadMe, clearSession])

  // React to token rejection from anywhere (api.ts interceptor).
  useEffect(() => {
    const onExpired = () => setUser(null)
    window.addEventListener(AUTH_EXPIRED_EVENT, onExpired)
    return () => window.removeEventListener(AUTH_EXPIRED_EVENT, onExpired)
  }, [])

  const login = useCallback(
    async (username: string, password: string) => {
      const { data } = await api.post<LoginResponse>('/api/login', {
        username,
        password,
        device_name: 'stockwise-web',
      })
      setToken(data.token)
      setUser(data.user)
    },
    [],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/api/logout')
    } catch {
      /* ignore — clear locally regardless */
    }
    clearSession()
  }, [clearSession])

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isLoading,
      login,
      logout,
      hasPermission: (slug) => !!user && user.permissions.includes(slug),
      hasRole: (...slugs) => !!user && slugs.some((s) => user.roles.includes(s)),
    }),
    [user, isLoading, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
