<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatusKind;
use App\Filament\Concerns\HasSavedViews;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\SavedViewActions;
use App\Models\Lead;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListLeads extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...SavedViewActions::for($this),
            ImportExportActions::import(LeadImporter::class, Lead::class),
            CreateAction::make(),
        ];
    }

    /**
     * Tabs follow the status KIND (the behaviour), not individual statuses,
     * so they stay correct however an administrator names the statuses.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('leads.tabs.open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('status', fn (Builder $status): Builder => $status
                    ->whereIn('kind', [LeadStatusKind::New->value, LeadStatusKind::Working->value, LeadStatusKind::Qualified->value]))),
            'qualified' => Tab::make(__('leads.tabs.qualified'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('status', fn (Builder $status): Builder => $status->where('kind', LeadStatusKind::Qualified->value))),
            'unqualified' => Tab::make(__('leads.tabs.unqualified'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('status', fn (Builder $status): Builder => $status->where('kind', LeadStatusKind::Unqualified->value))),
            'converted' => Tab::make(__('leads.tabs.converted'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('converted_at')),
            'all' => Tab::make(__('leads.tabs.all')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }
}
