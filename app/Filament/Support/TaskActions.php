<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The task status actions (decision A-10), shared by the table, the view
 * page and the relation managers: complete (with an optional note), cancel,
 * reopen and the bulk complete.
 *
 * Each action authorises through the policy verb and delegates to
 * TaskService, which owns every rule; a refused transition surfaces as a
 * danger notification. Every closure tolerates a missing record so the
 * actions can be declared before Filament resolves one.
 */
final class TaskActions
{
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label(__('tasks.actions.complete'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(__('tasks.actions.complete_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.complete_submit'))
            ->schema([
                Section::make(__('tasks.sections.notes'))
                    ->columns(1)
                    ->schema([
                        Textarea::make('completion_note')
                            ->label(__('tasks.fields.completion_note'))
                            ->rows(3)
                            ->maxLength(2000),
                    ]),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('complete', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    app(TaskService::class)->complete($record, self::actor(), (string) ($data['completion_note'] ?? ''));

                    Notification::make()->title(__('tasks.notifications.completed'))->success()->send();
                });
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('tasks.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('tasks.actions.cancel_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.cancel_submit'))
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('cancel', $record))
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->cancel($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.cancelled'))->success()->send();
                });
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label(__('tasks.actions.reopen'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('tasks.actions.reopen_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.reopen_submit'))
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isClosed() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('reopen', $record))
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->reopen($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.reopened'))->success()->send();
                });
            });
    }

    /**
     * Completes every selected open task the actor may complete; closed and
     * trashed ones are skipped rather than refused, so a mixed selection
     * still finishes.
     */
    public static function completeBulk(): BulkAction
    {
        return BulkAction::make('complete')
            ->label(__('tasks.actions.complete'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('tasks.actions.complete_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.complete_submit'))
            ->authorizeIndividualRecords('complete')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $service = app(TaskService::class);
                $actor = self::actor();
                $count = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Task || ! $record->isOpen() || $record->trashed()) {
                        continue;
                    }

                    $service->complete($record, $actor);
                    $count++;
                }

                Notification::make()->title(__('tasks.notifications.bulk_completed', ['count' => $count]))->success()->send();
            });
    }

    private static function can(string $ability, Task $task): bool
    {
        return auth()->user()?->can($ability, $task) ?? false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }

    /** Runs a service call and turns a refused transition into a danger notification. */
    private static function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidTaskTransitionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }
}
