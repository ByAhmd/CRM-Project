<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\CustomFieldEntity;
use App\Filament\Imports\Concerns\ResolvesImportLookups;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use App\Models\Contact;
use App\Services\Contacts\ContactService;
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
 * CSV import of contacts (module 18, decisions D-4, D-6).
 *
 * Every row is validated with the rules of the contact form. The account is
 * named and must be one the importer may see (blank is allowed: a contact
 * without a company); users by email within the importer's reach; tags by
 * name. A duplicate is an existing contact inside the importer's visible
 * scope with the same normalised email or mobile: the run's duplicate
 * strategy decides whether it is updated or the row is refused. A new
 * contact is owned by the importer unless the owner column names someone
 * else. The one-primary-contact-per-account rule is applied after every
 * save through ContactService (D-6).
 */
final class ContactImporter extends Importer
{
    use ResolvesImportLookups;

    protected static ?string $model = Contact::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('first_name')
                ->label(__('imports.columns.contact.first_name'))
                ->requiredMapping()
                ->rules(['required', 'string', 'min:2', 'max:80'])
                ->example(__('imports.examples.contact.first_name')),

            ImportColumn::make('last_name')
                ->label(__('imports.columns.contact.last_name'))
                ->requiredMapping()
                ->rules(['required', 'string', 'min:2', 'max:80'])
                ->example(__('imports.examples.contact.last_name')),

            ImportColumn::make('account')
                ->label(__('imports.columns.contact.account'))
                ->rules(['nullable', 'string', 'max:150'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Contact $record, ?string $state): void {
                    $record->account_id = $importer->resolveAccount($state)?->getKey();
                })
                ->example(__('imports.examples.contact.account')),

