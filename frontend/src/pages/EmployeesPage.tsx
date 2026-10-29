import { useEffect, useState } from 'react'
import { Download, FileUp, Pencil, Plus, UserRound } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { useEmployeeDepartments, useEmployees, useEmployeeUsers, useSaveEmployee, downloadEmployeeTemplate, useImportEmployees, type Employee } from '@/features/employees/api'
import { apiErrorMessage } from '@/lib/api'
import { invalidTo, toast } from '@/lib/notify'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, type Column } from '@/components/DataTable'
import { FilterBar } from '@/components/FilterBar'
import { ColumnMenu, useHiddenColumns, visibleColumns } from '@/components/ColumnVisibility'
import { Modal } from '@/components/ui/modal'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'

type Draft = { id?: number; name: string; position: string; phone_wa: string; phone_office: string; department_id: string; user_id: string; is_active: boolean }
const EMPTY: Draft = { name: '', position: '', phone_wa: '', phone_office: '', department_id: '', user_id: '', is_active: true }

/** Add / edit one worker. */
function EmployeeForm({ open, employee, onClose }: { open: boolean; employee: Employee | null; onClose: () => void }) {
  const save = useSaveEmployee()
  const { data: departments } = useEmployeeDepartments()
  const { data: users } = useEmployeeUsers(open)
  const [d, setD] = useState<Draft>(EMPTY)
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => {
    if (!open) return
    setErr(null)
    setD(employee ? {
      id: employee.id, name: employee.name, position: employee.position ?? '', phone_wa: employee.phone_wa ?? '', phone_office: employee.phone_office ?? '',
      department_id: employee.department_id ? String(employee.department_id) : '', user_id: employee.user_id ? String(employee.user_id) : '', is_active: employee.is_active,
    } : EMPTY)
  }, [open, employee])
  const set = (k: keyof Draft) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setD((x) => ({ ...x, [k]: e.target.value }))

  const submit = () => {
    setErr(null)
    if (!d.name.trim()) return invalidTo(setErr, 'Nama pekerja wajib diisi.')
    save.mutate({
      id: d.id, name: d.name.trim(), position: d.position.trim() || null, phone_wa: d.phone_wa.trim() || null, phone_office: d.phone_office.trim() || null,
      department_id: d.department_id ? Number(d.department_id) : null, user_id: d.user_id ? Number(d.user_id) : null,
      ...(d.id ? { is_active: d.is_active } : {}),
    }, {
      onSuccess: () => { toast.success(d.id ? 'Data pekerja diperbarui.' : 'Pekerja ditambahkan.'); onClose() },
      onError: (e) => setErr(apiErrorMessage(e)),
    })
  }

  return (
    <Modal open={open} onClose={onClose} title={employee ? `Ubah ${employee.name}` : 'Tambah Pekerja'} className="max-w-lg"
      description="Data pekerja perusahaan: nama, jabatan, divisi, dan akun STOCKWISE-nya (kalau ada)."
      footer={<>
        <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
        <Button size="sm" onClick={submit} disabled={save.isPending}>{save.isPending ? 'Menyimpan…' : 'Simpan'}</Button>
      </>}>
      <div className="space-y-3">
        <div className="space-y-1.5"><Label htmlFor="e-name">Nama *</Label><Input id="e-name" value={d.name} maxLength={150} onChange={set('name')} placeholder="Nama lengkap" /></div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5"><Label htmlFor="e-pos">Jabatan</Label><Input id="e-pos" value={d.position} maxLength={100} onChange={set('position')} placeholder="mis. Driver" /></div>
          <div className="space-y-1.5"><Label htmlFor="e-phone-wa">No WA</Label><Input id="e-phone-wa" value={d.phone_wa} maxLength={40} onChange={set('phone_wa')} placeholder="628..." /></div>
        </div>
        <div className="space-y-1.5"><Label htmlFor="e-phone-office">No Tlp Kantor</Label><Input id="e-phone-office" value={d.phone_office} maxLength={40} onChange={set('phone_office')} placeholder="021..." /></div>
        <div className="space-y-1.5"><Label htmlFor="e-div">Divisi</Label>
          <Select id="e-div" value={d.department_id} onChange={set('department_id')}>
            <option value="">— pilih —</option>
            {(departments ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </Select>
        </div>
        <div className="space-y-1.5"><Label htmlFor="e-user">Akun STOCKWISE</Label>
          <Select id="e-user" value={d.user_id} onChange={set('user_id')}>
            <option value="">Tidak punya akun</option>
            {(users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name} ({u.username})</option>)}
          </Select>
        </div>
        {employee && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-4 accent-primary" checked={d.is_active} onChange={(e) => setD((x) => ({ ...x, is_active: e.target.checked }))} /> Aktif (masih bekerja)
          </label>
        )}
        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}

