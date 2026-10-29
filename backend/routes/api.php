<?php

use App\Http\Controllers\Api\Agent\AccurateIngestController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryDashboardController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemRequestController;
use App\Http\Controllers\Api\LegacyExportController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NpbgController;
use App\Http\Controllers\Api\NpbgVerificationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PpbController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RiController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SafetyStockController;
use App\Http\Controllers\Api\StockOpnameController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\Tracking;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| PHASE 1 — health probe.
| PHASE 2 — auth (login/logout/me) + RBAC (roles/permissions) + route protection.
| Later phases add module routes (see docs/api-spec.md).
*/

Route::get('/ping', HealthController::class)->name('api.ping');

// Named 'login' route so the auth middleware has a redirect target for
// non-JSON guests; this API only ever answers 401.
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:20,1')
    ->name('api.login');

/*
| Kartu Stock through a share link (no account): the token is the access, limited
| to the link's project and its VIEW / EDIT / FULL right. Throttled per IP.
*/
/*
| Request Barang through a share link (no account): can only SEND a request.
| The admin turns each link on / off (ItemRequestLinkController).
*/
Route::get('public/item-request/{token}', [\App\Http\Controllers\Api\PublicItemRequestController::class, 'show'])
    ->middleware('throttle:60,1')->name('api.public.item-request.show');
Route::post('public/item-request/{token}', [\App\Http\Controllers\Api\PublicItemRequestController::class, 'store'])
    ->middleware('throttle:6,1')->name('api.public.item-request.store');
// Cek Permintaan: the requester follows their requests by track token (kept on their device)
// or finds one by number + name. Only the status and the items are shown.
Route::get('public/request-status', [\App\Http\Controllers\Api\PublicItemRequestController::class, 'status'])
    ->middleware('throttle:60,1')->name('api.public.request-status');
Route::get('public/request-status/image', [\App\Http\Controllers\Api\PublicItemRequestController::class, 'image'])
    ->middleware('throttle:120,1')->name('api.public.request-status.image');
Route::post('public/request-status/lookup', [\App\Http\Controllers\Api\PublicItemRequestController::class, 'lookup'])
    ->middleware('throttle:10,1')->name('api.public.request-status.lookup');

Route::prefix('public/kartu-stock/{token}')->middleware('throttle:240,1')->controller(\App\Http\Controllers\Api\PublicStockCardController::class)->group(function (): void {
    Route::get('/', 'project')->name('api.public.stock-card.project');
    Route::get('/pdf', 'projectPdf')->name('api.public.stock-card.project-pdf');
    Route::get('/items', 'items')->name('api.public.stock-card.items');
    Route::get('/next-number', 'nextNumber')->name('api.public.stock-card.next-number');
    Route::post('/cards', 'store')->name('api.public.stock-card.store');
    Route::get('/cards/{card}', 'show')->whereNumber('card')->name('api.public.stock-card.show');
    Route::put('/cards/{card}', 'update')->whereNumber('card')->name('api.public.stock-card.update');
    Route::delete('/cards/{card}', 'destroy')->whereNumber('card')->name('api.public.stock-card.destroy');
    Route::get('/cards/{card}/pdf', 'cardPdf')->whereNumber('card')->name('api.public.stock-card.pdf');
    Route::post('/cards/{card}/entries', 'storeEntry')->whereNumber('card')->name('api.public.stock-card.entries.store');
    Route::put('/cards/{card}/entries/{entry}', 'updateEntry')->whereNumber(['card', 'entry'])->name('api.public.stock-card.entries.update');
    Route::delete('/cards/{card}/entries/{entry}', 'destroyEntry')->whereNumber(['card', 'entry'])->name('api.public.stock-card.entries.destroy');
    Route::post('/files', 'upload')->name('api.public.stock-card.files.store');
    Route::get('/files/{photo}', 'file')->whereNumber('photo')->name('api.public.stock-card.files.show');
    Route::delete('/files/{photo}', 'deleteFile')->whereNumber('photo')->name('api.public.stock-card.files.destroy');
});

