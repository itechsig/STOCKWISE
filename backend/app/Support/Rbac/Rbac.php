<?php

namespace App\Support\Rbac;

/**
 * Single source of truth for RBAC (docs/roles-permissions.md).
 * Consumed by RoleSeeder / PermissionSeeder and by the `permission` middleware
 * (to reject unknown slugs early).
 */
final class Rbac
{
    /** roleSlug => [name, description]; the description lists its tasks, one per line (≤ 255 characters) */
    public const ROLES = [
        'super_admin' => ['Super Admin', "Akses penuh ke semua fitur\nKelola user, role & hak akses\nPengaturan, sync Accurate & audit log"],
        'admin_gudang' => ['Admin Gudang', "Verifikasi Request Barang (cocokkan ke Master Produk)\nMaster Produk, stok, Kartu Stock, Analisis & Restock\nDraft PPB ke PPB, RI dari PO, Retur Pembelian\nStock opname, tracking, sync Accurate & laporan"],
        'anak_gudang' => ['Anak Gudang / Stock Opname', "Hitung fisik & input hasil stock opname\nLihat stok, Master Produk & riwayat opname"],
        'lapangan_gudang' => ['Lapangan Gudang', "Siapkan & serah terima Request Barang (tetapkan jumlah, foto, tanda tangan)\nHitung fisik stock opname\nTracking: Lend, Borrow, STPP, Ban Luar, Maintenance, Manufaktur, Bekas"],
        'purchasing' => ['Purchasing', "Lengkapi PO dari PPB yang disetujui (vendor & harga), ajukan ke Direktur\nUpload bukti pembelian (status Sudah dibeli)\nVendor, RFQ, Retur Pembelian & laporan pengadaan"],
        'bos' => ['BOS / Management', "Dashboard executive & semua laporan (hanya lihat)\nKeputusan akhir klarifikasi barang NPBG"],
        'karyawan' => ['Karyawan / Requester', "Buat Request Barang / Jasa & pantau statusnya\nDapat notifikasi saat barang siap diambil"],
        'manager' => ['Manager', "Approval tahap 1: Request Barang, PPB & NPBG manual\nUbah jumlah disetujui & beri catatan\nSemua tugas di menu Approval"],
        'direktur' => ['Direktur', "Approval akhir: Request Barang, PPB, NPBG manual & PO STOCKWISE\nBisa approve langsung tanpa menunggu Manager\nUbah jumlah disetujui & beri catatan"],
    ];

