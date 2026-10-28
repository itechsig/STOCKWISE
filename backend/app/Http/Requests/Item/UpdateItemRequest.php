<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('item')->id;

        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('items', 'code')->ignore($id)],
            'description' => ['sometimes', 'string', 'max:400'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id'), function (string $attr, mixed $value, \Closure $fail) {
                // UOM: one of the fixed list (App\Support\ItemOptions::UOM)
                if ($value !== null && ! in_array(\App\Models\Unit::whereKey($value)->value('code'), \App\Support\ItemOptions::UOM, true) && (int) $value !== (int) $this->route('item')->unit_id) {
                    $fail('Pilih UOM dari daftar yang tersedia.');
                }
            }],
            'item_type' => ['sometimes', Rule::in(['CONSUMABLE', 'SERIALIZED', 'ASSET_PART', 'TYRE', 'MANUFACTURED'])],
            'product_kind' => ['sometimes', Rule::in(['BARANG', 'JASA'])],
            'needs_blueprint' => ['sometimes', 'boolean'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'default_warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')],
            'default_location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouse_locations', 'id')],
            'blueprint_img_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'blueprint_3d_ref' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ] + \App\Support\ItemOptions::rules($this->route('item'));
    }
}
