export interface AuthUser {
  id: number
  name: string
  username: string
  email: string | null
  phone: string | null
  position: string | null
  is_active: boolean
  site: { id: number; code: string; name: string } | null
  roles: string[]
  permissions: string[]
  last_login_at: string | null
}

export interface LoginResponse {
  token: string
  user: AuthUser
}
