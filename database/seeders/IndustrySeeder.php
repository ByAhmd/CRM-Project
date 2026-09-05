<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Industry;
use Illuminate\Database\Seeder;

/**
 * Seeds the default industries for the Saudi market (decision A-4).
 * Idempotent: a default is skipped when a row already carries either of its
 * names, so an administrator's edits to one name, the activity flag or the
 * order survive a reseed without tripping the unique name indexes.
 */
final class IndustrySeeder extends Seeder
{
    /**
     * @return list<array{name_en: string, name_ar: string}>
     */
    public static function defaults(): array
    {
        return [
            ['name_en' => 'Technology', 'name_ar' => 'التقنية'],
            ['name_en' => 'Retail', 'name_ar' => 'تجارة التجزئة'],
            ['name_en' => 'Wholesale', 'name_ar' => 'تجارة الجملة'],
            ['name_en' => 'Manufacturing', 'name_ar' => 'الصناعة'],
            ['name_en' => 'Construction', 'name_ar' => 'المقاولات والبناء'],
            ['name_en' => 'Real Estate', 'name_ar' => 'العقارات'],
            ['name_en' => 'Healthcare', 'name_ar' => 'الرعاية الصحية'],
            ['name_en' => 'Education', 'name_ar' => 'التعليم'],
            ['name_en' => 'Hospitality', 'name_ar' => 'الضيافة والسياحة'],
            ['name_en' => 'Food and Beverage', 'name_ar' => 'الأغذية والمشروبات'],
            ['name_en' => 'Logistics', 'name_ar' => 'الخدمات اللوجستية'],
            ['name_en' => 'Finance', 'name_ar' => 'الخدمات المالية'],
            ['name_en' => 'Government', 'name_ar' => 'القطاع الحكومي'],
            ['name_en' => 'Energy', 'name_ar' => 'الطاقة'],
            ['name_en' => 'Other', 'name_ar' => 'أخرى'],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $sort => $industry) {
            $exists = Industry::query()
                ->where('name_en', $industry['name_en'])
                ->orWhere('name_ar', $industry['name_ar'])
                ->exists();

            if ($exists) {
                continue;
            }

            Industry::query()->create([
                'name_en' => $industry['name_en'],
                'name_ar' => $industry['name_ar'],
                'is_active' => true,
                'sort' => $sort + 1,
            ]);
        }
    }
}
