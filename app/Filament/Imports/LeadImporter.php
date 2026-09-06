<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\CustomFieldEntity;
use App\Enums\LeadPriority;
use App\Filament\Imports\Concerns\ResolvesImportLookups;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Support\Normalizer;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CSV import of leads (module 18, decisions D-4, D-7).
 *
 * Every row is validated with the rules of the lead form. Lookups are named
 * in either language (source, status), users by email within the importer's
 * reach, tags by name. A duplicate is an existing lead inside the importer's
 * visible scope with the same normalised email or phone: the run's
 * duplicate strategy decides whether it is updated or the row is refused.
 * A new lead is owned by the importer unless the owner column names someone
 * else, starts in the default status when the column is blank, and never
 * starts in the Converted status. An existing lead keeps its status: status
 * moves go through the workflow only (D-7).
 */
final class LeadImporter extends Importer
{
    use ResolvesImportLookups;

    protected static ?string $model = Lead::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('first_name')
                ->label(__('imports.columns.lead.first_name'))
                ->requiredMapping()
                ->rules(['required', 'string', 'min:2', 'max:80'])
                ->example('فهد'),

            ImportColumn::make('last_name')
                ->label(__('imports.columns.lead.last_name'))
                ->requiredMapping()
                ->rules(['required', 'string', 'min:2', 'max:80'])
                ->example('القحطاني'),

            ImportColumn::make('company_name')
                ->label(__('imports.columns.lead.company_name'))
                ->rules(['nullable', 'string', 'max:150'])
                ->ignoreBlankState()
                ->example('شركة الأفق'),

            ImportColumn::make('job_title')
                ->label(__('imports.columns.lead.job_title'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example('مدير المشتريات'),

            ImportColumn::make('email')
                ->label(__('imports.columns.lead.email'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                ->example('fahad@example.com'),

            ImportColumn::make('phone')
                ->label(__('imports.columns.lead.phone'))
                ->rules(['nullable', 'string', 'max:30'])
                ->ignoreBlankState()
                ->example('0501112233'),

            ImportColumn::make('website')
                ->label(__('imports.columns.lead.website'))
                ->rules(['nullable', 'url', 'max:255'])
                ->ignoreBlankState()
                ->example('https://example.com'),

            ImportColumn::make('address_line')
                ->label(__('imports.columns.lead.address_line'))
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->example('طريق الملك فهد'),

            ImportColumn::make('city')
                ->label(__('imports.columns.lead.city'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example('الرياض'),

            ImportColumn::make('region')
                ->label(__('imports.columns.lead.region'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example('منطقة الرياض'),

            ImportColumn::make('country')
                ->label(__('imports.columns.lead.country'))
                ->castStateUsing(fn (?string $state): ?string => $state === null ? null : Str::upper($state))
                ->rules(['nullable', 'string', Rule::in(array_keys(AddressSchema::countryOptions()))])
                ->ignoreBlankState()
                ->example('SA'),

            ImportColumn::make('postal_code')
                ->label(__('imports.columns.lead.postal_code'))
                ->rules(['nullable', 'string', 'max:20'])
                ->ignoreBlankState()
                ->example('12345'),

            ImportColumn::make('source')
                ->label(__('imports.columns.lead.source'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Lead $record, ?string $state): void {
                    $source = $importer->lookup(LeadSource::class, $state, LeadSource::query()->where('is_active', true));

                    $record->lead_source_id = $source?->getKey();
                })
                ->example('Website'),

            ImportColumn::make('status')
                ->label(__('imports.columns.lead.status'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Lead $record, ?string $state): void {
                    if ($record->exists) {
                        return; // status moves go through LeadStatusWorkflow only (D-7)
                    }

                    $status = $importer->lookup(LeadStatus::class, $state, LeadStatus::query()->where('is_active', true));

                    if ($status?->isConverted() === true) {
                        $importer->failRow('converted_status');
                    }

                    $record->lead_status_id = $status?->getKey();
                })
                ->example('New'),

            ImportColumn::make('priority')
                ->label(__('imports.columns.lead.priority'))
                ->rules(['nullable', 'string', 'max:50'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Lead $record, ?string $state): void {
                    $priority = $importer->resolveEnum(LeadPriority::class, $state);

                    if ($priority instanceof LeadPriority) {
                        $record->priority = $priority;
                    }
                })
                ->example('high'),

            ImportColumn::make('owner')
                ->label(__('imports.columns.lead.owner'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Lead $record, ?string $state): void {
                    $owner = $importer->resolveOwner($state, Lead::permissionGroup());

                    if ($owner !== null) {
                        $record->owner_id = $owner->getKey();
                    }
                })
                ->example('rep@example.com'),

            ImportColumn::make('tags')
                ->label(__('imports.columns.lead.tags'))
                ->rules(['nullable', 'string', 'max:500'])
                ->ignoreBlankState()
                ->fillRecordUsing(fn (self $importer, ?string $state) => $importer->rememberTags($state))
                ->example('VIP|Enterprise'),

            ImportColumn::make('description')
                ->label(__('imports.columns.lead.description'))
                ->rules(['nullable', 'string', 'max:5000'])
                ->ignoreBlankState()
                ->example('Met at the Riyadh expo.'),

            // One column per active definition of the entity, mapped by its
            // own label (D-9).
            ...CustomFieldActions::importColumns(CustomFieldEntity::Lead),
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
        $existing = $this->resolveDuplicate($this->findDuplicate());

        if ($existing instanceof Lead) {
            return $existing;
        }

        $this->assertMayCreate(Lead::class);

        $user = $this->importingUser();

        return new Lead([
            'owner_id' => $user->getKey(),
            'created_by' => $user->getKey(),
        ]);
    }

    /** A new lead gets the default status and priority when the file left them blank. */
    protected function beforeCreate(): void
    {
        $record = $this->record;

        assert($record instanceof Lead);

        if ($record->getAttribute('lead_status_id') === null) {
            $record->lead_status_id = LeadStatus::query()->where('is_default', true)->value('id');
        }

        if ($record->getAttribute('priority') === null) {
            $record->priority = LeadPriority::Medium;
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

        return __('imports.notifications.completed', [
            'successful' => (string) $import->successful_rows,
            'failed' => (string) $import->getFailedRowsCount(),
        ]);
    }

    /**
     * An existing lead inside the importer's visible scope (D-4) with the
     * same normalised email or phone; leads outside that scope are invisible
     * to the importer, so the row creates a new lead.
     */
    private function findDuplicate(): ?Lead
    {
        $email = Normalizer::email(is_string($this->data['email'] ?? null) ? $this->data['email'] : null);
        $phone = Normalizer::phone(is_string($this->data['phone'] ?? null) ? $this->data['phone'] : null);

        if ($email === null && $phone === null) {
            return null;
        }

        return $this->visible(Lead::query())
            ->where(function (Builder $query) use ($email, $phone): void {
                $query->whereRaw('1 = 0');

                if ($email !== null) {
                    $query->orWhere('email_normalized', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone_normalized', $phone);
                }
            })
            ->orderBy('id')
            ->first();
    }
}
