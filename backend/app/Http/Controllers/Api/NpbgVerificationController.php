<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NpbgVerificationResource;
use App\Models\Npbg;
use App\Models\NpbgVerification;
use App\Models\NpbgVerificationLog;
use App\Services\NpbgVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Klarifikasi/Verifikasi Barang — see App\Services\NpbgVerificationService for the workflow. */
class NpbgVerificationController extends Controller
{
    public function __construct(private readonly NpbgVerificationService $service) {}

    public function index(Npbg $npbg): AnonymousResourceCollection
    {
        return NpbgVerificationResource::collection(
            $npbg->verifications()->with(['openedBy', 'respondedBy', 'decidedBy', 'logs.actor'])->latest()->get()
        );
    }

    public function show(NpbgVerification $verification): NpbgVerificationResource
    {
        return $this->fresh($verification);
    }

    public function store(Request $request, Npbg $npbg): JsonResponse
    {
        $data = $request->validate([
            'alasan_pengajuan' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'string'],
        ]);

        $verification = $this->service->open($npbg, $request->user(), $data['alasan_pengajuan'], $data['attachment'] ?? null);

        return $this->fresh($verification)->response()->setStatusCode(201);
    }

    public function process(Request $request, NpbgVerification $verification): NpbgVerificationResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->fresh($this->service->process($verification, $request->user(), $data['note'] ?? null));
    }

    public function offerAlternative(Request $request, NpbgVerification $verification): NpbgVerificationResource
    {
        $data = $request->validate([
            'deskripsi_alternatif' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'string'],
        ]);

        return $this->fresh($this->service->offerAlternative(
            $verification, $request->user(), $data['deskripsi_alternatif'], $data['note'] ?? null, $data['attachment'] ?? null
        ));
    }

    public function respond(Request $request, NpbgVerification $verification): NpbgVerificationResource
    {
        $data = $request->validate([
            'decision' => ['required', 'in:ACCEPT,REJECT'],
            'reason' => ['required_if:decision,REJECT', 'nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'string'],
        ]);

        return $this->fresh($this->service->respond(
            $verification, $request->user(), $data['decision'], $data['reason'] ?? null, $data['attachment'] ?? null
        ));
    }

    public function escalate(Request $request, NpbgVerification $verification): NpbgVerificationResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->fresh($this->service->escalate($verification, $request->user(), $data['note'] ?? null));
    }

    public function bosDecide(Request $request, NpbgVerification $verification): NpbgVerificationResource
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:DISETUJUI,DITOLAK'],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'string'],
        ]);

        return $this->fresh($this->service->bosDecide(
            $verification, $request->user(), $data['keputusan'], $data['catatan'] ?? null, $data['attachment'] ?? null
        ));
    }

    public function attachment(NpbgVerificationLog $log): StreamedResponse
    {
        abort_unless($log->attachment_path && Storage::disk('local')->exists($log->attachment_path), 404);

        return Storage::disk('local')->response($log->attachment_path);
    }

    private function fresh(NpbgVerification $verification): NpbgVerificationResource
    {
        return new NpbgVerificationResource(
            $verification->load(['openedBy', 'respondedBy', 'decidedBy', 'logs.actor'])
        );
    }
}
