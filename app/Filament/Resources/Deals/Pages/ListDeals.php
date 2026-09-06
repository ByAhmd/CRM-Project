<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStatus;
use App\Filament\Concerns\HasSavedViews;
use App\Filament\Imports\DealImporter;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\SavedViewActions;
use App\Models\Deal;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListDeals extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...SavedViewActions::for($this),
            ImportExportActions::import(DealImporter::class, Deal::class),
            CreateAction::make(),
        ];
    }

    /**
     * Tabs follow the derived status (D-8), so they stay correct however an
     * administrator names the pipeline stages.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('deals.tabs.open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DealStatus::Open->value)),
            'won' => Tab::make(__('deals.tabs.won'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DealStatus::Won->value)),
            'lost' => Tab::make(__('deals.tabs.lost'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DealStatus::Lost->value)),
            'all' => Tab::make(__('deals.tabs.all')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }
}