/** Master Data · Data Pekerja: the people of the company. */
export function EmployeesPage() {
  const { hasPermission } = useAuth()
  const canManage = hasPermission('master.employee.manage')
  const [typed, setTyped] = useState('')
  const [search, setSearch] = useState('')
  const [division, setDivision] = useState('')
  const [status, setStatus] = useState('')
  const [editing, setEditing] = useState<Employee | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const importEmployees = useImportEmployees()
  useEffect(() => { const t = setTimeout(() => setSearch(typed), 300); return () => clearTimeout(t) }, [typed])
  const { data: departments } = useEmployeeDepartments()
  const { data, isLoading } = useEmployees({ search: search || undefined, department_id: division || undefined, is_active: status || undefined })
  const rows = data ?? []

  const columns: Column<Employee>[] = [
    { key: 'name', header: 'Nama', cell: (e) => <span className="font-medium">{e.name}</span> },
    { key: 'position', header: 'Jabatan', cell: (e) => e.position ?? '' },
    { key: 'department', header: 'Divisi', cell: (e) => <span className="whitespace-nowrap">{e.department ?? ''}</span> },
    { key: 'phone_wa', header: 'No WA', cell: (e) => <span className="whitespace-nowrap">{e.phone_wa ?? ''}</span> },
    { key: 'phone_office', header: 'No Tlp Kantor', cell: (e) => <span className="whitespace-nowrap">{e.phone_office ?? ''}</span> },
    { key: 'user', header: 'Akun', cell: (e) => (e.user ? <span className="whitespace-nowrap">{e.user.name} <span className="font-mono text-xs text-muted-foreground">({e.user.username})</span></span> : <span className="text-muted-foreground">-</span>) },
    { key: 'status', header: 'Status', cell: (e) => (e.is_active ? <Badge variant="success">Aktif</Badge> : <Badge variant="neutral">Nonaktif</Badge>) },
    ...(canManage ? [{
      key: 'aksi', header: <span className="sr-only">Aksi</span>, className: 'text-right',
      cell: (e: Employee) => <Button size="sm" variant="ghost" className="h-8" onClick={() => { setEditing(e); setFormOpen(true) }}><Pencil className="size-3.5" /> Ubah</Button>,
    }] : []),
  ]
  const [hiddenCols, toggleCol] = useHiddenColumns('stockwise:employee-hidden-columns')

  const handleImport = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    importEmployees.mutate(file, {
      onSuccess: (result) => {
        toast.success(`Import selesai: ${result.created} ditambahkan, ${result.updated} diperbarui`)
        if (result.errors.length > 0) {
          toast.warning(`${result.errors.length} error: ${result.errors.slice(0, 3).join(', ')}${result.errors.length > 3 ? '...' : ''}`)
        }
      },
      onError: (err) => {
        toast.fromError(err, apiErrorMessage(err))
      },
    })
  }

  return (
    <div className="space-y-4">
      <PageHeader title="Master Data · Data Pekerja" icon={<UserRound className="size-5" />}
        subtitle="Data pekerja perusahaan: nama, jabatan, divisi, dan akun STOCKWISE-nya."
        actions={canManage && <>
          <Button size="sm" variant="outline" onClick={downloadEmployeeTemplate}><Download className="size-4" /> Template</Button>
          <Button size="sm" variant="outline" onClick={() => document.getElementById('employee-import')?.click()} disabled={importEmployees.isPending}>
            <FileUp className="size-4" /> {importEmployees.isPending ? 'Mengimpor...' : 'Import'}
          </Button>
          <input id="employee-import" type="file" accept=".xlsx,.xls" className="hidden" onChange={handleImport} />
          <Button size="sm" onClick={() => { setEditing(null); setFormOpen(true) }}><Plus className="size-4" /> Tambah</Button>
        </>} />

      <FilterBar end={<>
        <span className="text-xs text-muted-foreground">{rows.length.toLocaleString('id-ID')} pekerja</span>
        <ColumnMenu columns={columns} hiddenCols={hiddenCols} onToggle={toggleCol} alwaysVisible={['name', 'aksi']} hint="Pilih kolom yang tampil di tabel." />
      </>}>
        <Input className="w-full sm:max-w-sm" placeholder="Cari nama, jabatan, no WA, no tlp kantor, divisi…" value={typed} onChange={(e) => setTyped(e.target.value)} />
        <div className="w-full sm:w-52">
          <Select aria-label="Divisi" value={division} onChange={(e) => setDivision(e.target.value)}>
            <option value="">Semua divisi</option>
            {(departments ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </Select>
        </div>
        <div className="w-full sm:w-40">
          <Select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">Semua status</option>
            <option value="1">Aktif</option>
            <option value="0">Nonaktif</option>
          </Select>
        </div>
      </FilterBar>

      <DataTable columns={visibleColumns(columns, hiddenCols, ['name', 'aksi'])} rows={rows} rowKey={(e) => e.id} isLoading={isLoading}
        emptyText={search || division || status ? 'Tidak ada pekerja untuk filter ini.' : 'Belum ada data pekerja.'} />

      <EmployeeForm open={formOpen} employee={editing} onClose={() => setFormOpen(false)} />
    </div>
  )
}
