import {
  ClipboardList, ClipboardPen, Handshake, History,
  LayoutDashboard, type LucideIcon, Package, ShieldHalf,
} from 'lucide-react'

/** One menu entry: the sidebar, the breadcrumb and the menu search all read this. */
export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  permissions?: string[]
  /** a live count next to the label (e.g. requests waiting for approval) */
  badge?: 'approvals' | 'important'
  /** who may open `to` itself; without them the menu opens its first sub menu they may open */
  selfPermissions?: string[]
  /** other words the menu search finds it by */
  keywords?: string
  /** sub menus shown under it (a dropdown in the sidebar) */
  children?: { to: string; label: string; keywords?: string; permissions?: string[] }[]
}

export interface NavGroup {
  label: string | null
  items: NavItem[]
}

const APPROVAL_PERMISSIONS = ['item_request.approve_manager', 'item_request.approve_director', 'item_request.handover', 'ppb.approve_manager', 'ppb.approve_director', 'po.approve_director', 'npbg.approve_manager', 'npbg.approve_director']

export const NAV: NavGroup[] = [
  {
    label: null,
    items: [
      { to: '/', label: 'Dashboard', icon: LayoutDashboard, keywords: 'beranda ringkasan' },
      {
        to: '/important-requests', label: 'Important Request', icon: ClipboardPen, permissions: ['important_request.access'], badge: 'important', keywords: 'instruksi tugas perintah kerja direktur atasan',
        children: [
          { to: '/important-requests', label: 'Dashboard', keywords: 'ringkasan', permissions: ['important_request.access'] },
          { to: '/important-requests/saya', label: 'Permintaan Saya', keywords: 'request saya buat', permissions: ['important_request.create'] },
          { to: '/important-requests/tugas', label: 'Tugas Saya', keywords: 'tugas ditugaskan', permissions: ['important_request.access'] },
          { to: '/important-requests/persetujuan', label: 'Perlu Persetujuan', keywords: 'review hasil approve', permissions: ['important_request.create'] },
          { to: '/important-requests/selesai', label: 'Selesai', keywords: 'selesai arsip', permissions: ['important_request.access'] },
        ],
      },
      {
        to: '/item-requests', label: 'Item Request', icon: ClipboardList, permissions: ['item_request.create', 'item_request.view', ...APPROVAL_PERMISSIONS], keywords: 'permintaan barang',
        children: [
          { to: '/item-requests', label: 'Request Barang', keywords: 'permintaan', permissions: ['item_request.create', 'item_request.view'] },
          { to: '/approvals', label: 'Approval', keywords: 'manager direktur serah terima', permissions: APPROVAL_PERMISSIONS },
          { to: '/npbg', label: 'NPBG', keywords: 'barang keluar pengeluaran', permissions: ['npbg.view'] },
          { to: '/ppb', label: 'PPB', keywords: 'permintaan pembelian', permissions: ['ppb.view'] },
          { to: '/purchase-orders', label: 'Purchase Order', keywords: 'po purchasing pembelian', permissions: ['po.view'] },
          { to: '/ri', label: 'RI', keywords: 'penerimaan barang masuk', permissions: ['ri.view'] },
          { to: '/purchase-returns', label: 'Retur Pembelian', keywords: 'purchase return vendor', permissions: ['purchase_return.view'] },
          { to: '/stock-opnames', label: 'Stock Opname', keywords: 'so hitung fisik penyesuaian', permissions: ['opname.view'] },
          { to: '/stock-card', label: 'Kartu Stock', keywords: 'kartu stok proyek', permissions: ['item.view', 'inventory.view'] },
        ],
      },
      {
        to: '/lend', label: 'Tracking & Analytic', icon: Handshake, permissions: ['lend.view', 'borrow.view', 'stpp.view', 'tyre.view', 'maintenance.view', 'manufacturing.view', 'used_return.view', 'important_request.analytics'], keywords: 'tracking analytic',
        children: [
          { to: '/lend', label: 'Peminjaman (Lend)', permissions: ['lend.view'] },
          { to: '/borrow', label: 'Pinjam Luar (Borrow)', permissions: ['borrow.view'] },
          { to: '/stpp', label: 'STPP', permissions: ['stpp.view'] },
          { to: '/tyre-changes', label: 'Ban Luar', keywords: 'tyre', permissions: ['tyre.view'] },
          { to: '/maintenance', label: 'Maintenance', permissions: ['maintenance.view'] },
          { to: '/manufacturing', label: 'Manufaktur & Assembly', permissions: ['manufacturing.view'] },
          { to: '/used-returns', label: 'Pengembalian Bekas', permissions: ['used_return.view'] },
          { to: '/analytics/important-requests', label: 'Analitik Important Request', keywords: 'analitik instruksi tugas kinerja', permissions: ['important_request.analytics'] },
        ],
      },
      {
        to: '/items', label: 'Data & Laporan', icon: Package, permissions: ['item.view', 'master.vendor.view', 'master.employee.view', 'inventory.view_analysis', 'item.safety_stock.view', 'report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname', 'report.stock_movement'], keywords: 'data laporan',
        children: [
          { to: '/items?kind=BARANG', label: 'Barang', keywords: 'master barang stok produk', permissions: ['item.view'] },
          { to: '/items?kind=JASA', label: 'Jasa', keywords: 'master jasa servis layanan', permissions: ['item.view'] },
          { to: '/vendors', label: 'Vendor & Supplier', keywords: 'master vendor supplier pemasok', permissions: ['master.vendor.view'] },
          { to: '/employees', label: 'Data Pekerja', keywords: 'master pekerja karyawan pegawai roster', permissions: ['master.employee.view'] },
          { to: '/inventory/analysis', label: 'Analisis & Safety Stock', keywords: 'persediaan inventory', permissions: ['inventory.view_analysis', 'item.safety_stock.view'] },
          { to: '/reports', label: 'Laporan & Export', permissions: ['report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname', 'report.stock_movement'] },
        ],
      },
      {
        to: '/sync/history', label: 'Integrasi Accurate', icon: History, permissions: ['sync.accurate.view', 'accurate.session.view'], keywords: 'sync accurate',
        children: [
          { to: '/sync/history', label: 'Sinkron & Log Accurate', keywords: 'sync audit log', permissions: ['sync.accurate.view'] },
          { to: '/accurate/online', label: 'Accurate Online', permissions: ['accurate.session.view'] },
        ],
      },
      {
        to: '/settings/users', label: 'Pengaturan', icon: ShieldHalf, permissions: ['user.view', 'role.view'], keywords: 'settings pengaturan',
        children: [
          { to: '/settings/users', label: 'Pengguna', keywords: 'user akun', permissions: ['user.view'] },
          { to: '/settings/roles', label: 'Role & Hak Akses', keywords: 'permission', permissions: ['role.view'] },
        ],
      },
    ],
  },
]

