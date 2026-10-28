<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemRequestLink;
use App\Support\ItemRequestForms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Share links of the Request Barang form (item_request.process): made, turned
 * on / off and deleted by admin gudang. An inactive or deleted link opens
 * nothing — the form says it is closed.
 */
class ItemRequestLinkController extends Controller
{
    /**
     * GET /api/share-base — the address shared links (request form, Cek Permintaan) start with.
     * Opened on the server itself (localhost) such a link would only open there: the server's LAN
     * address is used instead (or STOCKWISE_SHARE_URL when set).
     */
    public function shareBase(Request $request): JsonResponse
    {
        $host = $request->getHost();
        $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $configured = rtrim((string) config('stockwise.share_url'), '/');
        if ($configured !== '') {
            $base = $configured;
        } elseif ($local && ($ip = $this->lanIp())) {
            $base = $request->getScheme().'://'.$ip.(in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort());
        } else {
            $base = $request->getSchemeAndHttpHost();
        }

        return response()->json(['data' => ['base' => $base, 'local_only' => $local && $base === $request->getSchemeAndHttpHost()]]);
    }

    /** The server's private LAN IPv4 (192.168.x / 10.x / 172.16–31.x), if any. */
    private function lanIp(): ?string
    {
        $ips = array_merge([gethostbyname(gethostname())], gethostbynamel(gethostname()) ?: []);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! str_starts_with($ip, '127.')
                && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                return $ip;
            }
        }

        return null;
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => ItemRequestLink::with('creator:id,name')->withCount('requests')->latest('id')->get()->map(fn ($l) => $this->row($l))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            // which form the link opens (ATK, Maintenance General, …) — fixed for the link's life
            'form_type' => ['required', \Illuminate\Validation\Rule::in(array_keys(ItemRequestForms::TYPES))],
        ]);
        $link = ItemRequestLink::create($data + ['token' => ItemRequestLink::newToken(), 'is_active' => true, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($link->load('creator:id,name')->loadCount('requests'))], 201);
    }

    /** PUT — turn the link on / off, or rename it. */
    public function update(Request $request, ItemRequestLink $link): JsonResponse
    {
        $link->update($request->validate(['is_active' => ['sometimes', 'boolean'], 'label' => ['sometimes', 'nullable', 'string', 'max:100']]));

        return response()->json(['data' => $this->row($link->load('creator:id,name')->loadCount('requests'))]);
    }

    public function destroy(ItemRequestLink $link): JsonResponse
    {
        $link->delete(); // requests sent through it stay (link_id -> null)

        return response()->json(['message' => 'Link dihapus, tidak bisa dibuka lagi.']);
    }

    private function row(ItemRequestLink $l): array
    {
        return [
            'id' => $l->id, 'token' => $l->token, 'label' => $l->label, 'is_active' => $l->is_active,
            'form_type' => $l->form_type, 'form_label' => ItemRequestForms::label($l->form_type),
            'created_by' => $l->creator?->name, 'created_at' => $l->created_at, 'last_used_at' => $l->last_used_at,
            'requests_count' => $l->requests_count ?? 0,
        ];
    }
}
