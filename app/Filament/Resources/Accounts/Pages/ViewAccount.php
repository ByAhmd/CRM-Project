<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\OwnershipActions;
use App\Models\Account;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnershipActions::assign(Account::permissionGroup()),
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
