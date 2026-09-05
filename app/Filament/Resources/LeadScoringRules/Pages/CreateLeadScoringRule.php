<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadScoringRules\Pages;

use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateLeadScoringRule extends CreateRecord
{
    protected static string $resource = LeadScoringRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
