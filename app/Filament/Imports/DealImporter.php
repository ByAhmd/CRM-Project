<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\CustomFieldEntity;
use App\Enums\ForecastCategory;
use App\Enums\StageKind;
use App\Filament\Imports\Concerns\ResolvesImportLookups;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * CSV import of deals (module 18, decisions D-4, D-6, D-8).
 *
 * Every row is validated with the rules of the deal form. The account is
 * named and must be one the importer may see — a deal without an account
 * is refused; the primary contact by full name within that account; the
 * pipeline and stage by name in either language (default pipeline and
 * default Open stage when blank; a Won or Lost stage is refused, a stage of
 * another pipeline too); the source by name; users by email within the
 * importer's reach; tags by name. A duplicate is an existing deal inside
 * the importer's visible scope with the same title on the same account:
 * the run's duplicate strategy decides whether it is updated or the row is
 * refused. A new deal is owned by the importer unless the owner column
 * names someone else, carries the organisation currency and starts Open
 * (DealObserver). An existing deal keeps its pipeline and stage: stage
 * moves go through DealStageWorkflow only (D-8).
 */
final class DealImporter extends Importer
{
    use ResolvesImportLookups;

    protected static ?string $model = Deal::class;

    /** The account named by the current row, resolved once for the duplicate check and the fill. */
    private ?Account $rowAccount = null;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')
                ->label(__('imports.columns.deal.title'))
                ->requiredMapping()
                ->rules(['required', 'string', 'max:150'])
                ->example(__('imports.examples.deal.title')),

            ImportColumn::make('account')
                ->label(__('imports.columns.deal.account'))
                ->requiredMapping()
                ->rules(['required', 'string', 'max:150'])
                ->fillRecordUsing(function (self $importer, Deal $record): void {
                    if ($importer->rowAccount instanceof Account) {
                        $record->account_id = $importer->rowAccount->getKey();
                    }
                })
                ->example(__('imports.examples.deal.account')),

            ImportColumn::make('contact')
                ->label(__('imports.columns.deal.contact'))
                ->rules(['nullable', 'string', 'max:170'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Deal $record, ?string $state): void {
                    $record->contact_id = $importer->resolveContact($state)?->getKey();
                })
                ->example(__('imports.examples.deal.contact')),

            ImportColumn::make('pipeline')
                ->label(__('imports.columns.deal.pipeline'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Deal $record, ?string $state): void {
                    if ($record->exists) {
                        return; // stage moves go through DealStageWorkflow only (D-8)
                    }

                    $pipeline = $importer->lookup(Pipeline::class, $state, Pipeline::query()->where('is_active', true));

                    if ($pipeline instanceof Pipeline) {
                        $record->pipeline_id = $pipeline->getKey();
                    }
                })
                ->example(__('imports.examples.deal.pipeline')),

            ImportColumn::make('stage')
                ->label(__('imports.columns.deal.stage'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Deal $record, ?string $state): void {
                    if ($record->exists) {
                        return; // stage moves go through DealStageWorkflow only (D-8)
                    }

                    $stage = $importer->resolveStage($state, $importer->pipelineIdFor($record));

                    if ($stage instanceof PipelineStage) {
                        $record->stage_id = $stage->getKey();
                    }
                })
                ->example(__('imports.examples.deal.stage')),

            ImportColumn::make('amount')
                ->label(__('imports.columns.deal.amount'))
                ->numeric(decimalPlaces: 2)
                ->rules(['nullable', 'numeric', 'min:0', 'max:'.DealForm::MAX_AMOUNT])
                ->ignoreBlankState()
                ->example('15000.00'),

            ImportColumn::make('probability')
                ->label(__('imports.columns.deal.probability'))
                ->integer()
                ->rules(['nullable', 'integer', 'min:0', 'max:100'])
                ->ignoreBlankState()
                ->example('60'),

            ImportColumn::make('expected_close_date')
                ->label(__('imports.columns.deal.expected_close_date'))
                ->rules(['nullable', 'date_format:Y-m-d'])
                ->ignoreBlankState()
                ->example('2026-12-31'),

            ImportColumn::make('forecast_category')
                ->label(__('imports.columns.deal.forecast_category'))
                ->rules(['nullable', 'string', 'max:50'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Deal $record, ?string $state): void {
                    $category = $importer->resolveEnum(ForecastCategory::class, $state);

                    if ($category instanceof ForecastCategory) {
                        $record->forecast_category = $category;
                    }
                })
                ->example('pipeline'),

            ImportColumn::make('source')
                ->label(__('imports.columns.deal.source'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Deal $record, ?string $state): void {
                    $source = $importer->lookup(LeadSource::class, $state, LeadSource::query()->where('is_active', true));

                    $record->lead_source_id = $source?->getKey();
                })
                ->example(__('imports.examples.deal.source')),

            ImportColumn::make('owner')
                ->label(__('imports.columns.deal.owner'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                // A new record is created for the named owner; an existing one is
                // reassigned through RecordAssignmentService (D-4, A-20).
                ->fillRecordUsing(fn (self $importer, Deal $record, ?string $state) => $importer->fillOwner($record, $state))
                ->example('rep@example.com'),

            ImportColumn::make('tags')
                ->label(__('imports.columns.deal.tags'))
                ->rules(['nullable', 'string', 'max:500'])
                ->ignoreBlankState()
                ->fillRecordUsing(fn (self $importer, ?string $state) => $importer->rememberTags($state))
                ->example(__('imports.examples.deal.tags')),

            ImportColumn::make('description')
                ->label(__('imports.columns.deal.description'))
                ->rules(['nullable', 'string', 'max:5000'])
                ->ignoreBlankState()
                ->example(__('imports.examples.deal.description')),

            // One column per active definition of the entity, mapped by its
            // own label (D-9).
            ...CustomFieldActions::importColumns(CustomFieldEntity::Deal),
        ];
    }

