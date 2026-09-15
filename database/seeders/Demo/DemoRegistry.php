<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Services\Settings\SettingsRepository;
use InvalidArgumentException;

/**
 * The ids of every row one `app:demo-data` run created, so `--fresh` removes
 * exactly those rows and nothing else.
 *
 * Stored as JSON in the settings table under `demo.registry` through
 * SettingsRepository::putInternal() (bookkeeping, not an organisation setting,
 * so no settings audit event). Ids are kept as sorted [first, last] ranges:
 * a run inserts its rows one after another, so even a `--scale` run records
 * a few ranges per table instead of thousands of ids.
 */
final class DemoRegistry
{
    public const SETTING_KEY = 'demo.registry';

    private const VERSION = 1;

    /**
     * Every table the registry may name, in the order `--fresh` removes them
     * (children before the rows they reference).
     *
     * @var list<string>
     */
    public const TABLES = [
        'activity_log',
        'attachments',
        'activities',
        'notes',
        'tasks',
        'deal_products',
        'deal_stage_logs',
        'deals',
        'lead_status_logs',
        'leads',
        'contacts',
        'accounts',
        'products',
        'saved_views',
        'notification_preferences',
        'users',
        'teams',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function exists(): bool
    {
        return is_array($this->settings->get(self::SETTING_KEY));
    }

    /**
     * @param  array<string, list<int>>  $ids  table => ids
     */
    public function save(array $ids, int $scale, string $seededAt): void
    {
        $tables = [];

        foreach ($ids as $table => $tableIds) {
            if (! in_array($table, self::TABLES, true)) {
                throw new InvalidArgumentException(sprintf('The demo registry does not know the table "%s".', $table));
            }

            $tables[$table] = self::compact($tableIds);
        }

        $this->settings->putInternal(self::SETTING_KEY, [
            'version' => self::VERSION,
            'seeded_at' => $seededAt,
            'scale' => $scale,
            'tables' => $tables,
        ]);
    }

    /**
     * The recorded ids of one table, ascending.
     *
     * @return list<int>
     */
    public function ids(string $table): array
    {
        $registry = $this->settings->get(self::SETTING_KEY);
        $ranges = is_array($registry) && is_array($registry['tables'][$table] ?? null) ? $registry['tables'][$table] : [];

        return self::expand($ranges);
    }

    /**
     * Row counts per table, in removal order.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = count($this->ids($table));
        }

        return $counts;
    }

    public function forget(): void
    {
        $this->settings->forgetInternal(self::SETTING_KEY);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{int, int}>
     */
    public static function compact(array $ids): array
    {
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id, $ids)));
        sort($ids);

        $ranges = [];

        foreach ($ids as $id) {
            $last = count($ranges) - 1;

            if ($last >= 0 && $ranges[$last][1] === $id - 1) {
                $ranges[$last][1] = $id;

                continue;
            }

            $ranges[] = [$id, $id];
        }

        return $ranges;
    }

    /**
     * @param  array<mixed>  $ranges
     * @return list<int>
     */
    public static function expand(array $ranges): array
    {
        $ids = [];

        foreach ($ranges as $range) {
            if (! is_array($range) || ! isset($range[0], $range[1]) || ! is_numeric($range[0]) || ! is_numeric($range[1])) {
                continue;
            }

            for ($id = (int) $range[0]; $id <= (int) $range[1]; $id++) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
