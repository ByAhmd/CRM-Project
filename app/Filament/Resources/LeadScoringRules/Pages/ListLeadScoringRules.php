<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadScoringRules\Pages;

use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLeadScoringRules extends ListRecords
{
    protected static string $resource = LeadScoringRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
