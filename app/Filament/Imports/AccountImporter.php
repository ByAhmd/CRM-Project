<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Filament\Imports\Concerns\ResolvesImportLookups;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use App\Models\Industry;
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
 * CSV import of accounts (module 18, decisions D-4, D-6).
 *
 * Every row is validated with the rules of the account form. The industry
 * is named in either language, the type and size by enum value or label,
 * the parent account by name inside the importer's visible scope, users by
 * email within the importer's reach, tags by name. A duplicate is an
 * existing account inside the importer's visible scope with the same name
 * (case-insensitive) or normalised email: the run's duplicate strategy
 * decides whether it is updated or the row is refused. A new account is
 * owned by the importer unless the owner column names someone else and is a
 * prospect unless the file says otherwise (D-6).
 */
final class AccountImporter extends Importer
{
    use ResolvesImportLookups;

    protected static ?string $model = Account::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label(__('imports.columns.account.name'))
                ->requiredMapping()
                ->rules(['required', 'string', 'min:2', 'max:150'])
                ->example('شركة الأفق'),

            ImportColumn::make('type')
                ->label(__('imports.columns.account.type'))
                ->rules(['nullable', 'string', 'max:50'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Account $record, ?string $state): void {
                    $type = $importer->resolveEnum(AccountType::class, $state);

                    if ($type instanceof AccountType) {
                        $record->type = $type;
                    }
                })
                ->example('prospect'),

            ImportColumn::make('industry')
                ->label(__('imports.columns.account.industry'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Account $record, ?string $state): void {
                    $industry = $importer->lookup(Industry::class, $state, Industry::query()->where('is_active', true));

                    $record->industry_id = $industry?->getKey();
                })
                ->example('Technology'),

            ImportColumn::make('size')
                ->label(__('imports.columns.account.size'))
                ->rules(['nullable', 'string', 'max:50'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Account $record, ?string $state): void {
                    $size = $importer->resolveEnum(CompanySize::class, $state);

                    if ($size instanceof CompanySize) {
                        $record->size = $size;
                    }
                })
                ->example('51_200'),

            ImportColumn::make('website')
                ->label(__('imports.columns.account.website'))
                ->rules(['nullable', 'url', 'max:255'])
                ->ignoreBlankState()
                ->example('https://example.com'),

            ImportColumn::make('email')
                ->label(__('imports.columns.account.email'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                ->example('info@example.com'),

            ImportColumn::make('phone')
                ->label(__('imports.columns.account.phone'))
                ->rules(['nullable', 'string', 'max:30'])
                ->ignoreBlankState()
                ->example('0112223344'),

            ImportColumn::make('address_line')
                ->label(__('imports.columns.account.address_line'))
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->example('طريق الملك فهد'),

            ImportColumn::make('city')
                ->label(__('imports.columns.account.city'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example('الرياض'),

            ImportColumn::make('region')
                ->label(__('imports.columns.account.region'))
                ->rules(['nullable', 'string', 'max:100'])
                ->ignoreBlankState()
                ->example('منطقة الرياض'),

            ImportColumn::make('country')
                ->label(__('imports.columns.account.country'))
                ->castStateUsing(fn (?string $state): ?string => $state === null ? null : Str::upper($state))
                ->rules(['nullable', 'string', Rule::in(array_keys(AddressSchema::countryOptions()))])
                ->ignoreBlankState()
                ->example('SA'),

            ImportColumn::make('postal_code')
                ->label(__('imports.columns.account.postal_code'))
                ->rules(['nullable', 'string', 'max:20'])
                ->ignoreBlankState()
                ->example('12345'),

            ImportColumn::make('parent')
                ->label(__('imports.columns.account.parent'))
                ->rules(['nullable', 'string', 'max:150'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Account $record, ?string $state): void {
                    $parent = $importer->resolveParent($state, $record);

                    $record->parent_account_id = $parent?->getKey();
                })
                ->example('مجموعة الأفق القابضة'),

            ImportColumn::make('owner')
                ->label(__('imports.columns.account.owner'))
                ->rules(['nullable', 'email', 'max:190'])
                ->ignoreBlankState()
                ->fillRecordUsing(function (self $importer, Account $record, ?string $state): void {
                    $owner = $importer->resolveOwner($state, Account::permissionGroup());

                    if ($owner !== null) {
                        $record->owner_id = $owner->getKey();
                    }
                })
                ->example('rep@example.com'),

            ImportColumn::make('tags')
                ->label(__('imports.columns.account.tags'))
                ->rules(['nullable', 'string', 'max:500'])
                ->ignoreBlankState()
                ->fillRecordUsing(fn (self $importer, ?string $state) => $importer->rememberTags($state))
                ->example('VIP|Enterprise'),

            ImportColumn::make('description')
                ->label(__('imports.columns.account.description'))
                ->rules(['nullable', 'string', 'max:5000'])
                ->ignoreBlankState()
                ->example('Regional distributor.'),
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

        if ($existing instanceof Account) {
            return $existing;
        }

        $this->assertMayCreate(Account::class);

        $user = $this->importingUser();

        return new Account([
            'owner_id' => $user->getKey(),
            'created_by' => $user->getKey(),
        ]);
    }

    /** A new account is a prospect unless the file says otherwise (D-6). */
    protected function beforeCreate(): void
    {
        $record = $this->record;

        assert($record instanceof Account);

        if ($record->getAttribute('type') === null) {
            $record->type = AccountType::Prospect;
        }
    }

    protected function afterSave(): void
    {
        $this->syncTags();
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
     * The named parent account, inside the importer's visible scope (D-4)
     * and never the account itself.
     */
    private function resolveParent(?string $name, Account $record): ?Account
    {
        $raw = Str::squish((string) $name);
        $name = self::normaliseName($name);

        if ($name === null) {
            return null;
        }

        $parent = $this->visible(Account::query())
            ->whereRaw('LOWER(name) = ?', [$name])
            ->when($record->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->orderBy('id')
            ->first();

        if (! $parent instanceof Account) {
            $this->failRow('account_not_found', ['value' => $raw]);
        }

        return $parent;
    }

    /**
     * An existing account inside the importer's visible scope (D-4) with the
     * same name or normalised email; accounts outside that scope are
     * invisible to the importer, so the row creates a new account.
     */
    private function findDuplicate(): ?Account
    {
        $name = self::normaliseName(is_string($this->data['name'] ?? null) ? $this->data['name'] : null);
        $email = Normalizer::email(is_string($this->data['email'] ?? null) ? $this->data['email'] : null);

        if ($name === null && $email === null) {
            return null;
        }

        return $this->visible(Account::query())
            ->where(function (Builder $query) use ($name, $email): void {
                $query->whereRaw('1 = 0');

                if ($name !== null) {
                    $query->orWhereRaw('LOWER(name) = ?', [$name]);
                }

                if ($email !== null) {
                    $query->orWhere('email_normalized', $email);
                }
            })
            ->orderBy('id')
            ->first();
    }
}
