<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // one master for vendors and suppliers (Master Vendor & Supplier)
        $query = Vendor::query()->orderBy('name');
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$w}%")->orWhere('code', 'like', "%{$w}%")
                ->orWhere('phone', 'like', "%{$w}%")->orWhere('email', 'like', "%{$w}%")->orWhere('address', 'like', "%{$w}%"));
        }
        $request->whenFilled('is_active', fn ($v) => $query->where('is_active', filter_var($v, FILTER_VALIDATE_BOOL)));

        return response()->json([
            'data' => $query->limit(min(max((int) $request->integer('limit', 50), 1), 2000))
                ->get(['id', 'name', 'code', 'phone', 'email', 'address', 'source', 'is_active', 'needs_review']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', Rule::unique('vendors', 'name')],
            'code' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => Vendor::create($data + ['is_active' => true])], 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200', Rule::unique('vendors', 'name')->ignore($vendor->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'needs_review' => ['sometimes', 'boolean'],
        ]);
        // the Accurate sync finds its vendors by name: a renamed one would come back as a second vendor
        if (array_key_exists('name', $data) && $data['name'] !== $vendor->name && $vendor->source === 'accurate') {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Nama vendor dari Accurate diubah di Accurate (kalau diubah di sini, sinkron membuat vendor dobel).']);
        }
        $vendor->update($data);

        return response()->json(['data' => $vendor]);
    }
}
