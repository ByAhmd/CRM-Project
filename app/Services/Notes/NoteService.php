<?php

declare(strict_types=1);

namespace App\Services\Notes;

use App\Enums\ActivityLogEvent;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\CauserResolver;

/**
 * Every write to a note (decision A-10): create on one of the four subjects,
 * edit in place with `edited_at` stamped, pin and unpin, soft delete and
 * restore.
 *
 * The subject columns are set here and nowhere else: a contact or deal note
 * also carries the account so the account page lists it. Attribute changes
 * reach the ledger through LogsActivity on the model, written as the given
 * actor (not whoever happens to be authenticated); the pin toggles are
 * audited on top with the note's excerpt as the subject label, so the audit
 * screen names the note rather than showing a bare boolean diff.
 */
final class NoteService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CauserResolver $causers,
    ) {}

    public function create(Model $subject, User $author, string $body, bool $pinned = false): Note
    {
        $body = $this->cleanBody($body);
        $foreignKeys = $this->foreignKeysFor($subject);

        return $this->asCauser($author, fn (): Note => DB::transaction(function () use ($author, $body, $pinned, $foreignKeys): Note {
            $note = new Note([
                'body' => $body,
                'author_id' => $author->getKey(),
                'is_pinned' => $pinned,
                ...$foreignKeys,
            ]);
            $note->save();

            return $note;
        }));
    }

    public function update(Note $note, User $actor, string $body): Note
    {
        $this->assertEditable($note);
        $body = $this->cleanBody($body);

        return $this->asCauser($actor, fn (): Note => DB::transaction(function () use ($note, $body): Note {
            if ($body === $note->body) {
                return $note;
            }

            $note->body = $body;
            $note->edited_at = now();
            $note->save();

            return $note;
        }));
    }

    public function pin(Note $note, User $actor): Note
    {
        return $this->setPinned($note, $actor, true);
    }

    public function unpin(Note $note, User $actor): Note
    {
        return $this->setPinned($note, $actor, false);
    }

    public function delete(Note $note, User $actor): void
    {
        $this->asCauser($actor, function () use ($note): void {
            DB::transaction(function () use ($note): void {
                $note->delete();
            });
        });
    }

    public function restore(Note $note, User $actor): Note
    {
        return $this->asCauser($actor, fn (): Note => DB::transaction(function () use ($note): Note {
            $note->restore();

            return $note;
        }));
    }

    private function setPinned(Note $note, User $actor, bool $pinned): Note
    {
        $this->assertEditable($note);

        return $this->asCauser($actor, fn (): Note => DB::transaction(function () use ($note, $actor, $pinned): Note {
            if ($note->is_pinned === $pinned) {
                return $note;
            }

            $note->is_pinned = $pinned;
            $note->save();

            $this->audit->record(
                $pinned ? ActivityLogEvent::NotePinned : ActivityLogEvent::NoteUnpinned,
                $note,
                $actor,
                ['subject_label' => $note->excerpt()],
            );

            return $note;
        }));
    }

    /**
     * Runs the callback with the actor as the ledger causer, so the rows
     * LogsActivity writes name who acted even outside a web request, and
     * restores the default resolution afterwards.
     *
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

    /**
     * Trims the ends and normalises line endings; inner newlines are kept —
     * they are the only formatting a note has (no rich editor in v1). The
     * length limit lives here, not only in the form, so every caller obeys it.
     */
    private function cleanBody(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('notes.validation.body_required')]);
        }

        if (mb_strlen($body) > Note::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages(['body' => __('notes.validation.body_too_long', ['max' => Note::MAX_BODY_LENGTH])]);
        }

        return $body;
    }

    /**
     * @return array<string, int|null>
     */
    private function foreignKeysFor(Model $subject): array
    {
        return match (true) {
            $subject instanceof Lead => ['lead_id' => (int) $subject->getKey()],
            $subject instanceof Contact => ['contact_id' => (int) $subject->getKey(), 'account_id' => $this->nullableId($subject->account_id)],
            $subject instanceof Account => ['account_id' => (int) $subject->getKey()],
            $subject instanceof Deal => ['deal_id' => (int) $subject->getKey(), 'account_id' => $this->nullableId($subject->account_id)],
            default => throw new InvalidArgumentException(__('notes.validation.subject_unsupported')),
        };
    }

    private function nullableId(mixed $id): ?int
    {
        return $id === null ? null : (int) $id;
    }

    /**
     * A trashed note is restored before it is edited or pinned, and a note whose
     * every subject has been removed is orphaned and no longer editable.
     */
    private function assertEditable(Note $note): void
    {
        if ($note->trashed()) {
            throw new InvalidArgumentException(__('notes.validation.trashed'));
        }

        if ($note->lead_id === null && $note->contact_id === null && $note->account_id === null && $note->deal_id === null) {
            throw new InvalidArgumentException(__('notes.validation.subject_required'));
        }
    }
}
