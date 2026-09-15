<?php

declare(strict_types=1);

namespace App\Filament\Imports\Concerns;

use App\Contracts\OwnedRecord;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Support\ImportExportActions;
use App\Models\Tag;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use BackedEnum;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lookups every CSV importer needs (module 18, decisions D-4, A-4).
 *
 * A CSV names things the way people write them: a status by its Arabic or
 * English name, a user by email, an enum by its value or its label, tags as a
 * pipe-separated list. Each resolver trims, compares case-insensitively (the
 * name and email columns use case-insensitive collations, so the indexed
 * columns are compared as they are, never wrapped in LOWER()) and
 * either returns the row or fails the CSV row with a translated reason — a
 * failed row never stops the import, it lands in failed_import_rows.
 *
 * Scope (D-4): users are resolved inside the importing user's reach
 * (RecordVisibilityResolver::assignableUsers), records inside what the
 * importing user may see (RecordVisibilityResolver::visible). Records outside
 * that scope are invisible to the importer, so they are neither updated nor
 * reported as duplicates.
 *
 * Authorisation (D-3, D-4): Filament's Importer performs no per-record
 * authorisation, so the policy verbs are applied here, exactly as the panel
 * applies them — a row creates only when the importer may `create` the
 * entity, updates a duplicate only when the importer may `update` that
 * record, and names another owner only when the importer holds the
 * entity's `assign` verb. A refused row lands in failed_import_rows with
 * the translated reason; nothing is written.
 *
 * Reassignment (D-4, A-20): a new record is simply created for the owner the
 * row names, as the create pages do. A row that names another owner for an
 * EXISTING record is a change of owner, so it is never written as a column:
 * the owner is kept aside while the row's other values are saved and then
 * handed to RecordAssignmentService inside the same savepoint, which writes
 * the `{entity}.assigned` audit event and notifies the new owner. A target
 * the service refuses fails the row and rolls the whole row back.
 *
 * The importing user is the run's owner: ImportCsv authenticates the job as
 * that user before any row is processed, so audit rows carry the right causer.
 */
trait ResolvesImportLookups
{
    public const DUPLICATE_SKIP = 'skip';

    public const DUPLICATE_UPDATE = 'update';

    /**
     * Tag ids resolved while filling the current row, synced after the save;
     * null while the row carries no tags cell (an unmapped or blank tags
     * column leaves an updated record's tags alone).
     *
     * @var list<int>|null
     */
    protected ?array $pendingTagIds = null;

