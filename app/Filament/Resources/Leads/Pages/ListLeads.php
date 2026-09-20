<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatusKind;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\ImportExportActions;
use App\Models\Lead;
use App\Models\LeadStatus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportExportActions::import(LeadImporter::class, Lead::class),
            // A panel that opens this as a modal instead of linking to the
            // create page builds it from the entity form, custom section
            // included, so the state is stripped and written here too (D-9).
            CustomFieldActions::createAction(CreateAction::make()),
        ];
    }

    /**
     * Tabs follow the status KIND (the behaviour), not individual statuses,
     * so they stay correct however an administrator names the statuses.
     *
     * A status tab filters `lead_status_id IN (<ids of the statuses of those
     * kinds>)` read from the small lookup table, not an EXISTS over
     * lead_statuses: the optimizer then estimates the rows exactly, reads the
     * default page (created_at desc) backwards on leads_created_at_index and
     * stops after the page instead of joining from the statuses and
     * filesorting every open lead, and counts the tab on
     * leads_deleted_at_lead_status_id_index from the index alone (plan
     * section 8, step-13 EXPLAIN evidence).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('leads.tabs.open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::inStatusKinds($query, LeadStatusKind::New, LeadStatusKind::Working, LeadStatusKind::Qualified)),
            'qualified' => Tab::make(__('leads.tabs.qualified'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::inStatusKinds($query, LeadStatusKind::Qualified)),
            'unqualified' => Tab::make(__('leads.tabs.unqualified'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::inStatusKinds($query, LeadStatusKind::Unqualified)),
            'converted' => Tab::make(__('leads.tabs.converted'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('converted_at')),
            'all' => Tab::make(__('leads.tabs.all')),
        ];
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private static function inStatusKinds(Builder $query, LeadStatusKind ...$kinds): Builder
    {
        $statusIds = LeadStatus::query()
            ->whereIn('kind', array_map(static fn (LeadStatusKind $kind): string => $kind->value, $kinds))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $query->whereIn($query->qualifyColumn('lead_status_id'), $statusIds);
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }
}