            ImportColumn::make('job_title')
                ->label(__('imports.columns.contact.job_title'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example(__('imports.examples.contact.job_title')),

            ImportColumn::make('department')
                ->label(__('imports.columns.contact.department'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example(__('imports.examples.contact.department')),

            ImportColumn::make('email')
                ->label(__('imports.columns.contact.email'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                ->example('noura@example.com'),

            ImportColumn::make('mobile')
                ->label(__('imports.columns.contact.mobile'))
                ->rules(['nullable', 'string', 'max:30'])
                ->ignoreBlankState()
                ->example('0509998877'),

            ImportColumn::make('phone')
                ->label(__('imports.columns.contact.phone'))
                ->rules(['nullable', 'string', 'max:30'])
                ->ignoreBlankState()
                ->example('0112223344'),

            ImportColumn::make('preferred_locale')
                ->label(__('imports.columns.contact.preferred_locale'))
                ->castStateUsing(fn (?string $state): ?string => $state === null ? null : Str::lower($state))
                ->rules(['nullable', 'string', Rule::in((array) config('app.locales'))])
                ->ignoreBlankState()
                ->example('ar'),

            ImportColumn::make('linkedin_url')
                ->label(__('imports.columns.contact.linkedin_url'))
                ->rules(['nullable', 'url', 'max:255'])
                ->ignoreBlankState()
                ->example('https://www.linkedin.com/in/noura'),

            ImportColumn::make('is_primary')
                ->label(__('imports.columns.contact.is_primary'))
                ->boolean()
                ->rules(['nullable', 'boolean'])
                ->ignoreBlankState()
                ->example('yes'),

            ImportColumn::make('address_line')
                ->label(__('imports.columns.contact.address_line'))
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->example(__('imports.examples.contact.address_line')),

            ImportColumn::make('city')
                ->label(__('imports.columns.contact.city'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example(__('imports.examples.contact.city')),

            ImportColumn::make('region')
                ->label(__('imports.columns.contact.region'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example(__('imports.examples.contact.region')),

            ImportColumn::make('country')
                ->label(__('imports.columns.contact.country'))
                ->castStateUsing(fn (?string $state): ?string => $state === null ? null : Str::upper($state))
                ->rules(['nullable', 'string', Rule::in(array_keys(AddressSchema::countryOptions()))])
                ->ignoreBlankState()
                ->example('SA'),

            ImportColumn::make('postal_code')
                ->label(__('imports.columns.contact.postal_code'))
                ->rules(['nullable', 'string', 'max:20'])
                ->ignoreBlankState()
                ->example('12345'),

            ImportColumn::make('owner')
                ->label(__('imports.columns.contact.owner'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                // A new record is created for the named owner; an existing one is
                // reassigned through RecordAssignmentService (D-4, A-20).
                ->fillRecordUsing(fn (self $importer, Contact $record, ?string $state) => $importer->fillOwner($record, $state))
                ->example('rep@example.com'),

            ImportColumn::make('tags')
                ->label(__('imports.columns.contact.tags'))
                ->rules(['nullable', 'string', 'max:500'])
                ->ignoreBlankState()
                ->fillRecordUsing(fn (self $importer, ?string $state) => $importer->rememberTags($state))
                ->example(__('imports.examples.contact.tags')),

            ImportColumn::make('description')
                ->label(__('imports.columns.contact.description'))
                ->rules(['nullable', 'string', 'max:5000'])
                ->ignoreBlankState()
                ->example(__('imports.examples.contact.description')),

            // One column per active definition of the entity, mapped by its
            // own label (D-9).
            ...CustomFieldActions::importColumns(CustomFieldEntity::Contact),
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

        if ($existing instanceof Contact) {
            return $existing;
        }

        $this->assertMayCreate(Contact::class);

        $user = $this->importingUser();

        return new Contact([
            'owner_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'is_primary' => false,
        ]);
    }

    /** A new contact is written to in the application's default language unless the file says otherwise. */
    protected function beforeCreate(): void
    {
        $record = $this->record;

        assert($record instanceof Contact);

        if ($record->getAttribute('preferred_locale') === null) {
            $record->preferred_locale = (string) config('app.locale');
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

        $record = $this->record;

        assert($record instanceof Contact);

        app(ContactService::class)->enforcePrimaryRule($record);

        // The row's values, now that the record has an id (D-9).
        CustomFieldsSchema::persistImported($record, CustomFieldsSchema::importedValues($this, $record), $this->importingUser());
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        ImportExportActions::applyLocale($import->getOptions());

        return self::completedNotificationBody($import);
    }

    /**
     * The named account, inside the importer's visible scope (D-4); a name
     * the importer cannot see fails the row.
     */
    private function resolveAccount(?string $name): ?Account
    {
        $raw = Str::squish((string) $name);
        $name = self::normaliseName($name);

        if ($name === null) {
            return null;
        }

        // The raw column (case-insensitive collation) keeps accounts_name_index usable.
        $account = $this->visible(Account::query())->where('accounts.name', $name)->orderBy('id')->first();

        if (! $account instanceof Account) {
            $this->failRow('account_not_found', ['value' => $raw]);
        }

        return $account;
    }

    /**
     * An existing contact inside the importer's visible scope (D-4) with the
     * same normalised email or mobile; contacts outside that scope are
     * invisible to the importer, so the row creates a new contact.
     */
    private function findDuplicate(): ?Contact
    {
        $email = Normalizer::email(is_string($this->data['email'] ?? null) ? $this->data['email'] : null);
        $mobile = Normalizer::phone(is_string($this->data['mobile'] ?? null) ? $this->data['mobile'] : null);

        if ($email === null && $mobile === null) {
            return null;
        }

        return $this->visible(Contact::query())
            ->where(function (Builder $query) use ($email, $mobile): void {
                $query->whereRaw('1 = 0');

                if ($email !== null) {
                    $query->orWhere('email_normalized', $email);
                }

                if ($mobile !== null) {
                    $query->orWhere('phone_normalized', $mobile);
                }
            })
            ->orderBy('id')
            ->first();
    }
}
