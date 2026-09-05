<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Invite / edit user.
 *
 * - email is required on invite and read-only afterwards (it is the login);
 * - roles are not a model attribute: the page strips them from the data and
 *   hands them to RoleService so the change is guarded and audited;
 * - status appears on edit only; an invited user is pending until they accept.
 */
final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('users.sections.details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('users.fields.name'))
                            ->placeholder(__('users.placeholders.name'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100),

                        TextInput::make('email')
                            ->label(__('users.fields.email'))
                            ->placeholder(__('users.placeholders.email'))
                            ->email()
                            ->required()
                            ->maxLength(190)
                            ->rules([
                                fn (?User $record): object => Rule::unique('users', 'email')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                            ])
                            ->validationMessages(['unique' => __('users.validation.email_unique')])
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->extraInputAttributes(['dir' => 'ltr']),

                        TextInput::make('phone')
                            ->label(__('users.fields.phone'))
                            ->placeholder(__('users.placeholders.phone'))
                            ->tel()
                            ->maxLength(30)
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Select::make('locale')
                            ->label(__('users.fields.locale'))
                            ->helperText(__('users.helpers.locale'))
                            ->options(fn (): array => self::localeOptions())
                            ->default(fn (): string => (string) config('app.locale'))
                            ->required()
                            ->native(false),
                    ])
                    ->columns(1),

                Section::make(__('users.sections.access'))
                    ->schema([
                        Select::make('roles')
                            ->label(__('users.fields.roles'))
                            ->helperText(__('users.helpers.roles'))
                            ->options(fn (): array => self::roleOptions())
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->validationMessages(['required' => __('users.validation.roles_required')]),

                        Select::make('team_id')
                            ->label(__('users.fields.team'))
                            ->helperText(__('users.helpers.team'))
                            ->relationship('team', Team::localisedNameColumn(), fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn (Team $record): string => $record->display_name)
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false),
                    ])
                    ->columns(1),

                Section::make(__('users.sections.status'))
                    ->visibleOn('edit')
                    ->schema([
                        Select::make('status')
                            ->label(__('users.fields.status'))
                            ->helperText(__('users.helpers.status'))
                            ->options(UserStatus::class)
                            ->required()
                            ->native(false),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy(Role::localisedNameColumn())
            ->get()
            ->mapWithKeys(fn (Role $role): array => [(string) $role->name => $role->display_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        $options = [];

        foreach ((array) config('app.locales') as $locale) {
            $options[$locale] = LanguageSwitch::make()->getLabel($locale);
        }

        return $options;
    }
}
