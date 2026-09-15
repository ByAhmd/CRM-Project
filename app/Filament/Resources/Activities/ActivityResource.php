<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities;

use App\Enums\NavigationGroup;
use App\Enums\VisibilityLevel;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Activities\Schemas\ActivityForm;
use App\Filament\Resources\Activities\Schemas\ActivityInfolist;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Activities (decision A-10). Immutable: there is no edit page.
 *
 * Visibility per D-4 with one addition mirrored in ActivityPolicy: an
 * activity is readable when it falls inside the actor's own activity scope
 * (owner / team / all) OR when it is linked to a lead, contact, account or
 * deal the actor may read, each resolved through that resource's own
 * query so the four scopes are never re-implemented here.
 *
 * @extends resource<Activity>
 */
final class ActivityResource extends Resource
{
    use ScopesQueriesToVisibleRecords;

    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 10;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Activities;
    }

    public static function getNavigationLabel(): string
    {
        return __('activities.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('activities.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activities.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return ActivityForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ActivityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    /**
     * @return Builder<Activity>
     */
    public static function getEloquentQuery(): Builder
    {
        return self::constrainToReadable(parent::getEloquentQuery())
            ->with(['type', 'lead', 'contact', 'account', 'deal', 'owner']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'create' => CreateActivity::route('/create'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }

    /**
     * The view page of a linked record, when the actor may open it.
     */
    public static function urlForSubject(Model $record): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('view', $record)) {
            return null;
        }

        return match ($record::class) {
            Lead::class => LeadResource::getUrl('view', ['record' => $record]),
            Contact::class => ContactResource::getUrl('view', ['record' => $record]),
            Account::class => AccountResource::getUrl('view', ['record' => $record]),
            Deal::class => DealResource::getUrl('view', ['record' => $record]),
            default => null,
        };
    }

    /**
     * Own activity scope OR linked to a readable subject. At the `all`
     * level nothing needs adding; at `none` the resolver already fails
     * closed and the subject paths are not opened either, since viewAny
     * is the policy's precondition for every read.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private static function constrainToReadable(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $level = app(RecordVisibilityResolver::class)->levelFor($user, Activity::class);

        if ($level === VisibilityLevel::All || $level === VisibilityLevel::None) {
            return self::constrainToVisible($query);
        }

        return $query->where(function (Builder $nested): void {
            self::constrainToVisible($nested)
                ->orWhereIn('activities.lead_id', LeadResource::getEloquentQuery()->select('leads.id'))
                ->orWhereIn('activities.contact_id', ContactResource::getEloquentQuery()->select('contacts.id'))
                ->orWhereIn('activities.account_id', AccountResource::getEloquentQuery()->select('accounts.id'))
                ->orWhereIn('activities.deal_id', DealResource::getEloquentQuery()->select('deals.id'));
        });
    }
}
