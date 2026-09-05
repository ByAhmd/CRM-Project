<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Leads\LeadStatusWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "Change status" for leads (decision D-7), shared by the table, the view
 * page and the bulk toolbar. The note becomes mandatory the moment a status
 * of kind Qualified is chosen; the workflow enforces the same rule server-side.
 */
final class LeadActions
{
    public static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label(__('leads.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->modalHeading(__('leads.actions.change_status_heading'))
            ->modalSubmitActionLabel(__('leads.actions.change_status_submit'))
            ->schema(self::statusForm())
            ->authorize(fn (Lead $record): bool => auth()->user()?->can('changeStatus', $record) ?? false)
            ->action(function (Lead $record, array $data): void {
                $status = LeadStatus::query()->findOrFail((int) $data['lead_status_id']);

                if (self::perform($record, $status, (string) ($data['note'] ?? ''))) {
                    Notification::make()
                        ->title(__('leads.notifications.status_changed', ['status' => $status->display_name]))
                        ->success()
                        ->send();
                }
            });
    }

    public static function changeStatusBulk(): BulkAction
    {
        return BulkAction::make('changeStatus')
            ->label(__('leads.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalHeading(__('leads.actions.change_status_heading'))
            ->modalSubmitActionLabel(__('leads.actions.change_status_submit'))
            ->schema(self::statusForm())
            ->authorizeIndividualRecords('changeStatus')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $status = LeadStatus::query()->findOrFail((int) $data['lead_status_id']);
                $changed = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Lead) {
                        continue;
                    }

                    self::perform($record, $status, (string) ($data['note'] ?? ''), silent: true) ? $changed++ : $failed++;
                }

                Notification::make()
                    ->title(__('leads.notifications.bulk_status_changed', ['changed' => $changed, 'failed' => $failed]))
                    ->status($failed === 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    private static function statusForm(): array
    {
        return [
            Select::make('lead_status_id')
                ->label(__('leads.fields.status'))
                ->options(function (?Model $record): array {
                    $query = $record instanceof Lead
                        ? app(LeadStatusWorkflow::class)->allowedTargets($record)
                        : LeadStatus::query()->where('is_active', true)->where('kind', '!=', LeadStatusKind::Converted->value)->orderBy('sort');

                    return $query->get()
                        ->mapWithKeys(fn (LeadStatus $status): array => [$status->getKey() => $status->display_name])
                        ->all();
                })
                ->required()
                ->live()
                ->native(false),

            Textarea::make('note')
                ->label(__('leads.fields.status_note'))
                ->helperText(__('leads.helpers.status_note'))
                ->rows(3)
                ->maxLength(2000)
                ->required(fn (Get $get): bool => self::isQualified($get('lead_status_id'))),
        ];
    }

    private static function isQualified(mixed $statusId): bool
    {
        if ($statusId === null || $statusId === '') {
            return false;
        }

        return LeadStatus::query()->whereKey((int) $statusId)->where('kind', LeadStatusKind::Qualified->value)->exists();
    }

    private static function perform(Lead $lead, LeadStatus $status, string $note, bool $silent = false): bool
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            app(LeadStatusWorkflow::class)->transition($lead, $status, $actor, $note);

            return true;
        } catch (InvalidLeadTransitionException $exception) {
            if (! $silent) {
                Notification::make()->title($exception->getMessage())->danger()->send();
            }

            return false;
        }
    }
}
