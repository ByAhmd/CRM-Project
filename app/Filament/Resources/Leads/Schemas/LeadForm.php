<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadPriority;
use App\Enums\LeadStatusKind;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\DuplicateWarning;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TagsSelect;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Create / edit lead. The status is chosen on create only; afterwards it
 * changes through the "change status" action so every move is logged (D-7).
 */
final class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('leads.sections.person'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('first_name')
                                ->label(__('leads.fields.first_name'))
                                ->required()
                                ->minLength(2)
                                ->maxLength(80),

                            TextInput::make('last_name')
                                ->label(__('leads.fields.last_name'))
                                ->required()
                                ->minLength(2)
                                ->maxLength(80),
                        ]),

                        Grid::make(2)->schema([
                            TextInput::make('company_name')
                                ->label(__('leads.fields.company_name'))
                                ->maxLength(150),

                            TextInput::make('job_title')
                                ->label(__('leads.fields.job_title'))
                                ->maxLength(100),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('leads.sections.classification'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('lead_source_id')
                                ->label(__('leads.fields.source'))
                                ->relationship('source', LeadSource::localisedNameColumn(), fn (Builder $query): Builder => $query->where('is_active', true))
                                ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->native(false),

                            Select::make('priority')
                                ->label(__('leads.fields.priority'))
                                ->options(LeadPriority::class)
                                ->default(LeadPriority::Medium->value)
                                ->required()
                                ->native(false),
                        ]),

                        Select::make('lead_status_id')
                            ->label(__('leads.fields.status'))
                            ->helperText(__('leads.helpers.initial_status'))
                            ->options(fn (): array => LeadStatus::query()
                                ->where('is_active', true)
                                ->where('kind', '!=', LeadStatusKind::Converted->value)
                                ->orderBy('sort')
                                ->get()
                                ->mapWithKeys(fn (LeadStatus $status): array => [$status->getKey() => $status->display_name])
                                ->all())
                            ->default(fn (): ?int => LeadStatus::query()->where('is_default', true)->value('id'))
                            ->required()
                            ->native(false)
                            ->visibleOn('create'),

                        Placeholder::make('status_display')
                            ->label(__('leads.fields.status'))
                            ->content(fn (?Lead $record): string => (string) ($record?->status?->getAttribute('display_name') ?? __('common.placeholders.empty')))
                            ->helperText(__('leads.helpers.status_readonly'))
                            ->visibleOn('edit'),

                        TextInput::make('score_override')
                            ->label(__('leads.fields.score_override'))
                            ->helperText(__('leads.helpers.score_override'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->nullable()
                            ->visible(fn (): bool => auth()->user()?->can('lead.assign') ?? false),
                    ])
                    ->columns(1),

                Section::make(__('leads.sections.contact'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('email')
                                ->label(__('leads.fields.email'))
                                ->email()
                                ->maxLength(190)
                                ->live(onBlur: true)
                                ->extraInputAttributes(['dir' => 'ltr']),

                            TextInput::make('phone')
                                ->label(__('leads.fields.phone'))
                                ->tel()
                                ->maxLength(30)
                                ->live(onBlur: true)
                                ->extraInputAttributes(['dir' => 'ltr']),
                        ]),

                        DuplicateWarning::forLead(),

                        TextInput::make('website')
                            ->label(__('leads.fields.website'))
                            ->url()
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ])
                    ->columns(1),

                AddressSchema::section(),

                Section::make(__('leads.sections.ownership'))
                    ->schema([
                        ...OwnerSelect::components(Lead::permissionGroup()),
                        TagsSelect::make(),
                    ])
                    ->columns(1),

                Section::make(__('leads.sections.notes'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('leads.fields.description'))
                            ->rows(4)
                            ->maxLength(5000),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }
}
