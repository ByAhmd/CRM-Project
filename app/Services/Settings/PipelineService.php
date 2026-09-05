<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Exceptions\Settings\InvalidPipelineException;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for pipelines and their stages (decisions D-8, A-4).
 *
 * Pipeline invariants:
 *
 * 1. Exactly one pipeline is the default for new deals. Saving a pipeline as
 *    the default clears the flag on every other pipeline inside the same
 *    transaction; the default can be neither unset, deactivated nor deleted —
 *    an administrator promotes another pipeline instead. The first pipeline
 *    ever created becomes the default so the invariant holds from the first row.
 *
 * Stage-set invariants, validated by validateStageSet() after every stage
 * change (a pipeline update never touches its stages, so it is not validated
 * there — a pipeline whose set became invalid outside the service can still be
 * renamed, re-sorted, activated or promoted to default):
 *
 * 2. At least one Open stage, exactly one Won and exactly one Lost stage.
 * 3. Exactly one default stage, and it is Open.
 * 4. Won is stored with probability 100 and Lost with 0, whatever was submitted.
 *
 * A new pipeline is created with the minimal valid set (one default Open
 * stage, Won and Lost) so the invariants can be validated strictly on every
 * later stage change; the administrator renames and extends from there.
 *
 * Every refusal is an InvalidPipelineException carrying a translated message;
 * the pages show it as a danger notification and halt.
 */
