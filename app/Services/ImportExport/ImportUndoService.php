<?php

declare(strict_types=1);

namespace App\Services\ImportExport;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Enums\DealStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\UndoImport;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealContact;
use App\Models\DealProduct;
use App\Models\DealStageLog;
use App\Models\Import;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Policies\ImportPolicy;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Audit\AuditLogger;
use App\Support\Filament\PanelUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Undoes an import run (decision D-29): soft-deletes the records the run
 * created — never the duplicates it updated, never anything created from
 * them afterwards — and only those nobody has touched since.
 *
 * What may be deleted is exactly the set of records carrying the run's id
 * (`import_id`, stamped by ResolvesImportLookups::saveRecord on creation
 * only). Each is counted once under the first reason that applies, in this
 * order: already deleted → touched since the run completed (touchedKeys()
 * holds the per-entity rules) → outside what the actor may delete under the
 * entity's own `delete` policy (deletableKeys()) → deletable. Every rule is
 * answered for the whole set with one query, never one query per record: a
 * run holds up to 5 000 records and preview() is shown in a modal.
 *
 * preview() reads and writes nothing. request() is the actor's confirmation:
 * it stamps `undo_requested_at` (the run shows «undoing») and queues the
 * first UndoImport batch after commit. undoBatch() is one job's unit of
 * work: it classifies BATCH_SIZE carried records again now (the state may
 * have moved since the modal), soft-deletes the deletable ones through the
 * model's own delete() while the actor is the authenticated user — so
 * LogsActivity writes `{entity}.deleted` with the actor as causer and
 * «السجلات المحذوفة» can restore any one of them — adds the batch's counts to
 * `undone_rows` / `kept_rows`, then queues the next batch or finalises: the
 * updated duplicates are added to `kept_rows` exactly once there (they never
 * carry the id, so they are counted as successful rows minus carried
 * records), making undone_rows + kept_rows === successful_rows; one
 * `import.undone` ledger entry records the act with every count, and the
 * actor gets a bell notice in the locale they confirmed in.
 *
 * Nothing else interferes with the soft delete: the four models' observers
 * watch saving (LeadObserver) and creating (DealObserver) only, the workflow
 * guard watches `updating` (a soft delete is a direct update query that
 * fires no `updating`), and no model concern hooks `deleting`.
 */
