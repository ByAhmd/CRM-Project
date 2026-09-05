<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Schemas;

use App\Enums\UserStatus;
use App\Models\Team;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

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
                            ])
                            ->validationMessages(['unique' => __('teams.validation.name_unique')]),

                        Select::make('manager_user_id')
                            ->label(__('teams.fields.manager'))
                            ->helperText(__('teams.helpers.manager'))
                            ->relationship(
                                'manager',
                                'name',
                                fn (Builder $query): Builder => $query->where('status', UserStatus::Active->value)->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false),

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
                    ->columns(1),
            ])
            ->columns(1);
    }
}
