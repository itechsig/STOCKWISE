import axios, { AxiosError } from 'axios'

/**
 * Axios instance for the STOCKWISE API.
 *
 * Base URL comes from VITE_API_URL; in dev it is left empty so requests hit the
 * Vite proxy (`/api` -> Laravel, see vite.config.ts).
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '',
  headers: { Accept: 'application/json' },
  withCredentials: false,
})

const TOKEN_KEY = 'stockwise_token'

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* ignore storage errors */
  }
}

/** Fired when the API rejects the current token (401) so the app can log out. */
export const AUTH_EXPIRED_EVENT = 'stockwise:auth-expired'

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401 && getToken()) {
      setToken(null)
      window.dispatchEvent(new CustomEvent(AUTH_EXPIRED_EVENT))
    }
    return Promise.reject(error)
  },
)

/** Extract a human message from an Axios error. */
export function apiErrorMessage(error: unknown, fallback = 'Terjadi kesalahan.'): string {
  if (error instanceof AxiosError) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    // 422 validation errors: prefer the specific per-field reason (e.g.
    // "Username/email atau password salah.") over Laravel's generic
    // top-level "The given data was invalid." — that generic text is what
    // data.message holds whenever a validator has field errors, so reading
    // it first would silently hide the actually useful message.
    const fieldErrors = data?.errors ? Object.values(data.errors).flat() : []
    if (fieldErrors.length > 0) {
      return fieldErrors.length === 1 ? fieldErrors[0] : fieldErrors.map((m) => `• ${m}`).join('\n')
    }
    return data?.message ?? error.message ?? fallback
  }
  return fallback
}
