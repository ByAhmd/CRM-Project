<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Support\RecordLabel;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A note on a lead, a contact, an account or a deal (decision A-10).
 *
 * Plain text with newlines preserved; authored, pinnable and edited in place.
 * The subject columns are written by NoteService only (at least one is
 * required); every body change is kept in the audit ledger by LogsActivity
 * and the pin toggles are audited by the service on top.
 *
 * @property bool $is_pinned
 * @property ?Carbon $edited_at
 */
#[Fillable(['body', 'author_id', 'lead_id', 'contact_id', 'account_id', 'deal_id', 'is_pinned', 'edited_at'])]
final class Note extends Model
{
    /** The body limit, enforced by NoteService and mirrored by the form field. */
    public const int MAX_BODY_LENGTH = 5000;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::NoteUpdated->logName())
            ->logOnly(['body', 'is_pinned'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::NoteCreated->value,
            'deleted' => ActivityLogEvent::NoteDeleted->value,
            'restored' => ActivityLogEvent::NoteRestored->value,
            default => ActivityLogEvent::NoteUpdated->value,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * The record the note was written on: the most specific subject first, so
     * a contact or deal note resolves to the contact or deal rather than to
     * the account copied alongside it. Trashed subjects still count: the owner
     * who opens a soft-deleted record keeps reading and tidying its notes.
     * Null only when every subject has been removed for good.
     */
    public function subjectRecord(): (Model&OwnedRecord)|null
    {
        return $this->lead()->withTrashed()->first()
            ?? $this->contact()->withTrashed()->first()
            ?? $this->deal()->withTrashed()->first()
            ?? $this->account()->withTrashed()->first();
    }

    public function subjectLabel(): string
    {
        $subject = $this->subjectRecord();

        return $subject === null ? '' : RecordLabel::of($subject);
    }

    /** The opening of the body with whitespace collapsed, for tables and audit rows. */
    public function excerpt(int $chars = 80): string
    {
        return Str::limit(Str::squish($this->body), $chars);
    }

    public function isAuthoredBy(User $user): bool
    {
        return $this->author_id !== null && (int) $this->author_id === (int) $user->getKey();
    }
}
