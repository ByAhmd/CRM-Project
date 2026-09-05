<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PipelineStage;
use LogicException;

/**
 * Integrity guards for pipeline stages (decision D-8).
 *
 * A stage belongs to its pipeline for life: deals reference pipeline and
 * stage together, so moving a stage would silently corrupt their history.
 * And a pipeline needs exactly one Won and one Lost stage, so the last of
 * either kind cannot be deleted. Both are bugs when reached through code —
 * PipelineService refuses them first with a translated message, and this
 * observer is the guard for every other write path.
 */
final class PipelineStageObserver
{
    public function updating(PipelineStage $stage): void
    {
        if ($stage->isDirty('pipeline_id')) {
            throw new LogicException('A pipeline stage cannot be moved to another pipeline: pipeline_id is immutable.');
        }
    }

    public function deleting(PipelineStage $stage): void
    {
        if (! $stage->isClosed()) {
            return;
        }

        if (! $stage->siblingsOfSameKind()->exists()) {
            throw new LogicException(sprintf(
                'The only %s stage of pipeline %d cannot be deleted: every pipeline needs exactly one.',
                $stage->kind->value,
                $stage->pipeline_id,
            ));
        }
    }
}
