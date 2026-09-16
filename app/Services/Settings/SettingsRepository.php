<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\ActivityLogEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Typed access to organisation settings (decisions D-2, D-8).
 *
 * Values are cached as one array (file cache locally, shared cache in
 * production); every write invalidates it and is audited with before/after.
 *
 * The array is also kept in memory for the life of the instance, which the
 * container scopes to one request or one queued job: a list rendering a money
 * or date cell per row reads the cache store once, not once per cell, and a
 * long-running worker still sees a change made between two jobs. Every writer
 * here clears the memory with the cache.
 */
#[Scoped]
final class SettingsRepository
{
    private const CACHE_KEY = 'crm.settings';

    public const CURRENCY = 'general.currency';

    public const TIMEZONE = 'general.timezone';

    public const WEEK_STARTS_ON = 'general.week_starts_on';

    public const ORGANISATION_NAME = 'general.organisation_name';

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function currency(): string
    {
        return (string) $this->get(self::CURRENCY, (string) config('crm.currency'));
    }

    public function timezone(): string
    {
        return (string) $this->get(self::TIMEZONE, (string) config('crm.timezone'));
    }

    public function weekStartsOn(): int
    {
        return (int) $this->get(self::WEEK_STARTS_ON, (int) config('crm.week_starts_on'));
    }

    public function organisationName(): string
    {
        return (string) $this->get(self::ORGANISATION_NAME, (string) config('app.name'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        /** @var array<string, mixed> $all */
        $all = Cache::remember($this->cacheKey(), now()->addHours(12), static function (): array {
            return Setting::query()->pluck('value', 'key')->all();
        });

        return $this->values = $all;
    }

    /**
     * The first moment of a calendar day of the organisation (a date picked in
     * a filter), in the application timezone the datetime columns are stored
     * in — so a range filter compares the raw, indexed column.
     */
    public function startOfOrganisationDay(string|DateTimeInterface $date): Carbon
    {
        return $this->organisationDay($date)->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    /** The last moment of a calendar day of the organisation, in the application timezone. */
    public function endOfOrganisationDay(string|DateTimeInterface $date): Carbon
    {
        return $this->organisationDay($date)->endOfDay()->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $values  key => value
     */
    public function update(array $values, ?User $actor = null): void
    {
        DB::transaction(function () use ($values, $actor): void {
            $changes = [];

            foreach ($values as $key => $value) {
                $setting = Setting::query()->firstOrNew(['key' => $key]);
                $old = $setting->exists ? $setting->value : null;

                if ($setting->exists && $old === $value) {
                    continue;
                }

                $setting->group = $setting->group ?? explode('.', $key, 2)[0];
                $setting->value = $value;
                $setting->save();

                $changes[$key] = ['old' => $old, 'new' => $value];
            }

            $this->forget();

            if ($changes !== []) {
                $this->audit->record(ActivityLogEvent::SettingsUpdated, null, $actor, [
                    'subject_label' => __('settings.general.title'),
                    'changes' => $changes,
                ]);
            }
        });
    }

    /**
     * Stores a value the application keeps for itself under a reserved key
     * (the demo data registry of `app:demo-data`). It is bookkeeping, not an
     * organisation setting an administrator changed, so no settings audit
     * event is written; the cache is cleared like on every other write.
     */
    public function putInternal(string $key, mixed $value): void
    {
        DB::transaction(function () use ($key, $value): void {
            $setting = Setting::query()->firstOrNew(['key' => $key]);
            $setting->group = explode('.', $key, 2)[0];
            $setting->value = $value;
            $setting->save();
        });

        $this->forget();
    }

    /** Removes a value stored with putInternal(). */
    public function forgetInternal(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->forget();
    }

    /** Creates missing rows from config defaults; existing values are never overwritten. */
    public function seedDefaults(): void
    {
        $defaults = [
            self::CURRENCY => (string) config('crm.currency'),
            self::TIMEZONE => (string) config('crm.timezone'),
            self::WEEK_STARTS_ON => (int) config('crm.week_starts_on'),
            self::ORGANISATION_NAME => (string) config('app.name'),
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => explode('.', $key, 2)[0]],
            );
        }

        $this->forget();
    }

    private function forget(): void
    {
        Cache::forget($this->cacheKey());
        $this->values = null;
    }

    /**
     * The cache key for the settings of the database this connection points at.
     *
     * One organisation means one set of settings rows (D-2), so the suffix never
     * varies within a running deployment. It matters when the same cache store is
     * reached by a process pointed at another database — a restore drill, a
     * maintenance shell with DB_DATABASE overridden, a staging copy that shares a
     * cache prefix. Without it that process warms `crm.settings` from its own
     * database and every later request reads the wrong currency, timezone and
     * week start until the entry expires twelve hours later.
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY.':'.DB::connection()->getDatabaseName();
    }

    /** The calendar date alone, placed in the organisation timezone. */
    private function organisationDay(string|DateTimeInterface $date): Carbon
    {
        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr(trim($date), 0, 10);

        return Carbon::parse($day, $this->timezone());
    }
}