final class PipelineService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Pipeline
    {
        return DB::transaction(function () use ($data): Pipeline {
            $wantsDefault = (bool) ($data['is_default'] ?? false);

            if (! $wantsDefault && ! Pipeline::query()->where('is_default', true)->exists()) {
                $data['is_default'] = true;
                $wantsDefault = true;
            }

            if ($wantsDefault && ! (bool) ($data['is_active'] ?? true)) {
                throw InvalidPipelineException::defaultCannotBeDeactivated();
            }

            if ($wantsDefault) {
                $this->clearDefaults();
            }

            $pipeline = Pipeline::query()->create($data);

            $this->scaffoldStages($pipeline);
            $this->validateStageSet($pipeline);

            return $pipeline;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Pipeline $pipeline, array $data): Pipeline
    {
        return DB::transaction(function () use ($pipeline, $data): Pipeline {
            $current = Pipeline::withTrashed()->lockForUpdate()->findOrFail($pipeline->getKey());

            $wantsDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $current->isDefault();
            $wantsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $current->is_active;

            if ($current->isDefault() && ! $wantsDefault) {
                throw InvalidPipelineException::defaultCannotBeUnset();
            }

            if ($wantsDefault && ! $wantsActive) {
                throw InvalidPipelineException::defaultCannotBeDeactivated();
            }

            if ($wantsDefault && $current->trashed()) {
                throw InvalidPipelineException::deletedCannotBeDefault();
            }

            if ($wantsDefault && ! $current->isDefault()) {
                $this->clearDefaults($current);
            }

            $current->update($data);

            return $current;
        });
    }

    public function delete(Pipeline $pipeline): void
    {
        DB::transaction(function () use ($pipeline): void {
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());

            if ($current->isDefault()) {
                throw InvalidPipelineException::defaultCannotBeDeleted();
            }

            $current->delete();
        });
    }

    /** Whether the pipeline may be removed at all — the pages hide the action when it may not. */
    public function isDeletable(Pipeline $pipeline): bool
    {
        return ! $pipeline->isDefault();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createStage(Pipeline $pipeline, array $data): PipelineStage
    {
        return DB::transaction(function () use ($pipeline, $data): PipelineStage {
            $data = $this->normaliseStageData($data);

            if (! array_key_exists('sort', $data)) {
                $data['sort'] = (int) $pipeline->stages()->max('sort') + 1;
            }

            if ($data['is_default'] === true) {
                $this->clearStageDefaults($pipeline);
            }

            $stage = PipelineStage::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'name_ar' => $data['name_ar'] ?? null,
                'name_en' => $data['name_en'] ?? null,
                'kind' => $data['kind'],
                'probability' => $data['probability'],
                'color' => $data['color'] ?? BadgeColor::Primary,
                'is_default' => $data['is_default'],
                'sort' => $data['sort'],
            ]);

            $this->validateStageSet($pipeline);

            return $stage;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStage(PipelineStage $stage, array $data): PipelineStage
    {
        return DB::transaction(function () use ($stage, $data): PipelineStage {
            $current = PipelineStage::query()->lockForUpdate()->findOrFail($stage->getKey());
            $data = $this->normaliseStageData($data, $current);

            if ($data['is_default'] === true && ! $current->isDefault()) {
                $this->clearStageDefaults($current->pipeline, $current);
            }

            $current->update($data);

            $this->validateStageSet($current->pipeline);

            return $current;
        });
    }

    public function deleteStage(PipelineStage $stage): void
    {
        DB::transaction(function () use ($stage): void {
            $current = PipelineStage::query()->lockForUpdate()->findOrFail($stage->getKey());

            if ($current->isClosed() && ! $current->siblingsOfSameKind()->exists()) {
                throw InvalidPipelineException::lastStageOfKind($current->kind);
            }

            $pipeline = $current->pipeline;

            $current->delete();

            $this->validateStageSet($pipeline);
        });
    }

    /** Whether the stage may be removed at all — the relation manager hides the action when it may not. */
    public function isStageDeletable(PipelineStage $stage): bool
    {
        if ($stage->isDefault()) {
            return false;
        }

        return ! $stage->isClosed() || $stage->siblingsOfSameKind()->exists();
    }

    /**
     * Asserts the stage-set invariants against the database, not the loaded
     * relation, so the check sees the change that was just written.
     */
    public function validateStageSet(Pipeline $pipeline): void
    {
        $stages = $pipeline->stages()->get();

        $byKind = static fn (StageKind $kind): int => $stages
            ->filter(static fn (PipelineStage $stage): bool => $stage->kind === $kind)
            ->count();

        if ($byKind(StageKind::Open) < 1) {
            throw InvalidPipelineException::openStageRequired();
        }

        if ($byKind(StageKind::Won) !== 1) {
            throw InvalidPipelineException::wonStageRequired();
        }

        if ($byKind(StageKind::Lost) !== 1) {
            throw InvalidPipelineException::lostStageRequired();
        }

        $defaults = $stages->filter(static fn (PipelineStage $stage): bool => $stage->isDefault());

        if ($defaults->count() !== 1) {
            throw InvalidPipelineException::defaultStageRequired();
        }

        $default = $defaults->first();

        if ($default === null || $default->kind !== StageKind::Open) {
            throw InvalidPipelineException::defaultStageMustBeOpen();
        }
    }

    /**
     * Resolves the kind, forces the probability of Won (100) and Lost (0) and
     * normalises the default flag so the invariants read a single shape.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseStageData(array $data, ?PipelineStage $current = null): array
    {
        $kind = $this->kindFrom($data['kind'] ?? null) ?? $current->kind ?? StageKind::Open;
        $data['kind'] = $kind;

        $submitted = array_key_exists('probability', $data) ? (int) $data['probability'] : ($current->probability ?? 0);

        $data['probability'] = match ($kind) {
            StageKind::Won => 100,
            StageKind::Lost => 0,
            StageKind::Open => $submitted,
        };

        if ($data['probability'] < 0 || $data['probability'] > 100) {
            throw InvalidPipelineException::probabilityOutOfRange();
        }

        $data['is_default'] = array_key_exists('is_default', $data)
            ? (bool) $data['is_default']
            : ($current?->isDefault() ?? false);

        return $data;
    }

    /** The minimal valid stage set a new pipeline starts with. */
    private function scaffoldStages(Pipeline $pipeline): void
    {
        $defaults = [
            ['key' => 'qualification', 'kind' => StageKind::Open, 'probability' => 10, 'color' => BadgeColor::Primary, 'is_default' => true, 'sort' => 10],
            ['key' => 'won', 'kind' => StageKind::Won, 'probability' => 100, 'color' => BadgeColor::Success, 'is_default' => false, 'sort' => 20],
            ['key' => 'lost', 'kind' => StageKind::Lost, 'probability' => 0, 'color' => BadgeColor::Danger, 'is_default' => false, 'sort' => 30],
        ];

        foreach ($defaults as $stage) {
            $pipeline->stages()->create([
                'name_ar' => (string) __('pipelines.stages.defaults.'.$stage['key'], [], 'ar'),
                'name_en' => (string) __('pipelines.stages.defaults.'.$stage['key'], [], 'en'),
                'kind' => $stage['kind'],
                'probability' => $stage['probability'],
                'color' => $stage['color'],
                'is_default' => $stage['is_default'],
                'sort' => $stage['sort'],
            ]);
        }
    }

    private function clearDefaults(?Pipeline $except = null): void
    {
        Pipeline::withTrashed()
            ->where('is_default', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->get()
            ->each(static fn (Pipeline $other) => $other->update(['is_default' => false]));
    }

    private function clearStageDefaults(Pipeline $pipeline, ?PipelineStage $except = null): void
    {
        $pipeline->stages()
            ->where('is_default', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->get()
            ->each(static fn (PipelineStage $other) => $other->update(['is_default' => false]));
    }

    private function kindFrom(mixed $value): ?StageKind
    {
        if ($value instanceof StageKind) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return StageKind::tryFrom($value);
        }

        return null;
    }
}
