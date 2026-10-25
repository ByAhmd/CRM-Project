<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use App\Models\User;
use App\Services\ImportExport\ImportUndoPreview;
use App\Services\ImportExport\ImportUndoService;
use Filament\Actions\Action;
use Filament\Actions\Imports\Http\Controllers\DownloadImportFailureCsv;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One import run: the counts, the failed rows and their CSV, and the undo
 * (D-29).
 *
 * The failed-rows file is streamed by Filament's own controller, called
 * with this (policy-bearing) model so ImportPolicy::view decides — the
 * owner always, `imports.view` holders for every run. The signed route
 * Filament puts in the completion notification stays owner-only.
 *
 * The undo action is offered to whoever ImportPolicy::undo allows and says
 * the counts before anything happens (ImportUndoService::preview): what will
 * be deleted, and what stays and why. When nothing can be deleted the dialog
 * still opens — so the reader learns why — but has no submit button.
 * Confirming hands the run to ImportUndoService::request and reloads the
 * page, which then reads «undoing»; the import history (ImportsTable)
 * refreshes itself until the queued job ends and the run reads «undone».
 */
final class ViewImport extends ViewRecord
{
    protected static string $resource = ImportResource::class;

    /**
     * The preview per run for the current request: the dialog's description
     * and its submit button both ask for it, so it is computed once.
     *
     * @var array<int, ImportUndoPreview>
     */
    private array $undoPreviews = [];

    /**
     * The infolist names who undid the run (`undoneBy.name`); the relation is
     * loaded with the record so the page never lazy-loads it.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing('undoneBy');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadFailedRows')
                ->label(__('imports.actions.download_failed_rows'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('danger')
                ->visible(fn (Import $record): bool => $record->getFailedRowsCount() > 0)
                ->authorize(fn (Import $record): bool => auth()->user()?->can('view', $record) ?? false)
                ->action(fn (Import $record): StreamedResponse => app(DownloadImportFailureCsv::class)(request(), $record)),

            Action::make('undo')
                ->label(__('imports.actions.undo'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize(fn (Import $record): bool => auth()->user()?->can('undo', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading(__('imports.confirmations.undo.heading'))
                ->modalDescription(fn (Import $record): Htmlable => $this->undoDescription($record))
                ->modalSubmitAction(fn (Import $record): ?bool => $this->undoPreview($record)->hasNothingToDelete() ? false : null)
                ->modalSubmitActionLabel(__('imports.confirmations.undo.submit'))
                ->action(function (Import $record): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(ImportUndoService::class)->request($record, $actor);

                    $this->redirect(ImportResource::getUrl('view', ['record' => $record]));
                })
                ->successNotification(fn (Notification $notification): Notification => $notification
                    ->title(__('imports.notifications.undo_started.title'))
                    ->body(__('imports.notifications.undo_started.body'))),
        ];
    }

    /**
     * The confirmation's text: what will be deleted and that it is
     * restorable, then each kept reason whose count is above zero (the
     * `keeps_*` strings have an empty zero form on purpose), then that the
     * work runs in the background — or, with nothing to delete, why.
     */
    private function undoDescription(Import $record): Htmlable
    {
        $preview = $this->undoPreview($record);

        if ($preview->hasNothingToDelete()) {
            return new HtmlString(e(__('imports.confirmations.undo.nothing')));
        }

        $sentences = [
            trans_choice('imports.confirmations.undo.will_delete', $preview->deletable, ['count' => (string) $preview->deletable]),
            __('imports.confirmations.undo.restorable'),
        ];

        if ($preview->updated > 0) {
            $sentences[] = trans_choice('imports.confirmations.undo.keeps_updated', $preview->updated, ['count' => (string) $preview->updated]);
        }

        if ($preview->touched > 0) {
            $sentences[] = trans_choice('imports.confirmations.undo.keeps_touched', $preview->touched, ['count' => (string) $preview->touched]);
        }

        if ($preview->forbidden > 0) {
            $sentences[] = trans_choice('imports.confirmations.undo.keeps_forbidden', $preview->forbidden, ['count' => (string) $preview->forbidden]);
        }

        if ($preview->alreadyDeleted > 0) {
            $sentences[] = trans_choice('imports.confirmations.undo.keeps_deleted', $preview->alreadyDeleted, ['count' => (string) $preview->alreadyDeleted]);
        }

        $sentences[] = __('imports.confirmations.undo.queued');

        return new HtmlString(implode('<br>', array_map(static fn (string $sentence): string => e($sentence), $sentences)));
    }

    private function undoPreview(Import $record): ImportUndoPreview
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return ImportUndoPreview::empty();
        }

        return $this->undoPreviews[(int) $record->getKey()] ??= app(ImportUndoService::class)->preview($record, $actor);
    }
}
