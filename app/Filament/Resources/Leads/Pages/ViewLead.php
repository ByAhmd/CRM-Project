<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\EmailActions;
use App\Filament\Support\LeadActions;
use App\Filament\Support\LeadConversionActions;
use App\Filament\Support\OwnershipActions;
use App\Models\Lead;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The lead page. A soft-deleted lead is frozen (D-13): its edit page and
 * workflow actions are refused by LeadPolicy, so it is restored from here.
 */
final class ViewLead extends ViewRecord
{
    /**
     * Every relation the infolist and the header actions read: the status
     * history with its statuses and authors would otherwise cost queries per
     * log (plan section 8).
     */
    private const array RELATIONS = [
        'status', 'source', 'owner', 'qualifier', 'converter', 'creator', 'tags',
        'convertedAccount', 'convertedContact', 'convertedDeal',
        'statusLogs.fromStatus', 'statusLogs.toStatus', 'statusLogs.changer',
    ];

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
     * A workflow action (status change, conversion) may drop the relations it
     * changed from the record, so they are loaded again before the page
     * renders instead of lazily per status log.
     */
    public function rendering(): void
    {
        $this->getRecord()->loadMissing(self::RELATIONS);
    }
}
