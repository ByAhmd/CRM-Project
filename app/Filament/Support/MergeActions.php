<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\RecordMerger;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "Merge duplicates" bulk action (decision A-11): select exactly two records,
 * choose which one survives; the other is folded into it and soft-deleted.
 */
final class MergeActions
{
    /**
     * @param  callable(Model): string  $label
     */
    public static function mergeBulk(string $entity, callable $label): BulkAction
    {
        return BulkAction::make('merge')
            ->label(__('merge.actions.merge'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->modalHeading(__('merge.actions.heading'))
            ->modalDescription(__('merge.actions.description'))
            ->modalSubmitActionLabel(__('merge.actions.submit'))
            ->authorizeIndividualRecords('merge')
            ->schema([
                Select::make('keep_id')
                    ->label(__('merge.fields.keep'))
                    ->helperText(__('merge.helpers.keep'))
                    ->options(function (HasTable $livewire) use ($label): array {
                        $options = [];

                        foreach ($livewire->getSelectedTableRecords() as $record) {
                            if ($record instanceof Model) {
                                $options[(string) $record->getKey()] = $label($record);
                            }
                        }

                        return $options;
                    })
                    ->required()
                    ->native(false),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) use ($entity): void {
                if ($records->count() !== 2) {
                    Notification::make()->title(__('merge.validation.exactly_two'))->danger()->send();

                    return;
                }

                $keep = $records->firstWhere(fn (Model $record): bool => (string) $record->getKey() === (string) ($data['keep_id'] ?? ''));
                $duplicate = $records->first(fn (Model $record): bool => $keep === null || ! $record->is($keep));

                if ($keep === null || $duplicate === null) {
                    Notification::make()->title(__('merge.validation.exactly_two'))->danger()->send();

                    return;
                }

                $actor = auth()->user();
                assert($actor instanceof User);

                $merger = app(RecordMerger::class);

                match ($entity) {
                    'contact' => $merger->mergeContacts(self::asContact($keep), self::asContact($duplicate), $actor),
                    'account' => $merger->mergeAccounts(self::asAccount($keep), self::asAccount($duplicate), $actor),
                    default => throw new \InvalidArgumentException("Unknown merge entity [{$entity}]."),
                };

                Notification::make()->title(__('merge.notifications.done'))->success()->send();
            });
    }

    private static function asContact(Model $model): Contact
    {
        assert($model instanceof Contact);

        return $model;
    }

    private static function asAccount(Model $model): Account
    {
        assert($model instanceof Account);

        return $model;
    }
}
