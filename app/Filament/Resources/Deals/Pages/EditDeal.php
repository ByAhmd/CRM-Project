<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\Deals\DealAmountCalculator;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * A closed deal cannot be edited: DealPolicy::update() refuses it, so the
 * resource authorisation answers 403 before this page renders.
 */
final class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * The repeater saves the line items after the deal row, so the amount is
     * recomputed once they are in place (D-8).
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Deal) {
            app(DealAmountCalculator::class)->recalculate($record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
