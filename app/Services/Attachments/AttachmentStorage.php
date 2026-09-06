<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Exceptions\Attachments\InvalidAttachmentException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\Activitylog\CauserResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Every rule about files (module row 13, decision D-13).
 *
 * Uploads are validated on the bytes, never on what the browser claims: the
 * MIME type is sniffed with finfo on the real file, checked against
 * `crm.attachments.allowed_mime_types`, the size against
 * `crm.attachments.max_kb`. libmagic builds differ between hosts, so the
 * sniff gets a second opinion for the Office container types only (see
 * resolveMime()): an OOXML file that libmagic reports as a bare zip must
 * prove itself through its `[Content_Types].xml`, a legacy OLE document is
 * accepted under the type its extension claims, and CSV aliases collapse to
 * text/csv. Accepted files land on the private disk at
 * `crm/{entity}/{id}/{uuid}.{ext}` with the extension derived from the
 * sniffed type, so a renamed executable can neither pass nor keep its name.
 *
 * Deletion is soft and keeps the file (D-13); forceDelete() removes both and
 * has no UI — it exists for a future super-admin prune. Streaming goes
 * through the disk (never a public URL) and is audited per download.
 *
 * The actor is set as the audit causer around every write so the ledger
 * names the uploader even when the service runs outside a request.
 */
final class AttachmentStorage
{
    /**
     * The extension the sniffed MIME type is stored under.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * What libmagic reports for an OOXML package when its magic database
     * predates the format: the zip container itself, or nothing at all.
     *
     * @var list<string>
     */
    private const OOXML_CONTAINER_TYPES = ['application/zip', 'application/octet-stream'];

    /**
     * What libmagic reports for a legacy OLE2 (.doc/.xls) document.
     *
     * @var list<string>
     */
    private const OLE_CONTAINER_TYPES = ['application/CDFV2', 'application/vnd.ms-office', 'application/x-ole-storage'];

