<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\DealActions;
use App\Filament\Support\OwnershipActions;
use App\Models\Deal;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The deal page. A soft-deleted deal is frozen (D-13): its edit page and
 * workflow actions are refused by DealPolicy, so it is restored from here.
 */
final class ViewDeal extends ViewRecord
{
    /**
     * Every relation the infolist and the header actions read: the line items
     * with their products and the stage history with its stages and authors
     * would otherwise cost queries per line and per log (plan section 8).
     */
    private const array RELATIONS = [
        'stage', 'pipeline', 'owner', 'account', 'contact', 'source', 'lead', 'closeReason', 'creator', 'tags',
        'products.product',
        'stageLogs.fromStage', 'stageLogs.toStage', 'stageLogs.changer',
    ];

    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DealActions::changeStage(),
            DealActions::markWon(),
            DealActions::markLost(),
            DealActions::reopen(),
            OwnershipActions::assign(Deal::permissionGroup()),
            EditAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * The relations are loaded once with the record, and every custom field
     * entry reads the record's values, so they are loaded once as well (D-9).
     */
    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);
        $record->loadMissing(self::RELATIONS);

        return CustomFieldActions::loadValues($record);
    }

    /**
     * A workflow action drops the relations it changed from the record
     * (DealStageWorkflow, DealCloseService), so they are loaded again before
     * the page renders instead of lazily per stage log.
     */
    public function rendering(): void
    {
        $this->getRecord()->loadMissing(self::RELATIONS);
    }
}
