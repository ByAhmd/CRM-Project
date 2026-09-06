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
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewDeal extends ViewRecord
{
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
        ];
    }

    /**
     * Every custom field entry of the page reads the record's values, so the
     * relation is loaded once with the record instead of once per entry (D-9).
     */
    protected function resolveRecord(int|string $key): Model
    {
        return CustomFieldActions::loadValues(parent::resolveRecord($key));
    }
}
