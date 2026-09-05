<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\CloseReasonKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * The deal workflow actions (decision D-8), shared by the table, the view
 * page and the kanban board: change stage, mark as won, mark as lost, reopen.
 *
 * Each action authorises through the policy verb and delegates to the
 * workflow services, which own every rule; a refused transition surfaces as a
 * danger notification. The record is resolved by Filament (table row, page
 * record) or, on the board, from the action arguments through `->record()`,
 * so every closure tolerates a missing record.
 */
final class DealActions
{
    public static function changeStage(): Action
    {
        return Action::make('changeStage')
            ->label(__('deals.actions.change_stage'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->modalHeading(__('deals.actions.change_stage_heading'))
            ->modalSubmitActionLabel(__('deals.actions.change_stage_submit'))
            ->schema([
                Select::make('stage_id')
                    ->label(__('deals.fields.stage'))
                    ->options(fn (?Model $record): array => $record instanceof Deal
                        ? app(DealStageWorkflow::class)->allowedStages($record)
                            ->get()
                            ->mapWithKeys(fn (PipelineStage $stage): array => [$stage->getKey() => $stage->display_name])
                            ->all()
                        : [])
                    ->required()
                    ->native(false),

                Textarea::make('note')
                    ->label(__('deals.fields.stage_note'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Deal && ! $record->isClosed())
            ->authorize(fn (?Model $record): bool => $record instanceof Deal && self::can('changeStage', $record))
            ->action(function (Deal $record, array $data): void {
                $stage = PipelineStage::query()->findOrFail((int) $data['stage_id']);

                self::attempt(function () use ($record, $stage, $data): void {
                    app(DealStageWorkflow::class)->transition($record, $stage, self::actor(), (string) ($data['note'] ?? ''));

                    Notification::make()
                        ->title(__('deals.notifications.stage_changed', ['stage' => $stage->display_name]))
                        ->success()
                        ->send();
                });
            });
    }

    public static function markWon(): Action
    {
        return Action::make('markWon')
            ->label(__('deals.actions.mark_won'))
            ->icon(Heroicon::OutlinedTrophy)
            ->color('success')
            ->modalHeading(__('deals.actions.mark_won_heading'))
            ->modalSubmitActionLabel(__('deals.actions.mark_won_submit'))
            ->schema([
                self::reasonSelect(CloseReasonKind::Won),

                Textarea::make('note')
                    ->label(__('deals.fields.stage_note'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Deal && ! $record->isClosed())
            ->authorize(fn (?Model $record): bool => $record instanceof Deal && self::can('close', $record))
            ->action(function (Deal $record, array $data): void {
                $reason = DealCloseReason::query()->findOrFail((int) $data['close_reason_id']);

                self::attempt(function () use ($record, $reason, $data): void {
                    app(DealCloseService::class)->win($record, $reason, self::actor(), (string) ($data['note'] ?? ''));

                    Notification::make()->title(__('deals.notifications.won'))->success()->send();
                });
            });
    }

    public static function markLost(): Action
    {
        return Action::make('markLost')
            ->label(__('deals.actions.mark_lost'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(__('deals.actions.mark_lost_heading'))
            ->modalSubmitActionLabel(__('deals.actions.mark_lost_submit'))
            ->schema([
                self::reasonSelect(CloseReasonKind::Lost),

                Textarea::make('lost_notes')
                    ->label(__('deals.fields.lost_notes'))
                    ->helperText(__('deals.helpers.lost_notes'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Deal && ! $record->isClosed())
            ->authorize(fn (?Model $record): bool => $record instanceof Deal && self::can('close', $record))
            ->action(function (Deal $record, array $data): void {
                $reason = DealCloseReason::query()->findOrFail((int) $data['close_reason_id']);

                self::attempt(function () use ($record, $reason, $data): void {
                    app(DealCloseService::class)->lose($record, $reason, self::actor(), (string) ($data['lost_notes'] ?? ''));

                    Notification::make()->title(__('deals.notifications.lost'))->success()->send();
                });
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label(__('deals.actions.reopen'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('deals.actions.reopen_heading'))
            ->modalSubmitActionLabel(__('deals.actions.reopen_submit'))
            ->schema([
                Textarea::make('note')
                    ->label(__('deals.fields.stage_note'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Deal && $record->isClosed())
            ->authorize(fn (?Model $record): bool => $record instanceof Deal && self::can('reopen', $record))
            ->action(function (Deal $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    app(DealCloseService::class)->reopen($record, self::actor(), (string) ($data['note'] ?? ''));

                    Notification::make()->title(__('deals.notifications.reopened'))->success()->send();
                });
            });
    }

    private static function reasonSelect(CloseReasonKind $kind): Select
    {
        return Select::make('close_reason_id')
            ->label(__('deals.fields.close_reason'))
            ->options(fn (): array => DealCloseReason::query()
                ->where('kind', $kind->value)
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (DealCloseReason $reason): array => [$reason->getKey() => $reason->display_name])
                ->all())
            ->required()
            ->native(false);
    }

    private static function can(string $ability, Deal $deal): bool
    {
        return auth()->user()?->can($ability, $deal) ?? false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }

    /** Runs a workflow call and turns a refused transition into a danger notification. */
    private static function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidDealTransitionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }
}