    /**
     * @return array<Component>
     */
    public static function getOptionsFormComponents(): array
    {
        return [self::duplicateStrategySelect()];
    }

    public function resolveRecord(): Model
    {
        $this->rowAccount = $this->resolveAccount(is_string($this->data['account'] ?? null) ? $this->data['account'] : null);

        $existing = $this->resolveDuplicate($this->findDuplicate($this->rowAccount));

        if ($existing instanceof Deal) {
            return $existing;
        }

        $this->assertMayCreate(Deal::class);

        $user = $this->importingUser();

        return new Deal([
            'owner_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'amount' => 0,
        ]);
    }

    /**
     * A new deal starts in the default pipeline's default Open stage when
     * the file left them blank, in the organisation currency, forecast as
     * pipeline (D-8). DealObserver derives the Open status from the stage.
     */
    protected function beforeCreate(): void
    {
        $record = $this->record;

        assert($record instanceof Deal);

        $pipelineId = $this->pipelineIdFor($record);

        if ($record->getAttribute('stage_id') === null) {
            $stageId = DealForm::defaultStageId($pipelineId);

            if ($stageId === null) {
                $this->failRow('pipeline_unavailable');
            }

            $record->stage_id = $stageId;
        }

        if ($record->getAttribute('currency') === null) {
            $record->currency = app(SettingsRepository::class)->currency();
        }

        if ($record->getAttribute('forecast_category') === null) {
            $record->forecast_category = ForecastCategory::Pipeline;
        }

        if ($record->getAttribute('amount') === null) {
            $record->amount = '0.00';
        }
    }

    /**
     * Filament reuses one importer instance for every row of a chunk, so the
     * values a row collects are dropped before the next one starts (D-9).
     */
    protected function beforeValidate(): void
    {
        CustomFieldsSchema::forgetImported($this);
    }

    protected function afterSave(): void
    {
        $this->syncTags();

        // The row's values, now that the record has an id (D-9).
        $record = $this->record;

        if ($record !== null) {
            CustomFieldsSchema::persistImported($record, CustomFieldsSchema::importedValues($this, $record), $this->importingUser());
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        ImportExportActions::applyLocale($import->getOptions());

        return self::completedNotificationBody($import);
    }

    /**
     * The named account, inside the importer's visible scope (D-4). A deal
     * needs one: a blank or unknown name fails the row.
     */
    private function resolveAccount(?string $name): Account
    {
        $raw = Str::squish((string) $name);
        $name = self::normaliseName($name);

        $account = $name === null
            ? null
            // The raw column (case-insensitive collation) keeps accounts_name_index usable.
            : $this->visible(Account::query())->where('accounts.name', $name)->orderBy('id')->first();

        if (! $account instanceof Account) {
            $this->failRow('account_not_found', ['value' => $raw]);
        }

        return $account;
    }

    /** The primary contact by full name, within the row's account. */
    private function resolveContact(?string $name): ?Contact
    {
        $raw = Str::squish((string) $name);
        $name = self::normaliseName($name);

        if ($name === null || ! $this->rowAccount instanceof Account) {
            return null;
        }

        $contact = Contact::query()
            ->where('account_id', $this->rowAccount->getKey())
            // Narrowed by the indexed account first; the collation ignores case.
            ->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$name])
            ->orderBy('id')
            ->first();

        if (! $contact instanceof Contact) {
            $this->failRow('unknown_lookup', ['value' => $raw]);
        }

        return $contact;
    }

    /**
     * The pipeline a new deal goes into: the one the file named, else the
     * default pipeline — stamped on the record so the stage lookup and the
     * observer see the same pipeline.
     */
    private function pipelineIdFor(Deal $record): int
    {
        $current = $record->getAttribute('pipeline_id');

        if ($current !== null) {
            return (int) $current;
        }

        $pipelineId = DealForm::defaultPipelineId();

        if ($pipelineId === null) {
            $this->failRow('pipeline_unavailable');
        }

        $record->pipeline_id = $pipelineId;

        return $pipelineId;
    }

    /**
     * The named stage within the pipeline, which must be Open (D-8). A stage
     * of another pipeline and a Won or Lost stage each fail the row.
     */
    private function resolveStage(?string $name, int $pipelineId): ?PipelineStage
    {
        $raw = Str::squish((string) $name);
        $name = self::normaliseName($name);

        if ($name === null) {
            return null;
        }

        $stage = self::whereNamed(PipelineStage::query()->where('pipeline_id', $pipelineId), $name)->orderBy('sort')->first();

        if (! $stage instanceof PipelineStage) {
            if (self::whereNamed(PipelineStage::query(), $name)->exists()) {
                $this->failRow('stage_outside_pipeline', ['value' => $raw]);
            }

            $this->failRow('unknown_lookup', ['value' => $raw]);
        }

        if ($stage->kind !== StageKind::Open) {
            $this->failRow('stage_not_open', ['value' => $raw]);
        }

        return $stage;
    }

    /**
     * An existing deal inside the importer's visible scope (D-4) with the
     * same title on the same account; deals outside that scope are invisible
     * to the importer, so the row creates a new deal.
     */
    private function findDuplicate(Account $account): ?Deal
    {
        $title = self::normaliseName(is_string($this->data['title'] ?? null) ? $this->data['title'] : null);

        if ($title === null) {
            return null;
        }

        return $this->visible(Deal::query())
            ->where('account_id', $account->getKey())
            ->where('deals.title', $title)
            ->orderBy('id')
            ->first();
    }
}
