<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Schemas;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\AddressSchema;
use App\Livewire\RecordTimeline;
use App\Models\Account;
use App\Models\Contact;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

final class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('contacts.sections.details'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('account.name')
                                ->label(__('contacts.fields.account'))
                                ->url(fn (Contact $record): ?string => $record->account instanceof Account ? AccountResource::getUrl('view', ['record' => $record->account]) : null)
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('job_title')->label(__('contacts.fields.job_title'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('department')->label(__('contacts.fields.department'))->placeholder(__('common.placeholders.empty')),
                            IconEntry::make('is_primary')->label(__('contacts.fields.is_primary'))->boolean(),
                            TextEntry::make('owner.name')->label(__('contacts.fields.owner'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('preferred_locale')
                                ->label(__('contacts.fields.preferred_locale'))
                                ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : LanguageSwitch::make()->getLabel($state))
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                        TextEntry::make('tags')
                            ->label(__('common.fields.tags'))
                            ->state(fn (Contact $record): array => $record->tags->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder(__('common.placeholders.empty')),
                    ])
                    ->columns(1),

                Section::make(__('contacts.sections.contact'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('email')->label(__('contacts.fields.email'))->copyable()->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('mobile')->label(__('contacts.fields.mobile'))->copyable()->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('phone')->label(__('contacts.fields.phone'))->copyable()->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('linkedin_url')->label(__('contacts.fields.linkedin_url'))->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('address.section'))
                    ->schema([Grid::make(3)->schema(AddressSchema::entries())])
                    ->columns(1)
                    ->collapsible(),

                Section::make(__('contacts.sections.notes'))
                    ->schema([
                        TextEntry::make('description')->label(__('contacts.fields.description'))->placeholder(__('common.placeholders.empty'))->columnSpanFull(),
                        Grid::make(3)->schema([
                            TextEntry::make('creator.name')->label(__('contacts.fields.created_by'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('created_at')->label(__('contacts.fields.created_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('updated_at')->label(__('contacts.fields.updated_at'))->dateTime('Y-m-d H:i'),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),

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
