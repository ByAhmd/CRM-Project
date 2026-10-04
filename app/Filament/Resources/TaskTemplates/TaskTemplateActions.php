<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates;

use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Tasks\RoutineTaskService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The routine task actions (decision D-22), shared by the table rows and the
 * edit page: pause, resume, delete and the bulk delete. Each one authorises
 * through TaskTemplatePolicy and delegates to RoutineTaskService; deleting a
 * routine keeps the tasks it created, which the confirmation says. Every
 * closure tolerates a missing record so the actions can be declared before
 * Filament resolves one.
 */
final class TaskTemplateActions
{
    public static function pause(): Action
    {
        return Action::make('pause')
            ->label(__('task_templates.actions.pause'))
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('task_templates.actions.pause_heading'))
            ->modalDescription(__('task_templates.confirmations.pause'))
            ->modalSubmitActionLabel(__('task_templates.actions.pause_submit'))
            ->visible(fn (?Model $record): bool => $record instanceof TaskTemplate && $record->is_active)
            ->authorize(fn (?Model $record): bool => $record instanceof TaskTemplate && self::can('pause', $record))
            ->action(function (TaskTemplate $record, Component $livewire): void {
                app(RoutineTaskService::class)->pause($record, self::actor());
                self::refreshForm($livewire);

                Notification::make()->title(__('task_templates.notifications.paused'))->success()->send();
            });
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->label(__('task_templates.actions.resume'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('task_templates.actions.resume_heading'))
            ->modalDescription(__('task_templates.confirmations.resume'))
            ->modalSubmitActionLabel(__('task_templates.actions.resume_submit'))
            ->visible(fn (?Model $record): bool => $record instanceof TaskTemplate && ! $record->is_active)
            ->authorize(fn (?Model $record): bool => $record instanceof TaskTemplate && self::can('resume', $record))
            ->action(function (TaskTemplate $record, Component $livewire): void {
                app(RoutineTaskService::class)->resume($record, self::actor());
                self::refreshForm($livewire);

                Notification::make()->title(__('task_templates.notifications.resumed'))->success()->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('task_templates.confirmations.delete'))
            ->successNotificationTitle(__('task_templates.notifications.deleted'))
            ->authorize(fn (?Model $record): bool => $record instanceof TaskTemplate && self::can('delete', $record))
            ->using(function (TaskTemplate $record): bool {
                app(RoutineTaskService::class)->delete($record, self::actor());

                return true;
            });
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalDescription(__('task_templates.confirmations.delete'))
            ->successNotificationTitle(__('task_templates.notifications.deleted'))
            ->authorizeIndividualRecords('delete')
            ->using(function (Collection $records): void {
                $routines = app(RoutineTaskService::class);
                $actor = self::actor();

                foreach ($records as $record) {
                    if ($record instanceof TaskTemplate) {
                        $routines->delete($record, $actor);
                    }
                }
            });
    }

    /** The edit page shows the switch the action just flipped. */
    private static function refreshForm(Component $livewire): void
    {
        if ($livewire instanceof EditRecord) {
            $livewire->refreshFormData(['is_active']);
        }
    }

    private static function can(string $ability, TaskTemplate $record): bool
    {
        return auth()->user()?->can($ability, $record) ?? false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }
}