final class ImportUndoService
{
    /** Records classified and deleted per queued job (D-1: one batch must end inside the scheduler's drain). */
    public const int BATCH_SIZE = 200;

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly AuditLogger $audit,
    ) {}

    /** What undoing the run would do for this actor right now; reads only. */
    public function preview(Import $import, User $actor): ImportUndoPreview
    {
        $model = self::entityOf($import);

        if ($model === null || $import->completed_at === null) {
            return ImportUndoPreview::empty();
        }

        $query = $this->carried($model, $import);
        $records = $query->orderBy($query->getModel()->getQualifiedKeyName())->get();

        return $this->classify($import, $actor, $model, $records, $this->updatedCount($import, $records->count()));
    }

    /**
     * The actor confirmed the undo: refuses anyone ImportPolicy::undo refuses
     * (AuthorizationException), stamps the request on the locked row — a
     * second confirmation racing the first finds the stamp and is refused
     * the same way — and queues the first batch once the stamp is committed,
     * carrying the locale the actor confirmed in.
     */
    public function request(Import $import, User $actor): void
    {
        Gate::forUser($actor)->authorize('undo', $import);

        $locale = app()->getLocale();

        DB::transaction(function () use ($import, $actor, $locale): void {
            $locked = Import::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('undo', $locked);

            $locked->undo_requested_at = now();
            $locked->save();

            $import->setRawAttributes($locked->getAttributes(), true);

            UndoImport::dispatch((int) $locked->getKey(), (int) $actor->getKey(), $locale)->afterCommit();
        });
    }

    /**
     * One batch of the queued undo: the carried records after `$afterId`, in
     * key order, up to BATCH_SIZE of them. A run that is no longer undoing
     * (pruned, or already finished) is left alone.
     *
     * `$carried` holds the tallies of the batches before this one, handed
     * from job to job, so the final ledger entry can say how many records
     * were kept for each reason without a column per reason.
     *
     * @param  array{deleted?: int, touched?: int, forbidden?: int, already_deleted?: int}  $carried
     */
    public function undoBatch(Import $import, User $actor, int $afterId, array $carried = []): void
    {
        $model = self::entityOf($import);

        if ($model === null || $import->completed_at === null || ! $import->isUndoing()) {
            return;
        }

        $query = $this->carried($model, $import);
        $key = $query->getModel()->getQualifiedKeyName();
        $records = $query->where($key, '>', $afterId)->orderBy($key)->limit(self::BATCH_SIZE)->get();
        $batch = $this->classify($import, $actor, $model, $records, 0);
        $deletableKeys = array_fill_keys($batch->deletableIds, true);
        $deletable = $records->filter(static fn (Model $record): bool => isset($deletableKeys[(int) $record->getKey()]));

        // The deletions are audited as the actor's, the way ImportCsv runs the import as the importing user.
        auth()->setUser($actor);

        try {
            DB::transaction(function () use ($import, $deletable, $batch): void {
                foreach ($deletable as $record) {
                    $record->delete();
                }

                Import::query()->whereKey($import->getKey())->update([
                    'undone_rows' => DB::raw('undone_rows + '.$batch->deletable),
                    'kept_rows' => DB::raw('kept_rows + '.$batch->kept()),
                ]);
            });
        } finally {
            auth()->forgetGuards();
        }

        $tally = [
            'deleted' => ($carried['deleted'] ?? 0) + $batch->deletable,
            'touched' => ($carried['touched'] ?? 0) + $batch->touched,
            'forbidden' => ($carried['forbidden'] ?? 0) + $batch->forbidden,
            'already_deleted' => ($carried['already_deleted'] ?? 0) + $batch->alreadyDeleted,
        ];

        $last = $records->last();

        if ($records->count() === self::BATCH_SIZE && $last instanceof Model) {
            // The job has already switched the process to the actor's locale (UndoImport::handle), so the next batch inherits it.
            UndoImport::dispatch((int) $import->getKey(), (int) $actor->getKey(), app()->getLocale(), (int) $last->getKey(), $tally);

            return;
        }

        $this->finalise($import, $actor, $model, $tally);
    }

    /**
     * The last batch is done: the duplicates the run updated are counted
     * once, here, so undone_rows + kept_rows === successful_rows; the run is
     * stamped undone, the act goes to the ledger with every count, and the
     * actor is told.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @param  array{deleted: int, touched: int, forbidden: int, already_deleted: int}  $tally
     */
    private function finalise(Import $import, User $actor, string $model, array $tally): void
    {
        $updated = $this->updatedCount($import, $this->carried($model, $import)->count());

        DB::transaction(function () use ($import, $actor, $updated): void {
            Import::query()->whereKey($import->getKey())->update([
                'undone_at' => now(),
                'undone_by' => $actor->getKey(),
                'kept_rows' => DB::raw('kept_rows + '.$updated),
            ]);
        });

        $import->refresh();

        $this->audit->record(ActivityLogEvent::ImportUndone, $import, $actor, [
            'subject_label' => $import->file_name,
            'undone' => (int) $import->undone_rows,
            'kept' => (int) $import->kept_rows,
            'updated' => $updated,
            'touched' => $tally['touched'],
            'forbidden' => $tally['forbidden'],
            'already_deleted' => $tally['already_deleted'],
        ]);

        $this->notifyCompleted($import, $actor);
    }

    /**
     * The bell notice: what was deleted, what was kept (only when something
     * was), and a button to the run — shown only when the actor may enter
     * the Sales workspace (D-23), so the notice never carries a dead link.
     */
    private function notifyCompleted(Import $import, User $actor): void
    {
        $undone = (int) $import->undone_rows;
        $kept = (int) $import->kept_rows;

        $body = trans_choice('imports.notifications.undo_completed.undone', $undone, ['count' => (string) $undone]);

        if ($kept > 0) {
            $body .= ' '.trans_choice('imports.notifications.undo_completed.kept', $kept, ['count' => (string) $kept]);
        }

        $url = PanelUrl::forReader($actor, ImportResource::class, static fn (): string => ImportResource::getUrl('view', ['record' => $import]));

        Notification::make()
            ->title(__('imports.notifications.undo_completed.title'))
            ->body($body)
            ->success()
            ->actions($url === null ? [] : [
                Action::make('view')
                    ->label(__('imports.actions.view'))
                    ->url($url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($actor);
    }

    /**
     * Sorts the given carried records into the preview's buckets, each
     * record once, in the order already deleted → touched → forbidden →
     * deletable.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @param  Collection<int, Model>  $records
     */
    private function classify(Import $import, User $actor, string $model, Collection $records, int $updated): ImportUndoPreview
    {
        $since = $import->completed_at;

        if ($since === null) {
            throw new LogicException('An import that has not completed cannot be undone.');
        }

        $live = $records->filter(static fn (Model $record): bool => $record->getAttribute('deleted_at') === null);
        $alreadyDeleted = $records->count() - $live->count();

        $touchedKeys = $this->touchedKeys($model, $live, $since);
        $untouched = $live->reject(static fn (Model $record): bool => isset($touchedKeys[(int) $record->getKey()]));
        $touched = $live->count() - $untouched->count();

        $allowedKeys = $this->deletableKeys($actor, $model, $untouched);
        $deletableIds = [];

        foreach ($untouched as $record) {
            $key = (int) $record->getKey();

            if (isset($allowedKeys[$key])) {
                $deletableIds[] = $key;
            }
        }

        return new ImportUndoPreview(
            deletable: count($deletableIds),
            updated: $updated,
            touched: $touched,
            forbidden: $untouched->count() - count($deletableIds),
            alreadyDeleted: $alreadyDeleted,
            total: $records->count(),
            deletableIds: $deletableIds,
        );
    }

    /**
     * The keys of the given (live) records that were touched after `$since`,
     * the moment the run completed. The rules, per entity:
     *
     * Every entity —
     *  - any audit ledger entry whose subject is the record, written after
     *    the run completed (`activity_log.created_at`): an edit, a
     *    reassignment, a status or stage move, a merge, a pin, a conversion
     *    all leave one. The ledger is the authority; `updated_at` is not
     *    usable, the daily RescoreLeads bumps it on every open lead;
     *  - an activity, a note, a task or an attachment added to the record
     *    after the run completed (`created_at`; deleted children count too —
     *    the record was worked on).
     * Lead — a status log after the run (`lead_status_logs.changed_at`);
     *    a converted lead (`converted_at` set), whenever.
     * Deal — a stage log after the run (`deal_stage_logs.changed_at`); a
     *    product line after the run (`deal_products.created_at`); a contact
     *    attached after the run (`deal_contacts.created_at`); a closed deal
     *    (status won or lost), whenever. The import writes none of these
     *    children (DealImporter sets `deals.contact_id`, never the pivot);
     *    each child's moment is compared because every D-29 touch rule reads
     *    "after the run completed", like the rules above.
     * Account — any contact or deal of the account (`contacts.account_id`,
     *    `deals.account_id`) and any child account (`accounts.parent_account_id`),
     *    whenever it was created and deleted ones included. An Account run
     *    writes accounts only, so whatever names one of its accounts was linked
     *    to it afterwards — created under it, or an older record moved onto it
     *    from its form — and that link's ledger entry lands on the child, never
     *    on the account: existence is the touch, not the child's age.
     * Contact — any deal naming it as primary contact (`deals.contact_id`) and
     *    any lead converted into it (`leads.converted_contact_id`), whenever and
     *    deleted ones included, for the same reason: a Contact run writes
     *    contacts only, and the deal's or the lead's ledger entry never lands on
     *    the contact. Also a deal it was attached to after the run
     *    (`deal_contacts.created_at`).
     *
     * One query per rule over the whole set, never one per record.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @param  Collection<int, Model>  $records
     * @return array<int, true>
     */
    private function touchedKeys(string $model, Collection $records, Carbon $since): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        $ids = array_map(static fn (mixed $key): int => (int) $key, $records->modelKeys());
        $morph = (new $model)->getMorphClass();
        $column = self::childColumnOf($model);
        $touched = [];

        $mark = static function (iterable $keys) use (&$touched): void {
            foreach ($keys as $key) {
                $touched[(int) $key] = true;
            }
        };

        $mark(ActivityLog::query()->where('subject_type', $morph)->whereIn('subject_id', $ids)->where('created_at', '>', $since)->distinct()->pluck('subject_id'));
        $mark(Activity::query()->whereIn($column, $ids)->where('created_at', '>', $since)->distinct()->pluck($column));
        $mark(Note::withTrashed()->whereIn($column, $ids)->where('created_at', '>', $since)->distinct()->pluck($column));
        $mark(Task::withTrashed()->whereIn($column, $ids)->where('created_at', '>', $since)->distinct()->pluck($column));
        $mark(Attachment::withTrashed()->where('attachable_type', $morph)->whereIn('attachable_id', $ids)->where('created_at', '>', $since)->distinct()->pluck('attachable_id'));

        if ($model === Lead::class) {
            $mark(LeadStatusLog::query()->whereIn('lead_id', $ids)->where('changed_at', '>', $since)->distinct()->pluck('lead_id'));
            $mark($records->filter(static fn (Model $record): bool => $record->getAttribute('converted_at') !== null)->modelKeys());
        }

        if ($model === Deal::class) {
            $mark(DealStageLog::query()->whereIn('deal_id', $ids)->where('changed_at', '>', $since)->distinct()->pluck('deal_id'));
            $mark(DealProduct::query()->whereIn('deal_id', $ids)->where('created_at', '>', $since)->distinct()->pluck('deal_id'));
            $mark(DealContact::query()->whereIn('deal_id', $ids)->where('created_at', '>', $since)->distinct()->pluck('deal_id'));
            $mark($records->filter(static fn (Model $record): bool => $record->getAttribute('status') !== DealStatus::Open)->modelKeys());
        }

        if ($model === Account::class) {
            $mark(Contact::withTrashed()->whereIn('account_id', $ids)->distinct()->pluck('account_id'));
            $mark(Deal::withTrashed()->whereIn('account_id', $ids)->distinct()->pluck('account_id'));
            $mark(Account::withTrashed()->whereIn('parent_account_id', $ids)->distinct()->pluck('parent_account_id'));
        }

        if ($model === Contact::class) {
            $mark(Deal::withTrashed()->whereIn('contact_id', $ids)->distinct()->pluck('contact_id'));
            $mark(Lead::withTrashed()->whereIn('converted_contact_id', $ids)->distinct()->pluck('converted_contact_id'));
            $mark(DealContact::query()->whereIn('contact_id', $ids)->where('created_at', '>', $since)->distinct()->pluck('contact_id'));
        }

        return $touched;
    }

    /**
     * The keys of the given records the actor may delete under the entity's
     * own policy — ChecksPermissions::delete: the `{entity}.delete` key, then
     * the record inside the actor's write reach. The reach is answered for
     * the whole set through RecordVisibilityResolver::visible(), the same
     * rule the policy's per-record check applies (own / team / all, unowned
     * records from team level), as one query.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @param  Collection<int, Model>  $records
     * @return array<int, true>
     */
    private function deletableKeys(User $actor, string $model, Collection $records): array
    {
        if ($records->isEmpty() || ! $actor->can($model::permissionGroup().'.delete')) {
            return [];
        }

        $query = $model::query()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($records->modelKeys());
        $keys = $this->visibility->visible($actor, $query)->pluck($query->getModel()->getQualifiedKeyName());

        return array_fill_keys(array_map(static fn (mixed $key): int => (int) $key, $keys->all()), true);
    }

    /**
     * Every record carrying the run's id, soft-deleted ones included — the
     * only records an undo may touch. Updated duplicates never carry it
     * (ResolvesImportLookups::saveRecord), and a pruned run clears it.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     * @return Builder<Model>
     */
    private function carried(string $model, Import $import): Builder
    {
        return $model::query()->withoutGlobalScope(SoftDeletingScope::class)->where('import_id', $import->getKey());
    }

    /** The duplicates the run updated: its successful rows minus the records it created, which carry its id. */
    private function updatedCount(Import $import, int $carried): int
    {
        return max(0, (int) $import->successful_rows - $carried);
    }

    /**
     * The owned entity the run wrote, when its importer is one of ours and
     * writes an Eloquent model (ImportPolicy::entityOf); null otherwise.
     *
     * @return class-string<Model&OwnedRecord>|null
     */
    private static function entityOf(Import $import): ?string
    {
        $model = ImportPolicy::entityOf((string) $import->importer);

        if ($model === null || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        return $model;
    }

    /**
     * The foreign key an activity, a note or a task names the entity by.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     */
    private static function childColumnOf(string $model): string
    {
        return match ($model) {
            Lead::class => 'lead_id',
            Contact::class => 'contact_id',
            Account::class => 'account_id',
            Deal::class => 'deal_id',
            default => throw new LogicException(sprintf('%s is not an importable entity.', $model)),
        };
    }
}
