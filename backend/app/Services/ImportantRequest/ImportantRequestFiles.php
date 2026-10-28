<?php

namespace App\Services\ImportantRequest;

use App\Models\ImportantRequest;
use App\Models\ImportantRequestAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files of Important Request. The server's PHP takes at most 2 MB per uploaded file, so a file is sent
 * in chunks (raw bytes, up to 4 MB each) to a private temporary upload, then claimed by the request /
 * comment / submission it belongs to: only then it is checked (complete, size, extension, real type)
 * and moved into private storage under a random name. Files are never run nor served without an
 * authorization check (ImportantRequestController::download).
 */
class ImportantRequestFiles
{
    public const DISK = 'local';

    private const TMP = 'important-request-uploads';

    public const CHUNK_MAX = 4 * 1024 * 1024;

    /** extension => real types (finfo) it may have; office files are zip containers, old ones OLE */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
        '7z' => ['application/x-7z-compressed'],
    ];

    /** shown inline (preview) rather than downloaded */
    public const PREVIEW = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function maxBytes(): int
    {
        return max(1, (int) config('stockwise.important_request_max_mb', 20)) * 1024 * 1024;
    }

    /** Begin an upload: its name and size are checked now, its content when it is claimed. */
    public function start(User $user, string $name, int $size): string
    {
        $ext = $this->extension($name);
        if ($size <= 0 || $size > self::maxBytes()) {
            throw ValidationException::withMessages(['size' => 'Ukuran file maksimal '.config('stockwise.important_request_max_mb', 20).' MB.']);
        }
        $this->cleanup();
        $token = (string) Str::uuid();
        $this->writeMeta($token, ['user_id' => $user->id, 'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255), 'ext' => $ext, 'size' => $size, 'received' => 0, 'at' => now()->timestamp]);
        Storage::disk(self::DISK)->put(self::TMP."/{$token}.part", '');

        return $token;
    }

    /** One chunk, in order: offset must be what was received so far. */
    public function chunk(User $user, string $token, int $offset, string $bytes): array
    {
        $meta = $this->meta($user, $token);
        $len = strlen($bytes);
        if ($len === 0 || $len > self::CHUNK_MAX) {
            throw ValidationException::withMessages(['file' => 'Potongan file tidak valid.']);
        }
        if ($offset !== $meta['received'] || $meta['received'] + $len > $meta['size']) {
            throw ValidationException::withMessages(['file' => 'Urutan potongan file tidak sesuai, ulangi upload.']);
        }
        $fh = fopen(Storage::disk(self::DISK)->path(self::TMP."/{$token}.part"), 'ab');
        fwrite($fh, $bytes);
        fclose($fh);
        $meta['received'] += $len;
        $this->writeMeta($token, $meta);

        return ['received' => $meta['received'], 'size' => $meta['size'], 'complete' => $meta['received'] === $meta['size']];
    }

    /**
     * Claim finished uploads for a request (in a transaction): checked, moved to private storage, recorded.
     *
     * @param  list<string>  $tokens
     * @return list<ImportantRequestAttachment>
     */
    public function claim(User $user, array $tokens, ImportantRequest $request, string $kind, ?int $commentId = null, ?int $submissionId = null): array
    {
        if (count($tokens) > 10) {
            throw ValidationException::withMessages(['attachments' => 'Maksimal 10 file sekali kirim.']);
        }
        $made = [];
        foreach (array_unique($tokens) as $token) {
            $meta = $this->meta($user, (string) $token);
            if ($meta['received'] !== $meta['size']) {
                throw ValidationException::withMessages(['attachments' => "Upload {$meta['name']} belum selesai."]);
            }
            $tmp = self::TMP."/{$token}.part";
            $disk = Storage::disk(self::DISK);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($tmp)) ?: 'application/octet-stream';
            $this->assertType($meta['name'], $meta['ext'], $mime);
            $path = "important-requests/{$request->id}/".Str::uuid().'.'.$meta['ext'];
            $disk->move($tmp, $path);
            $disk->delete(self::TMP."/{$token}.json");
            $made[] = ImportantRequestAttachment::create([
                'important_request_id' => $request->id, 'comment_id' => $commentId, 'submission_id' => $submissionId, 'kind' => $kind,
                'original_name' => $meta['name'], 'path' => $path, 'size' => $meta['size'], 'mime' => $mime, 'extension' => $meta['ext'], 'uploaded_by' => $user->id,
            ]);
        }

        return $made;
    }

    private function extension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$ext])) {
            throw ValidationException::withMessages(['file' => "Tipe file .{$ext} tidak diizinkan. Boleh: ".implode(', ', array_keys(self::TYPES)).'.']);
        }

        return $ext;
    }

    /** The real content must fit its extension; anything runnable / scriptable is refused. */
    private function assertType(string $name, string $ext, string $mime): void
    {
        $danger = ['x-dosexec', 'x-msdownload', 'x-executable', 'x-msi', 'x-sh', 'x-php', 'javascript', 'html', 'svg', 'x-bat'];
        foreach ($danger as $d) {
            if (str_contains(strtolower($mime), $d)) {
                throw ValidationException::withMessages(['attachments' => "{$name} ditolak: isinya bukan dokumen yang diizinkan."]);
            }
        }
        if (! in_array($mime, self::TYPES[$ext], true)) {
            throw ValidationException::withMessages(['attachments' => "{$name} ditolak: isi file tidak sesuai dengan .{$ext} ({$mime})."]);
        }
    }

    private function meta(User $user, string $token): array
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $token), 404);
        $raw = Storage::disk(self::DISK)->get(self::TMP."/{$token}.json");
        $meta = $raw ? json_decode($raw, true) : null;
        if (! $meta || (int) $meta['user_id'] !== $user->id) {
            throw ValidationException::withMessages(['attachments' => 'Upload tidak ditemukan atau sudah kedaluwarsa, ulangi upload.']);
        }

        return $meta;
    }

    private function writeMeta(string $token, array $meta): void
    {
        Storage::disk(self::DISK)->put(self::TMP."/{$token}.json", json_encode($meta));
    }

    /** Uploads never claimed within a day are thrown away. */
    private function cleanup(): void
    {
        $disk = Storage::disk(self::DISK);
        foreach ($disk->files(self::TMP) as $f) {
            if ($disk->lastModified($f) < now()->subDay()->timestamp) {
                $disk->delete($f);
            }
        }
    }
}
