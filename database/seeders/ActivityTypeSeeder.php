<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivityKind;
use App\Enums\BadgeColor;
use App\Models\ActivityType;
use Illuminate\Database\Seeder;

/**
 * Seeds one system activity type per ActivityKind (decision A-4).
 *
 * The stable key is (kind, is_system): an administrator may rename, recolour
 * or deactivate a system row and the choice survives a redeploy; a missing
 * row is recreated with the kind's own label, colour and icon, in case order.
 */
final class ActivityTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ActivityKind::cases() as $index => $kind) {
            ActivityType::query()->firstOrCreate(
                ['kind' => $kind->value, 'is_system' => true],
                [
                    'name_ar' => __('enums.activity_kind.'.$kind->value, [], 'ar'),
                    'name_en' => __('enums.activity_kind.'.$kind->value, [], 'en'),
                    'icon' => $kind->getIcon()->name,
                    'color' => BadgeColor::from($kind->getColor()),
                    'is_active' => true,
                    'sort' => $index,
                ],
            );
        }
    }
}
