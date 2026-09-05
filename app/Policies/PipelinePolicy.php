<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Pipelines are configuration (decisions D-8, A-4): settings.manage covers
 * everything, with one extra guard — the default pipeline is never offered
 * for deletion, so a bulk delete skips it and the edit page hides the action.
 * PipelineService refuses the same on every other path.
 */
final class PipelinePolicy
{
    use ManagesSettings {
        delete as private deleteAsSettings;
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof Pipeline && $record->isDefault()) {
            return false;
        }

        return $this->deleteAsSettings($user, $record);
    }
}
