<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/dashboard/inventory — KPI cards, health score, and charts for the
 * Inventory Dashboard (brief: "kemudahan penggunaan... mudah dipahami user
 * gudang"). Reactive to the same filter params as Master Barang /
 * Inventory Analysis (search, accurate_category_induk/anak_1/2/3, unit_id,
 * warehouse_id, needs_blueprint, status, lead_time_min/max, selisih_min/max,
 * has_npbg) plus high_lead_time_threshold for the auto-notes. All
 * calculation lives in DashboardAnalyticsService — this controller only
 * validates the request and returns its result.
 */
class InventoryDashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAnalyticsService $service): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'accurate_category_induk' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accurate_category_anak_1' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accurate_category_anak_2' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accurate_category_anak_3' => ['sometimes', 'nullable', 'string', 'max:120'],
            'unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')],
            'warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')],
            'status' => ['sometimes', 'nullable', Rule::in(['AMAN', 'TIDAK_AMAN', 'BEP'])],
            'needs_blueprint' => ['sometimes', 'nullable', 'boolean'],
            'has_npbg' => ['sometimes', 'nullable', 'boolean'],
            'lead_time_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lead_time_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'gte:lead_time_min'],
            'selisih_min' => ['sometimes', 'nullable', 'numeric'],
            'selisih_max' => ['sometimes', 'nullable', 'numeric', 'gte:selisih_min'],
            'high_lead_time_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $service->build($request)]);
    }

    /**
     * GET /api/dashboard/inventory/filters — the dashboard's own filter
     * choices (category, unit, warehouse), so every role that sees the
     * dashboard can use its filters without Master Barang / master-data rights.
     */
    public function filters(): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => Item::query()->whereNotNull('accurate_category_induk')
                ->distinct()->orderBy('accurate_category_induk')->pluck('accurate_category_induk'),
            'units' => Unit::query()->orderBy('code')->get(['id', 'code']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]]);
    }
}
