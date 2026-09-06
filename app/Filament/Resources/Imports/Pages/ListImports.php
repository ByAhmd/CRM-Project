<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportResource;
use Filament\Resources\Pages\ListRecords;

final class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;
}
