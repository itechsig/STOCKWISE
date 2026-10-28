import { useState } from 'react'
import { KeyRound, Pencil, Power, Trash2, UserPlus, Users } from 'lucide-react'
import {
  useCreateUser,
  useDeleteUser,
  useResetUserPassword,
  useUpdateUser,
  useUserOptions,
  useUsers,
  type AdminUserRow,
  type UserPayload,
} from '@/features/admin/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Modal } from '@/components/ui/modal'
import { useInfiniteRows } from '@/components/InfiniteRows'
import { confirmDialog } from '@/components/ui/confirm'
import { FilterBar } from '@/components/FilterBar'

const SUPER_ADMIN = 'super_admin'
const selectClass = 'h-9 w-full rounded-md border border-input bg-card px-3 text-sm'

/** Password acak yang lolos aturan backend (≥8, huruf + angka) — untuk tombol "Buat otomatis". */
export function generatePassword(length = 12): string {
  const letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'
  const digits = '23456789'
  const all = letters + digits
  const pick = (set: string) => set[crypto.getRandomValues(new Uint32Array(1))[0] % set.length]
  const chars = [pick(letters), pick(letters), pick(digits), pick(digits)]
  while (chars.length < length) chars.push(pick(all))
  for (let i = chars.length - 1; i > 0; i--) {
    const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }
  return chars.join('')
}

interface FormState {
  name: string
  username: string
  email: string
  phone: string
  position: string
  siteId: string
  isActive: boolean
  roles: string[]
  password: string
  passwordConfirm: string
}

function toForm(u?: AdminUserRow): FormState {
  return {
    name: u?.name ?? '',
    username: u?.username ?? '',
    email: u?.email ?? '',
    phone: u?.phone ?? '',
    position: u?.position ?? '',
    siteId: u?.site?.id.toString() ?? '',
    isActive: u?.is_active ?? true,
    roles: u?.roles.map((r) => r.slug) ?? [],
    password: '',
    passwordConfirm: '',
  }
}

