<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

/**
 * Seeds the default lead sources (decision D-7).
 *
 * Idempotent on either name: a default is created only when neither its
 * English nor its Arabic name exists, so a source an administrator has
 * renamed (in one language or both), deactivated or reordered is left
 * untouched on the next deploy, and a missing default is recreated.
 */
final class LeadSourceSeeder extends Seeder
{
    /**
     * @return array<string, string> English name => Arabic name
     */
    public static function defaults(): array
    {
        return [
            'Website' => 'الموقع الإلكتروني',
            'Referral' => 'إحالة',
            'Social media' => 'وسائل التواصل الاجتماعي',
            'Campaign' => 'حملة تسويقية',
            'Advertisement' => 'إعلان',
            'Cold call' => 'اتصال بارد',
            'Email' => 'بريد إلكتروني',
            'Event' => 'فعالية',
            'Other' => 'أخرى',
        ];
    }

    public function run(): void
    {
        $sort = 0;

        foreach (self::defaults() as $nameEn => $nameAr) {
            $sort += 10;

            $exists = LeadSource::query()
                ->where('name_en', $nameEn)
                ->orWhere('name_ar', $nameAr)
                ->exists();

            if ($exists) {
                continue;
            }

            LeadSource::query()->create([
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'is_active' => true,
                'sort' => $sort,
            ]);
        }
    }
}
