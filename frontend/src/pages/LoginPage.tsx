import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { BarChart3, Boxes, ShieldCheck } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { PasswordInput } from '@/components/ui/password-input'
import { Label } from '@/components/ui/label'
import { Logo } from '@/components/Logo'

const HIGHLIGHTS = [
  { icon: Boxes, text: 'Stok aktual, reserved & tersedia real-time' },
  { icon: BarChart3, text: 'Analisa safety stock & prioritas pembelian' },
  { icon: ShieldCheck, text: 'Alur request → pickup → PPB → penerimaan terkontrol' },
]

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const from = (location.state as { from?: { pathname: string } } | null)?.from?.pathname ?? '/'

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (user) return <Navigate to={from} replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(username.trim(), password)
      navigate(from, { replace: true })
    } catch (err) {
      setError(apiErrorMessage(err, 'Login gagal.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="relative hidden flex-col justify-between overflow-hidden bg-sidebar p-12 text-sidebar-foreground lg:flex">
        <div className="pointer-events-none absolute -right-24 -top-24 size-96 rounded-full bg-primary/20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-32 -left-16 size-96 rounded-full bg-accent/20 blur-3xl" />
        <Logo className="relative [&_span]:text-lg [&_span]:text-white [&_img]:h-9 [&_img]:w-9" />
        <div className="relative space-y-6">
          <h1 className="text-3xl font-semibold leading-tight text-white">
            Inventory Intelligence System
            <br />
            PT Surya Inti Gas
          </h1>
          <ul className="space-y-3">
            {HIGHLIGHTS.map((h) => {
              const Icon = h.icon
              return (
                <li key={h.text} className="flex items-center gap-3 text-sm">
                  <span className="flex size-9 items-center justify-center rounded-lg bg-white/10">
                    <Icon className="size-4" />
                  </span>
                  {h.text}
                </li>
              )
            })}
          </ul>
        </div>
        <p className="relative text-xs text-sidebar-foreground/60">STOCKWISE · {new Date().getFullYear()}</p>
      </div>

      <div className="flex items-center justify-center p-6">
        <div className="w-full max-w-sm space-y-6">
          <div className="text-center lg:hidden">
            <Logo className="justify-center [&_img]:h-10 [&_img]:w-10" />
          </div>
          <div>
            <h2 className="text-xl font-semibold">Masuk ke STOCKWISE</h2>
            <p className="text-sm text-muted-foreground">Gunakan akun yang diberikan admin gudang.</p>
          </div>

          <form onSubmit={onSubmit} className="space-y-4" noValidate>
            <div className="space-y-1.5">
              <Label htmlFor="username">Username / Email</Label>
              <Input
                id="username"
                autoComplete="username"
                autoFocus
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="password">Password</Label>
              <PasswordInput
                id="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </div>

            {error && (
              <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {error}
              </p>
            )}

            <Button type="submit" className="w-full" disabled={submitting}>
              {submitting ? 'Memproses…' : 'Masuk'}
            </Button>
          </form>
        </div>
      </div>
    </div>
  )
}