/** Pages reached from another menu entry (detail / new pages, older paths). */
const EXTRA: { prefix: string; parent: string; label: string }[] = [
  { prefix: '/purchase-returns/new', parent: '/item-requests', label: 'Buat retur' },
  { prefix: '/ppb/drafts/', parent: '/item-requests', label: 'Draft PPB' },
  { prefix: '/ppb/drafts', parent: '/item-requests', label: 'Draft PPB' },
  { prefix: '/stock-card/project', parent: '/item-requests', label: 'Proyek' },
  { prefix: '/safety-stocks', parent: '/items', label: 'Safety Stock' },
  { prefix: '/sync/', parent: '/sync/history', label: 'Detail' },
  { prefix: '/vendors', parent: '/items', label: 'Vendor & Supplier' },
  { prefix: '/employees', parent: '/items', label: 'Data Pekerja' },
  { prefix: '/important-requests/baru', parent: '/important-requests/saya', label: 'Buat request' },
  { prefix: '/important-requests/', parent: '/important-requests', label: 'Detail' },
]

export interface Crumb { label: string; to?: string }

/** Breadcrumb of a path: group › menu › detail. */
export function crumbsFor(pathname: string): Crumb[] {
  if (pathname === '/') return [{ label: 'Dashboard' }]
  const all = NAV.flatMap((g) => g.items.map((i) => ({ ...i, group: g.label })))
  const extra = EXTRA.find((e) => pathname.startsWith(e.prefix) && pathname !== e.parent)
  const base = extra ? all.find((i) => i.to === extra.parent) : [...all].filter((i) => i.to !== '/' && (pathname === i.to || pathname.startsWith(i.to + '/')))
    .sort((a, b) => b.to.length - a.to.length)[0]
  if (!base) return []
  const out: Crumb[] = []
  if (base.group) out.push({ label: base.group })
  if (pathname === base.to) {
    out.push({ label: base.label })
  } else {
    out.push({ label: base.label, to: base.to })
    out.push({ label: extra?.label ?? 'Detail' })
  }
  return out
}

/** The menu entries a user can open. */
export function visibleNav(hasPermission: (p: string) => boolean): NavGroup[] {
  const can = (perms?: string[]) => !perms || perms.some((p) => hasPermission(p))
  return NAV.map((g) => ({
    ...g,
    items: g.items.filter((n) => can(n.permissions)).map((n) => {
      if (!n.children) return n
      const children = n.children.filter((c) => can(c.permissions))
      // without the right to open the menu's own page, it opens its first sub menu the user may open (e.g. only Vendor & Supplier)
      return { ...n, children, to: can(n.selfPermissions) || children.length === 0 ? n.to : children[0].to }
    }),
  })).filter((g) => g.items.length > 0)
}
