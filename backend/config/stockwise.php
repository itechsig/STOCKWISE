<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Excel source files
    |--------------------------------------------------------------------------
    | Folder containing the 9 company Excel files (see docs/excel-data-mapping.md).
    | Default: the repo root (one level above the Laravel app). Override with
    | STOCKWISE_IMPORT_PATH in .env, e.g. storage/app/private/imports.
    */

    'import_path' => env('STOCKWISE_IMPORT_PATH', dirname(base_path())),

    'files' => [
        'ppb_ri' => '1. PPB - RI.xlsx',
        'npbg' => '2. NPBG.xlsx',
        'borrow_lend' => '3. Tracking Borrow & Lend.xlsx',
        'stpp' => '4. Tracking STPP.xlsx',
        'ban_luar' => '5. Tracking Ban Luar.xlsx',
        'maintenance' => '6. Tracking Maintenance Assets.xlsx',
        'manufaktur' => '7. Tracking Manufaktur & Assembly.xlsx',
        'pengembalian_bekas' => '8. Tracking Pengembalian Bekas.xlsx',
        'master' => 'DATA.xlsx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Calculation engine parameters (docs/calculation-engine.md)
    |--------------------------------------------------------------------------
    */

    'engine' => [
        'lead_time_threshold_fallback' => (int) env('STOCKWISE_LT_THRESHOLD_FALLBACK', 14),
        'reservation_expiry_days' => (int) env('STOCKWISE_RESERVATION_EXPIRY_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Office network — validasi lokasi permintaan barang berdasarkan IP
    |--------------------------------------------------------------------------
    | Daftar IP / CIDR yang dianggap "jaringan kantor". Isi IP publik kantor
    | di STOCKWISE_OFFICE_IP_RANGES (pisahkan koma), mis. "103.10.20.0/24,103.10.21.5".
    | Default sudah mencakup jaringan lokal (LAN / localhost) untuk pengujian.
    */
    'office' => [
        'ip_ranges' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'STOCKWISE_OFFICE_IP_RANGES',
                '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
            ))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Agent logs (read-only, shown in Sync History → Log Agent)
    |--------------------------------------------------------------------------
    | Folder the Sync Agent writes sync.log / agent.log to (sync-service
    | AGENT_LOG_PATH). Only ever read, never written, by Laravel.
    */
    'agent_log_path' => env('STOCKWISE_AGENT_LOG_PATH', 'D:\\01.STOCKWISE\\Logs'),

    // Manual Sync Agent runs from the web (AgentRunController).
    'agent_python' => env('STOCKWISE_AGENT_PYTHON', 'C:\\Users\\server-sig\\AppData\\Local\\Programs\\Python\\Python311\\python.exe'),
    'agent_dir' => env('STOCKWISE_AGENT_DIR', 'D:\\01.STOCKWISE\\sync-service'),

    /*
    | Accurate sync (AccurateSyncService)
    | - sync_run_inline: process a sync inside the web request (old behaviour,
    |   freezes `php artisan serve` for everyone) instead of a background process.
    | - sync_seed_opening_balance: create an OPENING_BALANCE from Accurate's qty
    |   for every item that has no inventory row yet. Mass change to stock
    |   figures — enable deliberately.
    */
    'sync_run_inline' => (bool) env('STOCKWISE_SYNC_RUN_INLINE', false),
    'sync_seed_opening_balance' => (bool) env('STOCKWISE_SYNC_SEED_OPENING_BALANCE', false),

    // the address shared links start with (request form, Cek Permintaan); empty = the server's LAN address
    'share_url' => env('STOCKWISE_SHARE_URL', ''),
    // STOCKWISE stock is set to Accurate's qty after every sync (ACCURATE_SYNC
    // movements) while Accurate is the system of record — user decision 2026-09-26.
    'stock_follows_accurate' => (bool) env('STOCKWISE_STOCK_FOLLOWS_ACCURATE', true),

    // Goods STOCKWISE itself issues (Request Barang handed out) and receives (manual RI) change the stock
    // on top of Accurate's qty — counted from this moment (UTC, as stored: 10:00 WIB, when the Request Barang
    // fulfilment went live), so manual RI made before it never move stock. See App\Services\Inventory\StockwiseNet.
    // Accurate-only mode: transactions are made in Accurate only, STOCKWISE reads them. At the end of every
    // sync whatever was made in STOCKWISE is removed (App\Services\Accurate\StockwisePurge) — Kartu Stock,
    // the tracking modules and the settings stay.
    'accurate_only' => (bool) env('STOCKWISE_ACCURATE_ONLY', true),

    'stockwise_ledger_since' => env('STOCKWISE_LEDGER_SINCE', '2026-10-23 03:00:00'),

    /*
    | Lifetime of a human login token (hours). 0 = never expires (not recommended).
    | The Sync Agent's own token (stockwise:agent-token) is not affected.
    */
    'login_token_ttl_hours' => (int) env('STOCKWISE_LOGIN_TOKEN_TTL_HOURS', 12),

    /*
    | Google Chrome on this server, used headless to turn the printable Kartu
    | Stock into a PDF (App\Services\Inventory\StockCardService::pdf()).
    */
    'chrome_path' => env('STOCKWISE_CHROME_PATH', 'C:/Program Files/Google/Chrome/Application/chrome.exe'),


    // Important Request: largest file per upload (MB); sent in chunks, so the PHP upload limit does not cap it
    'important_request_max_mb' => (int) env('IMPORTANT_REQUEST_MAX_MB', 20),
];
