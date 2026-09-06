<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\EmailActions;
use App\Filament\Support\LeadActions;
use App\Filament\Support\LeadConversionActions;
use App\Filament\Support\OwnershipActions;
use App\Models\Lead;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LeadConversionActions::convert(),
            LeadActions::changeStatus(),
            EmailActions::send(),
            OwnershipActions::assign(Lead::permissionGroup()),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
