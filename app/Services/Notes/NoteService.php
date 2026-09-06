<?php

declare(strict_types=1);

namespace App\Services\Notes;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Notifications\NoteMentionNotification;
use App\Services\Access\RecordVisibilityResolver;
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
 *
 * Mentions are explicit (plan section 3.6): create() and update() accept the
 * ids of the users the author named. Only users the actor could assign the
 * subject to (RecordVisibilityResolver, D-4) who may themselves view the
 * subject are kept — anyone else is dropped silently — and each of them is
 * told once the transaction has committed. Mentions are not persisted: there
 * is no mentions table, the NoteMentionNotification is the record.
 */
final class NoteService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CauserResolver $causers,
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @param  list<int>  $mentionUserIds
     */
    public function create(Model $subject, User $author, string $body, bool $pinned = false, array $mentionUserIds = []): Note
    {
        $body = $this->cleanBody($body);
        $foreignKeys = $this->foreignKeysFor($subject);
        $mentioned = $this->mentionableUsers($subject, $author, $mentionUserIds);

        return $this->asCauser($author, fn (): Note => DB::transaction(function () use ($author, $body, $pinned, $foreignKeys, $mentioned): Note {
            $note = new Note([
                'body' => $body,
                'author_id' => $author->getKey(),
                'is_pinned' => $pinned,
                ...$foreignKeys,
            ]);
            $note->save();

            $this->notifyMentioned($note, $author, $mentioned);

            return $note;
        }));
    }

    /**
     * @param  list<int>  $mentionUserIds
     */
    public function update(Note $note, User $actor, string $body, array $mentionUserIds = []): Note
    {
        $this->assertEditable($note);
        $body = $this->cleanBody($body);
        $subject = $note->subjectRecord();
        $mentioned = $subject === null ? [] : $this->mentionableUsers($subject, $actor, $mentionUserIds);

        return $this->asCauser($actor, fn (): Note => DB::transaction(function () use ($note, $actor, $body, $mentioned): Note {
            if ($body !== $note->body) {
                $note->body = $body;
                $note->edited_at = now();
                $note->save();
            }

            $this->notifyMentioned($note, $actor, $mentioned);

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
     * The users among the given ids the actor may mention on the subject:
     * within the actor's assignment reach for the subject's permission group,
     * able to view the subject themselves, and never the actor. Ids outside
     * that set are dropped without complaint.
     *
     * @param  list<int>  $userIds
     * @return list<User>
     */
    private function mentionableUsers(Model $subject, User $actor, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0 && $id !== (int) $actor->getKey(),
        )));

        if ($ids === [] || ! $subject instanceof OwnedRecord) {
            return [];
        }

        return $this->visibility->assignableUsers($actor, $subject::permissionGroup())
            ->whereKey($ids)
            ->get()
            ->filter(static fn (User $user): bool => $user->can('view', $subject))
            ->values()
            ->all();
    }

    /**
     * @param  list<User>  $mentioned
     */
    private function notifyMentioned(Note $note, User $actor, array $mentioned): void
    {
        if ($mentioned === []) {
            return;
        }

        DB::afterCommit(static function () use ($note, $actor, $mentioned): void {
            foreach ($mentioned as $user) {
                $user->notify((new NoteMentionNotification($note, $actor))->locale($user->preferredLocale()));
            }
        });
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
