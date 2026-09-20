<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Schemas;

use App\Enums\UserStatus;
use App\Models\Team;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Create / edit team.
 *
 * - both names are unique across live teams; the index is global, so a deleted
 *   namesake is refused with a "restore instead" message rather than failing
 *   on insert;
 * - the manager is chosen among the team's active members (D-4: a manager
 *   reaches the team's records through their own team), so the field appears
 *   once the team exists; a manager stored before this rule stays a valid
 *   current value.
 */
final class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('teams.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('teams.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Team $record): object => Rule::unique('teams', 'name_ar')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                // The database index is global, so a deleted namesake must be
                                // restored rather than recreated; say so instead of crashing.
                                fn (?Team $record): Closure => self::trashedNamesakeRule('name_ar', $record),
                            ])
                            ->validationMessages(['unique' => __('teams.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('teams.fields.name_en'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?Team $record): object => Rule::unique('teams', 'name_en')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                // The database index is global, so a deleted namesake must be
                                // restored rather than recreated; say so instead of crashing.
                                fn (?Team $record): Closure => self::trashedNamesakeRule('name_en', $record),
                            ])
                            ->validationMessages(['unique' => __('teams.validation.name_unique')]),

                        Select::make('manager_user_id')
                            ->label(__('teams.fields.manager'))
                            ->helperText(__('teams.helpers.manager'))
                            ->relationship(
                                'manager',
                                'name',
                                fn (Builder $query, ?Team $record): Builder => self::managerOptionsQuery($query, $record),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false)
                            ->hiddenOn('create'),

                        Toggle::make('is_active')
                            ->label(__('teams.fields.is_active'))
                            ->helperText(__('teams.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('teams.fields.sort'))
                            ->helperText(__('teams.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),
            ])
            ->columns(1);
    }

    /**
     * Active members of the team being edited, plus the current manager so a
     * value stored before the rule still saves.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function managerOptionsQuery(Builder $query, ?Team $record): Builder
    {
        $teamId = $record?->getKey();
        $currentManagerId = $record?->manager_user_id;

        return $query
            ->where(function (Builder $candidates) use ($teamId, $currentManagerId): void {
                $candidates->where(function (Builder $members) use ($teamId): void {
                    $members->where('status', UserStatus::Active->value);

                    if ($teamId === null) {
                        $members->whereRaw('1 = 0');
                    } else {
                        $members->where('team_id', $teamId);
                    }
                });

                if ($currentManagerId !== null) {
                    $candidates->orWhereKey($currentManagerId);
                }
            })
            ->orderBy('name');
    }

    /** Fails when a soft-deleted team other than the record carries the name. */
    private static function trashedNamesakeRule(string $column, ?Team $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $record): void {
            $trashed = Team::onlyTrashed()
                ->where($column, $value)
                ->whereKeyNot($record?->getKey())
                ->exists();

            if ($trashed) {
                $fail(__('teams.validation.'.$column.'_unique_trashed'));
            }
        };
    }
}
