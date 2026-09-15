<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\CustomFieldEntity;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\CustomFieldsSchema;
use App\Livewire\RecordTimeline;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageLog;
use App\Models\Lead;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * The deal page (decision D-8): summary, closing details once won or lost,
 * line items, the append-only stage history and the notes.
 */
final class DealInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('deals.sections.summary'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('stage.display_name')
                                ->label(__('deals.fields.stage'))
                                ->badge()
                                ->color(fn (Deal $record): string => (string) ($record->stage?->color->value ?? 'gray')),
                            TextEntry::make('status')
                                ->label(__('deals.fields.status'))
                                ->badge(),
                            TextEntry::make('amount')
                                ->label(__('deals.fields.amount'))
                                ->money(currency: fn (Deal $record): string => $record->currency, locale: fn (): string => app()->getLocale())
                                ->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('effective_probability')
                                ->label(__('deals.fields.effective_probability'))
                                ->state(fn (Deal $record): int => $record->effective_probability)
                                ->formatStateUsing(fn (int $state): string => Number::percentage($state, locale: app()->getLocale())),
                            TextEntry::make('weighted_amount')
                                ->label(__('deals.fields.weighted_amount'))
                                ->state(fn (Deal $record): string => $record->weighted_amount)
                                ->money(currency: fn (Deal $record): string => $record->currency, locale: fn (): string => app()->getLocale())
                                ->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('forecast_category')
                                ->label(__('deals.fields.forecast_category'))
                                ->badge(),
                            TextEntry::make('expected_close_date')
                                ->label(__('deals.fields.expected_close_date'))
                                ->date('Y-m-d')
                                ->color(fn (Deal $record): ?string => self::isOverdue($record) ? 'danger' : null)
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('owner.name')
                                ->label(__('deals.fields.owner'))
                                ->placeholder(__('assignment.placeholders.unassigned')),
                            TextEntry::make('account.name')
                                ->label(__('deals.fields.account'))
                                ->url(fn (Deal $record): ?string => $record->account instanceof Account ? AccountResource::getUrl('view', ['record' => $record->account]) : null)
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('contact.full_name')
                                ->label(__('deals.fields.contact'))
                                ->state(fn (Deal $record): ?string => $record->contact?->full_name)
                                ->url(fn (Deal $record): ?string => $record->contact instanceof Contact ? ContactResource::getUrl('view', ['record' => $record->contact]) : null)
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('pipeline.display_name')
                                ->label(__('deals.fields.pipeline'))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('source.display_name')
                                ->label(__('deals.fields.source'))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('lead.full_name')
                                ->label(__('deals.fields.lead'))
                                ->state(fn (Deal $record): ?string => $record->lead?->full_name)
                                ->url(fn (Deal $record): ?string => $record->lead instanceof Lead ? LeadResource::getUrl('view', ['record' => $record->lead]) : null)
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                        TextEntry::make('tags')
                            ->label(__('common.fields.tags'))
                            ->state(fn (Deal $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder(__('common.placeholders.empty')),
                    ])
                    ->columns(1),

                Section::make(__('deals.sections.close'))
                    ->visible(fn (Deal $record): bool => $record->isClosed())
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('won_at')
                                ->label(__('deals.fields.won_at'))
                                ->dateTime('Y-m-d H:i')
                                ->visible(fn (Deal $record): bool => $record->isWon()),
                            TextEntry::make('lost_at')
                                ->label(__('deals.fields.lost_at'))
                                ->dateTime('Y-m-d H:i')
                                ->visible(fn (Deal $record): bool => $record->isLost()),
                            TextEntry::make('closeReason.display_name')
                                ->label(__('deals.fields.close_reason'))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('closed_by')
                                ->label(__('deals.fields.closed_by'))
                                ->state(fn (Deal $record): ?string => $record->stageLogs->first()?->changer?->name)
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                        TextEntry::make('lost_notes')
                            ->label(__('deals.fields.lost_notes'))
                            ->placeholder(__('common.placeholders.empty'))
                            ->visible(fn (Deal $record): bool => $record->isLost())
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Section::make(__('deals.sections.line_items'))
                    ->schema([
                        RepeatableEntry::make('products')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(6)->schema([
                                    TextEntry::make('product.display_name')->label(__('deals.fields.product'))->placeholder(__('common.placeholders.empty')),
                                    TextEntry::make('description')->label(__('deals.fields.line_description'))->placeholder(__('common.placeholders.empty')),
                                    TextEntry::make('quantity')->label(__('deals.fields.quantity'))->numeric(decimalPlaces: 2, locale: fn (): string => app()->getLocale())->extraAttributes(['dir' => 'ltr']),
                                    TextEntry::make('unit_price')->label(__('deals.fields.unit_price'))->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())->extraAttributes(['dir' => 'ltr']),
                                    TextEntry::make('discount_percent')->label(__('deals.fields.discount_percent'))->formatStateUsing(fn (mixed $state): string => Number::percentage((float) $state, 2, locale: app()->getLocale()))->extraAttributes(['dir' => 'ltr']),
                                    TextEntry::make('line_total')->label(__('deals.fields.line_total'))->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())->weight('semibold')->extraAttributes(['dir' => 'ltr']),
                                ]),
                            ])
                            ->placeholder(__('deals.empty.line_items'))
                            ->columns(1),
                        TextEntry::make('total')
                            ->label(__('deals.fields.total'))
                            ->state(fn (Deal $record): string => $record->amount)
                            ->money(currency: fn (Deal $record): string => $record->currency, locale: fn (): string => app()->getLocale())
                            ->weight('semibold')
                            ->extraAttributes(['dir' => 'ltr']),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Section::make(__('deals.sections.stage_history'))
                    ->schema([
                        RepeatableEntry::make('stageLogs')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(5)->schema([
                                    TextEntry::make('changed_at')->label(__('deals.history.changed_at'))->dateTime('Y-m-d H:i'),
                                    TextEntry::make('fromStage.display_name')->label(__('deals.history.from'))->placeholder(__('common.placeholders.empty')),
                                    TextEntry::make('toStage.display_name')->label(__('deals.history.to')),
                                    TextEntry::make('changer.name')->label(__('deals.history.by'))->placeholder(__('common.placeholders.empty')),
                                    TextEntry::make('duration_seconds')
                                        ->label(__('deals.history.duration'))
                                        ->formatStateUsing(fn (mixed $state): string => self::formatDuration($state === null ? null : (int) $state))
                                        ->placeholder(__('common.placeholders.empty')),
                                ]),
                                TextEntry::make('notes')
                                    ->label(__('deals.history.notes'))
                                    ->placeholder(__('common.placeholders.empty'))
                                    ->visible(fn (DealStageLog $record): bool => filled($record->notes)),
                            ])
                            ->placeholder(__('deals.empty.stage_history'))
                            ->columns(1),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Section::make(__('deals.sections.notes'))
                    ->schema([
                        TextEntry::make('description')->label(__('deals.fields.description'))->placeholder(__('common.placeholders.empty'))->columnSpanFull(),
                        Grid::make(4)->schema([
                            TextEntry::make('creator.name')->label(__('deals.fields.created_by'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('created_at')->label(__('deals.fields.created_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('updated_at')->label(__('deals.fields.updated_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('last_activity_at')->label(__('deals.fields.last_activity_at'))->dateTime('Y-m-d H:i')->placeholder(__('common.placeholders.empty')),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),

                // The administrator's own fields (D-9); nothing at all when the
                // entity carries no active definition.
                ...array_filter([CustomFieldsSchema::infolistSection(CustomFieldEntity::Deal)]),

                Section::make(__('timeline.section'))
                    ->schema([
                        Livewire::make(RecordTimeline::class, fn (Model $record): array => [
                            'subjectType' => $record::class,
                            'subjectId' => (int) $record->getKey(),
                        ])
                            ->lazy(),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /** An open deal whose expected close date has passed. */
    public static function isOverdue(Deal $record): bool
    {
        return ! $record->isClosed()
            && $record->expected_close_date !== null
            && $record->expected_close_date->endOfDay()->isPast();
    }

    /**
     * Seconds spent in a stage as "N days, N hours" (minutes only below an
     * hour); an empty placeholder when the log has no duration.
     */
    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return __('common.placeholders.empty');
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return trans_choice('deals.history.days', $days).__('common.separators.list').trans_choice('deals.history.hours', $hours);
        }

        if ($hours > 0) {
            return trans_choice('deals.history.hours', $hours).__('common.separators.list').trans_choice('deals.history.minutes', $minutes);
        }

        return trans_choice('deals.history.minutes', $minutes);
    }
}
