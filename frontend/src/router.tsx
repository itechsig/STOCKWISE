import { createBrowserRouter, Navigate } from 'react-router-dom'
import { recordLocation } from '@/lib/navHistory'
import { RequireAuth, RequirePermission } from '@/auth/guards'
import { AppLayout } from '@/components/AppLayout'
import { DashboardPage } from '@/pages/DashboardPage'
import { VendorsPage } from '@/pages/VendorsPage'
import { EmployeesPage } from '@/pages/EmployeesPage'
import { IrDashboardPage, IrListPage } from '@/pages/importantRequests/IrListPages'
import { IrDetailPage } from '@/pages/importantRequests/IrDetailPage'
import { IrFormPage } from '@/pages/importantRequests/IrFormPage'
import { IrAnalyticsPage } from '@/pages/importantRequests/IrAnalyticsPage'
import { HealthPage } from '@/pages/HealthPage'
import { InventoryHealthPage } from '@/pages/InventoryHealthPage'
import { ItemsPage } from '@/pages/ItemsPage'
import { LoginPage } from '@/pages/LoginPage'
import { NpbgDetailPage } from '@/pages/NpbgDetailPage'
import { NpbgListPage } from '@/pages/NpbgListPage'
import { OpnameDetailPage } from '@/pages/OpnameDetailPage'
import { OpnameListPage } from '@/pages/OpnameListPage'
import { StockCardPage } from '@/pages/StockCardPage'
import { StockCardProjectsPage } from '@/pages/StockCardProjectsPage'
import { StockCardProjectPage } from '@/pages/StockCardProjectPage'
import { PoDetailPage } from '@/pages/PoDetailPage'
import { PoListPage } from '@/pages/PoListPage'
import { PoDraftPage } from '@/pages/PoDraftPage'
import { PpbDetailPage } from '@/pages/PpbDetailPage'
import { PpbListPage } from '@/pages/PpbListPage'
import { ReportsPage } from '@/pages/ReportsPage'
import { RiDetailPage } from '@/pages/RiDetailPage'
import { RiListPage } from '@/pages/RiListPage'
import { SyncPage } from '@/pages/SyncPage'
import { AccurateOnlinePage } from '@/pages/AccurateOnlinePage'
import { BorrowPage } from '@/pages/tracking/BorrowPage'
import { LendPage } from '@/pages/tracking/LendPage'
import { MaintenancePage } from '@/pages/tracking/MaintenancePage'
import { ManufacturingPage } from '@/pages/tracking/ManufacturingPage'
import { StppPage } from '@/pages/tracking/StppPage'
import { TyrePage } from '@/pages/tracking/TyrePage'
import { UsedReturnPage } from '@/pages/tracking/UsedReturnPage'
import { UsersPage } from '@/pages/UsersPage'
import { RolesPage } from '@/pages/RolesPage'
import { SharedStockCardLayout, SharedStockCardProject } from '@/pages/SharedStockCardLayout'
import { PublicItemRequestPage } from '@/pages/PublicItemRequestPage'
import { ItemRequestListPage } from '@/pages/ItemRequestListPage'
import { ItemRequestDetailPage } from '@/pages/ItemRequestDetailPage'
import { ApprovalPage } from '@/pages/ApprovalPage'
import { PpbDraftListPage, PpbDraftPage } from '@/pages/PpbDraftPage'
import { PurchaseReturnListPage } from '@/pages/PurchaseReturnListPage'
import { PurchaseReturnNewPage } from '@/pages/PurchaseReturnNewPage'
import { PurchaseReturnDetailPage } from '@/pages/PurchaseReturnDetailPage'
import { RequestStatusPage } from '@/pages/RequestStatusPage'
import { APPROVAL_PERMISSIONS } from '@/features/itemRequests/api'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/health', element: <HealthPage /> },
  // Kartu Stock through a share link: no account, the link's project only.
  {
    path: '/share/kartu-stock/:token',
    element: <SharedStockCardLayout />,
    children: [
      { index: true, element: <SharedStockCardProject /> },
      { path: 'kartu/:id', element: <StockCardPage /> },
    ],
  },
  // Request Barang through a share link: no account, can only send a request.
  { path: '/form/request/:token', element: <PublicItemRequestPage /> },
  // Cek Permintaan: a requester follows their requests without an account
  { path: '/cek-permintaan', element: <RequestStatusPage /> },
  { path: '/cek-permintaan/:token', element: <RequestStatusPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <DashboardPage /> },
          {
            element: <RequirePermission permission={['item_request.create', 'item_request.view']} />,
            children: [
              { path: '/item-requests', element: <ItemRequestListPage /> },
            ],
          },
          {
            // the requester, admin gudang, and every approver of the request's steps
            element: <RequirePermission permission={['item_request.create', 'item_request.view', ...APPROVAL_PERMISSIONS]} />,
            children: [
              { path: '/item-requests/:id', element: <ItemRequestDetailPage /> },
            ],
          },
          {
            // Draft PPB from the Restock button (Analisis & Safety Stock)
            element: <RequirePermission permission={['ppb.create']} />,
            children: [
              { path: '/ppb/drafts', element: <PpbDraftListPage /> },
              { path: '/ppb/drafts/:id', element: <PpbDraftPage /> },
            ],
          },
          {
            // Master Vendor & Supplier (one master for both)
            element: <RequirePermission permission={['master.vendor.view']} />,
            children: [{ path: '/vendors', element: <VendorsPage /> }],
          },
          {
            // Important Request
            element: <RequirePermission permission={['important_request.access']} />,
            children: [
              { path: '/important-requests', element: <IrDashboardPage /> },
              { path: '/important-requests/saya', element: <IrListPage key="mine" scope="mine" /> },
              { path: '/important-requests/tugas', element: <IrListPage key="tasks" scope="tasks" /> },
              { path: '/important-requests/persetujuan', element: <IrListPage key="review" scope="review" /> },
              { path: '/important-requests/selesai', element: <IrListPage key="done" scope="done" /> },
              { path: '/important-requests/:id', element: <IrDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['important_request.create']} />,
            children: [
              { path: '/important-requests/baru', element: <IrFormPage key="new" /> },
              { path: '/important-requests/:id/ubah', element: <IrFormPage key="edit" /> },
            ],
          },
          {
            element: <RequirePermission permission={['important_request.analytics']} />,
            children: [{ path: '/analytics/important-requests', element: <IrAnalyticsPage /> }],
          },
          {
            // Master Data · Data Pekerja
            element: <RequirePermission permission={['master.employee.view']} />,
            children: [{ path: '/employees', element: <EmployeesPage /> }],
          },
          {
            element: <RequirePermission permission={['purchase_return.view']} />,
            children: [
              { path: '/purchase-returns', element: <PurchaseReturnListPage /> },
              { path: '/purchase-returns/:id', element: <PurchaseReturnDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['purchase_return.manage']} />,
            children: [
              { path: '/purchase-returns/new', element: <PurchaseReturnNewPage /> },
            ],
          },
          {
            element: <RequirePermission permission={APPROVAL_PERMISSIONS} />,
            children: [
              { path: '/approvals', element: <ApprovalPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['npbg.view']} />,
            children: [
              { path: '/npbg', element: <NpbgListPage /> },
              { path: '/npbg/:id', element: <NpbgDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['ppb.view']} />,
            children: [
              { path: '/ppb', element: <PpbListPage /> },
              { path: '/ppb/:id', element: <PpbDetailPage /> },
            ],
          },
          {
            // Draft PO: approved PPB items, vendor prices compared, picked into one PO per vendor
            element: <RequirePermission permission={['po.create']} />,
            children: [{ path: '/purchase-orders/draft', element: <PoDraftPage /> }],
          },
          {
            element: <RequirePermission permission="po.view" />,
            children: [
              { path: '/purchase-orders', element: <PoListPage /> },
              { path: '/purchase-orders/:id', element: <PoDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['ri.view']} />,
            children: [
              { path: '/ri', element: <RiListPage /> },
              { path: '/ri/:id', element: <RiDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['item.view', 'inventory.view']} />,
            children: [
              { path: '/stock-card', element: <StockCardProjectsPage /> },
              { path: '/stock-card/project/:projectId', element: <StockCardProjectPage /> },
              { path: '/stock-card/:id', element: <StockCardPage /> },
            ],
          },
          {
            element: <RequirePermission permission="opname.view" />,
            children: [
              { path: '/stock-opnames', element: <OpnameListPage /> },
              { path: '/stock-opnames/:id', element: <OpnameDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission="lend.view" />,
            children: [{ path: '/lend', element: <LendPage /> }],
          },
          {
            element: <RequirePermission permission="borrow.view" />,
            children: [{ path: '/borrow', element: <BorrowPage /> }],
          },
          {
            element: <RequirePermission permission="stpp.view" />,
            children: [{ path: '/stpp', element: <StppPage /> }],
          },
          {
            element: <RequirePermission permission="tyre.view" />,
            children: [{ path: '/tyre-changes', element: <TyrePage /> }],
          },
          {
            element: <RequirePermission permission="maintenance.view" />,
            children: [{ path: '/maintenance', element: <MaintenancePage /> }],
          },
          {
            element: <RequirePermission permission="manufacturing.view" />,
            children: [{ path: '/manufacturing', element: <ManufacturingPage /> }],
          },
          {
            element: <RequirePermission permission="used_return.view" />,
            children: [{ path: '/used-returns', element: <UsedReturnPage /> }],
          },
          {
            element: <RequirePermission permission="item.view" />,
            children: [{ path: '/items', element: <ItemsPage /> }],
          },
          {
            element: <RequirePermission permission={['inventory.view_analysis', 'item.safety_stock.view']} />,
            children: [
              { path: '/inventory/analysis', element: <InventoryHealthPage /> },
              // Safety Stock digabung ke halaman di atas (tab); link/bookmark lama tetap jalan
              { path: '/safety-stocks', element: <Navigate to="/inventory/analysis?tab=safety" replace /> },
            ],
          },
          {
            element: <RequirePermission permission="sync.accurate.view" />,
            children: [
              // Log Agen merged into this same page (as a tab); old links / bookmarks still land there
              { path: '/sync/accurate', element: <Navigate to="/sync/history" replace /> },
              { path: '/sync/agent-log', element: <Navigate to="/sync/history" replace /> },
              { path: '/sync/history', element: <SyncPage /> },
            ],
          },
          {
            element: (
              <RequirePermission
                permission={['report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname', 'report.stock_movement']}
              />
            ),
            children: [{ path: '/reports', element: <ReportsPage /> }],
          },
          {
            element: <RequirePermission permission="accurate.session.view" />,
            children: [{ path: '/accurate/online', element: <AccurateOnlinePage /> }],
          },
          {
            element: <RequirePermission permission="user.view" />,
            children: [{ path: '/settings/users', element: <UsersPage /> }],
          },
          {
            element: <RequirePermission permission="role.view" />,
            children: [{ path: '/settings/roles', element: <RolesPage /> }],
          },
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])

// remember where the user came from, for the sub pages' back control (components/BackLink.tsx)
recordLocation(router.state.location.pathname, router.state.location.search)
router.subscribe((state) => recordLocation(state.location.pathname, state.location.search))
