<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exports\Pages;

use App\Filament\Resources\Exports\ExportResource;
use Filament\Resources\Pages\ListRecords;

final class ListExports extends ListRecords
{
    protected static string $resource = ExportResource::class;
}
