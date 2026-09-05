<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\AddressSchema;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

final class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('leads.sections.classification'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('status.display_name')
                                ->label(__('leads.fields.status'))
                                ->badge()
                                ->color(fn (Lead $record): string => (string) ($record->status?->color->value ?? 'gray')),
                            TextEntry::make('effective_score')
                                ->label(__('leads.fields.score'))
                                ->state(fn (Lead $record): int => $record->effective_score)
                                ->badge()
                                ->color(fn (Lead $record): string => self::scoreColor($record->effective_score)),
                            TextEntry::make('priority')->label(__('leads.fields.priority'))->badge(),
                            TextEntry::make('source.display_name')->label(__('leads.fields.source'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('owner.name')->label(__('leads.fields.owner'))->placeholder(__('assignment.placeholders.unassigned')),
                            TextEntry::make('qualified_at')->label(__('leads.fields.qualified_at'))->dateTime('Y-m-d H:i')->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('qualifier.name')->label(__('leads.fields.qualified_by'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('last_activity_at')->label(__('leads.fields.last_activity_at'))->dateTime('Y-m-d H:i')->placeholder(__('common.placeholders.empty')),
                        ]),
                        TextEntry::make('tags')
                            ->label(__('common.fields.tags'))
                            ->state(fn (Lead $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder(__('common.placeholders.empty')),
                    ])
                    ->columns(1),

                Section::make(__('leads.sections.conversion'))
                    ->visible(fn (Lead $record): bool => $record->isConverted())
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('converted_at')->label(__('leads.fields.converted_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('converter.name')->label(__('leads.fields.converted_by'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('convertedAccount.name')
                                ->label(__('leads.fields.converted_account'))
                                ->url(fn (Lead $record): ?string => $record->convertedAccount instanceof Account ? AccountResource::getUrl('view', ['record' => $record->convertedAccount]) : null)
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('convertedContact.full_name')
                                ->label(__('leads.fields.converted_contact'))
                                ->state(fn (Lead $record): ?string => $record->convertedContact?->full_name)
                                ->url(fn (Lead $record): ?string => $record->convertedContact instanceof Contact ? ContactResource::getUrl('view', ['record' => $record->convertedContact]) : null)
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('leads.sections.person'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('company_name')->label(__('leads.fields.company_name'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('job_title')->label(__('leads.fields.job_title'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('email')->label(__('leads.fields.email'))->copyable()->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('phone')->label(__('leads.fields.phone'))->copyable()->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('website')->label(__('leads.fields.website'))->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('address.section'))
                    ->schema([Grid::make(3)->schema(AddressSchema::entries())])
                    ->columns(1)
                    ->collapsible()
                    ->collapsed(),

                Section::make(__('leads.sections.status_history'))
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(4)->schema([
                                    TextEntry::make('changed_at')->label(__('leads.history.changed_at'))->dateTime('Y-m-d H:i'),
                                    TextEntry::make('fromStatus.display_name')->label(__('leads.history.from'))->placeholder(__('common.placeholders.empty')),
                                    TextEntry::make('toStatus.display_name')->label(__('leads.history.to')),
                                    TextEntry::make('changer.name')->label(__('leads.history.by'))->placeholder(__('common.placeholders.empty')),
                                ]),
                                TextEntry::make('notes')
                                    ->label(__('leads.history.notes'))
                                    ->placeholder(__('common.placeholders.empty'))
                                    ->visible(fn (LeadStatusLog $record): bool => filled($record->notes)),
                            ])
                            ->columns(1),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Section::make(__('leads.sections.notes'))
                    ->schema([
                        TextEntry::make('description')->label(__('leads.fields.description'))->placeholder(__('common.placeholders.empty'))->columnSpanFull(),
                        Grid::make(3)->schema([
                            TextEntry::make('creator.name')->label(__('leads.fields.created_by'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('created_at')->label(__('leads.fields.created_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('updated_at')->label(__('leads.fields.updated_at'))->dateTime('Y-m-d H:i'),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    public static function scoreColor(int $score): string
    {
        return match (true) {
            $score >= 70 => 'success',
            $score >= 40 => 'warning',
            default => 'gray',
        };
    }
}
