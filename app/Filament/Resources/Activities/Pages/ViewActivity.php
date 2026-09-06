<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use Filament\Resources\Pages\ViewRecord;

/**
 * An activity is read-only once logged (A-10): the only header action is
 * the audited hard delete, and the subheading says why there is no edit.
 */
final class ViewActivity extends ViewRecord
{
    protected static string $resource = ActivityResource::class;

    public function getSubheading(): string
    {
        return __('activities.validation.immutable');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActivitiesTable::deleteAction(),
        ];
    }
}
