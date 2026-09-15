<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Seeds the default "Sales" pipeline and its stages (decision D-8).
 *
 * Idempotent and administrator-safe: the pipeline and each stage are matched
 * on either name, so a row an administrator has renamed in one language,
 * recoloured or reordered is left untouched on the next deploy and a missing
 * default stage is recreated; a soft-deleted match is respected and receives
 * no stages. The pipeline becomes the default only when no other pipeline
 * already is, so the single-default invariant kept by PipelineService is
 * never broken by a redeploy.
 */
final class PipelineSeeder extends Seeder
{
    public const PIPELINE_NAME_EN = 'Sales';

    public const PIPELINE_NAME_AR = 'المبيعات';

    /**
     * @return list<array{name_en: string, name_ar: string, kind: StageKind, probability: int, color: BadgeColor, is_default: bool}>
     */
    public static function stages(): array
    {
        return [
            ['name_en' => 'Qualification', 'name_ar' => 'التأهيل', 'kind' => StageKind::Open, 'probability' => 10, 'color' => BadgeColor::Primary, 'is_default' => true],
            ['name_en' => 'Proposal', 'name_ar' => 'العرض', 'kind' => StageKind::Open, 'probability' => 30, 'color' => BadgeColor::Info, 'is_default' => false],
            ['name_en' => 'Negotiation', 'name_ar' => 'التفاوض', 'kind' => StageKind::Open, 'probability' => 60, 'color' => BadgeColor::Warning, 'is_default' => false],
            ['name_en' => 'Won', 'name_ar' => 'مكسوبة', 'kind' => StageKind::Won, 'probability' => 100, 'color' => BadgeColor::Success, 'is_default' => false],
            ['name_en' => 'Lost', 'name_ar' => 'مفقودة', 'kind' => StageKind::Lost, 'probability' => 0, 'color' => BadgeColor::Danger, 'is_default' => false],
        ];
    }

    public function run(): void
    {
        $pipeline = Pipeline::withTrashed()
            ->where(fn (Builder $query): Builder => $query
                ->where('name_en', self::PIPELINE_NAME_EN)
                ->orWhere('name_ar', self::PIPELINE_NAME_AR))
            ->first();

        if ($pipeline === null) {
            $pipeline = Pipeline::query()->create([
                'name_en' => self::PIPELINE_NAME_EN,
                'name_ar' => self::PIPELINE_NAME_AR,
                'is_default' => ! Pipeline::withTrashed()->where('is_default', true)->exists(),
                'is_active' => true,
                'sort' => 10,
            ]);
        }

        if ($pipeline->trashed()) {
            return;
        }

        $sort = 0;

        foreach (self::stages() as $stage) {
            $sort += 10;

            $exists = $pipeline->stages()
                ->where(fn (Builder $query): Builder => $query
                    ->where('name_en', $stage['name_en'])
                    ->orWhere('name_ar', $stage['name_ar']))
                ->exists();

            if ($exists) {
                continue;
            }

            $pipeline->stages()->create([
                'name_en' => $stage['name_en'],
                'name_ar' => $stage['name_ar'],
                'kind' => $stage['kind'],
                'probability' => $stage['probability'],
                'color' => $stage['color'],
                'is_default' => $stage['is_default'] && ! $pipeline->stages()->where('is_default', true)->exists(),
                'sort' => $sort,
            ]);
        }
    }
}
