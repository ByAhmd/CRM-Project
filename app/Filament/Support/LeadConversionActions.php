<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Contacts\DuplicateFinder;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component as LivewireComponent;

/**
 * "Convert" for a qualified lead (decisions D-6, D-7), shared by the table
 * and the view page. The modal lets the user decide what the conversion
 * produces — a new or existing account (or none for an individual), a new
 * or existing contact, an optional deal — and defaults every choice from the
 * lead itself. LeadConversionWorkflow enforces every rule server-side.
 */
final class LeadConversionActions
{
    private const int SEARCH_LIMIT = 25;

    public static function convert(): Action
    {
        return Action::make('convert')
            ->label(__('leads.actions.convert'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('success')
            ->modalHeading(__('leads.actions.convert_heading'))
            ->modalSubmitActionLabel(__('leads.actions.convert_submit'))
            ->modalWidth('2xl')
            ->schema(self::conversionForm())
            ->fillForm(fn (Lead $record): array => self::defaults($record))
            ->authorize(fn (Lead $record): bool => auth()->user()?->can('convert', $record) ?? false)
            ->visible(fn (Lead $record): bool => ! $record->isConverted() && $record->status?->kind === LeadStatusKind::Qualified)
            ->action(function (Lead $record, array $data, LivewireComponent $livewire): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    $result = app(LeadConversionWorkflow::class)->convert($record, ConversionRequest::fromArray($data), $actor);
                } catch (InvalidLeadTransitionException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('leads.notifications.converted', ['name' => $result->contact->full_name]))
                    ->success()
                    ->send();

                if ($livewire instanceof ViewLead) {
                    $livewire->redirect(ContactResource::getUrl('view', ['record' => $result->contact]));
                }
            });
    }

