<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Pages;

use App\Enums\ActivityKind;
use App\Filament\Concerns\HasSavedViews;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Support\SavedViewActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListActivities extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...SavedViewActions::for($this),
            CreateAction::make()
                ->label(__('activities.actions.log')),
        ];
    }

    /**
     * Tabs follow the KIND copied onto each activity, so they stay correct
     * however an administrator names or reshuffles the activity types.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('activities.tabs.all')),
            'mine' => Tab::make(__('activities.tabs.mine'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('activities.owner_id', auth()->id())),
            'calls' => Tab::make(__('activities.tabs.calls'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('activities.kind', ActivityKind::Call->value)),
            'meetings' => Tab::make(__('activities.tabs.meetings'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('activities.kind', ActivityKind::Meeting->value)),
            'emails' => Tab::make(__('activities.tabs.emails'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('activities.kind', ActivityKind::Email->value)),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'all';
    }
}