    /** group => [slug => human name] */
    public const PERMISSIONS = [
        'profile' => [
            'profile.view_own' => 'Lihat profil sendiri',
            'profile.update_own' => 'Ubah profil sendiri',
            'notification.view_own' => 'Lihat notifikasi sendiri',
        ],
        'user_management' => [
            'user.view' => 'Lihat user',
            'user.create' => 'Buat user',
            'user.update' => 'Ubah user',
            'user.delete' => 'Hapus user',
            'user.assign_role' => 'Assign role ke user',
            'role.view' => 'Lihat role',
            'role.manage' => 'Kelola role',
            'permission.view' => 'Lihat permission',
            'permission.manage' => 'Kelola permission',
        ],
        'master_data' => [
            'master.category.view' => 'Lihat kategori',
            'master.category.manage' => 'Kelola kategori',
            'master.unit.view' => 'Lihat satuan',
            'master.unit.manage' => 'Kelola satuan',
            'master.warehouse.view' => 'Lihat gudang',
            'master.warehouse.manage' => 'Kelola gudang',
            'master.location.view' => 'Lihat rak',
            'master.location.manage' => 'Kelola rak',
            'master.site.view' => 'Lihat site',
            'master.site.manage' => 'Kelola site',
            'master.vendor.view' => 'Lihat vendor',
            'master.vendor.manage' => 'Kelola vendor',
            'master.customer.view' => 'Lihat pelanggan',
            'master.customer.manage' => 'Kelola pelanggan',
            'master.project.view' => 'Lihat proyek',
            'master.project.manage' => 'Kelola proyek',
            'master.workshop.view' => 'Lihat bengkel',
            'master.workshop.manage' => 'Kelola bengkel',
            'master.department.view' => 'Lihat divisi',
            'master.department.manage' => 'Kelola divisi',
            'master.employee.view' => 'Lihat karyawan (roster)',
            'master.employee.manage' => 'Kelola karyawan (roster)',
        ],
        'item_inventory' => [
            'item.view' => 'Lihat Master Produk',
            'item.lookup' => 'Cari barang (untuk Request Barang / PPB)',
            'item.create' => 'Tambah barang / jasa ke Master Produk',
            'item.update' => 'Ubah barang di Master Produk',
            'item.delete' => 'Hapus barang dari Master Produk',
            'item.import' => 'Import barang dari Excel',
            'item.safety_stock.view' => 'Lihat safety stock',
            'item.safety_stock.update' => 'Ubah safety stock',
            'item.safety_stock.resolve_conflict' => 'Selesaikan konflik safety stock',
            'item.lead_time.update' => 'Ubah lead time',
            'item.alias.view' => 'Lihat alias barang',
            'item.alias.match' => 'Cocokkan barang (alias)',
            'inventory.view' => 'Lihat stok (inventory)',
            'inventory.view_analysis' => 'Lihat Analisis & Safety Stock (Restock)',
            'inventory.transfer' => 'Transfer stok antar gudang',
            'stock_movement.view' => 'Lihat Kartu Stock (pergerakan stok)',
        ],
        // NPBG asli — mirror ARINV/ARINVDET dari Accurate (read-mostly, hanya field
        // kepemilikan Stockwise yang bisa diubah). Diisi lewat Sync Accurate.
        // Request Barang (ItemRequestController) — karyawan request, admin gudang jadikan NPBG / PPB
        'item_request' => [
            'item_request.create' => 'Buat Request Barang & pantau milik sendiri',
            'item_request.view' => 'Lihat semua Request Barang',
            'item_request.process' => 'Verifikasi Request Barang (cocokkan barang & jumlah, kirim ke approval)',
            'item_request.approve_manager' => 'Approval Manager: Request Barang (ubah jumlah & catatan)',
            'item_request.approve_director' => 'Approval Direktur: Request Barang (akhir, bisa langsung tanpa Manager)',
            'item_request.handover' => 'Gudang: siapkan & serah terima (tetapkan jumlah, foto, tanda tangan)',
        ],
        'npbg' => [
            'npbg.view' => 'Lihat NPBG',
            // NPBG manual (Tambah NPBG, bukan dari request): Manager lalu Direktur
            'npbg.approve_manager' => 'Approval Manager: NPBG manual',
            'npbg.approve_director' => 'Approval Direktur: NPBG manual (setelah Manager)',
            'npbg.create' => 'Buat NPBG manual (perlu approval Manager & Direktur)',
            'npbg.update' => 'Ubah field NPBG (tipe/klasifikasi/proyek/dll)',
        ],
        // Klarifikasi/Verifikasi Barang — kasus barang beda type/spesifikasi.
        'npbg_verification' => [
            'npbg.verification.view' => 'Lihat klarifikasi/verifikasi barang NPBG',
            'npbg.verification.manage' => 'Ajukan/proses/tawarkan alternatif/eskalasi klarifikasi',
            'npbg.verification.respond' => 'Terima/tolak barang alternatif (Maintenance)',
            'npbg.verification.bos_decide' => 'Keputusan akhir BOS untuk klarifikasi',
        ],
        'stock_opname' => [
            'opname.view' => 'Lihat stock opname',
            'opname.schedule' => 'Jadwalkan stock opname',
            'opname.count' => 'Input stok fisik',
            'opname.submit' => 'Submit hasil opname',
            'opname.review' => 'Review hasil opname',
            'opname.approve' => 'Approve opname (buat adjustment)',
            'opname.reject' => 'Reject opname',
            'opname.request_recount' => 'Minta hitung ulang',
        ],
        // Mirror Accurate REQUISITION/REQUISITIONDET — read-only (sync), tapi bisa juga dibuat manual.
        'ppb' => [
            'ppb.view' => 'Lihat PPB',
            'ppb.create' => 'Draft PPB & ajukan PPB (dari Request Barang / Restock)',
            'ppb.approve' => 'Approve PPB Accurate (satu tahap)',
            // PPB STOCKWISE (bukan dari Accurate): dua tahap, Manager lalu Direktur
            'ppb.approve_manager' => 'Approval Manager: PPB STOCKWISE',
            'ppb.approve_director' => 'Approval Direktur: PPB STOCKWISE (lalu jadi Menunggu PO)',
        ],
        // Mirror Accurate APINV/APITMDET — read-only, lihat AccurateSyncService::syncRi().
        // Terpisah dari grup 'receiving' (alur internal DRAFT->CHECKING->CONFIRMED).
        'ri' => [
            'ri.view' => 'Lihat RI',
            'ri.create' => 'Buat RI dari PO (partial atau selesai), dari pengembalian bekas, atau manual',
        ],
        'purchase_return' => [
            'purchase_return.view' => 'Lihat retur pembelian',
            'purchase_return.manage' => 'Buat & kelola retur pembelian (kirim ke vendor, selesaikan, batalkan)',
        ],
        'purchasing' => [
            'rfq.view' => 'Lihat RFQ',
            'rfq.create' => 'Buat RFQ',
            'rfq.update' => 'Ubah RFQ',
            'rfq.send' => 'Kirim RFQ ke vendor',
            'rfq.select' => 'Pilih quote RFQ',
            'po.view' => 'Lihat PO',
            'po.create' => 'Buat / lengkapi PO (vendor & harga) dan upload bukti pembelian',
            'po.update' => 'Ubah PO',
            'po.approve' => 'Approve PO non-STOCKWISE',
            // PO STOCKWISE (bukan dari Accurate) disetujui Direktur
            'po.approve_director' => 'Approval Direktur: PO STOCKWISE',
            'po.send' => 'Kirim PO ke vendor & upload bukti pembelian (Sudah dibeli)',
            'po.cancel' => 'Batalkan PO',
        ],
        'tracking' => [
            'lend.view' => 'Lihat Lend', 'lend.create' => 'Buat Lend', 'lend.update' => 'Ubah Lend', 'lend.return' => 'Proses pengembalian Lend',
            'borrow.view' => 'Lihat Borrow', 'borrow.create' => 'Buat Borrow', 'borrow.update' => 'Ubah Borrow', 'borrow.return' => 'Proses pengembalian Borrow',
            'stpp.view' => 'Lihat STPP', 'stpp.create' => 'Buat STPP', 'stpp.update' => 'Ubah STPP', 'stpp.return' => 'Proses penarikan STPP',
            'tyre.view' => 'Lihat Ban Luar', 'tyre.create' => 'Buat penggantian ban', 'tyre.update' => 'Ubah penggantian ban', 'tyre.close' => 'Tutup (RI ban lama)',
            'maintenance.view' => 'Lihat Maintenance', 'maintenance.create' => 'Buat SPK', 'maintenance.update' => 'Ubah SPK', 'maintenance.complete' => 'Selesaikan SPK',
            'manufacturing.view' => 'Lihat Manufaktur', 'manufacturing.create' => 'Buat MA/MJ', 'manufacturing.update' => 'Ubah MA/MJ', 'manufacturing.complete' => 'Selesaikan MA/MJ',
            'used_return.view' => 'Lihat Pengembalian Bekas', 'used_return.create' => 'Buat pengembalian bekas', 'used_return.update' => 'Ubah pengembalian bekas', 'used_return.close' => 'Tutup pengembalian bekas',
        ],
        'dashboard_report' => [
            'dashboard.karyawan' => 'Dashboard Karyawan',
            'dashboard.gudang' => 'Dashboard Gudang',
            'dashboard.opname' => 'Dashboard Opname',
            'dashboard.lapangan' => 'Dashboard Lapangan',
            'dashboard.purchasing' => 'Dashboard Purchasing',
            'dashboard.executive' => 'Dashboard Executive',
            'report.items' => 'Laporan Master Barang',
            'report.inventory' => 'Laporan inventory',
            'report.npbg' => 'Laporan NPBG',
            'report.ppb' => 'Laporan PPB',
            'report.ri' => 'Laporan RI',
            'report.opname' => 'Laporan opname',
            'report.stock_movement' => 'Laporan stock movement',
            'report.procurement' => 'Laporan procurement',
            'export.excel' => 'Export Excel',
            'export.csv' => 'Export CSV',
            'export.pdf' => 'Export PDF',
        ],
        'system' => [
            'audit_log.view' => 'Lihat audit log',
            'settings.view' => 'Lihat settings',
            'settings.update' => 'Ubah settings',
        ],
        'accurate_sync' => [
            'sync.accurate.view' => 'Lihat status & riwayat sync Accurate',
            'sync.accurate.trigger' => 'Jalankan sync Accurate',
        ],
        'important_request' => [
            'important_request.access' => 'Important Request: lihat & kerjakan tugas yang ditugaskan, berdiskusi',
            'important_request.create' => 'Important Request: buat & tugaskan instruksi, review hasil',
            'important_request.view_all' => 'Important Request: pantau semua request (admin)',
            'important_request.analytics' => 'Important Request: analitik (Tracking & Analytic)',
        ],
        'accurate_session' => [
            'accurate.session.view' => 'Lihat PC yang sedang terhubung ke Accurate',
            'accurate.session.terminate' => 'Putuskan koneksi PC ke Accurate secara manual',
        ],
    ];

