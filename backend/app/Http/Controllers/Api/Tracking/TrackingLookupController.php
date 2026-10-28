<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Services\Tracking\AccurateDocRefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Helpers for the tracking forms: vehicles known from Accurate NPBG/RI, and
 * an NPBG / RI number looked up the way it is typed in the Excel.
 */
class TrackingLookupController extends Controller
{
    public function __construct(private readonly AccurateDocRefs $refs) {}

    public function vehicles(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->refs->vehicles($request->string('search')->value())]);
    }

    /** NPBG / RI list for the pick-from-list inputs of the tracking forms. */
    public function documents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:npbg,ri'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->refs->search($data['type'], $data['q'] ?? null)]);
    }

    public function document(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:npbg,ri'],
            'no' => ['required', 'string', 'max:40'],
        ]);
        $doc = $data['type'] === 'npbg' ? $this->refs->npbgSummary($data['no']) : $this->refs->riSummary($data['no']);

        return $doc
            ? response()->json(['data' => $doc])
            : response()->json(['message' => strtoupper($data['type'])." {$data['no']} tidak ditemukan di data Accurate."], 404);
    }
}
