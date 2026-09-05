<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CloseReasonKind;
use App\Models\DealCloseReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Seeds the default deal close reasons (decision D-8): four win reasons and
 * five loss reasons.
 *
 * Idempotent on either name within a kind: a default is created only when
 * neither its English nor its Arabic name exists for that kind, so a reason an
 * administrator has renamed (in one language or both), deactivated or
 * reordered is left untouched on the next deploy, and a missing default is
 * recreated. The same name may legitimately exist for both kinds.
 */
final class DealCloseReasonSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as $kind => $reasons) {
            foreach ($reasons as $index => [$nameAr, $nameEn]) {
                $exists = DealCloseReason::query()
                    ->where('kind', $kind)
                    ->where(fn (Builder $query): Builder => $query
                        ->where('name_en', $nameEn)
                        ->orWhere('name_ar', $nameAr))
                    ->exists();

                if ($exists) {
                    continue;
                }

                DealCloseReason::query()->create([
                    'kind' => $kind,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'is_active' => true,
                    'sort' => $index + 1,
                ]);
            }
        }
    }

    /**
     * @return array<string, list<array{string, string}>>
     */
    public static function defaults(): array
    {
        return [
            CloseReasonKind::Won->value => [
                ['السعر', 'Price'],
                ['العلاقة', 'Relationship'],
                ['المزايا', 'Features'],
                ['الجودة', 'Quality'],
            ],
            CloseReasonKind::Lost->value => [
                ['السعر', 'Price'],
                ['منافس', 'Competitor'],
                ['لا توجد ميزانية', 'No budget'],
                ['التوقيت', 'Timing'],
                ['لا يوجد رد', 'No response'],
            ],
        ];
    }
}