    /**
     * roleSlug => list of permission slugs, or ['*'] for all.
     * Mirrors the matrix in docs/roles-permissions.md §3.
     */
    public const MATRIX = [
        'super_admin' => ['*'],

        'karyawan' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access',
            'item.lookup', 'item_request.create',
            'dashboard.karyawan',
            'export.excel', 'export.csv', 'export.pdf',
        ],

        'lapangan_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access',
            'inventory.view', 'stock_movement.view',
            'npbg.view', 'item_request.handover',
            'npbg.verification.view', 'npbg.verification.manage', 'npbg.verification.respond',
            // Stock opname: admin gudang menjadwalkan, admin lapangan yang turun hitung fisik & input hasilnya.
            'opname.view', 'opname.count', 'opname.submit',
            'lend.view', 'lend.create', 'lend.update', 'lend.return',
            'borrow.view', 'borrow.create', 'borrow.update', 'borrow.return',
            'stpp.view', 'stpp.create', 'stpp.update', 'stpp.return',
            'tyre.view', 'tyre.create', 'tyre.update', 'tyre.close',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete',
            'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'manufacturing.complete',
            'used_return.view', 'used_return.create', 'used_return.update', 'used_return.close',
            'dashboard.gudang', 'dashboard.lapangan', 'dashboard.opname',
            'report.npbg', 'report.opname', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'anak_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access',
            'inventory.view', 'stock_movement.view', 'item.view', 'item.alias.view',
            'opname.view', 'opname.count', 'opname.submit',
            'stpp.view',
            'dashboard.gudang', 'dashboard.opname',
            'report.opname', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'admin_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access',
            'master.category.view', 'master.unit.view', 'master.warehouse.view', 'master.location.view',
            'master.site.view', 'master.vendor.view', 'master.customer.view', 'master.project.view',
            'master.workshop.view', 'master.department.view', 'master.employee.view',
            'item.view', 'item.create', 'item.update', 'item.delete', 'item.import',
            'item.safety_stock.view', 'item.safety_stock.update', 'item.safety_stock.resolve_conflict',
            'item.lead_time.update', 'item.alias.view', 'item.alias.match',
            'inventory.view', 'inventory.view_analysis', 'inventory.transfer', 'stock_movement.view',
            'sync.accurate.view', 'sync.accurate.trigger',
            'npbg.view', 'npbg.create', 'npbg.update',
            'item_request.create', 'item_request.view', 'item_request.process',
            'npbg.verification.view', 'npbg.verification.manage', 'npbg.verification.respond',
            'opname.view', 'opname.schedule', 'opname.count', 'opname.submit',
            'opname.review', 'opname.approve', 'opname.reject', 'opname.request_recount',
            'ppb.view', 'ppb.create', 'ri.view', 'ri.create',
            'purchase_return.view', 'purchase_return.manage',
            'lend.view', 'lend.create', 'lend.update', 'lend.return',
            'borrow.view', 'borrow.create', 'borrow.update', 'borrow.return',
            'stpp.view', 'stpp.create', 'stpp.update', 'stpp.return',
            'tyre.view', 'tyre.create', 'tyre.update', 'tyre.close',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete',
            'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'manufacturing.complete',
            'used_return.view', 'used_return.create', 'used_return.update', 'used_return.close',
            'dashboard.gudang', 'dashboard.lapangan', 'dashboard.opname',
            'report.items', 'report.inventory', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname',
            'report.stock_movement', 'report.procurement',
            'export.excel', 'export.csv', 'export.pdf',
            'audit_log.view', 'settings.view',
        ],

        'purchasing' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access',
            'master.vendor.view', 'master.vendor.manage', 'master.category.view', 'master.unit.view',
            'master.warehouse.view',
            'item.view', 'item.alias.view',
            'inventory.view', 'inventory.view_analysis', 'stock_movement.view',
            'ppb.view', 'ri.view',
            'purchase_return.view', 'purchase_return.manage',
            'rfq.view', 'rfq.create', 'rfq.update', 'rfq.send', 'rfq.select',
            'po.view', 'po.create', 'po.update', 'po.approve', 'po.send', 'po.cancel',
            'dashboard.purchasing',
            'report.procurement', 'report.ppb', 'report.ri', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'bos' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access', 'important_request.create', 'important_request.view_all', 'important_request.analytics',
            'inventory.view', 'inventory.view_analysis', 'stock_movement.view', 'item.view',
            'npbg.view', 'npbg.verification.view', 'npbg.verification.bos_decide',
            'ppb.view', 'ri.view', 'po.view', 'purchase_return.view',
            'opname.view',
            'lend.view', 'borrow.view', 'stpp.view', 'tyre.view', 'maintenance.view',
            'manufacturing.view', 'used_return.view',
            'dashboard.executive',
            'report.items', 'report.inventory', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname',
            'report.stock_movement', 'report.procurement',
            'export.excel', 'export.csv', 'export.pdf',
            'audit_log.view',
        ],

        'manager' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access', 'important_request.create',
            'item_request.approve_manager',
            'ppb.view', 'ppb.approve_manager', 'npbg.view', 'npbg.approve_manager',
        ],

        'direktur' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'important_request.access', 'important_request.create', 'important_request.view_all', 'important_request.analytics',
            'item_request.approve_director',
            'ppb.view', 'ppb.approve_director', 'po.view', 'po.approve_director', 'npbg.view', 'npbg.approve_director',
        ],
    ];

    /** Flat list of every known permission slug. */
    public static function allPermissionSlugs(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::PERMISSIONS)));
    }

    /** Resolve a role's permission slugs, expanding the ['*'] wildcard. */
    public static function permissionsForRole(string $roleSlug): array
    {
        $entries = self::MATRIX[$roleSlug] ?? [];

        if ($entries === ['*']) {
            return self::allPermissionSlugs();
        }

        return $entries;
    }
}