    /**
     * @return list<Component>
     */
    private static function conversionForm(): array
    {
        return [
            Placeholder::make('lead_summary')
                ->label(__('leads.navigation.model'))
                ->content(fn (?Model $record): string => $record instanceof Lead
                    ? implode(' · ', array_filter([$record->full_name, $record->company_name, $record->email]))
                    : ''),

            Section::make(__('leads.sections.convert_account'))
                ->schema([
                    Radio::make('account_mode')
                        ->label(__('leads.fields.account_mode'))
                        ->helperText(__('leads.helpers.account_mode'))
                        ->options([
                            ConversionRequest::ACCOUNT_NEW => __('leads.options.account_mode.new'),
                            ConversionRequest::ACCOUNT_EXISTING => __('leads.options.account_mode.existing'),
                            ConversionRequest::ACCOUNT_NONE => __('leads.options.account_mode.none'),
                        ])
                        ->required()
                        ->live(),

                    TextInput::make('account_name')
                        ->label(__('leads.fields.account_name'))
                        ->maxLength(150)
                        ->required(fn (Get $get): bool => $get('account_mode') === ConversionRequest::ACCOUNT_NEW)
                        ->visible(fn (Get $get): bool => $get('account_mode') === ConversionRequest::ACCOUNT_NEW),

                    Select::make('account_id')
                        ->label(__('leads.fields.account_id'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::searchAccounts($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => self::accountLabel($value))
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('account_mode') === ConversionRequest::ACCOUNT_EXISTING)
                        ->visible(fn (Get $get): bool => $get('account_mode') === ConversionRequest::ACCOUNT_EXISTING),
                ])
                ->columns(1),

            Section::make(__('leads.sections.convert_contact'))
                ->schema([
                    Radio::make('contact_mode')
                        ->label(__('leads.fields.contact_mode'))
                        ->options([
                            ConversionRequest::CONTACT_NEW => __('leads.options.contact_mode.new'),
                            ConversionRequest::CONTACT_EXISTING => __('leads.options.contact_mode.existing'),
                        ])
                        ->helperText(function (?Model $record): ?string {
                            $match = $record instanceof Lead ? self::matchingContact($record) : null;

                            return $match instanceof Contact ? __('leads.helpers.contact_existing', ['name' => $match->full_name]) : null;
                        })
                        ->required()
                        ->live(),

                    Select::make('contact_id')
                        ->label(__('leads.fields.contact_id'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::searchContacts($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => self::contactLabel($value))
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('contact_mode') === ConversionRequest::CONTACT_EXISTING)
                        ->visible(fn (Get $get): bool => $get('contact_mode') === ConversionRequest::CONTACT_EXISTING),
                ])
                ->columns(1),

            Section::make(__('leads.sections.convert_deal'))
                ->schema([
                    Toggle::make('create_deal')
                        ->label(__('leads.fields.create_deal'))
                        ->helperText(__('leads.helpers.create_deal'))
                        ->live(),

                    Grid::make(2)
                        ->schema([
                            TextInput::make('deal_title')
                                ->label(__('leads.fields.deal_title'))
                                ->maxLength(150)
                                ->required(),

                            Select::make('pipeline_id')
                                ->label(__('leads.fields.pipeline'))
                                ->options(fn (): array => self::activePipelines())
                                ->native(false)
                                ->required(),

                            TextInput::make('deal_amount')
                                ->label(__('leads.fields.deal_amount'))
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->suffix(fn (): string => app(SettingsRepository::class)->currency())
                                ->extraInputAttributes(['dir' => 'ltr']),

                            DatePicker::make('expected_close_date')
                                ->label(__('leads.fields.expected_close_date'))
                                ->native(false)
                                ->displayFormat('Y-m-d')
                                ->extraInputAttributes(['dir' => 'ltr']),
                        ])
                        ->visible(fn (Get $get): bool => (bool) $get('create_deal')),
                ])
                ->columns(1),

            Textarea::make('note')
                ->label(__('leads.fields.conversion_note'))
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(Lead $record): array
    {
        $company = trim((string) $record->company_name);
        $match = self::matchingContact($record);

        return [
            'account_mode' => $company !== '' ? ConversionRequest::ACCOUNT_NEW : ConversionRequest::ACCOUNT_NONE,
            'account_name' => $company !== '' ? $company : null,
            'account_id' => null,
            'contact_mode' => $match instanceof Contact ? ConversionRequest::CONTACT_EXISTING : ConversionRequest::CONTACT_NEW,
            'contact_id' => $match?->getKey(),
            'create_deal' => true,
            'deal_title' => $company !== '' ? $company : $record->full_name,
            'pipeline_id' => Pipeline::query()->where('is_default', true)->value('id'),
            'deal_amount' => null,
            'expected_close_date' => null,
            'note' => null,
        ];
    }

    /** The first visible contact sharing the lead's normalised email, if any. */
    private static function matchingContact(Lead $record): ?Contact
    {
        if (blank($record->email)) {
            return null;
        }

        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return null;
        }

        // The finder is confined to the actor's reach, so a contact outside it
        // is neither suggested nor named, and the five-match limit applies to
        // contacts the actor may pick (D-4, A-11).
        $matches = app(DuplicateFinder::class)->contacts(email: $record->email, phone: null, viewer: $viewer);

        if ($matches->isEmpty()) {
            return null;
        }

        $contact = ContactResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->whereIn('contacts.id', $matches->modelKeys())
            ->orderBy('contacts.id')
            ->first();

        return $contact instanceof Contact ? $contact : null;
    }

    /**
     * Accounts within the actor's reach matching the typed search, resolved
     * on the database side so the modal never loads the whole universe.
     *
     * @return array<int, string>
     */
    private static function searchAccounts(string $search): array
    {
        return AccountResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->select(['accounts.id', 'accounts.name'])
            ->where('accounts.name', 'like', '%'.self::escapeLike($search).'%')
            ->orderBy('accounts.name')
            ->limit(self::SEARCH_LIMIT)
            ->pluck('accounts.name', 'accounts.id')
            ->all();
    }

    /** The visible account's name, or null when it is outside the actor's reach (so validation rejects it). */
    private static function accountLabel(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $name = AccountResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->select(['accounts.id', 'accounts.name'])
            ->whereKey((int) $value)
            ->value('accounts.name');

        return is_string($name) ? $name : null;
    }

    /**
     * Contacts within the actor's reach matching the typed search on name or
     * e-mail, labelled by full name.
     *
     * @return array<int, string>
     */
    private static function searchContacts(string $search): array
    {
        $term = '%'.self::escapeLike($search).'%';

        return ContactResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->select(['contacts.id', 'contacts.first_name', 'contacts.last_name'])
            ->where(function (Builder $query) use ($term): void {
                $query->where('contacts.first_name', 'like', $term)
                    ->orWhere('contacts.last_name', 'like', $term)
                    ->orWhere('contacts.email', 'like', $term);
            })
            ->orderBy('contacts.last_name')
            ->orderBy('contacts.first_name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->mapWithKeys(fn (Model $contact): array => [(int) $contact->getKey() => (string) $contact->getAttribute('full_name')])
            ->all();
    }

    /** The visible contact's full name, or null when it is outside the actor's reach (so validation rejects it). */
    private static function contactLabel(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $contact = ContactResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->select(['contacts.id', 'contacts.first_name', 'contacts.last_name'])
            ->whereKey((int) $value)
            ->first();

        return $contact instanceof Model ? (string) $contact->getAttribute('full_name') : null;
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes(trim($value), '\\%_');
    }

    /**
     * @return array<int, string>
     */
    private static function activePipelines(): array
    {
        return Pipeline::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Pipeline $pipeline): array => [$pipeline->getKey() => $pipeline->display_name])
            ->all();
    }
}
