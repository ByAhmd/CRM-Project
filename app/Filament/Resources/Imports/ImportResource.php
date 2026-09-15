<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports;

use App\Enums\NavigationGroup;
use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Imports\Schemas\ImportInfolist;
use App\Filament\Resources\Imports\Tables\ImportsTable;
use App\Models\Import;
use App\Models\User;
use App\Policies\ImportPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Import history (module 18, decisions D-4, D-13): read-only. A user sees
 * their own runs; holders of `imports.view` see other users' runs of the
 * entities they reach at all level (ImportPolicy). The rows are written by
 * Filament's ImportAction and pruned by retention only.
 */
final class ImportResource extends Resource
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected static ?string $model = Import::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 60;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'file_name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('imports.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('imports.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('imports.navigation.plural_model');
    }

    public static function infolist(Schema $schema): Schema
    {
        return ImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportsTable::configure($table);
    }

    /**
     * Own runs always; other users' runs only of the importers whose entity
     * the viewer reviews (ImportPolicy::reviews: `imports.view` plus all-level
     * reach), so neither the list nor the view page opens a run the viewer
     * could not read. The reviewable importers are resolved in PHP from the
     * known classes, not per row.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with('user');

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $policy = app(ImportPolicy::class);
        $reviewable = array_values(array_filter(
            array_keys(self::entityOptions()),
            static fn (string $importer): bool => $policy->reviews($user, $importer),
        ));

        return $query->where(static function (Builder $runs) use ($user, $reviewable): void {
            $runs->where('user_id', $user->getKey());

            if ($reviewable !== []) {
                $runs->orWhereIn('importer', $reviewable);
            }
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImports::route('/'),
            'view' => ViewImport::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The entity each importer class handles, labelled for the reader.
     *
     * @return array<class-string, string>
     */
    public static function entityOptions(): array
    {
        return [
            LeadImporter::class => __('leads.navigation.plural_model'),
            ContactImporter::class => __('contacts.navigation.plural_model'),
            AccountImporter::class => __('accounts.navigation.plural_model'),
            DealImporter::class => __('deals.navigation.plural_model'),
        ];
    }

    public static function entityLabel(string $importer): string
    {
        return self::entityOptions()[$importer] ?? class_basename($importer);
    }

    /**
     * Processing until Filament stamps completed_at; then failed when no row
     * made it, completed otherwise (partial failures show in the counts).
     */
    public static function status(Import $import): string
    {
        if ($import->completed_at === null) {
            return self::STATUS_PROCESSING;
        }

        if ($import->total_rows > 0 && $import->successful_rows === 0) {
            return self::STATUS_FAILED;
        }

        return self::STATUS_COMPLETED;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'warning',
        };
    }
}
