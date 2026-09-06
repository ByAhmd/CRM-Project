<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks;

use App\Enums\NavigationGroup;
use App\Enums\VisibilityLevel;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Tasks and follow-ups (decisions A-10, D-4).
 *
 * Visibility per D-4 with one addition mirrored in TaskPolicy: a task is
 * readable when it falls inside the actor's own task scope (assignee / team
 * / all) OR when it is linked to a lead, contact, account or deal the actor
 * may read, each resolved through that resource's own query so the four
 * scopes are never re-implemented here. The status changes only through
 * the actions, which call TaskService.
 *
 * @extends resource<Task>
 */
final class TaskResource extends Resource
{
    use ScopesQueriesToVisibleRecords;

    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Activities;
    }

    public static function getNavigationLabel(): string
    {
        return __('tasks.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('tasks.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tasks.navigation.plural_model');
    }

    /**
     * My open tasks due today or already overdue; nothing when there are none.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = self::attentionQuery()?->count() ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $query = self::attentionQuery();

        if ($query === null) {
            return null;
        }

        return $query->where('due_at', '<', now())->exists() ? 'danger' : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TaskInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    /**
     * @return Builder<Task>
     */
    public static function getEloquentQuery(): Builder
    {
        return self::constrainToReadable(parent::getEloquentQuery())
            ->with(['assignee', 'lead', 'contact', 'account', 'deal']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }

    // --- Global search (permission-aware through the readable scope) ---

    public static function canGloballySearch(): bool
    {
        return self::canViewAny();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return self::constrainToReadable(parent::getGlobalSearchEloquentQuery())->with(['assignee']);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Task);

        return array_filter([
            __('tasks.fields.due_at') => $record->due_at?->format('Y-m-d H:i'),
            __('tasks.fields.assignee') => $record->assignee?->name,
        ]);
    }

    /**
     * The view page of a linked record, when the actor may open it.
     */
    public static function urlForSubject(Model $record): ?string
    {
        return ActivityResource::urlForSubject($record);
    }

    /**
     * Own task scope OR linked to a readable subject. At the `all` level
     * nothing needs adding; at `none` the resolver already fails closed and
     * the subject paths are not opened either, since viewAny is the policy's
     * precondition for every read.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function constrainToReadable(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $level = app(RecordVisibilityResolver::class)->levelFor($user, Task::class);

        if ($level === VisibilityLevel::All || $level === VisibilityLevel::None) {
            return self::constrainToVisible($query);
        }

        return $query->where(function (Builder $nested): void {
            self::constrainToVisible($nested)
                ->orWhereIn('tasks.lead_id', LeadResource::getEloquentQuery()->select('leads.id'))
                ->orWhereIn('tasks.contact_id', ContactResource::getEloquentQuery()->select('contacts.id'))
                ->orWhereIn('tasks.account_id', AccountResource::getEloquentQuery()->select('accounts.id'))
                ->orWhereIn('tasks.deal_id', DealResource::getEloquentQuery()->select('deals.id'));
        });
    }

    /**
     * The actor's open tasks due by the end of today, or null when the actor
     * may not list tasks at all.
     *
     * @return Builder<Task>|null
     */
    private static function attentionQuery(): ?Builder
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('viewAny', Task::class)) {
            return null;
        }

        return Task::query()
            ->whereIn('tasks.status', Task::openStatusValues())
            ->where('tasks.assignee_id', $user->getKey())
            ->where('tasks.due_at', '<=', now()->endOfDay());
    }
}