    /**
     * The OOXML part that must be declared in `[Content_Types].xml` for the
     * extension to be honoured, and the MIME type it resolves to.
     *
     * @var array<string, array{part: string, mime: string}>
     */
    private const OOXML_PARTS = [
        'docx' => ['part' => '/word/document.xml', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['part' => '/xl/workbook.xml', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    /** @var array<string, string> */
    private const OLE_TYPES = [
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
    ];

    /**
     * Extensions never kept from a client name when the MIME map has no entry.
     *
     * @var list<string>
     */
    private const UNSAFE_EXTENSIONS = [
        'php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'js', 'mjs', 'svg', 'xml',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'sh', 'ps1', 'jar', 'vbs', 'scr',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * Validate and store a file against a record.
     *
     * Accepts an UploadedFile (a request upload, or one built from a path)
     * or the path of a temporary file already on the attachments disk, as
     * Filament's FileUpload leaves it. The temporary file is copied, not
     * moved: the caller removes it once the row exists.
     */
    public function store(UploadedFile|string $file, Model $attachable, User $uploader, ?string $description = null): Attachment
    {
        $diskName = $this->diskName();
        $disk = Storage::disk($diskName);

        $source = $file instanceof UploadedFile ? $this->fromUpload($file) : $this->fromDiskPath($disk, $file);

        $mime = $this->resolveMime($this->sniff($source['real_path']), $source['real_path'], $source['name']);

        if (! in_array($mime, $this->allowedMimeTypes(), true)) {
            throw InvalidAttachmentException::mimeNotAllowed($mime);
        }

        $maxBytes = $this->maxBytes();

        if ($source['size'] > $maxBytes) {
            throw InvalidAttachmentException::tooLarge(Number::fileSize($maxBytes, maxPrecision: 1));
        }

        $uuid = (string) Str::uuid();
        $extension = $this->extensionFor($mime, $source['name']);
        $path = sprintf('crm/%s/%s/%s.%s', $this->entity($attachable), $attachable->getKey(), $uuid, $extension);

        return $this->asCauser($uploader, fn (): Attachment => DB::transaction(function () use ($disk, $diskName, $source, $path, $uuid, $mime, $attachable, $uploader, $description, $extension): Attachment {
            $this->write($disk, $source['real_path'], $path);

            try {
                return Attachment::query()->create([
                    'uuid' => $uuid,
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'disk' => $diskName,
                    'path' => $path,
                    'original_name' => $this->sanitiseName($source['name'], $extension),
                    'mime_type' => $mime,
                    'size' => $source['size'],
                    'description' => $this->normaliseDescription($description),
                    'uploaded_by' => $uploader->getKey(),
                ]);
            } catch (Throwable $exception) {
                $disk->delete($path);

                throw $exception;
            }
        }));
    }

    /** Soft delete: the row is hidden, the file stays (D-13). */
    public function delete(Attachment $attachment, User $actor): void
    {
        $this->asCauser($actor, fn (): bool => DB::transaction(fn (): bool => (bool) $attachment->delete()));
    }

    public function restore(Attachment $attachment, User $actor): void
    {
        $this->asCauser($actor, fn (): bool => DB::transaction(fn (): bool => (bool) $attachment->restore()));
    }

    /**
     * Remove the row and, through AttachmentObserver, the file. Not exposed
     * in the UI: reserved for a future super-admin prune.
     */
    public function forceDelete(Attachment $attachment, User $actor): void
    {
        $this->asCauser($actor, fn (): bool => DB::transaction(fn (): bool => (bool) $attachment->forceDelete()));
    }

    public function exists(Attachment $attachment): bool
    {
        return Storage::disk($attachment->disk)->exists($attachment->path);
    }

    /**
     * Stream the file from the disk under its original name — inline for
     * images and PDFs, as a download otherwise — and audit the download.
     * The actor defaults to the authenticated user.
     */
    public function stream(Attachment $attachment, ?User $actor = null): StreamedResponse
    {
        $response = Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            $attachment->isInline() ? 'inline' : 'attachment',
        );

        $this->audit->record(ActivityLogEvent::AttachmentDownloaded, $attachment, $actor, [
            'subject_label' => $attachment->original_name,
            'downloaded_by' => $actor?->getKey() ?? auth()->id(),
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
        ]);

        return $response;
    }

    /**
     * @return array{real_path: string, name: string, size: int}
     */
    private function fromUpload(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();

        if (! $file->isValid() || $realPath === false) {
            throw InvalidAttachmentException::fileMissing();
        }

        return [
            'real_path' => $realPath,
            'name' => $file->getClientOriginalName(),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * @return array{real_path: string, name: string, size: int}
     */
    private function fromDiskPath(Filesystem $disk, string $path): array
    {
        if (! $disk->exists($path)) {
            throw InvalidAttachmentException::fileMissing();
        }

        return [
            'real_path' => $disk->path($path),
            'name' => Str::afterLast(str_replace('\\', '/', $path), '/'),
            'size' => (int) $disk->size($path),
        ];
    }

    private function sniff(string $realPath): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * Reconcile the sniff with what the name claims, for the container types
     * only. The sniff stays the authority: an OOXML claim is honoured only
     * when the zip's `[Content_Types].xml` declares the Word or Excel part,
     * an OLE claim only when the bytes are an OLE2 container, and a CSV alias
     * (`application/csv`, or `text/plain` under a .csv name) becomes text/csv.
     * Anything else keeps the sniffed type and falls to the allowlist.
     */
    private function resolveMime(string $sniffed, string $realPath, string $originalName): string
    {
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($sniffed === 'application/csv' || ($sniffed === 'text/plain' && $extension === 'csv')) {
            return 'text/csv';
        }

        if (in_array($sniffed, self::OOXML_CONTAINER_TYPES, true) && isset(self::OOXML_PARTS[$extension])) {
            $expected = self::OOXML_PARTS[$extension];

            return $this->declaresOoxmlPart($realPath, $expected['part']) ? $expected['mime'] : $sniffed;
        }

        if (in_array($sniffed, self::OLE_CONTAINER_TYPES, true) && isset(self::OLE_TYPES[$extension])) {
            return self::OLE_TYPES[$extension];
        }

        return $sniffed;
    }

    /** True when the file is a zip whose `[Content_Types].xml` names the given part. */
    private function declaresOoxmlPart(string $realPath, string $part): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($realPath, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $contentTypes = $zip->getFromName('[Content_Types].xml');

            return is_string($contentTypes)
                && str_contains($contentTypes, 'PartName="'.$part.'"')
                && $zip->locateName(ltrim($part, '/')) !== false;
        } finally {
            $zip->close();
        }
    }

    private function write(Filesystem $disk, string $realPath, string $path): void
    {
        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw InvalidAttachmentException::fileMissing();
        }

        try {
            $written = $disk->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw InvalidAttachmentException::storageFailed();
        }
    }

    /**
     * The storage folder per subject: the permission group of an owned
     * record (`lead`, `deal`, …), else the lower-cased class basename.
     */
    private function entity(Model $attachable): string
    {
        return $attachable instanceof OwnedRecord
            ? $attachable::permissionGroup()
            : Str::lower(class_basename($attachable));
    }

    private function extensionFor(string $mime, string $originalName): string
    {
        if (isset(self::EXTENSIONS[$mime])) {
            return self::EXTENSIONS[$mime];
        }

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 && ! in_array($extension, self::UNSAFE_EXTENSIONS, true)) {
            return $extension;
        }

        return 'bin';
    }

    /** The client name reduced to a safe basename of at most 255 characters. */
    private function sanitiseName(string $name, string $extension): string
    {
        $name = Str::afterLast(str_replace('\\', '/', trim($name)), '/');
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name), " .\t");

        if ($name === '') {
            $name = 'attachment.'.$extension;
        }

        return mb_substr($name, 0, 255);
    }

    private function normaliseDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : mb_substr($description, 0, 255);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asCauser(User $actor, Closure $callback): mixed
    {
        $this->causers->setCauser($actor);

        try {
            return $callback();
        } finally {
            $this->causers->setCauser(null);
        }
    }

    private function diskName(): string
    {
        return (string) config('crm.attachments.disk');
    }

    /**
     * @return list<string>
     */
    private function allowedMimeTypes(): array
    {
        return array_values((array) config('crm.attachments.allowed_mime_types'));
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('crm.attachments.max_kb')) * 1024;
    }
}
