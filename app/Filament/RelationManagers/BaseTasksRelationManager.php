<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tasks on a lead, a contact, an account or a deal (decision A-10):
 * the shared listing, the "add task" modal with the owner record as the
 * subject, the modal edit and the status actions, every write through
 * TaskService.
 *
 * Deliberately abstract — the one exception to the "every class is final"
 * rule (CLAUDE.md section 3): the four subject resources need identical
 * managers that differ only in the relationship they read and the column
 * that points at the owner record, so each is a final subclass setting
 * `$relationship` and `$subjectForeignKey` and nothing else. The manager is
 * offered only to users who may list tasks AND read the owner record;
 * every task on a readable record is readable (TaskPolicy::view), and every
 * write authorises through TaskPolicy explicitly so the actions work on the
 * view page as well as the edit page.
 */
abstract class BaseTasksRelationManager extends RelationManager
{
    /** The `tasks` column that points at the owner record. */
    protected static string $subjectForeignKey;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tasks.navigation.plural_model');
    }

    public static function getModelLabel(): string
    {
        return __('tasks.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('viewAny', Task::class)
            && $user->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema, withSubjects: false);
    }

    public function infolist(Schema $schema): Schema
    {
        return TaskInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return TasksTable::configure($table, withSubject: false)
            ->recordTitleAttribute('title')
            // The relation orders by due date for its other callers; the table drops
            // that order so the column sorts apply and re-adds it as the default.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->reorder()
                ->with(['assignee', 'lead', 'contact', 'account', 'deal']))
            ->headerActions([
                CreateAction::make()
                    ->label(__('tasks.actions.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading(__('tasks.actions.add_heading'))
                    ->modalSubmitActionLabel(__('tasks.actions.add_submit'))
                    ->createAnother(false)
                    ->authorize(fn (): bool => $this->actor()->can('create', Task::class))
                    ->using(fn (array $data): Task => app(TaskService::class)->create(
                        [...$data, static::$subjectForeignKey => $this->getOwnerRecord()->getKey()],
                        $this->actor(),
                    ))
                    ->successNotificationTitle(__('tasks.notifications.added')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(__('tasks.actions.view')),

                EditAction::make()
                    ->modalHeading(__('tasks.actions.edit'))
                    ->hidden(fn (Task $record): bool => $record->trashed())
                    ->authorize(fn (Task $record): bool => $this->actor()->can('update', $record))
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['owner_id'] = $data['assignee_id'] ?? null;

                        return $data;
                    })
                    ->using(fn (Task $record, array $data): Task => app(TaskService::class)->update($record, $data, $this->actor()))
                    ->successNotificationTitle(__('tasks.notifications.updated')),

                TaskActions::complete(),
                TaskActions::cancel(),
                TaskActions::reopen(),
                OwnershipActions::assign(Task::permissionGroup()),

                DeleteAction::make()
                    ->authorize(fn (Task $record): bool => $this->actor()->can('delete', $record)),

                RestoreAction::make()
                    ->authorize(fn (Task $record): bool => $this->actor()->can('restore', $record)),
            ]);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }
}
