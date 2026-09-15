<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RoleService;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Invite / edit user.
 *
 * - email is required on invite and read-only afterwards (it is the login);
 *   the unique index is global, so a deleted account's email is refused with
 *   a "restore instead" message rather than failing on insert;
 * - roles are not a model attribute: the page strips them from the data and
 *   hands them to RoleService so the change is guarded and audited;
 *   super_admin is offered only to roles.manage holders (A-12);
 * - the team list offers active teams plus the record's current team, so a
 *   user in a deactivated team stays editable;
 * - status appears on edit only; pending is reached through an invitation,
 *   so it is offered only while the account is still pending.
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
                                // The database index is global, so a deleted account must be
                                // restored rather than invited again; say so instead of crashing.
                                fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    $trashed = User::onlyTrashed()
                                        ->where('email', $value)
                                        ->whereKeyNot($record?->getKey())
                                        ->exists();

                                    if ($trashed) {
                                        $fail(__('users.validation.email_unique_trashed'));
                                    }
                                },
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
                            ->options(fn (?User $record): array => self::roleOptions($record))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->validationMessages(['required' => __('users.validation.roles_required')]),

                        Select::make('team_id')
                            ->label(__('users.fields.team'))
                            ->helperText(__('users.helpers.team'))
                            ->relationship(
                                'team',
                                Team::localisedNameColumn(),
                                fn (Builder $query, ?User $record): Builder => self::teamOptionsQuery($query, $record),
                            )
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
                            ->options(fn (?User $record): array => self::statusOptions($record))
                            ->required()
                            ->native(false),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * Active teams for new assignments; the record's current team stays valid
     * after it was deactivated, so unrelated edits still save.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    private static function teamOptionsQuery(Builder $query, ?User $record): Builder
    {
        $currentTeamId = $record?->team_id;

        return $query->where(function (Builder $teams) use ($currentTeamId): void {
            $teams->where('is_active', true);

            if ($currentTeamId !== null) {
                $teams->orWhereKey($currentTeamId);
            }
        });
    }

    /**
     * Every role, except super_admin for an actor without roles.manage. A
     * record that already holds super_admin keeps it listed so the current
     * value still reads correctly (such a record is not editable by that
     * actor anyway: UserPolicy::update).
     *
     * @return array<string, string>
     */
    private static function roleOptions(?User $record): array
    {
        $actor = auth()->user();
        $offersSuperAdmin = ($actor instanceof User && app(RoleService::class)->mayAdministerSuperAdmins($actor))
            || ($record?->isSuperAdmin() ?? false);

        return Role::query()
            ->where('guard_name', 'web')
            ->when(! $offersSuperAdmin, fn (Builder $query): Builder => $query->where('name', '!=', CrmRole::SuperAdmin->value))
            ->orderBy(Role::localisedNameColumn())
            ->get()
            ->mapWithKeys(fn (Role $role): array => [(string) $role->name => $role->display_name])
            ->all();
    }

    /**
     * Active and disabled; pending only while the account still is pending.
     *
     * @return array<string, string>
     */
    private static function statusOptions(?User $record): array
    {
        $options = [];

        foreach (UserStatus::cases() as $status) {
            if ($status === UserStatus::Pending && $record?->status !== UserStatus::Pending) {
                continue;
            }

            $options[$status->value] = $status->getLabel();
        }

        return $options;
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
