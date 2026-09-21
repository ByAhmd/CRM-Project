<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\CustomFieldEntity;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\LtrText;
use App\Livewire\RecordTimeline;
use App\Models\Account;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

final class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('accounts.sections.details'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('type')->label(__('accounts.fields.type'))->badge(),
                            TextEntry::make('industry.display_name')->label(__('accounts.fields.industry'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('size')->label(__('accounts.fields.size'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('owner.name')->label(__('accounts.fields.owner'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('parent.name')->label(__('accounts.fields.parent'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('customer_since')->label(__('accounts.fields.customer_since'))->date('Y-m-d')->placeholder(__('common.placeholders.empty')),
                        ]),
                        TextEntry::make('tags')
                            ->label(__('common.fields.tags'))
                            ->state(fn (Account $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder(__('common.placeholders.empty')),
                    ])
                    ->columns(1),

                Section::make(__('accounts.sections.contact'))
                    ->schema([
                        Grid::make(3)->schema([
                            LtrText::entry(TextEntry::make('website')->label(__('accounts.fields.website'))->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->placeholder(__('common.placeholders.empty'))),
                            LtrText::entry(TextEntry::make('email')->label(__('accounts.fields.email'))->copyable()->placeholder(__('common.placeholders.empty'))),
                            LtrText::entry(TextEntry::make('phone')->label(__('accounts.fields.phone'))->copyable()->placeholder(__('common.placeholders.empty'))),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('address.section'))
                    ->schema([Grid::make(3)->schema(AddressSchema::entries())])
                    ->columns(1)
                    ->collapsible(),

                Section::make(__('accounts.sections.notes'))
                    ->schema([
                        TextEntry::make('description')->label(__('accounts.fields.description'))->placeholder(__('common.placeholders.empty'))->columnSpanFull(),
                        Grid::make(3)->schema([
                            TextEntry::make('creator.name')->label(__('accounts.fields.created_by'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('created_at')->label(__('accounts.fields.created_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('updated_at')->label(__('accounts.fields.updated_at'))->dateTime('Y-m-d H:i'),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),

                // The administrator's own fields (D-9); nothing at all when the
                // entity carries no active definition.
                ...array_filter([CustomFieldsSchema::infolistSection(CustomFieldEntity::Account)]),

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
}
