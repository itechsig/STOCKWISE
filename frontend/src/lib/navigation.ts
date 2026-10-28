import {
  ArrowLeftRight, BarChart3, BookOpenCheck, Boxes, ClipboardCheck, ClipboardList, ClipboardPen, FileSpreadsheet, FileStack, Handshake, History,
  LayoutDashboard, type LucideIcon, Package, PackageCheck, Receipt, Recycle, ShieldHalf, ShoppingCart, Truck, Undo2, Users, Wifi, Wrench,
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
    items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard, keywords: 'beranda ringkasan' }],
  },
  {
    // Important Request: work instructions, tasks and their follow-up (its own module, not inventory)
    label: 'Important Request',
    items: [
      {
        to: '/important-requests', label: 'Important Request', icon: ClipboardPen, permissions: ['important_request.access'], badge: 'important',
        keywords: 'instruksi tugas perintah kerja direktur atasan',
        children: [
          { to: '/important-requests/saya', label: 'Permintaan Saya', keywords: 'request saya buat', permissions: ['important_request.create'] },
          { to: '/important-requests/tugas', label: 'Tugas Saya', keywords: 'tugas ditugaskan' },
          { to: '/important-requests/persetujuan', label: 'Perlu Persetujuan', keywords: 'review hasil approve', permissions: ['important_request.create'] },
          { to: '/important-requests/selesai', label: 'Selesai', keywords: 'selesai arsip' },
        ],
      },
    ],
  },
  {
    // "Item Request" = the former Operasional Gudang + Pengadaan groups, one group
    label: 'Item Request',
    items: [
      { to: '/item-requests', label: 'Request Barang', icon: ClipboardList, permissions: ['item_request.create', 'item_request.view'], keywords: 'permintaan' },
      { to: '/approvals', label: 'Approval', icon: ClipboardCheck, permissions: APPROVAL_PERMISSIONS, badge: 'approvals', keywords: 'manager direktur serah terima' },
      { to: '/npbg', label: 'NPBG', icon: FileStack, permissions: ['npbg.view'], keywords: 'barang keluar pengeluaran' },
      { to: '/ppb', label: 'PPB', icon: FileStack, permissions: ['ppb.view'], keywords: 'permintaan pembelian' },
      { to: '/purchase-orders', label: 'Purchase Order', icon: ShoppingCart, permissions: ['po.view'], keywords: 'po purchasing pembelian' },
      { to: '/ri', label: 'RI', icon: Receipt, permissions: ['ri.view'], keywords: 'penerimaan barang masuk' },
      { to: '/purchase-returns', label: 'Retur Pembelian', icon: Undo2, permissions: ['purchase_return.view'], keywords: 'purchase return vendor' },
      { to: '/stock-opnames', label: 'Stock Opname', icon: PackageCheck, permissions: ['opname.view'], keywords: 'so hitung fisik penyesuaian' },
      { to: '/stock-card', label: 'Kartu Stock', icon: BookOpenCheck, permissions: ['item.view', 'inventory.view'], keywords: 'kartu stok proyek' },
    ],
  },
  {
    label: 'Tracking & Analytic',
    items: [
      { to: '/lend', label: 'Peminjaman (Lend)', icon: Handshake, permissions: ['lend.view'] },
      { to: '/borrow', label: 'Pinjam Luar (Borrow)', icon: ArrowLeftRight, permissions: ['borrow.view'] },
      { to: '/stpp', label: 'STPP', icon: Package, permissions: ['stpp.view'] },
      { to: '/tyre-changes', label: 'Ban Luar', icon: Truck, permissions: ['tyre.view'], keywords: 'tyre' },
      { to: '/maintenance', label: 'Maintenance', icon: Wrench, permissions: ['maintenance.view'] },
      { to: '/manufacturing', label: 'Manufaktur & Assembly', icon: Boxes, permissions: ['manufacturing.view'] },
      { to: '/used-returns', label: 'Pengembalian Bekas', icon: Recycle, permissions: ['used_return.view'] },
      { to: '/analytics/important-requests', label: 'Analitik Important Request', icon: BarChart3, permissions: ['important_request.analytics'], keywords: 'analitik instruksi tugas kinerja' },
    ],
  },
  {
    label: 'Data & Laporan',
    items: [
      {
        // Master Data: Barang, Jasa (Master Produk) and Vendor & Supplier
        to: '/items', label: 'Master Data', icon: Package, permissions: ['item.view', 'master.vendor.view', 'master.employee.view'], selfPermissions: ['item.view'], keywords: 'master data barang item produk stok persediaan',
        children: [
          { to: '/items?kind=BARANG', label: 'Barang', keywords: 'master barang stok produk', permissions: ['item.view'] },
          { to: '/items?kind=JASA', label: 'Jasa', keywords: 'master jasa servis layanan', permissions: ['item.view'] },
          { to: '/vendors', label: 'Vendor & Supplier', keywords: 'master vendor supplier pemasok', permissions: ['master.vendor.view'] },
          { to: '/employees', label: 'Data Pekerja', keywords: 'master pekerja karyawan pegawai roster', permissions: ['master.employee.view'] },
        ],
      },
      { to: '/inventory/analysis', label: 'Analisis & Safety Stock', icon: Boxes, permissions: ['inventory.view_analysis', 'item.safety_stock.view'], keywords: 'persediaan inventory' },
      { to: '/reports', label: 'Laporan & Export', icon: FileSpreadsheet, permissions: ['report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname', 'report.stock_movement'] },
    ],
  },
  {
    label: 'Integrasi Accurate',
    items: [
      { to: '/sync/history', label: 'Sinkron & Log Accurate', icon: History, permissions: ['sync.accurate.view'], keywords: 'sync audit log' },
      { to: '/accurate/online', label: 'Accurate Online', icon: Wifi, permissions: ['accurate.session.view'] },
    ],
  },
  {
    label: 'Pengaturan',
    items: [
      { to: '/settings/users', label: 'Pengguna', icon: Users, permissions: ['user.view'], keywords: 'user akun' },
      { to: '/settings/roles', label: 'Role & Hak Akses', icon: ShieldHalf, permissions: ['role.view'], keywords: 'permission' },
    ],
  },
]

/** Pages reached from another menu entry (detail / new pages, older paths). */
const EXTRA: { prefix: string; parent: string; label: string }[] = [
  { prefix: '/purchase-returns/new', parent: '/purchase-returns', label: 'Buat retur' },
  { prefix: '/ppb/drafts/', parent: '/ppb', label: 'Draft PPB' },
  { prefix: '/ppb/drafts', parent: '/ppb', label: 'Draft PPB' },
  { prefix: '/stock-card/project', parent: '/stock-card', label: 'Proyek' },
  { prefix: '/safety-stocks', parent: '/inventory/analysis', label: 'Safety Stock' },
  { prefix: '/sync/', parent: '/sync/history', label: 'Detail' },
  { prefix: '/vendors', parent: '/items', label: 'Vendor & Supplier' },
  { prefix: '/employees', parent: '/items', label: 'Data Pekerja' },
  { prefix: '/important-requests/saya', parent: '/important-requests', label: 'Permintaan Saya' },
  { prefix: '/important-requests/tugas', parent: '/important-requests', label: 'Tugas Saya' },
  { prefix: '/important-requests/persetujuan', parent: '/important-requests', label: 'Perlu Persetujuan' },
  { prefix: '/important-requests/selesai', parent: '/important-requests', label: 'Selesai' },
  { prefix: '/important-requests/baru', parent: '/important-requests', label: 'Buat request' },
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
