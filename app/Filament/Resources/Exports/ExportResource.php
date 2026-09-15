<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exports;

use App\Enums\NavigationGroup;
use App\Filament\Exports\AccountExporter;
use App\Filament\Exports\ActivityExporter;
use App\Filament\Exports\ContactExporter;
use App\Filament\Exports\DealExporter;
use App\Filament\Exports\LeadExporter;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Exports\Tables\ExportsTable;
use App\Models\Export;
use App\Models\User;
use App\Policies\ExportPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Export history (module 18, decisions D-4, D-13): read-only. A user sees
 * their own runs and downloads their own files; holders of `exports.view` see
 * and download other users' runs of the entities they reach at all level
 * (ExportPolicy). The rows are written by Filament's ExportAction and pruned
 * by retention only, files included.
 */
final class ExportResource extends Resource
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected static ?string $model = Export::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 61;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'file_name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('exports.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('exports.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exports.navigation.plural_model');
    }

    public static function table(Table $table): Table
    {
        return ExportsTable::configure($table);
    }

    /**
     * Own runs always; other users' runs only of the exporters whose entity
     * the viewer reviews (ExportPolicy::reviews: `exports.view` plus all-level
     * reach), so the list never shows a run the viewer could not open. The
     * reviewable exporters are resolved in PHP from the known classes, not
     * per row.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with('user');

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $policy = app(ExportPolicy::class);
        $reviewable = array_values(array_filter(
            array_keys(self::entityOptions()),
            static fn (string $exporter): bool => $policy->reviews($user, $exporter),
        ));

        return $query->where(static function (Builder $runs) use ($user, $reviewable): void {
            $runs->where('user_id', $user->getKey());

            if ($reviewable !== []) {
                $runs->orWhereIn('exporter', $reviewable);
            }
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExports::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The entity each exporter class handles, labelled for the reader.
     *
     * @return array<class-string, string>
     */
    public static function entityOptions(): array
    {
        return [
            LeadExporter::class => __('leads.navigation.plural_model'),
            ContactExporter::class => __('contacts.navigation.plural_model'),
            AccountExporter::class => __('accounts.navigation.plural_model'),
            DealExporter::class => __('deals.navigation.plural_model'),
            TaskExporter::class => __('tasks.navigation.plural_model'),
            ActivityExporter::class => __('activities.navigation.plural_model'),
        ];
    }

    public static function entityLabel(string $exporter): string
    {
        return self::entityOptions()[$exporter] ?? class_basename($exporter);
    }

    /**
     * Processing until Filament stamps completed_at; then failed when no row
     * made it, completed otherwise.
     */
    public static function status(Export $export): string
    {
        if ($export->completed_at === null) {
            return self::STATUS_PROCESSING;
        }

        if ($export->total_rows > 0 && $export->successful_rows === 0) {
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
