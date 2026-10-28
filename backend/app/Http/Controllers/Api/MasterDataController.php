<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SerialUnit;
use App\Models\Site;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $query = Category::query()->orderBy('path');
        $request->whenFilled('level', fn ($v) => $query->where('level', (int) $v));
        $request->whenFilled('parent_id', fn ($v) => $query->where('parent_id', $v));

        return response()->json(['data' => $query->get(['id', 'parent_id', 'name', 'level', 'path', 'is_active'])]);
    }

    public function categoryTree(): JsonResponse
    {
        $all = Category::query()->orderBy('name')->get(['id', 'parent_id', 'name', 'level', 'path']);

        $build = function ($parentId) use (&$build, $all) {
            return $all->where('parent_id', $parentId)->values()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'level' => $c->level,
                'path' => $c->path,
                'children' => $build($c->id),
            ]);
        };

        return response()->json(['data' => $build(null)]);
    }

    public function units(): JsonResponse
    {
        return response()->json([
            'data' => Unit::orderBy('code')->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function sites(): JsonResponse
    {
        return response()->json([
            'data' => Site::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function warehouses(Request $request): JsonResponse
    {
        $query = Warehouse::query()->with('site:id,code,name')->orderBy('code');
        $request->whenFilled('site_id', fn ($v) => $query->where('site_id', $v));

        return response()->json(['data' => $query->get()]);
    }

    public function warehouseLocations(Request $request): JsonResponse
    {
        $query = WarehouseLocation::query()->orderBy('code');
        $request->whenFilled('warehouse_id', fn ($v) => $query->where('warehouse_id', $v));

        return response()->json([
            'data' => $query->get(['id', 'warehouse_id', 'code', 'description', 'is_active']),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $query = Customer::query()->orderBy('name');
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$s}%");
        }

        return response()->json(['data' => $query->limit(100)->get(['id', 'name', 'code', 'is_active'])]);
    }

    public function projects(Request $request): JsonResponse
    {
        $query = Project::query()->with('customer:id,name')->orderBy('name');
        $request->whenFilled('customer_id', fn ($v) => $query->where('customer_id', $v));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$s}%");
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function workshops(): JsonResponse
    {
        return response()->json([
            'data' => Workshop::orderBy('name')->get(['id', 'name', 'is_internal', 'site_id', 'is_active']),
        ]);
    }

    public function assets(Request $request): JsonResponse
    {
        $query = Asset::query()->orderBy('code');
        $request->whenFilled('site_id', fn ($v) => $query->where('site_id', $v));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
        }

        return response()->json(['data' => $query->limit(100)->get(['id', 'code', 'name', 'asset_type', 'brand_model', 'site_id'])]);
    }

    public function serialUnits(Request $request): JsonResponse
    {
        $query = SerialUnit::query()->orderBy('serial_no');
        $request->whenFilled('kind', fn ($v) => $query->where('kind', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('serial_no', 'like', "%{$s}%");
        }

        return response()->json(['data' => $query->limit(100)->get(['id', 'serial_no', 'kind', 'item_id', 'description_raw', 'status'])]);
    }
}
