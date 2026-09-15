<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use App\Services\Settings\PipelineService;
use Illuminate\Database\Eloquent\Model;

/**
 * Pipelines are configuration (decisions D-8, A-4): settings.manage covers
 * everything, with the guards PipelineService enforces on every path — the
 * default pipeline and a pipeline that still holds deals (including
 * soft-deleted ones) are never offered for deletion, so a bulk delete skips
 * them and the edit page hides the action. A soft delete would otherwise
 * bypass the deals.pipeline_id RESTRICT key and strand its deals.
 */
final class PipelinePolicy
{
    use ManagesSettings {
        delete as private deleteAsSettings;
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof Pipeline && ! app(PipelineService::class)->isDeletable($record)) {
            return false;
        }

        return $this->deleteAsSettings($user, $record);
    }
}