    /**
     * The new owner an existing record's owner cell names, handed to
     * RecordAssignmentService once the row is saved; null while the row
     * changes no owner.
     */
    protected ?User $pendingOwner = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): void
    {
        ImportExportActions::applyLocale($this->options);

        $this->pendingTagIds = null;
        $this->pendingOwner = null;

        parent::__invoke($data);
    }

    /**
     * Saves the row and, when its owner cell reassigns an existing record,
     * hands that change to RecordAssignmentService (audit + notification,
     * D-4, A-20). Both run in one savepoint inside the chunk's transaction,
     * so a refused reassignment leaves nothing of the row behind.
     */
    public function saveRecord(): void
    {
        DB::transaction(function (): void {
            parent::saveRecord();

            $this->reassignPendingOwner();
        });
    }

    /** The duplicate-strategy option every importer offers (skip by default). */
    public static function duplicateStrategySelect(): Select
    {
        return Select::make('duplicate_strategy')
            ->label(__('imports.fields.duplicate_strategy'))
            ->helperText(__('imports.helpers.duplicate_strategy'))
            ->options([
                self::DUPLICATE_SKIP => __('imports.options.duplicate_strategy.skip'),
                self::DUPLICATE_UPDATE => __('imports.options.duplicate_strategy.update'),
            ])
            ->default(self::DUPLICATE_SKIP)
            ->required()
            ->native(false);
    }

    protected function importingUser(): User
    {
        $user = $this->import->user;

        assert($user instanceof User);

        return $user;
    }

    protected function updatesDuplicates(): bool
    {
        return ($this->options['duplicate_strategy'] ?? self::DUPLICATE_SKIP) === self::DUPLICATE_UPDATE;
    }

    /**
     * An existing record inside the importer's scope: returned when the run
     * updates duplicates and the importer may update that record (policy
     * `update`), refused with the record's id otherwise.
     *
     * @template TModel of Model
     *
     * @param  TModel|null  $existing
     * @return TModel|null
     */
    protected function resolveDuplicate(?Model $existing): ?Model
    {
        if ($existing === null) {
            return null;
        }

        $id = (string) $existing->getKey();

        if (! $this->updatesDuplicates()) {
            $this->failRow('duplicate', ['id' => $id]);
        }

        if (! $this->importingUser()->can('update', $existing)) {
            $this->failRow('update_forbidden', ['id' => $id]);
        }

        return $existing;
    }

    /**
     * Refuses the row unless the importer may create the entity (policy
     * `create`); called before a new model is built for the row.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertMayCreate(string $model): void
    {
        if (! $this->importingUser()->can('create', $model)) {
            $this->failRow('create_forbidden');
        }
    }

    /**
     * The records of an owned entity the importing user may see (D-4); the
     * query's model must implement OwnedRecord.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function visible(Builder $query): Builder
    {
        return app(RecordVisibilityResolver::class)->visible($this->importingUser(), $query);
    }

    /**
     * A bilingual lookup row (lead status, source, industry, pipeline, …) by
     * its Arabic or English name. Blank resolves to null; an unknown name
     * fails the row.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Builder<TModel>|null  $query  a narrower query (active rows, one pipeline, …)
     * @return TModel|null
     */
    protected function lookup(string $model, ?string $name, ?Builder $query = null): ?Model
    {
        $raw = Str::squish((string) $name);
        $name = self::normaliseName($name);

        if ($name === null) {
            return null;
        }

        $record = self::whereNamed($query ?? $model::query(), $name)->first();

        if ($record === null) {
            $this->failRow('unknown_lookup', ['value' => $raw]);
        }

        return $record;
    }

    /**
     * Matches the Arabic or the English name. The columns are compared as
     * they are — their case-insensitive collation already ignores case — so
     * the lookups' name indexes serve the query on every imported row.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function whereNamed(Builder $query, string $name): Builder
    {
        return $query->where(function (Builder $nested) use ($name): void {
            $nested->where($nested->qualifyColumn('name_ar'), $name)
                ->orWhere($nested->qualifyColumn('name_en'), $name);
        });
    }

    /** Trimmed, whitespace-squished, lower-cased; null when blank. */
    protected static function normaliseName(?string $name): ?string
    {
        $name = Str::squish((string) $name);

        return $name === '' ? null : Str::lower($name);
    }

    /**
     * A user by email, within the importing user's reach for the entity
     * (RecordVisibilityResolver::assignableUsers). Blank resolves to null; an
     * email outside reach fails the row, and so does any user other than the
     * importer when the importer lacks the entity's `assign` verb — a rep
     * cannot hand records to someone they could not assign to in the panel
     * (D-4).
     */
    protected function resolveOwner(?string $email, string $permissionGroup): ?User
    {
        $email = Str::lower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        $importer = $this->importingUser();

        // The raw column (case-insensitive collation) keeps users_email_unique usable.
        $user = app(RecordVisibilityResolver::class)
            ->assignableUsers($importer, $permissionGroup)
            ->where('email', $email)
            ->first();

        if (! $user instanceof User) {
            $this->failRow('owner_out_of_reach', ['value' => $email]);
        }

        if ((int) $user->getKey() !== (int) $importer->getKey() && ! $importer->can($permissionGroup.'.assign')) {
            $this->failRow('owner_out_of_reach', ['value' => $email]);
        }

        return $user;
    }

    /**
     * Fills the owner column of a new record with the user the cell names
     * (resolveOwner() checks reach and the `assign` verb). For an existing
     * record the owner is kept for reassignPendingOwner(): a change of owner
     * is never written straight to the column (D-4, A-20).
     */
    protected function fillOwner(Model&OwnedRecord $record, ?string $email): void
    {
        $owner = $this->resolveOwner($email, $record::permissionGroup());

        if ($owner === null) {
            return;
        }

        if ($record->exists) {
            $currentOwnerId = $record->getAttribute($record::ownerColumn());

            if ($currentOwnerId !== null && (int) $currentOwnerId === (int) $owner->getKey()) {
                return; // the row names the owner the record already has
            }

            // A change of owner on this record is the policy's `assign` verb,
            // exactly as the panel's owner field and assign actions require.
            if (! $this->importingUser()->can('assign', $record)) {
                $this->failRow('owner_out_of_reach', ['value' => (string) $owner->email]);
            }

            $this->pendingOwner = $owner;

            return;
        }

        $record->setAttribute($record::ownerColumn(), $owner->getKey());
    }

    /**
     * Reassigns the saved record to the owner its row named, through
     * RecordAssignmentService with the importing user as the actor; an
     * unchanged owner is left alone by the service. A refused target fails
     * the row.
     */
    protected function reassignPendingOwner(): void
    {
        $owner = $this->pendingOwner;
        $this->pendingOwner = null;
        $record = $this->record;

        if (! $owner instanceof User || ! $record instanceof OwnedRecord) {
            return;
        }

        try {
            app(RecordAssignmentService::class)->assign($record, $owner, $this->importingUser());
        } catch (UnassignableUserException) {
            $this->failRow('owner_out_of_reach', ['value' => (string) $owner->email]);
        }
    }

    /**
     * A backed enum case by its value or by its label in any configured
     * locale (a CSV written in Arabic names "مرتفع", one written in English
     * "High", an exported file "high"). Blank resolves to null.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    protected function resolveEnum(string $enum, ?string $value): ?BackedEnum
    {
        $needle = self::normaliseName($value);

        if ($needle === null) {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if (Str::lower((string) $case->value) === $needle) {
                return $case;
            }

            if (! $case instanceof HasLabel) {
                continue;
            }

            foreach (self::labelsInEveryLocale($case) as $label) {
                if ($label === $needle) {
                    return $case;
                }
            }
        }

        $this->failRow('unknown_lookup', ['value' => Str::squish((string) $value)]);
    }

    /**
     * The case's label rendered in every configured locale, normalised.
     *
     * @return list<string>
     */
    private static function labelsInEveryLocale(HasLabel $case): array
    {
        $current = app()->getLocale();
        $labels = [];

        try {
            foreach ((array) config('app.locales') as $locale) {
                app()->setLocale((string) $locale);

                $label = self::normaliseName((string) $case->getLabel());

                if ($label !== null) {
                    $labels[] = $label;
                }
            }
        } finally {
            app()->setLocale($current);
        }

        return $labels;
    }

    /**
     * Existing active tags by name, pipe-separated; an unknown or inactive
     * tag fails the row so the file is corrected rather than silently
     * trimmed. Blank resolves to no tags.
     *
     * @return Collection<int, Tag>
     */
    protected function resolveTags(?string $names): Collection
    {
        $tags = new Collection;

        foreach (explode('|', (string) $names) as $raw) {
            $raw = Str::squish($raw);
            $name = self::normaliseName($raw);

            if ($name === null) {
                continue;
            }

            $tag = self::whereNamed(Tag::query()->where('is_active', true), $name)->first();

            if (! $tag instanceof Tag) {
                $this->failRow('unknown_lookup', ['value' => $raw]);
            }

            $tags->put((int) $tag->getKey(), $tag);
        }

        return $tags->values();
    }

    /** Remembers the row's tags for syncTags() once the record has a key. */
    protected function rememberTags(?string $names): void
    {
        $this->pendingTagIds = $this->resolveTags($names)
            ->map(fn (Tag $tag): int => (int) $tag->getKey())
            ->all();
    }

    /** Syncs the row's tags on the saved record (afterSave hook). */
    protected function syncTags(): void
    {
        $record = $this->record;

        if ($this->pendingTagIds === null || $record === null || ! $record->exists || ! method_exists($record, 'tags')) {
            return;
        }

        $record->tags()->sync($this->pendingTagIds);
        $this->pendingTagIds = null;
    }

    /**
     * The completion notification body: each count with its own plural form
     * (Arabic has six, English two), the failed part only when rows failed.
     * The caller applies the run's locale first.
     */
    protected static function completedNotificationBody(Import $import): string
    {
        $successful = (int) $import->successful_rows;
        $failed = (int) $import->getFailedRowsCount();

        $body = trans_choice('imports.notifications.completed', $successful, ['count' => (string) $successful]);

        if ($failed > 0) {
            $body .= ' '.trans_choice('imports.notifications.failed', $failed, ['count' => (string) $failed]);
        }

        return $body;
    }

    /**
     * Fails the current CSV row with a translated reason; the row lands in
     * failed_import_rows and the import carries on.
     *
     * @param  array<string, string>  $replace
     */
    protected function failRow(string $reason, array $replace = []): never
    {
        throw new RowImportFailedException(__('imports.validation.'.$reason, $replace));
    }
}