/*
|--------------------------------------------------------------------------
| Sync Agent ingest (docs/sync-architecture.md) — office Agent only, never a
| human user. Gated by the Sanctum `agent:sync` ability (see
| App\Console\Commands\Accurate\AgentTokenCommand), deliberately separate
| from the RBAC `permission:` middleware used by every route below.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'abilities:agent:sync', 'agent.token'])->prefix('agent/sync')->group(function (): void {
    Route::post('/sessions', [AccurateIngestController::class, 'startSession'])->name('api.agent.sync.start');
    Route::post('/sessions/{syncBatch}/tables/{table}', [AccurateIngestController::class, 'pushTable'])->name('api.agent.sync.push-table');
    Route::post('/sessions/{syncBatch}/complete', [AccurateIngestController::class, 'complete'])->name('api.agent.sync.complete');
    Route::get('/sessions/{syncBatch}', [AccurateIngestController::class, 'show'])->name('api.agent.sync.show');
    Route::post('/sessions/{syncBatch}/fail', [AccurateIngestController::class, 'fail'])->name('api.agent.sync.fail');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::get('/user', fn (Request $request) => $request->user())->name('api.user');

    // "Catatan" column on every data table (any signed-in user)
    Route::get('/record-notes', [\App\Http\Controllers\Api\RecordNoteController::class, 'index'])->name('api.record-notes.index');
    Route::put('/record-notes', [\App\Http\Controllers\Api\RecordNoteController::class, 'upsert'])->name('api.record-notes.upsert');

    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:role.view')->name('api.roles.index');
    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:permission.view|role.manage')->name('api.permissions.index');

    // Self-service password change (any logged-in user).
    Route::post('/me/password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:10,1')->name('api.me.password');

    // Role management (Pengaturan -> Role & Hak Akses).
    Route::middleware('permission:role.manage')->group(function () {
        Route::post('/roles', [RoleController::class, 'store'])->name('api.roles.store');
        Route::match(['put', 'patch'], '/roles/{role}', [RoleController::class, 'update'])->name('api.roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('api.roles.destroy');
    });

    // User management (Pengaturan -> Pengguna).
    Route::get('/users/options', [UserController::class, 'options'])
        ->middleware('permission:user.view|user.create|user.update')->name('api.users.options');
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view')->name('api.users.index');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create')->name('api.users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view')->name('api.users.show');
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:user.update')->name('api.users.update');
    Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])
        ->middleware(['permission:user.update', 'throttle:20,1'])->name('api.users.password');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:user.delete')->name('api.users.destroy');

    // Accurate Online: PCs connected to Accurate + manual disconnect. Standalone —
    // not tied to the Sync Agent or the sync (see AccurateSessionMonitor).
    Route::get('/accurate/sessions', [\App\Http\Controllers\Api\AccurateSessionController::class, 'index'])
        ->middleware('permission:accurate.session.view')->name('api.accurate.sessions');
    Route::post('/accurate/sessions/terminate', [\App\Http\Controllers\Api\AccurateSessionController::class, 'terminate'])
        ->middleware(['permission:accurate.session.terminate', 'throttle:30,1'])->name('api.accurate.sessions.terminate');
    Route::put('/accurate/ip-labels', [\App\Http\Controllers\Api\AccurateSessionController::class, 'saveLabel'])
        ->middleware(['permission:accurate.session.terminate', 'throttle:60,1'])->name('api.accurate.ip-labels.save');

    // Manual Sync Agent run (copy Accurate .GDB -> staging [-> sync]) — Log Agen page.
    Route::get('/agent-runs/status', [\App\Http\Controllers\Api\AgentRunController::class, 'status'])
        ->middleware('permission:sync.accurate.view')->name('api.agent-runs.status');
    Route::post('/agent-runs', [\App\Http\Controllers\Api\AgentRunController::class, 'store'])
        ->middleware(['permission:sync.accurate.trigger', 'throttle:10,1'])->name('api.agent-runs.store');

    Route::middleware('permission:notification.view_own')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('api.notifications.unread-count');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('api.notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('api.notifications.read-all');
    });

    /*
    |--------------------------------------------------------------------------
    | PHASE 3 — Master Data + Inventory + Calculation Engine
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:master.category.view')->group(function () {
        Route::get('/categories', [MasterDataController::class, 'categories'])->name('api.categories');
        Route::get('/categories/tree', [MasterDataController::class, 'categoryTree'])->name('api.categories.tree');
    });
    Route::get('/units', [MasterDataController::class, 'units'])
        ->middleware('permission:master.unit.view|item_request.create|item.view')->name('api.units');
    Route::get('/warehouses', [MasterDataController::class, 'warehouses'])
        ->middleware('permission:master.warehouse.view')->name('api.warehouses');
    Route::get('/sites', [MasterDataController::class, 'sites'])
        ->middleware('permission:master.warehouse.view|po.create')->name('api.sites');
    Route::get('/warehouse-locations', [MasterDataController::class, 'warehouseLocations'])
        ->middleware('permission:master.location.view')->name('api.warehouse-locations');

    Route::get('/items/lookup', [ItemController::class, 'lookup'])
        ->middleware('permission:item.lookup|item.view')->name('api.items.lookup');
    Route::middleware('permission:item.view')->group(function () {
        Route::get('/items', [ItemController::class, 'index'])->name('api.items.index');
        Route::get('/items/accurate-categories', [ItemController::class, 'accurateCategoryOptions'])->name('api.items.accurate-categories');
        Route::get('/items/options', [ItemController::class, 'options'])->name('api.items.options');
        Route::get('/items/ids', [ItemController::class, 'ids'])->name('api.items.ids');
        Route::get('/items/{item}', [ItemController::class, 'show'])->name('api.items.show');
        Route::get('/items/{item}/history', [ItemController::class, 'history'])->name('api.items.history');
    });
    Route::post('/items', [ItemController::class, 'store'])
        ->middleware('permission:item.create')->name('api.items.store');
    Route::match(['put', 'patch'], '/items/{item}', [ItemController::class, 'update'])
        ->middleware('permission:item.update')->name('api.items.update');
    Route::delete('/items/{item}', [ItemController::class, 'destroy'])
        ->middleware('permission:item.delete')->name('api.items.destroy');

    Route::middleware('permission:item.safety_stock.view')->group(function () {
        Route::get('/safety-stocks', [SafetyStockController::class, 'index'])->name('api.safety-stocks.index');
        Route::get('/safety-stocks/{safetyStock}', [SafetyStockController::class, 'show'])->name('api.safety-stocks.show');
    });
    Route::middleware('permission:item.safety_stock.update')->group(function () {
        Route::post('/safety-stocks', [SafetyStockController::class, 'store'])->name('api.safety-stocks.store');
        Route::match(['put', 'patch'], '/safety-stocks/{safetyStock}', [SafetyStockController::class, 'update'])
            ->name('api.safety-stocks.update');
        Route::delete('/safety-stocks/{safetyStock}', [SafetyStockController::class, 'destroy'])
            ->name('api.safety-stocks.destroy');
    });
    Route::post('/safety-stocks/{safetyStock}/resolve-conflict', [SafetyStockController::class, 'resolveConflict'])
        ->middleware('permission:item.safety_stock.resolve_conflict')->name('api.safety-stocks.resolve-conflict');

    Route::middleware('permission:sync.accurate.view')->group(function () {
        Route::get('/sync/status', [SyncController::class, 'status'])->name('api.sync.status');
        Route::get('/sync/history', [SyncController::class, 'history'])->name('api.sync.history');
        Route::get('/sync/history/{syncBatch}', [SyncController::class, 'show'])->name('api.sync.history.show');
        Route::get('/sync/agent-log', [\App\Http\Controllers\Api\AgentLogController::class, 'index'])->name('api.sync.agent-log');
    });
    Route::post('/sync/accurate', [SyncController::class, 'store'])
        ->middleware('permission:sync.accurate.trigger')->name('api.sync.accurate.store');

    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('api.inventory.index');
        Route::get('/inventory/analysis', [InventoryController::class, 'analysis'])
            ->middleware('permission:inventory.view_analysis')->name('api.inventory.analysis');
        Route::get('/inventory/{item}', [InventoryController::class, 'show'])->name('api.inventory.show');
    });
    Route::post('/inventory/projected', [InventoryController::class, 'projected'])
        ->middleware('permission:item_request.create|inventory.view')->name('api.inventory.projected');

    /*
    | Request Barang — karyawan request, admin gudang turns lines into NPBG / PPB.
    | Own requests with item_request.create; all of them with item_request.view.
    */
    Route::get('/item-requests', [ItemRequestController::class, 'index'])
        ->middleware('permission:item_request.create|item_request.view')->name('api.item-requests.index');
    Route::post('/item-requests', [ItemRequestController::class, 'store'])
        ->middleware('permission:item_request.create')->name('api.item-requests.store');
    Route::get('/item-request-forms', [ItemRequestController::class, 'forms'])
        ->middleware('permission:item_request.create|item_request.view')->name('api.item-request-forms.index');
    Route::get('/item-requests/{itemRequest}', [ItemRequestController::class, 'show'])
        ->middleware('permission:item_request.create|item_request.view|item_request.approve_manager|item_request.approve_director|item_request.handover')->name('api.item-requests.show');
    Route::post('/item-requests/{itemRequest}/process', [ItemRequestController::class, 'process'])
        ->middleware('permission:item_request.process')->name('api.item-requests.process');
    Route::put('/item-requests/{itemRequest}/lines/{line}/match', [ItemRequestController::class, 'matchLine'])
        ->middleware(['permission:item_request.process', 'throttle:120,1'])->name('api.item-requests.match-line');
    Route::put('/item-requests/{itemRequest}/urgency', [ItemRequestController::class, 'urgency'])
        ->middleware('permission:item_request.process')->name('api.item-requests.urgency');
    // Approval: Manager → Direktur → Lapangan Gudang → serah terima (ItemRequestApprovalService)
    Route::get('/approvals', [ItemRequestController::class, 'approvals'])
        ->middleware('permission:item_request.approve_manager|item_request.approve_director|item_request.handover|ppb.approve_manager|ppb.approve_director|po.approve_director|npbg.approve_manager|npbg.approve_director')->name('api.approvals.index');
    Route::get('/approvals/count', [ItemRequestController::class, 'approvalCount'])
        ->middleware('permission:item_request.approve_manager|item_request.approve_director|item_request.handover|ppb.approve_manager|ppb.approve_director|po.approve_director|npbg.approve_manager|npbg.approve_director')->name('api.approvals.count');
    // STOCKWISE PPB (Manager → Direktur) and PO (Direktur) waiting for this user
    Route::get('/approvals/purchasing', [\App\Http\Controllers\Api\PurchasingApprovalController::class, 'index'])
        ->middleware('permission:ppb.approve_manager|ppb.approve_director|po.approve_director|npbg.approve_manager|npbg.approve_director')->name('api.approvals.purchasing');
    Route::post('/item-requests/{itemRequest}/approval', [ItemRequestController::class, 'decide'])
        ->middleware(['permission:item_request.approve_manager|item_request.approve_director|item_request.handover', 'throttle:60,1'])->name('api.item-requests.approval');
    Route::post('/item-requests/{itemRequest}/handover', [ItemRequestController::class, 'handover'])
        ->middleware(['permission:item_request.handover', 'throttle:30,1'])->name('api.item-requests.handover');
    // Gudang fulfils an approved request from real stock: NPBG #1, #2 …, each with its own serah terima
    Route::post('/item-requests/{itemRequest}/fulfillments', [ItemRequestController::class, 'fulfill'])
        ->middleware(['permission:item_request.handover', 'throttle:60,1'])->name('api.item-requests.fulfill');
    Route::post('/item-requests/{itemRequest}/fulfillments/{fulfillment}/confirm', [ItemRequestController::class, 'confirmFulfillment'])
        ->middleware(['permission:item_request.handover', 'throttle:60,1'])->name('api.item-requests.fulfillment-confirm');
    Route::post('/item-requests/{itemRequest}/fulfillments/{fulfillment}/handover', [ItemRequestController::class, 'handoverFulfillment'])
        ->middleware(['permission:item_request.handover', 'throttle:30,1'])->name('api.item-requests.fulfillment-handover');
    Route::get('/item-requests/{itemRequest}/fulfillments/{fulfillment}/file/{slot}', [ItemRequestController::class, 'fulfillmentFile'])
        ->whereIn('slot', ['handover', 'signature'])
        ->middleware('permission:item_request.create|item_request.view|item_request.approve_manager|item_request.approve_director|item_request.handover')->name('api.item-requests.fulfillment-file');
    Route::post('/item-requests/{itemRequest}/close', [ItemRequestController::class, 'close'])
        ->middleware(['permission:item_request.handover|item_request.process', 'throttle:30,1'])->name('api.item-requests.close');
    Route::get('/item-requests/{itemRequest}/lines/{line}/image/{slot}', [ItemRequestController::class, 'lineImage'])->whereIn('slot', ['img1', 'img2', 'img3'])
        ->middleware('permission:item_request.create|item_request.view|item_request.approve_manager|item_request.approve_director|item_request.handover')->name('api.item-requests.line-image');
    Route::get('/item-requests/{itemRequest}/image/{slot}', [ItemRequestController::class, 'image'])->whereIn('slot', ['img1', 'img2', 'img3'])
        ->middleware('permission:item_request.create|item_request.view|item_request.approve_manager|item_request.approve_director|item_request.handover')->name('api.item-requests.image');
    Route::get('/item-requests/{itemRequest}/handover/{slot}', [ItemRequestController::class, 'handoverFile'])
        ->whereIn('slot', ['handover', 'signature'])
        ->middleware('permission:item_request.create|item_request.view|item_request.approve_manager|item_request.approve_director|item_request.handover')->name('api.item-requests.handover-file');
    Route::post('/item-requests/{itemRequest}/cancel', [ItemRequestController::class, 'cancel'])
        ->middleware('permission:item_request.create|item_request.process')->name('api.item-requests.cancel');
    Route::get('/share-base', [\App\Http\Controllers\Api\ItemRequestLinkController::class, 'shareBase'])->name('api.share-base');
    Route::middleware('permission:item_request.process')->group(function () {
        Route::get('/item-request-links', [\App\Http\Controllers\Api\ItemRequestLinkController::class, 'index'])->name('api.item-request-links.index');
        Route::post('/item-request-links', [\App\Http\Controllers\Api\ItemRequestLinkController::class, 'store'])->name('api.item-request-links.store');
        Route::put('/item-request-links/{link}', [\App\Http\Controllers\Api\ItemRequestLinkController::class, 'update'])->name('api.item-request-links.update');
        Route::delete('/item-request-links/{link}', [\App\Http\Controllers\Api\ItemRequestLinkController::class, 'destroy'])->name('api.item-request-links.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | NPBG — Accurate ARINV/ARINVDET mirror (read-mostly; only a few
    | Stockwise-owned fields are editable). Populated by "Sync Accurate"
    | (see the sync.accurate routes above), never created manually.
    |--------------------------------------------------------------------------
    */
    Route::get('/npbg', [NpbgController::class, 'index'])
        ->middleware('permission:npbg.view')->name('api.npbg.index');
    Route::post('/npbg', [NpbgController::class, 'store'])
        ->middleware('permission:npbg.create')->name('api.npbg.store');
    Route::get('/npbg/klasifikasi', [NpbgController::class, 'klasifikasiOptions'])
        ->middleware('permission:npbg.view')->name('api.npbg.klasifikasi');
    // old name, for pages still open on the previous version
    Route::get('/npbg/jenis-pengeluaran', [NpbgController::class, 'klasifikasiOptions'])
        ->middleware('permission:npbg.view')->name('api.npbg.jenis-pengeluaran');
    Route::post('/npbg/{npbg}/approval', [NpbgController::class, 'decide'])
        ->middleware(['permission:npbg.approve_manager|npbg.approve_director', 'throttle:60,1'])->name('api.npbg.approval');
    // sent back by the Manager / Direktur: admin revises it and sends it again
    Route::post('/npbg/{npbg}/resubmit', [NpbgController::class, 'resubmit'])
        ->middleware(['permission:npbg.create', 'throttle:30,1'])->name('api.npbg.resubmit');
    Route::get('/npbg/{npbg}', [NpbgController::class, 'show'])
        ->middleware('permission:npbg.view')->name('api.npbg.show');
    Route::get('/npbg/{npbg}/print', [NpbgController::class, 'print'])
        ->middleware('permission:npbg.view')->name('api.npbg.print');
    Route::put('/npbg/{npbg}/kembali-bekas', [NpbgController::class, 'kembaliBekas'])
        ->middleware(['permission:npbg.view', 'permission:used_return.update'])->name('api.npbg.kembali-bekas');
    Route::get('/npbg/{npbg}/print-pdf', [NpbgController::class, 'printPdf'])
        ->middleware('permission:npbg.view')->name('api.npbg.print-pdf');
    Route::match(['put', 'patch'], '/npbg/{npbg}', [NpbgController::class, 'update'])
        ->middleware('permission:npbg.update')->name('api.npbg.update');

    /*
    |--------------------------------------------------------------------------
    | NPBG — Klarifikasi/Verifikasi Barang (barang alternatif beda type/spek).
    |--------------------------------------------------------------------------
    */
    Route::get('/npbg/{npbg}/verifications', [NpbgVerificationController::class, 'index'])
        ->middleware('permission:npbg.verification.view')->name('api.npbg.verifications.index');
    Route::post('/npbg/{npbg}/verifications', [NpbgVerificationController::class, 'store'])
        ->middleware('permission:npbg.verification.manage')->name('api.npbg.verifications.store');
    Route::get('/npbg-verifications/{verification}', [NpbgVerificationController::class, 'show'])
        ->middleware('permission:npbg.verification.view')->name('api.npbg.verifications.show');
    Route::post('/npbg-verifications/{verification}/process', [NpbgVerificationController::class, 'process'])
        ->middleware('permission:npbg.verification.manage')->name('api.npbg.verifications.process');
    Route::post('/npbg-verifications/{verification}/offer-alternative', [NpbgVerificationController::class, 'offerAlternative'])
        ->middleware('permission:npbg.verification.manage')->name('api.npbg.verifications.offer-alternative');
    Route::post('/npbg-verifications/{verification}/respond', [NpbgVerificationController::class, 'respond'])
        ->middleware('permission:npbg.verification.respond')->name('api.npbg.verifications.respond');
    Route::post('/npbg-verifications/{verification}/escalate', [NpbgVerificationController::class, 'escalate'])
        ->middleware('permission:npbg.verification.manage')->name('api.npbg.verifications.escalate');
    Route::post('/npbg-verifications/{verification}/bos-decide', [NpbgVerificationController::class, 'bosDecide'])
        ->middleware('permission:npbg.verification.bos_decide')->name('api.npbg.verifications.bos-decide');
    Route::get('/npbg-verifications/logs/{log}/attachment', [NpbgVerificationController::class, 'attachment'])
        ->middleware('permission:npbg.verification.view')->name('api.npbg.verifications.attachment');

    /*
    |--------------------------------------------------------------------------
    | PHASE 6 — Stock Opname
    |--------------------------------------------------------------------------
    */
    Route::get('/stock-opnames', [StockOpnameController::class, 'index'])
        ->middleware('permission:opname.view')->name('api.opnames.index');
    Route::get('/stock-opnames/lines', [StockOpnameController::class, 'lines'])
        ->middleware('permission:opname.view')->name('api.opnames.lines');
    Route::get('/stock-opnames/{stockOpname}', [StockOpnameController::class, 'show'])
        ->middleware('permission:opname.view')->name('api.opnames.show');
    Route::get('/stock-opnames/{stockOpname}/usage', [StockOpnameController::class, 'usage'])
        ->middleware('permission:opname.view')->name('api.opnames.usage');
    Route::get('/stock-opnames/{stockOpname}/print', [StockOpnameController::class, 'print'])
        ->middleware('permission:opname.view')->name('api.opnames.print');
    Route::post('/stock-opnames', [StockOpnameController::class, 'store'])
        ->middleware('permission:opname.schedule')->name('api.opnames.store');
    Route::post('/stock-opnames/{stockOpname}/start', [StockOpnameController::class, 'start'])
        ->middleware('permission:opname.count')->name('api.opnames.start');
    Route::put('/stock-opnames/{stockOpname}/items/{item}', [StockOpnameController::class, 'count'])
        ->middleware('permission:opname.count')->name('api.opnames.count');
    Route::post('/stock-opnames/{stockOpname}/submit', [StockOpnameController::class, 'submit'])
        ->middleware('permission:opname.submit')->name('api.opnames.submit');
    Route::post('/stock-opnames/{stockOpname}/review', [StockOpnameController::class, 'review'])
        ->middleware('permission:opname.approve')->name('api.opnames.review');

    /*
    |--------------------------------------------------------------------------
    | PHASE 7 — PPB + Purchasing (PPB/PO/RI mirrors dari Accurate)
    |--------------------------------------------------------------------------
    */
    // Important Request: work instructions from a superior, discussion, results and their review
    Route::middleware('permission:important_request.access')->prefix('important-requests')->controller(\App\Http\Controllers\Api\ImportantRequestController::class)->group(function (): void {
        Route::get('/', 'index')->name('api.important-requests.index');
        Route::get('/summary', 'summary')->name('api.important-requests.summary');
        Route::get('/recipients', 'recipients')->middleware('permission:important_request.create')->name('api.important-requests.recipients');
        Route::get('/analytics', 'analytics')->middleware('permission:important_request.analytics')->name('api.important-requests.analytics');
        Route::post('/', 'store')->middleware(['permission:important_request.create', 'throttle:30,1'])->name('api.important-requests.store');
        Route::get('/{importantRequest}', 'show')->whereNumber('importantRequest')->name('api.important-requests.show');
        Route::put('/{importantRequest}', 'update')->whereNumber('importantRequest')->middleware('permission:important_request.create')->name('api.important-requests.update');
        Route::delete('/{importantRequest}', 'destroy')->whereNumber('importantRequest')->middleware('permission:important_request.create')->name('api.important-requests.destroy');
        Route::post('/{importantRequest}/send', 'send')->whereNumber('importantRequest')->middleware('permission:important_request.create')->name('api.important-requests.send');
        Route::post('/{importantRequest}/act', 'act')->whereNumber('importantRequest')->name('api.important-requests.act');
        Route::post('/{importantRequest}/submissions/{submission}/review', 'review')->whereNumber(['importantRequest', 'submission'])->middleware('permission:important_request.create')->name('api.important-requests.review');
        Route::post('/{importantRequest}/deadline', 'deadline')->whereNumber('importantRequest')->middleware('permission:important_request.create')->name('api.important-requests.deadline');
        Route::post('/{importantRequest}/cancel', 'cancel')->whereNumber('importantRequest')->middleware('permission:important_request.create')->name('api.important-requests.cancel');
        Route::post('/{importantRequest}/reopen', 'reopen')->whereNumber('importantRequest')->name('api.important-requests.reopen');
        Route::get('/{importantRequest}/comments', 'comments')->whereNumber('importantRequest')->name('api.important-requests.comments');
        Route::post('/{importantRequest}/comments', 'addComment')->whereNumber('importantRequest')->middleware('throttle:60,1')->name('api.important-requests.comments.store');
        Route::put('/{importantRequest}/comments/{comment}', 'editComment')->whereNumber(['importantRequest', 'comment'])->name('api.important-requests.comments.update');
        Route::delete('/{importantRequest}/comments/{comment}', 'deleteComment')->whereNumber(['importantRequest', 'comment'])->name('api.important-requests.comments.destroy');
        Route::get('/{importantRequest}/activities', 'activities')->whereNumber('importantRequest')->name('api.important-requests.activities');
        Route::post('/{importantRequest}/attachments', 'addAttachments')->whereNumber('importantRequest')->middleware('permission:important_request.create')->name('api.important-requests.attachments.store');
        Route::delete('/{importantRequest}/attachments/{attachment}', 'deleteAttachment')->whereNumber(['importantRequest', 'attachment'])->name('api.important-requests.attachments.destroy');
        Route::get('/{importantRequest}/attachments/{attachment}', 'download')->whereNumber(['importantRequest', 'attachment'])->name('api.important-requests.attachments.show');
    });
    // files go up in chunks (PHP's upload limit here is 2 MB a file)
    Route::middleware(['permission:important_request.access', 'throttle:600,1'])->controller(\App\Http\Controllers\Api\ImportantRequestController::class)->group(function (): void {
        Route::post('/important-request-uploads', 'uploadStart')->name('api.important-request-uploads.store');
        Route::post('/important-request-uploads/{token}', 'uploadChunk')->name('api.important-request-uploads.chunk');
    });
    // Master Data · Data Pekerja (roster of the company's people)
    Route::get('/employees', [\App\Http\Controllers\Api\EmployeeController::class, 'index'])
        ->middleware('permission:master.employee.view')->name('api.employees.index');
    Route::get('/employees/departments', [\App\Http\Controllers\Api\EmployeeController::class, 'departments'])
        ->middleware('permission:master.employee.view')->name('api.employees.departments');
    Route::get('/employees/users', [\App\Http\Controllers\Api\EmployeeController::class, 'users'])
        ->middleware('permission:master.employee.manage')->name('api.employees.users');
    Route::get('/employees/template', [\App\Http\Controllers\Api\EmployeeController::class, 'template'])
        ->middleware('permission:master.employee.manage')->name('api.employees.template');
    Route::post('/employees/import', [\App\Http\Controllers\Api\EmployeeController::class, 'import'])
        ->middleware('permission:master.employee.manage')->name('api.employees.import');
    Route::post('/employees', [\App\Http\Controllers\Api\EmployeeController::class, 'store'])
        ->middleware('permission:master.employee.manage')->name('api.employees.store');
    Route::put('/employees/{employee}', [\App\Http\Controllers\Api\EmployeeController::class, 'update'])
        ->middleware('permission:master.employee.manage')->name('api.employees.update');
    Route::get('/vendors', [VendorController::class, 'index'])
        ->middleware('permission:master.vendor.view')->name('api.vendors.index');
    Route::post('/vendors', [VendorController::class, 'store'])
        ->middleware('permission:master.vendor.manage')->name('api.vendors.store');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])
        ->middleware('permission:master.vendor.manage')->name('api.vendors.update');

    // divisi-options/vendor-options sebelum {ppb}/{ri}/{purchaseOrder} — rute statis
    // harus didaftarkan lebih dulu, kalau tidak "divisi-options" akan dicoba
    // resolve sebagai id model oleh rute wildcard di bawahnya.
    Route::get('/ppb/divisi-options', [PpbController::class, 'divisiOptions'])
        ->middleware('permission:ppb.view')->name('api.ppb.divisi-options');
    Route::get('/ppb/vendor-options', [PpbController::class, 'vendorOptions'])
        ->middleware('permission:ppb.view')->name('api.ppb.vendor-options');
    Route::get('/ppb', [PpbController::class, 'index'])
        ->middleware('permission:ppb.view')->name('api.ppb.index');
    Route::post('/ppb', [PpbController::class, 'store'])
        ->middleware('permission:ppb.create')->name('api.ppb.store');
    // Draft PPB: Restock (Safety Stock) → review / edit → submit as PPB (PpbDraftService)
    Route::middleware('permission:ppb.create')->group(function () {
        // the Draft PPB list: item lines from Request Barang / Safety Stock, picked into one PPB
        Route::get('/ppb-draft-lines', [\App\Http\Controllers\Api\PpbDraftController::class, 'lines'])->name('api.ppb-draft-lines.index');
        Route::post('/ppb-draft-lines/submit', [\App\Http\Controllers\Api\PpbDraftController::class, 'submitLines'])->name('api.ppb-draft-lines.submit');
        Route::put('/ppb-draft-lines/{line}', [\App\Http\Controllers\Api\PpbDraftController::class, 'updateLine'])->name('api.ppb-draft-lines.update');
        Route::delete('/ppb-draft-lines/{line}', [\App\Http\Controllers\Api\PpbDraftController::class, 'removeLine'])->name('api.ppb-draft-lines.destroy');
        Route::get('/ppb-drafts', [\App\Http\Controllers\Api\PpbDraftController::class, 'index'])->name('api.ppb-drafts.index');
        Route::post('/ppb-drafts', [\App\Http\Controllers\Api\PpbDraftController::class, 'store'])->name('api.ppb-drafts.store');
        Route::get('/ppb-drafts/{ppbDraft}', [\App\Http\Controllers\Api\PpbDraftController::class, 'show'])->name('api.ppb-drafts.show');
        Route::put('/ppb-drafts/{ppbDraft}', [\App\Http\Controllers\Api\PpbDraftController::class, 'update'])->name('api.ppb-drafts.update');
        Route::post('/ppb-drafts/{ppbDraft}/submit', [\App\Http\Controllers\Api\PpbDraftController::class, 'submit'])->name('api.ppb-drafts.submit');
        Route::delete('/ppb-drafts/{ppbDraft}', [\App\Http\Controllers\Api\PpbDraftController::class, 'destroy'])->name('api.ppb-drafts.destroy');
    });
    Route::get('/ppb/{ppb}', [PpbController::class, 'show'])
        ->middleware('permission:ppb.view')->name('api.ppb.show');
    Route::post('/ppb/{ppb}/approval', [PpbController::class, 'decide'])
        ->middleware(['permission:ppb.approve|ppb.approve_manager|ppb.approve_director', 'throttle:60,1'])->name('api.ppb.approval');
    // sent back by the Manager / Direktur: admin revises it and sends it again
    Route::post('/ppb/{ppb}/resubmit', [PpbController::class, 'resubmit'])
        ->middleware(['permission:ppb.create', 'throttle:30,1'])->name('api.ppb.resubmit');
    Route::get('/ppb/{ppb}/print', [PpbController::class, 'print'])
        ->middleware('permission:ppb.view')->name('api.ppb.print');
    Route::get('/ppb/{ppb}/print-pdf', [PpbController::class, 'printPdf'])
        ->middleware('permission:ppb.view')->name('api.ppb.print-pdf');

    // RI (mirror Accurate APINV/APITMDET) — terpisah dari /api/receivings (alur internal DRAFT->CHECKING->CONFIRMED).
    Route::get('/ri/vendor-options', [RiController::class, 'vendorOptions'])
        ->middleware('permission:ri.view')->name('api.ri.vendor-options');
    Route::get('/ri/po-lines', [RiController::class, 'poLines'])
        ->middleware('permission:ri.create')->name('api.ri.po-lines');
    Route::get('/ri/po-orders', [RiController::class, 'poOrders'])
        ->middleware('permission:ri.create')->name('api.ri.po-orders');
    Route::post('/ri/from-po', [RiController::class, 'storeFromPo'])
        ->middleware('permission:ri.create')->name('api.ri.store-from-po');
    Route::get('/ri/used-return-lines', [RiController::class, 'usedReturnLines'])
        ->middleware('permission:ri.create')->name('api.ri.used-return-lines');
    Route::get('/ri', [RiController::class, 'index'])
        ->middleware('permission:ri.view')->name('api.ri.index');
    Route::post('/ri', [RiController::class, 'store'])
        ->middleware('permission:ri.create')->name('api.ri.store');
    Route::get('/ri/{ri}', [RiController::class, 'show'])
        ->middleware('permission:ri.view')->name('api.ri.show');
    Route::get('/ri/{ri}/photo/{slot}', [RiController::class, 'photo'])->whereIn('slot', ['foto1', 'foto2', 'foto3', 'doc1', 'doc2', 'doc3'])
        ->middleware('permission:ri.view')->name('api.ri.photo');
    Route::get('/ri/{ri}/print', [RiController::class, 'print'])
        ->middleware('permission:ri.view')->name('api.ri.print');
    Route::get('/ri/{ri}/print-pdf', [RiController::class, 'printPdf'])
        ->middleware('permission:ri.view')->name('api.ri.print-pdf');

    // Retur Pembelian: goods of an RI back to the vendor (PurchaseReturnService)
    Route::middleware('permission:purchase_return.view')->group(function () {
        Route::get('/purchase-returns', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'index'])->name('api.purchase-returns.index');
        Route::get('/purchase-returns/{purchaseReturn}', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'show'])->whereNumber('purchaseReturn')->name('api.purchase-returns.show');
        Route::get('/purchase-returns/{purchaseReturn}/photo/{slot}', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'photo'])
            ->whereNumber('purchaseReturn')->whereIn('slot', ['barang', 'kirim'])->name('api.purchase-returns.photo');
    });
    Route::middleware('permission:purchase_return.manage')->group(function () {
        Route::get('/purchase-returns/ri-search', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'riSearch'])->name('api.purchase-returns.ri-search');
        Route::get('/purchase-returns/ri-lines', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'riLines'])->name('api.purchase-returns.ri-lines');
        Route::post('/purchase-returns', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'store'])->middleware('throttle:30,1')->name('api.purchase-returns.store');
        Route::post('/purchase-returns/{purchaseReturn}/send', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'send'])->middleware('throttle:30,1')->name('api.purchase-returns.send');
        Route::post('/purchase-returns/{purchaseReturn}/resolve', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'resolve'])->name('api.purchase-returns.resolve');
        Route::post('/purchase-returns/{purchaseReturn}/cancel', [\App\Http\Controllers\Api\PurchaseReturnController::class, 'cancel'])->name('api.purchase-returns.cancel');
    });

    Route::get('/purchase-orders/divisi-options', [PurchaseOrderController::class, 'divisiOptions'])
        ->middleware('permission:po.view')->name('api.po.divisi-options');
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->middleware('permission:po.view')->name('api.po.index');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('permission:po.create')->name('api.po.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->middleware('permission:po.view')->name('api.po.show');
    Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])
        ->middleware('permission:po.view')->name('api.po.print');
    Route::get('/purchase-orders/{purchaseOrder}/print-pdf', [PurchaseOrderController::class, 'printPdf'])
        ->middleware('permission:po.view')->name('api.po.print-pdf');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
        ->middleware('permission:po.approve|po.approve_director')->name('api.po.approve');
    // STOCKWISE PO: purchasing completes it (vendor / price), the Direktur approves or sends it back
    Route::put('/purchase-orders/{purchaseOrder}/complete', [PurchaseOrderController::class, 'complete'])
        ->middleware('permission:po.create|po.update')->name('api.po.complete');
    Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])
        ->middleware('permission:po.approve_director')->name('api.po.reject');
    // PO STOCKWISE approved → proof of purchase uploaded → "Sudah dibeli"
    Route::post('/purchase-orders/{purchaseOrder}/proof', [PurchaseOrderController::class, 'uploadProof'])
        ->middleware(['permission:po.send|po.create', 'throttle:30,1'])->name('api.po.proof.upload');
    Route::get('/purchase-orders/{purchaseOrder}/proof', [PurchaseOrderController::class, 'proof'])
        ->middleware('permission:po.view')->name('api.po.proof');
    Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])
        ->middleware('permission:po.send')->name('api.po.send');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('permission:po.cancel')->name('api.po.cancel');

    // PHASE 8 — Tracking masters + modules.
    Route::get('/customers', [MasterDataController::class, 'customers'])
        ->middleware('permission:master.customer.view')->name('api.customers');
    Route::get('/projects', [MasterDataController::class, 'projects'])
        ->middleware('permission:master.project.view')->name('api.projects');
    Route::get('/workshops', [MasterDataController::class, 'workshops'])
        ->middleware('permission:master.workshop.view')->name('api.workshops');
    Route::get('/assets', [MasterDataController::class, 'assets'])
        ->middleware('permission:maintenance.view|tyre.view|item.view')->name('api.assets');
    Route::get('/serial-units', [MasterDataController::class, 'serialUnits'])
        ->middleware('permission:stpp.view|tyre.view|manufacturing.view')->name('api.serial-units');

    Route::get('/lend', [Tracking\LendController::class, 'index'])->middleware('permission:lend.view')->name('api.lend.index');
    Route::get('/lend/{lend}', [Tracking\LendController::class, 'show'])->middleware('permission:lend.view')->name('api.lend.show');
    Route::post('/lend', [Tracking\LendController::class, 'store'])->middleware('permission:lend.create')->name('api.lend.store');
    Route::match(['put', 'patch'], '/lend/{lend}', [Tracking\LendController::class, 'update'])->middleware('permission:lend.update')->name('api.lend.update');
    Route::delete('/lend/{lend}', [Tracking\LendController::class, 'destroy'])->middleware('permission:lend.update')->name('api.lend.destroy');
    Route::post('/lend/{lend}/return', [Tracking\LendController::class, 'return'])->middleware('permission:lend.return')->name('api.lend.return');

    Route::get('/borrow', [Tracking\BorrowController::class, 'index'])->middleware('permission:borrow.view')->name('api.borrow.index');
    Route::get('/borrow/{borrow}', [Tracking\BorrowController::class, 'show'])->middleware('permission:borrow.view')->name('api.borrow.show');
    Route::post('/borrow', [Tracking\BorrowController::class, 'store'])->middleware('permission:borrow.create')->name('api.borrow.store');
    Route::match(['put', 'patch'], '/borrow/{borrow}', [Tracking\BorrowController::class, 'update'])->middleware('permission:borrow.update')->name('api.borrow.update');
    Route::delete('/borrow/{borrow}', [Tracking\BorrowController::class, 'destroy'])->middleware('permission:borrow.update')->name('api.borrow.destroy');
    Route::post('/borrow/{borrow}/return', [Tracking\BorrowController::class, 'return'])->middleware('permission:borrow.return')->name('api.borrow.return');

    Route::get('/stpp/options', [Tracking\StppController::class, 'options'])->middleware('permission:stpp.view')->name('api.stpp.options');
    // Excel sheet "Maintenance" (Maintenance STPP)
    Route::get('/stpp-maintenance', [Tracking\StppController::class, 'maintenanceIndex'])->middleware('permission:stpp.view')->name('api.stpp.maintenance.index');
    Route::post('/stpp-maintenance', [Tracking\StppController::class, 'maintenanceStore'])->middleware('permission:stpp.update')->name('api.stpp.maintenance.store');
    Route::put('/stpp-maintenance/{log}', [Tracking\StppController::class, 'maintenanceUpdate'])->middleware('permission:stpp.update')->name('api.stpp.maintenance.update');
    Route::delete('/stpp-maintenance/{log}', [Tracking\StppController::class, 'maintenanceDestroy'])->middleware('permission:stpp.update')->name('api.stpp.maintenance.destroy');
    Route::get('/stpp', [Tracking\StppController::class, 'index'])->middleware('permission:stpp.view')->name('api.stpp.index');
    Route::get('/stpp/{stpp}', [Tracking\StppController::class, 'show'])->middleware('permission:stpp.view')->name('api.stpp.show');
    Route::post('/stpp', [Tracking\StppController::class, 'store'])->middleware('permission:stpp.create')->name('api.stpp.store');
    Route::match(['put', 'patch'], '/stpp/{stpp}', [Tracking\StppController::class, 'update'])->middleware('permission:stpp.update')->name('api.stpp.update');
    Route::delete('/stpp/{stpp}', [Tracking\StppController::class, 'destroy'])->middleware('permission:stpp.update')->name('api.stpp.destroy');
    Route::post('/stpp/{stpp}/withdraw', [Tracking\StppController::class, 'withdraw'])->middleware('permission:stpp.return')->name('api.stpp.withdraw');
    Route::post('/stpp/{stpp}/reissue', [Tracking\StppController::class, 'reissue'])->middleware('permission:stpp.create')->name('api.stpp.reissue');

    // Tracking form helpers (vehicles & NPBG/RI numbers from Accurate)
    Route::get('/tracking/vehicles', [Tracking\TrackingLookupController::class, 'vehicles'])
        ->middleware('permission:lend.view|borrow.view|tyre.view|maintenance.view|manufacturing.view')->name('api.tracking.vehicles');
    Route::get('/tracking/documents', [Tracking\TrackingLookupController::class, 'documents'])
        ->middleware('permission:lend.view|borrow.view|tyre.view|maintenance.view|manufacturing.view')->name('api.tracking.documents');
    Route::get('/tracking/document', [Tracking\TrackingLookupController::class, 'document'])
        ->middleware('permission:lend.view|borrow.view|tyre.view|maintenance.view|manufacturing.view')->name('api.tracking.document');
    // Pengembalian Bekas — Excel sheets "Spare Part" (matrix) and "Spare Part Lain" (other)
    Route::get('/used-return-sheets/{sheet}', [Tracking\UsedReturnSheetController::class, 'index'])->whereIn('sheet', ['matrix', 'other'])->middleware('permission:used_return.view')->name('api.used-return-sheets.index');
    Route::get('/used-return-sheets/{sheet}/{id}', [Tracking\UsedReturnSheetController::class, 'show'])->whereIn('sheet', ['matrix', 'other'])->middleware('permission:used_return.view')->name('api.used-return-sheets.show');
    Route::post('/used-return-sheets/{sheet}', [Tracking\UsedReturnSheetController::class, 'save'])->whereIn('sheet', ['matrix', 'other'])->middleware('permission:used_return.update')->name('api.used-return-sheets.store');
    Route::put('/used-return-sheets/{sheet}/{id}', [Tracking\UsedReturnSheetController::class, 'save'])->whereIn('sheet', ['matrix', 'other'])->middleware('permission:used_return.update')->name('api.used-return-sheets.update');
    Route::delete('/used-return-sheets/{sheet}/{id}', [Tracking\UsedReturnSheetController::class, 'destroy'])->whereIn('sheet', ['matrix', 'other'])->middleware('permission:used_return.update')->name('api.used-return-sheets.destroy');
    Route::get('/tyre-changes/options', [Tracking\TyreChangeController::class, 'options'])->middleware('permission:tyre.view')->name('api.tyre.options');
    // Excel sheets "Ban Luar BPN" (inventory) and "Deliver & Receive Ban SIG-BPN" (transfers)
    Route::get('/tyre-bpn/{sheet}', [Tracking\TyreChangeController::class, 'bpn'])->whereIn('sheet', ['inventory', 'transfers'])->middleware('permission:tyre.view')->name('api.tyre.bpn');
    Route::post('/tyre-bpn/{sheet}', [Tracking\TyreChangeController::class, 'bpnSave'])->whereIn('sheet', ['inventory', 'transfers'])->middleware('permission:tyre.update')->name('api.tyre.bpn.store');
    Route::put('/tyre-bpn/{sheet}/{id}', [Tracking\TyreChangeController::class, 'bpnSave'])->whereIn('sheet', ['inventory', 'transfers'])->middleware('permission:tyre.update')->name('api.tyre.bpn.update');
    Route::delete('/tyre-bpn/{sheet}/{id}', [Tracking\TyreChangeController::class, 'bpnDestroy'])->whereIn('sheet', ['inventory', 'transfers'])->middleware('permission:tyre.update')->name('api.tyre.bpn.destroy');
    Route::get('/tyre-changes', [Tracking\TyreChangeController::class, 'index'])->middleware('permission:tyre.view')->name('api.tyre.index');
    Route::get('/tyre-changes/{tyreChange}', [Tracking\TyreChangeController::class, 'show'])->middleware('permission:tyre.view')->name('api.tyre.show');
    Route::post('/tyre-changes', [Tracking\TyreChangeController::class, 'store'])->middleware('permission:tyre.create')->name('api.tyre.store');
    Route::match(['put', 'patch'], '/tyre-changes/{tyreChange}', [Tracking\TyreChangeController::class, 'update'])->middleware('permission:tyre.update')->name('api.tyre.update');
    Route::delete('/tyre-changes/{tyreChange}', [Tracking\TyreChangeController::class, 'destroy'])->middleware('permission:tyre.update')->name('api.tyre.destroy');
    Route::post('/tyre-changes/{tyreChange}/close', [Tracking\TyreChangeController::class, 'close'])->middleware('permission:tyre.close')->name('api.tyre.close');

    // Kartu Stock (paper "KARTU STOCK BARANG": created from Master Barang, lines by hand, print / PDF) — permissions in the controller
    Route::get('/stock-card-projects', [\App\Http\Controllers\Api\StockCardProjectController::class, 'index'])->name('api.stock-card-projects.index');
    Route::post('/stock-card-projects', [\App\Http\Controllers\Api\StockCardProjectController::class, 'store'])->name('api.stock-card-projects.store');
    Route::get('/stock-card-projects/{project}', [\App\Http\Controllers\Api\StockCardProjectController::class, 'show'])->name('api.stock-card-projects.show');
    Route::put('/stock-card-projects/{project}', [\App\Http\Controllers\Api\StockCardProjectController::class, 'update'])->name('api.stock-card-projects.update');
    Route::delete('/stock-card-projects/{project}', [\App\Http\Controllers\Api\StockCardProjectController::class, 'destroy'])->name('api.stock-card-projects.destroy');
    Route::get('/stock-card-projects/{project}/pdf', [\App\Http\Controllers\Api\StockCardProjectController::class, 'pdf'])->name('api.stock-card-projects.pdf');
    Route::get('/stock-card-projects/{project}/share-links', [\App\Http\Controllers\Api\StockCardShareLinkController::class, 'index'])->name('api.stock-card-projects.share-links.index');
    Route::post('/stock-card-projects/{project}/share-links', [\App\Http\Controllers\Api\StockCardShareLinkController::class, 'store'])->name('api.stock-card-projects.share-links.store');
    Route::put('/stock-card-share-links/{link}', [\App\Http\Controllers\Api\StockCardShareLinkController::class, 'update'])->name('api.stock-card-share-links.update');
    Route::delete('/stock-card-share-links/{link}', [\App\Http\Controllers\Api\StockCardShareLinkController::class, 'destroy'])->name('api.stock-card-share-links.destroy');
    Route::get('/stock-card/items', [\App\Http\Controllers\Api\StockCardController::class, 'items'])->name('api.stock-card.items');
    Route::get('/stock-cards', [\App\Http\Controllers\Api\StockCardController::class, 'index'])->name('api.stock-cards.index');
    Route::get('/stock-cards/next-number', [\App\Http\Controllers\Api\StockCardController::class, 'nextNumber'])->name('api.stock-cards.next-number');
    Route::post('/stock-cards', [\App\Http\Controllers\Api\StockCardController::class, 'store'])->name('api.stock-cards.store');
    Route::get('/stock-cards/{stockCard}', [\App\Http\Controllers\Api\StockCardController::class, 'show'])->name('api.stock-cards.show');
    Route::put('/stock-cards/{stockCard}', [\App\Http\Controllers\Api\StockCardController::class, 'update'])->name('api.stock-cards.update');
    Route::delete('/stock-cards/{stockCard}', [\App\Http\Controllers\Api\StockCardController::class, 'destroy'])->name('api.stock-cards.destroy');
    Route::get('/stock-cards/{stockCard}/pdf', [\App\Http\Controllers\Api\StockCardController::class, 'pdf'])->name('api.stock-cards.pdf');
    Route::post('/stock-cards/{stockCard}/entries', [\App\Http\Controllers\Api\StockCardController::class, 'storeEntry'])->name('api.stock-cards.entries.store');
    Route::put('/stock-cards/{stockCard}/entries/{entry}', [\App\Http\Controllers\Api\StockCardController::class, 'updateEntry'])->name('api.stock-cards.entries.update');
    Route::delete('/stock-cards/{stockCard}/entries/{entry}', [\App\Http\Controllers\Api\StockCardController::class, 'destroyEntry'])->name('api.stock-cards.entries.destroy');
    // evidence photos of every tracking module (permission checked per module in the controller)
    Route::get('/tracking-photos/{photo}', [Tracking\TrackingPhotoController::class, 'show'])->name('api.tracking-photos.show');
    Route::post('/tracking-photos', [Tracking\TrackingPhotoController::class, 'store'])->name('api.tracking-photos.store');
    Route::delete('/tracking-photos/{photo}', [Tracking\TrackingPhotoController::class, 'destroy'])->name('api.tracking-photos.destroy');
    // Excel sheet view: one row per Sub SPK
    Route::get('/maintenance-lines/options', [Tracking\MaintenanceController::class, 'options'])->middleware('permission:maintenance.view')->name('api.maintenance.options');
    Route::get('/maintenance-lines', [Tracking\MaintenanceController::class, 'lines'])->middleware('permission:maintenance.view')->name('api.maintenance.lines');
    Route::get('/maintenance-orders', [Tracking\MaintenanceController::class, 'index'])->middleware('permission:maintenance.view')->name('api.maintenance.index');
    Route::get('/maintenance-orders/{maintenanceOrder}', [Tracking\MaintenanceController::class, 'show'])->middleware('permission:maintenance.view')->name('api.maintenance.show');
    Route::post('/maintenance-orders', [Tracking\MaintenanceController::class, 'store'])->middleware('permission:maintenance.create')->name('api.maintenance.store');
    Route::match(['put', 'patch'], '/maintenance-orders/{maintenanceOrder}', [Tracking\MaintenanceController::class, 'update'])->middleware('permission:maintenance.update')->name('api.maintenance.update');
    Route::delete('/maintenance-orders/{maintenanceOrder}', [Tracking\MaintenanceController::class, 'destroy'])->middleware('permission:maintenance.update')->name('api.maintenance.destroy');
    Route::post('/maintenance-orders/{maintenanceOrder}/subs', [Tracking\MaintenanceController::class, 'addSub'])->middleware('permission:maintenance.update')->name('api.maintenance.subs');
    Route::match(['put', 'patch'], '/maintenance-subs/{sub}', [Tracking\MaintenanceController::class, 'updateSub'])->middleware('permission:maintenance.update')->name('api.maintenance.subs.update');
    Route::delete('/maintenance-subs/{sub}', [Tracking\MaintenanceController::class, 'destroySub'])->middleware('permission:maintenance.update')->name('api.maintenance.subs.destroy');
    Route::post('/maintenance-subs/{sub}/complete', [Tracking\MaintenanceController::class, 'completeSub'])->middleware('permission:maintenance.complete')->name('api.maintenance.subs.complete');

    // Excel sheet view: one row per unit (?kind=ASSEMBLY → sheet MA, ?kind=JASA → sheet MJ)
    Route::get('/manufacturing-lines/options', [Tracking\ManufacturingController::class, 'options'])->middleware('permission:manufacturing.view')->name('api.manufacturing.options');
    Route::get('/manufacturing-lines', [Tracking\ManufacturingController::class, 'lines'])->middleware('permission:manufacturing.view')->name('api.manufacturing.lines');
    Route::get('/manufacturing-orders', [Tracking\ManufacturingController::class, 'index'])->middleware('permission:manufacturing.view')->name('api.manufacturing.index');
    Route::get('/manufacturing-orders/{manufacturingOrder}', [Tracking\ManufacturingController::class, 'show'])->middleware('permission:manufacturing.view')->name('api.manufacturing.show');
    Route::post('/manufacturing-orders', [Tracking\ManufacturingController::class, 'store'])->middleware('permission:manufacturing.create')->name('api.manufacturing.store');
    Route::match(['put', 'patch'], '/manufacturing-orders/{manufacturingOrder}', [Tracking\ManufacturingController::class, 'update'])->middleware('permission:manufacturing.update')->name('api.manufacturing.update');
    Route::delete('/manufacturing-orders/{manufacturingOrder}', [Tracking\ManufacturingController::class, 'destroy'])->middleware('permission:manufacturing.update')->name('api.manufacturing.destroy');
    Route::post('/manufacturing-orders/{manufacturingOrder}/subs', [Tracking\ManufacturingController::class, 'addSub'])->middleware('permission:manufacturing.update')->name('api.manufacturing.subs');
    Route::match(['put', 'patch'], '/manufacturing-subs/{sub}', [Tracking\ManufacturingController::class, 'updateSub'])->middleware('permission:manufacturing.update')->name('api.manufacturing.subs.update');
    Route::delete('/manufacturing-subs/{sub}', [Tracking\ManufacturingController::class, 'destroySub'])->middleware('permission:manufacturing.update')->name('api.manufacturing.subs.destroy');
    Route::post('/manufacturing-subs/{sub}/photo', [Tracking\ManufacturingController::class, 'uploadPhoto'])->middleware('permission:manufacturing.update')->name('api.manufacturing.subs.photo.store');
    Route::get('/manufacturing-subs/{sub}/photo', [Tracking\ManufacturingController::class, 'photo'])->middleware('permission:manufacturing.view')->name('api.manufacturing.subs.photo');
    Route::post('/manufacturing-subs/{sub}/complete', [Tracking\ManufacturingController::class, 'completeSub'])->middleware('permission:manufacturing.complete')->name('api.manufacturing.subs.complete');

    Route::get('/used-returns', [Tracking\UsedReturnController::class, 'index'])->middleware('permission:used_return.view')->name('api.used-returns.index');
    Route::get('/used-returns/{usedReturn}', [Tracking\UsedReturnController::class, 'show'])->middleware('permission:used_return.view')->name('api.used-returns.show');
    Route::post('/used-returns', [Tracking\UsedReturnController::class, 'store'])->middleware('permission:used_return.create')->name('api.used-returns.store');
    Route::match(['put', 'patch'], '/used-returns/{usedReturn}', [Tracking\UsedReturnController::class, 'update'])->middleware('permission:used_return.update')->name('api.used-returns.update');
    Route::delete('/used-returns/{usedReturn}', [Tracking\UsedReturnController::class, 'destroy'])->middleware('permission:used_return.update')->name('api.used-returns.destroy');
    Route::post('/used-returns/{usedReturn}/close', [Tracking\UsedReturnController::class, 'close'])->middleware('permission:used_return.close')->name('api.used-returns.close');

    // PHASE 9 — Dashboard (ringkasan per-peran).
    Route::get('/dashboard', DashboardController::class)->name('api.dashboard');
    // Dashboard visualisasi: every logged-in role (user decision 2026-09-29).
    Route::get('/dashboard/inventory', InventoryDashboardController::class)->name('api.dashboard.inventory');
    Route::get('/dashboard/inventory/filters', [InventoryDashboardController::class, 'filters'])->name('api.dashboard.inventory.filters');
    // Tracking & Pembelian: aggregates for every role, the document lists per view permission.
    Route::get('/dashboard/operations', \App\Http\Controllers\Api\OperationsDashboardController::class)->name('api.dashboard.operations');
    // Kartu Stock: barang masuk / keluar per hari, minggu, bulan (who may open Kartu Stock).
    Route::get('/dashboard/stock-cards', \App\Http\Controllers\Api\StockCardDashboardController::class)->name('api.dashboard.stock-cards');

    // PHASE 10 — Laporan & export (xlsx / csv / pdf-print).
    Route::get('/export/{dataset}', ExportController::class)->name('api.export');

    // Replika Excel lama (docs/excel-data-mapping.md) — migrasi penuh ke web.
    Route::get('/legacy-export', [LegacyExportController::class, 'index'])->name('api.legacy-export.index');
    Route::get('/legacy-export/{key}', LegacyExportController::class)->name('api.legacy-export');
});
