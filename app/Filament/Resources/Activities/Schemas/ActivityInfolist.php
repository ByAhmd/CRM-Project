<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Schemas;

use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Activity;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * One activity (decision A-10): what happened, the records it is linked to
 * and who logged it. Links to a linked record appear only when the reader
 * may open it. System payloads stay internal (ids only).
 */
final class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('activities.sections.details'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('type.display_name')
                                ->label(__('activities.fields.type'))
                                ->badge()
                                ->color(fn (Activity $record): string => (string) ($record->type?->color->value ?? 'gray'))
                                ->icon(fn (Activity $record): Heroicon => $record->type?->heroicon() ?? $record->kind->getIcon()),
                            TextEntry::make('kind')
                                ->label(__('activities.fields.kind'))
                                ->badge(),
                            TextEntry::make('occurred_at')
                                ->label(__('activities.fields.occurred_at'))
                                ->dateTime('Y-m-d H:i'),
                            TextEntry::make('direction')
                                ->label(__('activities.fields.direction'))
                                ->badge()
                                ->color('gray')
                                ->placeholder(__('common.placeholders.empty'))
                                ->visible(fn (Activity $record): bool => $record->kind->hasDirection()),
                            TextEntry::make('duration_minutes')
                                ->label(__('activities.fields.duration_minutes'))
                                ->numeric()
                                ->placeholder(__('common.placeholders.empty'))
                                ->visible(fn (Activity $record): bool => $record->kind->hasDuration()),
                            TextEntry::make('outcome')
                                ->label(__('activities.fields.outcome'))
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                        TextEntry::make('subject')
                            ->label(__('activities.fields.subject'))
                            ->weight('semibold')
                            ->columnSpanFull(),
                        TextEntry::make('body')
                            ->label(__('activities.fields.body'))
                            ->placeholder(__('common.placeholders.empty'))
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Section::make(__('activities.sections.related'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('deal.title')
                                ->label(__('activities.fields.deal'))
                                ->url(fn (Activity $record): ?string => $record->deal === null ? null : ActivityResource::urlForSubject($record->deal))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('lead.full_name')
                                ->label(__('activities.fields.lead'))
                                ->state(fn (Activity $record): ?string => $record->lead?->full_name)
                                ->url(fn (Activity $record): ?string => $record->lead === null ? null : ActivityResource::urlForSubject($record->lead))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('contact.full_name')
                                ->label(__('activities.fields.contact'))
                                ->state(fn (Activity $record): ?string => $record->contact?->full_name)
                                ->url(fn (Activity $record): ?string => $record->contact === null ? null : ActivityResource::urlForSubject($record->contact))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('account.name')
                                ->label(__('activities.fields.account'))
                                ->url(fn (Activity $record): ?string => $record->account === null ? null : ActivityResource::urlForSubject($record->account))
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('activities.sections.ownership'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('owner.name')
                                ->label(__('activities.fields.owner'))
                                ->placeholder(__('assignment.placeholders.unassigned')),
                            TextEntry::make('creator.name')
                                ->label(__('activities.fields.created_by'))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('created_at')
                                ->label(__('activities.fields.created_at'))
                                ->dateTime('Y-m-d H:i'),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }
}
