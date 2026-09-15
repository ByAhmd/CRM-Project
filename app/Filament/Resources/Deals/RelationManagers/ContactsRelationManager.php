<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Enums\DealContactRole;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealContact;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The people involved in a deal and their role (decision D-6). The panel
 * lists and offers only contacts the actor may see (D-4), narrowed to the
 * deal's account when it has one. Every change requires `update` on the
 * deal, so a closed deal's contacts are frozen with it.
 */
final class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('deals.sections.contacts');
    }

    public static function getModelLabel(): string
    {
        return __('contacts.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_name')
            // Only the attached contacts the viewer may read (D-4): seeing the
            // deal does not reveal every person involved in it.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('contacts.id', ContactResource::getEloquentQuery()->select('contacts.id')))
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('contacts.fields.name'))
                    ->state(fn (Contact $record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->weight('semibold'),
                TextColumn::make('job_title')->label(__('contacts.fields.job_title'))->placeholder(__('common.placeholders.empty')),
                TextColumn::make('email')->label(__('contacts.fields.email'))->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('role')
                    ->label(__('deals.fields.role'))
                    ->state(fn (Contact $record): ?DealContactRole => self::pivot($record)?->role)
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('common.placeholders.empty')),
            ])
            ->defaultSort('contacts.last_name')
            ->headerActions([
                AttachAction::make()
                    ->label(__('deals.actions.attach_contact'))
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->recordTitle(fn (Contact $record): string => $record->full_name)
                    ->recordSelectSearchColumns(['first_name', 'last_name', 'email'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $this->constrainToOfferedContacts($query))
                    ->preloadRecordSelect()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        self::roleSelect(),
                    ])
                    ->authorize(fn (): bool => $this->canUpdateDeal()),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([self::roleSelect()])
                    ->authorize(fn (): bool => $this->canUpdateDeal()),
                DetachAction::make()
                    ->authorize(fn (): bool => $this->canUpdateDeal()),
            ])
            ->emptyStateHeading(__('deals.empty.contacts'));
    }

    private static function roleSelect(): Select
    {
        return Select::make('role')
            ->label(__('deals.fields.role'))
            ->options(DealContactRole::class)
            ->nullable()
            ->native(false);
    }

    private static function pivot(Contact $record): ?DealContact
    {
        $pivot = $record->getRelationValue('pivot');

        return $pivot instanceof DealContact ? $pivot : null;
    }

    private function canUpdateDeal(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $this->getOwnerRecord());
    }

    /**
     * The contacts the actor may see (D-4), narrowed to the deal's account
     * when it has one (D-6).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function constrainToOfferedContacts(Builder $query): Builder
    {
        $deal = $this->getOwnerRecord();

        $query->whereIn('contacts.id', ContactResource::getEloquentQuery()->select('contacts.id'));

        if ($deal instanceof Deal && $deal->account_id !== null) {
            $query->where('contacts.account_id', $deal->account_id);
        }

        return $query;
    }
}
