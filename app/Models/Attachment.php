<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityLogEvent;
use App\Observers\AttachmentObserver;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A file attached to a lead, contact, account or deal (module row 13, D-13).
 *
 * Written by AttachmentStorage only: the service sniffs the MIME type,
 * enforces the allowlist and the size limit and places the file on the
 * private disk. The uuid is the download route key; a soft-deleted row keeps
 * its file, a force delete removes it through AttachmentObserver.
 *
 * @property int $size
 */
#[Fillable([
    'uuid', 'attachable_type', 'attachable_id', 'disk', 'path',
    'original_name', 'mime_type', 'size', 'description', 'uploaded_by',
])]
#[ObservedBy(AttachmentObserver::class)]
final class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::AttachmentUploaded->logName())
            ->logOnly(['original_name', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::AttachmentUploaded->value,
            'deleted' => ActivityLogEvent::AttachmentDeleted->value,
            'restored' => ActivityLogEvent::AttachmentRestored->value,
            default => ActivityLogEvent::AttachmentUpdated->value,
        };
    }

    /**
     * The record the file belongs to, trashed subjects included so a policy
     * can still decide on an attachment of a soft-deleted lead.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The size in bytes as "1.5 KB" / "2 MB". */
    public function humanSize(): string
    {
        return Number::fileSize($this->size, maxPrecision: 1);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** Images and PDFs render in the browser; everything else is served as a download. */
    public function isInline(): bool
    {
        return $this->isImage() || $this->mime_type === 'application/pdf';
    }

    /**
     * The presentation family of the MIME type, keyed as in
     * `attachments.options.mime`: image, pdf, document, spreadsheet, text, other.
     */
    public function mimeFamily(): string
    {
        return self::familyOf($this->mime_type);
    }

    /**
     * The family a MIME type is presented under — shared by the badge and by
     * the upload helper, which lists the families the allowlist permits.
     */
    public static function familyOf(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            in_array($mime, [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ], true) => 'document',
            in_array($mime, [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
            ], true) => 'spreadsheet',
            str_starts_with($mime, 'text/') => 'text',
            default => 'other',
        };
    }

    public function downloadUrl(): string
    {
        return route('attachments.download', $this);
    }
}
