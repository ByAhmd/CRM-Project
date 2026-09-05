<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Models\Deal;
use App\Services\Deals\DealAmountCalculator;
use Filament\Resources\Pages\CreateRecord;

/**
 * The status is not part of the form: DealObserver derives Open from the
 * initial stage and the close columns start empty (D-8).
 */
final class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['owner_id'] = $data['owner_id'] ?? auth()->id();
        $data['currency'] = DealResource::currency();
        $data['pipeline_id'] = $data['pipeline_id'] ?? DealForm::defaultPipelineId();
        $data['stage_id'] = $data['stage_id'] ?? DealForm::defaultStageId($data['pipeline_id']);

        return $data;
    }

    /**
     * The repeater saves the line items after the deal row, so the amount is
     * recomputed once they exist (D-8).
     */
    protected function afterCreate(): void
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
