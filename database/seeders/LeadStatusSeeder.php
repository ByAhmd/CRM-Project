<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Models\LeadStatus;
use App\Services\Settings\LeadStatusService;
use Illuminate\Database\Seeder;

/**
 * Seeds the default lead statuses (decision D-7): New → Contacted →
 * Qualified → Converted, plus Unqualified.
 *
 * Idempotent and administrator-safe: a status that already exists under
 * either name is left untouched (renamed or recoloured rows survive a
 * redeploy) and the Converted row is matched by kind so a second one is
 * never attempted. Rows are created through LeadStatusService so every
 * invariant it enforces applies to seeded data too: New is seeded first and
 * the service makes the first row ever created the default, so an existing
 * default is never displaced.
 */
final class LeadStatusSeeder extends Seeder
{
    /**
     * @return list<array{name_ar: string, name_en: string, kind: LeadStatusKind, color: BadgeColor, sort: int}>
     */
    public static function defaults(): array
    {
        return [
            ['name_ar' => 'جديد', 'name_en' => 'New', 'kind' => LeadStatusKind::New, 'color' => BadgeColor::Info, 'sort' => 1],
            ['name_ar' => 'تم التواصل', 'name_en' => 'Contacted', 'kind' => LeadStatusKind::Working, 'color' => BadgeColor::Primary, 'sort' => 2],
            ['name_ar' => 'مؤهل', 'name_en' => 'Qualified', 'kind' => LeadStatusKind::Qualified, 'color' => BadgeColor::Success, 'sort' => 3],
            ['name_ar' => 'محوّل', 'name_en' => 'Converted', 'kind' => LeadStatusKind::Converted, 'color' => BadgeColor::Warning, 'sort' => 4],
            ['name_ar' => 'غير مؤهل', 'name_en' => 'Unqualified', 'kind' => LeadStatusKind::Unqualified, 'color' => BadgeColor::Gray, 'sort' => 5],
        ];
    }

    public function run(): void
    {
        $service = app(LeadStatusService::class);

        foreach (self::defaults() as $default) {
            if ($this->exists($default)) {
                continue;
            }

            $service->create($default + ['is_default' => false, 'is_active' => true]);
        }
    }

    /**
     * @param  array{name_ar: string, name_en: string, kind: LeadStatusKind, color: BadgeColor, sort: int}  $default
     */
    private function exists(array $default): bool
    {
        if ($default['kind']->isSingleton() && LeadStatus::query()->where('kind', $default['kind']->value)->exists()) {
            return true;
        }

        return LeadStatus::query()
            ->where('name_en', $default['name_en'])
            ->orWhere('name_ar', $default['name_ar'])
            ->exists();
    }
}