function UserFormModal({ user, onClose }: { user?: AdminUserRow; onClose: () => void }) {
  const isEdit = !!user
  const { user: me, hasPermission } = useAuth()
  const iAmSuper = !!me?.roles.includes(SUPER_ADMIN)
  const canAssignRole = !isEdit || hasPermission('user.assign_role')
  const { data: options } = useUserOptions()
  const create = useCreateUser()
  const update = useUpdateUser()
  const [form, setForm] = useState<FormState>(() => toForm(user))
  const [err, setErr] = useState<string | null>(null)
  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setForm((f) => ({ ...f, [k]: v }))

  const toggleRole = (slug: string) =>
    set('roles', form.roles.includes(slug) ? form.roles.filter((r) => r !== slug) : [...form.roles, slug])

  function submit() {
    setErr(null)
    if (!isEdit && form.password !== form.passwordConfirm) {
      setErr('Konfirmasi password tidak sama.')
      return
    }
    const body: UserPayload = {
      name: form.name.trim(),
      username: form.username.trim(),
      email: form.email.trim() || null,
      phone: form.phone.trim() || null,
      position: form.position.trim() || null,
      site_id: form.siteId ? Number(form.siteId) : null,
      is_active: form.isActive,
      ...(canAssignRole ? { roles: form.roles } : {}),
      ...(!isEdit ? { password: form.password, password_confirmation: form.passwordConfirm } : {}),
    }
    const opts = { onSuccess: onClose, onError: (e: unknown) => setErr(apiErrorMessage(e)) }
    if (isEdit) update.mutate({ id: user.id, body }, opts)
    else create.mutate(body, opts)
  }

  const pending = create.isPending || update.isPending
  const valid = form.name.trim() && form.username.trim() && form.roles.length > 0 && (isEdit || form.password.length >= 8)

  return (
    <Modal
      open
      onClose={onClose}
      title={isEdit ? `Ubah Pengguna — ${user.username}` : 'Tambah Pengguna'}
      className="max-w-2xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
          <Button size="sm" disabled={pending || !valid} onClick={submit}>Simpan</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1">
            <Label htmlFor="u-name">Nama lengkap *</Label>
            <Input id="u-name" value={form.name} onChange={(e) => set('name', e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="u-username">Username *</Label>
            <Input id="u-username" value={form.username} autoComplete="off" onChange={(e) => set('username', e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="u-email">Email</Label>
            <Input id="u-email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="u-phone">No. HP</Label>
            <Input id="u-phone" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="u-position">Jabatan</Label>
            <Input id="u-position" value={form.position} onChange={(e) => set('position', e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="u-site">Site</Label>
            <select id="u-site" className={selectClass} value={form.siteId} onChange={(e) => set('siteId', e.target.value)}>
              <option value="">— Tidak ada —</option>
              {options?.sites.map((s) => (
                <option key={s.id} value={s.id}>{s.code} — {s.name}</option>
              ))}
            </select>
          </div>
        </div>

        {!isEdit && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label htmlFor="u-pass">Password awal *</Label>
              <div className="flex gap-2">
                <Input id="u-pass" type="text" autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} />
                <Button
                  type="button" variant="outline" size="sm" className="h-10 shrink-0"
                  onClick={() => {
                    const p = generatePassword()
                    setForm((f) => ({ ...f, password: p, passwordConfirm: p }))
                  }}
                >
                  Buat otomatis
                </Button>
              </div>
            </div>
            <div className="space-y-1">
              <Label htmlFor="u-pass2">Ulangi password *</Label>
              <Input id="u-pass2" type="text" autoComplete="new-password" value={form.passwordConfirm} onChange={(e) => set('passwordConfirm', e.target.value)} />
            </div>
            <p className="text-xs text-muted-foreground sm:col-span-2">
              Minimal 8 karakter, berisi huruf dan angka. Catat dan berikan ke pengguna — minta mereka menggantinya lewat menu "Ganti Password".
            </p>
          </div>
        )}

        <div className="space-y-2">
          <Label>Role *</Label>
          {!canAssignRole && <p className="text-xs text-muted-foreground">Anda tidak punya izin mengubah role pengguna.</p>}
          <div className="grid gap-2 sm:grid-cols-2">
            {options?.roles.map((r) => {
              const lockedSuper = r.slug === SUPER_ADMIN && !iAmSuper
              return (
                <label key={r.slug} className={`flex items-center gap-2 rounded-md border px-3 py-2 text-sm ${lockedSuper || !canAssignRole ? 'opacity-50' : 'cursor-pointer hover:bg-muted/40'}`}>
                  <input
                    type="checkbox"
                    checked={form.roles.includes(r.slug)}
                    disabled={lockedSuper || !canAssignRole}
                    onChange={() => toggleRole(r.slug)}
                  />
                  <span>{r.name}</span>
                  {!r.is_system && <Badge variant="neutral">custom</Badge>}
                </label>
              )
            })}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.isActive} onChange={(e) => set('isActive', e.target.checked)} />
          Akun aktif (bisa login)
        </label>

        {err && <p className="whitespace-pre-line text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}

function ResetPasswordModal({ user, onClose }: { user: AdminUserRow; onClose: () => void }) {
  const reset = useResetUserPassword()
  const [password, setPassword] = useState(() => generatePassword())
  const [err, setErr] = useState<string | null>(null)

  return (
    <Modal
      open
      onClose={onClose}
      title={`Reset Password — ${user.username}`}
      description="Semua sesi login pengguna ini akan langsung diakhiri."
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
          <Button
            size="sm"
            disabled={reset.isPending || password.length < 8}
            onClick={() => reset.mutate({ id: user.id, password }, { onSuccess: onClose, onError: (e) => setErr(apiErrorMessage(e)) })}
          >
            Reset Password
          </Button>
        </>
      }
    >
      <div className="space-y-2">
        <Label htmlFor="rp-pass">Password baru</Label>
        <div className="flex gap-2">
          <Input id="rp-pass" type="text" className="font-mono" value={password} onChange={(e) => setPassword(e.target.value)} />
          <Button type="button" variant="outline" size="sm" className="h-10 shrink-0" onClick={() => setPassword(generatePassword())}>
            Acak ulang
          </Button>
        </div>
        <p className="text-xs text-muted-foreground">Catat password ini sebelum menyimpan dan berikan langsung ke pengguna.</p>
        {err && <p className="whitespace-pre-line text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}

export function UsersPage() {
  const { user: me, hasPermission } = useAuth()
  const iAmSuper = !!me?.roles.includes(SUPER_ADMIN)
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useUsers({
    search: search || undefined,
    role: role || undefined,
    is_active: status ? (status as '0' | '1') : undefined,
    page,
  })
  // scroll to the end instead of Sebelumnya/Berikutnya (pages appended while scrolling)
  const { rows: scrollRows, footer: scrollFooter } = useInfiniteRows(data, page, setPage)
  const { data: options } = useUserOptions()
  const update = useUpdateUser()
  const del = useDeleteUser()

  const [editing, setEditing] = useState<AdminUserRow | 'new' | null>(null)
  const [resetting, setResetting] = useState<AdminUserRow | null>(null)

  const canCreate = hasPermission('user.create')
  const canUpdate = hasPermission('user.update')
  const canDelete = hasPermission('user.delete')
  const touchable = (u: AdminUserRow) => iAmSuper || !u.roles.some((r) => r.slug === SUPER_ADMIN)
  const isMe = (u: AdminUserRow) => u.id === me?.id

  async function toggleActive(u: AdminUserRow) {
    const verb = u.is_active ? 'nonaktifkan' : 'aktifkan'
    if (!(await confirmDialog(`Yakin ${verb} akun ${u.username}?${u.is_active ? ' Semua sesi loginnya akan diakhiri.' : ''}`))) return
    update.mutate({ id: u.id, body: { is_active: !u.is_active } })
  }

  async function remove(u: AdminUserRow) {
    if (!(await confirmDialog(`Hapus permanen akun ${u.username}? Jika pengguna sudah punya riwayat transaksi, sistem akan menolak — nonaktifkan saja.`))) return
    del.mutate(u.id)
  }

  const columns: Column<AdminUserRow>[] = [
    {
      key: 'name',
      header: 'Pengguna',
      cell: (u) => (
        <div>
          <div className="font-medium">{u.name}{isMe(u) && <span className="ml-1 text-xs text-primary">(Anda)</span>}</div>
          <div className="font-mono text-xs text-muted-foreground">{u.username}</div>
        </div>
      ),
    },
    { key: 'email', header: 'Email / HP', cell: (u) => <div className="text-xs">{u.email ?? '—'}<div className="text-muted-foreground">{u.phone ?? ''}</div></div> },
    { key: 'site', header: 'Site', cell: (u) => u.site?.code ?? '—' },
    {
      key: 'roles',
      header: 'Role',
      cell: (u) => (
        <div className="flex flex-wrap gap-1">
          {u.roles.map((r) => <Badge key={r.slug} variant={r.slug === SUPER_ADMIN ? 'warning' : 'default'}>{r.name}</Badge>)}
        </div>
      ),
    },
    { key: 'status', header: 'Status', cell: (u) => (u.is_active ? <Badge variant="success">Aktif</Badge> : <Badge variant="neutral">Nonaktif</Badge>) },
    {
      key: 'login',
      header: 'Login terakhir',
      cell: (u) => <span className="text-xs">{u.last_login_at ? new Date(u.last_login_at).toLocaleString('id-ID') : '—'}</span>,
    },
  ]

  if (canUpdate || canDelete) {
    columns.push({
      key: 'actions',
      header: '',
      cell: (u) =>
        touchable(u) ? (
          <div className="flex justify-end gap-1">
            {canUpdate && (
              <>
                <Button size="sm" variant="ghost" title="Ubah" onClick={() => setEditing(u)}><Pencil className="size-4" /></Button>
                <Button size="sm" variant="ghost" title="Reset password" onClick={() => setResetting(u)}><KeyRound className="size-4" /></Button>
                {!isMe(u) && (
                  <Button size="sm" variant="ghost" title={u.is_active ? 'Nonaktifkan' : 'Aktifkan'} onClick={() => toggleActive(u)} disabled={update.isPending}>
                    <Power className={`size-4 ${u.is_active ? 'text-destructive' : 'text-green-600'}`} />
                  </Button>
                )}
              </>
            )}
            {canDelete && !isMe(u) && (
              <Button size="sm" variant="ghost" title="Hapus" onClick={() => remove(u)} disabled={del.isPending}><Trash2 className="size-4" /></Button>
            )}
          </div>
        ) : (
          <span className="text-xs text-muted-foreground">khusus Super Admin</span>
        ),
    })
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title="Pengguna"
        subtitle="Kelola akun, role, dan status login pengguna STOCKWISE"
        icon={<Users className="size-5" />}
        actions={canCreate ? <Button size="sm" onClick={() => setEditing('new')}><UserPlus className="size-4" /> Tambah Pengguna</Button> : undefined}
      />

      <FilterBar>
        <Input
          placeholder="Cari nama / username / email…"
          className="max-w-xs"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
        <select className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm" value={role} onChange={(e) => { setRole(e.target.value); setPage(1) }}>
          <option value="">Semua role</option>
          {options?.roles.map((r) => <option key={r.slug} value={r.slug}>{r.name}</option>)}
        </select>
        <select className="h-9 max-w-full rounded-md border border-input bg-card px-3 text-sm" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">Semua status</option>
          <option value="1">Aktif</option>
          <option value="0">Nonaktif</option>
        </select>
      </FilterBar>

      <DataTable columns={columns} rows={scrollRows}
        footer={scrollFooter} rowKey={(u) => u.id} isLoading={isLoading} emptyText="Tidak ada pengguna." />

      {editing && <UserFormModal user={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      {resetting && <ResetPasswordModal user={resetting} onClose={() => setResetting(null)} />}
    </div>
  )
}
